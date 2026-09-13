<?php
if (session_status()===PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action     = $input['action'] ?? null;
$vorlageId  = isset($input['vorlage_id']) ? (int)$input['vorlage_id'] : 0;
$id         = isset($input['id']) ? (int)$input['id'] : 0;

if(!$action || !$vorlageId || !$id){
  echo json_encode(['ok'=>false,'error'=>'Fehlende Parameter.']); exit;
}

/** Hilfsfunktionen **/
function getNode(mysqli $db, int $id): ?array {
  $st = $db->prepare("SELECT id, vorlage_id, parent_id, position FROM unterkategorien WHERE id=?");
  $st->bind_param("i",$id); $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return $row ?: null;
}
function countChildren(mysqli $db, int $vorlageId, ?int $parentId): int {
  if ($parentId===null) {
    $st = $db->prepare("SELECT COUNT(*) c FROM unterkategorien WHERE vorlage_id=? AND parent_id IS NULL");
    $st->bind_param("i",$vorlageId);
  } else {
    $st = $db->prepare("SELECT COUNT(*) c FROM unterkategorien WHERE vorlage_id=? AND parent_id=?");
    $st->bind_param("ii",$vorlageId,$parentId);
  }
  $st->execute(); $c = (int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
  return $c;
}
function siblings(mysqli $db, int $vorlageId, ?int $parentId): array {
  if ($parentId===null) {
    $st=$db->prepare("SELECT id FROM unterkategorien WHERE vorlage_id=? AND parent_id IS NULL ORDER BY position, id");
    $st->bind_param("i",$vorlageId);
  } else {
    $st=$db->prepare("SELECT id FROM unterkategorien WHERE vorlage_id=? AND parent_id=? ORDER BY position, id");
    $st->bind_param("ii",$vorlageId,$parentId);
  }
  $st->execute();
  $arr = array_column($st->get_result()->fetch_all(MYSQLI_ASSOC),'id');
  $st->close();
  return $arr;
}
function setPositionBulk(mysqli $db, array $ids, ?int $parentId, int $vorlageId){
  // renumeriere Position ab 1
  $pos = 1;
  foreach($ids as $nid){
    $st = $db->prepare("UPDATE unterkategorien SET parent_id=?, position=? WHERE id=? AND vorlage_id=?");
    if($parentId===null) { $null=null; $st->bind_param("iisi",$null,$pos,$nid,$vorlageId); }
    else { $st->bind_param("iiii",$parentId,$pos,$nid,$vorlageId); }
    $st->execute(); $st->close();
    $pos++;
  }
}

/** Aktionen **/
if ($action === 'reorder') {
  $parentId = array_key_exists('parent_id',$input) && $input['parent_id']!==null ? (int)$input['parent_id'] : null;
  $newIndex = isset($input['new_index']) ? (int)$input['new_index'] : 0;

  $node = getNode($mysqli,$id);
  if(!$node || (int)$node['vorlage_id'] !== $vorlageId) { echo json_encode(['ok'=>false,'error'=>'Knoten nicht gefunden.']); exit; }
  if( ($node['parent_id']??null) !== $parentId) { echo json_encode(['ok'=>false,'error'=>'Nur Sortieren innerhalb derselben Ebene erlaubt.']); exit; }

  $sib = siblings($mysqli,$vorlageId,$parentId);
  // aktuellen aus Liste nehmen
  $sib = array_values(array_filter($sib, fn($x)=> (int)$x !== $id));
  // am gewünschten Index einfügen
  array_splice($sib, max(0,min($newIndex,count($sib))), 0, [$id]);
  setPositionBulk($mysqli,$sib,$parentId,$vorlageId);

  echo json_encode(['ok'=>true]); exit;
}

if ($action === 'indent') {
  $newParentId = isset($input['new_parent_id']) ? (int)$input['new_parent_id'] : 0;
  if(!$newParentId){ echo json_encode(['ok'=>false,'error'=>'Neuer Parent fehlt.']); exit; }

  $node = getNode($mysqli,$id);
  $newParent = getNode($mysqli,$newParentId);
  if(!$node || !$newParent || (int)$node['vorlage_id']!==$vorlageId || (int)$newParent['vorlage_id']!==$vorlageId){
    echo json_encode(['ok'=>false,'error'=>'Knoten oder neuer Parent nicht gefunden.']); exit;
  }
  // Sicherheitsregel: neuer Parent muss bisheriges Geschwister sein
  if (($node['parent_id']??null) !== ($newParent['parent_id']??null)){
    echo json_encode(['ok'=>false,'error'=>'Nach rechts nur unter vorheriges Geschwister möglich.']); exit;
  }
  // Max 10 Kinder prüfen
  $cnt = countChildren($mysqli,$vorlageId,$newParentId);
  if($cnt >= 10){ echo json_encode(['ok'=>false,'error'=>'Maximal 10 Unterordner beim Ziel erreicht.']); exit; }

  // Alte Geschwisterliste ohne node → neu nummerieren (Lücke schließen)
  $oldSibs = siblings($mysqli,$vorlageId,$node['parent_id']??null);
  $oldSibs = array_values(array_filter($oldSibs, fn($x)=> (int)$x !== $id));
  setPositionBulk($mysqli,$oldSibs,$node['parent_id']??null,$vorlageId);

  // Neue Kinderliste des Ziel-Parents um node erweitern (am Ende)
  $newSibs = siblings($mysqli,$vorlageId,$newParentId);
  $newSibs[] = $id;
  setPositionBulk($mysqli,$newSibs,$newParentId,$vorlageId);

  // WICHTIG: code NICHT ändern (damit keine Dateien verloren gehen)
  echo json_encode(['ok'=>true]); exit;
}

if ($action === 'outdent') {
  $node = getNode($mysqli,$id);
  if(!$node || (int)$node['vorlage_id']!==$vorlageId){ echo json_encode(['ok'=>false,'error'=>'Knoten nicht gefunden.']); exit; }
  $parentId = $node['parent_id'] ?? null;
  if($parentId===null){ echo json_encode(['ok'=>false,'error'=>'Bereits auf oberster Ebene.']); exit; }

  $parent = getNode($mysqli,$parentId);
  $grand = null;
  $grandId = $parent['parent_id'] ?? null;

  // Max 10 Kinder beim Großeltern-Knoten prüfen
  $cnt = countChildren($mysqli,$vorlageId,$grandId);
  if($cnt >= 10){ echo json_encode(['ok'=>false,'error'=>'Maximal 10 Unterordner beim Ziel erreicht.']); exit; }

  // 1) Aus alter Ebene entfernen und neu nummerieren
  $oldSibs = siblings($mysqli,$vorlageId,$parentId);
  $oldSibs = array_values(array_filter($oldSibs, fn($x)=> (int)$x !== $id));
  setPositionBulk($mysqli,$oldSibs,$parentId,$vorlageId);

  // 2) In neue Ebene (Großeltern) direkt NACH dem Parent einordnen
  $gpSibs = siblings($mysqli,$vorlageId,$grandId);
  $idxParent = array_search((int)$parentId, array_map('intval',$gpSibs), true);
  if($idxParent===false) $idxParent = count($gpSibs)-1;
  array_splice($gpSibs, $idxParent+1, 0, [$id]);
  setPositionBulk($mysqli,$gpSibs,$grandId,$vorlageId);

  echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unbekannte Aktion.']); exit;
