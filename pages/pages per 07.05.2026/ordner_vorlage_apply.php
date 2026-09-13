<?php
// pages/ordner_vorlage_apply.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Projekt-Kontext
require_once __DIR__ . '/../includes/project_ctx.php';
$projectId = (int)($GLOBALS['__projekt_id'] ?? 0);

require_once __DIR__ . '/../includes/fs.php'; // project_root_path(), fs_abs_from_rel(), fs_scan_project()

/* ---------- Helpers: DB-Metadaten ---------- */
function table_exists(mysqli $db, string $table): bool {
  $st = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
  $st->bind_param("s",$table); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $st = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
  $st->bind_param("ss",$table,$col); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/* ---------- Eingaben ---------- */
$vorlage_id = (int)($_GET['vorlage_id'] ?? 0);
if ($projectId <= 0)  die("❌ Kein Projektkontext.");
if ($vorlage_id <= 0) die("❌ Keine Vorlage gewählt.");

/* ---------- Projekt + Root prüfen ---------- */
$root = project_root_path($mysqli, $projectId);
if (!$root || !is_dir($root)) {
  $msg = "❌ Projekt-Root fehlt oder ist ungültig. Bitte zuerst unter „Projekt-Speicherort“ setzen.";
  header('Location: '.url('pages/ordner_vorlagen.php?projekt_id='.$projectId.'&msg='.rawurlencode($msg)));
  exit;
}

/* ---------- Vorlage laden ---------- */
$vorlage = null;
if (table_exists($mysqli, 'ordner_vorlagen')) {
  $st = $mysqli->prepare("SELECT id, name, ".(column_exists($mysqli,'ordner_vorlagen','version')?'version':'0 AS version')." FROM ordner_vorlagen WHERE id=?");
  $st->bind_param("i",$vorlage_id); $st->execute();
  $vorlage = $st->get_result()->fetch_assoc(); $st->close();
}
if (!$vorlage) die("❌ Vorlage #{$vorlage_id} nicht gefunden.");
$vorlage_version = (int)($vorlage['version'] ?? 0);

/* ---------- Knoten (Ordner) laden – zwei unterstützte Schemata ---------- */
function load_nodes_schema_nodes(mysqli $db, int $vid): array {
  if (!table_exists($db,'ordner_vorlagen_nodes')) return [];
  $have = [];
  $res = $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ordner_vorlagen_nodes'");
  while ($r = $res->fetch_row()) $have[$r[0]] = true;
  if (empty($have['rel_path'])) return [];
  $sql = "SELECT rel_path".(isset($have['is_dir'])?", is_dir":" , 1 AS is_dir")."
          FROM ordner_vorlagen_nodes
          WHERE vorlage_id=?
          ORDER BY LENGTH(rel_path) ASC, rel_path ASC";
  $st = $db->prepare($sql); $st->bind_param("i",$vid); $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  return $rows;
}
function load_nodes_schema_unterkategorien(mysqli $db, int $vid): array {
  if (!table_exists($db,'unterkategorien')) return [];
  $hasSort = column_exists($db,'unterkategorien','sort');
  $hasLbl  = column_exists($db,'unterkategorien','label_default');
  $hasName = column_exists($db,'unterkategorien','name') || column_exists($db,'unterkategorien','name_variable');

  $st = $db->prepare("
    SELECT id,
           parent_id,
           ".($hasLbl ? "label_default" : ($hasName ? "name" : "''"))." AS label_default,
           ".(column_exists($db,'unterkategorien','name_variable') ? "name_variable" : "''")." AS name_variable,
           ".($hasSort ? "sort" : "0 AS sort")."
    FROM unterkategorien
    WHERE vorlage_id=?
    ORDER BY parent_id IS NULL DESC, ".($hasSort ? "sort ASC, " : "")."id ASC
  ");
  $st->bind_param("i",$vid); $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  if (!$rows) return [];

  $nodes = []; $roots = [];
  foreach ($rows as $r){
    $id=(int)$r['id'];
    $nodes[$id] = [
      'id'=>$id,
      'parent_id'=> $r['parent_id']!==null ? (int)$r['parent_id'] : null,
      'label'=> trim((string)$r['label_default']) ?: trim((string)$r['name_variable']),
      'children'=>[],
    ];
  }
  foreach ($nodes as $id=>&$n) { $pid = $n['parent_id']; if ($pid && isset($nodes[$pid])) $nodes[$pid]['children'][] = $id; else $roots[] = $id; } unset($n);
  $calc = function($id) use (&$nodes,&$calc): string {
    $n = $nodes[$id]; $seg = $n['label'] !== '' ? $n['label'] : ('Ordner-'.$id);
    return $n['parent_id'] ? ($calc($n['parent_id']).'/'.$seg) : $seg;
  };

  $out = [];
  foreach ($nodes as $id=>$_) $out[] = ['rel_path'=>$calc($id), 'is_dir'=>1];
  usort($out, fn($a,$b)=>substr_count($a['rel_path'],'/') <=> substr_count($b['rel_path'],'/'));
  return $out;
}
$nodes = load_nodes_schema_nodes($mysqli, $vorlage_id);
if (!$nodes) $nodes = load_nodes_schema_unterkategorien($mysqli, $vorlage_id);
if (!$nodes) die("❌ Keine Ordnerdefinitionen in dieser Vorlage gefunden.");

/* ---------- Sanitizer (Windows) ---------- */
function sanitize_segment(string $s): string {
  $s = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]/u', '_', $s);
  $s = rtrim($s, " .\t"); $s = trim($s);
  if ($s === '') $s = 'Ordner';
  if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $s)) $s = '_'.$s;
  if (mb_strlen($s) > 240) $s = mb_substr($s, 0, 240);
  return $s;
}
function sanitize_rel_path(string $rel): string {
  $rel = str_replace('\\','/',$rel);
  $rel = trim($rel, '/ ');
  $parts = $rel === '' ? [] : explode('/', $rel);
  $parts = array_map('sanitize_segment', $parts);
  $parts = array_values(array_filter($parts, fn($p)=>$p!==''));
  return implode('/', $parts);
}

/* ---------- Ordnerliste vorbereiten (Eltern zuerst) ---------- */
$dirRelPaths = [];
foreach ($nodes as $n) {
  if ((int)($n['is_dir'] ?? 1) !== 1) continue;
  $rel = sanitize_rel_path((string)$n['rel_path']);
  if ($rel === '') continue;
  $dirRelPaths[$rel] = true;
}
$dirRelPaths = array_keys($dirRelPaths);
usort($dirRelPaths, fn($a,$b)=>substr_count($a,'/') <=> substr_count($b,'/')); // Eltern vor Kindern

/* ---------- Ordner physisch anlegen ---------- */
$created = 0; $skipped = 0; $errors = 0; $errList = [];
foreach ($dirRelPaths as $rel) {
  $abs = fs_abs_from_rel($root, $rel);
  if ($abs === null) { $errors++; $errList[] = "Pfad ausserhalb Root blockiert: {$rel}"; continue; }
  if (is_dir($abs)) { $skipped++; continue; }
  if (@mkdir($abs, 0777, true)) { $created++; }
  else { if (!is_dir($abs)) { $errors++; $errList[] = "mkdir fehlgeschlagen: ".$abs; } }
}

/* ---------- Projekt ↔ Vorlage koppeln (wenn Spalte existiert) ---------- */
if (column_exists($mysqli,'projekte','ordner_vorlage_id')) {
  $st = $mysqli->prepare("UPDATE projekte SET ordner_vorlage_id=? WHERE id=?");
  $st->bind_param("ii",$vorlage_id,$projectId); $st->execute(); $st->close();
}

/* ---------- Apply-Log (UPSERT) ---------- */
if (table_exists($mysqli,'ordner_vorlage_applied')) {
  // Welche Spalten gibt's?
  $cols = [];
  $res = $mysqli->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ordner_vorlage_applied'");
  while ($r = $res->fetch_row()) $cols[$r[0]] = true;

  $hasAppliedAt = !empty($cols['applied_at']);
  $hasCreated   = !empty($cols['created_count']);
  $hasSkipped   = !empty($cols['skipped_count']);
  $hasError     = !empty($cols['error_count']);
  $hasVersion   = !empty($cols['version']); // muss für den Unique-Key vorhanden sein

  // Basisspalten
  $fields = ["vorlage_id","projekt_id"];
  $ph     = ["?","?"];
  $types  = "ii";
  $args   = [$vorlage_id,$projectId];

  if ($hasVersion) { $fields[]="version"; $ph[]="?"; $types.="i"; $args[]=$vorlage_version; }
  if ($hasAppliedAt) { $fields[]="applied_at"; $ph[]="NOW()"; } // NOW() direkt
  if ($hasCreated)   { $fields[]="created_count"; $ph[]="?"; $types.="i"; $args[]=$created; }
  if ($hasSkipped)   { $fields[]="skipped_count"; $ph[]="?"; $types.="i"; $args[]=$skipped; }
  if ($hasError)     { $fields[]="error_count"; $ph[]="?"; $types.="i"; $args[]=$errors; }

  $updates = [];
  if ($hasAppliedAt) $updates[] = "applied_at = NOW()";
  if ($hasCreated)   $updates[] = "created_count = created_count + VALUES(created_count)";
  if ($hasSkipped)   $updates[] = "skipped_count = skipped_count + VALUES(skipped_count)";
  if ($hasError)     $updates[] = "error_count = error_count + VALUES(error_count)";
  if (empty($updates)) $updates[] = ($hasVersion ? "version=version" : "vorlage_id=vorlage_id");

  $sql = "INSERT INTO ordner_vorlage_applied (".implode(',',$fields).") VALUES (".implode(',',$ph).")
          ON DUPLICATE KEY UPDATE ".implode(', ',$updates);

  // prepare: NOW() steht als Literal im SQL -> wir müssen nur die ? binden
  $st = $mysqli->prepare($sql);
  if ($types !== '') {
    // Zähle nur die Anzahl der ? in $sql und binde entsprechend
    $qCount = substr_count($sql, '?');
    if ($qCount !== strlen($types)) {
      // Falls z.B. keine optionalen Felder vorhanden sind
      $types = substr($types, 0, $qCount);
      $args  = array_slice($args, 0, $qCount);
    }
    if ($qCount > 0) $st->bind_param($types, ...$args);
  }
  $st->execute(); $st->close();
}

/* ---------- Re-Scan ---------- */
$scan = fs_scan_project($mysqli, $projectId, 0);
$scanMsg = $scan['ok'] ? "Scan: {$scan['count']} Einträge" : ("Scan-Fehler: ".$scan['msg']);

/* ---------- Redirect ---------- */
$back = url('pages/ordner_vorlagen.php?projekt_id='.$projectId
          .'&applied=1'
          .'&created='.$created
          .'&skipped='.$skipped
          .'&errors='.$errors
          .'&scan='.rawurlencode($scanMsg));

if ($errors > 0) {
  $short = implode(' | ', array_slice($errList,0,5));
  $back .= '&warn='.rawurlencode($short.(count($errList)>5?' …':'')); 
}

header('Location: '.$back);
exit;
