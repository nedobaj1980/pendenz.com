<?php
// 5) C:\xampp\htdocs\pendenz.com\api\chat\search_projects.php
// Suche in Projekten (robust gegen unterschiedliche Spaltennamen)
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

function table_columns(mysqli $db, string $table): array {
  $t = $db->real_escape_string($table);
  $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}'";
  $rs = $db->query($sql);
  $cols=[]; while($row=$rs->fetch_assoc()){ $cols[strtolower($row['COLUMN_NAME'])]=$row['COLUMN_NAME']; } $rs->close();
  return $cols;
}
function col(array $cols, array $candidates): ?string {
  foreach($candidates as $c){ $k=strtolower($c); if(isset($cols[$k])) return $cols[$k]; }
  return null;
}

$table = 'projekte';
$cols  = table_columns($mysqli, $table);
$idCol   = col($cols, ['id','projekt_id','project_id']);
$nameCol = col($cols, ['name','titel','bezeichnung','title']);
if(!$idCol || !$nameCol){ echo json_encode(['ok'=>false,'error'=>'schema: projekte columns missing']); exit; }

$q     = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(25, (int)($_GET['limit'] ?? 10)));

if ($q === '') {
  $sql = "SELECT `{$idCol}` AS id, `{$nameCol}` AS name FROM `{$table}` ORDER BY `{$nameCol}` ASC LIMIT {$limit}";
  $rs  = $mysqli->query($sql);
} else {
  $like = "%{$q}%";
  $sql = "SELECT `{$idCol}` AS id, `{$nameCol}` AS name FROM `{$table}` WHERE `{$nameCol}` LIKE ? ORDER BY `{$nameCol}` ASC LIMIT {$limit}";
  $st = $mysqli->prepare($sql);
  $st->bind_param("s", $like);
  $st->execute(); $rs = $st->get_result();
}
$out=[]; while($row=$rs->fetch_assoc()){ $out[]=['id'=>(int)$row['id'],'name'=>$row['name']]; }
if(isset($st)) $st->close(); else $rs->close();

echo json_encode(['ok'=>true,'results'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
