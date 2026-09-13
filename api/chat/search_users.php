<?php
// 6) C:\xampp\htdocs\pendenz.com\api\chat\search_users.php
// Benutzer-Suche (Name/Email), schließt optional den eigenen User aus (?exclude_self=1)
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$me = (int)($_SESSION['user_id'] ?? 0);
if ($me <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

function tbl_cols(mysqli $db, string $table): array {
  $t = $db->real_escape_string($table);
  $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}'";
  $rs = $db->query($sql);
  $cols=[]; while($row=$rs->fetch_assoc()){ $cols[strtolower($row['COLUMN_NAME'])]=$row['COLUMN_NAME']; } $rs->close();
  return $cols;
}
function pick(array $cols, array $cand){ foreach($cand as $c){ $k=strtolower($c); if(isset($cols[$k])) return $cols[$k]; } return null; }

$table='benutzer';
$cols = tbl_cols($mysqli, $table);
$idCol   = pick($cols, ['id','user_id']);
$nameCol = pick($cols, ['name','fullname','vorname','nachname','anzeige_name','display_name']);
$mailCol = pick($cols, ['email','mail','e_mail']);

if(!$idCol || (!$nameCol && !$mailCol)){ echo json_encode(['ok'=>false,'error'=>'schema: benutzer columns missing']); exit; }

$q     = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(25, (int)($_GET['limit'] ?? 10)));
$excMe = (int)($_GET['exclude_self'] ?? 1) === 1;

$where=[]; $params=[]; $types='';
if ($q !== '') {
  if ($nameCol){ $where[] = "`{$nameCol}` LIKE ?"; $params[]="%{$q}%"; $types.='s'; }
  if ($mailCol){ $where[] = "`{$mailCol}` LIKE ?"; $params[]="%{$q}%"; $types.='s'; }
}
if ($excMe) { $where[] = "`{$idCol}` <> ?"; $params[]=$me; $types.='i'; }
$whereSql = $where ? ('WHERE '.implode(' OR ',$where)) : '';

$sql = "SELECT `{$idCol}` AS id"
     . ($nameCol? ", `{$nameCol}` AS name" : ", '' AS name")
     . ($mailCol? ", `{$mailCol}` AS email" : ", '' AS email")
     . " FROM `{$table}` {$whereSql} ORDER BY name ASC LIMIT {$limit}";

if ($params){
  $st = $mysqli->prepare($sql);
  $st->bind_param($types, ...$params);
  $st->execute(); $rs=$st->get_result();
} else {
  $rs = $mysqli->query($sql);
}
$out=[]; while($row=$rs->fetch_assoc()){ $out[]=['id'=>(int)$row['id'],'name'=>$row['name']?:$row['email'],'email'=>$row['email']]; }
if(isset($st)) $st->close(); else $rs->close();

echo json_encode(['ok'=>true,'results'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
