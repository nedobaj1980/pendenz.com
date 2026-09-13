<?php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

$vorlageId = isset($_GET['vorlage_id']) ? (int)$_GET['vorlage_id'] : 0;
$projektId = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;

if ($vorlageId<=0 || $projektId<=0) die("❌ Vorlage oder Projekt fehlt.");

/* Projektname für Root-Label */
$st = $mysqli->prepare("SELECT id, name FROM projekte WHERE id=?");
$st->bind_param("i",$projektId);
$st->execute();
$projekt = $st->get_result()->fetch_assoc();
$st->close();
if(!$projekt) die("❌ Projekt nicht gefunden.");
$projektName = $projekt['name'];

/* Vorlage prüfen */
$st = $mysqli->prepare("SELECT id FROM struktur_vorlagen WHERE id=?");
$st->bind_param("i",$vorlageId);
$st->execute();
$exists = (bool)$st->get_result()->fetch_column();
$st->close();
if (!$exists) die("❌ Vorlage nicht gefunden.");

/* gesamte Vorlagenstruktur laden (code, parent_id-Verknüpfung) */
$rows = $mysqli->prepare("SELECT id, parent_id, name_variable, label_default, position, code
                          FROM unterkategorien
                          WHERE vorlage_id=? AND projekt_id IS NULL
                          ORDER BY id");
$rows->bind_param("i",$vorlageId);
$rows->execute();
$tpl = $rows->get_result()->fetch_all(MYSQLI_ASSOC);
$rows->close();
if (empty($tpl)) {
    die("ℹ️ Diese Vorlage enthält keine Unterkategorien.");
}

/* Hilfsindex: alt_id -> node */
$byId = [];
foreach($tpl as $r) $byId[(int)$r['id']] = $r;

/* Elterncode bestimmen (damit wir parent im Ziel per code finden) */
$parentCodeOf = function(int $tplId) use ($byId): ?string {
    $node = $byId[$tplId] ?? null;
    if (!$node) return null;
    $pid = $node['parent_id'];
    if (!$pid) return null;
    return $byId[(int)$pid]['code'] ?? null;
};

/* Knoten im Ziel (projekt) per code finden */
function findTargetByCode(mysqli $db, int $projektId, string $code): ?int {
    $st = $db->prepare("SELECT id FROM unterkategorien WHERE projekt_id=? AND code=? LIMIT 1");
    $st->bind_param("is", $projektId, $code);
    $st->execute();
    $id = $st->get_result()->fetch_column();
    $st->close();
    return $id ? (int)$id : null;
}

/* Knoten im Ziel anlegen (oder bestehende ID zurückgeben) */
function ensureTargetNode(mysqli $db, int $projektId, array $srcNode, ?int $parentTargetId, string $projektName): int {
    $code   = $srcNode['code'];
    $exists = findTargetByCode($db, $projektId, $code);
    $label  = (string)$srcNode['label_default'];
    // Root (code == '1') wird zu "Projekt <Name>"
    if ($code === '1') {
        $label = 'Projekt ' . $projektName;
    }

    if ($exists) {
        // Optional: Parent-Bezug nachziehen, falls geändert (wir überschreiben NICHT aggressiv)
        if ($parentTargetId) {
            $st = $db->prepare("UPDATE unterkategorien SET parent_id=? WHERE id=? AND (parent_id IS NULL OR parent_id<>?)");
            $st->bind_param("iii", $parentTargetId, $exists, $parentTargetId);
            $st->execute();
            $st->close();
        }
        // Optional: Position aktualisieren (nur wenn gesetzt)
        if (isset($srcNode['position'])) {
            $pos = (int)$srcNode['position'];
            $st = $db->prepare("UPDATE unterkategorien SET position=? WHERE id=?");
            $st->bind_param("ii", $pos, $exists);
            $st->execute();
            $st->close();
        }
        // Root-Label ggf. updaten
        if ($code === '1') {
            $st = $db->prepare("UPDATE unterkategorien SET label_default=? WHERE id=?");
            $st->bind_param("si", $label, $exists);
            $st->execute();
            $st->close();
        }
        return $exists;
    }

    // Insert neu
    $st = $db->prepare("INSERT INTO unterkategorien (vorlage_id, projekt_id, parent_id, name_variable, label_default, position, code)
                        VALUES (NULL, ?, ?, ?, ?, ?, ?)");
    $pid = $parentTargetId ?: NULL;
    $nameVar = (string)$srcNode['name_variable'];
    $labelIns = $label;
    $pos = (int)$srcNode['position'];
    $st->bind_param("iissis", $projektId, $pid, $nameVar, $labelIns, $pos, $code);
    $st->execute();
    $newId = $st->insert_id;
    $st->close();

    return (int)$newId;
}

/* Rekursiver Einbau – idempotent: je code nur einmal */
function buildProjectTree(mysqli $db, int $projektId, array $tplRows, array $byId, string $projektName): void {
    // Reihenfolge: garantiert Eltern vor Kindern → sortiere nach Länge des Codes
    usort($tplRows, function($a,$b){
        return strlen($a['code']) <=> strlen($b['code']);
    });

    // Map code -> targetId
    $targetByCode = [];

    foreach($tplRows as $node){
        $code = $node['code'];
        $parentCode = null;
        if (!empty($node['parent_id'])) {
            $parentCode = $byId[(int)$node['parent_id']]['code'] ?? null;
        }
        $parentTargetId = $parentCode ? ($targetByCode[$parentCode] ?? findTargetByCode($db,$projektId,$parentCode)) : null;
        $targetId = ensureTargetNode($db, $projektId, $node, $parentTargetId, $projektName);
        $targetByCode[$code] = $targetId;
    }
}

/* ausführen */
buildProjectTree($mysqli, $projektId, $tpl, $byId, $projektName);

/* zurück zur Projektseite oder Meldung */
echo "<main class='container' style='padding:16px;'>";
echo "<div class='card' style='padding:12px;'>";
echo "✅ Die Vorlage wurde in das Projekt <strong>".htmlspecialchars($projektName)."</strong> eingebaut (ohne Duplikate).<br>";
echo "Der Root-Ordner heißt nun: <strong>Projekt ".htmlspecialchars($projektName)."</strong>.";
echo "</div>";
echo "<p><a class='btn' href='projekt_baum.php?projekt_id=".$projektId."'>🔭 Projekt-Struktur ansehen</a>
          <a class='btn' href='vorlagen.php'>← Zurück zu Vorlagen</a></p>";
echo "</main>";
