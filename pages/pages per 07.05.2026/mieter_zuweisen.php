<?php
// pages/mieter_zuweisen.php — Ordner-first Mieter-Zuordnung (liest aus fs_rel_path, robust, null-safe)

if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();

require_once __DIR__ . '/../includes/functions.php'; // e()
require_once __DIR__ . '/../includes/user_ui.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/fs.php'; // project_root_path(), fs_abs_from_rel(), fs_scan_project()

/* -------- lokale Helper -------- */
if (!function_exists('en')) { function en($v){ return e((string)$v); } }
if (!function_exists('page_url')) { function page_url($p){ return '/pendenz.com/pages/'.ltrim($p,'/'); } }
if (!function_exists('csrf_field')) {
  function csrf_field() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $t = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $t;
    echo '<input type="hidden" name="csrf" value="'.en($t).'">';
  }
}
if (!function_exists('csrf_validate')) {
  function csrf_validate() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $tok  = $_POST['csrf'] ?? ($_POST['csrf_token'] ?? '');
    $sess = $_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? '');
    if ($tok !== '' && $sess !== '' && !hash_equals($sess, $tok)) throw new Exception('Ungültiger CSRF-Token.');
  }
}
if (!function_exists('csrf_token_value')) {
  function csrf_token_value() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
  }
}

/* ---------- DB-Introspektion ---------- */
function table_exists(mysqli $db, $table) {
  $st=$db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
  $st->bind_param("s",$table); $st->execute();
  $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function column_exists(mysqli $db, $table, $col) {
  $st=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $st->bind_param("ss",$table,$col); $st->execute();
  $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function name_col(mysqli $db, $table) {
  foreach (['name','bezeichnung','titel','kuerzel','nummer','whg','wohnung','adresse'] as $c) if (column_exists($db,$table,$c)) return $c;
  return 'id';
}
function fk_target_table(mysqli $db, $table, $column) {
  $sql="SELECT REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1";
  $st=$db->prepare($sql); $st->bind_param('ss',$table,$column); $st->execute();
  $t=$st->get_result()->fetch_column(); $st->close();
  return $t ?: null;
}
function id_exists(mysqli $db, $table, $id) {
  if ($id<=0 || !$table || !table_exists($db,$table)) return false;
  $st=$db->prepare("SELECT 1 FROM `$table` WHERE id=? LIMIT 1");
  $st->bind_param('i',$id); $st->execute();
  $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/* ---------- Eingaben ---------- */
$projekt_id = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_GET['id'] ?? 0);
$ctxRel     = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';

/* ---------- Tabellenwahl (Objekt/Wohnung) ---------- */
$tblProjekte = 'projekte';
$refLieg    = fk_target_table($mysqli, 'projekt_verknuepfungen', 'liegenschaft_id');
$refEinheit = fk_target_table($mysqli, 'projekt_verknuepfungen', 'einheit_id');

if ($refLieg && table_exists($mysqli,$refLieg))       $tblLieg = $refLieg;
elseif (table_exists($mysqli,'objekte'))              $tblLieg = 'objekte';
elseif (table_exists($mysqli,'liegenschaften'))       $tblLieg = 'liegenschaften';
else                                                  $tblLieg = null;

$fkProjInLieg = null;
if ($tblLieg) foreach (['projekt_id','project_id','projekte_id','pid','projekt'] as $cand) if (column_exists($mysqli,$tblLieg,$cand)) { $fkProjInLieg=$cand; break; }

if ($refEinheit && table_exists($mysqli,$refEinheit)) $tblEinheit = $refEinheit;
elseif (table_exists($mysqli,'vermietungseinheiten')) $tblEinheit = 'vermietungseinheiten';
elseif (table_exists($mysqli,'wohnungen'))            $tblEinheit = 'wohnungen';
else                                                  $tblEinheit = null;

$fkLiegInEinheit = null;
if ($tblEinheit) foreach (['objekt_id','liegenschaft_id','parent_id'] as $cand) if (column_exists($mysqli,$tblEinheit,$cand)) { $fkLiegInEinheit=$cand; break; }

/* ---------- Ajax vor JEDEM Output ---------- */
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] !== '';

/* Schema-Check – bei AJAX JSON statt HTML */
if (!$tblLieg || !$tblEinheit || !$fkLiegInEinheit) {
  $msg = "❌ Schema nicht ausreichend. Lieg=".en($tblLieg ?: '—')
       ." · Einheit=".en($tblEinheit ?: '—')."/FK=".en($fkLiegInEinheit ?: '—');
  if ($isAjax) {
    if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'msg'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
  }
  die($msg);
}

/* ---------- FS-Helper ---------- */
function fs_sanitize_name($name){
  $name=preg_replace('~[\\\\/]+~','-',trim((string)$name));
  $name=preg_replace('~[:*?"<>|]~','',$name);
  $name=preg_replace('~\s+~',' ',$name);
  return $name===''?'Unbenannt':$name;
}
function fs_mkdirp($abs){ return is_dir($abs) ? true : @mkdir($abs,0777,true); }
function fs_rel_parent($rel){
  $rel=trim((string)$rel,'/'); if($rel==='') return '';
  $p=strrpos($rel,'/'); return $p===false ? '' : substr($rel,0,$p);
}
function fs_ensure_unique_rel(mysqli $db,$projekt_id,$rootAbs,$baseRel){
  $candidate=$baseRel; $i=2;
  while(true){
    $abs=fs_abs_from_rel($rootAbs,$candidate);
    if ($abs && !is_dir($abs) && !is_file($abs)) return $candidate;
    $st=$db->prepare("SELECT 1 FROM fs_nodes WHERE project_id=? AND rel_path=? LIMIT 1");
    $st->bind_param("is",$projekt_id,$candidate); $st->execute();
    $exists=(bool)$st->get_result()->fetch_row(); $st->close();
    if (!$exists) return $candidate;
    $candidate=$baseRel.'-'.$i; $i++; if($i>200) break;
  }
  return $baseRel.'-uniq';
}
function fs_move_dir(mysqli $db,$projekt_id,$rootAbs,$srcRel,$dstRelBase){
  $dstRel=fs_ensure_unique_rel($db,$projekt_id,$rootAbs,$dstRelBase);
  $srcAbs=fs_abs_from_rel($rootAbs,$srcRel);
  $dstAbs=fs_abs_from_rel($rootAbs,$dstRel);
  if(!$srcAbs||!$dstAbs) return null;
  if(!is_dir($srcAbs)) return null;
  if(!fs_mkdirp(dirname($dstAbs))) return null;
  if(!@rename($srcAbs,$dstAbs)) return null;
  if(function_exists('fs_scan_project')){ @fs_scan_project($db,$projekt_id,0); }
  return $dstRel;
}
function get_person_label(mysqli $db,$personTable,$mieter_id){
  $mieter_id=(int)$mieter_id; $label='Mieter_'.$mieter_id;
  if(!$mieter_id) return $label;
  if($personTable==='benutzer'){
    $hasVor=column_exists($db,'benutzer','vorname'); $hasNach=column_exists($db,'benutzer','nachname'); $hasName=column_exists($db,'benutzer','name');
    if($hasName){ $q=$db->prepare("SELECT name FROM benutzer WHERE id=?"); $q->bind_param('i',$mieter_id); }
    else { $q=$db->prepare("SELECT TRIM(CONCAT_WS(' ', ".($hasNach?"COALESCE(nachname,'')":"''").", ".($hasVor?"COALESCE(vorname,'')":"''").")) FROM benutzer WHERE id=?"); $q->bind_param('i',$mieter_id); }
    $q->execute(); $x=$q->get_result()->fetch_column(); $q->close();
    if($x!==null && $x!=='') $label=$x;
  } elseif($personTable==='mieter'){
    $col=name_col($db,'mieter'); $q=$db->prepare("SELECT {$col} FROM mieter WHERE id=?"); $q->bind_param('i',$mieter_id);
    $q->execute(); $x=$q->get_result()->fetch_column(); $q->close();
    if($x!==null && $x!=='') $label=$x;
  }
  return fs_sanitize_name($label);
}
function build_vormieter_target_rel(mysqli $db,$projekt_id,$rootAbs,$unitRel,$personLabel,$von,$bis){
  $von=$von?:''; $bis=$bis?:date('Y-m-d');
  $baseRel=($unitRel!==''?rtrim((string)$unitRel,'/').'/' :'').'Vormieter/'.$personLabel.'__von_'.$von.'__bis_'.$bis;
  return fs_ensure_unique_rel($db,$projekt_id,$rootAbs,$baseRel);
}

/* ---------- Pfad→IDs-Erkennung (toleranter) ---------- */
function guess_ids_from_path(
  mysqli $db, $projekt_id, $rel,
  $tblLieg, $colNameLieg, $fkProjInLieg,
  $tblEinheit, $colNameEinheit, $fkLiegInEinheit
){
  $out=['liegenschaft_id'=>null,'einheit_id'=>null,'lieg_name'=>null,'einheit_name'=>null,'einheiten'=>[]];
  if (!$projekt_id || !$rel) return $out;

  $norm=function($s){
    $s=mb_strtolower((string)$s,'UTF-8');
    $s=strtr($s,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    $s=preg_replace('~[^\p{L}\p{N}]+~u','',$s);
    return $s;
  };

  $parts=array_values(array_filter(array_map('trim', explode('/', str_replace('\\','/',$rel)))));
  if(!$parts) return $out;

  // --- NEU: Index von „Wohnungen“ finden
  $idxWohn=null;
  for($i=0;$i<count($parts);$i++){
    if(preg_match('~^wohnungen$~iu',$parts[$i])){ $idxWohn=$i; break; }
  }

  // Key für die Einheit: erstes Segment NACH „Wohnungen“ oder letztes Segment
  $wohnKeyRaw = ($idxWohn!==null && isset($parts[$idxWohn+1])) ? $parts[$idxWohn+1] : (count($parts)?end($parts):'');
  $wohnKeyNorm=$norm($wohnKeyRaw);

  // --- NEU: Objekt-Name als Segment VOR „Wohnungen“ nehmen (wenn vorhanden)
  $objKeyRaw = ($idxWohn!==null && $idxWohn>0) ? $parts[$idxWohn-1] : (count($parts)>=2 ? $parts[count($parts)-2] : '');
  $objKeyNorm=$norm($objKeyRaw);

  // Falls der Objektkey leer ist, optional Muster „Objekt (\d+)“ direkt aus Pfad lesen
  if($objKeyNorm===''){
    foreach($parts as $p){ if(preg_match('~^objekt\s*(\d+)$~iu',trim($p))){ $objKeyRaw=$p; $objKeyNorm=$norm($p); break; } }
  }

  // ----- Objekte laden
  $rowsObj=[];
  if ($fkProjInLieg){
    $st=$db->prepare("SELECT id, {$colNameLieg} AS name FROM {$tblLieg} WHERE {$fkProjInLieg}=? ORDER BY id ASC");
    $st->bind_param("i",$projekt_id);
  } else {
    $st=$db->prepare("SELECT id, {$colNameLieg} AS name FROM {$tblLieg} ORDER BY id ASC");
  }
  $st->execute(); $res=$st->get_result(); while($r=$res->fetch_assoc()) $rowsObj[]=$r; $st->close();
  if(!$rowsObj) return $out;

  // Objekt matchen: erst exakter/teilweiser Namensmatch mit dem Segment vor „Wohnungen“
  $objId=null; $objName=null;
  if($objKeyNorm!==''){
    foreach($rowsObj as $r){
      $n=$norm($r['name']);
      if($n===$objKeyNorm || strpos($n,$objKeyNorm)!==false || strpos($objKeyNorm,$n)!==false){ $objId=(int)$r['id']; $objName=$r['name']; break; }
    }
  }
  // Fallback: Falls nicht gefunden, heuristisch wie bisher (evtl. erstes Objekt)
  if(!$objId){
    $objId=(int)$rowsObj[0]['id']; $objName=$rowsObj[0]['name'];
  }

  $out['liegenschaft_id']=$objId; $out['lieg_name']=$objName;

  // ----- Einheiten zum Objekt laden
  // Spalten erkennen
  $hasNum = column_exists($db,$tblEinheit,'nummer') || column_exists($db,$tblEinheit,'nr');
  $hasWhg = column_exists($db,$tblEinheit,'whg') || column_exists($db,$tblEinheit,'whg_nr') || column_exists($db,$tblEinheit,'wohnung_nr');
  $colNum = column_exists($db,$tblEinheit,'nummer') ? 'nummer' : (column_exists($db,$tblEinheit,'nr') ? 'nr' : null);
  $colWhg = column_exists($db,$tblEinheit,'whg') ? 'whg'
           : (column_exists($db,$tblEinheit,'whg_nr') ? 'whg_nr'
           : (column_exists($db,$tblEinheit,'wohnung_nr') ? 'wohnung_nr' : null));

  $cols="id, {$colNameEinheit} AS name";
  if($colNum) $cols.=", `$colNum` AS nummer";
  if($colWhg) $cols.=", `$colWhg` AS whg";

  $rowsE=[]; $st=$db->prepare("SELECT $cols FROM {$tblEinheit} WHERE {$fkLiegInEinheit}=? ORDER BY id ASC");
  $st->bind_param("i",$objId); $st->execute();
  $res=$st->get_result(); while($r=$res->fetch_assoc()) $rowsE[]=$r; $st->close();
  if(!$rowsE) return $out;

  foreach($rowsE as $r) $out['einheiten'][]=['id'=>(int)$r['id'],'name'=>(string)$r['name']];

  // Zahl aus „Wohnung1“ extrahieren
  $numFromPath=null; if(preg_match('~(\d+(?:\.\d+)?)~u',$wohnKeyRaw,$m)) $numFromPath=$m[1];

  // 1) exakter Normal-Name
  foreach($rowsE as $r){ if($norm($r['name'])===$wohnKeyNorm){ $out['einheit_id']=(int)$r['id']; $out['einheit_name']=$r['name']; return $out; } }
  // 2) Teil-Name
  foreach($rowsE as $r){ $n=$norm($r['name']); if($n!=='' && ($n===$wohnKeyNorm || strpos($n,$wohnKeyNorm)!==false || strpos($wohnKeyNorm,$n)!==false)){ $out['einheit_id']=(int)$r['id']; $out['einheit_name']=$r['name']; return $out; } }
  // 3) Nummernmatch
  if($numFromPath!==null){
    foreach($rowsE as $r){
      if($colNum && isset($r['nummer']) && (string)$r['nummer']===(string)$numFromPath){ $out['einheit_id']=(int)$r['id']; $out['einheit_name']=$r['name']; return $out; }
      if($colWhg && isset($r['whg'])   && (string)$r['whg']===(string)$numFromPath){ $out['einheit_id']=(int)$r['id']; $out['einheit_name']=$r['name']; return $out; }
      if(preg_match('~(^|[^0-9])'.preg_quote((string)$numFromPath,'~').'([^0-9]|$)~u',$r['name'])){ $out['einheit_id']=(int)$r['id']; $out['einheit_name']=$r['name']; return $out; }
    }
  }
  // 4) Spezial: beide Namen beginnen mit „wohnung“
  if($wohnKeyNorm!==''){
    foreach($rowsE as $r){
      $n=$norm($r['name']);
      if(strpos($n,'wohnung')===0 && strpos($wohnKeyNorm,'wohnung')===0){
        if(preg_replace('~[^0-9]+~','',$n) === preg_replace('~[^0-9]+~','',$wohnKeyNorm)){
          $out['einheit_id']=(int)$r['id']; $out['einheit_name']=$r['name']; return $out;
        }
      }
    }
  }
  // 5) Fallback: wenn nur 1 Einheit vorhanden → nimm sie
  if(count($rowsE)===1){
    $out['einheit_id']=(int)$rowsE[0]['id']; $out['einheit_name']=$rowsE[0]['name']; return $out;
  }

  // kein Treffer → Kandidaten bleiben erhalten
  $out['einheit_id']=null;
  return $out;
}


/* ---------- AJAX ---------- */
if ($isAjax) {
  if (function_exists('ob_get_length') && ob_get_length()) { @ob_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (($_GET['ajax'] ?? '')==='guess_from_path') {
      $pid=(int)($_GET['projekt_id'] ?? 0);
      $rel=(string)($_GET['path'] ?? '');
      $out=guess_ids_from_path($mysqli,$pid,$rel,$tblLieg,name_col($mysqli,$tblLieg),$fkProjInLieg,$tblEinheit,name_col($mysqli,$tblEinheit),$fkLiegInEinheit);
      $out['ok']=true; echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'unknown ajax'], JSON_UNESCAPED_UNICODE); exit;
  } catch(Throwable $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); exit;
  }
}

/* ---------- Projektliste & aktuelles Projekt ---------- */
$projekte=[];
if (table_exists($mysqli,$tblProjekte)) {
  if ($res=$mysqli->query("SELECT id,name,status FROM {$tblProjekte} ORDER BY id")) {
    while($r=$res->fetch_assoc()) $projekte[]=$r; $res->close();
  }
}
$projekt=null;
if ($projekt_id>0 && table_exists($mysqli,$tblProjekte)) {
  $sel="SELECT id,name,status"; if (column_exists($mysqli,$tblProjekte,'root_path')) $sel.=",root_path";
  $sel.=" FROM {$tblProjekte} WHERE id=?";
  $st=$mysqli->prepare($sel); $st->bind_param("i",$projekt_id); $st->execute();
  $projekt=$st->get_result()->fetch_assoc(); $st->close();
}

/* ---------- Personenquelle ---------- */
$personTable = null; $personQuery = null;
if (table_exists($mysqli,'benutzer')) {
  $hasVor = column_exists($mysqli,'benutzer','vorname');
  $hasNach= column_exists($mysqli,'benutzer','nachname');
  $hasName= column_exists($mysqli,'benutzer','name');
  $hasMail= column_exists($mysqli,'benutzer','email');
  if ($hasName) $label='name';
  else {
    $parts=[]; if($hasNach) $parts[]="COALESCE(nachname,'')"; if($hasVor) $parts[]="COALESCE(vorname,'')";
    $label="TRIM(CONCAT_WS(' ', ".($parts?implode(',',$parts):"CAST(id AS CHAR)")."))";
  }
  if ($hasMail) $label="TRIM(CONCAT($label, CASE WHEN email IS NULL OR email='' THEN '' ELSE CONCAT(' (',email,')') END))";
  $personTable='benutzer'; $personQuery="SELECT id, $label AS name FROM benutzer ORDER BY name, id";
} elseif (table_exists($mysqli,'mieter')) {
  $colNameMieter=name_col($mysqli,'mieter');
  $personTable='mieter'; $personQuery="SELECT id, {$colNameMieter} AS name FROM mieter ORDER BY name, id";
}

/* ---------- FS-Navigation ---------- */
function crumbs($rel){
  $rel=ltrim((string)$rel,'/'); if($rel==='') return [];
  $parts=explode('/',$rel); $acc=[]; $out=[];
  foreach($parts as $p){ $acc[]=$p; $out[]=['label'=>$p,'rel'=>implode('/',$acc)]; }
  return $out;
}
function child_folders(mysqli $db,$pid,$parentRel=''){
  if (!table_exists($db,'fs_nodes')) return [];
  if ($parentRel==='') {
    $st=$db->prepare("SELECT name, rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND (parent_rel_path IS NULL OR parent_rel_path='') ORDER BY name ASC");
    $st->bind_param("i",$pid);
  } else {
    $st=$db->prepare("SELECT name, rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND parent_rel_path=? ORDER BY name ASC");
    $st->bind_param("is",$pid,$parentRel);
  }
  $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  return $rows;
}

/* ---------- Personen & Zuordnungen ---------- */
$personen=[]; if ($personQuery) { if ($q=$mysqli->query($personQuery)) { while($r=$q->fetch_assoc()) $personen[]=$r; $q->close(); } }

$zuordnungen=[];
if ($projekt_id>0 && table_exists($mysqli,'projekt_verknuepfungen')) {
  if ($personTable==='benutzer') {
    $hasVor=column_exists($mysqli,'benutzer','vorname'); $hasNach=column_exists($mysqli,'benutzer','nachname');
    $hasName=column_exists($mysqli,'benutzer','name');   $hasMail=column_exists($mysqli,'benutzer','email');
    if($hasName) $personLabel="b.name";
    else {
      $parts=[]; if($hasNach) $parts[]="COALESCE(b.nachname,'')"; if($hasVor) $parts[]="COALESCE(b.vorname,'')";
      $personLabel="TRIM(CONCAT_WS(' ', ".($parts?implode(',',$parts):"CAST(b.id AS CHAR)")."))";
    }
    if($hasMail) $personLabel="TRIM(CONCAT($personLabel, CASE WHEN b.email IS NULL OR b.email='' THEN '' ELSE CONCAT(' (',b.email,')') END))";
    $sub_person="(SELECT $personLabel FROM benutzer b WHERE b.id=pv.mieter_id)";
  } elseif ($personTable==='mieter') {
    $colM=name_col($mysqli,'mieter'); $sub_person="(SELECT {$colM} FROM mieter m WHERE m.id=pv.mieter_id)";
  } else $sub_person="NULL";

  $colL=name_col($mysqli,$tblLieg); $colE=name_col($mysqli,$tblEinheit);
  $sub_lieg="(SELECT {$colL} FROM {$tblLieg} l WHERE l.id=pv.liegenschaft_id)";
  $sub_einheit="(SELECT {$colE} FROM {$tblEinheit} ve WHERE ve.id=pv.einheit_id)";

  $sql="SELECT pv.*, {$sub_person} AS mieter_name, {$sub_lieg} AS lieg_name, {$sub_einheit} AS einheit_name
        FROM projekt_verknuepfungen pv
        WHERE pv.projekt_id=?
        ORDER BY pv.status, pv.beginn DESC, pv.id DESC";
  $st=$mysqli->prepare($sql); $st->bind_param('i',$projekt_id); $st->execute();
  $zuordnungen=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
}

/* ---------- POST-Aktionen ---------- */
$flash='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    csrf_validate();
    $act=$_POST['action'] ?? '';

    if($act==='choose_project'){
      $pid=(int)$_POST['projekt_id'];
      header('Location: '.page_url('mieter_zuweisen.php?projekt_id='.$pid)); exit;
    }

    if($act==='save_zuordnung'){
      $projekt_id=(int)($_POST['projekt_id'] ?? 0);
      $fs_rel_path=trim((string)($_POST['fs_rel_path'] ?? ''));
      $mieter_id  =($_POST['mieter_id'] ?? '')!=='' ? (int)$_POST['mieter_id'] : null;
      $mietzins_netto=($_POST['mietzins_netto'] ?? '')!=='' ? (float)$_POST['mietzins_netto'] : null;
      $nk_akonto   =($_POST['nk_akonto'] ?? '')!=='' ? (float)$_POST['nk_akonto'] : null;
      $beginn      =($_POST['beginn'] ?? '')!=='' ? $_POST['beginn'] : null;
      $ende        =($_POST['ende'] ?? '')!=='' ? $_POST['ende'] : null;
      $status      =$_POST['status'] ?? 'aktiv';
      $bemerkung   =trim((string)($_POST['bemerkung'] ?? ''));

      $g=guess_ids_from_path($mysqli,$projekt_id,$fs_rel_path,$tblLieg,name_col($mysqli,$tblLieg),$fkProjInLieg,$tblEinheit,name_col($mysqli,$tblEinheit),$fkLiegInEinheit);
      $liegenschaft_id=(int)($g['liegenschaft_id'] ?? 0);
      $einheit_id=(int)($g['einheit_id'] ?? 0);

      if(!$projekt_id) throw new Exception('Kein Projekt.');
      if(!$liegenschaft_id) throw new Exception('Objekt/Liegenschaft aus Ordner nicht ermittelbar.');

      // Wenn keine eindeutige Einheit erkannt wurde, aber genau 1 Kandidat existiert: nimm ihn
      if(!$einheit_id && !empty($g['einheiten']) && count($g['einheiten'])===1){
        $einheit_id=(int)$g['einheiten'][0]['id'];
      }
      if(!$einheit_id) throw new Exception('Wohnung/Einheit aus Ordner nicht ermittelbar.');

      if(!id_exists($mysqli,$tblLieg,$liegenschaft_id)) throw new Exception("Objekt-ID #$liegenschaft_id existiert nicht in ".($tblLieg?:'?').".");
      if(!id_exists($mysqli,$tblEinheit,$einheit_id))  throw new Exception("Einheit-ID #$einheit_id existiert nicht in ".($tblEinheit?:'?').".");

      // Zielordner <Einheit>/Mieter/<Name> anlegen (unique)
      $personLabel=get_person_label($mysqli,$personTable,(int)($mieter_id ?? 0));
      $folderName =fs_sanitize_name($personLabel ?: ('Mieter_'.($mieter_id ?? '')));

      $unitRel=trim($fs_rel_path,'/');
      if($unitRel!==''){ $lower=mb_strtolower($unitRel,'UTF-8'); if(strpos($lower,'/mieter/')!==false) $unitRel=substr($unitRel,0,strpos($lower,'/mieter/')); }
      $targetRelBase=($unitRel!=='' ? $unitRel.'/' : '').'Mieter/'.$folderName;

      $rootAbs=project_root_path($mysqli,$projekt_id);
      if($rootAbs){
        $mieterDirRel=($unitRel!=='' ? $unitRel.'/' : '').'Mieter';
        $mieterDirAbs=fs_abs_from_rel($rootAbs,$mieterDirRel);
        if($mieterDirAbs) fs_mkdirp($mieterDirAbs);
        $finalRel=fs_ensure_unique_rel($mysqli,$projekt_id,$rootAbs,$targetRelBase);
        $finalAbs=fs_abs_from_rel($rootAbs,$finalRel);
        if($finalAbs && fs_mkdirp($finalAbs)){
          $fs_rel_path=$finalRel;
          if(function_exists('fs_scan_project')){ @fs_scan_project($mysqli,$projekt_id,0); }
        }
      }

      // INSERT (ordner_id = NULL)
      $sql="INSERT INTO projekt_verknuepfungen
        (projekt_id, liegenschaft_id, einheit_id, mieter_id,
         fs_rel_path, ordner_id, status, mietzins_netto, nk_akonto, beginn, ende, bemerkung)
        VALUES (?,?,?,?,?,NULL,?,?,?,?,?,?)";
      $st=$mysqli->prepare($sql); if(!$st) throw new Exception('Prepare fehlgeschlagen: '.$mysqli->error);

      $mietzins_val=isset($mietzins_netto)?number_format($mietzins_netto,2,'.',''):null;
      $nk_val      =isset($nk_akonto)     ?number_format($nk_akonto,2,'.',''):null;

      $ok=$st->bind_param('iiiisssssss',
        $projekt_id, $liegenschaft_id, $einheit_id, $mieter_id,
        $fs_rel_path, $status, $mietzins_val, $nk_val, $beginn, $ende, $bemerkung
      );
      if(!$ok) throw new Exception('bind_param fehlgeschlagen: '.$st->error);
      if(!$st->execute()) throw new Exception('Ausführen fehlgeschlagen: '.$st->error);
      $st->close();

      $flash='✅ Zuordnung gespeichert und Ordner angelegt: <code>'.en($fs_rel_path).'</code>';
    }

    if($act==='move_to_vormieter'){
      $rec_id=(int)($_POST['id'] ?? 0);
      if($rec_id<=0) throw new Exception('Ungültige ID.');

      $q=$mysqli->prepare("SELECT projekt_id, fs_rel_path, mieter_id, beginn, ende FROM projekt_verknuepfungen WHERE id=?");
      $q->bind_param('i',$rec_id); $q->execute(); $row=$q->get_result()->fetch_assoc(); $q->close();
      if(!$row) throw new Exception('Datensatz nicht gefunden.');

      $pid=(int)$row['projekt_id']; $srcRel=trim((string)$row['fs_rel_path'],'/'); $mieterId=(int)($row['mieter_id'] ?? 0);
      $von=$row['beginn'] ?: null; $bis=date('Y-m-d');
      if($srcRel==='') throw new Exception('Kein Mieter-Ordnerpfad gespeichert.');

      $unitRel=$srcRel; $lower=mb_strtolower($unitRel,'UTF-8');
      if(strpos($lower,'/mieter/')!==false) $unitRel=substr($unitRel,0,strpos($lower,'/mieter/')); else $unitRel=fs_rel_parent($srcRel);

      $personLabel=get_person_label($mysqli,$personTable,$mieterId);
      $rootAbs=project_root_path($mysqli,$pid); if(!$rootAbs) throw new Exception('Kein Projekt-Root.');

      $dstRel=build_vormieter_target_rel($mysqli,$pid,$rootAbs,$unitRel,$personLabel,$von,$bis);
      $movedRel=fs_move_dir($mysqli,$pid,$rootAbs,$srcRel,$dstRel); if(!$movedRel) throw new Exception('Verschieben fehlgeschlagen.');

      $upd=$mysqli->prepare("UPDATE projekt_verknuepfungen SET fs_rel_path=?, status='historisch', ende=? WHERE id=?");
      $upd->bind_param('ssi',$movedRel,$bis,$rec_id); $upd->execute(); $upd->close();

      $flash='📦 Vormieter angelegt: <code>'.en($movedRel).'</code> (inkl. Inhalt verschoben).';
    }

    if($act==='delete_zuordnung'){
      $rec_id=(int)($_POST['id'] ?? 0);
      if($rec_id<=0) throw new Exception('Ungültige ID.');
      $del=$mysqli->prepare("DELETE FROM projekt_verknuepfungen WHERE id=?");
      $del->bind_param('i',$rec_id); $del->execute(); $del->close();
      $flash='🗑️ Zuordnung gelöscht.';
    }

  }catch(Throwable $e){ $flash='❌ '.en($e->getMessage()); }
}

/* ---------- ERST JETZT HTML/Includes ---------- */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Mieter/Benutzer zuweisen (Ordner als Master)</title>
<link rel="stylesheet" href="../assets/app.css"><!-- optional -->
<style>
.container{max-width:1200px;margin:20px auto;padding:10px}
.grid{display:grid;grid-template-columns:320px 1fr;gap:16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px}
label{font-weight:600}
select,input,textarea,button{padding:8px;border:1px solid #e5e7eb;border-radius:8px;width:100%}
.btn{display:inline-block;padding:8px 10px;border-radius:8px;background:#0a2a6e;color:#fff;text-decoration:none;border:0;cursor:pointer;width:auto}
.btn:hover{background:#071c4a}
.small{font-size:12px;color:#6b7280}
.badge{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:8px;border-bottom:1px solid #eef2f7;text-align:left}
.tree a{display:block;padding:6px 8px;border-radius:8px;text-decoration:none;color:#111827;margin:1px 0}
.tree a:hover{background:#f1f5f9}
.tree a.active{background:#111827;color:#fff}
.filebar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:8px 0}
.filebar .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:6px 10px;text-decoration:none;color:#111827}
.filebar .chip:hover{background:#f8fafc}
.row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
</style>

<script>
var CSRF_TOKEN = "<?php echo en(csrf_token_value()); ?>";
var CUR_PATH   = <?php echo json_encode($ctxRel ?: ''); ?>;
var PROJ_ID    = <?php echo (int)$projekt_id; ?>;

async function fetchJSON(u, opt){
  const r = await fetch(u, opt||{});
  const ct = (r.headers.get('content-type')||'').toLowerCase();
  if (!ct.includes('application/json')) {
    const txt = await r.text();
    console.error('Kein JSON erhalten:', txt);
    throw new Error('Server lieferte kein JSON (siehe Console)');
  }
  return r.json();
}

// NEU: liest primär aus dem Feld fs_rel_path
async function takeFolderPath(){
  const inp = document.getElementById('fs_rel_path');
  let rel = (inp.value || '').trim();
  if (!rel) { // fallback auf den aktuell navigierten Ordner
    rel = CUR_PATH || '';
    inp.value = rel;
  }

  const box = document.getElementById('fallback_box');
  const sel = document.getElementById('fallback_einheit');
  box.style.display = 'none';
  sel.innerHTML = '';

  if(!PROJ_ID || !rel){ showGuess('(kein Projekt oder Pfad)'); return; }

  const g = await fetchJSON('mieter_zuweisen.php?ajax=guess_from_path&projekt_id='+PROJ_ID+'&path='+encodeURIComponent(rel));
  if(g && g.ok){
    document.getElementById('liegenschaft_id').value = g.liegenschaft_id || '';
    document.getElementById('einheit_id').value      = g.einheit_id || '';

    const label = (g.lieg_name?('Objekt: '+g.lieg_name):'Objekt: ?') + ' · ' + (g.einheit_name?('Wohnung: '+g.einheit_name):'Wohnung: ?');
    showGuess(label);

    if (!g.einheit_id && Array.isArray(g.einheiten)){
      if (g.einheiten.length === 1){
        // Auto-Pick, wenn genau eine Kandidaten-Einheit
        document.getElementById('einheit_id').value = g.einheiten[0].id;
        showGuess((g.lieg_name?('Objekt: '+g.lieg_name):'Objekt: ?') + ' · Wohnung: '+g.einheiten[0].name);
      } else if (g.einheiten.length){
        box.style.display = 'block';
        sel.insertAdjacentHTML('beforeend','<option value="">– Wohnung wählen –</option>');
        g.einheiten.forEach(function(e){
          sel.insertAdjacentHTML('beforeend','<option value="'+e.id+'">'+e.name+'</option>');
        });
        sel.onchange = function(){
          document.getElementById('einheit_id').value = this.value || '';
          const picked = this.options[this.selectedIndex]?.text || '';
          showGuess((g.lieg_name?('Objekt: '+g.lieg_name):'Objekt: ?') + ' · Wohnung: '+picked);
        };
      }
    }
  } else {
    showGuess('Keine Zuordnung ermittelbar.');
  }
}

// Bonus: beim Tippen/Einfügen im Feld automatisch prüfen
async function guessOnPathChange(){
  const inp = document.getElementById('fs_rel_path');
  let rel = (inp.value || '').trim();
  if (!rel || !PROJ_ID) { showGuess('—'); return; }

  const g = await fetchJSON('mieter_zuweisen.php?ajax=guess_from_path&projekt_id='+PROJ_ID+'&path='+encodeURIComponent(rel));
  if(g && g.ok){
    document.getElementById('liegenschaft_id').value = g.liegenschaft_id || '';
    document.getElementById('einheit_id').value      = g.einheit_id || '';
    const label = (g.lieg_name?('Objekt: '+g.lieg_name):'Objekt: ?') + ' · ' + (g.einheit_name?('Wohnung: '+g.einheit_name):'Wohnung: ?');
    showGuess(label);
  }
}

document.addEventListener('DOMContentLoaded', function(){
  const inp = document.getElementById('fs_rel_path');
  if (inp) {
    inp.addEventListener('change', guessOnPathChange);
    inp.addEventListener('blur', guessOnPathChange);
    inp.addEventListener('paste', function(){ setTimeout(guessOnPathChange, 0); });
    // NEU: beim Laden sofort versuchen, aus dem aktuellen Ordner zu raten
    guessOnPathChange().catch(console.error);
  }
});

</script>
</head>
<body>
<div class="container">
  <?php if ($flash): ?>
    <div class="card" style="border-color:#cde;background:#effaf0"><?= $flash ?></div>
  <?php endif; ?>

  <div class="card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <strong>🧩 Benutzer/Mieter einer Wohnung zuweisen (Ordner-first)</strong>
      <a class="badge" href="<?= en(page_url('projekt_dashboard.php'.($projekt_id?('?id='.$projekt_id):''))) ?>">Projekt-Dashboard</a>
      <a class="badge" href="<?= en(page_url('projekt_verknuepfungen.php'.($projekt_id?('?projekt_id='.$projekt_id):''))) ?>">Bindeglied</a>
      <a class="badge" href="<?= en(page_url('pendenzen.php'.($projekt_id?('?projekt_id='.$projekt_id):''))) ?>">Pendenzen</a>
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="choose_project">
      <label for="projekt_id">Projekt</label>
      <select id="projekt_id" name="projekt_id" onchange="this.form.submit()">
        <option value="">– wählen –</option>
        <?php foreach($projekte as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $projekt_id===(int)$p['id']?'selected':'' ?>>
            <?= en('#'.$p['id'].' '.($p['name']??'')) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($projekt_id): ?>
        <a class="btn" href="<?= en(page_url('mieter_zuweisen.php?projekt_id='.$projekt_id)) ?>">Ordner-Root</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$projekt_id || !$projekt): ?>
    <div class="card" style="margin-top:10px">Bitte zuerst ein Projekt wählen.</div>
  <?php else: ?>

  <div class="grid" style="margin-top:10px">
    <aside class="card">
      <div class="tree">
        <div style="font-weight:600;margin-bottom:8px;">Ordner-Navigation</div>
        <?php
          $anc=crumbs($ctxRel); array_unshift($anc,['label'=>'Root','rel'=>'']); $current=$ctxRel;
          foreach($anc as $i=>$node):
            $rel=$node['rel']; $childrenL=child_folders($mysqli,$projekt_id,$rel);
            $open=($rel==='' || strpos($current,$rel.'/')===0 || $current===$rel);
        ?>
          <details <?= $open?'open':'' ?> style="margin-bottom:6px;">
            <summary style="cursor:pointer;padding:6px 8px;border-radius:8px;background:#f8fafc">
              <span class="badge">#<?= (int)$i ?></span>
              <strong style="margin-left:6px;"><?= en($node['label']) ?></strong>
            </summary>
            <div style="padding:6px 0 0 8px">
              <?php foreach($childrenL as $ch):
                $href = page_url('mieter_zuweisen.php?projekt_id='.$projekt_id.'&path='.rawurlencode($ch['rel_path']));
                $active = ($current === $ch['rel_path']) ? 'active' : '';
              ?>
                <a class="<?= $active ?>" href="<?= en($href) ?>">📁 <?= en($ch['name']) ?></a>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </aside>

    <section>
      <div class="card">
        <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
          <div>
            <div class="small">Projekt: <strong><?= en($projekt['name'] ?? '') ?></strong> · Status: <strong><?= en($projekt['status'] ?? '') ?></strong></div>
            <div class="filebar">
              <?php
                if ($ctxRel!=='') {
                  $parent = ($ctxRel && strrpos($ctxRel,'/')!==false) ? substr($ctxRel,0,strrpos($ctxRel,'/')) : '';
                  $upHref = page_url('mieter_zuweisen.php?projekt_id='.$projekt_id.($parent!==''?'&path='.rawurlencode($parent):''));
                  echo '<a class="chip" href="'.en($upHref).'">↥ Eine Ebene hoch</a>';
                }
                $filesUrl = page_url('files.php?projekt_id='.$projekt_id.($ctxRel!==''?'&path='.rawurlencode($ctxRel):'')); ?>
              <a class="chip" target="_blank" href="<?= en($filesUrl) ?>">📁 Im Dateibrowser</a>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <span class="badge">Aktueller Ordner: <code><?= en($ctxRel?:'(Root)') ?></code></span>
            <button class="btn" type="button" onclick="takeFolderPath()">📌 Ordnerlink übernehmen</button>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:10px">
        <h3 style="margin:0 0 10px;">Zuweisung speichern</h3>
        <form method="post">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="save_zuordnung">
          <input type="hidden" name="projekt_id" value="<?= (int)$projekt_id ?>">
          <input type="hidden" id="liegenschaft_id" name="liegenschaft_id" value="">
          <input type="hidden" id="einheit_id" name="einheit_id" value="">

          <div class="row-2">
            <div>
              <label><?= $personTable==='benutzer' ? 'Benutzer (Mieter)' : ($personTable==='mieter' ? 'Mieter' : 'Person') ?></label>
              <select id="mieter_id" name="mieter_id">
                <option value="">– keiner –</option>
                <?php if ($personen): foreach($personen as $m): ?>
                  <option value="<?= (int)$m['id'] ?>"><?= en($m['name'] ?? ('#'.$m['id'])) ?></option>
                <?php endforeach; else: ?>
                  <option value="" disabled>(Keine Personentabelle vorhanden)</option>
                <?php endif; ?>
              </select>
              <div class="small" style="margin-top:6px">Verwaltung zentral: <?= en($personTable ?: '—') ?>.</div>
            </div>
            <div>
              <label>Erkannte Zuordnung</label>
              <div id="guess_label" class="badge" style="display:block">—</div>
              <div class="small">Tipp: Feld unten ausfüllen/ändern oder Button klicken.</div>
              <div id="fallback_box" class="small" style="display:none; margin-top:8px;">
                <label style="font-weight:600; display:block; margin-bottom:4px;">Keine eindeutige Wohnung erkannt – bitte wählen:</label>
                <select id="fallback_einheit" style="max-width:420px;"></select>
              </div>
            </div>
          </div>

          <div style="margin-top:10px">
            <label>fs_rel_path (Ordner der Einheit/Mieter)</label>
            <input type="text" id="fs_rel_path" name="fs_rel_path" value="<?= en($ctxRel ?: '') ?>" placeholder="z. B. Projekt/Objekt 2/Wohnungen/Wohnung1">
            <div class="small">Tipp: Pfad hier einfügen/ändern oder oben „Ordnerlink übernehmen“ klicken.</div>
          </div>

          <div class="row-3" style="margin-top:10px">
            <div><label>Mietzins netto (CHF)</label><input type="number" step="0.01" name="mietzins_netto"></div>
            <div><label>NK Akonto (CHF)</label><input type="number" step="0.01" name="nk_akonto"></div>
            <div>
              <label>Status</label>
              <select name="status">
                <?php foreach(['aktiv','historisch','geplant'] as $s): ?>
                  <option value="<?= en($s) ?>"><?= en($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row-2" style="margin-top:10px">
            <div><label>Beginn</label><input type="date" name="beginn"></div>
            <div><label>Ende</label><input type="date" name="ende"></div>
          </div>

          <div style="margin-top:10px"><label>Bemerkung</label><textarea name="bemerkung" rows="3"></textarea></div>

          <div style="margin-top:12px"><button class="btn">Speichern</button></div>
        </form>
      </div>

      <div class="card" style="margin-top:10px">
        <h3 style="margin:0 0 10px;">Bestehende Zuordnungen</h3>
        <table class="tbl">
          <thead><tr>
            <th>Objekt</th><th>Wohnung</th><th><?= $personTable==='benutzer' ? 'Benutzer' : 'Mieter' ?></th><th>Miete/NK</th><th>Zeitraum</th><th>Status</th><th>Aktion</th>
          </tr></thead>
          <tbody>
          <?php if (!$zuordnungen): ?>
            <tr><td colspan="7" class="small" style="padding:10px;color:#666">Keine Zuordnungen gefunden.</td></tr>
          <?php else: foreach($zuordnungen as $v):
            $periode=(($v['beginn']??'')!==''?$v['beginn']:'—').' bis '.(($v['ende']??'')!==''?$v['ende']:'—');
            $miete=(($v['mietzins_netto']!==null)?number_format((float)$v['mietzins_netto'],2,'.','').' CHF':'–').'/'.(($v['nk_akonto']!==null)?number_format((float)$v['nk_akonto'],2,'.','').' CHF':'–');
          ?>
            <tr>
              <td><?= en(($v['lieg_name']??'')!=='' ? $v['lieg_name'] : ('#'.(int)$v['liegenschaft_id'])) ?></td>
              <td><?= en(($v['einheit_name']??'')!=='' ? $v['einheit_name'] : ('#'.(int)$v['einheit_id'])) ?></td>
              <td><?= en(($v['mieter_name']??'')!=='' ? $v['mieter_name'] : '—') ?></td>
              <td><?= en($miete) ?></td>
              <td><?= en($periode) ?></td>
              <td><span class="badge"><?= en($v['status'] ?? '') ?></span></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Zuordnung löschen?')">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_zuordnung">
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="badge" style="border:none;cursor:pointer">🗑️ Löschen</button>
                </form>
                <?php if (!empty($v['fs_rel_path']) && ($v['status'] ?? '')!=='historisch'): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Mieter ausziehen und Ordner verschieben?')">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="move_to_vormieter">
                    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                    <button class="badge" style="border:none;cursor:pointer">🚚 Auszug</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <?php endif; ?>
</div>
</body>
</html>
