<?php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

/*
POST JSON:
{
  vorlage_id: int,
  node_id: int,
  direction: "left" | "right"
}
*/

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);

$vorlageId = (int)($req['vorlage_id'] ?? 0);
$nodeId    = (int)($req['node_id'] ?? 0);
$dir       = trim((string)($req['direction'] ?? ''));

if ($vorlageId<=0 || $nodeId<=0 || !in_array($dir, ['left','right'], true)) {
    echo json_encode(['ok'=>false, 'error'=>'Ungültige Parameter']); exit;
}

// --- Helper -----------------------------------------------------------------
function fetchRow(mysqli $db, int $id): ?array {
    $st = $db->prepare("SELECT id, vorlage_id, projekt_id, parent_id, position, code FROM unterkategorien WHERE id=? LIMIT 1");
    $st->bind_param("i",$id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

function fetchChildren(mysqli $db, int $vorlageId, ?int $parentId): array {
    if ($parentId===null) {
        $st = $db->prepare("SELECT id, code FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id IS NULL ORDER BY position, id");
        $st->bind_param("i",$vorlageId);
    } else {
        $st = $db->prepare("SELECT id, code FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id=? ORDER BY position, id");
        $st->bind_param("ii",$vorlageId, $parentId);
    }
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function getDepthFromCode(string $code): int {
    if ($code === '' || $code === null) return 0;
    return substr_count($code, '_'); // '1' => 0, '1_1' => 1, ...
}

function nextChildIndexFromCodes(array $childRows, string $parentCode): int {
    $max = 0;
    foreach($childRows as $r){
        $c = (string)$r['code'];
        if (strpos($c, $parentCode.'_') !== 0) continue;
        $seg = substr($c, strrpos($c, '_')+1);
        if (ctype_digit($seg)) {
            $n = (int)$seg;
            if ($n > $max) $max = $n;
        }
    }
    return $max + 1;
}

function compactPositions(mysqli $db, int $vorlageId, ?int $parentId, int $removedPos): void {
    if ($parentId===null) {
        $st = $db->prepare("UPDATE unterkategorien SET position=position-1 WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id IS NULL AND position>?");
        $st->bind_param("ii",$vorlageId,$removedPos);
    } else {
        $st = $db->prepare("UPDATE unterkategorien SET position=position-1 WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id=? AND position>?");
        $st->bind_param("iii",$vorlageId,$parentId,$removedPos);
    }
    $st->execute();
    $st->close();
}

function nextEndPosition(mysqli $db, int $vorlageId, ?int $parentId): int {
    if ($parentId===null) {
        $st = $db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id IS NULL");
        $st->bind_param("i",$vorlageId);
    } else {
        $st = $db->prepare("SELECT COALESCE(MAX(position),0)+1 FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id=?");
        $st->bind_param("ii",$vorlageId,$parentId);
    }
    $st->execute();
    $pos = (int)($st->get_result()->fetch_column() ?? 1);
    $st->close();
    return $pos;
}

function recalcCodesRecursive(mysqli $db, int $vorlageId, int $nodeId, string $newCode): void {
    // set self
    $st = $db->prepare("UPDATE unterkategorien SET code=? WHERE id=?");
    $st->bind_param("si", $newCode, $nodeId);
    $st->execute();
    $st->close();

    // children
    $st = $db->prepare("SELECT id FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id=? ORDER BY position, id");
    $st->bind_param("ii",$vorlageId,$nodeId);
    $st->execute();
    $children = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    $i=1;
    foreach($children as $ch){
        $childId = (int)$ch['id'];
        $childCode = $newCode . '_' . $i;
        $i++;
        recalcCodesRecursive($db, $vorlageId, $childId, $childCode);
    }
}

// --- Load node & checks ------------------------------------------------------
$node = fetchRow($mysqli, $nodeId);
if (!$node || (int)$node['vorlage_id'] !== $vorlageId || !empty($node['projekt_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'Knoten ungültig (Vorlage oder Typ)']); exit;
}

$parentId   = $node['parent_id'];   // kann NULL sein (Root)
$oldPos     = (int)$node['position'];
$nodeCode   = (string)$node['code'];

// Root-Schutz (wir erlauben nur einen Root '1'): Node selbst darf nicht Root sein
if ($parentId === null && $dir === 'left') {
    echo json_encode(['ok'=>false, 'error'=>'Root kann nicht weiter nach links verschoben werden.']); exit;
}

$mysqli->begin_transaction();

try {
    if ($dir === 'right') {
        // vorherigen Geschwister finden
        if ($parentId===null) {
            $st = $mysqli->prepare("SELECT id, code, position FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id IS NULL AND position<? ORDER BY position DESC LIMIT 1");
            $st->bind_param("ii",$vorlageId,$oldPos);
        } else {
            $st = $mysqli->prepare("SELECT id, code, position FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id=? AND position<? ORDER BY position DESC LIMIT 1");
            $st->bind_param("iii",$vorlageId,$parentId,$oldPos);
        }
        $st->execute();
        $prev = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$prev) {
            throw new Exception("Kein vorheriger Geschwister – Rechts-Einrücken nicht möglich.");
        }

        $targetParentId   = (int)$prev['id'];
        $targetParentCode = (string)$prev['code'];

        // Tiefe prüfen
        $newDepth = getDepthFromCode($targetParentCode) + 1;
        if ($newDepth > 9) {
            throw new Exception("Max. 10 Ebenen erreicht.");
        }

        // Node aus alter Geschwisterliste "entnehmen"
        compactPositions($mysqli, $vorlageId, $parentId, $oldPos);

        // neue Position am Ende der Zielchildren
        $newPos = nextEndPosition($mysqli, $vorlageId, $targetParentId);

        // neue Code-Basis: parentCode + _<next>
        $children = fetchChildren($mysqli, $vorlageId, $targetParentId);
        $nextIdx  = nextChildIndexFromCodes($children, $targetParentCode);
        $newCode  = $targetParentCode . '_' . $nextIdx;

        // Parent/Pos setzen (Code folgt rekursiv)
        $st = $mysqli->prepare("UPDATE unterkategorien SET parent_id=?, position=? WHERE id=?");
        $st->bind_param("iii",$targetParentId, $newPos, $nodeId);
        $st->execute();
        $st->close();

        // Codes für Knoten + Nachfahren neu
        recalcCodesRecursive($mysqli, $vorlageId, $nodeId, $newCode);

    } else { // left
        // Parent laden
        $parent = $parentId!==null ? fetchRow($mysqli, (int)$parentId) : null;
        if (!$parent) throw new Exception("Kein Parent – Links-Ausrücken nicht möglich.");

        // Root-Schutz: Parent ist Root -> nicht erlauben (sonst mehrere Wurzeln)
        if ($parent['parent_id'] === null) {
            throw new Exception("Ausrücken über Root ist nicht erlaubt.");
        }

        $grandId   = (int)$parent['parent_id'];
        $grand     = fetchRow($mysqli, $grandId);
        if (!$grand) throw new Exception("Kein Großeltern-Knoten – Links-Ausrücken nicht möglich.");

        $grandCode = (string)$grand['code'];

        // Tiefe prüfen (wird kleiner, aber wir prüfen dennoch)
        $newDepth = getDepthFromCode($grandCode) + 1;
        if ($newDepth > 9) {
            throw new Exception("Max. 10 Ebenen erreicht.");
        }

        // Aus alter Liste entfernen
        compactPositions($mysqli, $vorlageId, $parentId, $oldPos);

        // neue Position am Ende beim Großeltern-Knoten
        $newPos = nextEndPosition($mysqli, $vorlageId, $grandId);

        // neuer Code
        $children = fetchChildren($mysqli, $vorlageId, $grandId);
        $nextIdx  = nextChildIndexFromCodes($children, $grandCode);
        $newCode  = $grandCode . '_' . $nextIdx;

        // Parent/Pos setzen
        $st = $mysqli->prepare("UPDATE unterkategorien SET parent_id=?, position=? WHERE id=?");
        $st->bind_param("iii",$grandId, $newPos, $nodeId);
        $st->execute();
        $st->close();

        // Codes rekursiv neu
        recalcCodesRecursive($mysqli, $vorlageId, $nodeId, $newCode);
    }

    $mysqli->commit();
    echo json_encode(['ok'=>true]);

} catch (Throwable $e) {
    $mysqli->rollback();
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
