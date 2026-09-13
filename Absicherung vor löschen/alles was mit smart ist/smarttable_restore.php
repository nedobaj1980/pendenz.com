<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) {
  api_bad(401, 'not_authenticated');
}

try {
  global $mysqli;

  $table_id   = api_int($_POST['table_id'] ?? 0);
  $table_name = trim($_POST['table'] ?? '');
  $id         = api_int($_POST['id'] ?? 0);
  if ($id <= 0) api_bad(400,'missing_id');

  // Tabellen-Metadaten
  if ($table_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE id=?");
    $stmt->bind_param('i',$table_id);
  } elseif ($table_name !== '') {
    $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE table_name=?");
    $stmt->bind_param('s',$table_name);
  } else {
    api_bad(400,'table_id_or_name_required');
  }
  $stmt->execute(); $table = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$table) api_bad(404,'table_not_found');

  $tid      = (int)$table['id'];
  $physical = $table['physical_table'] ?: $table['table_name'];
  $pk       = $table['primary_key'] ?: 'id';

  // Rechte (edit reicht für restore)
  $role = $_SESSION['rolle'] ?? 'benutzer';
  $stmt = $mysqli->prepare("SELECT can_edit FROM smarttable_access WHERE table_id=? AND role=?");
  $stmt->bind_param('is',$tid,$role); $stmt->execute();
  $acc = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$acc || (int)$acc['can_edit'] !== 1) api_bad(403,'forbidden');

  // Restore nur wenn deleted_at existiert
  $q = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at' LIMIT 1";
  $s = $mysqli->prepare($q); $s->bind_param('s',$physical); $s->execute();
  $hasDeletedAt = (bool)$s->get_result()->fetch_row(); $s->close();
  if (!$hasDeletedAt) api_bad(400,'no_deleted_at');

  $stmt = $mysqli->prepare("UPDATE `$physical` SET `deleted_at` = NULL WHERE `$pk` = ? LIMIT 1");
  $stmt->bind_param('i',$id); $stmt->execute(); $aff = $stmt->affected_rows; $stmt->close();

  api_json(200, ['ok'=>true,'affected'=>$aff]);
} catch (Throwable $e) {
  api_bad(500,'exception',['message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
}
