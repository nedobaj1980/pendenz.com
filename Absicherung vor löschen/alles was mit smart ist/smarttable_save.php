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
  $fields     = $_POST['fields'] ?? []; // erwartet assoziatives Array (Form-POST) ODER JSON (siehe unten)

  // JSON body unterstützen
  if (empty($fields) && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if (is_array($j)) {
      $table_id   = $j['table_id']   ?? $table_id;
      $table_name = $j['table']      ?? $table_name;
      $id         = $j['id']         ?? $id;
      $fields     = $j['fields']     ?? [];
    }
  }

  if (!$table_id && !$table_name) api_bad(400,'table_id_or_name_required');
  // Tabellen-Metadaten
  if ($table_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE id=?");
    $stmt->bind_param('i',$table_id);
  } else {
    $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE table_name=?");
    $stmt->bind_param('s',$table_name);
  }
  $stmt->execute(); $table = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$table) api_bad(404,'table_not_found');

  $tid      = (int)$table['id'];
  $physical = $table['physical_table'] ?: $table['table_name'];
  $pk       = $table['primary_key'] ?: 'id';

  // Rechte
  $role = $_SESSION['rolle'] ?? 'benutzer';
  $fieldNeeded = ($id>0) ? 'can_edit' : 'can_create';
  $stmt = $mysqli->prepare("SELECT $fieldNeeded AS allowed FROM smarttable_access WHERE table_id=? AND role=?");
  $stmt->bind_param('is',$tid,$role); $stmt->execute();
  $acc = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$acc || (int)$acc['allowed'] !== 1) api_bad(403,'forbidden');

  // Whitelist-Felder laden
  $allowed = [];
  $stmt = $mysqli->prepare("SELECT field FROM smarttable_columns WHERE table_id=?");
  $stmt->bind_param('i',$tid); $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){ $allowed[]=$r['field']; }
  $stmt->close();

  // Edit-Deny aus Settings
  $stmt = $mysqli->prepare("SELECT editdeny_json FROM smarttable_settings WHERE table_name=?");
  $stmt->bind_param('s',$table['table_name']); $stmt->execute();
  $set = $stmt->get_result()->fetch_assoc(); $stmt->close();
  $deny = json_decode($set['editdeny_json'] ?? '[]', true) ?: [];

  // Eingaben filtern
  $payload = [];
  foreach ($fields as $k=>$v) {
    if (!in_array($k, $allowed, true)) continue;
    if (in_array($k, $deny, true)) continue;
    $payload[$k] = $v;
  }
  if (empty($payload)) api_bad(400,'no_allowed_fields');

  // SQL bauen
  if ($id > 0) {
    // UPDATE
    $sets = []; $types=''; $params=[];
    foreach ($payload as $k=>$v) { $sets[] = "`$k` = ?"; $types.='s'; $params[]=$v; }
    // updated_at, falls vorhanden
    $hasUpdated = false;
    $q = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'updated_at' LIMIT 1";
    $s = $mysqli->prepare($q); $s->bind_param('s',$physical); $s->execute();
    $hasUpdated = (bool)$s->get_result()->fetch_row(); $s->close();
    if ($hasUpdated) { $sets[] = "`updated_at` = NOW()"; }

    $sql = "UPDATE `$physical` SET ".implode(', ',$sets)." WHERE `$pk` = ? LIMIT 1";
    $types.='i'; $params[] = $id;

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $aff = $stmt->affected_rows; $stmt->close();

    api_json(200, ['ok'=>true,'updated'=>$aff,'id'=>$id]);
  } else {
    // INSERT
    $cols = array_keys($payload);
    $ph   = array_fill(0, count($cols), '?');
    $types = str_repeat('s', count($cols));
    $params = array_values($payload);

    // created_at vorhanden?
    $hasCreated = false;
    $q = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'created_at' LIMIT 1";
    $s = $mysqli->prepare($q); $s->bind_param('s',$physical); $s->execute();
    $hasCreated = (bool)$s->get_result()->fetch_row(); $s->close();
    if ($hasCreated) {
      $cols[] = 'created_at'; $ph[] = 'NOW()'; // direkter NOW() ohne Bind
    }

    $sql = "INSERT INTO `$physical` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$ph).")";
    $stmt = $mysqli->prepare($sql);
    // Achtung: NOW() hat kein Parameter → Anzahl an ? könnte kleiner sein
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $newId = $stmt->insert_id; $stmt->close();

    api_json(200, ['ok'=>true,'inserted_id'=>$newId]);
  }

} catch (Throwable $e) {
  api_bad(500,'exception',['message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
}
