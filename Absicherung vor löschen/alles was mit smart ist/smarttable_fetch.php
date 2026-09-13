<?php
/**
 * /api/smarttable_fetch.php
 * Liefert eine paginierte Liste aus einer in smarttable_tables registrierten Tabelle.
 * - JSON only (keine Redirects/kein HTML)
 * - robust gegen fehlende Spalten / falsche Default-Order
 * - respektiert soft_delete, Projekt-Scope, Rechte (can_view)
 */

ob_start(); // schluckt evtl. BOM/echo vor Headern
if (session_status() === PHP_SESSION_NONE) session_start();

/* Ruhiger Bootstrap (DB + JSON-Helper). Siehe /api/bootstrap.php */
require_once __DIR__ . '/bootstrap.php';

ob_clean(); // alles weg, ab hier nur JSON
header('Content-Type: application/json; charset=utf-8');

/* ---------- Auth: keine Redirects, nur JSON ---------- */
if (empty($_SESSION['user_id'])) {
  api_bad(401, 'not_authenticated');
}

/* ---------- Hilfsfunktionen nur für dieses Script ---------- */

/** Liste physischer Spalten einer Tabelle */
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

/** Prüfen, ob Spalte physisch existiert */
function column_exists(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
          LIMIT 1";
  $stmt = $db->prepare($sql);
  $stmt->bind_param('ss', $table, $col);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

try {
  global $mysqli;

  /* ---------- Eingaben ---------- */
  $table_id   = api_int($_GET['table_id'] ?? 0);
  $table_name = trim($_GET['table'] ?? '');
  $page       = max(1, api_int($_GET['page'] ?? 1));
  $page_size  = min(200, max(1, api_int($_GET['page_size'] ?? 20)));
  $project_id = api_int($_GET['project_id'] ?? 0);
  $search     = trim($_GET['search'] ?? '');
  $order      = json_decode($_GET['order'] ?? '[]', true) ?: [];
  $filters    = json_decode($_GET['filters'] ?? '[]', true) ?: [];

  /* ---------- Tabellen-Metadaten laden ---------- */
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
  if (!$table) api_bad(404,'table_not_found', ['hint'=>'Eintrag in smarttable_tables fehlt']);

  $tid            = (int)$table['id'];
  $physical       = $table['physical_table'] ?: $table['table_name'];
  $pk             = $table['primary_key'] ?: 'id';
  $has_proj_scope = (int)$table['has_project_scope'] === 1;
  $proj_fk        = $table['project_fk_field'] ?: 'projekt_id';
  $default_order  = $table['default_order'] ?: "$pk DESC";
  $always_where   = $table['always_where'] ?? '';

  /* ---------- Spalten-Metadaten + Settings ---------- */
  // definierte Spalten (Labels, Visibility)
  $cols = [];
  $stmt = $mysqli->prepare("SELECT field,label,visible FROM smarttable_columns WHERE table_id=? ORDER BY sort_order, id");
  $stmt->bind_param('i',$tid); $stmt->execute();
  $res = $stmt->get_result();
  while($r=$res->fetch_assoc()){ $cols[$r['field']] = $r; }
  $stmt->close();

  // Settings (searchables, soft_delete, fallback columns_json)
  $stmt = $mysqli->prepare("SELECT searchable_json, soft_delete, columns_json FROM smarttable_settings WHERE table_name=?");
  $stmt->bind_param('s',$table['table_name']); $stmt->execute();
  $set = $stmt->get_result()->fetch_assoc(); $stmt->close();

  $searchables = json_decode($set['searchable_json'] ?? '[]', true) ?: [];
  $soft_delete = (int)($set['soft_delete'] ?? 0) === 1;

  if (!$cols) { // Fallback: columns_json als sichtbare Liste
    foreach (json_decode($set['columns_json'] ?? '[]', true) as $c) {
      $cols[$c] = ['field'=>$c,'label'=>$c,'visible'=>1];
    }
  }
  if (!$cols) api_bad(500,'no_columns_defined');

  // erlaubte Felder lt. Metadaten
  $allowed_fields = array_keys($cols);

  // physisch vorhandene Spalten ermitteln und Schnitt bilden
  $physical_cols = table_columns($mysqli, $physical);
  $allowed_fields = array_values(array_intersect($allowed_fields, $physical_cols));
  if (!$allowed_fields) {
    api_bad(500, 'no_matching_columns', [
      'configured' => array_keys($cols),
      'physical'   => $physical_cols
    ]);
  }
  // Suchfelder ebenfalls nur auf existierende Spalten
  $searchables = array_values(array_intersect($searchables, $allowed_fields));

  /* ---------- Rechte prüfen (View) ---------- */
  $role = $_SESSION['rolle'] ?? 'benutzer';
  $stmt = $mysqli->prepare("SELECT can_view FROM smarttable_access WHERE table_id=? AND role=?");
  $stmt->bind_param('is',$tid,$role); $stmt->execute();
  $acc = $stmt->get_result()->fetch_assoc(); $stmt->close();
  if (!$acc || (int)$acc['can_view'] !== 1) api_bad(403,'forbidden');

  /* ---------- WHERE bauen ---------- */
  $where = []; $params = []; $types = '';

  if ($always_where) $where[] = "($always_where)";
  if ($soft_delete) {
    $deletedCol = 'deleted_at';
    if (in_array($deletedCol, $allowed_fields, true) && column_exists($mysqli, $physical, $deletedCol)) {
      $where[] = "`$deletedCol` IS NULL";
    }
  }
  if ($has_proj_scope && $project_id>0 && in_array($proj_fk,$allowed_fields,true)) {
    $where[]="`$proj_fk` = ?"; $types.='i'; $params[]=$project_id;
  }

  // Filters
  $opmap = ['='=>'=', '!='=>'<>', 'contains'=>'LIKE', 'startswith'=>'LIKE', '>'=>'>', '<'=>'<', '>='=>'>=', '<='=>'<=' , 'between'=>'BETWEEN'];
  foreach ($filters as $f) {
    $field=$f['field']??''; $op=$f['op']??'='; $val=$f['value']??null; $val2=$f['value2']??null;
    if (!in_array($field,$allowed_fields,true) || !isset($opmap[$op])) continue;
    switch($op){
      case 'contains':   $where[]="`$field` LIKE ?"; $types.='s'; $params[]="%$val%"; break;
      case 'startswith': $where[]="`$field` LIKE ?"; $types.='s'; $params[]="$val%";  break;
      case 'between':    if($val!==null && $val2!==null){ $where[]="(`$field` BETWEEN ? AND ?)"; $types.='ss'; $params[]=$val; $params[]=$val2; } break;
      default:           $where[]="`$field` {$opmap[$op]} ?"; $types.='s'; $params[]=$val;
    }
  }

  // Volltext-ähnliche Suche
  if ($search && $searchables) {
    $or=[]; foreach($searchables as $sf){ $or[]="`$sf` LIKE ?"; $types.='s'; $params[]="%$search%"; }
    if ($or) $where[]='('.implode(' OR ', $or).')';
  }

  $where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

  /* ---------- ORDER & LIMIT (robust) ---------- */
  $ob = [];
  foreach ($order as $o) {
    $f = $o[0] ?? '';
    $d = strtolower($o[1] ?? 'asc');
    if (in_array($f, $physical_cols, true)) {
      $ob[] = "`$f` " . ($d === 'desc' ? 'DESC' : 'ASC');
    }
  }

  // Default-Order aus Metadaten sicher parsen (nur existierende Felder)
  $parsed_default = [];
  if (!$ob && $default_order) {
    foreach (explode(',', $default_order) as $part) {
      $part = trim($part);
      if ($part === '') continue;
      if (preg_match('/^`?([a-zA-Z0-9_]+)`?\s*(ASC|DESC)?$/i', $part, $m)) {
        $f = $m[1]; $dir = strtoupper($m[2] ?? 'ASC');
        if (in_array($f, $physical_cols, true)) {
          $parsed_default[] = "`$f` " . ($dir === 'DESC' ? 'DESC' : 'ASC');
        }
      }
    }
  }

  if ($ob) {
    $order_sql = 'ORDER BY ' . implode(', ', $ob);
  } elseif ($parsed_default) {
    $order_sql = 'ORDER BY ' . implode(', ', $parsed_default);
  } else {
    $order_sql = "ORDER BY `$pk` DESC";
  }

  $offset = ($page - 1) * $page_size;

  /* ---------- COUNT ---------- */
  $sql_count = "SELECT COUNT(*) c FROM `$physical` $where_sql";
  $stmt = $mysqli->prepare($sql_count);
  if ($types) { $stmt->bind_param($types, ...$params); }
  $stmt->execute(); $total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0); $stmt->close();

  /* ---------- ROWS ---------- */
  $fields_sql = implode(',', array_map(fn($f)=>"`$f`", $allowed_fields));
  $sql_rows = "SELECT $fields_sql FROM `$physical` $where_sql $order_sql LIMIT ? OFFSET ?";
  $stmt = $mysqli->prepare($sql_rows);
  $types2 = $types.'ii'; $params2 = $params; $params2[] = $page_size; $params2[] = $offset;
  $stmt->bind_param($types2, ...$params2);
  $stmt->execute(); $res = $stmt->get_result();

  $rows=[]; while($r=$res->fetch_assoc()){ $rows[]=$r; }
  $stmt->close();

  /* ---------- Columns-Array fürs Frontend ---------- */
  $columns=[]; foreach($allowed_fields as $f){
    $meta = $cols[$f] ?? ['label'=>$f, 'visible'=>1];
    $columns[] = [
      'field'   => $f,
      'label'   => $meta['label'] ?? $f,
      'visible' => (int)($meta['visible'] ?? 1) === 1
    ];
  }

  /* ---------- Antwort ---------- */
  api_json(200, [
    'table'=>['id'=>$tid,'name'=>$table['table_name'],'physical'=>$physical,'pk'=>$pk],
    'page'=>$page,'page_size'=>$page_size,'total'=>$total,
    'columns'=>$columns,'rows'=>$rows
  ]);

} catch (Throwable $e) {
  // DEV: präzise Fehlermeldung als JSON (kein HTML)
  api_bad(500,'exception',[
    'message'=>$e->getMessage(),
    'file'=>$e->getFile(),
    'line'=>$e->getLine()
  ]);
}
