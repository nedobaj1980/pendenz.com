<?php
require_once __DIR__ . '/_bootstrap.php';

require_superadmin_json();

api_try(function () {
  $db = db();
  $in = json_decode(file_get_contents('php://input'), true) ?: [];

  $table = $in['table'] ?? '';
  $id    = (int)($in['id'] ?? 0);
  $field = $in['field'] ?? '';
  $value = $in['value'] ?? '';

  if (!$table || !$id || !$field) {
    json_response(['ok'=>false,'error'=>'Missing params'], 400);
  }

  // Erlaubte Tabellen + Felder (mit gängigen Synonymen)
  $ALLOWED = [
    'projekte'  => ['name','titel','title','beschreibung','beschreibung_kurz','sort_index'],
    'pendenzen' => ['status','titel','title','name','beschreibung','beschreibung_kurz','prioritaet','prio','faellig_am','due_date','sort_index'],
  ];
  if (!isset($ALLOWED[$table])) {
    json_response(['ok'=>false,'error'=>'Table not allowed'], 403);
  }
  if (!in_array($field, $ALLOWED[$table], true)) {
    json_response(['ok'=>false,'error'=>'Field not allowed'], 403);
  }
  if (!col_exists($db, $table, $field)) {
    json_response(['ok'=>false,'error'=>'Column does not exist in table'], 400);
  }

  $sql  = "UPDATE `{$table}` SET `{$field}`=? WHERE id=?";
  $stmt = $db->prepare($sql);
  $stmt->bind_param("si", $value, $id);
  $ok = $stmt->execute();
  $stmt->close();

  json_response(['ok'=>$ok]);
});
