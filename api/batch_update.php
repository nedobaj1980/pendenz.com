<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_superadmin_json();

api_try(function () {
  $db = db();

  // Eingabe
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $table   = $in['table']   ?? '';
  $updates = $in['updates'] ?? []; // [{id: 1, changes: {feld: wert, ...}}, ...]
  $order   = $in['order']   ?? []; // [id1, id2, id3, ...] optional
  $orderField = $in['orderField'] ?? null;

  if (!$table || !is_array($updates)) {
    json_response(['ok'=>false,'error'=>'Missing table or updates'], 400);
  }

  // zulässige Tabellen (Synonyme erlaubt)
  $TABLE_MAP = [
    'users'     => 'users',
    'benutzer'  => 'users',
    'projekte'  => 'projekte',
    'projects'  => 'projekte',
    'pendenzen' => 'pendenzen',
    'tasks'     => 'pendenzen',
  ];
  $tkey = strtolower($table);
  if (!isset($TABLE_MAP[$tkey])) {
    json_response(['ok'=>false,'error'=>'Table not allowed'], 403);
  }
  $T = $TABLE_MAP[$tkey];

  if (!table_exists($db, $T)) {
    json_response(['ok'=>false,'error'=>'Table not found'], 404);
  }

  // dynamisch erlaubte Felder = alle existierenden Spalten der Tabelle
  $allowedCols = cols_of($db, $T); // -> array of column names

  $errors  = [];
  $updated = 0;

  $db->begin_transaction();

  // Reihenfolge speichern (wenn gewünscht)
  if ($order && $orderField && in_array($orderField, $allowedCols, true)) {
    $pos = 1;
    $stmtOrd = $db->prepare("UPDATE `$T` SET `$orderField`=? WHERE id=?");
    foreach ($order as $id) {
      $id = (int)$id;
      $stmtOrd->bind_param('ii', $pos, $id);
      if (!$stmtOrd->execute()) {
        $errors[] = ['id'=>$id,'field'=>$orderField,'error'=>$db->error];
      }
      $pos++;
    }
    $stmtOrd->close();
  }

  // Inline-Änderungen je Zeile
  foreach ($updates as $up) {
    $id = (int)($up['id'] ?? 0);
    $changes = $up['changes'] ?? [];
    if (!$id || !is_array($changes) || !$changes) continue;

    // Filter auf existierende Spalten
    $set = [];
    $vals = [];
    $types = '';

    foreach ($changes as $col => $val) {
      if (!in_array($col, $allowedCols, true)) {
        $errors[] = ['id'=>$id,'field'=>$col,'error'=>'column_not_exists'];
        continue;
      }
      $set[] = "`$col`=?";
      if (is_int($val))      { $types .= 'i'; $vals[] = $val; }
      elseif (is_float($val)){ $types .= 'd'; $vals[] = $val; }
      else                   { $types .= 's'; $vals[] = (string)$val; }
    }

    if (!$set) continue;

    $sql = "UPDATE `$T` SET ".implode(',', $set)." WHERE id=?";
    $stmt = $db->prepare($sql);
    $types .= 'i';
    $vals[] = $id;

    $stmt->bind_param($types, ...$vals);
    if ($stmt->execute()) {
      $updated += ($stmt->affected_rows >= 0 ? 1 : 0);
    } else {
      $errors[] = ['id'=>$id,'error'=>$db->error];
    }
    $stmt->close();
  }

  $db->commit();

  json_response([
    'ok'      => true,
    'updated' => $updated,
    'errors'  => $errors,
  ]);
});

/**
 * liefert Spaltennamen einer Tabelle
 */
function cols_of(mysqli $db, string $table): array {
  $cols = [];
  $res = $db->query("SHOW COLUMNS FROM `$table`");
  while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
  $res->free();
  return $cols;
}
