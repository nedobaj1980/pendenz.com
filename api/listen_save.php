<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$uid   = $_SESSION['user_id'] ?? null;
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$id         = (int)($input['id'] ?? 0);
$name       = trim((string)($input['name'] ?? ''));
$table      = trim((string)($input['table_name'] ?? 'pendenzen'));
$filters    = $input['filters'] ?? [];
$sort_col   = trim((string)($input['sort_col'] ?? ''));
$sort_dir   = (strtolower($input['sort_dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc';
$per_page   = max(5, min(200, (int)($input['per_page'] ?? 25)));
$columns    = $input['columns'] ?? []; 
$shared     = !empty($input['shared']) ? 1 : 0;
$is_default = !empty($input['is_default']) ? 1 : 0;

if ($name==='') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'name_required']); exit; }

$filters_json = json_encode($filters, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

if ($is_default) {
    // Falls diese Liste Standard wird, alle anderen für diese Tabelle/Besitzer deaktivieren
    $mysqli->query("UPDATE listen SET is_default=0 WHERE table_name='{$mysqli->real_escape_string($table)}' AND owner_id=" . (int)$uid);
}

if ($id>0) {
  // Update
  $stmt = $mysqli->prepare("UPDATE listen SET name=?, table_name=?, filters_json=?, sort_col=?, sort_dir=?, per_page=?, shared=?, is_default=?, updated_at=NOW() WHERE id=?");
  $stmt->bind_param('ssssssiii', $name, $table, $filters_json, $sort_col, $sort_dir, $per_page, $shared, $is_default, $id);
  $stmt->execute();
  $stmt->close();

  // Spalten ersetzen
  $stmt = $mysqli->prepare("DELETE FROM listen_spalten WHERE listen_id=?");
  $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();

  if (is_array($columns)) {
    $stmt = $mysqli->prepare("INSERT INTO listen_spalten (listen_id,col_name,sort_order,width_desktop,width_ipad,width_mobile,visible_desktop,visible_ipad,visible_mobile) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($columns as $idx=>$c) {
      $col = (string)($c['col_name'] ?? '');
      if ($col==='') continue;
      $ord = (int)($c['sort_order'] ?? (($idx+1)*10));
      $w_d = isset($c['width_desktop']) ? (string)$c['width_desktop'] : null;
      $w_i = isset($c['width_ipad']) ? (string)$c['width_ipad'] : null;
      $w_m = isset($c['width_mobile']) ? (string)$c['width_mobile'] : null;
      $v_d = isset($c['visible_desktop']) ? (int)$c['visible_desktop'] : 1;
      $v_i = isset($c['visible_ipad']) ? (int)$c['visible_ipad'] : 1;
      $v_m = isset($c['visible_mobile']) ? (int)$c['visible_mobile'] : 1;

      $stmt->bind_param('isisssiii', $id, $col, $ord, $w_d, $w_i, $w_m, $v_d, $v_i, $v_m);
      $stmt->execute();
    }
    $stmt->close();
  }
  echo json_encode(['ok'=>true,'id'=>$id]); exit;
} else {
  // Insert
  $stmt = $mysqli->prepare("INSERT INTO listen (name, table_name, filters_json, sort_col, sort_dir, per_page, owner_id, shared, is_default) VALUES (?,?,?,?,?,?,?,?,?)");
  $stmt->bind_param('ssssssiii', $name, $table, $filters_json, $sort_col, $sort_dir, $per_page, $uid, $shared, $is_default);
  $stmt->execute();
  $newId = $stmt->insert_id;
  $stmt->close();

  if (is_array($columns) && $newId) {
    $stmt = $mysqli->prepare("INSERT INTO listen_spalten (listen_id,col_name,sort_order,width_desktop,width_ipad,width_mobile,visible_desktop,visible_ipad,visible_mobile) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($columns as $idx=>$c) {
      $col = (string)($c['col_name'] ?? '');
      if ($col==='') continue;
      $ord = (int)($c['sort_order'] ?? (($idx+1)*10));
      $w_d = isset($c['width_desktop']) ? (string)$c['width_desktop'] : null;
      $w_i = isset($c['width_ipad']) ? (string)$c['width_ipad'] : null;
      $w_m = isset($c['width_mobile']) ? (string)$c['width_mobile'] : null;
      $v_d = isset($c['visible_desktop']) ? (int)$c['visible_desktop'] : 1;
      $v_i = isset($c['visible_ipad']) ? (int)$c['visible_ipad'] : 1;
      $v_m = isset($c['visible_mobile']) ? (int)$c['visible_mobile'] : 1;

      $stmt->bind_param('isisssiii', $newId, $col, $ord, $w_d, $w_i, $w_m, $v_d, $v_i, $v_m);
      $stmt->execute();
    }
    $stmt->close();
  }
  echo json_encode(['ok'=>true,'id'=>$newId]); exit;
}
