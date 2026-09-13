<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['error'=>'db_missing']); exit;
}
if (!in_array(($_SESSION['rolle'] ?? 'gast'), ['admin','superadmin'], true)) {
  http_response_code(403);
  echo json_encode(['error'=>'forbidden']); exit;
}

$table = trim($_GET['table'] ?? '');
if ($table === '') { echo json_encode(['error'=>'table_required']); exit; }

try {
  // Spalten + Datentyp
  $stmt=$mysqli->prepare(
    "SELECT COLUMN_NAME, DATA_TYPE
     FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ?
     ORDER BY ORDINAL_POSITION"
  );
  $stmt->bind_param('s',$table); $stmt->execute();
  $res=$stmt->get_result();
  $cols=[]; $searchable=[];
  while($r=$res->fetch_assoc()){
    $cols[]=$r;
    $t=strtolower($r['DATA_TYPE']);
    if (in_array($t,['varchar','text','mediumtext','longtext','char'],true)) $searchable[]=$r['COLUMN_NAME'];
  }
  $stmt->close();

  // PK
  $stmt=$mysqli->prepare(
    "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name='PRIMARY' LIMIT 1"
  );
  $stmt->bind_param('s',$table); $stmt->execute();
  $pk = ($stmt->get_result()->fetch_assoc()['COLUMN_NAME'] ?? null);
  $stmt->close();

  // Virtuelle Felder (SaaS)
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $resV = $mysqli->query("SELECT field_key, label FROM pendenz_field_defs WHERE mandant_id = $uid AND enabled=1");
  if ($resV) {
      while($v = $resV->fetch_assoc()) {
          $cols[] = ['COLUMN_NAME' => 'json:' . $v['field_key'], 'DATA_TYPE' => 'virtual'];
      }
  }

  echo json_encode([
    'ok'=>true,
    'table'=>$table,
    'columns'=>$cols,
    'pk'=>$pk,
    'suggest_searchable'=>array_slice($searchable,0,5),
    'settings'=>null
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'exception','message'=>$e->getMessage()]); 
}
