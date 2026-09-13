<?php
// pages/pendenzen_settings.php
// Ziel: super einfache Bedienung (3 Schritte) + Live-Vorschau mit Direkt-Eingabe.
// Profi-Funktionen sind einklappbar, bestehende Actions/SQL bleiben identisch.

if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }

$PREFIX = site_prefix();
$flash  = "";

/* ---------- Ensure Zusatz-Tabellen & pendenzen.extra_json ---------- */
$mysqli->query("
  CREATE TABLE IF NOT EXISTS pendenz_field_defs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    type ENUM('text','number','date','select','checkbox') NOT NULL DEFAULT 'text',
    options_json JSON NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$mysqli->query("
  CREATE TABLE IF NOT EXISTS pendenz_export_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    columns_json TEXT NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$colCheck = $mysqli->query("
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='pendenzen' AND COLUMN_NAME='extra_json'
")->fetch_row();
if (!$colCheck) { $mysqli->query("ALTER TABLE pendenzen ADD COLUMN extra_json JSON NULL DEFAULT NULL"); }

/* ---------- Spaltenkatalog (Labels) ---------- */
$BASE = [
  'titel'           => 'Titel',
  'projekt_name'    => 'Projekt',
  'startdatum'      => 'Startdatum',
  'enddatum'        => 'Fällig',
  'status'          => 'Status',
  'wichtigkeit'     => 'Wichtigkeit',
  'sichtbarkeit'    => 'Sichtbarkeit',
  'erstellt_am'     => 'Erstellt',
  'aktualisiert_am' => 'Aktualisiert',
];
$ALL_COLUMNS = $BASE;
// dyn. Zusatzfelder (für Labels) eintragen
$resDynLabels = $mysqli->query("SELECT field_key,label FROM pendenz_field_defs WHERE enabled=1 ORDER BY sort_order, id");
while($d=$resDynLabels->fetch_assoc()) $ALL_COLUMNS['json:'.$d['field_key']] = $d['label'];

/* ---------- Helpers ---------- */
function ident_ok($s){ return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$s); }
function parse_cols($json) {
  $arr = json_decode($json ?? '[]', true);
  if (!is_array($arr)) return [];
  $out=[];
  foreach($arr as $it){
    if (is_string($it)) $out[]=$it;
    elseif (is_array($it) && isset($it['name']) && (!isset($it['visible']) || $it['visible'])) $out[]=(string)$it['name'];
  }
  return $out;
}
function get_profile_by_name(mysqli $db, string $name) {
  $st=$db->prepare("SELECT * FROM pendenz_export_profiles WHERE name=? LIMIT 1");
  $st->bind_param("s",$name); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
  return $r ?: null;
}
function save_profile_visible_only(mysqli $db, string $name, array $orderedVisibleNames, int $is_default, int $user_id){
  $json = json_encode(array_values($orderedVisibleNames), JSON_UNESCAPED_UNICODE);
  if ($is_default) $db->query("UPDATE pendenz_export_profiles SET is_default=0");
  $ex = get_profile_by_name($db,$name);
  if ($ex){
    $st=$db->prepare("UPDATE pendenz_export_profiles SET columns_json=?, is_default=? WHERE id=?");
    $id=(int)$ex['id']; $st->bind_param("sii",$json,$is_default,$id); $st->execute(); $st->close();
    return $id;
  } else {
    $st=$db->prepare("INSERT INTO pendenz_export_profiles (name,columns_json,is_default,created_by) VALUES (?,?,?,?)");
    $st->bind_param("ssii",$name,$json,$is_default,$user_id); $st->execute(); $id=(int)$st->insert_id; $st->close();
    return $id;
  }
}
function db_tables(mysqli $db): array {
  $out=[]; $res=$db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
  if ($res) { while ($r=$res->fetch_array(MYSQLI_NUM)) $out[]=$r[0]; }
  return $out;
}
function fetch_table_columns(mysqli $db, string $table): array {
  $st=$db->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, EXTRA
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
  $st->bind_param("s",$table); $st->execute(); $res=$st->get_result(); $cols=[];
  while($r=$res->fetch_assoc()) $cols[]=$r;
  $st->close(); return $cols;
}
function sql_def_for_column(array $c): string {
  $type = strtolower($c['DATA_TYPE']);
  $ctype = strtolower($c['COLUMN_TYPE']);
  $sqlType = '';
  if ($type==='enum') {
    $sqlType = strtoupper($ctype);
  } elseif ($type==='varchar') {
    $len = (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?? 255);
    $sqlType = "VARCHAR($len)";
  } elseif ($type==='decimal') {
    $p = (int)($c['NUMERIC_PRECISION'] ?? 10);
    $s = (int)($c['NUMERIC_SCALE'] ?? 0);
    $sqlType = "DECIMAL($p,$s)";
  } elseif ($type==='tinyint') {
    if (preg_match('/tinyint\(\d+\)/i',$ctype)) $sqlType = strtoupper($ctype); else $sqlType = "TINYINT(1)";
  } else {
    $sqlType = strtoupper($ctype ?: $type);
  }
  $null = (strtoupper($c['IS_NULLABLE'])==='YES') ? "NULL" : "NOT NULL";
  $def = $c['COLUMN_DEFAULT'];
  $defSql = '';
  if ($def !== null) {
    if (is_numeric($def)) $defSql = " DEFAULT ".$def;
    elseif (strtoupper((string)$def)==='CURRENT_TIMESTAMP') $defSql = " DEFAULT CURRENT_TIMESTAMP";
    else $defSql = " DEFAULT '".addslashes((string)$def)."'";
  }
  return trim("$sqlType $null$defSql");
}
function sql_type_from_ui(mysqli $db, string $type, $len): string {
  $t = strtolower($type);
  switch($t){
    case 'varchar':
      $L = (int)($len?:255); if($L<1||$L>65535) $L=255; return "VARCHAR($L)";
    case 'text': return "TEXT";
    case 'int': return "INT";
    case 'bigint': return "BIGINT";
    case 'tinyint': return "TINYINT(1)";
    case 'decimal':
      $p=10;$s=2;
      if (is_string($len) && preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*$/',$len,$m)){ $p=(int)$m[1]; $s=(int)$m[2]; }
      return "DECIMAL($p,$s)";
    case 'enum':
      $opts=[];
      if (is_string($len)) {
        $try=json_decode($len,true);
        $opts = is_array($try) ? $try : array_filter(array_map('trim', explode(',',$len)), fn($x)=>$x!=='');
      } elseif (is_array($len)) { $opts=$len; }
      if (!$opts) throw new Exception("Bitte bei Auswahlliste die Werte angeben (z. B. rot, grün, blau).");
      $safe = array_map(fn($o)=>"'".$db->real_escape_string($o)."'", $opts);
      return 'ENUM('.implode(',',$safe).')';
    case 'date': return "DATE";
    case 'datetime': return "DATETIME";
    default: throw new Exception("Unbekannter Typ: $type");
  }
}
function ui_type_from_column(array $c): array {
  $dt = strtolower($c['DATA_TYPE']);
  $ctype = strtolower($c['COLUMN_TYPE']);
  $type = $dt; $details = '';
  if ($dt === 'varchar' && preg_match('/varchar\((\d+)\)/i',$ctype,$m)){ $type='varchar'; $details=$m[1]; }
  elseif ($dt === 'decimal' && preg_match('/decimal\((\d+),(\d+)\)/i',$ctype,$m)){ $type='decimal'; $details=$m[1].','.$m[2]; }
  elseif ($dt === 'tinyint' && preg_match('/tinyint\((\d+)\)/i',$ctype,$m)){ $type='tinyint'; $details=$m[1]; }
  elseif ($dt === 'enum' && preg_match("/enum\\((.+)\\)/i",$ctype,$mm)){
    preg_match_all("/'((?:\\\\'|[^'])*)'/", $mm[1], $vals);
    $opts = array_map(fn($s)=>str_replace("\\'", "'", $s), ($vals[1] ?? []));
    $details = implode(', ', $opts);
  }
  return [$type,$details];
}
/** Normalisieren für CSV & Inline-Insert (deutsche Datumsformate, datetime-local etc.) */
function normalize_value_for_column($val, array $meta) {
  if ($val === '' || $val === null) return null;
  $dt = strtolower($meta['DATA_TYPE']);
  $orig = $val;

  if (in_array($dt, ['datetime','timestamp'])) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $val)) {
      $val = str_replace('T',' ', $val);
      if (strlen($val) === 16) $val .= ':00';
      return $val;
    }
  }
  if (preg_match('/^\s*(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?\s*$/', $val, $m)) {
    $d=$m[1]; $M=$m[2]; $Y=$m[3];
    if ($dt==='date') return sprintf('%04d-%02d-%02d', $Y, $M, $d);
    if (in_array($dt, ['datetime','timestamp'])) {
      $hh=$m[4] ?? '00'; $mm=$m[5] ?? '00'; $ss=$m[6] ?? '00';
      return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $Y, $M, $d, $hh, $mm, $ss);
    }
  }
  if (in_array($dt, ['datetime','timestamp']) && preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $val)) {
    return $val . ':00';
  }
  if (in_array($dt, ['decimal','double','float'])) {
    $tmp = str_replace(',', '.', (string)$val);
    if ($tmp==='') return null;
    return $tmp;
  }
  if (in_array($dt, ['int','bigint','tinyint'])) {
    if ($val==='') return null;
    return $val;
  }
  return $orig;
}

// Kurze Erklärungen je Spalte (Legende)
function legend_for_table(string $table): array {
  $common = [
    'id'           => 'Interne Nummer (automatisch)',
    'created_at'   => 'Erstellt am (oft automatisch)',
    'updated_at'   => 'Zuletzt geändert (optional automatisch)',
    'deleted_at'   => 'Papierkorb: NULL = aktiv, Datum = gelöscht',
  ];
  if ($table === 'pendenzen') {
    $pend = [
      'titel'        => 'Kurzbeschreibung der Pendenz',
      'projekt_name' => 'Projektname (aus Verknüpfung – read-only)',
      'projekt_id'   => 'Projekt-ID (intern, wird gesetzt)',
      'startdatum'   => 'Beginn',
      'enddatum'     => 'Fälligkeitsdatum',
      'status'       => 'Arbeitsstand (z. B. offen, in Bearbeitung, erledigt, bestätigt)',
      'wichtigkeit'  => 'Priorität (z. B. niedrig, mittel, hoch)',
      'sichtbarkeit' => 'Wer sieht die Pendenz (Projekt / Team / Benutzer)',
    ];
    return $pend + $common;
  }
  return $common;
}

/* ---------- Aktionen ---------- */
try {
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_table_profile') {
    $table = trim($_POST['table'] ?? '');
    $cols = $_POST['cols2'] ?? [];
    if (!ident_ok($table)) throw new Exception("Tabellenname ungültig.");
    if (!is_array($cols) || !count($cols)) throw new Exception("Bitte mindestens eine Spalte wählen.");
    $realCols = fetch_table_columns($mysqli, $table);
    $valid = array_map(fn($r)=>$r['COLUMN_NAME'], $realCols);

    // zusätzlich erlauben: json:* Einträge (nur für Darstellung)
    $extraAllowed = [];
    if ($table==='pendenzen') {
      $q=$mysqli->query("SELECT field_key FROM pendenz_field_defs WHERE enabled=1");
      while($r=$q->fetch_assoc()) $extraAllowed[]='json:'.$r['field_key'];
    }

    $cols = array_values(array_filter($cols, function($c) use($valid,$extraAllowed){
      return in_array($c,$valid,true) || in_array($c,$extraAllowed,true);
    }));
    if (!$cols) throw new Exception("Ungültige Spalten.");
    $uid=(int)($_SESSION['user_id']??0);
    $profId = save_profile_visible_only($mysqli, $table, $cols, ($table==='pendenzen')?1:0, $uid);
    
    // Wenn projekt_id vorhanden, dieses Profil dem Projekt zuweisen
    $pid = (int)($_POST['projekt_id'] ?? 0);
    if ($pid > 0 && $table === 'pendenzen') {
        $stP = $mysqli->prepare("UPDATE projekte SET pendenzen_profile_id = ? WHERE id = ?");
        $stP->bind_param("ii", $profId, $pid);
        $stP->execute();
        $stP->close();
        $flash = "✅ Profil „$table“ gespeichert und dem Projekt zugewiesen.";
    } else {
        $flash = "✅ Profil „$table“ gespeichert. (Nur Darstellung, keine DB-Änderung)";
    }
    
    $redir = $PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table);
    if ($pid > 0) $redir .= "&projekt_id=".$pid;
    header("Location: ".$redir);
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='clone_column') {
    $table = trim($_POST['table'] ?? '');
    $src   = trim($_POST['src_col'] ?? '');
    $dst   = trim($_POST['dst_col'] ?? '');
    if (!ident_ok($table) || !ident_ok($src) || !ident_ok($dst)) throw new Exception("Ungültige Angaben.");
    if ($src === $dst) throw new Exception("Neuer Spaltenname muss anders sein.");
    $st=$mysqli->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->bind_param("ss",$table,$dst); $st->execute(); $cnt=(int)$st->get_result()->fetch_row()[0]; $st->close();
    if ($cnt>0) throw new Exception("Spalte „$dst“ existiert bereits.");
    $cols = fetch_table_columns($mysqli,$table);
    $found=null; foreach($cols as $c){ if ($c['COLUMN_NAME']===$src){ $found=$c; break; } }
    if (!$found) throw new Exception("Quellspalte nicht gefunden.");
    $def = sql_def_for_column($found);
    $sql = "ALTER TABLE `".$mysqli->real_escape_string($table)."` ADD COLUMN `".$mysqli->real_escape_string($dst)."` ".$def;
    if (!$mysqli->query($sql)) throw new Exception("Konnte Spalte nicht kopieren: ".$mysqli->error);
    $flash = "✅ Spalte „$src“ → „$dst“ kopiert.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table));
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add_column') {
    $table = trim($_POST['table'] ?? '');
    $name  = trim($_POST['col_name'] ?? '');
    $type  = trim($_POST['col_type'] ?? '');
    $len   = trim($_POST['col_len'] ?? '');
    $nullable = isset($_POST['col_null']) ? 1 : 0;
    $def   = ($_POST['col_default'] ?? '');
    if (!ident_ok($table) || !ident_ok($name)) throw new Exception("Ungültige Angaben.");
    $sqlType = sql_type_from_ui($mysqli, $type, $len);
    $nullSql = $nullable ? " NULL" : " NOT NULL";
    $defSql  = '';
    if ($def !== '') {
      if (is_numeric($def)) $defSql = " DEFAULT ".$def;
      elseif (in_array(strtolower($type),['date','datetime']) && strtoupper(trim($def))==='CURRENT_TIMESTAMP') $defSql = " DEFAULT CURRENT_TIMESTAMP";
      else $defSql = " DEFAULT '".$mysqli->real_escape_string($def)."'";
    }
    $sql = "ALTER TABLE `".$mysqli->real_escape_string($table)."` ADD COLUMN `".$mysqli->real_escape_string($name)."` $sqlType$nullSql$defSql";
    if (!$mysqli->query($sql)) throw new Exception("Spalte konnte nicht angelegt werden: ".$mysqli->error);
    $flash = "✅ Spalte „$name“ wurde angelegt.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table));
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='alter_column') {
    $table = trim($_POST['table'] ?? '');
    $old   = trim($_POST['old_name'] ?? '');
    $new   = trim($_POST['new_name'] ?? '');
    $type  = trim($_POST['type'] ?? '');
    $det   = trim($_POST['details'] ?? '');
    $nullable = isset($_POST['null']) ? 1 : 0;
    $dropDef  = isset($_POST['drop_default']);
    $defRaw   = $_POST['default'] ?? '';
    if (!ident_ok($table) || !ident_ok($old) || !ident_ok($new)) throw new Exception("Ungültige Angaben.");
    $sqlType = sql_type_from_ui($mysqli, $type, $det);
    $useChange = ($old !== $new);
    $safeTable = $mysqli->real_escape_string($table);
    $safeOld   = $mysqli->real_escape_string($old);
    $safeNew   = $mysqli->real_escape_string($new);
    $nullSql   = $nullable ? " NULL" : " NOT NULL";
    $defSql    = '';
    $multiSql = [];
    if ($dropDef) {
      $multiSql[] = "ALTER TABLE `{$safeTable}` ALTER `{$safeOld}` DROP DEFAULT";
    } elseif ($defRaw !== '') {
      if (is_numeric($defRaw)) { $defSql = " DEFAULT ".$defRaw; }
      elseif (in_array(strtolower($type),['date','datetime']) && strtoupper(trim($defRaw))==='CURRENT_TIMESTAMP') { $defSql = " DEFAULT CURRENT_TIMESTAMP"; }
      else { $defSql = " DEFAULT '".$mysqli->real_escape_string($defRaw)."'"; }
    }
    $alter = "ALTER TABLE `{$safeTable}` ".
             ($useChange
               ? "CHANGE `{$safeOld}` `{$safeNew}` {$sqlType}{$nullSql}{$defSql}"
               : "MODIFY `{$safeOld}` {$sqlType}{$nullSql}{$defSql}");
    $multiSql[] = $alter;
    foreach ($multiSql as $sql) {
      if (!$mysqli->query($sql)) throw new Exception("Änderung fehlgeschlagen: ".$mysqli->error);
    }
    $flash = "✅ Spalte „{$old}“ wurde aktualisiert.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table));
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='drop_column') {
    $table = trim($_POST['table'] ?? '');
    $name  = trim($_POST['name'] ?? '');
    if (!ident_ok($table) || !ident_ok($name)) throw new Exception("Ungültige Angaben.");
    $sql = "ALTER TABLE `".$mysqli->real_escape_string($table)."` DROP COLUMN `".$mysqli->real_escape_string($name)."`";
    if (!$mysqli->query($sql)) throw new Exception("Löschen fehlgeschlagen: ".$mysqli->error);
    $flash = "🗑️ Spalte „{$name}“ gelöscht.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table));
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='insert_bulk_csv') {
    $table = trim($_POST['table'] ?? '');
    $csv   = trim($_POST['csv'] ?? '');
    if (!ident_ok($table)) throw new Exception("Tabellenname ungültig.");
    if ($csv==='') throw new Exception("CSV ist leer.");

    $colsMeta = fetch_table_columns($mysqli,$table);
    $colsBy   = []; foreach($colsMeta as $m) $colsBy[$m['COLUMN_NAME']]=$m;
    $cols = array_keys($colsBy);

    $lines = preg_split("/\r\n|\n|\r/", $csv);
    if (count($lines)<2) throw new Exception("CSV braucht Kopfzeile + mind. 1 Datenzeile.");
    $header = str_getcsv($lines[0], ';');
    if (count($header)<2 && strpos($lines[0],',')!==false) $header = str_getcsv($lines[0], ',');
    $header = array_map('trim',$header);
    foreach($header as $h){ if (!in_array($h,$cols,true)) throw new Exception("Unbekannte Spalte in CSV: ".$h); }

    $safeTable = $mysqli->real_escape_string($table);
    $names = implode('`,`', array_map(function($x) use ($mysqli) { return $mysqli->real_escape_string($x); }, $header));
    $place = implode(',', array_fill(0,count($header),'?'));
    $sql = "INSERT INTO `{$safeTable}` (`{$names}`) VALUES ({$place})";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception("Prepare fehlgeschlagen: ".$mysqli->error);

    $types=''; foreach($header as $h){
      $dt=strtolower($colsBy[$h]['DATA_TYPE']);
      if (in_array($dt,['int','bigint','tinyint'])) $types.='i';
      elseif (in_array($dt,['decimal','double','float'])) $types.='d';
      else $types.='s';
    }

    $inserted=0;
    for($i=1;$i<count($lines);$i++){
      $line=trim($lines[$i]); if($line==='') continue;
      $vals = str_getcsv($line, ';');
      if (count($vals)<2 && strpos($line,',')!==false) $vals = str_getcsv($line, ',');
      if (count($vals)!==count($header)) continue;

      foreach($header as $idx=>$cn){ $vals[$idx] = normalize_value_for_column($vals[$idx], $colsBy[$cn]); }

      $bindVals = []; foreach($vals as $v){ $bindVals[] = $v; }
      $refs = []; foreach($bindVals as $k=>&$v){ $refs[$k] = &$v; }
      $stmt->bind_param($types, ...$refs);
      if (!$stmt->execute()) throw new Exception("Zeile ".($i+1)." fehlgeschlagen: ".$stmt->error);
      $inserted++;
    }
    $stmt->close();
    $flash = "✅ {$inserted} Datensätze eingefügt.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table));
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='insert_demo_row') {
    $table = trim($_POST['table'] ?? '');
    if (!ident_ok($table)) throw new Exception("Tabellenname ungültig.");

    $colsMeta = fetch_table_columns($mysqli,$table);
    $allowed = [];
    foreach($colsMeta as $c){ if (stripos($c['EXTRA']??'','auto_increment')!==false) continue; $allowed[$c['COLUMN_NAME']]=$c; }

    $colNames = [];
    foreach($_POST as $k=>$v){ if (strpos($k,'val__')===0) $colNames[] = substr($k,5); }
    $colNames = array_values(array_intersect($colNames, array_keys($allowed)));
    if (!$colNames) throw new Exception("Keine gültigen Felder gefunden.");

    $rowsCount = 0;
    foreach($colNames as $cn){ $rowsCount = max($rowsCount, is_array($_POST['val__'.$cn])?count($_POST['val__'.$cn]):0); }
    if ($rowsCount<1) throw new Exception("Keine Eingaben.");

    $safeTable = $mysqli->real_escape_string($table);
    $names = implode('`,`', array_map(function($x) use ($mysqli) { return $mysqli->real_escape_string($x); }, $colNames));
    $place = implode(',', array_fill(0,count($colNames),'?'));
    $sql = "INSERT INTO `{$safeTable}` (`{$names}`) VALUES ({$place})";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) throw new Exception("Prepare fehlgeschlagen: ".$mysqli->error);

    $types=''; foreach($colNames as $c){
      $dt=strtolower($allowed[$c]['DATA_TYPE']);
      if (in_array($dt,['int','bigint','tinyint'])) $types.='i';
      elseif (in_array($dt,['decimal','double','float'])) $types.='d';
      else $types.='s';
    }

    $inserted=0;
    for($row=0;$row<$rowsCount;$row++){
      $bindVals=[];
      foreach($colNames as $c){
        $arr = $_POST['val__'.$c] ?? [];
        $v   = $arr[$row] ?? null;
        $v   = normalize_value_for_column($v, $allowed[$c]);
        $bindVals[] = $v;
      }
      $refs = []; foreach($bindVals as $k=>&$v){ $refs[$k] = &$v; }
      $stmt->bind_param($types, ...$refs);
      if (!$stmt->execute()) throw new Exception("Einfügen fehlgeschlagen: ".$stmt->error);
      $inserted++;
    }
    $stmt->close();
    $flash = "✅ {$inserted} Datensatz/Datensätze in „$table“ eingefügt.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($table));
    exit;
  }

  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='duplicate_table') {
    $src = trim($_POST['src_table'] ?? '');
    $dst = trim($_POST['dst_table'] ?? '');
    $withData = isset($_POST['with_data']);
    if (!ident_ok($src) || !ident_ok($dst)) throw new Exception("Ungültige Tabellennamen.");
    if ($src === $dst) throw new Exception("Zielname muss anders sein.");
    $chk = $mysqli->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $chk->bind_param("s",$dst); $chk->execute(); $exists=(int)$chk->get_result()->fetch_row()[0]; $chk->close();
    if ($exists) throw new Exception("Zieltabelle existiert bereits.");
    $sql1 = "CREATE TABLE `".$mysqli->real_escape_string($dst)."` LIKE `".$mysqli->real_escape_string($src)."`";
    if (!$mysqli->query($sql1)) throw new Exception("CREATE LIKE fehlgeschlagen: ".$mysqli->error);
    if ($withData) {
      $sql2 = "INSERT INTO `".$mysqli->real_escape_string($dst)."` SELECT * FROM `".$mysqli->real_escape_string($src)."`";
      if (!$mysqli->query($sql2)) throw new Exception("INSERT SELECT fehlgeschlagen: ".$mysqli->error);
    }
    $flash = "✅ Tabelle „$src“ → „$dst“ dupliziert".($withData?" (mit Daten)":"").".";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($dst));
    exit;
  }

  if (isset($_POST['action']) && $_POST['action']==='create_table') {
    $tname = trim($_POST['t_name'] ?? '');
    $pk    = trim($_POST['t_pk'] ?? 'id');
    $rows  = json_decode($_POST['t_rows_json'] ?? '[]', true);
    $with_ts = isset($_POST['t_with_ts']) ? 1 : 0;
    $with_soft = isset($_POST['t_with_soft']) ? 1 : 0;
    if (!ident_ok($tname)) throw new Exception("Tabellenname ungültig.");
    if (!ident_ok($pk))    throw new Exception("Primärschlüssel ungültig.");
    if (!is_array($rows) || !count($rows)) throw new Exception("Bitte mindestens eine Spalte hinzufügen.");
    $chk = $mysqli->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $chk->bind_param("s",$tname); $chk->execute(); $exists=(int)$chk->get_result()->fetch_row()[0]; $chk->close();
    if ($exists) throw new Exception("Diese Tabelle gibt es bereits.");
    $defs = [];
    $defs[] = "`$pk` INT NOT NULL AUTO_INCREMENT";
    foreach ($rows as $i=>$r) {
      $name = trim($r['name'] ?? '');
      $type = strtolower($r['type'] ?? 'varchar');
      $len  = $r['len'] ?? null;
      $null = !empty($r['null']);
      $defSet = array_key_exists('def',$r);
      $defVal = $r['def'] ?? null;
      if (!ident_ok($name)) throw new Exception("Ungültiger Spaltenname in Zeile ".($i+1));
      $sqlType = sql_type_from_ui($mysqli, $type, $len);
      $line = "`$name` $sqlType".($null?" NULL":" NOT NULL");
      if ($defSet && $defVal!=='') {
        if (is_numeric($defVal)) { $line.=" DEFAULT ".$defVal; }
        elseif (in_array($type,['date','datetime'],true) && strtoupper((string)$defVal)==='CURRENT_TIMESTAMP') { $line.=" DEFAULT CURRENT_TIMESTAMP"; }
        else { $line.=" DEFAULT '".$mysqli->real_escape_string((string)$defVal)."'"; }
      }
      $defs[]=$line;
    }
    if ($with_ts){ $defs[]="`created_at` DATETIME NULL"; $defs[]="`updated_at` DATETIME NULL"; }
    if ($with_soft){ $defs[]="`deleted_at` DATETIME NULL"; }
    $defs[]="PRIMARY KEY (`$pk`)";
    $sql="CREATE TABLE `$tname` (\n  ".implode(",\n  ",$defs)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$mysqli->query($sql)) throw new Exception("Erstellen fehlgeschlagen: ".$mysqli->error);
    $flash="✅ Tabelle „$tname“ wurde erstellt.";
    header("Location: ".$PREFIX."pages/pendenzen_settings.php?table_preview=".urlencode($tname));
    exit;
  }

} catch (Throwable $e) { $flash = "❌ ".$e->getMessage(); }

/* ---------- Daten für Darstellung ---------- */
$allTables = db_tables($mysqli);
$previewTable = isset($_GET['table_preview']) && ident_ok($_GET['table_preview']) ? $_GET['table_preview'] : 'pendenzen';

$curProf = get_profile_by_name($mysqli, $previewTable);
$curCols = $curProf ? parse_cols($curProf['columns_json']) : [];

$metaForPreview = fetch_table_columns($mysqli,$previewTable);
if (!$curCols) {
  $curCols = array_slice(array_map(fn($r)=>$r['COLUMN_NAME'],$metaForPreview), 0, 5);
}

$previewTopRows = [];
if ($previewTable === 'pendenzen') {
  $res = $mysqli->query("
    SELECT p.*, pr.name AS projekt_name
    FROM pendenzen p
    LEFT JOIN projekte pr ON p.projekt_id=pr.id
    ORDER BY p.erstellt_am DESC
    LIMIT 10
  ");
  while($row=$res->fetch_assoc()){
    $row['_extra']=json_decode($row['extra_json'] ?? 'null', true) ?: [];
    $previewTopRows[]=$row;
  }
} else {
  $safe = $mysqli->real_escape_string($previewTable);
  $q = $mysqli->query("SELECT * FROM `$safe` LIMIT 10");
  if ($q) while($r=$q->fetch_assoc()) $previewTopRows[]=$r;
}

// Liste der DB-Spalten (verfügbar)
$previewColsAll = array_map(fn($r)=>$r['COLUMN_NAME'], $metaForPreview);
// plus dyn. json:* Felder für pendenzen
if ($previewTable==='pendenzen') {
  $q=$mysqli->query("SELECT field_key FROM pendenz_field_defs WHERE enabled=1 ORDER BY sort_order,id");
  while($r=$q->fetch_assoc()) $previewColsAll[] = 'json:'.$r['field_key'];
}

/* ---------- UI ---------- */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
  :root { --bd:#e5e7eb; --mut:#64748b; --ink:#0f172a; --hi:#2563eb; --bg:#f8fafc; --btn:#111827; }
  .layout { max-width:1320px; margin:0 auto; padding:18px; display:grid; gap:18px; }
  .card{ background:#fff; border:1px solid var(--bd); border-radius:12px; overflow:hidden; }
  .card>header{ padding:12px 14px; background:#f1f5f9; font-weight:700; display:flex; justify-content:space-between; align-items:center;}
  .card .content{ padding:14px; display:grid; gap:12px; }
  .btn{ border:1px solid var(--bd); background:#fff; color:#111827; border-radius:10px; padding:8px 12px; cursor:pointer; font-weight:600; }
  .btn.small{ padding:4px 8px; font-size:12px; }
  .btn.primary{ background:var(--hi); border-color:#1d4ed8; color:#fff; }
  .btn.danger{ background:#ef4444; border-color:#dc2626; color:#fff; }
  .muted{ color:#64748b; }
  .small{ font-size:12px; }
  table{ width:100%; border-collapse:collapse; font-size:14px; }
  th,td{ border-bottom:1px solid var(--bd); padding:8px 10px; text-align:left; vertical-align:top; }
  .lists{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
  .listbox{ border:1px dashed var(--bd); border-radius:10px; padding:10px; min-height:220px; background:#fff; }
  .item{ display:flex; align-items:center; justify-content:space-between; padding:6px 8px; border:1px solid var(--bd); border-radius:8px; margin-bottom:8px; background:#fff; }
  .item .lbl{ font-weight:600; }
  .controls{ display:flex; gap:6px; }
  input[type=text], input[type=number]{ width:100%; padding:10px; border:1px solid var(--bd); border-radius:10px; }
  select, textarea{ width:100%; padding:10px; border:1px solid var(--bd); border-radius:10px; }
  .notice{ padding:10px 12px; border-left:4px solid #1abc9c; background:#ecfdf5; border-radius:8px; }
  .grid2{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
  .row{ display:grid; grid-template-columns:1.4fr 1.4fr 2.2fr 1fr 1.6fr auto; gap:8px; align-items:center; }
  .row-head{ font-size:12px; color:#64748b; }
  .chip{ display:inline-flex; align-items:center; gap:6px; border:1px dashed var(--bd); padding:6px 8px; border-radius:10px; }
  .hr{height:1px;background:#e5e7eb;border:0;margin:8px 0;}
  details.summarybox { border:1px dashed var(--bd); border-radius:10px; padding:0; }
  details.summarybox > summary { cursor:pointer; list-style:none; padding:10px 14px; background:#f8fafc; border-radius:10px; font-weight:600; }
  details[open].summarybox > summary { border-bottom:1px dashed var(--bd); border-bottom-left-radius:0; border-bottom-right-radius:0; }
  .danger-zone { border:1px solid #fecaca; background:#fff7f7; border-radius:10px; padding:10px; }
  .help { background:#f8fafc; border:1px dashed var(--bd); border-radius:10px; padding:10px; }
  .kbd { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background:#f3f4f6; border:1px solid #e5e7eb; padding:2px 6px; border-radius:6px; font-size:12px; }
</style>

<div class="layout">
  <header style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Pendenzen – Einstellungen</h1>
    <div><a class="btn" href="<?= h($PREFIX) ?>pages/pendenzen.php">↩︎ Zurück zur Pendenzen-Liste</a></div>
  </header>

  <?php if($flash): ?><div class="notice"><?= h($flash) ?></div><?php endif; ?>

  <!-- Schritt 1: Tabelle wählen -->
  <section class="card">
    <header>
      <div>Schritt 1 · Tabelle wählen</div>
      <form method="get" style="display:flex;gap:8px;align-items:center;margin:0;">
        <span class="muted small">Aktuelle Tabelle:</span>
        <select name="table_preview" onchange="this.form.submit()">
          <?php foreach ($allTables as $t): ?>
            <option value="<?= h($t) ?>" <?= $previewTable===$t?'selected':''; ?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </header>
    <div class="content">
      <div class="help small">
        Wähle die Tabelle, die du konfigurieren willst. Für <b>pendenzen</b> verknüpfen wir automatisch das Projekt (<span class="kbd">projekt_name</span>) und unterstützen <span class="kbd">extra_json</span> Felder.
      </div>
    </div>
  </section>

  <!-- Schritt 2: Spalten für die Anzeige (mit Live-Vorschau + Direkt-Eingabe) -->
  <section class="card">
    <header>
      <div>Schritt 2 · Spalten auswählen & Reihenfolge festlegen (nur Darstellung)</div>
      <button class="btn small" type="button" id="btnReset">Empfohlene Spalten laden</button>
    </header>
    <div class="content">
      <div class="lists" style="margin-top:0;">
        <form method="post" id="tblForm" class="lists" style="grid-column:1/-1;">
          <input type="hidden" name="action" value="save_table_profile">
          <input type="hidden" name="table" value="<?= h($previewTable) ?>">
          <?php $pid = (int)($_GET['projekt_id'] ?? 0); ?>
          <input type="hidden" name="projekt_id" value="<?= $pid ?>">

          <!-- Verfügbare Spalten -->
          <div>
            <div class="muted small" style="margin-bottom:6px;">Verfügbare Spalten (anklicken zum Hinzufügen)</div>
            <div class="listbox" id="available2">
              <?php
                $selCols = $curCols ?: [];
                foreach ($previewColsAll as $c):
                  if (in_array($c,$selCols,true)) continue; ?>
                  <div class="item" data-key="<?= h($c) ?>">
                    <div>
                      <div class="lbl"><?= h($ALL_COLUMNS[$c] ?? $c) ?></div>
                      <div class="small muted"><?= h($c) ?></div>
                    </div>
                    <div class="controls"><button class="btn small" data-act="add" type="button">➕</button></div>
                  </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Ausgewählte Spalten -->
          <div>
            <div class="muted small" style="margin-bottom:6px;">Wird in der Liste angezeigt (Reihenfolge per ▲/▼)</div>
            <div class="listbox" id="selected2">
              <?php
                $initSel = $selCols ?: $previewColsAll;
                foreach ($initSel as $c): ?>
                  <div class="item" data-key="<?= h($c) ?>">
                    <div class="chip">
                      <span class="lbl"><?= h($ALL_COLUMNS[$c] ?? $c) ?></span>
                      <span class="small muted">(<?= h($c) ?>)</span>
                    </div>
                    <div class="controls">
                      <button class="btn small" data-act="up" type="button" title="nach oben">▲</button>
                      <button class="btn small" data-act="down" type="button" title="nach unten">▼</button>
                      <button class="btn small" data-act="remove" type="button" title="entfernen">➖</button>
                      <button class="btn small" data-act="clone" type="button" title="Spalte in DB duplizieren/umbenennen">⧉</button>
                    </div>
                  </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div style="grid-column:1/-1; display:flex; justify-content:flex-end; gap:8px;">
            <button class="btn primary" type="submit">Profil „<?= h($previewTable) ?>“ speichern</button>
          </div>
        </form>
      </div>

      <!-- Live-Vorschau + Legende + Direkt-Eingabe -->
      <div>
        <?php
          // Legende nur für die aktuell ausgewählten Spalten anzeigen
          $legendAll   = legend_for_table($previewTable);
          $legendShown = [];
          foreach ($curCols as $c) {
            if (strncmp($c,'json:',5)===0) { $legendShown[$c] = 'Zusatzfeld aus extra_json (frei definierbar)'; continue; }
            if (isset($legendAll[$c]))     { $legendShown[$c] = $legendAll[$c]; }
          }
        ?>
        <?php if ($legendShown): ?>
          <div class="help small" style="margin-bottom:8px;">
            <b>Legende:</b>
            <ul style="margin:6px 0 0 16px;">
              <?php foreach($legendShown as $key=>$txt): ?>
                <li><code><?= h($key) ?></code> – <?= h($txt) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="muted small" style="margin-bottom:6px;">Live-Vorschau (erste 10 Zeilen)</div>
        <div style="overflow:auto;">
          <table id="previewTable">
            <thead><tr id="prevHead"></tr></thead>
            <tbody id="prevBody"></tbody>
          </table>
        </div>
        <div class="small muted">Hinweis: Speichern ändert nur die Darstellung der Listenansicht – die Tabelle selbst bleibt unverändert.</div>

        <!-- Direkt in der Vorschau eine Zeile eintragen -->
        <?php
          $meta = $metaForPreview;
          $editableCols = [];
          foreach ($meta as $m) {
            $nm    = $m['COLUMN_NAME'];
            $extra = strtolower($m['EXTRA'] ?? '');
            if (strpos($extra, 'auto_increment') !== false) continue;
            $editableCols[] = $m;
          }
        ?>
        <style>
          .inline-add-wrap{ margin-top:10px; border:1px dashed var(--bd); border-radius:10px; }
          .inline-add-head{ padding:10px 12px; background:#f8fafc; border-bottom:1px dashed var(--bd); display:flex; justify-content:space-between; align-items:center; }
          .inline-add-body{ padding:10px; overflow:auto; }
          .inline-add-table{ width:100%; border-collapse:collapse; }
          .inline-add-table th, .inline-add-table td{ border-bottom:1px solid var(--bd); padding:6px 8px; text-align:left; vertical-align:top; }
          .inline-add-table th{ white-space:nowrap; }
          .dim-note{ font-size:12px; color:#64748b; }
        </style>

        <div class="inline-add-wrap">
          <div class="inline-add-head">
            <div><b>Direkt hier ausfüllen</b> <span class="dim-note">– neue Zeile für <code><?= h($previewTable) ?></code> eingeben</span></div>
            <button type="button" class="btn small" onclick="document.getElementById('inlineAddDetails').open = !document.getElementById('inlineAddDetails').open">
              Formular ein-/ausblenden
            </button>
          </div>

          <div class="inline-add-body">
            <details id="inlineAddDetails" class="summarybox" <?php if(!$previewTopRows) echo 'open'; ?>>
              <summary>Felder anzeigen</summary>
              <div style="padding:10px 0 0;">
                <form method="post">
                  <input type="hidden" name="action" value="insert_demo_row">
                  <input type="hidden" name="table" value="<?= h($previewTable) ?>">

                  <?php if (!$editableCols): ?>
                    <div class="dim-note">Es gibt keine editierbaren Spalten (vermutlich nur AUTO_INCREMENT/Read-only).</div>
                  <?php else: ?>
                    <table class="inline-add-table">
                      <thead>
                        <tr>
                          <?php foreach ($editableCols as $m): ?>
                            <th title="<?= h($m['COLUMN_TYPE']) ?>"><?= h($m['COLUMN_NAME']) ?></th>
                          <?php endforeach; ?>
                          <th style="width:1%;white-space:nowrap;">Aktion</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <?php
                          foreach ($editableCols as $m):
                            $nm = $m['COLUMN_NAME'];
                            $dt = strtolower($m['DATA_TYPE']);
                            $typeAttr='text'; $ph='Wert';
                            if (in_array($dt,['int','bigint'])) { $typeAttr='number'; $ph='Ganzzahl'; }
                            elseif ($dt==='tinyint')            { $typeAttr='checkbox'; }
                            elseif (in_array($dt,['decimal','double','float'])) { $typeAttr='text'; $ph='12.34'; }
                            elseif ($dt==='date')               { $typeAttr='date'; $ph=''; }
                            elseif (in_array($dt,['datetime','timestamp'])) { $typeAttr='datetime-local'; $ph=''; }
                            // ENUM-Optionen
                            $enumOpts = [];
                            if ($dt==='enum' && preg_match("/enum\\((.+)\\)/i", $m['COLUMN_TYPE'], $mm)) {
                              preg_match_all("/'((?:\\\\'|[^'])*)'/", $mm[1], $vals);
                              $enumOpts = array_map(fn($s)=>str_replace("\\'", "'", $s), ($vals[1] ?? []));
                            }
                          ?>
                            <td>
                              <?php if ($dt==='enum' && $enumOpts): ?>
                                <select name="val__<?= h($nm) ?>[]">
                                  <option value="">— wählen —</option>
                                  <?php foreach ($enumOpts as $o): ?>
                                    <option value="<?= h($o) ?>"><?= h($o) ?></option>
                                  <?php endforeach; ?>
                                </select>
                              <?php elseif ($typeAttr==='checkbox'): ?>
                                <input type="hidden" name="val__<?= h($nm) ?>[]" value="0">
                                <label class="small"><input type="checkbox" name="val__<?= h($nm) ?>[]" value="1" onclick="this.previousElementSibling.disabled=this.checked" /> Ja/Nein</label>
                              <?php else: ?>
                                <input type="<?= h($typeAttr) ?>" name="val__<?= h($nm) ?>[]" placeholder="<?= h($ph) ?>">
                              <?php endif; ?>
                              <div class="dim-note"><?= h($m['COLUMN_TYPE']) ?></div>
                            </td>
                          <?php endforeach; ?>
                          <td style="white-space:nowrap;">
                            <button class="btn primary">Zeile speichern</button>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </form>
                <div class="dim-note" style="margin-top:6px;">
                  Datumswerte akzeptieren <code>YYYY-MM-DD</code>, <code>YYYY-MM-DDTHH:MM</code> sowie <code>tt.mm.jjjj [hh:mm]</code>.
                </div>
              </div>
            </details>
          </div>
        </div>
        <!-- /Direkt in der Vorschau -->
      </div>

      <!-- Mehrere Datensätze + CSV (optional) -->
      <details class="summarybox" style="margin-top:8px;">
        <summary>Mehrere Zeilen auf einmal (Formular & CSV)</summary>
        <div class="content" style="padding:0; gap:12px;">
          <form method="post" id="quickInsertForm" style="padding:10px;">
            <input type="hidden" name="action" value="insert_demo_row">
            <input type="hidden" name="table" value="<?= h($previewTable) ?>">
            <div id="insertRows">
              <div class="grid2 insRow">
                <?php
                  foreach($metaForPreview as $m):
                    $nm=$m['COLUMN_NAME'];
                    $dt=strtolower($m['DATA_TYPE']);
                    $extra=strtolower($m['EXTRA']??'');
                    if (strpos($extra,'auto_increment')!==false) continue;
                    $typeAttr = 'text'; $ph='Wert eingeben';
                    if (in_array($dt,['int','bigint','tinyint'])) { $typeAttr='number'; $ph='Ganzzahl'; }
                    elseif (in_array($dt,['decimal','double','float'])) { $typeAttr='text'; $ph='Kommazahl (12.34)'; }
                    elseif ($dt==='date') { $typeAttr='date'; $ph=''; }
                    elseif (in_array($dt,['datetime','timestamp'])) { $typeAttr='datetime-local'; $ph='tt.mm.jjjj hh:mm'; }
                ?>
                  <div>
                    <label class="small"><?= h($nm) ?> <span class="muted">(<?= h($m['COLUMN_TYPE']) ?>)</span></label>
                    <?php if ($dt==='enum' && preg_match("/enum\\((.+)\\)/i", $m['COLUMN_TYPE'], $mm)):
                      preg_match_all("/'((?:\\\\'|[^'])*)'/", $mm[1], $vals);
                      $opts = array_map(fn($s)=>str_replace("\\'", "'", $s), ($vals[1] ?? []));
                    ?>
                      <select name="val__<?= h($nm) ?>[]">
                        <option value="">— wählen —</option>
                        <?php foreach($opts as $o): ?>
                          <option value="<?= h($o) ?>"><?= h($o) ?></option>
                        <?php endforeach; ?>
                      </select>
                    <?php else: ?>
                      <input type="<?= h($typeAttr) ?>" name="val__<?= h($nm) ?>[]" placeholder="<?= h($ph) ?>">
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="small" style="display:flex; gap:8px; margin-top:6px;">
              <button class="btn small" type="button" id="addInsertRow">+ Zeile hinzufügen</button>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:6px;">
              <button class="btn">Datensätze einfügen</button>
            </div>
          </form>

          <div class="hr"></div>

          <form method="post" style="padding:10px;">
            <input type="hidden" name="action" value="insert_bulk_csv">
            <input type="hidden" name="table" value="<?= h($previewTable) ?>">
            <div class="small muted">CSV einfügen (1. Zeile = Spalten):</div>
            <textarea name="csv" rows="6" placeholder="z. B.:
titel;created_at
Test Pendenz;2025-09-21 10:00
Noch eine;22.09.2025 12:30"></textarea>
            <div class="small muted">Trenner ; oder , wird erkannt. Deutsche Datumsformate möglich.</div>
            <div style="display:flex;justify-content:flex-end;margin-top:6px;">
              <button class="btn">CSV einfügen</button>
            </div>
          </form>
        </div>
      </details>
    </div>
  </section>

  <!-- Profi-Funktionen -->
  <section class="card">
    <header>
      <div>Profi-Funktionen (optional)</div>
      <button class="btn small" type="button" id="togglePro">anzeigen</button>
    </header>
    <div class="content" id="proBlock" style="display:none;">
      <details class="summarybox" open>
        <summary>Spalten-Inspector (MySQL-Eigenschaften)</summary>
        <div class="help small" style="margin:10px 0 0;">
          <b>Schnell erklärt:</b>
          <ul style="margin:6px 0 0 16px;">
            <li><b>Art</b> = Datentyp (z. B. Text, Zahl, Datum).</li>
            <li><b>Details</b> = Länge / Präzision oder Werte für Auswahlliste (ENUM).</li>
            <li><b>NULL?</b> = Darf leer sein? (<i>NULL = kein Wert gespeichert</i>).</li>
            <li><b>Default</b> = Vorgabewert, wenn du nichts eingibst (z. B. <code>0</code> oder <code>CURRENT_TIMESTAMP</code>).</li>
            <li><b>Aktionen</b> = Spalte speichern/ändern oder löschen (wirkt direkt in der Datenbank).</li>
          </ul>
        </div>
        <div class="content" style="overflow:auto;">
          <table>
            <thead>
              <tr>
                <th>Spalte</th>
                <th>Art</th>
                <th>Details</th>
                <th>NULL?</th>
                <th>Default</th>
                <th>Aktionen</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($metaForPreview as $m):
                $nm = $m['COLUMN_NAME']; [$uiType,$uiDet] = ui_type_from_column($m);
                $isNull = (strtoupper($m['IS_NULLABLE'])==='YES'); $defVal = $m['COLUMN_DEFAULT'];
              ?>
              <tr>
                <form method="post" class="small" onsubmit="return confirm('Änderung an Spalte <?= h($nm) ?> ausführen?');">
                  <input type="hidden" name="action" value="alter_column">
                  <input type="hidden" name="table" value="<?= h($previewTable) ?>">
                  <input type="hidden" name="old_name" value="<?= h($nm) ?>">
                  <td style="white-space:nowrap;">
                    <div class="small muted"><?= h($m['COLUMN_TYPE']) ?></div>
                    <input type="text" name="new_name" value="<?= h($nm) ?>" style="min-width:140px;">
                  </td>
                  <td>
                    <select name="type" style="min-width:160px;">
                      <option value="varchar"  <?= $uiType==='varchar'?'selected':''; ?>>Kurzer Text (VARCHAR)</option>
                      <option value="text"     <?= $uiType==='text'?'selected':''; ?>>Langer Text (TEXT)</option>
                      <option value="int"      <?= $uiType==='int'?'selected':''; ?>>Ganze Zahl (INT)</option>
                      <option value="bigint"   <?= $uiType==='bigint'?'selected':''; ?>>Große Zahl (BIGINT)</option>
                      <option value="tinyint"  <?= $uiType==='tinyint'?'selected':''; ?>>Ja/Nein (TINYINT)</option>
                      <option value="decimal"  <?= $uiType==='decimal'?'selected':''; ?>>Kommazahl (DECIMAL)</option>
                      <option value="enum"     <?= $uiType==='enum'?'selected':''; ?>>Auswahlliste (ENUM)</option>
                      <option value="date"     <?= $uiType==='date'?'selected':''; ?>>Datum</option>
                      <option value="datetime" <?= $uiType==='datetime'?'selected':''; ?>>Datum & Uhrzeit</option>
                    </select>
                  </td>
                  <td><input type="text" name="details" value="<?= h($uiDet) ?>" placeholder="Länge, 10,2 oder Liste: rot, grün, blau" style="min-width:200px;"></td>
                  <td style="text-align:center;"><input type="checkbox" name="null" value="1" <?= $isNull?'checked':''; ?>></td>
                  <td>
                    <div style="display:flex; gap:6px; align-items:center;">
                      <input type="text" name="default" value="<?= h((string)$defVal) ?>" placeholder="z. B. 0 oder CURRENT_TIMESTAMP" style="min-width:180px;">
                      <label class="small"><input type="checkbox" name="drop_default"> Default entfernen</label>
                    </div>
                  </td>
                  <td style="white-space:nowrap;"><button class="btn small">Speichern</button></td>
                </form>
                <td style="border:0; padding-left:0;">
                  <form method="post" class="small" onsubmit="return confirm('Spalte wirklich löschen? Das kann nicht rückgängig gemacht werden.');">
                    <input type="hidden" name="action" value="drop_column">
                    <input type="hidden" name="table" value="<?= h($previewTable) ?>">
                    <input type="hidden" name="name" value="<?= h($nm) ?>">
                    <button class="btn small danger" type="submit">Löschen</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div class="small muted">Achtung: Hier änderst du die Tabelle wirklich (DDL). Vorher Backup machen.</div>
        </div>
      </details>

      <details class="summarybox">
        <summary>Neue Spalte hinzufügen</summary>
        <div class="content">
          <form method="post" class="grid2" onsubmit="return confirm('Neue Spalte anlegen?');">
            <input type="hidden" name="action" value="add_column">
            <input type="hidden" name="table" value="<?= h($previewTable) ?>">
            <div>
              <label>Spaltenname</label>
              <input type="text" name="col_name" placeholder="z. B. status_text" required>
            </div>
            <div>
              <label>Art</label>
              <select name="col_type" required>
                <option value="varchar">Kurzer Text (VARCHAR)</option>
                <option value="text">Langer Text (TEXT)</option>
                <option value="int">Ganze Zahl (INT)</option>
                <option value="bigint">Große Zahl (BIGINT)</option>
                <option value="tinyint">Ja/Nein (TINYINT)</option>
                <option value="decimal">Kommazahl (DECIMAL)</option>
                <option value="enum">Auswahlliste (ENUM)</option>
                <option value="date">Datum</option>
                <option value="datetime">Datum & Uhrzeit</option>
              </select>
            </div>
            <div>
              <label>Details (optional)</label>
              <input type="text" name="col_len" placeholder="Länge, 10,2 oder Liste: rot, grün, blau">
            </div>
            <div>
              <label>Optionen</label>
              <label class="small"><input type="checkbox" name="col_null" value="1"> Darf leer sein</label>
            </div>
            <div>
              <label>Vorgabewert (optional)</label>
              <input type="text" name="col_default" placeholder="z. B. 0 oder CURRENT_TIMESTAMP">
            </div>
            <div style="display:flex;align-items:end;justify-content:flex-end;">
              <button class="btn">Spalte anlegen</button>
            </div>
          </form>
        </div>
      </details>

      <details class="summarybox">
        <summary>Tabelle duplizieren</summary>
        <form method="post" class="grid2" style="align-items:end; margin-top:8px;" onsubmit="return confirm('Tabelle wirklich duplizieren?');">
          <input type="hidden" name="action" value="duplicate_table">
          <input type="hidden" name="src_table" value="<?= h($previewTable) ?>">
          <div>
            <label class="small muted">Neue Tabelle als Kopie von <b><?= h($previewTable) ?></b></label>
            <input type="text" name="dst_table" placeholder="z. B. <?= h($previewTable) ?>_kopie" required>
          </div>
          <div>
            <label class="small muted">Optionen</label>
            <div class="small"><label><input type="checkbox" name="with_data" value="1"> Daten mit kopieren</label></div>
          </div>
          <div style="grid-column:1/-1; display:flex; justify-content:flex-end;">
            <button class="btn">Tabelle duplizieren</button>
          </div>
        </form>
      </details>

      <details class="summarybox">
        <summary>Neue Tabelle erstellen</summary>
        <div class="content">
          <div class="small muted">„Art“ in einfachen Worten. Empfohlene Extras vorausgewählt.</div>
          <form method="post" id="builderForm">
            <input type="hidden" name="action" value="create_table">
            <input type="hidden" name="t_rows_json" id="t_rows_json">
            <div class="grid2">
              <div>
                <label>Tabellen-Name</label>
                <input type="text" name="t_name" placeholder="z. B. checklisten" required>
              </div>
              <div>
                <label>Primärschlüssel</label>
                <input type="text" name="t_pk" value="id" required>
              </div>
            </div>
            <div class="row row-head" style="margin-top:6px;">
              <div>Spaltenname</div><div>Art</div><div>Details</div><div>Darf leer sein?</div><div>Vorgabewert</div><div>Aktion</div>
            </div>
            <div id="rows"></div>
            <div><button class="btn" type="button" id="addRow">+ Spalte hinzufügen</button></div>
            <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap; margin-top:6px;">
              <label class="small"><input type="checkbox" name="t_with_ts" value="1" checked> Zeitstempel (<span class="kbd">created_at</span>, <span class="kbd">updated_at</span>)</label>
              <label class="small"><input type="checkbox" name="t_with_soft" value="1" checked> Papierkorb / Soft-Delete (<span class="kbd">deleted_at</span>)</label>
            </div>
            <div style="display:flex;justify-content:flex-end; margin-top:6px;">
              <button class="btn primary" onclick="return confirm('Neue Tabelle erstellen?');">Tabelle erstellen</button>
            </div>
          </form>
        </div>
      </details>

      <div class="danger-zone small">
        <b>Hinweis:</b> Aktionen in diesem Block können die Struktur der Datenbank ändern. Bitte nur ausführen, wenn du weißt, was du tust.
      </div>
    </div>
  </section>
</div>

<script>
/* ===== Live-Daten in JS bringen ===== */
const PREVIEW_TABLE = <?= json_encode($previewTable) ?>;
const ALL_LABELS    = <?= json_encode($ALL_COLUMNS, JSON_UNESCAPED_UNICODE) ?>;
const PREVIEW_ROWS  = <?= json_encode($previewTopRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

/* ===== UI: Spalten wählen & Live-Vorschau ===== */
const available2 = document.getElementById('available2');
const selected2  = document.getElementById('selected2');
const tblForm    = document.getElementById('tblForm');
const prevHead   = document.getElementById('prevHead');
const prevBody   = document.getElementById('prevBody');

function currentSelectedKeys(){
  return [...selected2.querySelectorAll('.item')].map(it=>it.dataset.key);
}
function colLabel(key){
  return ALL_LABELS[key] ?? key;
}
function escapeHtml(s){ return s.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

function renderPreview(){
  const cols = currentSelectedKeys();
  prevHead.innerHTML = cols.map(c=>`<th>${escapeHtml(colLabel(c))}</th>`).join('');
  if (!PREVIEW_ROWS || !PREVIEW_ROWS.length){
    prevBody.innerHTML = `<tr><td class="muted" colspan="${Math.max(1, cols.length)}">Keine Daten.</td></tr>`;
    return;
  }
  const rowsHtml = PREVIEW_ROWS.map(row=>{
    const tds = cols.map(c=>{
      let val = '';
      if (PREVIEW_TABLE==='pendenzen' && c.startsWith('json:')){
        const key = c.substring(5);
        val = (row._extra && (key in row._extra)) ? row._extra[key] : '';
      } else {
        val = (row[c] !== undefined && row[c] !== null) ? row[c] : '';
      }
      if (typeof val === 'object') val = JSON.stringify(val);
      return `<td>${escapeHtml(String(val))}</td>`;
    }).join('');
    return `<tr>${tds}</tr>`;
  }).join('');
  prevBody.innerHTML = rowsHtml;
}

function mkSelectedItem(col){
  const div = document.createElement('div');
  div.className='item'; div.dataset.key=col;
  div.innerHTML = `
    <div class="chip">
      <span class="lbl">${escapeHtml(colLabel(col))}</span>
      <span class="small muted">(${escapeHtml(col)})</span>
    </div>
    <div class="controls">
      <button class="btn small" data-act="up" type="button">▲</button>
      <button class="btn small" data-act="down" type="button">▼</button>
      <button class="btn small" data-act="remove" type="button">➖</button>
      <button class="btn small" data-act="clone" type="button" title="Spalte in DB duplizieren/umbenennen">⧉</button>
    </div>`;
  return div;
}
function mkAvailItem(col){
  const div = document.createElement('div');
  div.className='item'; div.dataset.key=col;
  div.innerHTML = `<div><div class="lbl">${escapeHtml(colLabel(col))}</div><div class="small muted">${escapeHtml(col)}</div></div><div class="controls"><button class="btn small" data-act="add" type="button">➕</button></div>`;
  return div;
}
available2?.addEventListener('click', (e)=>{
  const b=e.target.closest('button[data-act="add"]'); if(!b) return;
  const it=b.closest('.item'); const col=it.dataset.key;
  selected2.appendChild(mkSelectedItem(col));
  it.remove();
  renderPreview();
});
selected2?.addEventListener('click',(e)=>{
  const b=e.target.closest('button[data-act]'); if(!b) return;
  const it=b.closest('.item'); const act=b.dataset.act;
  if (act==='remove'){
    const col=it.dataset.key;
    available2.appendChild(mkAvailItem(col));
    it.remove();
    renderPreview();
  } else if (act==='up'){
    const p=it.previousElementSibling; if(p) it.parentNode.insertBefore(it,p);
    renderPreview();
  } else if (act==='down'){
    const n=it.nextElementSibling; if(n) it.parentNode.insertBefore(n,it);
    renderPreview();
  } else if (act==='clone'){
    if (it.nextElementSibling && it.nextElementSibling.classList?.contains('clone-form')) return;
    const form = document.createElement('form');
    form.method='post'; form.className='clone-form';
    form.innerHTML = `
      <input type="hidden" name="action" value="clone_column">
      <input type="hidden" name="table" value="<?= h($previewTable) ?>">
      <input type="hidden" name="src_col" value="${it.dataset.key}">
      <span class="small muted">Kopie als:</span>
      <input type="text" name="dst_col" placeholder="${it.dataset.key}_kopie" required style="width:220px;">
      <button class="btn small" type="submit" onclick="return confirm('Spalte in der DB kopieren?');">Anlegen</button>
      <button class="btn small danger" type="button" data-act="cancel-clone">Abbrechen</button>`;
    it.after(form);
  }
});
document.addEventListener('click',(e)=>{
  const b=e.target.closest('button[data-act="cancel-clone"]'); if(!b) return;
  const f=b.closest('form.clone-form'); if(f) f.remove();
});
tblForm?.addEventListener('submit',(e)=>{
  tblForm.querySelectorAll('input[name="cols2[]"]').forEach(n=>n.remove());
  currentSelectedKeys().forEach(key=>{
    const inp=document.createElement('input'); inp.type='hidden'; inp.name='cols2[]'; inp.value=key;
    tblForm.appendChild(inp);
  });
});

function recommendedForPendenzen(){
  return ['titel','projekt_name','enddatum','status','wichtigkeit'];
}
document.getElementById('btnReset')?.addEventListener('click', ()=>{
  if (PREVIEW_TABLE!=='pendenzen'){ alert('Empfehlung gibt es hier nur für „pendenzen“.'); return; }
  const rec = recommendedForPendenzen();
  // räumen
  [...selected2.children].forEach(c=>available2.appendChild(mkAvailItem(c.dataset.key)));
  selected2.innerHTML='';
  rec.forEach(k=>{
    const inAvail = [...available2.querySelectorAll('.item')].find(i=>i.dataset.key===k);
    const already = [...selected2.querySelectorAll('.item')].find(i=>i.dataset.key===k);
    if (already) return;
    if (inAvail) { selected2.appendChild(mkSelectedItem(k)); inAvail.remove(); }
  });
  renderPreview();
});

/* ===== Multi-Insert: Zeile duplizieren ===== */
document.getElementById('addInsertRow')?.addEventListener('click', ()=>{
  const container = document.getElementById('insertRows');
  const rows = container.querySelectorAll('.insRow');
  if (!rows.length) return;
  const clone = rows[rows.length-1].cloneNode(true);
  clone.querySelectorAll('input,select,textarea').forEach(el=>{
    if (el.tagName==='SELECT') el.selectedIndex = 0;
    else el.value = '';
  });
  container.appendChild(clone);
});

/* ===== Tabellen-Builder ===== */
const rowsBox = document.getElementById('rows');
const addRowBtn = document.getElementById('addRow');
const builderForm = document.getElementById('builderForm');
function rowTemplate(i){
  return `
  <div class="row" data-i="${i}">
    <input type="text" class="r-name" placeholder="z. B. titel" required>
    <select class="r-type">
      <option value="varchar">Kurzer Text (VARCHAR)</option>
      <option value="text">Langer Text</option>
      <option value="int">Ganze Zahl</option>
      <option value="bigint">Große Zahl</option>
      <option value="tinyint">Ja/Nein (Checkbox)</option>
      <option value="decimal">Kommazahl (z. B. 10,2)</option>
      <option value="enum">Auswahlliste (Liste)</option>
      <option value="date">Datum</option>
      <option value="datetime">Datum & Uhrzeit</option>
    </select>
    <input type="text" class="r-len" placeholder="Details: Länge, Liste oder 10,2">
    <div style="text-align:center;"><input type="checkbox" class="r-null" title="Darf leer bleiben?"></div>
    <input type="text" class="r-def" placeholder="Vorgabewert (optional)">
    <div class="controls">
      <button class="btn small" data-act="up" type="button">▲</button>
      <button class="btn small" data-act="down" type="button">▼</button>
      <button class="btn small danger" data-act="del" type="button">✖</button>
    </div>
  </div>`;
}
let idx=0;
function addRow(){ rowsBox.insertAdjacentHTML('beforeend', rowTemplate(++idx)); }
addRowBtn?.addEventListener('click', addRow);
rowsBox?.addEventListener('click',(e)=>{
  const b = e.target.closest('button[data-act]'); if(!b) return;
  const row = b.closest('.row'); const act=b.dataset.act;
  if (act==='del'){ row.remove(); }
  else if (act==='up'){ const p=row.previousElementSibling; if(p) row.parentNode.insertBefore(row,p); }
  else if (act==='down'){ const n=row.nextElementSibling; if(n) row.parentNode.insertBefore(n,row); }
});
builderForm?.addEventListener('submit',(e)=>{
  const out=[];
  rowsBox.querySelectorAll('.row').forEach(r=>{
    out.push({
      name: r.querySelector('.r-name').value.trim(),
      type: r.querySelector('.r-type').value,
      len:  r.querySelector('.r-len').value.trim(),
      null: r.querySelector('.r-null').checked ? 1 : 0,
      def:  r.querySelector('.r-def').value
    });
  });
  document.getElementById('t_rows_json').value = JSON.stringify(out);
});

/* ===== Profi-Block togglen ===== */
document.getElementById('togglePro')?.addEventListener('click', (e)=>{
  const b=e.currentTarget; const blk=document.getElementById('proBlock');
  const show = blk.style.display==='none';
  blk.style.display = show ? 'block' : 'none';
  b.textContent = show ? 'ausblenden' : 'anzeigen';
});

/* ===== Smarte Defaults fürs Direkt-Form unter der Vorschau ===== */
function setSmartDefaultsForInlineAdd(){
  const now = new Date();
  const pad = (n)=> String(n).padStart(2,'0');
  const dt  = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;

  // created_at / updated_at (falls als datetime-local vorhanden)
  document.querySelectorAll('input[name="val__created_at[]"][type="datetime-local"]').forEach(i=>{
    if (!i.value) i.value = dt;
  });
  document.querySelectorAll('input[name="val__updated_at[]"][type="datetime-local"]').forEach(i=>{
    if (!i.value) i.value = dt;
  });

  // Für pendenzen: wenn Selects existieren, sinnvolle Defaults setzen
  const statusSel = document.querySelector('select[name="val__status[]"]');
  if (statusSel) {
    const opt = [...statusSel.options].find(o=>o.value.toLowerCase?.() === 'offen') 
             ?? [...statusSel.options].find(o=>o.value && o.value !== '');
    if (opt) statusSel.value = opt.value;
  }
  const prioSel = document.querySelector('select[name="val__wichtigkeit[]"]');
  if (prioSel) {
    const pref = ['mittel','normal','standard','medium'];
    const opt  = [...prioSel.options].find(o=>pref.includes((o.value||'').toLowerCase()))
              ?? [...prioSel.options].find(o=>o.value && o.value !== '');
    if (opt) prioSel.value = opt.value;
  }
}

setSmartDefaultsForInlineAdd();
renderPreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
