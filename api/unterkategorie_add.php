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
  parent_id: int,
  parent_code: string,
  label: string
}
*/
$raw = file_get_contents('php://input');
$req = json_decode($raw, true);

$vorlageId  = (int)($req['vorlage_id'] ?? 0);
$parentId   = (int)($req['parent_id'] ?? 0);
$parentCode = trim((string)($req['parent_code'] ?? ''));
$label      = trim((string)($req['label'] ?? ''));

if ($vorlageId<=0 || $parentId<=0 || $parentCode==='' || $label==='') {
    echo json_encode(['ok'=>false, 'error'=>'Ungültige Eingaben.']); exit;
}

/* Parent prüfen + Tiefe bestimmen */
$st = $mysqli->prepare("SELECT id, vorlage_id, projekt_id, code FROM unterkategorien WHERE id=? LIMIT 1");
$st->bind_param("i",$parentId);
$st->execute();
$parent = $st->get_result()->fetch_assoc();
$st->close();

if (!$parent || (int)$parent['vorlage_id'] !== $vorlageId || !empty($parent['projekt_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'Parent ungültig oder gehört nicht zur Vorlage.']); exit;
}

$depth = substr_count($parentCode, '_') + 1; // Kind-Ebene
if ($depth > 9) {
    echo json_encode(['ok'=>false, 'error'=>'Max. 10 Ebenen erreicht.']); exit;
}

/* Nächsten Index für Child ermitteln: max letztes Segment + 1 */
$like = $parentCode . "\_%";
$st = $mysqli->prepare("SELECT code FROM unterkategorien WHERE vorlage_id=? AND projekt_id IS NULL AND parent_id=? AND code LIKE ?");
$st->bind_param("iis", $vorlageId, $parentId, $like);
$st->execute();
$res = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$next = 1;
foreach($res as $r){
    $c = $r['code'];
    $seg = substr($c, strrpos($c, '_')+1);
    if (ctype_digit($seg)) {
        $n = (int)$seg;
        if ($n >= $next) $next = $n + 1;
    }
}
$newCode = $parentCode . '_' . $next;

/* Position am Ende */
$st = $mysqli->prepare("SELECT COALESCE(MAX(position),0)+1 FROM unterkategorien WHERE vorlage_id=? AND parent_id=? AND projekt_id IS NULL");
$st->bind_param("ii", $vorlageId, $parentId);
$st->execute();
$pos = (int)($st->get_result()->fetch_column() ?? 1);
$st->close();

/* name_variable nach Ebene */
$nameVar = 'ebene'.$depth;

/* Insert */
$st = $mysqli->prepare("INSERT INTO unterkategorien (vorlage_id, projekt_id, parent_id, name_variable, label_default, position, code)
                        VALUES (?, NULL, ?, ?, ?, ?, ?)");
$st->bind_param("iissis", $vorlageId, $parentId, $nameVar, $label, $pos, $newCode);
$st->execute();
$newId = (int)$st->insert_id;
$st->close();

/* Optional: FS-Ordner anlegen */
$base = realpath(__DIR__."/../uploads");
if ($base !== false) {
    $dir = $base . DIRECTORY_SEPARATOR . "vorlagen" . DIRECTORY_SEPARATOR . $vorlageId . DIRECTORY_SEPARATOR . $newCode;
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
}

echo json_encode([
  'ok'=>true,
  'node'=>[
    'id'=>$newId,
    'parent_id'=>$parentId,
    'code'=>$newCode,
    'label_default'=>$label,
    'name_variable'=>$nameVar,
    'position'=>$pos,
    'depth'=>$depth
  ]
]);
