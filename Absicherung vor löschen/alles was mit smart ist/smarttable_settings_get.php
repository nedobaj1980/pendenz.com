<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
  api_bad(401, 'not_authenticated');
}

function table_columns(mysqli $db, string $table): array {
  $sql = "SELECT COLUMN_NAME
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          ORDER BY ORDINAL_POSITION";
  $stmt = $db->prepare($sql);
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $cols = [];
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) { $cols[] = $r['COLUMN_NAME']; }
  $stmt->close();
  return $cols;
}

try {
  global $mysqli;

  // Parameter: ?edit=<smarttable_tables.id> ODER ?table=<name>
  $edit_id = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT);
  $table_name = trim($_GET['table'] ?? '');

  if ($edit_id) {
    $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE id=?");
    $stmt->bind_param('i', $edit_id);
  } elseif ($table_name !== '') {
    $stmt = $mysqli->prepare("SELECT * FROM smarttable_tables WHERE table_name=?");
    $stmt->bind_param('s', $table_name);
  } else {
    api_bad(400, 'edit_or_table_required');
  }
  $stmt->execute(); $trow = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$trow) api_bad(404, 'table_meta_not_found');

  $tid = (int)$trow['id'];
  $tname = $trow['table_name'];
  $physical = $trow['physical_table'] ?: $tname;

  // Rechte: nur Admins dürfen Settings (du kannst es anpassen)
  $role = $_SESSION['rolle'] ?? 'benutzer';
  if (!in_array($role, ['admin','superadmin'], true)) {
    // Erlaube fürs Bauen erstmal auch benutzer:
    // api_bad(403, 'forbidden');
  }

  // Settings
  $stmt = $mysqli->prepare("SELECT * FROM smarttable_settings WHERE table_name=?");
  $stmt->bind_param('s', $tname); $stmt->execute();
  $settings = $stmt->get_result()->fetch_assoc(); $stmt->close();

  // Columns
  $cols = [];
  $stmt = $mysqli->prepare("SELECT id, field, label, type, step, options_text, validate, visible, sort_order, ref_table, ref_field, ref_label_field
                            FROM smarttable_columns WHERE table_id=? ORDER BY sort_order, id");
  $stmt->bind_param('i', $tid); $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){ $cols[] = $r; }
  $stmt->close();

  // Physische Spalten + Liste aller registrierten Tabellen (für relation-dropdown)
  $physical_cols = table_columns($mysqli, $physical);
  $all_tables = [];
  $q = $mysqli->query("SELECT id, table_name FROM smarttable_tables ORDER BY table_name");
  while ($r=$q->fetch_assoc()) { $all_tables[] = $r; }

  api_json(200, [
    'table'    => $trow,
    'settings' => $settings,
    'columns'  => $cols,
    'physical_columns' => $physical_cols,
    'all_tables' => $all_tables
  ]);

} catch (Throwable $e) {
  api_bad(500, 'exception', ['message'=>$e->getMessage(), 'file'=>$e->getFile(), 'line'=>$e->getLine()]);
}
