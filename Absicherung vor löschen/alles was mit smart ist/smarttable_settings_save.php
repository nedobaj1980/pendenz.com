<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) api_bad(401, 'not_authenticated');

try {
  global $mysqli;

  // JSON einlesen
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) api_bad(400, 'invalid_json');

  $table_id = (int)($data['table_id'] ?? 0);
  if (!$table_id) api_bad(400, 'table_id_required');

  // Meta laden
  $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE id=?");
  $stmt->bind_param('i', $table_id); $stmt->execute();
  $trow = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$trow) api_bad(404, 'table_meta_not_found');
  $tname = $trow['table_name'];

  // Rechte
  $role = $_SESSION['rolle'] ?? 'benutzer';
  if (!in_array($role, ['admin','superadmin'], true)) {
    // fürs Bauen übergangsweise offen
    // api_bad(403, 'forbidden');
  }

  // Settings-Teil
  $columns_json   = isset($data['settings']['columns_json'])   ? json_encode($data['settings']['columns_json'])   : null;
  $searchable_json= isset($data['settings']['searchable_json'])? json_encode($data['settings']['searchable_json']): null;
  $editdeny_json  = isset($data['settings']['editdeny_json'])  ? json_encode($data['settings']['editdeny_json'])  : null;
  $soft_delete    = isset($data['settings']['soft_delete'])    ? (int)$data['settings']['soft_delete'] : null;
  $enabled        = isset($data['settings']['enabled'])        ? (int)$data['settings']['enabled'] : null;

  // Upsert Settings
  $stmt = $mysqli->prepare("SELECT id FROM smarttable_settings WHERE table_name=?");
  $stmt->bind_param('s',$tname); $stmt->execute();
  $sid = ($stmt->get_result()->fetch_assoc()['id'] ?? 0); $stmt->close();

  if ($sid) {
    $sql = "UPDATE smarttable_settings SET
              columns_json=COALESCE(?, columns_json),
              searchable_json=COALESCE(?, searchable_json),
              editdeny_json=COALESCE(?, editdeny_json),
              soft_delete=COALESCE(?, soft_delete),
              enabled=COALESCE(?, enabled),
              updated_at=NOW()
            WHERE id=?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('sssiii', $columns_json, $searchable_json, $editdeny_json, $soft_delete, $enabled, $sid);
    $stmt->execute(); $stmt->close();
  } else {
    $sql = "INSERT INTO smarttable_settings (table_name, columns_json, searchable_json, editdeny_json, soft_delete, enabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ssssii', $tname, $columns_json, $searchable_json, $editdeny_json, $soft_delete, $enabled);
    $stmt->execute(); $sid = $stmt->insert_id; $stmt->close();
  }

  // Columns-Teil (Liste von Items; update/insert je nach id)
  $cols = $data['columns'] ?? [];
  foreach ($cols as $c) {
    $cid = (int)($c['id'] ?? 0);
    $field = trim($c['field'] ?? '');
    if ($field === '') continue;

    $label = trim($c['label'] ?? $field);
    $type  = trim($c['type']  ?? 'text');
    $step  = isset($c['step']) ? (string)$c['step'] : null;
    $options_text = $c['options_text'] ?? null;
    $validate     = $c['validate']     ?? null;
    $visible      = isset($c['visible']) ? (int)$c['visible'] : 1;
    $sort_order   = isset($c['sort_order']) ? (int)$c['sort_order'] : 100;

    $ref_table       = $c['ref_table']       ?? null;
    $ref_field       = $c['ref_field']       ?? null;
    $ref_label_field = $c['ref_label_field'] ?? null;

    if ($cid > 0) {
      $sql = "UPDATE smarttable_columns
              SET label=?, type=?, step=?, options_text=?, validate=?, visible=?, sort_order=?, 
                  ref_table=?, ref_field=?, ref_label_field=?,
                  updated_at=NOW()
              WHERE id=? AND table_id=?";
      $stmt = $mysqli->prepare($sql);
      $stmt->bind_param('sssssiisssii',
        $label, $type, $step, $options_text, $validate, $visible, $sort_order,
        $ref_table, $ref_field, $ref_label_field,
        $cid, $table_id
      );
      $stmt->execute(); $stmt->close();
    } else {
      $sql = "INSERT INTO smarttable_columns
              (table_id, field, label, type, step, options_text, validate, visible, sort_order, 
               created_at, updated_at, ref_table, ref_field, ref_label_field, table_name, column_name)
              VALUES (?,?,?,?,?,?,?,?,?, NOW(), NOW(), ?,?,?, '', '')";
      $stmt = $mysqli->prepare($sql);
      $stmt->bind_param('issssssiiisss',
        $table_id, $field, $label, $type, $step, $options_text, $validate, $visible, $sort_order,
        $ref_table, $ref_field, $ref_label_field
      );
      $stmt->execute(); $stmt->close();
    }
  }

  api_json(200, ['ok'=>true, 'settings_id'=>$sid]);

} catch (Throwable $e) {
  api_bad(500,'exception',['message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()]);
}
