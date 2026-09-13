<?php
// 7) C:\xampp\htdocs\pendenz.com\api\chat\search_companies.php
// Firmen/Unternehmen-Suche – wählt vorhandene Tabelle automatisch (firmen|unternehmen|companies|company)
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

function find_table(mysqli $db, array $cands): ?string {
  $in = implode("','", array_map([$db,'real_escape_string'],$cands));
  $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('{$in}') LIMIT 1";
  $rs = $db->query($sql); $t = $rs && $rs->num_rows ? $rs->fetch_assoc()['TABLE_NAME'] : null; if($rs) $rs->close();
  return $t;
}
function cols(mysqli $db, string $t): array{
  $t=$db->real_escape_string($t);
  $rs=$db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}'");
  $o=[]; while($r=$rs->fetch_assoc()){ $o[strtolower($r['COLUMN_NAME'])]=$r['COLUMN_NAME']; } $rs->close(); return $o;
}
function pick(array $a, array $c){ foreach($c as $x){ $k=strtolower($x); if(isset($a[$k])) return $a[$k]; } return null; }

$table = find_table($mysqli, ['firmen','unternehmen','companies','company']);
if(!$table){ echo json_encode(['ok'=>false,'error'=>'no_company_table']); exit; }
$cs = cols($mysqli,$table);
$idCol   = pick($cs, ['id','firma_id','company_id']);
$nameCol = pick($cs, ['name','firma','bezeichnung','title']);
if(!$idCol || !$nameCol){ echo json_encode(['ok'=>false,'error'=>'schema: company columns missing']); exit; }

$q = trim((string)($_GET['q'] ?? ''));
$limit = max(1, min(25, (int)($_GET['limit'] ?? 10)));

if ($q===''){
  $sql = "SELECT `{$idCol}` AS id, `{$nameCol}` AS name FROM `{$table}` ORDER BY `{$nameCol}` ASC LIMIT {$limit}";
  $rs  = $mysqli->query($sql);
} else {
  $like="%{$q}%";
  $sql = "SELECT `{$idCol}` AS id, `{$nameCol}` AS name FROM `{$table}` WHERE `{$nameCol}` LIKE ? ORDER BY `{$nameCol}` ASC LIMIT {$limit}";
  $st  = $mysqli->prepare($sql); $st->bind_param("s",$like); $st->execute(); $rs=$st->get_result();
}
$out=[]; while($row=$rs->fetch_assoc()){ $out[]=['id'=>(int)$row['id'],'name'=>$row['name']]; }
if(isset($st)) $st->close(); else $rs->close();

echo json_encode(['ok'=>true,'results'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
