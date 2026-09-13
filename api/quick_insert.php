<?php
require_once __DIR__ . '/_bootstrap.php';

require_superadmin_json();

api_try(function () {
  $db = db();
  $in = json_decode(file_get_contents('php://input'), true) ?: [];

  $table  = $in['table'] ?? '';
  $values = $in['values'] ?? [];
  if (!$table || !is_array($values)) json_response(['ok'=>false,'error'=>'Missing params'], 400);

  $ALLOWED = [
    'projekte'  => ['name','titel','beschreibung','sort_index'],
    'pendenzen' => ['titel','beschreibung','status','prioritaet','faellig_am','sort_index'],
  ];
  if (!isset($ALLOWED[$table])) json_response(['ok'=>false,'error'=>'Table not allowed'], 403);

  $cols=[]; $vals=[];
  foreach ($ALLOWED[$table] as $c) if (array_key_exists($c, $values)) { $cols[]=$c; $vals[]=$values[$c]; }
  if (!$cols) json_response(['ok'=>false,'error'=>'No valid fields'], 400);

  $ph = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT INTO `{$table}` (`".implode('`,`',$cols)."`) VALUES ($ph)";
  $stmt = $db->prepare($sql);
  $types = str_repeat('s', count($vals));
  $stmt->bind_param($types, ...$vals);
  $ok = $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();

  json_response(['ok'=>$ok, 'id'=>$id]);
});
