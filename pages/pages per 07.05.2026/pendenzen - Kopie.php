<?php
// pages/pendenzen.php
// Stand: 2025-10-02

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php'; // handle_upload(), url(), e(), etc.
require_once __DIR__ . '/../includes/user_ui.php';
require_once __DIR__ . '/../includes/media.php';     // pendenz_fs_base(), image_to_max_1mb()
require_once __DIR__ . '/../includes/fs.php';        // fs_* helpers

require_login();

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$PREFIX = site_prefix();              // z.B. "/pendenz.com/"
$ASSET  = rtrim($PREFIX,'/').'/';     // für Bildpfade etc.
$flash  = "";

/** -------- Helpers/Fallbacks -------- */
if (!function_exists('dbcol')) {
  function dbcol($db, $sql){
    $r=$db->query($sql);
    if(!$r) return null;
    if (method_exists($r,'fetch_column')) return $r->fetch_column();
    $row = $r->fetch_row();
    return $row ? $row[0] : null;
  }
}
if (!function_exists('slugify')) {
  function slugify($s){
    $s = (string)($s ?? 'file');
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = preg_replace('~[^A-Za-z0-9._-]+~', '-', $s);
    return strtolower(trim($s, '-')) ?: 'file';
  }
}
if (!function_exists('abs_path_from')) {
  function abs_path_from($rel){
    $base = realpath(__DIR__.'/..') ?: (dirname(__DIR__));
    return rtrim($base,'/\\').'/'.ltrim($rel,'/');
  }
}
if (!function_exists('str_starts_with')) {
  function str_starts_with($s,$n){ return (string)$n === '' || strpos($s,$n) === 0; }
}

/** -------- DB Helpers -------- */
function table_exists(mysqli $db, string $name): bool {
  $name = $db->real_escape_string($name);
  $res = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$name}'");
  return ($res && $res->num_rows>0);
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $t = $db->real_escape_string($table);
  $c = $db->real_escape_string($col);
  $res = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='{$t}' AND column_name='{$c}'");
  return ($res && $res->num_rows>0);
}
function fetch_projekte($db){ return $db->query("SELECT id,name FROM projekte ORDER BY name ASC"); }
function fetch_members_by_project($db, $pid){
  $stmt=$db->prepare("
    SELECT DISTINCT b.id,b.name,b.email
    FROM benutzer b
    LEFT JOIN benutzer_projekte bp ON bp.benutzer_id=b.id AND bp.projekt_id=?
    LEFT JOIN team_projekte tp ON tp.projekt_id=?
    LEFT JOIN benutzer_teams bt ON bt.benutzer_id=b.id AND bt.team_id=tp.team_id
    WHERE bp.projekt_id IS NOT NULL OR bt.team_id IS NOT NULL
    ORDER BY b.name
  ");
  $stmt->bind_param("ii",$pid,$pid); $stmt->execute(); return $stmt->get_result();
}
function fetch_teams_by_project($db, $pid){
  $stmt=$db->prepare("SELECT t.id,t.name FROM team_projekte tp JOIN teams t ON t.id=tp.team_id WHERE tp.projekt_id=? ORDER BY t.name");
  $stmt->bind_param("i",$pid); $stmt->execute(); return $stmt->get_result();
}
function fetch_firmen_by_project($db, $pid){
  if (table_exists($db,'firma_projekte')) {
    $stmt=$db->prepare("SELECT f.id,f.name FROM firmen f JOIN firma_projekte fp ON fp.firma_id=f.id WHERE fp.projekt_id=? ORDER BY f.name");
    $stmt->bind_param("i",$pid); $stmt->execute(); return $stmt->get_result();
  }
  if (table_exists($db,'firmen')) return $db->query("SELECT id,name FROM firmen ORDER BY name");
  $mem = new ArrayObject(); return $mem->getIterator();
}
function fetch_field_defs($db){
  if (!table_exists($db,'pendenz_field_defs')) {
    $mem = new ArrayObject(); return $mem->getIterator();
  }
  return $db->query("SELECT * FROM pendenz_field_defs WHERE enabled=1 ORDER BY sort_order,id");
}

/** -------- FS aus fs_nodes -------- */
// fs_folder_options entfernt, da wir die Ordnerstruktur fest an die Wohnungen koppeln
// fs_folder_rows entfernt (Dateisystem ist nun an Wohnungen gebunden)

/** -------- Inline JSON APIs / AJAX -------- */
// Kontext (Mitglieder/Teams/Firmen)
if (isset($_GET['action']) && $_GET['action']==='context') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $pid = (int)($_GET['projekt_id'] ?? 0);
    if ($pid<=0) { echo json_encode(['ok'=>false,'error'=>'projekt_id fehlt']); exit; }
    
    $members=[]; $rs1=fetch_members_by_project($mysqli,$pid); 
    if($rs1) while($x=$rs1->fetch_assoc()){ $members[]=['id'=>(int)$x['id'],'name'=>$x['name'],'email'=>$x['email']]; }
    
    $teams=[]; $rs2=fetch_teams_by_project($mysqli,$pid);   
    if($rs2) while($x=$rs2->fetch_assoc()){ $teams[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $cos=[]; $rs3=fetch_firmen_by_project($mysqli,$pid);   
    if($rs3) foreach($rs3 as $x){ $cos[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $oid = (int)($_GET['objekt_id'] ?? 0);
    $wohns=[]; 
    $wQuery = "SELECT w.id, w.name, o.name as objekt_name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$pid";
    if($oid > 0) $wQuery .= " AND w.objekt_id=$oid";
    $wQuery .= " ORDER BY o.name, w.name";
    $rs4=$mysqli->query($wQuery);
    if($rs4) while($x=$rs4->fetch_assoc()){ $wohns[]=['id'=>(int)$x['id'],'name'=>$x['name'],'objekt_name'=>$x['objekt_name']]; }
    
    $objs=[]; $rs5=$mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=$pid ORDER BY name");
    if($rs5) while($x=$rs5->fetch_assoc()){ $objs[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $kats=[]; $rs6=$mysqli->query("SELECT id, name FROM pendenz_kategorien WHERE projekt_id IS NULL OR projekt_id=$pid ORDER BY name");
    if($rs6) while($x=$rs6->fetch_assoc()){ $kats[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    echo json_encode([
      'ok'=>true,
      'members'=>$members,
      'teams'=>$teams,
      'companies'=>$cos,
      'apartments'=>$wohns,
      'objects'=>$objs,
      'categories'=>$kats
    ], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $t) {
    echo json_encode(['ok'=>false, 'error'=>$t->getMessage()]);
  }
  exit;
}
// FS-Folder API wurde entfernt, Pfade generieren sich automatisch!
// Unterkategorien (robust)
if (isset($_GET['action']) && $_GET['action'] === 'subcats') {
  header('Content-Type: application/json; charset=utf-8');
  $kid = (int)($_GET['kategorie_id'] ?? 0);
  $items = [];
  try {
    $exists = table_exists($mysqli,'pendenz_subkategorien');
    if ($kid > 0 && $exists) {
      $st = $mysqli->prepare("SELECT id, name FROM pendenz_subkategorien WHERE kategorie_id=? ORDER BY name");
      $st->bind_param("i", $kid);
      $st->execute(); $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $items[] = ['id'=>(int)$r['id'],'name'=>(string)$r['name']];
      $st->close();
    }
    echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'items'=>[]], JSON_UNESCAPED_UNICODE);
  }
  exit;
}
// Medien: Liste/Cover/Löschen
if (isset($_GET['action']) && in_array($_GET['action'],['media_list','media_set_cover','media_delete'],true)) {
  header('Content-Type: application/json; charset=utf-8');
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $pendenz_id = (int)($_GET['id'] ?? 0);
  $p = null; if ($pendenz_id>0){ $r=$mysqli->query("SELECT * FROM pendenzen WHERE id={$pendenz_id}"); $p=$r?$r->fetch_assoc():null; }
  if (!$p || !can_view_pendenz($mysqli,$p,$uid)) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }

  if ($_GET['action']==='media_list') {
    $rows=[]; $res=$mysqli->query("SELECT id,typ,pfad,titel,is_cover FROM pendenz_dateien WHERE pendenz_id={$pendenz_id} ORDER BY id DESC");
    while($a=$res->fetch_assoc()){ $rows[]=$a; }
    echo json_encode(['ok'=>true,'items'=>$rows]); exit;
  }
  if (!can_edit_pendenz($mysqli,$p,$uid)) { echo json_encode(['ok'=>false,'error'=>'Edit not allowed']); exit; }

  if ($_GET['action']==='media_set_cover' && $_SERVER['REQUEST_METHOD']==='POST') {
    $fid=(int)($_POST['file_id']??0);
    $has=$mysqli->query("SELECT id FROM pendenz_dateien WHERE id={$fid} AND pendenz_id={$pendenz_id}")->num_rows>0;
    if(!$has){ echo json_encode(['ok'=>false,'error'=>'File not found']); exit; }
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pendenz_id}");
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id={$fid}");
    echo json_encode(['ok'=>true]); exit;
  }
  if ($_GET['action']==='media_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $fid=(int)($_POST['file_id']??0);
    $rowR=$mysqli->query("SELECT * FROM pendenz_dateien WHERE id={$fid} AND pendenz_id={$pendenz_id}");
    $row=$rowR?$rowR->fetch_assoc():null;
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'File not found']); exit; }
    $abs=__DIR__.'/../'.ltrim($row['pfad'],'/');
    if(is_file($abs)) @unlink($abs);
    $mysqli->query("DELETE FROM pendenz_dateien WHERE id={$fid}");
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false,'error'=>'Invalid request']); exit;
}

// Reihenfolge speichern (Drag&Drop)
if (isset($_GET['action']) && $_GET['action']==='reorder' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  $ids = $_POST['ids'] ?? [];
  if (!is_array($ids) || empty($ids)) { echo json_encode(['ok'=>false,'error'=>'ids leer']); exit; }
  $hasSort = column_exists($mysqli, 'pendenzen', 'sort_index');
  if (!$hasSort) { echo json_encode(['ok'=>false,'error'=>'Spalte pendenzen.sort_index fehlt']); exit; }

  $uid = (int)($_SESSION['user_id'] ?? 0);
  $stmtSelect = $mysqli->prepare("SELECT * FROM pendenzen WHERE id=?");
  $stmtUpdate = $mysqli->prepare("UPDATE pendenzen SET sort_index=? WHERE id=?");

  $base = time() * 1000; // stabil
  $i = 0;
  foreach ($ids as $raw) {
    $id = (int)$raw;
    $stmtSelect->bind_param("i",$id);
    $stmtSelect->execute();
    $res = $stmtSelect->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) continue;
    if (!can_edit_pendenz($mysqli, $row, $uid)) continue;

    $val = $base + $i;
    $stmtUpdate->bind_param("ii", $val, $id);
    $stmtUpdate->execute();
    $i++;
  }
  $stmtSelect->close();
  $stmtUpdate->close();
  echo json_encode(['ok'=>true]);
  exit;
}

/** -------- Duplizieren -------- */
if (isset($_GET['duplicate'])) {
  $srcId=(int)$_GET['duplicate'];
  $srcR=$mysqli->query("SELECT * FROM pendenzen WHERE id={$srcId}");
  $src=$srcR?$srcR->fetch_assoc():null;
    if ($src && can_view_pendenz($mysqli,$src,(int)(current_user_id()??0))) {
    $uid=(int)(current_user_id()??0);
    // Sicherstellen, dass uid existiert
    $chkUser = $mysqli->query("SELECT id FROM benutzer WHERE id=$uid");
    if (!$chkUser || $chkUser->num_rows === 0) {
        $uid = 15; // Fallback zu Admin Demo
    }
    $st=$mysqli->prepare("INSERT INTO pendenzen
      (projekt_id, ordner_id, fs_rel_path, titel, kurzbeschreibung, langbeschreibung, notiz,
       wichtigkeit, startdatum, enddatum, status, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id, extra_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $titel=''; $kurz=''; $lang=''; $notiz=''; $status='offen'; $jx=$src['extra_json'] ?? null;
    $st->bind_param("iisssssisssisiss",
      $src['projekt_id'], $src['ordner_id'], $src['fs_rel_path'],
      $titel,$kurz,$lang,$notiz,
      $src['wichtigkeit'],$src['startdatum'],$src['enddatum'],$status,$uid,$src['sichtbarkeit'],$src['assignee_can_edit'],$src['zustaendig_id'],$jx
    );
    $st->execute(); $newId=$st->insert_id; $st->close();
    header("Location: ".$PREFIX."pages/pendenzen.php?edit=".$newId);
    exit;
  }
}

/** -------- Pendenz löschen (inkl. Dateien) -------- */
if (isset($_GET['delete'])) {
  $did=(int)$_GET['delete'];
  $r=$mysqli->query("SELECT * FROM pendenzen WHERE id={$did}");
  $p=$r?$r->fetch_assoc():null;
  if ($p && can_edit_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) {
    $ra=$mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$did}");
    while($a=$ra->fetch_assoc()){
      $abs=__DIR__.'/../'.ltrim($a['pfad'],'/');
      if(is_file($abs)) @unlink($abs);
    }
    $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id={$did}");
    $mysqli->query("DELETE FROM pendenzen WHERE id={$did}");
    log_action($mysqli,'pendenz',$did,'delete');
  }
  header("Location: ".$PREFIX."pages/pendenzen.php");
  exit;
}

/** -------- Create/Update -------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_POST['action']) || !in_array($_POST['action'],['delete_file','set_cover','save_template','apply_template'],true))) {
  try{
    // AJAX-Inline? (wir beantworten dann JSON)
    $isAjaxInline = (isset($_POST['inline_new']) && $_POST['inline_new']=='1')
      && (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'],'xmlhttprequest')!==false)
        || (stripos($_SERVER['HTTP_ACCEPT']??'','application/json')!==false)
      );

    $id=(int)($_POST['id']??0);
    $projekt_id=(int)($_POST['projekt_id']??0);
    $wohnung_id=($_POST['wohnung_id']??'')!==''?(int)$_POST['wohnung_id']:null;
    $ordner_id=($_POST['ordner_id']??'')!==''?(int)$_POST['ordner_id']:null;
    $fs_rel_path = isset($_POST['fs_rel_path']) ? trim((string)$_POST['fs_rel_path']) : null;
    if ($fs_rel_path==='') $fs_rel_path = null;

    $titel=trim($_POST['titel']??"");
    $kurz=trim($_POST['kurzbeschreibung']??"");
    $lang=trim($_POST['langbeschreibung']??"");
    $notiz=trim($_POST['notiz']??"");
    $wichtigkeit=(int)($_POST['wichtigkeit']??0);
    $startdatum=($_POST['startdatum']??'')!==''?$_POST['startdatum']:null;
    $enddatum=($_POST['enddatum']??'')!==''?$_POST['enddatum']:null;
    $uhrzeit=($_POST['uhrzeit']??'')!==''?$_POST['uhrzeit']:null;
    $tageszeit=($_POST['tageszeit']??'')!==''?$_POST['tageszeit']:null;
    $status=$_POST['status']??'offen'; if ($status==='in_bearbeitung') $status='in Bearbeitung';

    $sichtbarkeit_ui=$_POST['sichtbarkeit_ui']??'projekt_all';
    $assignee_can_edit=isset($_POST['assignee_can_edit'])?1:0;

    $assignee_type=$_POST['assignee_type']??'user';
    $assignee_user_id=($_POST['assignee_user_id']??'')!==''?(int)$_POST['assignee_user_id']:null;
    $assignee_team_id=($_POST['assignee_team_id']??'')!==''?(int)$_POST['assignee_team_id']:null;
    $assignee_company_id=($_POST['assignee_company_id']??'')!==''?(int)$_POST['assignee_company_id']:null;

    $sicht_team_id=($_POST['sicht_team_id']??'')!==''?(int)$_POST['sicht_team_id']:null;
    $sicht_user_ids=array_filter(array_map('intval',$_POST['sicht_user_ids']??[]),fn($v)=>$v>0);

    $cat_id   = ($_POST['kategorie_id']??'')!==''?(int)$_POST['kategorie_id']:null;
    $sub_id   = ($_POST['unterkategorie_id']??'')!==''?(int)$_POST['unterkategorie_id']:null;

    $confirmation_required = isset($_POST['confirmation_required']) ? 1 : 0;
    $public_enabled        = isset($_POST['public_enabled']) ? 1 : 0;
    $external_can_view     = isset($_POST['external_can_view']) ? 1 : 0;
    $external_can_upload   = isset($_POST['external_can_upload']) ? 1 : 0;
    $is_protocol           = isset($_POST['is_protocol']) ? 1 : 0;
    $protocol_type         = $_POST['protocol_type'] ?? 'none';

    if($titel==="") throw new Exception("Titel ist erforderlich.");
    if($projekt_id<=0) throw new Exception("Projekt auswählen.");

    $sichtbarkeit=($sichtbarkeit_ui==='privat'?'privat':(($sichtbarkeit_ui==='team'||$sichtbarkeit_ui==='users')?'custom':'projekt'));
    $zustaendig_id=($assignee_type==='user' && $assignee_user_id)?$assignee_user_id:null;

    if($id>0){
      $old=$mysqli->query("SELECT * FROM pendenzen WHERE id={$id}")->fetch_assoc();
      if(!$old) throw new Exception("Pendenz nicht gefunden.");
      if(!can_edit_pendenz($mysqli,$old,(int)($_SESSION['user_id']??0))) throw new Exception("Keine Berechtigung.");

      $st=$mysqli->prepare("UPDATE pendenzen
        SET projekt_id=?, wohnung_id=?, ordner_id=?, fs_rel_path=?, titel=?, kurzbeschreibung=?, langbeschreibung=?, notiz=?, wichtigkeit=?, startdatum=?, enddatum=?, uhrzeit=?, tageszeit=?, status=?, sichtbarkeit=?, assignee_can_edit=?, zustaendig_id=?, is_protocol=?, protocol_type=?
        WHERE id=?");
      $st->bind_param("iiisssssissssssiisii",
        $projekt_id, $wohnung_id, $ordner_id, $fs_rel_path, $titel, $kurz, $lang, $notiz, $wichtigkeit, $startdatum, $enddatum, $uhrzeit, $tageszeit, $status, $sichtbarkeit, $assignee_can_edit, $zustaendig_id, $is_protocol, $protocol_type, $id
      );
      $st->execute(); $pendenz_id=$id; log_action($mysqli,'pendenz',$id,'update',['title'=>$titel,'status'=>$status]);
    } else {
      $erstellt_von=(int)(current_user_id()??0);
      $chkUser = $mysqli->query("SELECT id FROM benutzer WHERE id=$erstellt_von");
      if (!$chkUser || $chkUser->num_rows === 0) {
          $erstellt_von = 15; // Fallback zu Admin Demo
      }
      $st=$mysqli->prepare("INSERT INTO pendenzen
        (projekt_id, wohnung_id, ordner_id, fs_rel_path, titel, kurzbeschreibung, langbeschreibung, notiz, wichtigkeit, startdatum, enddatum, uhrzeit, tageszeit, status, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id, is_protocol, protocol_type)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $st->bind_param("iiiisssssissssssiisi",
        $projekt_id, $wohnung_id, $ordner_id, $fs_rel_path, $titel, $kurz, $lang, $notiz, $wichtigkeit, $startdatum, $enddatum, $uhrzeit, $tageszeit, $status, $erstellt_von, $sichtbarkeit, $assignee_can_edit, $zustaendig_id, $is_protocol, $protocol_type
      );
      $st->execute(); $pendenz_id=$st->insert_id; log_action($mysqli,'pendenz',$pendenz_id,'create',['title'=>$titel]);
    }

    // extra_json speichern
    $ext=[
      '_assignee_type'=>$assignee_type,
      '_assignee_team_id'=>($assignee_type==='team'?$assignee_team_id:null),
      '_assignee_company_id'=>($assignee_type==='company'?$assignee_company_id:null),
      '_sicht_ui'=>$sichtbarkeit_ui,
      '_sicht_team_id'=>$sicht_team_id,
      '_sicht_user_ids'=>$sicht_user_ids,
      'confirmation_required'=>$confirmation_required,
      'public_enabled'=>$public_enabled,
      'external_can_view'=>$external_can_view,
      'external_can_upload'=>$external_can_upload,
      'kategorie_id'=>$cat_id,
      'unterkategorie_id'=>$sub_id,
    ];
    $defs=fetch_field_defs($mysqli);
    if ($defs) while($fd=$defs->fetch_assoc()){
      $k=$fd['field_key']; $t=$fd['type']; $v=$_POST['field_'.$k]??null;
      if($t==='checkbox') $v=isset($_POST['field_'.$k])?1:0;
      if($v!=='' && $v!==null) $ext[$k]=$v;
    }
    $ext=array_filter($ext,fn($v)=>$v!==null);
    $jx=$ext?json_encode($ext,JSON_UNESCAPED_UNICODE):null;
    $sx=$mysqli->prepare("UPDATE pendenzen SET extra_json=? WHERE id=?");
    $sx->bind_param("si",$jx,$pendenz_id); $sx->execute(); $sx->close();

    // ACL (bei custom)
    if($sichtbarkeit==='custom'){
      $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$pendenz_id);
      $ins=$mysqli->prepare("INSERT INTO pendenz_acl (pendenz_id,benutzer_id,can_view,can_edit) VALUES (?,?,?,?)");
      if($sichtbarkeit_ui==='team' && $sicht_team_id){
        $q=$mysqli->prepare("SELECT bt.benutzer_id FROM benutzer_teams bt WHERE bt.team_id=?");
        $q->bind_param("i",$sicht_team_id); $q->execute(); $rs=$q->get_result();
        while($row=$rs->fetch_assoc()){ $uidX=(int)$row['benutzer_id']; $cv=1; $ce=$assignee_can_edit?1:0; $ins->bind_param("iiii",$pendenz_id,$uidX,$cv,$ce); $ins->execute(); }
        $q->close();
      } elseif(!empty($sicht_user_ids)){
        foreach($sicht_user_ids as $uidX){ $cv=1; $ce=$assignee_can_edit?1:0; $ins->bind_param("iiii",$pendenz_id,$uidX,$cv,$ce); $ins->execute(); }
      }
      $ins->close();
    }

    // Uploads: Bilder
    $coverAdded = false;
    if(!empty($_FILES['bilder']) && is_array($_FILES['bilder']['name'])){
      $n=count($_FILES['bilder']['name']);
      for($i=0;$i<$n;$i++){
        $_FILES['__img']=[
          'name'=>$_FILES['bilder']['name'][$i]??null,
          'type'=>$_FILES['bilder']['type'][$i]??null,
          'tmp_name'=>$_FILES['bilder']['tmp_name'][$i]??null,
          'error'=>$_FILES['bilder']['error'][$i]??UPLOAD_ERR_NO_FILE,
          'size'=>$_FILES['bilder']['size'][$i]??0
        ];
        try{
          $uidUp=(int)($_SESSION['user_id']??0);
          $u=handle_upload('__img','uploads/pendenzen_tmp',$uidUp,($titel?:'Pendenz'),
            ['image/jpeg','image/png','image/gif','image/webp'], 20_000_000, 6000, 4000);
          if(!$u) continue;
          $u=is_array($u)?$u:(['pfad'=>ltrim($u,'/'),'dateiname'=>basename($u)]);
          list($rel,$abs) = pendenz_fs_base($mysqli, $projekt_id, $ordner_id, $pendenz_id, $titel);
          $destDir = $abs.'/bilder'; if (!is_dir($destDir)) @mkdir($destDir,0775,true);

          $srcRel = ltrim($u['pfad'],'/'); $srcAbs = abs_path_from($srcRel); if(!$srcAbs || !is_file($srcAbs)) continue;
          $ext = pathinfo($u['dateiname'], PATHINFO_EXTENSION);
          $baseName = pathinfo($u['dateiname'], PATHINFO_FILENAME);
          $safeBase = slugify($baseName);
          $destName = $safeBase.'.'.$ext; $k2=1; while(file_exists($destDir.'/'.$destName)){ $destName=$safeBase.'-'.$k2.'.'.$ext; $k2++; }
          @rename($srcAbs, $destDir.'/'.$destName);
          $finalAbs = $destDir.'/'.$destName; $finalRel = $rel.'/bilder/'.$destName;

          $mime = @mime_content_type($finalAbs) ?: 'application/octet-stream';
          $size = filesize($finalAbs) ?: 0;
          if (function_exists('image_to_max_1mb')) { list($finalAbs, $mime, $size) = image_to_max_1mb($finalAbs,$mime); }

          $st2=$mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id,typ,pfad,mimetype,groesse,titel,is_cover,hochgeladen_von) VALUES (?,?,?,?,?,?,?,?)");
          $isCover = 0; $origTitle = $u['dateiname'] ?: basename($finalRel); $type='image'; $uidU=(int)($_SESSION['user_id']??0);
          $st2->bind_param("isssisis",$pendenz_id,$type,$finalRel,$mime,$size,$origTitle,$isCover,$uidU);
          $st2->execute(); $fid = $st2->insert_id; $st2->close();

          if (!$coverAdded) {
            $has=$mysqli->query("SELECT 1 FROM pendenz_dateien WHERE pendenz_id={$pendenz_id} AND is_cover=1 LIMIT 1");
            if (!$has || !$has->num_rows) {
              $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pendenz_id}");
              $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id=".$fid);
              $coverAdded = true;
            }
          }
          log_action($mysqli,'pendenz_datei',$fid,'upload',['file'=>$origTitle]);
        }catch(Throwable $ex){ $flash.=" ⚠️ ".$ex->getMessage(); }
      }
    }
    // Uploads: Anhänge
    if(!empty($_FILES['anhaenge']) && is_array($_FILES['anhaenge']['name'])){
      $n=count($_FILES['anhaenge']['name']);
      for($i=0;$i<$n;$i++){
        $_FILES['__file']=[
          'name'=>$_FILES['anhaenge']['name'][$i]??null,
          'type'=>$_FILES['anhaenge']['type'][$i]??null,
          'tmp_name'=>$_FILES['anhaenge']['tmp_name'][$i]??null,
          'error'=>$_FILES['anhaenge']['error'][$i]??UPLOAD_ERR_NO_FILE,
          'size'=>$_FILES['anhaenge']['size'][$i]??0
        ];
        try{
          $uidUp=(int)($_SESSION['user_id']??0);
          $u=handle_upload('__file','uploads/pendenzen_tmp',$uidUp,($titel?:'Pendenz'),[
            'application/pdf','application/zip','application/x-zip-compressed',
            'audio/mpeg','audio/mp3','audio/wav',
            'video/mp4','video/quicktime'
          ], 80_000_000, 0, 0);
          if(!$u) continue;
          $u=is_array($u)?$u:(['pfad'=>ltrim($u,'/'),'dateiname'=>basename($u)]);
          list($rel,$abs) = pendenz_fs_base($mysqli, $projekt_id, $ordner_id, $pendenz_id, $titel);
          $destDir = $abs.'/anhaenge'; if (!is_dir($destDir)) @mkdir($destDir,0775,true);

          $srcRel = ltrim($u['pfad'],'/'); $srcAbs = abs_path_from($srcRel); if(!$srcAbs || !is_file($srcAbs)) continue;
          $ext = pathinfo($u['dateiname'], PATHINFO_EXTENSION);
          $baseName = pathinfo($u['dateiname'], PATHINFO_FILENAME);
          $safeBase = slugify($baseName);
          $destName = $safeBase.'.'.$ext; $k3=1; while(file_exists($destDir.'/'.$destName)){ $destName=$safeBase.'-'.$k3.'.'.$ext; $k3++; }
          @rename($srcAbs, $destDir.'/'.$destName);
          $finalAbs = $destDir.'/'.$destName; $finalRel = $rel.'/anhaenge/'.$destName;

          $mime = @mime_content_type($finalAbs) ?: 'application/octet-stream';
          $size = filesize($finalAbs) ?: 0;
          $type = (str_starts_with($mime,'audio/'))?'audio':((str_starts_with($mime,'video/'))?'video':'file');

          $st2=$mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id,typ,pfad,mimetype,groesse,titel,is_cover,hochgeladen_von) VALUES (?,?,?,?,?,?,?,?)");
          $isCover = 0; $origTitle = $u['dateiname'] ?: basename($finalRel); $uidU=(int)($_SESSION['user_id']??0);
          $st2->bind_param("isssisis",$pendenz_id,$type,$finalRel,$mime,$size,$origTitle,$isCover,$uidU);
          $st2->execute(); $st2->close();

          log_action($mysqli,'pendenz_datei',$pendenz_id,'upload',['file'=>$origTitle]);
        }catch(Throwable $ex){ $flash.=" ⚠️ ".$ex->getMessage(); }
      }
    }

    // Remember last meta
    $_SESSION['pendenz_last'] = [
      'projekt_id'=>$projekt_id,
      'wohnung_id'=>$wohnung_id,
      'fs_rel_path'=>$fs_rel_path,
      'kategorie_id'=>$cat_id,
      'unterkategorie_id'=>$sub_id,
      'assignee_type'=>$assignee_type,
      'assignee_user_id'=>$assignee_user_id,
      'assignee_team_id'=>$assignee_team_id,
      'assignee_company_id'=>$assignee_company_id,
      'sichtbarkeit_ui'=>$sichtbarkeit_ui,
      'sicht_team_id'=>$sicht_team_id,
      'sicht_user_ids'=>$sicht_user_ids
    ];

    $flash=$flash ?: ($id>0 ? "✅ Pendenz aktualisiert." : "✅ Pendenz gespeichert.");

    if ($isAjaxInline) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'ok'   => true,
        'id'   => (int)$pendenz_id,
        'msg'  => ($id>0 ? 'Pendenz aktualisiert.' : 'Pendenz gespeichert.'),
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } catch (Throwable $e) { 
    $flash="❌ ".$e->getMessage(); 
    if (!empty($isAjaxInline)) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'error'=>strip_tags($flash)], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

/** -------- Delete (Datei-Serveraktionen) -------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && in_array($_POST['action'], ['delete_file','set_cover'], true)) {
  try{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $pendenz_id = (int)($_POST['pendenz_id'] ?? 0);
    $file_id = (int)($_POST['file_id'] ?? 0);
    if ($pendenz_id<=0 || $file_id<=0) throw new Exception('Parameter fehlen.');

    $r = $mysqli->query("SELECT * FROM pendenzen WHERE id={$pendenz_id}");
    $p = $r ? $r->fetch_assoc() : null;
    if (!$p || !can_edit_pendenz($mysqli, $p, $uid)) throw new Exception('Keine Berechtigung.');

    $rowR = $mysqli->query("SELECT * FROM pendenz_dateien WHERE id={$file_id} AND pendenz_id={$pendenz_id}");
    $row = $rowR ? $rowR->fetch_assoc() : null;
    if (!$row) throw new Exception('Datei nicht gefunden.');

    if ($_POST['action']==='delete_file') {
      $abs = __DIR__.'/../'.ltrim($row['pfad'],'/');
      if (is_file($abs)) @unlink($abs);
      $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id={$pendenz_id} AND id={$file_id}");
      log_action($mysqli,'pendenz_datei',$file_id,'delete',['file'=>$row['titel'] ?: $row['pfad']]);
      $flash = "🗑️ Datei gelöscht.";
    } else {
      if ($row['typ']!=='image') throw new Exception('Cover nur für Bilder.');
      $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pendenz_id}");
      $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id={$file_id}");
      log_action($mysqli,'pendenz_datei',$file_id,'set_cover');
      $flash = "🖼 Cover aktualisiert.";
    }
  } catch (Throwable $e) { $flash = "❌ ".$e->getMessage(); }
  header("Location: ".$PREFIX."pages/pendenzen.php?edit=".$pendenz_id);
  exit;
}

/** -------- Vorlagen -------- */
$hasTplTbl = table_exists($mysqli,'pendenz_vorlagen');
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save_template') {
  $name = trim($_POST['tpl_name'] ?? '');
  if ($name==='') { $flash='❌ Vorlagen-Name fehlt.'; }
  else {
    $data = [
      'projekt_id'=> (int)($_POST['projekt_id']??0),
      'fs_rel_path'=> trim((string)($_POST['fs_rel_path']??'')),
      'kategorie_id'=> (int)($_POST['kategorie_id']??0),
      'unterkategorie_id'=> (int)($_POST['unterkategorie_id']??0),
      'assignee_type'=> $_POST['assignee_type']??'user',
      'assignee_user_id'=> (int)($_POST['assignee_user_id']??0),
      'assignee_team_id'=> (int)($_POST['assignee_team_id']??0),
      'assignee_company_id'=> (int)($_POST['assignee_company_id']??0),
      'sichtbarkeit_ui'=> $_POST['sichtbarkeit_ui']??'projekt_all',
      'sicht_team_id'=> (int)($_POST['sicht_team_id']??0),
      'sicht_user_ids'=> array_filter(array_map('intval',$_POST['sicht_user_ids']??[]))
    ];
    if ($hasTplTbl) {
      $st=$mysqli->prepare("INSERT INTO pendenz_vorlagen (name,data_json,erstellt_von) VALUES (?,?,?)");
      $jx=json_encode($data,JSON_UNESCAPED_UNICODE); $uid=(int)($_SESSION['user_id']??0);
      $st->bind_param("ssi",$name,$jx,$uid); $st->execute(); $st->close();
    } else {
      $_SESSION['pendenz_templates'] = $_SESSION['pendenz_templates'] ?? [];
      $_SESSION['pendenz_templates'][] = ['id'=>time(),'name'=>$name,'data'=>$data];
    }
    $flash='✅ Vorlage gespeichert.';
  }
}
if (isset($_GET['action']) && $_GET['action']==='template_get') {
  header('Content-Type: application/json; charset=utf-8');
  $id=(int)($_GET['id']??0);
  $out=null;
  if ($hasTplTbl) {
    $st=$mysqli->prepare("SELECT data_json FROM pendenz_vorlagen WHERE id=?");
    $st->bind_param("i",$id); $st->execute();
    $res=$st->get_result(); $jx=$res?($res->fetch_column()):null; $st->close();
    if ($jx) $out=json_decode($jx,true);
  } else {
    foreach (($_SESSION['pendenz_templates'] ?? []) as $t) if ((int)$t['id']===$id) $out=$t['data'];
  }
  echo json_encode(['ok'=>(bool)$out,'data'=>$out]); exit;
}

/** -------- Filter & Liste vorbereiten -------- */
$GET = filter_input_array(INPUT_GET, [
  'cols' => ['filter'=>FILTER_UNSAFE_RAW,'flags'=>FILTER_REQUIRE_ARRAY],
  'q' => FILTER_UNSAFE_RAW,
  'sort' => FILTER_UNSAFE_RAW,
  'dir' => FILTER_UNSAFE_RAW,
  'f' => ['filter'=>FILTER_UNSAFE_RAW,'flags'=>FILTER_REQUIRE_ARRAY],
  'list_id' => FILTER_SANITIZE_NUMBER_INT,
  'projekt_id' => FILTER_SANITIZE_NUMBER_INT,
]) ?? [];

$q = trim((string)($_GET['q'] ?? ''));
$f_params = is_array($_GET['f'] ?? null) ? $_GET['f'] : [];
$dir  = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$selectedPid = (int)($_GET['projekt_id'] ?? 0);
$listenId    = (int)($_GET['list_id'] ?? 0);
if ($listenId <= 0 && !empty($_GET['listen_id'])) $listenId = (int)$_GET['listen_id']; // Fallback

// NEU: Falls keine Liste gewählt wurde, prüfen ob es eine Standard-Ansicht gibt
if ($listenId <= 0 && !isset($_GET['cols'])) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    // Fehlerunterdrückung (@), falls die Datenbank noch nicht synchronisiert ist (is_default Spalte)
    $resDef = @$mysqli->query("SELECT id FROM listen WHERE table_name='pendenzen' AND owner_id=$uid AND is_default=1 LIMIT 1");
    if ($resDef && $rowDef = $resDef->fetch_assoc()) {
        $listenId = (int)$rowDef['id'];
    }
}

// Spalten & Meta-Checks
$hasPk = table_exists($mysqli,'pendenz_kategorien');
$hasPs = table_exists($mysqli,'pendenz_subkategorien');
$hasSortIndex = column_exists($mysqli, 'pendenzen', 'sort_index');

// Sort Mapping
$sortMap = [
  'titel'=>'p.titel','projekt_name'=>'pr.name','status'=>'p.status','wichtigkeit'=>'p.wichtigkeit',
  'startdatum'=>'p.startdatum','enddatum'=>'p.enddatum','erstellt_am'=>'p.erstellt_am',
  'bilder'=>'bilder_count','anhaenge'=>'anhaenge_count','erstes_bild'=>'erstes_bild','fs_rel_path'=>'p.fs_rel_path'
];
if ($hasPk) $sortMap['kategorie_name']='pk.name';
if ($hasPs) $sortMap['unterkategorie_name']='ps.name';

$sortKey = (string)($GET['sort'] ?? ($hasSortIndex ? 'custom' : 'erstellt_am'));
$orderSql = ($sortMap[$sortKey] ?? 'p.erstellt_am').' '.strtoupper($dir);

// Permissions & Where
// (Redundanter SQL-Block entfernt – PendenzenService wird unten genutzt)


// Labels & Columns (Expanded for SaaS compat)
$labels = [
  'id'=>'ID','erstes_bild'=>'Bild','cover'=>'Cover','titel'=>'Titel','projekt_name'=>'Projekt','status'=>'Status',
  'wichtigkeit'=>'Prio', 'startdatum'=>'Start', 'enddatum'=>'Fällig', 'fs_rel_path'=>'Ordner',
  'wohnung_name'=>'Einheit / Mietsache', 'kategorie_name'=>'Kategorie', 'unterkategorie_name'=>'Sub',
  'erstellt_am'=>'Erstellt', 'geaendert_am'=>'Update', 'bilder'=>'📸', 'anhaenge'=>'📎', 'balance'=>'Saldo'
];
$defaultCols = ['erstes_bild', 'titel', 'status', 'enddatum', 'kategorie_name', 'bilder', 'anhaenge', 'balance'];

// 1. Verfügbare Listen (Layer) laden für den Selector
$availableLayers = [];
$resL = $mysqli->query("SELECT id, name FROM listen WHERE table_name='pendenzen' ORDER BY name ASC");
if($resL) while($l = $resL->fetch_assoc()) $availableLayers[] = $l;

// 2. Initial-Handling: Was ist aktiv?
$selectedCols = (isset($_GET['cols']) && is_array($_GET['cols'])) ? $_GET['cols'] : ($_SESSION['pendenzen_cols'] ?? $defaultCols);
if (!is_array($selectedCols) || empty($selectedCols)) $selectedCols = $defaultCols;
$selectedCols = array_values(array_filter($selectedCols, 'is_string'));

// 3. Wenn Profil (Layer) gewählt: Spalten & Filter aus DB laden
if ($listenId > 0) {
    if ($stP = $mysqli->prepare("SELECT name, filters_json FROM listen WHERE id=?")) {
        $stP->bind_param("i", $listenId);
        $stP->execute();
        $resP = $stP->get_result()->fetch_assoc();
        $stP->close();

        if ($resP) {
            // Spalten für diesen Layer laden
            $stC = $mysqli->prepare("SELECT col_name FROM listen_spalten WHERE listen_id=? ORDER BY sort_order ASC, id ASC");
            if ($stC) {
                $stC->bind_param("i", $listenId);
                $stC->execute();
                $resC = $stC->get_result();
                $layerCols = [];
                while($rc=$resC->fetch_assoc()) $layerCols[] = $rc['col_name'];
                $stC->close();
                if (!empty($layerCols)) $selectedCols = $layerCols;
            }

            // Filter anwenden (Layer-Werte haben Priorität wenn URL-Werte leer sind)
            $fJson = json_decode((string)$resP['filters_json'], true);
            if ($fJson) {
                if (!$selectedPid && !empty($fJson['projekt_id'])) $selectedPid = (int)$fJson['projekt_id'];
                if (empty($_GET['status']) && !empty($fJson['status'])) $_GET['status'] = $fJson['status'];
                if (empty($_GET['q'])      && !empty($fJson['q']))      $_GET['q']      = (string)$fJson['q'];
                if (empty($_GET['wohnung_id']) && !empty($fJson['wohnung_id'])) $_GET['wohnung_id'] = (int)$fJson['wohnung_id'];
                if (empty($_GET['kategorie_id']) && !empty($fJson['kategorie_id'])) $_GET['kategorie_id'] = (int)$fJson['kategorie_id'];
            }
        }
    }
}

$selectedStatus = $_GET['status'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$wohnungId = (int)($_GET['wohnung_id'] ?? 0);
$objektId  = (int)($_GET['objekt_id'] ?? 0);
$kategorieId = (int)($_GET['kategorie_id'] ?? 0);
$_SESSION['pendenzen_cols'] = $selectedCols;

// 4. Finalisierung: Labels & Spalten-Mapping
foreach($selectedCols as $sc) if(!isset($labels[$sc])) $labels[$sc] = ucwords(str_replace('_',' ',$sc));
$cols = $selectedCols;

// Liste der Projekte für Dropdowns (Single Source)
$projects = [];
$resProj = $mysqli->query("SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name");
if($resProj) while($p = $resProj->fetch_assoc()) $projects[] = $p;


// Spaltenbreiten
$colWidths = $_SESSION['pendenzen_col_widths'] ?? [];

// Helper für Breiten
if(!function_exists('css_width')){ function css_width(?string $w){ return $w ? ' style="width:'.$w.';"' : ''; } }

// === Standard Helper ===
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('qs')) {
  function qs(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k=>$v) { if ($v === null || $v === '') unset($q[$k]); }
    return '?' . http_build_query($q);
  }
}
if (!function_exists('th_sort_link')) {
  function th_sort_link(string $orderKey, string $label): string {
    $curOrder = (string)($_GET['order'] ?? ($_GET['sort'] ?? 'eigene'));
    $curDir   = strtolower((string)($_GET['dir'] ?? 'desc'));
    $isActive = ($curOrder === $orderKey);
    $nextDir  = $isActive ? ($curDir === 'asc' ? 'desc' : 'asc') : 'asc';
    $arrow    = $isActive ? ($curDir === 'asc' ? ' ▲' : ' ▼') : '';
    $href     = qs(['order'=>$orderKey, 'dir'=>$nextDir, 'offset'=>0]);
    return '<a href="'.h($href).'">'.h($label).$arrow.'</a>';
  }
}

// === Modularisierung: Autoloader + Pendenzen-Service ===
require_once __DIR__ . '/../app/core/autoload.php';
use App\Modules\Pendenzen\Service as PendenzenService;

// DB-Handle bestimmen ($mysqli kommt üblich aus config.php)
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} elseif (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_errno) { die('DB-Verbindung fehlgeschlagen: '.$db->connect_error); }
} else {
    die('Kein DB-Handle gefunden. Bitte $mysqli in config.php bereitstellen.');
}

// Module instanziieren
$pendenzen = new PendenzenService($db);

$filters = [
  'projekt_id' => $selectedPid ?: null,
  'listen_id'  => $listenId ?: null,
  'status'     => $selectedStatus ?: null,
  'objekt_id'  => $objektId ?: null,
  'wohnung_id' => $wohnungId ?: null,
  'kategorie_id' => $kategorieId ?: null,
  'q'          => $q,
  'order'      => (string)($_GET['order'] ?? 'eigene'),
  'dir'        => (string)($_GET['dir'] ?? 'desc'),
  'limit'      => (int)($_GET['limit'] ?? 100),
  'offset'     => (int)($_GET['offset'] ?? 0),
];

// Aktuelle Pendenzen für die Liste
$rsPendenzen = $pendenzen->searchResult($filters);





/** -------- Ausgabe -------- */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

// Defaults aus Session (nur für „Neue Pendenz“)
$last = $_SESSION['pendenz_last'] ?? [];
$editPendenz=null;
if (isset($_GET['edit'])) {
  $eid=(int)$_GET['edit']; $r=$mysqli->query("SELECT * FROM pendenzen WHERE id={$eid}");
  $tmp=$r?$r->fetch_assoc():null;
  if($tmp && can_view_pendenz($mysqli,$tmp,(int)($_SESSION['user_id']??0))) $editPendenz=$tmp;
}
?>
<link rel="stylesheet" href="<?= asset_url('smarttable.css') ?>">
<style>
/* Seite auf volle Bildschirmbreite */
*, *::before, *::after { box-sizing: border-box; }
body { background: #f1f5f9; color: #1e293b; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
.container-fluid { padding: 2rem; max-width: 98%; margin: 0 1%; }

/* Dashboard Header */
.hero-teal { 
  background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); 
  padding: 24px 32px; 
  box-shadow: 0 10px 25px -5px rgba(14, 165, 233, 0.3);
  color: #fff;
}
.btn-head { background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.3); color: #fff; transition: all 0.3s; }
.btn-head:hover { background: rgba(255, 255, 255, 0.3); transform: translateY(-2px); }

/* Smart Card Design */
.smart-card { 
  background: #fff; 
  border-radius: 16px; 
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.03); 
  border: 1px solid #e2e8f0; 
  padding: 24px; 
  margin-bottom: 24px;
}
.smart-card h2 { 
  font-size: 1.1rem; 
  font-weight: 700; 
  color: #0f172a; 
  margin: 0 0 20px 0; 
  display: flex; 
  align-items: center; 
  gap: 12px; 
  border-bottom: 1px solid #f1f5f9; 
  padding-bottom: 12px; 
}

/* Inputs hübscher & Formular smarter */
.form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:24px; align-items: start; }
.form-grid label { display:flex; flex-direction:column; gap:8px; font-size:13px; font-weight:600; color:#475569; }
.form-grid input, .form-grid select, .form-grid textarea {
  padding:12px 14px; border:1px solid #cbd5e1; border-radius:10px; background:#fff; outline:none; font-size:14px; color:#1e293b; transition: all 0.2s;
}
.form-grid input:focus, .form-grid select:focus, .form-grid textarea:focus { border-color: #0ea5e9; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1); }

/* Tabelle */
.table-container { 
  background: #fff; 
  border-radius: 16px; 
  box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); 
  border: 1px solid #e2e8f0; 
  overflow-x: auto; 
  margin-top: 24px;
}
.table { width:100%; border-collapse:collapse; min-width:1200px; }
.table th { 
  background: #f8fafc; 
  padding: 16px; 
  font-size: 12px; 
  font-weight: 700; 
  color: #64748b; 
  text-transform: uppercase; 
  letter-spacing: 0.05em; 
  border-bottom: 2px solid #f1f5f9;
}
.table td { padding: 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.table tbody tr:hover { background: #f8fafc; }

/* Status Chips */
.status-chip {
  padding: 6px 14px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  display: inline-block;
}
.status-chip:hover { filter: brightness(0.95); transform: scale(1.05); }

/* Prio Stars */
.star-rating { color: #cbd5e1; transition: all 0.2s; }
.star-rating span { 
  padding: 4px; 
  display: inline-block; 
  transition: transform 0.2s; 
}
.star-rating span:hover { transform: scale(1.3); color: #f59e0b !important; }

/* Action Buttons */
.btn-xxs {
  width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
  border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; transition: all 0.2s; text-decoration: none;
}
.btn-xxs:hover { background: #f8fafc; border-color: #cbd5e1; transform: translateY(-1px); }
.btn-danger-light { color: #ef4444; }
.btn-danger-light:hover { background: #fee2e2; border-color: #fecaca; }

/* Inline New Row */
#inlineNewRow input, #inlineNewRow select {
  font-size: 13px; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0;
}
</style>

<div class="container-fluid">

  <header class="hero hero-teal" style="display:flex;gap:8px;justify-content:space-between;align-items:center; border-radius: 12px; margin-bottom: 24px;">
    <h1 style="margin:0;">Pendenzen Dashboard</h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <select onchange="location.href='pendenzen.php?list_id='+this.value" style="background:#0284c7; color:#fff; border:1px solid rgba(255,255,255,0.2); border-radius:8px; padding:8px 12px; font-weight:600; cursor:pointer; outline:none; box-shadow:0 0 10px rgba(0,0,0,0.1);">
        <option value="">— Arbeitsbereich / Layer wählen —</option>
        <?php foreach($availableLayers as $layer): ?>
           <option value="<?= (int)$layer['id'] ?>" <?= $listenId==$layer['id']?'selected':'' ?>><?= h($layer['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-head" type="button" onclick="toggleForm()" id="btnToggleForm">➕ Neue Pendenz</button>
      <a class="btn btn-head" href="listen_settings.php">⚙️ Ansichten konfigurieren</a>
      <a class="btn" href="pendenzen_liste.php">📄 Exporte</a>
    </div>
  </header>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= h($flash) ?></div>
  <?php endif; ?>


  <div id="formSection" style="<?= $editPendenz ? '' : 'display:none;' ?>">
    <!-- Schnellstarts -->
    <div class="smart-card" style="background:#f0f9ff; border-color:#bae6fd; margin-bottom: 20px;">
      <h2 style="color:#0369a1; font-size:16px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg> Schnellstarts & Vorlagen</h2>
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:15px;">
        <form method="post" style="display:flex; flex-direction:column; gap:8px;">
           <input type="hidden" name="action" value="save_template">
           <div style="display:flex; gap:6px;">
             <input type="text" name="tpl_name" placeholder="Vorlage Name..." style="flex:1; padding:8px;">
             <button class="btn btn-teal btn-small">Speichern</button>
           </div>
        </form>
        <div style="display:flex; gap:6px; align-items:center;">
           <select id="tpl_select" style="flex:1; padding:8px;">
             <option value="">— Vorlage wählen —</option>
             <?php
                if (table_exists($mysqli, 'pendenz_vorlagen')) {
                  $rsTpl = $mysqli->query("SELECT id, name FROM pendenz_vorlagen ORDER BY name ASC");
                  if($rsTpl) while($t = $rsTpl->fetch_assoc()): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                  <?php endwhile;
                } ?>
           </select>
           <button class="btn btn-small" type="button" id="tpl_apply">Anwenden</button>
        </div>
      </div>
    </div>


  <form method="post" enctype="multipart/form-data" id="pendenz-form">
    <?php if($editPendenz): ?><input type="hidden" name="id" value="<?= (int)$editPendenz['id'] ?>"><?php endif; ?>

    <?php
      // Initial-Werte & Data Fetching
      $pidInit   = (int)($editPendenz['projekt_id'] ?? ($last['projekt_id'] ?? ($_GET['projekt_id'] ?? 0)));
      $widInit   = (int)($editPendenz['wohnung_id'] ?? ($last['wohnung_id'] ?? 0));
      $fsRelEdit = (string)($editPendenz['fs_rel_path'] ?? '');
      $fsRelGet  = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';
      $fsRelInit = $fsRelEdit !== '' ? $fsRelEdit : ($last['fs_rel_path'] ?? $fsRelGet);

      $extra=json_decode($editPendenz['extra_json']??'null',true)?:[];
      $cat_id = (int)($extra['kategorie_id'] ?? ($last['kategorie_id'] ?? 0));
      $sub_id = (int)($extra['unterkategorie_id'] ?? ($last['unterkategorie_id'] ?? 0));
      
      $assType   = $extra['_assignee_type']      ?? ($last['assignee_type'] ?? 'user');
      $assTeam   = (int)($extra['_assignee_team_id'] ?? ($last['assignee_team_id'] ?? 0));
      $assUser   = (int)($editPendenz['zustaendig_id'] ?? ($last['assignee_user_id'] ?? 0));
      $assCompany= (int)($extra['_assignee_company_id'] ?? ($last['assignee_company_id'] ?? 0));
      
      $sichtUI   = $extra['_sicht_ui'] ?? ($last['sichtbarkeit_ui'] ?? 'projekt_all');
      $sichtTeam = (int)($extra['_sicht_team_id'] ?? ($last['sicht_team_id'] ?? 0));
      $sichtUsers= array_map('intval', $extra['_sicht_user_ids'] ?? ($last['sicht_user_ids'] ?? []));
      $public_enabled      = (int)($extra['public_enabled'] ?? 0);
      $external_can_view   = (int)($extra['external_can_view'] ?? 0);
      $external_can_upload = (int)($extra['external_can_upload'] ?? 0);
      $confirmation_required = (int)($extra['confirmation_required'] ?? 0);

      // Kategorien/Subkategorien initial (Server)
      $cats=[]; $subsByCat=[];
      if (table_exists($mysqli,'pendenz_kategorien')) {
        $qcat = "SELECT id,name FROM pendenz_kategorien WHERE projekt_id IS NULL OR projekt_id = $pidInit ORDER BY name";
        $rc=$mysqli->query($qcat);
        if($rc) while($x=$rc->fetch_assoc()) $cats[]=$x;
        if (table_exists($mysqli,'pendenz_subkategorien')) {
          $rs=$mysqli->query("SELECT id,kategorie_id,name FROM pendenz_subkategorien ORDER BY name");
          if($rs) while($x=$rs->fetch_assoc()){
            $kid=(int)$x['kategorie_id']; $subsByCat[$kid] = $subsByCat[$kid] ?? []; $subsByCat[$kid][]=['id'=>(int)$x['id'],'name'=>$x['name']];
          }
        }
      }
    ?>

    <div class="form-grid">
      <!-- Sektion 1: Basisdaten -->
      <div class="smart-card">
        <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg> Basisdaten</h2>
        <div style="display:grid; gap:16px;">
          <label>Projekt*
            <select name="projekt_id" id="projekt_id" required>
              <option value="">— wählen —</option>
              <?php $proj=fetch_projekte($mysqli); while($r=$proj->fetch_assoc()):
                $sel = ((int)$r['id']===$pidInit)?'selected':''; ?>
                <option value="<?= (int)$r['id'] ?>" <?= $sel ?> data-path="<?= h($r['name']) ?>"><?= h($r['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </label>

          <label>Objekt
            <select name="objekt_id" id="objekt_id">
              <option value="">— wählen —</option>
              <?php
                if ($pidInit) {
                  $rsO = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=$pidInit ORDER BY name");
                  if($rsO) while($o = $rsO->fetch_assoc()) {
                    $sel = ((int)$o['id']===$objektId)?'selected':''; ?>
                    <option value="<?= (int)$o['id'] ?>" data-path="<?= h($o['name']) ?>" <?= $sel ?>><?= h($o['name']) ?></option>
                  <?php }
                }
              ?>
            </select>
          </label>

          <label>Einheit / Mietsache
            <select name="wohnung_id" id="wohnung_id">
              <option value="">— keine —</option>
              <?php
                if ($pidInit) {
                  $sqW = "SELECT w.id, w.name, o.name as objekt_name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$pidInit";
                  if ($objektId > 0) $sqW .= " AND w.objekt_id=$objektId";
                  $sqW .= " ORDER BY o.name, w.name";
                  $rsW = $mysqli->query($sqW);
                  if($rsW) while($w = $rsW->fetch_assoc()) {
                    $sel = ((int)$w['id']===$widInit)?'selected':''; ?>
                    <option value="<?= (int)$w['id'] ?>" data-path="<?= h($w['name']) ?>" <?= $sel ?>><?= h($w['name']) ?> (<?= h($w['objekt_name']) ?>)</option>
                  <?php }
                }
              ?>
            </select>
          </label>

          <label title="Wird automatisch anhand der Auswahl oben gesetzt">Zugehöriger Ordner (Pfad)
            <input type="text" name="fs_rel_path" id="fs_rel_path" value="<?= h($fsRelInit) ?>" readonly style="background:#f1f5f9; color:#1e293b; font-weight:700; border:1px solid #cbd5e1;">
            <span style="font-size:11px; color:#64748b; font-weight:400; margin-top:2px;">Das perfekte System: Pfad orientiert sich automatisch an Projekt / Objekt / Wohnung.</span>
          </label>
        </div>
      </div>

      <!-- Sektion 2: Details & Beschreibung -->
      <div class="smart-card">
        <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Beschreibung</h2>
        <div style="display:grid; gap:16px;">
          <label>Titel*<input type="text" name="titel" value="<?= h($editPendenz['titel']??"") ?>" required placeholder="Kurzer aussagekräftiger Titel…"></label>
          <label>Kurzbeschreibung<input type="text" name="kurzbeschreibung" value="<?= h($editPendenz['kurzbeschreibung'] ?? '') ?>" placeholder="Zusammenfassung…"></label>
          <label>Inhalt / Details<textarea name="langbeschreibung" rows="5" placeholder="Genaue Details…"><?= h($editPendenz['langbeschreibung'] ?? '') ?></textarea></label>
          <label>Interne Notiz<textarea name="notiz" rows="2" style="background:#fffcf0;" placeholder="Nur intern…"><?= h($editPendenz['notiz'] ?? '') ?></textarea></label>
        </div>
      </div>

      <!-- Sektion 3: Status & Metadata -->
      <div class="smart-card">
        <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Status & Termine</h2>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
          <label>Status
            <select name="status">
              <?php $cur = $editPendenz['status'] ?? 'offen'; $statusOpts=['offen'=>'offen','in Bearbeitung'=>'in Bearbeitung','erledigt'=>'erledigt','archiviert'=>'archiviert']; ?>
              <?php foreach($statusOpts as $v=>$lbl): ?><option value="<?= h($v) ?>" <?= $v===$cur?'selected':''; ?>><?= h($lbl) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label>Priorität (0-5)<input type="number" min="0" max="5" name="wichtigkeit" value="<?= h((string)($editPendenz['wichtigkeit']??"0")) ?>"></label>
          <label>Startdatum<input type="date" name="startdatum" value="<?= h($editPendenz['startdatum']??"") ?>"></label>
          <label>Fällig am<input type="date" name="enddatum" value="<?= h($editPendenz['enddatum']??"") ?>"></label>
          
          <label>Präzise Uhrzeit<input type="time" name="uhrzeit" value="<?= h($editPendenz['uhrzeit']??"") ?>"></label>
          <label>Tagesabschnitt
            <select name="tageszeit">
              <option value="">— egal —</option>
              <?php $tz=$editPendenz['tageszeit']??""; foreach(['Vormittag','Mittag','Nachmittag','Abend','Nacht'] as $opt): ?>
                <option value="<?= h($opt) ?>" <?= $tz===$opt?'selected':'' ?>><?= h($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          
          <fieldset class="form-row-full">
            <legend>Protokoll</legend>
            <label style="flex-direction:row; gap:10px; align-items:center;"><input type="checkbox" name="is_protocol" value="1" <?= (int)($editPendenz['is_protocol']??0)===1?'checked':'' ?>> Als Protokoll markieren</label>
            <select name="protocol_type" style="margin-top:10px;">
              <?php $ptArr=['none'=>'Kein Protokoll','abnahme'=>'Abnahme','uebergabe'=>'Übergabe','besichtigung'=>'Besichtigung']; $ptCur=$editPendenz['protocol_type']??'none'; foreach($ptArr as $kv=>$kopt): ?>
                <option value="<?= h($kv) ?>" <?= $kv===$ptCur?'selected':'' ?>><?= h($kopt) ?></option>
              <?php endforeach; ?>
            </select>
          </fieldset>
        </div>
      </div>

      <!-- Sektion 4: Kategorien -->
      <div class="smart-card">
        <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg> Kategorisierung</h2>
        <div style="display:grid; gap:16px;">
          <label>Haupt-Kategorie
            <select name="kategorie_id" id="kategorie_id">
              <option value="">— wählen —</option>
              <?php foreach($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id']===$cat_id)?'selected':''; ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Unter-Kategorie
            <select name="unterkategorie_id" id="unterkategorie_id" <?= empty($cat_id) ? 'disabled' : '' ?>>
              <option value=""><?= empty($cat_id) ? '— zuerst Kategorie wählen —' : '— wählen —' ?></option>
              <?php if(!empty($cat_id) && isset($subsByCat[$cat_id])): foreach($subsByCat[$cat_id] as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$sub_id)?'selected':''; ?>><?= h($s['name']) ?></option>
              <?php endforeach; endif; ?>
            </select>
          </label>
        </div>
      </div>

      <!-- Sektion 5: Zuständig & Sichtbar -->
      <div class="smart-card">
        <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg> Zuständigkeit & Sichtbarkeit</h2>
        <div style="display:grid; gap:16px;">
          <fieldset>
            <legend>Zuweisung</legend>
            <div style="display:flex;gap:12px;margin-bottom:12px; font-size:12px;">
              <label style="flex-direction:row; gap:4px;"><input type="radio" name="assignee_type" value="user" <?= $assType==='user'?'checked':'' ?>> User</label>
              <label style="flex-direction:row; gap:4px;"><input type="radio" name="assignee_type" value="team" <?= $assType==='team'?'checked':'' ?>> Team</label>
              <label style="flex-direction:row; gap:4px;"><input type="radio" name="assignee_type" value="company" <?= $assType==='company'?'checked':'' ?>> Firma</label>
            </div>
            <select name="assignee_user_id" id="assignee_user_id" style="<?= $assType!=='user'?'display:none;':'' ?>">
              <option value="">— User wählen —</option>
              <?php if($pidInit){ $mem=fetch_members_by_project($mysqli,$pidInit); while($m=$mem->fetch_assoc()): ?>
                <option value="<?= (int)$m['id'] ?>" <?= ((int)$m['id']===$assUser)?'selected':''; ?>><?= h($m['name']) ?></option>
              <?php endwhile; } ?>
            </select>
            <select name="assignee_team_id" id="assignee_team_id" style="<?= $assType!=='team'?'display:none;':'' ?>">
              <option value="">— Team wählen —</option>
              <?php if($pidInit){ $tms=fetch_teams_by_project($mysqli,$pidInit); while($t=$tms->fetch_assoc()): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id']===$assTeam)?'selected':''; ?>><?= h($t['name']) ?></option>
              <?php endwhile; } ?>
            </select>
            <select name="assignee_company_id" id="assignee_company_id" style="<?= $assType!=='company'?'display:none;':'' ?>">
              <option value="">— Firma wählen —</option>
              <?php if($pidInit){ $cos=fetch_firmen_by_project($mysqli,$pidInit); foreach($cos as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id']===$assCompany)?'selected':''; ?>><?= h($c['name']) ?></option>
              <?php endforeach; } ?>
            </select>
          </fieldset>

          <fieldset>
            <legend>Sichtbarkeit</legend>
            <select name="sichtbarkeit_ui">
              <option value="projekt_all" <?= $sichtUI==='projekt_all'?'selected':'' ?>>Alle (Projekt)</option>
              <option value="team" <?= $sichtUI==='team'?'selected':'' ?>>Team</option>
              <option value="users" <?= $sichtUI==='users'?'selected':'' ?>>User-Liste</option>
              <option value="privat" <?= $sichtUI==='privat'?'selected':'' ?>>Privat</option>
            </select>
            <div id="extra_sicht" style="margin-top:10px; <?= ($sichtUI!=='team' && $sichtUI!=='users')?'display:none;':'' ?>">
              <select name="sicht_team_id" id="sicht_team_id" style="<?= $sichtUI!=='team'?'display:none;':'' ?>">
                <?php if($pidInit){ $tms=fetch_teams_by_project($mysqli,$pidInit); while($t=$tms->fetch_assoc()): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id']===$sichtTeam)?'selected':''; ?>><?= h($t['name']) ?></option>
                <?php endwhile; } ?>
              </select>
              <select name="sicht_user_ids[]" id="sicht_user_ids" multiple size="4" style="<?= $sichtUI!=='users'?'display:none;':'' ?> width:100%;">
                <?php if($pidInit){ $mem=fetch_members_by_project($mysqli,$pidInit); while($m=$mem->fetch_assoc()): ?>
                  <option value="<?= (int)$m['id'] ?>" <?= in_array((int)$m['id'],$sichtUsers,true)?'selected':'' ?>><?= h($m['name']) ?></option>
                <?php endwhile; } ?>
              </select>
            </div>
          </fieldset>
        </div>
      </div>

      <!-- Sektion 6: Medien -->
      <div class="smart-card">
        <h2><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg> Medien & Extern</h2>
        <div style="display:grid; gap:16px;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
            <label>Bilder<input type="file" name="bilder[]" multiple accept="image/*"></label>
            <label>Anhänge<input type="file" name="anhaenge[]" multiple accept=".pdf,.zip,audio/*,video/*"></label>
          </div>
          <?php if($editPendenz): ?>
            <div style="margin-top:8px;">
               <div id="mediaImages" class="media-grid" style="grid-template-columns:repeat(auto-fill,minmax(80px,1fr));"></div>
               <div id="mediaFiles" class="media-grid" style="margin-top:8px;"></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div style="display:flex; justify-content:center; gap:16px; margin-top:30px;">
      <button class="btn btn-teal" style="padding:16px 60px; font-size:18px; font-weight:800; background:#1abc9c; border-radius:14px; border:none; color:#fff; box-shadow:0 12px 20px -5px rgba(26,188,156,0.3);">Speichern & Beenden</button>
      <a class="btn" style="padding:16px 20px; font-size:16px; border-radius:14px; background:#fff; border:1px solid #cbd5e1;" href="pendenzen.php">Abbrechen</a>
    </div>
  </form>


  </div> <!-- #formSection Ende -->
<?php
// aktuelle Order/Dir
$orderParam = (string)($_GET['order'] ?? ($_GET['sort'] ?? 'eigene'));
$dirParam   = (string)($_GET['dir'] ?? 'desc');
?>

  <div class="sdash-wrap" data-csrf="dummy">
    <div class="sdash-savebar" style="display:none; position:fixed; top:20px; right:20px; z-index:9999; padding:10px 20px; border-radius:8px; color:#fff; font-weight:600; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);">
      <span class="sdash-save-status"></span>
    </div>

    <!-- Suche & Filter -->
    <div class="smart-card" style="margin-bottom:20px; background:#f8fafc;">
    <form method="get" id="filterForm" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; width:100%;">
      
      <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:220px;">
        <select name="list_id" onchange="this.form.submit()" style="background:#e0f2fe; border-color:#7dd3fc; color:#0369a1; font-weight:700; flex:1; height:44px; border-radius:8px;">
          <option value="">— Standard-Ansicht —</option>
          <?php foreach($availableLayers as $layer): ?>
             <option value="<?= (int)$layer['id'] ?>" <?= $listenId==$layer['id']?'selected':'' ?>><?= h($layer['name']) ?> (Layer)</option>
          <?php endforeach; ?>
        </select>
        <a href="listen_settings.php" class="btn btn-outline" style="padding:0 12px; height:44px; display:flex; align-items:center; justify-content:center; border-color:#7dd3fc; background:#f0f9ff; color:#0369a1; font-weight:bold; text-decoration:none; border-radius:8px;" title="Neue Liste erstellen">+ Neu</a>
      </div>

      <select name="projekt_id" id="filter_projekt_id" onchange="this.form.submit()" style="flex:1; min-width:180px; height:44px; font-weight:600; border-color:#94a3b8;">
        <option value="">— Projekt wählen —</option>
        <?php foreach($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id']===$selectedPid?'selected':'' ?> data-path="<?= h($p['name']) ?>"><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="objekt_id" id="filter_objekt_id" onchange="this.form.submit()" style="flex:1; min-width:180px; height:44px;">
        <option value="">— Alle Objekte —</option>
        <?php if($selectedPid):
                $objs = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=$selectedPid ORDER BY name");
                if($objs) while($o = $objs->fetch_assoc()): ?>
          <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id']===(int)($_GET['objekt_id']??0)?'selected':'' ?> data-path="<?= h($o['name']) ?>"><?= h($o['name']) ?></option>
        <?php endwhile; endif; ?>
      </select>

      <select name="wohnung_id" id="filter_wohnung_id" onchange="this.form.submit()" class="wohnung-select" style="flex:1; min-width:180px; height:44px;">
        <option value="" data-path="">— Alle Einheiten —</option>
        <?php if($selectedPid):
                $sqF = "SELECT w.id, w.name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$selectedPid";
                $selOid = (int)($_GET['objekt_id']??0);
                if ($selOid > 0) $sqF .= " AND w.objekt_id=$selOid";
                $sqF .= " ORDER BY w.name";
                $whg = $mysqli->query($sqF);
                if($whg) while($w = $whg->fetch_assoc()): 
                  $pP = slugify($w['name'] ?: 'einheit'); ?>
          <option value="<?= (int)$w['id'] ?>" data-path="<?= h($pP) ?>" <?= (int)$w['id']===(int)($_GET['wohnung_id']??0)?'selected':'' ?>><?= h($w['name']) ?></option>
        <?php endwhile; endif; ?>
      </select>

      <select name="status" onchange="this.form.submit()" style="width:140px; height:44px;">
        <option value="">— Status —</option>
        <?php foreach(['offen','in Bearbeitung','erledigt','archiviert','wartend'] as $st): ?>
          <option value="<?= h($st) ?>" <?= $selectedStatus===$st?'selected':'' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="kategorie_id" onchange="this.form.submit()" style="width:160px; height:44px;">
        <option value="">— Kategorie —</option>
        <?php 
          $kats = $mysqli->query("SELECT id, name FROM pendenz_kategorien ORDER BY name");
          if($kats) while($k = $kats->fetch_assoc()): ?>
          <option value="<?= (int)$k['id'] ?>" <?= (int)$k['id']===(int)($_GET['kategorie_id']??0)?'selected':'' ?>><?= h($k['name']) ?></option>
        <?php endwhile; ?>
      </select>

      <input type="text" name="q" id="q" placeholder="Suche..." value="<?= h($q) ?>" style="flex:1.5; min-width:200px; height:44px;">

      <button type="submit" class="btn btn-primary" style="background:#0ea5e9; border:none; padding:10px 24px; font-weight:700; height:44px;">Filtern</button>
      <a href="pendenzen.php" class="btn btn-outline" style="padding:10px 16px; height:44px; display:flex; align-items:center;">Reset</a>
      
      <?php if ($listenId > 0 || $selectedPid > 0): ?>
        <a href="pendenzen_liste.php?export=pdf&<?= http_build_query($_GET) ?>" target="_blank" class="btn secondary" style="padding:10px 20px; height:44px; display:flex; align-items:center; gap:8px; background:#f1f5f9; border-color:#cbd5e1; color:#334155; font-weight:700;">
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
          PDF Liste
        </a>
      <?php endif; ?>
    </form>
  </div>

  <div class="table-container">
    <table class="table" id="pendenzenTable" data-search="#q">
      <thead>
        <tr>
          <?php foreach ($cols as $c):
            $thStyle = css_width($colWidths[$c] ?? '');
            
            // Map column names to DB fields for SmartTable
            $field = $c;
            if($c==='kategorie_name') $field='kategorie_id';
            elseif($c==='unterkategorie_name') $field='unterkategorie_id';
            elseif($c==='projekt_name') $field='projekt_id';
            elseif($c==='wohnung_name') $field='wohnung_id';
            elseif(in_array($c, ['bilder','anhaenge','erstes_bild','cover'])) $field='';

            // Editor types
            $type = 'text';
            $optsAttr = '';
            if($c === 'enddatum' || $c === 'startdatum') $type = 'date';
            if($c === 'wichtigkeit') $type = 'number';
            if($c === 'status') {
              $type = 'select';
              $optsAttr = ' data-options=\'["offen","in Bearbeitung","erledigt","archiviert","wartend"]\'';
            }
          ?>
            <th class="sort"<?= $thStyle ?> data-field="<?= h($field) ?>" data-type="<?= h($type) ?>"<?= $optsAttr ?>>
              <?= h($labels[$c] ?? $c) ?>
            </th>
          <?php endforeach; ?>
          <th class="action-col" data-nosort>Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rsPendenzen || $rsPendenzen->num_rows === 0): ?>
          <tr><td colspan="<?= count($cols) + 1 ?>" style="padding:40px; text-align:center; color:#64748b;">Keine Pendenzen in dieser Ansicht.</td></tr>
        <?php else: while ($row = $rsPendenzen->fetch_assoc()): ?>
          <tr data-id="<?= (int)$row['id'] ?>">
            <?php foreach ($cols as $c): 
              $isEditable = !in_array($c, ['erstes_bild','cover','bilder','anhaenge','balance', 'id']);
              $fieldType = 'text';
              if (str_contains($c, 'datum')) $fieldType = 'date';
              if ($c === 'uhrzeit') $fieldType = 'time';
              if ($c === 'tageszeit') $fieldType = 'select';
            ?>
              <td class="<?= $isEditable ? 'inline-editable' : '' ?>" 
                  data-field="<?= h($c) ?>" 
                  data-type="<?= h($fieldType) ?>"
                  data-id="<?= (int)$row['id'] ?>">
                <?php
                  switch($c) {
                    case 'erstes_bild':
                    case 'cover':
                      $p = trim((string)$row['erstes_bild']);
                      $src = $p ? (str_starts_with($p,'http') ? $p : $PREFIX . ltrim($p,'/')) : '';
                      echo $src ? '<img class="thumb" src="'.h($src).'" alt="img" style="width:60px; height:45px; object-fit:cover; border-radius:6px;">' : '—';
                      break;
                    case 'status':
                      $st = $row['status'];
                      $bg = ($st==='offen'?'#fee2e2':($st==='erledigt'?'#dcfce7':'#dbeafe'));
                      $fg = ($st==='offen'?'#991b1b':($st==='erledigt'?'#166534':'#1e40af'));
                      echo '<span class="status-chip" onclick="cycleStatus('.(int)$row['id'].',\''.h($st).'\')" style="background:'.$bg.'; color:'.$fg.'; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; cursor:pointer;">'.h($st).'</span>';
                      break;
                    case 'wichtigkeit':
                      $prio = (int)$row['wichtigkeit'];
                      echo '<div class="star-rating" data-id="'.(int)$row['id'].'" style="cursor:pointer; display:flex; gap:2px; font-size:16px;">';
                      for($i=1;$i<=5;$i++) {
                        $clr = $i <= $prio ? '#f59e0b' : '#cbd5e1';
                        echo '<span onclick="updatePrio('.(int)$row['id'].','.$i.')" title="Setze Prio '.$i.'" style="color:'.$clr.'; transition:transform .1s;">★</span>';
                      }
                      echo '</div>';
                      break;
                    case 'titel':
                      echo '<div class="titel-cell" style="display:flex; flex-direction:column;">';
                      echo '<a href="pendenz_show.php?id='.(int)$row['id'].'" style="font-weight:700; color:#0f172a; text-decoration:none;">'.h($row['titel']).'</a>';
                      if (!empty($row['kurzbeschreibung'])) echo '<small style="color:#64748b; font-size:11px;">'.h($row['kurzbeschreibung']).'</small>';
                      echo '</div>';
                      break;
                    case 'startdatum':
                    case 'enddatum':
                      $d = $row[$c];
                      if (!$d) { echo '—'; break; }
                      echo '<div style="display:flex; flex-direction:column; line-height:1.2;">';
                      echo '<span>' . date('d.m.Y', strtotime($d)) . '</span>';
                      
                      $time = trim((string)($row['uhrzeit'] ?? ''));
                      $tz   = trim((string)($row['tageszeit'] ?? ''));
                      
                      if ($time || $tz) {
                        echo '<div style="font-size:11px; color:#64748b; font-weight:600; display:flex; gap:4px; align-items:center;">';
                        if ($time) echo '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> ' . substr($time, 0, 5);
                        if ($time && $tz) echo ' • ';
                        if ($tz) echo h($tz);
                        echo '</div>';
                      }
                      echo '</div>';
                      break;
                    case 'bilder': echo (int)$row['bilder_count']; break;
                    case 'anhaenge': echo (int)$row['anhaenge_count']; break;
                    case 'balance':
                      $bal = (float)($row['balance'] ?? 0);
                      $wid = (int)($row['wohnung_id'] ?? 0);
                      $urlF = "finanzen.php?wohnung_id=" . $wid;
                      echo '<a href="' . $urlF . '" style="text-decoration:none;">';
                      if ($bal > 0) {
                        echo '<span style="color:#ef4444; font-weight:700;">' . number_format($bal, 2, '.', '\'') . '</span>';
                      } elseif ($bal < 0) {
                        echo '<span style="color:#22c55e; font-weight:700;">' . number_format(abs($bal), 2, '.', '\'') . ' <small>(Guthaben)</small></span>';
                      } else {
                        echo '<span style="color:#64748b;">0.00</span>';
                      }
                      echo '</a>';
                      break;
                    default:
                      if (str_starts_with($c, 'json:')) {
                        $fld = str_replace('json:', '', $c);
                        $jx = json_decode((string)($row['extra_json'] ?? '[]'), true) ?: [];
                        echo h($jx[$fld] ?? '—');
                      } else {
                        echo h($row[$c] ?? '—');
                      }
                  }
                ?>
              </td>
            <?php endforeach; ?>
            <td class="action-col">
              <a class="btn btn-xxs" href="pendenzen.php?edit=<?= (int)$row['id'] ?>" title="Bearbeiten">✏️</a>
              <a class="btn btn-xxs btn-danger-light" href="pendenzen.php?delete=<?= (int)$row['id'] ?>" onclick="return confirm('Wirklich löschen?')" title="Löschen">🗑️</a>
            </td>
          </tr>
        <?php endwhile; endif; ?>
        
        <style>
          .inline-editable { cursor: pointer; transition: background 0.2s; position: relative; }
          .inline-editable:hover { background: #f0f9ff !important; box-shadow: inset 0 0 0 1px #0ea5e9; }
          .inline-editor { width: 100%; border: 2px solid #0ea5e9; border-radius: 4px; padding: 4px; font-size: 13px; outline: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
          .inline-saving { opacity: 0.5; pointer-events: none; }
        </style>

        <!-- Inline-Erfassung (nur einmal am Ende) -->
        <tr id="inlineNewRow" style="background:#f8fafc; border-top:2px solid #e2e8f0;">
          <?php foreach ($cols as $c): ?>
            <td><?= inline_input_for_col($c, $labels, []) ?></td>
          <?php endforeach; ?>
          <td>
             <div style="display:flex; gap:5px; align-items:center;">
               <select id="inline_project_id" style="max-width:120px; font-size:12px;" onchange="updateProjectContext(this.value, true)">
                 <option value="">Projekt…</option>
                 <?php foreach($projects as $p): ?>
                   <option value="<?= (int)$p['id'] ?>" <?= $p['id']==$selectedPid?'selected':'' ?>><?= h($p['name']) ?></option>
                 <?php endforeach; ?>
               </select>
               <button type="button" class="btn btn-teal btn-xxs" id="inlineCreateBtn">Speichern</button>
             </div>
             <div id="inlineMsg" style="font-size:11px; margin-top:4px;"></div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

<?php
// --- Inline-Erfassungszeile: zeigt nur Felder der sichtbaren Spalten ($cols)
function inline_input_for_col($c, $labels, $imgHeights) {
  switch ($c) {

    case 'titel':
      return '<input type="text" name="titel" placeholder="Titel*" required>';

    case 'projekt_name':
    case 'Projekt':
      return '<i>(Nutzt Projekt-Auswahl rechts)</i>';

    case 'status':
      return '<select name="status" style="width:100%; border-radius:8px;"><option value="offen">offen</option><option value="in Bearbeitung">in Bearbeitung</option><option value="erledigt">erledigt</option><option value="wartend">wartend</option></select>';

    case 'wichtigkeit':
      return '<input type="number" name="wichtigkeit" min="0" max="5" placeholder="Prio 0-5" style="width:100%; border-radius:8px;">';

    case 'startdatum':
      return '<input type="date" name="startdatum" style="width:100%; border-radius:8px;">';

    case 'enddatum':
      return '<input type="date" name="enddatum" style="width:100%; border-radius:8px;">';

    case 'fs_rel_path':
      return '<input type="text" name="fs_rel_path" placeholder="Ordner-Pfad" style="width:100%; border-radius:8px; font-family:monospace; font-size:11px;">';

    case 'kategorie_name':
    case 'kategorie_id':
      return '<select name="kategorie_id" class="kategorie-select" style="width:100%; border-radius:8px;"><option value="">Kategorie…</option></select>';

    case 'wohnung_name':
    case 'wohnung_id':
      return '<select name="wohnung_id" class="wohnung-select" style="width:100%; border-radius:8px;"><option value="">Einheit / Mietsache…</option></select>';

    case 'bilder':
      return '<input type="file" name="bilder[]" multiple accept="image/*" style="width:100%;">';

    case 'anhaenge':
      return '<input type="file" name="anhaenge[]" multiple accept=".pdf,.zip,application/pdf,application/zip,audio/*,video/*" style="width:100%;">';

    case 'cover':
    case 'erstes_bild':
      return '&nbsp;';

    default:
      return '<input type="text" name="'.h($c).'" placeholder="'.h($labels[$c] ?? $c).'" style="width:100%;">';
  }
}
?>


<!-- Inline Section removed from here as it is now inside Tbody -->
	  
	  
	  
	  
	  
    </div>
	<?php
  // dieselben $limit/$offset wie oben verwenden
  $limit  = (int)($_GET['limit']  ?? 50);
  $offset = (int)($_GET['offset'] ?? 0);
  if ($limit <= 0) $limit = 50;
  $prev = max(0, $offset - $limit);
  $next = $offset + $limit;
?>
<div class="pager" style="display:flex;gap:10px;justify-content:center;margin:10px 0;">
  <a class="btn" href="<?= qs(['offset'=>$prev,'limit'=>$limit]) ?>">« Zurück</a>
  <a class="btn" href="<?= qs(['offset'=>$next,'limit'=>$limit]) ?>">Weiter »</a>
</div>

  </div>
</div>

<!-- Ordnerauswahl-Modal -->
<div class="modal" id="folderModal" role="dialog" aria-modal="true" aria-labelledby="folderModalTitle">
  <div class="box">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 id="folderModalTitle" style="margin:0;">Ordner im Projekt wählen</h3>
      <button type="button" id="folderClose" class="iconchip">Schliessen ✖</button>
    </div>
    <div id="folderTree"></div>
  </div>
</div>

  </div> <!-- .sdash-wrap Ende -->
</div> <!-- .container-fluid Ende -->

<script>
// -------- AJAX: Status-Zyklus (Ein-Klick-Update)
async function cycleStatus(id, current) {
  const stati = ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'];
  let idx = stati.indexOf(current);
  if (idx === -1) idx = 0;
  const next = stati[(idx + 1) % stati.length];

  const chip = event.currentTarget;
  const oldText = chip.textContent;
  chip.textContent = '…';
  
  try {
    const res = await fetch('api_smarttable.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_cell', table: 'pendenzen', id: id, field: 'status', value: next })
    });
    const js = await res.json();
    if (!js.ok) throw new Error(js.error);
    
    // UI Update ohne Reload
    chip.textContent = next;
    const bg = (next==='offen'?'#fee2e2':(next==='erledigt'?'#dcfce7':'#dbeafe'));
    const fg = (next==='offen'?'#991b1b':(next==='erledigt'?'#166534':'#1e40af'));
    chip.style.background = bg;
    chip.style.color = fg;
    // Update current for next click
    chip.setAttribute('onclick', `cycleStatus(${id},'${next}')`);
  } catch(e) {
    alert('Fehler: ' + e.message);
    chip.textContent = oldText;
  }
}

// -------- AJAX: Prio-Sterne (Ein-Klick-Update)
async function updatePrio(id, val) {
  try {
    const res = await fetch('api_smarttable.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_cell', table: 'pendenzen', id: id, field: 'wichtigkeit', value: val })
    });
    const js = await res.json();
    if (!js.ok) throw new Error(js.error);

    // UI Update: Alle Sterne in dieser Zeile färben
    const container = document.querySelector(`.star-rating[data-id="${id}"]`);
    if (container) {
      const stars = container.querySelectorAll('span');
      stars.forEach((s, idx) => {
        s.style.color = (idx < val) ? '#f59e0b' : '#cbd5e1';
      });
    }
  } catch(e) {
    alert('Fehler: ' + e.message);
  }
}

// -------- AUTO-SYNC: Wohnung -> Path
document.addEventListener('change', e => {
  // Wohnung -> Ordnerpfad Sync (Automatisierung für dich)
  if (e.target.name === 'wohnung_id' || e.target.classList.contains('wohnung-select')) {
    const opt = e.target.options[e.target.selectedIndex];
    const path = opt?.dataset?.path;
    if (path) {
      const container = e.target.closest('form') || e.target.closest('tr') || document.getElementById('formSection');
      const pathInput = container.querySelector('[name="fs_rel_path"]');
      const pathDisplay = container.querySelector('#fs_rel_path_display');
      if (pathInput) {
        pathInput.value = path;
        if (pathDisplay) pathDisplay.value = path;
        
        pathInput.classList.add('bg-blue-50');
        setTimeout(() => pathInput.classList.remove('bg-blue-50'), 500);
        
        if (e.target.closest('td') && !pathInput.readOnly) {
           pathInput.dispatchEvent(new Event('change', {bubbles:true}));
        }
      }
    }
  }
});

// -------- UI: Formular ein/ausblenden
function toggleForm() {
  const f = document.getElementById('formSection');
  const b = document.getElementById('btnToggleForm');
  if (!f || !b) return;
  if (f.style.display === 'none') {
    f.style.display = 'block';
    b.textContent = '✖ Formular schließen';
    f.scrollIntoView({ behavior: 'smooth' });
  } else {
    f.style.display = 'none';
    b.textContent = '➕ Neue Pendenz';
  }
}

// -------- Projekt-Wechsel: Kontext (Mitglieder, Teams, Firmen, Wohnungen)
async function updateProjectContext(pid, onlyInline = false, isFilter = false) {
  if (!pid) return;
  try {
    const res = await fetch('pendenzen.php?action=context&projekt_id=' + pid);
    const js = await res.json();
    if (!js.ok) return;

    // Alle Wohnungs-Selects finden
    const prefix = isFilter ? 'filter_' : '';
    const wSelId = isFilter ? 'filter_wohnung_id' : (onlyInline ? '' : 'wohnung_id');
    const selectors = onlyInline ? ['.wohnung-select'] : (isFilter ? ['#filter_wohnung_id'] : ['#wohnung_id']);
    
    selectors.forEach(selName => {
      const sels = document.querySelectorAll(selName);
      sels.forEach(sel => {
        const curVal = sel.value;
        sel.innerHTML = isFilter ? '<option value="">— Alle Einheiten —</option>' : '<option value="" data-path="">— keine / alle —</option>';
        (js.apartments || []).forEach(a => {
          const opt = document.createElement('option');
          opt.value = a.id;
          opt.textContent = a.name + (a.objekt_name && !isFilter ? ' (' + a.objekt_name + ')' : '');
          opt.dataset.path = a.name; 
          if (String(a.id) === String(curVal)) opt.selected = true;
          sel.appendChild(opt);
        });
      });
    });

    // Objekte-Select aktualisieren
    const objSelId = isFilter ? 'filter_objekt_id' : 'objekt_id';
    const objSel = document.getElementById(objSelId);
    if (objSel) {
        const curVal = objSel.value;
        objSel.innerHTML = isFilter ? '<option value="">— Alle Objekte —</option>' : '<option value="">— wählen —</option>';
        (js.objects || []).forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.id;
            opt.textContent = o.name;
            opt.dataset.path = o.name;
            if (String(o.id) === String(curVal)) opt.selected = true;
            objSel.appendChild(opt);
        });

        // UX-Optimierung: Wenn nur 1 Objekt vorhanden ist (und noch keins gewählt), automatisch wählen
        if ((js.objects || []).length === 1 && (!objSel.value || objSel.value === "")) {
            objSel.value = js.objects[0].id;
            // Wohnungsauswahl triggern
            updateApartmentsForObject(pid, objSel.value, isFilter ? 'filter_wohnung_id' : 'wohnung_id');
        }

        if (!objSel.dataset.hasListener) {
            objSel.dataset.hasListener = 'true';
            objSel.addEventListener('change', () => {
                const pidVal = document.getElementById(isFilter ? 'filter_projekt_id' : 'projekt_id')?.value;
                const oidVal = objSel.value;
                updateApartmentsForObject(pidVal, oidVal, isFilter ? 'filter_wohnung_id' : 'wohnung_id');
            });
        }
    }

    if (!isFilter) syncFolderPath();
    if (onlyInline || isFilter) return;

    // Zuständige aktualisieren (nur für Hauptformular)
    const userSel = document.getElementById('assignee_user_id');
    if (userSel) {
      userSel.innerHTML = '<option value="">— (optional) —</option>';
      (js.members || []).forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = m.name + ' (' + m.email + ')';
        userSel.appendChild(opt);
      });
    }

    const teamSel = document.getElementById('assignee_team_id');
    if (teamSel) {
      teamSel.innerHTML = '<option value="">— (optional) —</option>';
      (js.teams || []).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.name;
        teamSel.appendChild(opt);
      });
    }

    // Kategorien-Selects finden & füllen
    const katSelectors = ['.kategorie-select']; // nur Inline vorerst, da Hauptformular durch PHP kommt
    katSelectors.forEach(selName => {
      document.querySelectorAll(selName).forEach(sel => {
        const curVal = sel.value;
        sel.innerHTML = '<option value="">Kategorie…</option>';
        (js.categories || []).forEach(k => {
          const opt = document.createElement('option');
          opt.value = k.id;
          opt.textContent = k.name;
          if (String(k.id) === String(curVal)) opt.selected = true;
          sel.appendChild(opt);
        });
      });
    });
  } catch (err) { console.error(err); }
}

async function updateApartmentsForObject(pid, oid, targetId = 'wohnung_id') {
    if (!pid) return;
    try {
        const res = await fetch(`pendenzen.php?action=context&projekt_id=${pid}&objekt_id=${oid}`);
        const js = await res.json();
        if (!js.ok) return;

        const wSel = document.getElementById(targetId);
        if (wSel) {
            const curVal = wSel.value;
            const isFilter = targetId.startsWith('filter_');
            wSel.innerHTML = isFilter ? '<option value="">— Alle Einheiten —</option>' : '<option value="" data-path="">— keine / alle —</option>';
            (js.apartments || []).forEach(a => {
                const opt = document.createElement('option');
                opt.value = a.id;
                opt.textContent = a.name;
                opt.dataset.path = a.name;
                if (String(a.id) === String(curVal)) opt.selected = true;
                wSel.appendChild(opt);
            });
        }
        if (!targetId.startsWith('filter_')) syncFolderPath();
    } catch(err) { console.error(err); }
}

document.getElementById('projekt_id')?.addEventListener('change', (e) => updateProjectContext(e.target.value));
document.getElementById('filter_projekt_id')?.addEventListener('change', (e) => {
    // Falls man Auto-Filter ohne Submit möchte, müsste hier submit() hin.
    // Aber wir nutzen updateProjectContext für die Dropdowns.
    updateProjectContext(e.target.value, false, true);
});

// Let initial selected project trigger initial context
window.addEventListener('DOMContentLoaded', () => {
    const pid = document.getElementById('projekt_id')?.value;
    const oid = document.getElementById('objekt_id')?.value;
    if (pid) {
      if (oid) updateApartmentsForObject(pid, oid);
      else updateProjectContext(pid);
    }
    const fpid = document.getElementById('filter_projekt_id')?.value;
    const foid = document.getElementById('filter_objekt_id')?.value;
    if (fpid) {
      if (foid) updateApartmentsForObject(fpid, foid, 'filter_wohnung_id');
      else updateProjectContext(fpid, false, true);
    }
    
    const ipid = document.getElementById('inline_project_id')?.value;
    if (ipid) updateProjectContext(ipid, true);
});

// Pfad-Synchronisierung für das perfekte System
function syncFolderPath() {
  const pSel = document.getElementById('projekt_id');
  const oSel = document.getElementById('objekt_id');
  const wSel = document.getElementById('wohnung_id');
  const fsIn = document.getElementById('fs_rel_path');
  if (!fsIn) return;

  let path = '';
  const pPath = pSel?.options[pSel.selectedIndex]?.dataset.path || '';
  const oPath = oSel?.options[oSel.selectedIndex]?.dataset.path || '';
  const wPath = wSel?.options[wSel.selectedIndex]?.dataset.path || '';

  if (oPath) path = oPath;
  if (wPath) path += '/10_Mietsache/' + wPath;

  fsIn.value = path;
}

document.getElementById('projekt_id')?.addEventListener('change', syncFolderPath);
document.getElementById('objekt_id')?.addEventListener('change', syncFolderPath);
document.getElementById('wohnung_id')?.addEventListener('change', syncFolderPath);

// (Alte Logik durch syncFolderPath ersetzt)


// -------- Vorlagen anwenden
document.getElementById('tpl_apply')?.addEventListener('click', async () => {
  const id = document.getElementById('tpl_select')?.value || '';
  if (!id) return;
  const res = await fetch('pendenzen.php?action=template_get&id=' + encodeURIComponent(id));
  const js = await res.json();
  if (!js.ok) return;
  const d = js.data || {};

  const setVal = (sel,val)=>{ const el=document.querySelector(sel); if(el){ el.value = (val ?? ''); el.dispatchEvent(new Event('change')); } };
  setVal('select[name="projekt_id"]', d.projekt_id || '');
  setVal('#fs_rel_path', d.fs_rel_path || '');
  setVal('#kategorie_id', d.kategorie_id || '');
  setVal('#unterkategorie_id', d.unterkategorie_id || '');

  const assType = d.assignee_type || 'user';
  const r = document.querySelector('input[name="assignee_type"][value="'+assType+'"]'); if (r) r.checked = true;
  setVal('#assignee_user_id', d.assignee_user_id || '');
  setVal('#assignee_team_id', d.assignee_team_id || '');
  setVal('#assignee_company_id', d.assignee_company_id || '');

  const sicht = d.sichtbarkeit_ui || 'projekt_all';
  const r2 = document.querySelector('input[name="sichtbarkeit_ui"][value="'+sicht+'"]'); if (r2) r2.checked = true;
  setVal('#sicht_team_id', d.sicht_team_id || '');

  const multi = document.getElementById('sicht_user_ids');
  if (multi) {
    const ids = (d.sicht_user_ids || []).map(x=>String(x));
    for (const opt of multi.options) opt.selected = ids.includes(opt.value);
  }
});

// -------- Spalten-Auswahl: Col-Order Payload
const colOrderForm = document.querySelector('form[method="get"]');
const colOrderZone = document.getElementById('col-order-zone');
function currentColsOrder(){
  return Array.from(colOrderZone.querySelectorAll('.col-item')).map(x=>x.getAttribute('data-col'));
}
colOrderForm?.addEventListener('submit', () => {
  const payload = currentColsOrder().join(',');
  document.getElementById('cols_order_payload').value = payload;
});
// Drag innerhalb Spalten-UI
function enableDnd(container){
  let dragEl=null;
  container.addEventListener('dragstart', e=>{
    const t=e.target.closest('.col-item'); if(!t) return;
    dragEl=t; t.classList.add('dragging');
    e.dataTransfer.effectAllowed='move';
  });
  container.addEventListener('dragend', e=>{
    const t=e.target.closest('.col-item'); if(!t) return;
    t.classList.remove('dragging');
    dragEl=null;
  });
  container.addEventListener('dragover', e=>{
    e.preventDefault();
    const after = Array.from(container.querySelectorAll('.col-item:not(.dragging)')).find(el=>{
      const box=el.getBoundingClientRect();
      return e.clientY < box.top + box.height/2;
    });
    const dragging = container.querySelector('.col-item.dragging');
    if (!dragging) return;
    if (after) container.insertBefore(dragging, after); else container.appendChild(dragging);
  });
}
enableDnd(colOrderZone);

// -------- Tabellen-Reihenfolge Drag&Drop
const hasSortIndex = <?= $hasSortIndex ? 'true' : 'false' ?>;
if (hasSortIndex) {
  const toggle = document.getElementById('reorderToggle');
  const saveBtn = document.getElementById('reorderSave');
  const hint = document.getElementById('reorderHint');
  const tbody = document.querySelector('#pendenzenTable tbody');

  let dragRow = null;

  function setReorderMode(on){
    for (const tr of tbody.querySelectorAll('tr')) {
      tr.draggable = !!on;
      tr.style.cursor = on ? 'grab' : '';
    }
    saveBtn.style.display = on ? '' : 'none';
    hint.style.display = on ? '' : 'none';
  }

  function enableRowDnd() {
    tbody.addEventListener('dragstart', e=>{
      const tr = e.target.closest('tr'); if(!tr) return;
      dragRow = tr; tr.classList.add('dragging');
      e.dataTransfer.effectAllowed='move';
    });
    tbody.addEventListener('dragend', e=>{
      const tr = e.target.closest('tr'); if(!tr) return;
      tr.classList.remove('dragging'); dragRow=null;
      for (const x of tbody.querySelectorAll('tr')) x.classList.remove('drop-target');
    });
    tbody.addEventListener('dragover', e=>{
      if(!dragRow) return; e.preventDefault();
      const tr = e.target.closest('tr');
      if(!tr || tr===dragRow) return;
      tr.classList.add('drop-target');
      const box = tr.getBoundingClientRect();
      const before = (e.clientY < box.top + box.height/2);
      tr.classList.remove('drop-target');
      if (before) tbody.insertBefore(dragRow, tr); else tbody.insertBefore(dragRow, tr.nextSibling);
    });
  }
  enableRowDnd();

  toggle?.addEventListener('change', e=> setReorderMode(!!toggle.checked));

  saveBtn?.addEventListener('click', async ()=>{
    const ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(tr=>tr.getAttribute('data-id'));
    const fd = new FormData();
    ids.forEach(id=>fd.append('ids[]', id));
    const res = await fetch('pendenzen.php?action=reorder', { method:'POST', body: fd });
    const js = await res.json().catch(()=>({ok:false}));
    if (js && js.ok) {
      saveBtn.textContent = 'Gespeichert ✅';
      setTimeout(()=>{ saveBtn.textContent='Reihenfolge speichern'; }, 1500);
    } else {
      alert(js.error || 'Fehler beim Speichern der Reihenfolge.');
    }
  });
}

// -------- Medienlisten nachladen (falls im Edit)
(async ()=>{
  const id = <?= isset($editPendenz) ? (int)$editPendenz['id'] : 0 ?>;
  if (id>0) {
    const load = async (type, intoId)=>{
      const res = await fetch('pendenzen.php?action=media_list&id='+id);
      const js = await res.json();
      const into = document.getElementById(intoId);
      if (!into || !js.ok) return;
      const items = js.items || [];
      const filtered = items.filter(x => type==='image' ? x.typ==='image' : x.typ!=='image');
      into.innerHTML = filtered.map(x=>{
        const isImg = x.typ==='image';
        const src = isImg ? '<?= h($ASSET) ?>' + x.pfad.replace(/^\//,'') : '';
        return `
          <div class="media-tile">
            ${isImg ? `<img class="media-thumb" src="${src}" alt="">` : `<div class="media-title">📎 ${x.titel||x.pfad}</div>`}
            <div class="media-actions">
              ${isImg ? `<form method="post"><input type="hidden" name="action" value="set_cover"><input type="hidden" name="pendenz_id" value="${id}"><input type="hidden" name="file_id" value="${x.id}"><button class="btn btn-small" title="Als Cover setzen">Cover</button></form>` : ``}
              <form method="post" onsubmit="return confirm('Datei wirklich löschen?');">
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="pendenz_id" value="${id}">
                <input type="hidden" name="file_id" value="${x.id}">
                <button class="btn btn-danger btn-small">Löschen</button>
              </form>
            </div>
          </div>`;
      }).join('');
    };
    await load('file','mediaFiles');
    await load('image','mediaImages');
  }
})();

// --- Scrollposition erhalten (GET-Formulare + Links) ---
(function(){
  // Beim Laden ggf. zur alten Position springen
  const p = new URL(location.href).searchParams.get('scroll');
  if (p && +p > 0) { window.scrollTo(0, +p); }

  // allen GET-Formularen scroll anhängen
  document.querySelectorAll('form[method="get"]').forEach(f=>{
    f.addEventListener('submit', ()=>{
      let hid = f.querySelector('input[name="scroll"]');
      if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.name='scroll'; f.appendChild(hid); }
      hid.value = String(window.scrollY);
    });
  });

  // Links zu pendenzen.php so umschreiben, dass scroll=… mitkommt
  function withScroll(href){
    try{
      const u = new URL(href, location.href);
      if (!/pendenzen\.php/i.test(u.pathname)) return href;
      u.searchParams.set('scroll', String(window.scrollY));
      return u.toString();
    }catch(e){ return href; }
  }
  document.querySelectorAll('a[href]').forEach(a=>{
    a.addEventListener('click', (e)=>{
      const href = a.getAttribute('href') || '';
      if (!href || a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
      if (!/pendenzen\.php/i.test(href)) return;
      e.preventDefault();
      location.href = withScroll(a.href || href);
    });
  });

  // Komfort: Breite der Aktionen-Spalte auto-submitten
  document.querySelector('th.actions-th input[name="w[__actions]"]')
    ?.addEventListener('change', function(){ this.form.requestSubmit(); });
})();

// ---------- Full Inline Editing
(function(){
  console.log('[SmartTable] Inline Edit initialized');
  document.addEventListener('mousedown', async (e) => {
    const td = e.target.closest('.inline-editable');
    if (!td) return;
    if (td.querySelector('.inline-editor')) return;
    
    // Links/Buttons/Stars/Chips ignorieren wir, da diese eigene Logik haben
    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('.star-rating') || e.target.closest('.status-chip')) {
        return;
    }

    const field = td.dataset.field;
    const id    = td.dataset.id;
    let type    = td.dataset.type || 'text';
    const oldVal = td.dataset.value || td.textContent.trim();

    if (field === 'kurzbeschreibung' || field === 'langbeschreibung' || field === 'notiz') {
        type = 'textarea';
    }

    let input;
    if (type === 'select' || field === 'status') {
        input = document.createElement('select');
        let opts = [];
        if (field === 'status') {
             opts = ['offen','in Bearbeitung','erledigt','archiviert','wartend'];
        } else if (field === 'tageszeit') {
             opts = ['', 'Vormittag','Mittag','Nachmittag','Abend','Nacht'];
        }
        
        opts.forEach(opt => {
            const o = document.createElement('option'); o.value = opt; o.textContent = opt || '—';
            if (opt === oldVal) o.selected = true;
            input.appendChild(o);
        });
    } else if (type === 'textarea') {
        input = document.createElement('textarea');
        input.value = td.textContent.trim();
        input.rows = 3;
        input.style.width = '100%';
        input.style.resize = 'vertical';
    } else {
        input = document.createElement('input');
        input.type = type;
        input.value = (type === 'date' || type === 'time') ? oldVal : td.textContent.trim();
    }

    input.className = 'inline-editor';
    const originalContent = td.innerHTML;
    
    const finish = async () => {
        const newVal = input.value;
        if (newVal === oldVal) {
            td.innerHTML = originalContent;
            return;
        }

        td.classList.add('inline-saving');
        try {
            const res = await fetch('api_smarttable.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'update_cell', table: 'pendenzen', id, field, value: newVal })
            });
            const js = await res.json();
            if (!js.ok) throw new Error(js.error);
            location.reload();
        } catch(err) {
            alert('Fehler: ' + err.message);
            td.innerHTML = originalContent;
            td.classList.remove('inline-saving');
        }
    };

    input.addEventListener('blur', finish);
    if (input.tagName === 'SELECT') {
        input.addEventListener('change', finish);
    }
    input.addEventListener('keydown', e => { 
        if (e.key === 'Enter' && type !== 'textarea') { e.preventDefault(); input.blur(); } 
        if (e.key === 'Escape') { td.innerHTML = originalContent; }
    });

    td.innerHTML = '';
    td.appendChild(input);
    input.focus();
  });
})();

// ---------- Inline-Create (AJAX)
(function(){
  const btn  = document.getElementById('inlineCreateBtn');
  const row  = document.getElementById('inlineNewRow');
  const msg  = document.getElementById('inlineMsg');
  const proj = document.getElementById('inline_project_id');

  if (!btn || !row) return;

  function visibleInputs() {
    // Nur Felder einsammeln, die tatsächlich in der Inline-Zeile sichtbar sind
    return row.querySelectorAll('input, select, textarea');
  }

  btn.addEventListener('click', async () => {
    msg.textContent = '';

    // Pflichtfelder
    const titel = row.querySelector('input[name="titel"]')?.value.trim() || '';
    let projektId = proj?.value || '';
    if (!titel) { msg.textContent = '❌ Titel ist erforderlich.'; return; }
    if (!projektId) { msg.textContent = '❌ Projekt wählen.'; return; }

    const fd = new FormData();
    fd.set('inline_new', '1');            // signalisiert AJAX-JSON
    fd.set('projekt_id', projektId);
    fd.set('status', 'offen');            // Default, falls kein Status-Feld sichtbar

    for (const el of visibleInputs()) {
      if (!el.name) continue;
      if (el.type === 'file') {
        for (const f of el.files || []) fd.append(el.name, f);
      } else if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) fd.append(el.name, el.value || '1');
      } else {
        const v = (el.value ?? '').trim();
        if (v !== '') fd.append(el.name, v);
      }
    }

    // Scroll-Position mitschicken (für reload)
    const scrollY = window.scrollY || 0;

    btn.disabled = true;
    btn.textContent = 'Speichere…';

    try {
      const res = await fetch('pendenzen.php', { method: 'POST', body: fd, headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' }});
      const js  = await res.json().catch(()=>({ok:false,error:'Unerwartete Antwort'}));
      if (!js.ok) throw new Error(js.error || 'Fehler');

      // Erfolg → Zeile leeren + kurze Bestätigung + sanfter Reload
      for (const el of visibleInputs()) {
        if (el.type === 'file') { el.value = ''; }
        else if (el.tagName === 'SELECT') { /* belassen */ }
        else { el.value = ''; }
      }
      msg.textContent = '✅ Gespeichert (#'+js.id+')';

      // Reload mit Scroll-Position
      const u = new URL(location.href);
      u.searchParams.set('scroll', String(scrollY));
      location.href = u.toString();
    } catch (e) {
      msg.textContent = '❌ ' + (e.message || 'Fehler beim Speichern');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Speichern';
    }
  });
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
