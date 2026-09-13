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
require_once __DIR__ . '/../includes/vorgang_taxonomy.php'; // Vorgangsarten

require_login();
vorgang_taxonomy_ensure_tables($mysqli);

// Migration: Ensure pendenzen.objekt_id exists
if (column_exists($mysqli, 'pendenzen', 'projekt_id') && !column_exists($mysqli, 'pendenzen', 'objekt_id')) {
    $mysqli->query("ALTER TABLE pendenzen ADD COLUMN objekt_id INT(11) NULL DEFAULT NULL AFTER projekt_id");
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$PREFIX = site_prefix();              // z.B. "/pendenz.com/"
$ASSET  = rtrim($PREFIX,'/').'/'; 
$flash  = "";
?>
<script>
// --- GLOBAL DELETE (Urvater-Definition im Head) ---
window.trashPendenz = async function(id) {
    if(!id) return;
    if(!confirm('Diesen Eintrag wirklich permanent löschen?')) return;
    
    // Debugging Alert (entfernen wenn alles geht)
    // alert('Lösche ID: ' + id);

    const row = document.querySelector(`tr[data-id="${id}"]`);
    if(row) {
        row.style.opacity = '0.3';
        row.style.filter = 'grayscale(1)';
    }

    try {
        const res = await fetch(`pendenzen.php?delete=${id}&ajax=1`);
        const js = await res.json();
        if(js.ok) {
            if(row) {
                row.style.transition = '0.4s ease';
                row.style.transform = 'scale(0.8) translateX(200px)';
                row.style.opacity = '0';
                setTimeout(()=>row.remove(), 400);
            }
        } else {
            alert('Löschen fehlgeschlagen: ' + (js.error || 'Serverfehler'));
            if(row) {
                row.style.opacity = '1';
                row.style.filter = '';
            }
        }
    } catch(e) {
        console.error('AJAX Delete Failed:', e);
        if(confirm('Verbindungsproblem. Möchtest du die Seite neu laden und es klassisch versuchen?')) {
            window.location.href = `pendenzen.php?delete=${id}`;
        }
    }
};
</script>
<?php

// --- PHP WORKDAY HELPERS ---
function phpIsWorkDay($ts) {
    $w = (int)date('w', $ts);
    return ($w > 0 && $w < 6);
}

function phpAddWorkDays($ts, $days) {
    $d = $ts;
    $added = 0;
    while ($added < $days) {
        $d = strtotime("+1 day", $d);
        if (phpIsWorkDay($d)) $added++;
    }
    return $d;
}

function phpGetWorkDaysDiff($start, $end) {
    if ($start > $end) return 0;
    $d = $start; $count = 0;
    while ($d < $end) {
        $d = strtotime("+1 day", $d);
        if (phpIsWorkDay($d)) $count++;
    }
    return $count;
}

function phpSubWorkDays($ts, $days) {
    $d = $ts;
    $subbed = 0;
    while ($subbed < $days) {
        $d = strtotime("-1 day", $d);
        if (phpIsWorkDay($d)) $subbed++;
    }
    return $d;
}

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
function fetch_members_by_project($db, $pid, $oid = 0){
  $oid = (int)$oid;
  $sql = "
    SELECT DISTINCT b.id, b.name, b.email, bkp.bezeichnung as bkp_name, bkp.code as bkp_code
    FROM benutzer b
    LEFT JOIN benutzer_projekte bp ON bp.benutzer_id=b.id AND bp.projekt_id=?
    LEFT JOIN team_projekte tp ON tp.projekt_id=?
    LEFT JOIN benutzer_teams bt ON bt.benutzer_id=b.id AND bt.team_id=tp.team_id
    LEFT JOIN unternehmer_projekte up ON up.benutzer_id=b.id AND up.projekt_id=?
    LEFT JOIN bkp_codes bkp ON bkp.id = up.bkp_id
    WHERE (bp.projekt_id IS NOT NULL OR bt.team_id IS NOT NULL OR up.projekt_id IS NOT NULL)
  ";
  if ($oid > 0) {
      // Additional filter for companies assigned to this building
      // Assuming unternehmer_projekte has an objekt_id column or similar link
      // If not, we fall back to project-wide but prioritize the building context if the schema allows
      // For now, let's check if we can filter by building specifically if that's what the user 'got done'
      $sql .= " AND (up.objekt_id IS NULL OR up.objekt_id = $oid OR up.objekt_id = 0)";
  }
  $sql .= " ORDER BY b.name";
  $stmt=$db->prepare($sql);
  $stmt->bind_param("iii",$pid,$pid,$pid); $stmt->execute(); return $stmt->get_result();
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
function ensure_standard_rooms(mysqli $db, int $wohnung_id) {
  $res = $db->query("SELECT COUNT(*) FROM raeume WHERE wohnung_id = $wohnung_id");
  if ($res && (int)$res->fetch_row()[0] === 0) {
      $rooms = ['Entrée','Küche','Essen','Wohnen','Zimmer 1','Zimmer 2','Bad/WC','Dusche/WC','Reduit','Balkon/Terrasse'];
      $stmt = $db->prepare("INSERT INTO raeume (wohnung_id, name, sort_order) VALUES (?, ?, ?)");
      foreach ($rooms as $i => $name) {
          $sort = ($i + 1) * 10;
          $stmt->bind_param("isi", $wohnung_id, $name, $sort);
          $stmt->execute();
      }
      $stmt->close();
  }
}

/** -------- FS aus fs_nodes -------- */

// Ordnerstruktur fest an die Wohnungen gekoppelt.
// UI HELPERS (nach unten verschoben)

/** -------- Inline JSON APIs / AJAX -------- */
if (isset($_GET['action']) && $_GET['action']==='context') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $pid = (int)($_GET['projekt_id'] ?? 0);
    if ($pid<=0) { echo json_encode(['ok'=>false,'error'=>'projekt_id fehlt']); exit; }
    
    $oid = (int)($_GET['objekt_id'] ?? 0);
    $members=[]; $rs1=fetch_members_by_project($mysqli,$pid,$oid); 
    if($rs1) while($x=$rs1->fetch_assoc()){ 
        $name = $x['name'] . ($x['bkp_name'] ? " ({$x['bkp_name']})" : "");
        $members[]=['id'=>(int)$x['id'],'name'=>$name,'email'=>$x['email'], 'bkp_code'=>$x['bkp_code']]; 
    }
    
    $teams=[]; $rs2=fetch_teams_by_project($mysqli,$pid);   
    if($rs2) while($x=$rs2->fetch_assoc()){ $teams[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $cos=[]; $rs3=fetch_firmen_by_project($mysqli,$pid);   
    if($rs3) foreach($rs3 as $x){ $cos[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $oid = (int)($_GET['objekt_id'] ?? 0);
    $wohns=[]; 
    $wQuery = "SELECT w.id, w.name, w.objekt_id, o.name as objekt_name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$pid";
    if($oid > 0) $wQuery .= " AND w.objekt_id=$oid";
    $wQuery .= " ORDER BY o.name, w.name";
    $rs4=$mysqli->query($wQuery);
    if($rs4) while($x=$rs4->fetch_assoc()){ $wohns[]=['id'=>(int)$x['id'],'name'=>$x['name'],'objekt_name'=>$x['objekt_name'], 'objekt_id'=>(int)$x['objekt_id']]; }
    
    $objs=[]; $rs5=$mysqli->query("SELECT id, name, folder_name FROM objekte WHERE projekt_id=$pid ORDER BY name");
    if($rs5) while($x=$rs5->fetch_assoc()){ $objs[]=['id'=>(int)$x['id'],'name'=>$x['name'], 'folder_name'=>$x['folder_name']]; }
    
    $kats=[]; $rs6=$mysqli->query("SELECT id, name FROM pendenz_kategorien WHERE projekt_id IS NULL OR projekt_id=$pid ORDER BY name");
    if($rs6) while($x=$rs6->fetch_assoc()){ $kats[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $arten=[]; $rs7=$mysqli->query("SELECT id, name FROM pendenzen_arten ORDER BY sort_order, name");
    if($rs7) while($x=$rs7->fetch_assoc()){ $arten[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    echo json_encode([
      'ok'=>true,
      'members'=>$members,
      'teams'=>$teams,
      'companies'=>$cos,
      'apartments'=>$wohns,
      'objects'=>$objs,
      'categories'=>$kats,
      'types'=>$arten
    ], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $t) {
    echo json_encode(['ok'=>false, 'error'=>$t->getMessage()]);
  }
  exit;
}
// FS-Folder API wurde entfernt, Pfade generieren sich automatisch!
// Räume (Wohnungsspezifisch)
if (isset($_GET['action']) && $_GET['action'] === 'rooms') {
  header('Content-Type: application/json; charset=utf-8');
  $wid = (int)($_GET['wohnung_id'] ?? 0);
  $items = [];
  try {
    if ($wid > 0) {
      $st = $mysqli->prepare("SELECT id, name FROM raeume WHERE wohnung_id=? ORDER BY sort_order, name");
      $st->bind_param("i", $wid);
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
// BKP Hierarchie (Neu)
if (isset($_GET['action']) && $_GET['action'] === 'bkp_hierarchy') {
  header('Content-Type: application/json; charset=utf-8');
  $bkp = $_GET['bkp'] ?? '';
  $kid = (int)($_GET['kategorie_id'] ?? 0);
  $res = ['ok'=>true, 'kategorien'=>[], 'templates'=>[]];
  try {
    if ($bkp !== '') {
      // Kategorien für BKP (oder ähnliche Codes) holen
      $stmt = $mysqli->prepare("
        SELECT k.id, k.name 
        FROM bkp_kategorien k
        JOIN bkp_codes c ON k.bkp_id = c.id
        WHERE c.code = ? OR c.code LIKE ?
        ORDER BY k.name
      ");
      $like = $bkp . ".%";
      $stmt->bind_param("ss", $bkp, $like);
      $stmt->execute();
      $rs = $stmt->get_result();
      while($row = $rs->fetch_assoc()) $res['kategorien'][] = $row;
      $stmt->close();
    }
    if ($kid > 0) {
      // Templates für gewählte Kategorie
      $stmt = $mysqli->prepare("SELECT id, text_vorlage FROM bkp_vorlagen_texte WHERE kategorie_id = ? ORDER BY text_vorlage");
      $stmt->bind_param("i", $kid);
      $stmt->execute();
      $rs = $stmt->get_result();
      while($row = $rs->fetch_assoc()) $res['templates'][] = $row;
      $stmt->close();
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}
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
    $did = (int)$_GET['delete'];
    $r = $mysqli->query("SELECT * FROM pendenzen WHERE id={$did}");
    $p = $r ? $r->fetch_assoc() : null;
    
    $authorized = can_edit_pendenz($mysqli, $p, (int)($_SESSION['user_id'] ?? 0));
    // Localhost Debug/Override
    if ($_SERVER['HTTP_HOST'] === 'localhost') $authorized = true;

    if ($p && $authorized) {
        $ra = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$did}");
        while ($a = $ra->fetch_assoc()) {
            $abs = __DIR__ . '/../' . ltrim($a['pfad'], '/');
            if (is_file($abs)) @unlink($abs);
        }
        $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id={$did}");
        $mysqli->query("DELETE FROM pendenzen WHERE id={$did}");
        log_action($mysqli, 'pendenz', $did, 'delete');
        
        if (isset($_GET['ajax'])) {
            echo json_encode(['ok' => true]);
            exit;
        }
    } else {
        if (isset($_GET['ajax'])) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung oder Pendenz nicht gefunden']);
            exit;
        }
        $flash = "Löschen nicht erlaubt oder Pendenz nicht gefunden.";
    }
    header("Location: " . $PREFIX . "pages/pendenzen.php");
    exit;
}

/** -------- Drive-Sync Action -------- */
if (isset($_POST['action']) && $_POST['action'] === 'sync_fs') {
    require_once __DIR__ . '/../includes/fs.php';
    $pid = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));
    if ($pid > 0) {
        $stats = sync_project_folders($mysqli, $pid);
        $flash = "📂 Dateisystem-Abgleich abgeschlossen: " . $stats['total'] . " Einheiten geprüft, " . $stats['created_disk'] . " Ordner neu angelegt.";
        header("Location: pendenzen.php?projekt_id=" . $pid . "&flash=" . urlencode($flash));
        exit;
    }
}

/** -------- Create/Update -------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_POST['action']) || !in_array($_POST['action'],['delete_file','set_cover','save_template','apply_template'],true))) {
  try{
    // AJAX-Inline? (wir beantworten dann JSON)
    $isAjaxInline = (isset($_POST['inline_new']) && $_POST['inline_new']=='1')
      && (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'],'xmlhttprequest')!==false)
        || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
      );

    $id=(int)($_POST['id']??0);
    $projekt_id=(int)($_POST['projekt_id']??0);
    $objekt_id=($_POST['objekt_id']??'')!==''?(int)$_POST['objekt_id']:null;
    $wohnung_id=($_POST['wohnung_id']??'')!==''?(int)$_POST['wohnung_id']:null;
    $ordner_id=($_POST['ordner_id']??'')!==''?(int)$_POST['ordner_id']:null;
    $fs_rel_path = isset($_POST['fs_rel_path']) ? trim((string)$_POST['fs_rel_path']) : null;
    if ($fs_rel_path==='') $fs_rel_path = null;
    $fs_branch = trim((string)($_POST['fs_branch'] ?? '10_Mietsache'));

    $titel=trim($_POST['titel']??"");
    $kurz=trim($_POST['kurzbeschreibung']??"");
    $lang=trim($_POST['langbeschreibung']??"");
    $notiz=trim($_POST['notiz']??"");
    $wichtigkeit=(int)($_POST['wichtigkeit']??0);
    $startdatum=($_POST['startdatum']??'')!==''?$_POST['startdatum']:null;
    $enddatum=($_POST['enddatum']??'')!==''?$_POST['enddatum']:null;
    $uhrzeit=($_POST['uhrzeit']??'')!==''?$_POST['uhrzeit']:null;
    $tageszeit=($_POST['tageszeit']??'')!==''?$_POST['tageszeit']:null;
    $dauerRaw=trim((string)($_POST['dauer']??''));
    
    // Säuberung der Dauer (entferne " Tage" etc. für DB-Kompatibilität)
    if ($dauerRaw !== '') {
        $num = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', $dauerRaw));
        $dauer = ($num > 0) ? "$num Tage" : null;
    }

    $status=$_POST['status']??'offen'; if ($status==='in_bearbeitung') $status='in Bearbeitung';

    // --- SERVER-SIDE DATE AUTO-CALCULATION (Sinc with JS logic) ---
    $tsStart = ($startdatum && $startdatum !== '0000-00-00') ? strtotime($startdatum) : null;
    $tsEnd   = ($enddatum && $enddatum !== '0000-00-00') ? strtotime($enddatum) : null;
    
    if ($tsStart && $dauer > 0) {
        // ZWANG: Start + Dauer = Fällig
        $newEndMs = phpAddWorkDays($tsStart, $dauer);
        $enddatum = date('Y-m-d', $newEndMs);
    } elseif ($tsStart && $tsEnd) {
        // Start + Ende = Dauer
        $dauer = phpGetWorkDaysDiff($tsStart, $tsEnd);
    } elseif ($tsEnd && $dauer > 0) {
        // NEU: Ende + Dauer = Start
        $newStartMs = phpSubWorkDays($tsEnd, $dauer);
        $startdatum = date('Y-m-d', $newStartMs);
    }

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
    $vorgaenger_id         = (($_POST['vorgaenger_id'] ?? '') !== '') ? (int)$_POST['vorgaenger_id'] : null;
    $vorgangsart_id                = (($_POST['vorgangsart_id'] ?? '') !== '') ? (int)$_POST['vorgangsart_id'] : null;
    $raum_id               = (($_POST['raum_id'] ?? '') !== '') ? (int)$_POST['raum_id'] : null;

    if($titel==="") throw new Exception("Titel ist erforderlich.");
    if($projekt_id<=0) throw new Exception("Projekt auswählen.");

    $sichtbarkeit=($sichtbarkeit_ui==='privat'?'privat':(($sichtbarkeit_ui==='team'||$sichtbarkeit_ui==='users')?'custom':'projekt'));
    $zustaendig_id=($assignee_type==='user' && $assignee_user_id)?$assignee_user_id:null;

    if($id>0){
      $old=$mysqli->query("SELECT * FROM pendenzen WHERE id={$id}")->fetch_assoc();
      if(!$old) throw new Exception("Pendenz nicht gefunden.");
      if(!can_edit_pendenz($mysqli,$old,(int)($_SESSION['user_id']??0))) throw new Exception("Keine Berechtigung.");

      $st=$mysqli->prepare("UPDATE pendenzen
        SET projekt_id=?, objekt_id=?, wohnung_id=?, ordner_id=?, fs_rel_path=?, fs_branch=?, titel=?, kurzbeschreibung=?, langbeschreibung=?, notiz=?, wichtigkeit=?, startdatum=?, enddatum=?, uhrzeit=?, tageszeit=?, dauer=?, vorgaenger_id=?, status=?, sichtbarkeit=?, assignee_can_edit=?, zustaendig_id=?, is_protocol=?, protocol_type=?, vorgangsart_id=?, raum_id=?
        WHERE id=?");
      $st->bind_param("iiiissssssisssssissiiisiii", 
        $projekt_id, $objekt_id, $wohnung_id, $ordner_id, $fs_rel_path, $fs_branch, $titel, $kurz, $lang, $notiz, $wichtigkeit, $startdatum, $enddatum, $uhrzeit, $tageszeit, $dauer, $vorgaenger_id, $status, $sichtbarkeit, $assignee_can_edit, $zustaendig_id, $is_protocol, $protocol_type, $vorgangsart_id, $raum_id, $id
      );
      $st->execute(); $pendenz_id=$id; log_action($mysqli,'pendenz',$id,'update',['title'=>$titel,'status'=>$status]);
    } else {
      $erstellt_von=(int)(current_user_id()??0);
      $chkUser = $mysqli->query("SELECT id FROM benutzer WHERE id=$erstellt_von");
      if (!$chkUser || $chkUser->num_rows === 0) {
          $erstellt_von = 15; // Fallback zu Admin Demo
      }
      $st=$mysqli->prepare("INSERT INTO pendenzen
        (projekt_id, objekt_id, wohnung_id, ordner_id, fs_rel_path, fs_branch, titel, kurzbeschreibung, langbeschreibung, notiz, wichtigkeit, startdatum, enddatum, uhrzeit, tageszeit, dauer, vorgaenger_id, status, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id, is_protocol, protocol_type, vorgangsart_id, raum_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $st->bind_param("iiiissssssisssssisiisiiisii", 
        $projekt_id, $objekt_id, $wohnung_id, $ordner_id, $fs_rel_path, $fs_branch, $titel, $kurz, $lang, $notiz, $wichtigkeit, $startdatum, $enddatum, $uhrzeit, $tageszeit, $dauer, $vorgaenger_id, $status, $erstellt_von, $sichtbarkeit, $assignee_can_edit, $zustaendig_id, $is_protocol, $protocol_type, $vorgangsart_id, $raum_id
      );
      $st->execute(); $pendenz_id=$st->insert_id; log_action($mysqli,'pendenz',$pendenz_id,'create',['title'=>$titel]);
    }

    if ($wohnung_id > 0) {
        ensure_standard_rooms($mysqli, $wohnung_id);
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
          list($rel,$abs) = pendenz_fs_base($mysqli, $projekt_id, $ordner_id, $pendenz_id, $titel, (string)$fs_rel_path);
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
          $isCover = 0; $origTitle = $u['dateiname'] ?: basename($finalRel); $type='image'; 
          
          $validUid = null;
          $u_sess = $_SESSION['user_id'] ?? null;
          if ($u_sess) {
              $u_chk = $mysqli->query("SELECT id FROM benutzer WHERE id=".(int)$u_sess);
              if ($u_chk && $u_chk->num_rows > 0) $validUid = (int)$u_sess;
          }

          $st2->bind_param("isssisis",$pendenz_id,$type,$finalRel,$mime,$size,$origTitle,$isCover,$validUid);
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
          list($rel,$abs) = pendenz_fs_base($mysqli, $projekt_id, $ordner_id, $pendenz_id, $titel, (string)$fs_rel_path);
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
          $isCover = 0; $origTitle = $u['dateiname'] ?: basename($finalRel); 
          
          $validUidA = null;
          $u_sessA = $_SESSION['user_id'] ?? null;
          if ($u_sessA) {
              $u_chkA = $mysqli->query("SELECT id FROM benutzer WHERE id=".(int)$u_sessA);
              if ($u_chkA && $u_chkA->num_rows > 0) $validUidA = (int)$u_sessA;
          }

          $st2->bind_param("isssisis",$pendenz_id,$type,$finalRel,$mime,$size,$origTitle,$isCover,$validUidA);
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
  if ($name=== '') { $flash='❌ Vorlagen-Name fehlt.'; }
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
  'erstellt_am'=>'Erstellt', 'geaendert_am'=>'Update', 'bilder'=>'📸', 'anhaenge'=>'📎', 'balance'=>'Saldo', 'dauer'=>'Dauer',
  'kurzbeschreibung'=>'Kurzbeschreibung', 'zustaendig_id' => 'Zuständig',
  'art_name' => 'Vorgangsart', 'raum_name' => 'Raum'
];
$defaultCols = ['erstes_bild', 'art_name', 'titel', 'kurzbeschreibung', 'status', 'enddatum', 'kategorie_name', 'bilder', 'anhaenge'];

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
            $layerFilters = json_decode((string)$resP['filters_json'], true);
            $layerQuickChoices = $layerFilters['quick_choices'] ?? '';
            
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
            $fJson = $layerFilters;
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
  'vorgangsart_id' => (int)($_GET['vorgangsart_id'] ?? 0) ?: null,
  'q'          => $q,
  'order'      => (string)($_GET['order'] ?? 'eigene'),
  'dir'        => (string)($_GET['dir'] ?? 'desc'),
  'limit'      => (int)($_GET['limit'] ?? 100),
  'offset'     => (int)($_GET['offset'] ?? 0),
  'owner_isolation_id' => (int)($_SESSION['user_id'] ?? 0),
  'is_superadmin' => is_superadmin()
];

// Aktuelle Pendenzen für die Liste
$rsPendenzen = $pendenzen->searchResult($filters);

// --- SELF-HEALING DATA INTEGRITY (Automated Bulk Fix on Refresh) ---
if ($rsPendenzen && $rsPendenzen->num_rows > 0) {
    while($row = $rsPendenzen->fetch_assoc()){
        $sid = (int)$row['id'];
        $st  = ($row['startdatum'] && $row['startdatum'] !== '0000-00-00') ? strtotime($row['startdatum']) : null;
        $ed  = ($row['enddatum'] && $row['enddatum'] !== '0000-00-00') ? strtotime($row['enddatum']) : null;
        $durRaw = trim((string)$row['dauer']);
        $durNum = (float)filter_var($durRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        $newSt = (string)($row['startdatum'] ?? ''); $newEd = (string)($row['enddatum'] ?? ''); $newDur = $durRaw;
        $needsSync = false;

        if ($st && $durNum > 0) {
            $expEd = date('Y-m-d', phpAddWorkDays($st, $durNum));
            if ($newEd !== $expEd) { $newEd = $expEd; $needsSync = true; }
        } elseif ($st && $ed) {
            $expDNum = (float)phpGetWorkDaysDiff($st, $ed); $expDStr = "$expDNum Tage";
            if ($newDur !== $expDStr) { $newDur = $expDStr; $needsSync = true; }
        } elseif ($ed && $durNum > 0 && !$st) {
            $expSt = date('Y-m-d', phpSubWorkDays($ed, $durNum));
            if ($newSt !== $expSt) { $newSt = $expSt; $needsSync = true; }
        }

        if ($durNum > 0 && !str_contains($newDur, ' Tage')) {
            $newDur = $durNum . " Tage"; $needsSync = true;
        }

        if ($needsSync) {
            $mysqli->query("UPDATE pendenzen SET startdatum='$newSt', enddatum='$newEd', dauer='$newDur', geaendert_am=NOW() WHERE id=$sid");
        }
    }
    $rsPendenzen->data_seek(0);
}





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
<style>
/* Performance: content-visibility for long lists */
tr { content-visibility: auto; contain-intrinsic-size: 1px 48px; }

/* Layout Constraints */
html, body { overflow-x: hidden; width: 100%; margin: 0; padding: 0; }
.container-fluid { max-width: 100vw; overflow-x: hidden; box-sizing: border-box; padding: 20px; }

.table-container { 
    width: 100%;
    max-width: 100%;
    overflow-x: auto; 
    -webkit-overflow-scrolling: touch;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    margin-top: 20px;
    position: relative;
    will-change: transform;
    border-radius: 12px;
}


/* Resizable Columns */
th { position: relative; }
.resizer {
    position: absolute; right: 0; top: 0; height: 100%; width: 5px;
    background: rgba(0,0,0,0.05); cursor: col-resize; user-select: none;
}
.resizer:hover { background: #0ea5e9; }

/* Spreadsheet Editor Styles */
.inline-editor {
    width: 100%; height: 100%; border: 2px solid #0ea5e9; 
    padding: 2px 6px; border-radius: 4px; outline: none;
    font-size: 12px; font-weight: 600; color: #1e293b;
    background: #fff; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1);
}
td.inline-editable:hover { background: #f1f5f9; cursor: cell; }
td.inline-editable { transition: 0.2s; position: relative; min-height: 40px; }

.table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
.table th { 
    position: sticky; top: 0; background: #f1f5f9; padding: 12px 10px; 
    text-align: left; font-weight: 800; color: #475569; 
    border-bottom: 2px solid #cbd5e1; z-index: 10;
    text-transform: uppercase; letter-spacing: 0.05em; font-size: 11px;
}
.table td { 
    padding: 10px; height: auto; min-height: 48px; border-bottom: 1px solid #e2e8f0; 
    white-space: normal; word-break: break-word; vertical-align: middle;
}
.table tbody tr:hover { background: #f8fafc; }

.status-chip { 
    padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; 
    cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px;
}
.status-chip:hover { transform: scale(1.05); filter: brightness(0.95); }

.star-rating span { cursor: pointer; transition: transform 0.1s; display: inline-block; }
.star-rating span:hover { transform: scale(1.3); }

.thumb-mini { 
    width: 50px; height: 40px; object-fit: cover; border-radius: 6px; 
    border: 1px solid #e2e8f0; cursor: zoom-in; transition: transform 0.2s;
}
.thumb-mini:hover { transform: scale(1.1); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }

/* Lightbox Modal */
.image-modal {
    display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%;
    background-color: rgba(0,0,0,0.9); backdrop-filter: blur(8px);
    justify-content: center; align-items: center; cursor: zoom-out;
}
.image-modal img { max-width: 90%; max-height: 90%; border-radius: 8px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }

/* Quick Choice Pills */
.quick-choices {
    display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;
}
.quick-choice-pill {
    background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;
    padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;
    cursor: pointer; transition: all 0.1s;
}
.quick-choice-pill:hover { background: #38bdf8; color: #fff; transform: translateY(-1px); }

/* --- QUICK CAPTURE DRAWER (MOBILE OPTIMIZED) --- */
.fab {
    position: fixed; bottom: 24px; right: 24px; width: 64px; height: 64px;
    background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 10px 25px rgba(2, 132, 199, 0.4); cursor: pointer; z-index: 10000;
    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); border: none; font-size: 32px;
}
.fab:hover { transform: scale(1.1) rotate(90deg); box-shadow: 0 15px 30px rgba(2, 132, 199, 0.5); }

.quick-drawer {
    position: fixed; top: 0; right: -100%; width: 100%; max-width: 500px; height: 100%;
    background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px);
    box-shadow: -10px 0 40px rgba(0,0,0,0.1); z-index: 10001;
    transition: right 0.4s cubic-bezier(0.4, 0, 0.2, 1); padding: 24px;
    display: flex; flex-direction: column; overflow-y: auto; border-left: 1px solid rgba(0,0,0,0.05);
}
.quick-drawer.active { right: 0; }
.quick-drawer-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.quick-drawer-header h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; }
.quick-drawer-close { background: #f1f5f9; border: none; border-radius: 50%; width: 36px; height: 36px; cursor: pointer; color: #64748b; font-size: 18px; display: flex; align-items: center; justify-content: center; }

.quick-field { margin-bottom: 20px; }
.quick-field label { display: block; margin-bottom: 8px; font-weight: 700; font-size: 13px; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; }
.quick-input { width: 100%; padding: 14px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; font-size: 16px; outline: none; transition: border-color 0.2s; }
.quick-input:focus { border-color: #0ea5e9; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1); }

.quick-cat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; }
.quick-cat-btn {
    border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 8px; text-align: center;
    cursor: pointer; transition: all 0.2s; background: #fff; display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.quick-cat-btn.active { background: #e0f2fe; border-color: #0ea5e9; box-shadow: 0 4px 10px rgba(14, 165, 233, 0.1); }
.quick-cat-btn span { font-size: 11px; font-weight: 700; color: #1e293b; }
.quick-cat-btn .icon { font-size: 24px; }

.quick-photo-zone {
    border: 2px dashed #0ea5e9; border-radius: 12px; padding: 20px; text-align: center;
    background: rgba(14, 165, 233, 0.03); cursor: pointer; transition: all 0.2s;
}
.quick-photo-zone:hover { background: rgba(14, 165, 233, 0.08); }
.quick-photo-preview { display: grid; grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); gap: 8px; margin-top: 12px; }
.quick-photo-preview img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0; }

.quick-save-btn {
    width: 100%; padding: 18px; background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white; border: none; border-radius: 16px; font-size: 18px; font-weight: 800; cursor: pointer;
    box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3); margin-top: auto; transition: all 0.2s;
}
.quick-save-btn:active { transform: scale(0.98); }

@media (max-width: 600px) {
    .quick-drawer { max-width: 100%; border-left: none; border-radius: 24px 24px 0 0; height: 90%; top: auto; bottom: -100%; right: 0; transition: bottom 0.4s; }
    .quick-drawer.active { bottom: 0; }
}
/* --- MODERN MAIN FORM --- */
#formSection {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 24px; padding: 32px; margin-bottom: 40px;
    border: 1px solid rgba(255,255,255,0.5); box-shadow: 0 20px 50px rgba(0,0,0,0.05);
}
.form-step-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; }
.form-card {
    background: #fff; border-radius: 20px; padding: 24px;
    border: 1px solid #e2e8f0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex; flex-direction: column; gap: 20px;
}
.form-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.05); border-color: #0ea5e9; }
.form-card h3 {
    margin: 0; font-size: 16px; font-weight: 800; color: #0f172a;
    display: flex; align-items: center; gap: 10px; text-transform: uppercase; letter-spacing: 0.05em;
}
.form-card h3 svg { color: #0ea5e9; width: 20px; height: 20px; }

.form-field { display: flex; flex-direction: column; gap: 8px; }
.form-field label { font-size: 12px; font-weight: 700; color: #64748b; }
.form-input {
    width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 12px;
    font-size: 15px; transition: all 0.2s; outline: none; background: #fcfcfc;
}
.form-input:focus { border-color: #0ea5e9; background: #fff; box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1); }

.form-actions-bar {
    margin-top: 32px; padding-top: 24px; border-top: 1px solid #e2e8f0;
    display: flex; justify-content: flex-end; gap: 16px;
}
.btn-save-primary {
    padding: 16px 48px; border-radius: 14px; background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
    color: white; font-weight: 800; font-size: 16px; border: none; cursor: pointer;
    box-shadow: 0 10px 20px rgba(14, 165, 233, 0.3); transition: all 0.2s;
}
.btn-save-primary:hover { transform: scale(1.02); box-shadow: 0 15px 30px rgba(14, 165, 233, 0.4); }
.btn-save-primary:active { transform: scale(0.98); }

@media (max-width: 768px) {
    #formSection { padding: 16px; }
    .btn-save-primary { width: 100%; }
    
    /* Mobile Table to Card Transformation */
    .table, .table tbody, .table tr, .table td { display: block; width: 100%; }
    .table thead { display: none; }
    
    .table tr {
        background: #ffffff;
        margin-bottom: 20px;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        border: 1px solid rgba(226, 232, 240, 0.8);
        position: relative;
    }
    
    .table td {
        display: flex;
        justify-content: flex-start;
        align-items: flex-start;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
        height: auto;
        white-space: normal;
        overflow: visible;
        min-height: 44px;
    }
    
    .table td:last-child { 
        border-bottom: none; 
        margin-top: 15px; 
        padding-top: 15px;
        border-top: 2px dashed #f1f5f9;
        justify-content: flex-end; 
        gap: 12px; 
    }
    
    .table td::before {
        content: attr(data-label);
        font-weight: 800;
        font-size: 9px;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-right: 15px;
        flex: 0 0 35%; /* Fixed proportion for label */
        margin-top: 3px;
    }
    
    .table td > span, .table td > div, .table td > a {
        flex: 1;
        word-break: break-word;
    }
    
    .table td .thumb-mini {
        width: 100px !important;
        height: 75px !important;
        border-radius: 10px;
        flex: 0 0 auto;
    }
    
    .table td[data-field="titel"] {
        flex-direction: column;
        align-items: flex-start;
        border-bottom: 2px solid #f1f5f9;
        margin-bottom: 5px;
    }
    .table td[data-field="titel"] span { 
        font-size: 16px; 
        color: #0f172a;
        line-height: 1.4;
    }
    
    .table td[data-field="status"] {
        background: #f8fafc;
        margin: 5px -20px;
        padding: 12px 20px;
    }
    
    #inlineNewRow { display: none; } /* Inline-Add on mobile hidden, use FAB/Drawer instead */
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
      <form method="POST" style="margin:0;">
          <input type="hidden" name="action" value="sync_fs">
          <button type="submit" class="btn btn-head" title="Alle Wohnungs-Ordner mit Datenbank abgleichen">🔄 Drive-Sync</button>
      </form>
      <a class="btn btn-head" href="pendenz_kategorien.php">🏷️ Kategorien</a>
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


    <?php
      // Initial-Werte & Data Fetching (Inheritance from Active Filters)
      $pidInit   = (int)($editPendenz['projekt_id'] ?? ($_GET['projekt_id'] ?? ($last['projekt_id'] ?? 0)));
      $oidInit   = (int)($editPendenz['objekt_id'] ?? ($_GET['objekt_id'] ?? ($last['objekt_id'] ?? 0)));
      $widInit   = (int)($editPendenz['wohnung_id'] ?? ($_GET['wohnung_id'] ?? ($last['wohnung_id'] ?? 0)));
      
      $fsRelEdit = (string)($editPendenz['fs_rel_path'] ?? '');
      $fsRelGet  = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';
      $fsRelInit = $fsRelEdit !== '' ? $fsRelEdit : ($last['fs_rel_path'] ?? $fsRelGet);

      // PHP Fallback calculation if still empty but we have an object/unit
      if ($fsRelInit === '' && $oidInit > 0) {
          $rsO = $mysqli->query("SELECT name, folder_name FROM objekte WHERE id=$oidInit");
          if ($o = $rsO->fetch_assoc()) {
              $fsRelInit = ($o['folder_name'] ?: $o['name']) . "/10_Mietsache";
              if ($widInit > 0) {
                  $rsW = $mysqli->query("SELECT name FROM wohnungen WHERE id=$widInit");
                  if ($w = $rsW->fetch_assoc()) $fsRelInit .= "/" . $w['name'];
              }
          }
      }

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
    <form method="post" enctype="multipart/form-data" id="pendenz-form">
      <?php if($editPendenz): ?><input type="hidden" name="id" value="<?= (int)$editPendenz['id'] ?>"><?php endif; ?>

      <div class="form-step-container">
        <!-- 1. Basics -->
        <div class="form-card">
           <h3>
             <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
             1. Projekt & Ort
           </h3>
           <div class="form-field">
             <label>Projekt*</label>
             <select name="projekt_id" id="projekt_id" class="form-input" required>
               <option value="">— bitte wählen —</option>
               <?php $proj=fetch_projekte($mysqli); while($p=$proj->fetch_assoc()): ?>
                 <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id']===$pidInit?'selected':'' ?> data-path="<?= h($p['name']) ?>"><?= h($p['name']) ?></option>
               <?php endwhile; ?>
             </select>
           </div>
            <div class="form-field">
              <label>Haus / Gebäude</label>
              <select name="objekt_id" id="objekt_id" class="form-input" onchange="filterUnitsByObject(this.value); syncFolderPath();">
                 <option value="">— Alle —</option>
                 <?php if($pidInit){ $rsO = $mysqli->query("SELECT id, name, folder_name FROM objekte WHERE projekt_id=$pidInit ORDER BY name"); if($rsO) while($o=$rsO->fetch_assoc()): ?>
                     <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id']===$oidInit?'selected':'' ?> data-path="<?= h($o['folder_name'] ?? $o['name']) ?>"><?= h($o['name']) ?></option>
                 <?php endwhile; } ?>
              </select>
            </div>
            <div class="form-field">
              <label>Bereich / Ebene</label>
              <select name="fs_branch" id="fs_branch" class="form-input" onchange="toggleUnitSelect(this.value); syncFolderPath();">
                 <option value="10_Mietsache" selected>10_Mietsache (Wohnungen)</option>
                 <option value="05_Allgemein">05_Allgemein (Dach, Keller, Fassade)</option>
                 <option value="06_Tiefgarage">06_Tiefgarage</option>
              </select>
            </div>
            <div class="form-field" id="unit_select_wrap">
              <label>Einheit / Wohnung</label>
              <select name="wohnung_id" id="wohnung_id" class="form-input" onchange="updateRooms(this.value)">
                 <option value="" data-path="">— keine / alle —</option>
                 <?php if($pidInit){ $sqW = "SELECT w.id, w.name, w.objekt_id FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$pidInit"; if($oidInit>0) $sqW.=" AND w.objekt_id=$oidInit"; $rsW=$mysqli->query($sqW); if($rsW) while($w=$rsW->fetch_assoc()): ?>
                     <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id']===$widInit?'selected':'' ?> data-path="<?= h($w['name']) ?>" data-objekt-id="<?= (int)$w['objekt_id'] ?>"><?= h($w['name']) ?></option>
                 <?php endwhile; } ?>
              </select>
            </div>
           <div class="form-field">
             <label>Raum</label>
             <select name="raum_id" id="raum_id" class="form-input">
                <option value="">— bitte wählen —</option>
                <?php 
                if($widInit){
                  $rsR = $mysqli->query("SELECT id, name FROM raeume WHERE wohnung_id=$widInit ORDER BY sort_order, name");
                  if($rsR) while($r=$rsR->fetch_assoc()): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id']===(int)($editPendenz['raum_id']??0)?'selected':'' ?>><?= h($r['name']) ?></option>
                <?php endwhile; } ?>
             </select>
           </div>
            <div class="form-field">
              <label>Vorgangsart</label>
             <select name="vorgangsart_id" id="vorgangsart_id" class="form-input">
                <option value="">— bitte wählen —</option>
                <?php 
                  $rsA = $mysqli->query("SELECT id, name FROM pendenzen_arten ORDER BY sort_order, name");
                  if($rsA) while($a=$rsA->fetch_assoc()): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= (int)$a['id']===(int)($editPendenz['vorgangsart_id']??0)?'selected':'' ?>><?= h($a['name']) ?></option>
                <?php endwhile; ?>
             </select>
           </div>
           <div class="form-field">
             <label>Ziel-Ordner</label>
             <input type="text" name="fs_rel_path" id="fs_rel_path" readonly class="form-input" style="background:#f1f5f9; cursor:not-allowed; border-color:#cbd5e1; font-size:12px;" value="<?= h($fsRelInit) ?>">
           </div>
        </div>

        <!-- 2. The Issue -->
        <div class="form-card">
           <h3>
             <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
             2. Mangel / Aufgabe
           </h3>
           <div class="form-field">
             <label>Bauteil / Gewerk (BKP)</label>
             <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
               <select id="bkp_cat_id" class="form-input" onchange="loadBkpTemplates(this.value)">
                 <option value="">— Gewerk wählen —</option>
               </select>
               <select id="bkp_tpl_id" class="form-input" onchange="applyBkpTemplate(this)">
                 <option value="">— Vorlage wählen —</option>
               </select>
             </div>
             <p style="font-size:10px; color:#64748b; margin-top:4px;">Wird automatisch geladen, sobald ein zuständiger Unternehmer gewählt wurde.</p>
           </div>

           <div class="form-field">
             <label>Kategorie</label>
             <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
               <select name="kategorie_id" id="kategorie_id" class="form-input">
                 <option value="">Kategorie wählen…</option>
                 <?php foreach($cats as $c): ?>
                   <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']==$cat_id?'selected':'' ?>><?= h($c['name']) ?></option>
                 <?php endforeach; ?>
               </select>
               <select name="unterkategorie_id" id="unterkategorie_id" class="form-input" disabled>
                 <option value="">Unterkategorie…</option>
               </select>
             </div>
           </div>
           <div class="form-field">
             <label>Titel / Aufgabe*</label>
             <input type="text" name="titel" value="<?= h($editPendenz['titel']??"") ?>" required class="form-input" placeholder="Wand beschädigt, Fenster klemmt...">
             <div class="quick-choices" data-field="titel"></div>
           </div>
           <div class="form-field">
             <label>Beschreibung & Details</label>
             <textarea name="langbeschreibung" rows="3" class="form-input" placeholder="Genaue Beschreibung..."><?= h($editPendenz['langbeschreibung']??"") ?></textarea>
           </div>
           
           <div class="advanced-toggle" onclick="toggleAdvancedFields(this)" style="cursor:pointer; color:#0ea5e9; font-size:12px; font-weight:700; display:flex; align-items:center; gap:5px;">
             <span>➕ Erweiterte Details & Notiz</span>
           </div>
           <div class="advanced-fields" style="display:none; flex-direction:column; gap:15px;">
              <div class="form-field">
                <label>Interne Notiz (Gelb)</label>
                <textarea name="notiz" rows="2" class="form-input" style="background:#fffbeb; border-color:#fde68a;" placeholder="Nur für interne Zwecke..."><?= h($editPendenz['notiz']??"") ?></textarea>
              </div>
              <div class="form-field">
                <label>Kurzbeschreibung</label>
                <input type="text" name="kurzbeschreibung" value="<?= h($editPendenz['kurzbeschreibung']??"") ?>" class="form-input" placeholder="Zusammenfassung...">
              </div>
           </div>
        </div>

        <!-- 3. Logistics -->
        <div class="form-card">
           <h3>
             <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
             3. Status & Termine
           </h3>
           <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
             <div class="form-field">
               <label>Status</label>
               <select name="status" class="form-input">
                 <?php foreach(['offen','in Bearbeitung','erledigt','archiviert','wartend'] as $st): ?>
                   <option value="<?= h($st) ?>" <?= ($editPendenz['status']??'offen')===$st?'selected':'' ?>><?= h($st) ?></option>
                 <?php endforeach; ?>
               </select>
             </div>
             <div class="form-field">
               <label>Priorität</label>
               <input type="number" name="wichtigkeit" min="0" max="5" value="<?= (int)($editPendenz['wichtigkeit']??0) ?>" class="form-input">
             </div>
           </div>
           <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
             <div class="form-field">
               <label>Start-Datum</label>
               <input type="date" name="startdatum" value="<?= h($editPendenz['startdatum']??"") ?>" class="form-input date-calc-trigger">
             </div>
             <div class="form-field">
               <label>Dauer (z.B. 2w, 4h, 3T)</label>
               <input type="text" name="dauer" value="<?= h($editPendenz['dauer']??0) ?>" class="form-input date-calc-trigger" placeholder="z.B. 1w oder 4h">
             </div>
           </div>
           <div class="form-field">
             <label>Fällig am (Deadline)</label>
             <input type="date" name="enddatum" value="<?= h($editPendenz['enddatum']??"") ?>" class="form-input date-calc-trigger">
           </div>
           
           <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
             <div class="form-field">
               <label>Uhrzeit</label>
               <input type="time" name="uhrzeit" value="<?= h($editPendenz['uhrzeit']??"") ?>" class="form-input time-calc-trigger">
             </div>
             <div class="form-field">
               <label>Tageszeit</label>
               <select name="tageszeit" class="form-input">
                 <option value="">— wählen —</option>
                 <?php foreach(['Morgens','Vormittag','Mittag','Nachmittag','Abend','Nacht'] as $tz): ?>
                   <option value="<?= h($tz) ?>" <?= ($editPendenz['tageszeit']??"")===$tz?'selected':'' ?>><?= h($tz) ?></option>
                 <?php endforeach; ?>
               </select>
             </div>
           </div>
           <div class="form-field">
             <label>Zuständig</label>
             <select name="zustaendig_id" id="zustaendig_id" class="form-input">
                <option value="">— (optional) —</option>
                 <?php if($pidInit): $mem=fetch_members_by_project($mysqli,$pidInit); while($m=$mem->fetch_assoc()): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id']===$assUser?'selected':'' ?>><?= h($m['name']) ?></option>
                 <?php endwhile; endif; ?>
             </select>
           </div>
           <div class="form-field" style="margin-top:8px;">
             <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
               <input type="checkbox" name="is_protocol" value="1" <?= (int)($editPendenz['is_protocol']??0)===1?'checked':'' ?>>
               <span style="font-weight:700;">Als Protokoll-Punkt (Abnahme)</span>
             </label>
           </div>
        </div>

        <!-- 4. Media -->
        <div class="form-card">
           <h3>
             <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
             4. Medien
           </h3>
           <div class="form-field">
             <label>Fotos & Dateien</label>
             <div style="border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px; text-align: center; background: #f8fafc; cursor: pointer;" onclick="this.nextElementSibling.click()">
               <span style="font-size:24px;">📁</span>
               <div style="font-size:12px; font-weight:700; color:#64748b; margin-top:8px;">Klicken zum Hochladen</div>
             </div>
             <input type="file" name="bilder[]" multiple accept="image/*" style="display:none;">
           </div>
           
           <?php if($editPendenz): ?>
             <div id="mediaImages" class="quick-photo-preview" style="margin-top:10px;"></div>
             <div id="mediaFiles" style="margin-top:10px; font-size:11px;"></div>
           <?php endif; ?>
        </div>
      </div>

      <div class="form-actions-bar">
        <a href="pendenzen.php" class="btn" style="background:#fff; border:1px solid #cbd5e1; padding: 14px 24px; border-radius:12px;">Abbrechen</a>
        <button type="submit" class="btn-save-primary">
          <?= $editPendenz ? 'ÄNDERUNGEN SPEICHERN' : 'PENDENZ ERSTELLEN' ?>
        </button>
      </div>
    </form>
  </div>
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
                $objs = $mysqli->query("SELECT id, name, folder_name FROM objekte WHERE projekt_id=$selectedPid ORDER BY name");
                if($objs) while($o = $objs->fetch_assoc()): ?>
          <option value="<?= (int)$o['id'] ?>" <?= (int)$o['id']===(int)($_GET['objekt_id']??0)?'selected':'' ?> data-path="<?= h($o['folder_name'] ?? $o['name']) ?>"><?= h($o['name']) ?></option>
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
                if($whg) while($w = $whg->fetch_assoc()): ?>
          <option value="<?= (int)$w['id'] ?>" data-path="<?= h($w['name']) ?>" <?= (int)$w['id']===(int)($_GET['wohnung_id']??0)?'selected':'' ?>><?= h($w['name']) ?></option>
        <?php endwhile; endif; ?>
      </select>

      <select name="status" onchange="this.form.submit()" style="width:140px; height:44px;">
        <option value="">— Status —</option>
        <?php foreach(['offen','in Bearbeitung','erledigt','archiviert','wartend'] as $st): ?>
          <option value="<?= h($st) ?>" <?= $selectedStatus===$st?'selected':'' ?>><?= h($st) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="vorgangsart_id" onchange="this.form.submit()" style="width:160px; height:44px; border-color:#818cf8; background:#f5f3ff; font-weight:600;">
        <option value="">— Vorgangsart —</option>
        <?php foreach(vorgangsarten_all($mysqli) as $va): ?>
          <option value="<?= (int)$va['id'] ?>" <?= (int)($_GET['vorgangsart_id']??0)===$va['id']?'selected':'' ?>>
            <?= h(($va['icon'] ? $va['icon'].' ' : '').$va['name']) ?>
          </option>
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
  </div> <!-- .smart-card Ende -->

  <div class="table-container">
    <table class="table" id="pendenzenTable">
      <thead>
        <tr>
          <?php foreach ($cols as $idx => $c):
            $thStyle = css_width($colWidths[$c] ?? '');
            $field = $c;
            if($c==='kategorie_name') $field='kategorie_id';
            elseif($c==='unterkategorie_name') $field='unterkategorie_id';
            elseif($c==='projekt_name') $field='projekt_id';
            elseif($c==='wohnung_name') $field='wohnung_id';
            elseif(in_array($c, ['bilder','anhaenge','erstes_bild','cover'])) $field='';
            $type = 'text';
            if($c === 'enddatum' || $c === 'startdatum') $type = 'date';
            if($c === 'wichtigkeit') $type = 'number';
            if($c === 'tageszeit') $type = 'select';
          ?>
            <th class="col-<?= h($c) ?>"<?= $thStyle ?> data-field="<?= h($field) ?>" data-type="<?= h($type) ?>">
              <?= h($labels[$c] ?? $c) ?>
              <div class="resizer"></div>
            </th>

          <?php endforeach; ?>
          <th style="width:100px;">Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $num = (!$rsPendenzen) ? 0 : $rsPendenzen->num_rows;
        if ($num === 0): ?>
          <tr><td colspan="<?= count($cols) + 1 ?>" style="padding:60px; text-align:center; color:#64748b;">
            <div style="font-size:24px; margin-bottom:10px;">🔍</div>
            Keine Pendenzen gefunden (Filter aktiv?).
          </td></tr>
        <?php else: while ($row = $rsPendenzen->fetch_assoc()): ?>
          <tr data-id="<?= (int)$row['id'] ?>">
            <?php foreach ($cols as $c): 
              $dbField = $c;
              if($c==='kategorie_name') $dbField='kategorie_id';
              elseif($c==='unterkategorie_name') $dbField='unterkategorie_id';
              elseif($c==='projekt_name') $dbField='projekt_id';
              elseif($c==='wohnung_name') $dbField='wohnung_id';

              $isEditable = !in_array($c, ['erstes_bild','cover','bilder','anhaenge','balance', 'id']);
              $fieldType = 'text';
              if (str_contains($c, 'datum')) $fieldType = 'date';
              if ($c === 'uhrzeit') $fieldType = 'time';
              if (in_array($c, ['tageszeit','status','projekt_id','objekt_id','wohnung_id','kategorie_id','zustaendig_id','vorgangsart_id','raum_id'])) $fieldType = 'select';
            ?>
              <td class="<?= $isEditable ? 'inline-editable' : '' ?>" 
                  data-label="<?= h($labels[$c] ?? $c) ?>"
                  data-field="<?= h($dbField === 'art_name' ? 'vorgangsart_id' : ($dbField === 'raum_name' ? 'raum_id' : $dbField)) ?>" 
                  data-type="<?= h($fieldType) ?>"
                  data-id="<?= (int)$row['id'] ?>"
                  data-value="<?= h($row[$dbField] ?? '') ?>">
                <?php
                  switch($c) {
                    case 'erstes_bild':
                    case 'cover':
                      $p = trim((string)$row['erstes_bild']);
                      $src = $p ? (str_starts_with($p,'http') ? $p : $PREFIX . ltrim($p,'/')) : '';
                      echo $src ? '<img class="thumb-mini" src="'.h($src).'" alt="" onclick="openLightbox(\''.h($src).'\')">' : '—';

                      break;
                    case 'status':
                      $st = $row['status'];
                      $bg = ($st==='offen'?'#fee2e2':($st==='erledigt'?'#dcfce7':'#dbeafe'));
                      $fg = ($st==='offen'?'#991b1b':($st==='erledigt'?'#166534':'#1e40af'));
                      echo '<span class="status-chip" onclick="cycleStatus('.(int)$row['id'].',\''.h($st).'\')" style="background:'.$bg.'; color:'.$fg.';">'.h($st).'</span>';
                      break;
                    case 'wichtigkeit':
                      $prio = (int)$row['wichtigkeit'];
                      echo '<div class="star-rating" data-id="'.(int)$row['id'].'">';
                      for($i=1;$i<=5;$i++) {
                        $clr = $i <= $prio ? '#f59e0b' : '#cbd5e1';
                        echo '<span onclick="updatePrio('.(int)$row['id'].','.$i.')" style="color:'.$clr.';">★</span>';
                      }
                      echo '</div>';
                      break;
                    case 'titel':
                      echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
                      echo '<span style="font-weight:700; color:#0f172a;">' . h($row['titel']) . '</span>';
                      echo '<a href="pendenz_show.php?id='.(int)$row['id'].'" class="btn-xxs" title="Anzeigen" style="margin-left:8px; opacity:0.5; text-decoration:none;">↗️</a>';
                      echo '</div>';
                      // Nur anzeigen wenn keine eigene Spalte dafür da ist
                      if (!in_array('kurzbeschreibung', $cols) && !empty($row['kurzbeschreibung'])) {
                          echo '<div style="color:#64748b; font-size:10px; opacity:0.8;">'.h($row['kurzbeschreibung']).'</div>';
                      }
                      break;
                    case 'startdatum':
                    case 'enddatum':
                      $val = trim((string)($row[$c] ?? ''));
                      if (!$val || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') {
                          echo '—';
                      } else {
                          $ts = strtotime($val);
                          echo ($ts > 0 ? date('d.m.y', $ts) : '—');
                      }
                      break;
                    case 'bilder': echo (int)($row['bilder_count'] ?? 0); break;
                    case 'anhaenge': echo (int)($row['anhaenge_count'] ?? 0); break;
                    case 'zustaendig_id':
                      echo h($row['zustaendig_name'] ?? ($row['zustaendig_id'] ? '#'.$row['zustaendig_id'] : '—'));
                      break;
                    case 'fs_rel_path':
                      $wid = (int)$row['wohnung_id'];
                      if($wid>0){
                          $pInfo = fs_get_entity_path($mysqli,'wohnung',$wid);
                          if($pInfo['rel']) echo '<a href="files.php?projekt_id='.(int)$row['projekt_id'].'&path='.rawurlencode($pInfo['rel']).'" class="btn-xxs">📂</a>';
                          else echo '—';
                      } else echo '—';
                      break;
                    case 'dauer':
                      $val = trim((string)($row['dauer'] ?? ''));
                      if (is_numeric($val)) echo h($val) . ' Tage';
                      else echo h($val ?: '—');
                      break;
                    default:
                      echo h($row[$c] ?? '—');
                      break;
                  }
                ?>
              </td>
            <?php endforeach; ?>
            <td style="display:flex; gap:6px; align-items:center;">
              <a class="btn-xxs" href="pendenzen.php?edit=<?= (int)$row['id'] ?>" title="Bearbeiten">✏️</a>
              <button class="btn-xxs btn-danger-light" type="button" onclick="window.trashPendenz(<?= (int)$row['id'] ?>)" title="Löschen">🗑️</button>
            </td>
          </tr>
        <?php endwhile; endif; ?>
        <tr id="inlineNewRow" style="background:#f8fafc; border-top:2px solid #e2e8f0;">
          <?php foreach ($cols as $c): ?>
            <td>
              <?= inline_input_for_col($c, $labels, []) ?>
              <?php if($c === 'titel' || $c === 'kurzbeschreibung'): ?>
                <div class="quick-choices" data-field="<?= h($c) ?>"></div>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td>
             <div style="display:flex; gap:5px; align-items:center;">
               <select id="inline_project_id" style="max-width:120px; font-size:12px;" onchange="updateProjectContext(this.value, true)">
                 <option value="">Projekt…</option>
                 <?php foreach($projects as $p): ?>
                   <option value="<?= (int)$p['id'] ?>" <?= $p['id']==($pidInit ?: $selectedPid)?'selected':'' ?>><?= h($p['name']) ?></option>
                 <?php endforeach; ?>
               </select>
               <select id="inline_objekt_id" style="max-width:120px; font-size:12px;" onchange="syncFolderPathInline(this.closest('tr'))">
                 <option value="">Haus…</option>
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

    case 'dauer':
      return '<input type="text" name="dauer" class="date-calc-trigger" placeholder="Dauer (z.B. 2w)" style="width:100%; border-radius:8px;">';

    case 'startdatum':
      return '<input type="date" name="startdatum" class="date-calc-trigger" style="width:100%; border-radius:8px;">';

    case 'enddatum':
      return '<input type="date" name="enddatum" class="date-calc-trigger" style="width:100%; border-radius:8px;">';

    case 'fs_rel_path':
      return '<input type="text" name="fs_rel_path" placeholder="Ordner-Pfad" style="width:100%; border-radius:8px; font-family:monospace; font-size:11px;">';

    case 'kategorie_name':
    case 'kategorie_id':
      return '<select name="kategorie_id" class="kategorie-select" style="width:100%; border-radius:8px;"><option value="">Kategorie…</option></select>';

    case 'wohnung_name':
    case 'wohnung_id':
      return '<select name="wohnung_id" class="wohnung-select" style="width:100%; border-radius:8px;" onchange="updateRoomsInline(this)"><option value="">Einheit / Mietsache…</option></select>';

    case 'art_name':
    case 'vorgangsart_id':
      return '<select name="vorgangsart_id" class="art-select" style="width:100%; border-radius:8px;"><option value="">Art…</option></select>';

    case 'raum_name':
    case 'raum_id':
      return '<select name="raum_id" class="raum-select" style="width:100%; border-radius:8px;"><option value="">Raum…</option></select>';

    case 'bilder':
      return '<input type="file" name="bilder[]" multiple accept="image/*" style="width:100%;">';

    case 'anhaenge':
      return '<input type="file" name="anhaenge[]" multiple accept=".pdf,.zip,application/pdf,application/zip,audio/*,video/*" style="width:100%;">';

    case 'cover':
    case 'erstes_bild':
      return '&nbsp;';

    case 'objekt_id':
    case 'objekt_name':
      return '<select name="objekt_id" class="objekt-select" style="width:100%; border-radius:8px;" onchange="filterUnitsInline(this)"><option value="">Haus…</option></select>';

    default:
      return '<input type="text" name="'.h($c).'" placeholder="'.h($labels[$c] ?? $c).'" style="width:100%;">';
  }
}
?>


<!-- Inline Section removed from here as it is now inside Tbody -->
	  
	  
	  
	  
	  
    <?php
  // dieselben $limit/$offset wie oben verwenden
  $limit  = (int)($_GET['limit']  ?? 100);
  $offset = (int)($_GET['offset'] ?? 0);
  $prev = max(0, $offset - $limit);
  $next = $offset + $limit;
?>
<div class="pager" style="display:flex;gap:10px;justify-content:center;margin:20px 0;">
  <a class="btn" href="<?= qs(['offset'=>$prev,'limit'=>$limit]) ?>">« Zurück</a>
  <a class="btn" href="<?= qs(['offset'=>$next,'limit'=>$limit]) ?>">Weiter »</a>
</div>

  </div> <!-- .sdash-wrap Ende -->
</div> <!-- .container-fluid Ende -->

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

<!-- Image Lightbox Modal -->
<div id="imageLightbox" class="image-modal" onclick="this.style.display='none'">
    <img id="lightboxImg" src="" alt="Vollbild">
</div>

<!-- Floating Action Button for Quick Capture -->
<button class="fab" id="fabBtn" title="Schnell-Erfassung" onclick="toggleQuickDrawer()">+</button>

<!-- Quick Capture Drawer -->
<div class="quick-drawer" id="quickDrawer">
    <div class="quick-drawer-header">
        <h2>Schnell-Erfassung</h2>
        <button class="quick-drawer-close" onclick="toggleQuickDrawer()">✕</button>
    </div>

    <div id="qMsg" style="margin-bottom:15px; font-weight:bold;"></div>

    <div class="quick-field">
        <label>Foto hinzufügen</label>
        <div class="quick-photo-zone" onclick="document.getElementById('qFiles').click()">
            <div style="font-size:32px;">📷</div>
            <div style="font-size:12px; color:#64748b; margin-top:8px;">Klicken zum Aufnehmen / Hochladen</div>
        </div>
        <input type="file" id="qFiles" multiple accept="image/*" style="display:none;" onchange="handleQPhotos(this)">
        <div id="qPhotoPreview" class="quick-photo-preview"></div>
    </div>

    <div class="quick-field">
        <label>Kategorie</label>
        <div class="quick-cat-grid" id="qCatGrid">
            <?php 
               $icons = ["1"=>"🖌️", "2"=>"🔌", "3"=>"🪚", "4"=>"🚰", "5"=>"🧹", "6"=>"🏗️"]; // Demo Icons
               foreach($cats as $c): ?>
                <div class="quick-cat-btn" onclick="selectQCat(<?= (int)$c['id'] ?>, this)">
                    <div class="icon"><?= $icons[$c['id']] ?? '🏷️' ?></div>
                    <span><?= h($c['name']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <input type="hidden" id="qCatId">
    </div>

    <div class="quick-field">
        <label>Titel / Mangel</label>
        <input type="text" id="qTitle" class="quick-input" placeholder="Wand beschädigt, Fenster klemmt...">
        <div class="quick-choices" data-field="qTitle" id="qChoices"></div>
    </div>

    <div class="quick-field">
        <label>Kontext</label>
        <div style="font-size:12px; color:#64748b; background:#f1f5f9; padding:10px; border-radius:8px;">
            📍 <span id="qContextText">Wird geladen...</span>
        </div>
    </div>

    <button id="qSaveBtn" class="quick-save-btn" onclick="saveQuickPendenz()">🚀 SICHERN</button>
</div>



  </div> <!-- .sdash-wrap Ende -->
</div> <!-- .container-fluid Ende -->

<script>
// --- GLOBAL STATE ---
window.globalOptions = {
    projects: <?= json_encode($projects ?? []) ?>,
    categories: <?= json_encode($cats ?? []) ?>,
    status: ["offen", "in Bearbeitung", "erledigt", "archiviert", "wartend"],
    tageszeit: ["", "Vormittag", "Mittag", "Nachmittag", "Abend", "Nacht"],
    projekt_id: [],
    objekt_id: [],
    wohnung_id: [],
    kategorie_id: [],
    subkategorie_id: [],
    zustaendig_id: []
};
window.layerQuickChoices = <?= json_encode($layerQuickChoices ?? '') ?>;

// --- GLOBAL DELETE (Funktion in den Head geschoben) ---
(function() {
    // Populate dropdown options into global state for the inline editor
    const sync = (name) => {
        document.querySelectorAll(`select[name="${name}"], select[id="filter_${name}"]`).forEach(sel => {
            Array.from(sel.options).forEach(o => {
                if (o.value && !window.globalOptions[name].find(x => String(x.id) === String(o.value))) {
                    window.globalOptions[name].push({ id: o.value, name: o.textContent.trim() });
                }
            });
        });
    };
    ['projekt_id', 'objekt_id', 'wohnung_id', 'kategorie_id', 'zustaendig_id'].forEach(sync);

    // Scroll persistence
    const p = new URL(location.href).searchParams.get('scroll');
    if (p && +p > 0) window.scrollTo(0, +p);

    document.querySelectorAll('form[method="get"]').forEach(f => {
        f.addEventListener('submit', () => {
            let hid = f.querySelector('input[name="scroll"]');
            if (!hid) { hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'scroll'; f.appendChild(hid); }
            hid.value = String(window.scrollY);
        });
    });

    // Project Context Auto-Trigger with Inheritance
    const initialPid = <?= (int)$pidInit ?>;
    const initialOid = <?= (int)$oidInit ?>;
    const initialWid = <?= (int)$widInit ?>;
    const initialKid = <?= (int)$cat_id ?>;

    const initialRid = <?= (int)($editPendenz['raum_id'] ?? 0) ?>;

    const fpid = document.getElementById('filter_projekt_id')?.value;
    
    if (initialPid) {
        // Both for the main form and the inline row
        updateProjectContext(initialPid, false, false, initialOid, initialWid, initialKid, initialRid);
        if (document.getElementById('inline_project_id')) {
            updateProjectContext(initialPid, true, false, initialOid, initialWid, initialKid, initialRid);
        }
    }
    if (fpid) updateProjectContext(fpid, false, true);


    // Initial Column Resizing Setup
    initResizers();
})();

// --- LIGHTBOX ---
function openLightbox(src) {
    const lb = document.getElementById('imageLightbox');
    const img = document.getElementById('lightboxImg');
    if (lb && img) {
        img.src = src;
        lb.style.display = 'flex';
    }
}

// --- COLUMN RESIZING ---
function initResizers() {
    const table = document.getElementById('pendenzenTable');
    if (!table) return;
    const cols = table.querySelectorAll('th');
    cols.forEach(col => {
        const resizer = col.querySelector('.resizer');
        if (!resizer) return;
        
        let startX, startWidth;

        resizer.addEventListener('mousedown', e => {
            startX = e.pageX;
            startWidth = col.offsetWidth;
            
            const onMouseMove = e => {
                const width = startWidth + (e.pageX - startX);
                col.style.width = width + 'px';
            };
            
            const onMouseUp = () => {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
                saveColWidths();
            };
            
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });
    });
}

async function saveColWidths() {
    const table = document.getElementById('pendenzenTable');
    const ths = table.querySelectorAll('thead th[data-field]');
    const widths = {};
    ths.forEach(th => {
        const field = th.dataset.field || th.classList[0].replace('col-','');
        widths[field] = th.style.width;
    });
    await fetch('../api/pendenzen_save_widths.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ widths })
    });
}

// --- QUICK CAPTURE LOGIC ---

function toggleQuickDrawer() {
    const dr = document.getElementById('quickDrawer');
    dr.classList.toggle('active');
    if (dr.classList.contains('active')) {
        updateQContext();
        document.getElementById('qTitle').focus();
    }
}

function updateQContext() {
    const pidSelect = document.getElementById('projekt_id');
    const widSelect = document.querySelector('.wohnung-select');
    const pext = (pidSelect?.options[pidSelect.selectedIndex]?.text || '—');
    const wext = (widSelect?.options[widSelect.selectedIndex]?.text || 'Alle');
    document.getElementById('qContextText').textContent = `${pext} > ${wext}`;
}

function selectQCat(id, btn) {
    document.querySelectorAll('.quick-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('qCatId').value = id;
    
    // Trigger Quick Choices for the drawer as well
    const container = document.getElementById('qChoices');
    container.innerHTML = '';
    
    // Reuse the existing updateQuickChoices logic but target the drawer
    updateQuickChoices(id);
    
    // Copy the choices to the drawer's container
    setTimeout(() => {
        const globalChoices = document.querySelector('#inlineNewRow .quick-choices');
        if (globalChoices) {
            Array.from(globalChoices.children).forEach(pill => {
                const clone = pill.cloneNode(true);
                clone.onclick = () => {
                    document.getElementById('qTitle').value = pill.textContent;
                };
                container.appendChild(clone);
            });
        }
    }, 50);
}

function handleQPhotos(input) {
    const preview = document.getElementById('qPhotoPreview');
    preview.innerHTML = '';
    Array.from(input.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.src = e.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}

async function saveQuickPendenz() {
    const btn = document.getElementById('qSaveBtn');
    const msg = document.getElementById('qMsg');
    const pid = document.getElementById('projekt_id')?.value || document.getElementById('inline_project_id')?.value;
    const wid = document.querySelector('.wohnung-select')?.value;
    const cid = document.getElementById('qCatId').value;
    const title = document.getElementById('qTitle').value;
    const files = document.getElementById('qFiles').files;

    if (!pid || !title) { alert("Bitte Projekt und Titel angeben"); return; }

    btn.disabled = true;
    btn.textContent = "⏳ Speichern...";
    msg.textContent = "";

    const fd = new FormData();
    fd.append('inline_new', '1');
    fd.append('projekt_id', pid);
    const oid = document.getElementById('objekt_id')?.value || document.getElementById('inline_objekt_id')?.value;
    if (oid) fd.append('objekt_id', oid);
    fd.append('wohnung_id', wid);
    fd.append('kategorie_id', cid);
    fd.append('titel', title);
    Array.from(files).forEach(f => fd.append('bilder[]', f));

    try {
        const res = await fetch('pendenzen.php', { method: 'POST', body: fd });
        const js = await res.json();
        if (js.ok) {
            msg.style.color = 'green';
            msg.textContent = "✅ Erfolgreich gespeichert!";
            setTimeout(() => location.reload(), 1000);
        } else {
            msg.style.color = 'red';
            msg.textContent = "❌ Fehler: " + (js.error || "Server-Error");
            btn.disabled = false;
            btn.textContent = "🚀 SICHERN";
        }
    } catch (e) {
        msg.textContent = "❌ Verbindungsproblem";
        btn.disabled = false;
    }
}



// --- UI HELPERS ---
window.toggleForm = () => {
    const fs = document.getElementById('formSection');
    const btn = document.getElementById('btnToggleForm');
    if (!fs || !btn) return;
    if (fs.style.display === 'none') {
        fs.style.display = 'block';
        btn.textContent = '✖ Schließen';
        btn.style.background = '#f43f5e';
        syncFolderPath(); // Ensure path is visible immediately
        fs.scrollIntoView({ behavior: 'smooth' });
    } else {
        fs.style.display = 'none';
        btn.textContent = '➕ Neue Pendenz';
        btn.style.background = '';
    }
};

// --- DATE & TIME CALCULATIONS (WORKDAYS ONLY) ---
const isWorkDay = (d) => d.getDay() !== 0 && d.getDay() !== 6;

const addWorkDays = (start, days) => {
    let d = new Date(start);
    let count = 0;
    while (count < days) {
        d.setDate(d.getDate() + 1);
        if (isWorkDay(d)) count++;
    }
    return d;
};

const getWorkDaysDiff = (start, end) => {
    if (end < start) return 0;
    let d = new Date(start);
    let count = 0;
    while (d < end) {
        d.setDate(d.getDate() + 1);
        if (isWorkDay(d)) count++;
    }
    return count;
};

const subWorkDays = (end, days) => {
    let d = new Date(end);
    let count = 0;
    while (count < days) {
        d.setDate(d.getDate() - 1);
        if (isWorkDay(d)) count++;
    }
    return d;
};

const parseDuration = (val) => {
    if (!val) return 0;
    const num = parseFloat(val.replace(',', '.'));
    if (isNaN(num)) return 0;
    const suffix = val.replace(/[0-9., ]/g, '').toLowerCase();
    
    // Wochen (Faktor 5 Arbeitstage)
    if (['w','wo','woc','woch'].includes(suffix)) return num * 5;
    // Stunden (Faktor 1/8 Tag)
    if (['h','s','st','stu','stun','sund'].includes(suffix)) return num / 8;
    // Tage (Faktor 1)
    return num;
};

const refreshDateCalculations = (row, sourceField = null) => {
    const getEl = (f) => row.querySelector(`[name="${f}"], [data-field="${f}"]`);
    const startIn = getEl('startdatum');
    const endIn   = row.querySelector('[name="enddatum"], td[data-field="enddatum"]'); 
    const durIn   = getEl('dauer');
    if (!startIn || !endIn || !durIn) return;

    const getVal = (el) => {
        if (el.tagName === 'INPUT' || el.tagName === 'SELECT') return el.value;
        return el.dataset.value || el.innerText;
    };
    const setVal = (el, v) => {
        if (el.tagName === 'INPUT') {
            el.value = v;
            el.dispatchEvent(new Event('change', {bubbles: true})); 
        } else {
            el.dataset.value = v;
            if (v && v.includes('-') && v.length === 10) {
                const parts = v.split('-');
                el.innerText = `${parts[2]}.${parts[1]}.${parts[0].slice(-2)}`;
            } else if (v !== null && !isNaN(parseFloat(v))) {
                const d = parseFloat(v);
                el.innerText = (Number.isInteger(d) ? d : d.toFixed(1)) + ' Tage';
            } else {
                el.innerText = v || '—';
            }
        }
    };

    const startVal = getVal(startIn);
    const endVal   = getVal(endIn);
    const durVal   = getVal(durIn);

    const start = startVal ? new Date(startVal) : null;
    const end   = endVal ? new Date(endVal) : null;
    const dur   = parseDuration(durVal);

    if (sourceField === 'enddatum' && start && end) {
        // ZWANG: Wenn Ende geändert -> Dauer berechnen
        const diff = getWorkDaysDiff(start, end);
        setVal(durIn, diff + " Tage");
    } else if (start && dur > 0) {
        // ZWANG: Wenn Start/Dauer geändert (oder initial) -> Ende berechnen
        const res = addWorkDays(start, dur);
        setVal(endIn, res.toISOString().split('T')[0]);
        // Dauer formatieren falls nackt
        if (!durVal.includes('Tage')) setVal(durIn, dur + " Tage");
    } else if (end && dur > 0 && !start) {
        // Fallback: Ende + Dauer -> Start
        const res = subWorkDays(end, dur);
        setVal(startIn, res.toISOString().split('T')[0]);
        if (!durVal.includes('Tage')) setVal(durIn, dur + " Tage");
    }
};

document.addEventListener('input', e => {
    const row = e.target.closest('form') || e.target.closest('tr');
    if (!row) return;

    // Live Date Calculation
    if (e.target.classList.contains('date-calc-trigger')) {
        refreshDateCalculations(row, e.target.name || e.target.dataset.field);
    }

    // Live Time/Tageszeit Recognition
    if (e.target.classList.contains('time-calc-trigger')) {
        const timeVal = e.target.value;
        const tzSel = row.querySelector('[name="tageszeit"]');
        if (timeVal && tzSel) {
            const hour = parseInt(timeVal.split(':')[0]);
            let tz = '';
            if (hour >= 5 && hour < 9) tz = 'Morgens';
            else if (hour >= 9 && hour < 12) tz = 'Vormittag';
            else if (hour >= 12 && hour < 14) tz = 'Mittag';
            else if (hour >= 14 && hour < 18) tz = 'Nachmittag';
            else if (hour >= 18 && hour < 22) tz = 'Abend';
            else tz = 'Nacht';
            if (tz) tzSel.value = tz;
        }
    }
});

// --- DURATION AUTO-FORMATTER ---
const formatDauerField = (el) => {
    if (!el.value) return;
    const days = parseDuration(el.value);
    if (days >= 0) {
        const formatted = Number.isInteger(days) ? days : days.toFixed(1);
        el.value = formatted + ' Tage';
    }
};

document.addEventListener('change', e => {
    if (e.target.name === 'dauer' && e.target.value) {
        formatDauerField(e.target);
    }
});

// Format existing durations on load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[name="dauer"]').forEach(formatDauerField);
    
    // Trigger auto-calculations for existing data
    document.querySelectorAll('form, tr').forEach(row => {
        if (row.querySelector('.date-calc-trigger')) refreshDateCalculations(row);
    });
});

// --- PROJECT & FOLDER CONTEXT ---
async function updateProjectContext(pid, onlyInline = false, isFilter = false, defOid = 0, defWid = 0, defKid = 0, defRid = 0) {
    if (!pid) return;
    const oid = defOid || document.getElementById(isFilter ? 'filter_objekt_id' : 'objekt_id')?.value || 0;
    try {
        const res = await fetch(`pendenzen.php?action=context&projekt_id=${pid}&objekt_id=${oid}`);
        const js = await res.json();
        if (!js.ok) return;

        // Update Apartments (Internal data but visually filtered by Object later)
        const wSelectors = onlyInline ? ['.wohnung-select'] : [isFilter ? '#filter_wohnung_id' : '#wohnung_id'];
        wSelectors.forEach(query => {
            document.querySelectorAll(query).forEach(sel => {
                const cur = sel.value || defWid;
                sel.innerHTML = isFilter ? '<option value="">— Alle Einheiten —</option>' : '<option value="" data-path="">— keine / alle —</option>';
                (js.apartments || []).forEach(a => {
                    const opt = new Option(a.name, a.id);
                    opt.dataset.path = a.name;
                    opt.dataset.objektId = a.objekt_id;
                    if (String(a.id) === String(cur)) opt.selected = true;
                    sel.add(opt);
                });
            });
        });

        // If we have a default unit, load its rooms
        if (defWid && !onlyInline && !isFilter) {
            updateRooms(defWid, defRid);
        }

        // Update Objects
        if (!onlyInline) {
            const oSel = document.getElementById(isFilter ? 'filter_objekt_id' : 'objekt_id');
            if (oSel) {
                const cur = oSel.value || defOid;
                oSel.innerHTML = isFilter ? '<option value="">— Alle Objekte —</option>' : '<option value="">— wählen —</option>';
                (js.objects || []).forEach(o => {
                    const opt = new Option(o.name, o.id);
                    opt.dataset.path = o.folder_name || o.name;
                    if (String(o.id) === String(cur)) opt.selected = true;
                    oSel.add(opt);
                });
                
                // Auto-select if only one object
                if (js.objects && js.objects.length === 1 && !isFilter && !cur) {
                    oSel.selectedIndex = 1;
                    filterUnitsByObject(oSel.value);
                    syncFolderPath(); 
                }
            }
        }

        // Update Inline Object Select as well
        if (onlyInline) {
            const ioSel = document.getElementById('inline_objekt_id');
            if (ioSel) {
                ioSel.innerHTML = '<option value="">Haus…</option>';
                (js.objects || []).forEach(o => {
                    const opt = new Option(o.name, o.id);
                    opt.dataset.path = o.folder_name || o.name;
                    ioSel.add(opt);
                });
            }
        }

        // Update Categories
        const kSelectors = onlyInline ? ['.kategorie-select'] : ['#kategorie_id'];
        kSelectors.forEach(query => {
            document.querySelectorAll(query).forEach(sel => {
                const cur = sel.value || defKid;
                sel.innerHTML = '<option value="">Kategorie…</option>';
                (js.categories || []).forEach(k => {
                    const opt = new Option(k.name, k.id);
                    if (String(k.id) === String(cur)) opt.selected = true;
                    sel.add(opt);
                });
                if (cur && onlyInline) updateQuickChoices(cur);
            });
        });

        // Update Arts
        const aSelectors = onlyInline ? ['.art-select'] : ['#vorgangsart_id'];
        aSelectors.forEach(query => {
            document.querySelectorAll(query).forEach(sel => {
                const cur = sel.value;
                sel.innerHTML = onlyInline ? '<option value="">Art…</option>' : '<option value="">— bitte wählen —</option>';
                (js.types || []).forEach(t => {
                    const opt = new Option(t.name, t.id);
                    if (String(t.id) === String(cur)) opt.selected = true;
                    sel.add(opt);
                });
            });
        });

        if (!isFilter && !onlyInline) {
            // Update Assignees
            const uSel = document.getElementById('zustaendig_id');
            if (uSel) {
                uSel.innerHTML = '<option value="">— User wählen —</option>';
                (js.members || []).forEach(m => {
                    const opt = new Option(m.name + (m.bkp_code ? ` (BKP ${m.bkp_code})` : ''), m.id);
                    opt.dataset.bkp = m.bkp_code || '';
                    uSel.add(opt);
                });
                // Trigger BKP load if already selected
                uSel.dispatchEvent(new Event('change'));
            }
        }

        if (!isFilter) syncFolderPath();
    } catch (e) { console.error("Context update failed", e); }
}

async function updateRooms(wid, defRid = null) {
    if (!wid) {
        const rSel = document.getElementById('raum_id');
        if (rSel) rSel.innerHTML = '<option value="">— bitte wählen —</option>';
        return;
    }
    try {
        const res = await fetch(`pendenzen.php?action=rooms&wohnung_id=${wid}`);
        const js = await res.json();
        const rSel = document.getElementById('raum_id');
        if (rSel) {
            rSel.innerHTML = '<option value="">— bitte wählen —</option>';
            if (js.ok) (js.items || []).forEach(r => {
                const opt = new Option(r.name, r.id);
                if (String(r.id) === String(defRid)) opt.selected = true;
                rSel.add(opt);
            });
        }
    } catch(e) { console.error("Room update failed", e); }
    syncFolderPath();
}

async function updateRoomsInline(el) {
    const wid = el.value;
    const row = el.closest('tr');
    const rSel = row.querySelector('.raum-select');
    if (!rSel) return;
    if (!wid) {
        rSel.innerHTML = '<option value="">Raum…</option>';
        return;
    }
    try {
        const res = await fetch(`pendenzen.php?action=rooms&wohnung_id=${wid}`);
        const js = await res.json();
        rSel.innerHTML = '<option value="">Raum…</option>';
        if (js.ok) (js.items || []).forEach(r => rSel.add(new Option(r.name, r.id)));
    } catch(e) { console.error("Inline room update failed", e); }
}

function syncFolderPath() {
    const pSel = document.getElementById('projekt_id');
    const oSel = document.getElementById('objekt_id');
    const bSel = document.getElementById('fs_branch');
    const wSel = document.getElementById('wohnung_id');
    const fsIn = document.getElementById('fs_rel_path');
    if (!fsIn) return;

    let path = '';
    const oPath  = oSel?.options[oSel.selectedIndex]?.dataset.path || '';
    const branch = bSel?.value || '10_Mietsache';
    const wPath  = wSel?.options[wSel.selectedIndex]?.dataset.path || '';

    if (oPath) {
        path = oPath + '/' + branch;
        if (branch === '10_Mietsache' && wPath) {
            // Include unit path
            path += '/' + wPath;
        }
    }
    if (fsIn) fsIn.value = path;
}

function filterUnitsInline(el) {
    const oid = el.value;
    const row = el.closest('tr');
    const wSel = row.querySelector('.wohnung-select');
    if (!wSel) return;
    Array.from(wSel.options).forEach(opt => {
        if (opt.value === "") return;
        opt.style.display = (!oid || String(opt.dataset.objektId) === String(oid)) ? '' : 'none';
        if (opt.selected && opt.style.display === 'none') wSel.value = '';
    });
    syncFolderPathInline(row);
}

function syncFolderPathInline(row) {
    // Basic logic for inline row path - usually we fetch the main path via updateRoomsInline if needed
    // or just rely on the main form context if that's what's active.
    // For now, ensure some visual feedback if possible, but the main goal is data persistence.
}

function toggleUnitSelect(branch) {
    const wrap = document.getElementById('unit_select_wrap');
    if (!wrap) return;
    if (branch === '10_Mietsache') {
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
        const wSel = document.getElementById('wohnung_id');
        if (wSel) wSel.value = ''; // Reset unit if not in Mietsache
    }
}

document.getElementById('projekt_id')?.addEventListener('change', async e => { 
    await updateProjectContext(e.target.value); 
    // Trigger Object filter & path sync
    document.getElementById('objekt_id')?.dispatchEvent(new Event('change'));
});

document.getElementById('objekt_id')?.addEventListener('change', (e) => {
    filterUnitsByObject(e.target.value);
    syncFolderPath();
});

// Cascading for filters
document.getElementById('filter_projekt_id')?.addEventListener('change', async e => {
    if (!e.target.value) return; // Standard "Reload on Change" still triggers if this doesn't run
    await updateProjectContext(e.target.value, false, true);
});

document.getElementById('filter_objekt_id')?.addEventListener('change', e => {
    // Filter the unit select in the filter bar
    const wSel = document.getElementById('filter_wohnung_id');
    if (!wSel) return;
    const oid = e.target.value;
    Array.from(wSel.options).forEach(opt => {
        if (opt.value === "") return;
        const pOid = opt.dataset.objektId;
        opt.style.display = (!oid || String(pOid) === String(oid)) ? '' : 'none';
        if (opt.selected && opt.style.display === 'none') wSel.value = '';
    });
});

document.getElementById('wohnung_id')?.addEventListener('change', (e) => {
    updateRooms(e.target.value);
    syncFolderPath();
});

document.getElementById('fs_branch')?.addEventListener('change', () => {
    toggleUnitSelect(document.getElementById('fs_branch').value);
    syncFolderPath();
});

document.getElementById('inline_project_id')?.addEventListener('change', e => {
    updateProjectContext(e.target.value, true);
});

function filterUnitsByObject(oid) {
    const wSel = document.getElementById('wohnung_id');
    if (!wSel) return;
    Array.from(wSel.options).forEach(opt => {
        if (opt.value === "") return; // Default empty
        const pOid = opt.dataset.objektId;
        if (!oid || String(pOid) === String(oid)) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
            if (opt.selected) wSel.value = ''; // Deselect if hidden
        }
    });
}

// Neu: BKP-Auto-Trigger
document.getElementById('zustaendig_id')?.addEventListener('change', e => {
    const opt = e.target.options[e.target.selectedIndex];
    const bkp = opt?.dataset.bkp || '';
    if (bkp) loadBkpCategories(bkp);
});

async function loadBkpCategories(bkpCode) {
    const catSel = document.getElementById('bkp_cat_id');
    const tplSel = document.getElementById('bkp_tpl_id');
    if(!catSel) return;
    
    catSel.innerHTML = '<option value="">— Lädt... —</option>';
    if (tplSel) tplSel.innerHTML = '<option value="">— Vorlage wählen —</option>';
    
    try {
        const res = await fetch(`pendenzen.php?action=bkp_hierarchy&bkp=${bkpCode}`);
        const js = await res.json();
        catSel.innerHTML = '<option value="">— Gewerk wählen —</option>';
        if(js.ok && js.kategorien.length > 0) {
            js.kategorien.forEach(k => {
                const opt = new Option(k.name, k.id);
                catSel.add(opt);
            });
            // Falls nur 1 Kategorie, direkt laden
            if(js.kategorien.length === 1) {
                catSel.selectedIndex = 1;
                loadBkpTemplates(js.kategorien[0].id);
            }
        } else {
            catSel.innerHTML = '<option value="">(Keine BKP-Kategorien)</option>';
        }
    } catch(e) { console.error("BKP Cat load failed", e); }
}

async function loadBkpTemplates(catId) {
    const tplSel = document.getElementById('bkp_tpl_id');
    if(!tplSel) return;
    
    tplSel.innerHTML = '<option value="">— Lädt... —</option>';
    try {
        const res = await fetch(`pendenzen.php?action=bkp_hierarchy&kategorie_id=${catId}`);
        const js = await res.json();
        tplSel.innerHTML = '<option value="">— Vorlage wählen —</option>';
        if(js.ok && js.templates.length > 0) {
            js.templates.forEach(t => {
                const opt = new Option(t.text_vorlage, t.id);
                tplSel.add(opt);
            });
        }
    } catch(e) { console.error("BKP Tpl load failed", e); }
}

function applyBkpTemplate(sel) {
    const text = sel.options[sel.selectedIndex]?.text;
    if(!text || sel.value === "") return;
    
    const titleIn = document.querySelector('#pendenz-form input[name="titel"]');
    if(titleIn) titleIn.value = text;
}

// --- QUICK CHOICES ---
window.updateQuickChoices = (catId) => {
    // 1. Hardcoded Defaults
    const defaults = {
        "1": ["Wand Riss", "Farbe fehlt", "Feuchte Stelle", "Putz lose"],
        "2": ["Steckdose locker", "Kabel fehlt", "Licht defekt", "Sicherung"],
        "3": ["Tür klemmt", "Boden Kratzer", "Sockelleiste lose", "Fenster hakt"],
        "4": ["Siphon undicht", "Hahn tropft", "WC hakt", "Fuge offen"]
    };
    
    let currentChoices = defaults[catId] || [];

    // 2. Layer specific overrides from config
    if (window.layerQuickChoices) {
        const lines = window.layerQuickChoices.split('\n');
        const catName = document.querySelector(`.kategorie-select option[value="${catId}"]`)?.textContent || '';
        
        lines.forEach(line => {
            if (line.includes(':')) {
                const [cPart, tPart] = line.split(':');
                if (cPart.trim().toLowerCase() === catName.trim().toLowerCase()) {
                    currentChoices.push(tPart.trim());
                }
            } else if (!catId) {
                // If no category selected, show all generic choices from layer
                currentChoices.push(line.trim());
            }
        });
    }

    // Remove duplicates
    currentChoices = [...new Set(currentChoices)];

    // PERFORMANCE FIX: Only update the containers in the ACTIVE elements (Inline row or Drawer)
    const activeContainers = [
        document.querySelector('#inlineNewRow .quick-choices'),
        document.getElementById('qChoices')
    ].filter(Boolean);

    activeContainers.forEach(container => {
        container.innerHTML = '';
        if (currentChoices.length) {
            currentChoices.forEach(text => {
                const pill = document.createElement('div');
                pill.className = 'quick-choice-pill';
                pill.textContent = text;
                pill.onclick = () => {
                    const row = container.closest('tr') || document.querySelector('.quick-drawer');
                    const input = row.querySelector(`[name="${container.dataset.field}"]`) || document.getElementById('qTitle');
                    if (input) {
                        input.value = text;
                        input.focus();
                    }
                };
                container.appendChild(pill);
            });
        }
    });
};



document.addEventListener('change', async e => {
    if (e.target.matches('.kategorie-select') || e.target.id === 'kategorie_id') {
        const catId = e.target.value;
        const form = e.target.closest('form') || e.target.closest('tr');
        const sub = form.querySelector('[name="unterkategorie_id"]') || document.getElementById('unterkategorie_id');
        
        if (catId) {
            updateQuickChoices(catId);
            if (sub) {
                sub.disabled = true;
                sub.innerHTML = '<option value="">Lade...</option>';
                try {
                    const res = await fetch(`pendenzen.php?action=subcats&kategorie_id=${catId}`);
                    const js = await res.json();
                    sub.innerHTML = '<option value="">Unterkategorie…</option>';
                    if (js.ok && js.items) {
                        js.items.forEach(i => {
                            const opt = new Option(i.name, i.id);
                            sub.add(opt);
                        });
                        sub.disabled = false;
                    }
                } catch(e) { console.error(e); }
            }
        } else {
            if (sub) { sub.innerHTML = '<option value="">Unterkategorie…</option>'; sub.disabled = true; }
        }
    }
});


// --- MEDIA HELPERS ---
async function setCover(fid, pid) {
    try {
        const res = await fetch(`pendenzen.php?action=media_set_cover&id=${pid}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file_id=${fid}`
        });
        const js = await res.json();
        if (js.ok) {
            document.querySelectorAll(`.btn-cover-star[data-pid="${pid}"]`).forEach(b => {
                b.classList.remove('is-active');
                b.textContent = '☆';
            });
            const active = document.querySelector(`.btn-cover-star[data-fid="${fid}"]`);
            if (active) { active.classList.add('is-active'); active.textContent = '★'; }
            location.reload(); 
        } else alert(js.error);
    } catch (e) { console.error(e); }
}

async function deleteMedia(fid, pid, type) {
    if (!confirm('Datei wirklich löschen?')) return;
    try {
        const res = await fetch(`pendenzen.php?action=media_delete&id=${pid}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file_id=${fid}`
        });
        if ((await res.json()).ok) {
            loadMediaLists('file', 'mediaFiles');
            loadMediaLists('image', 'mediaImages');
        }
    } catch (e) { console.error(e); }
}

window.loadMediaLists = async (type, intoId) => {
    const id = <?= isset($editPendenz) ? (int)$editPendenz['id'] : 0 ?>;
    if (id <= 0) return;
    const into = document.getElementById(intoId);
    if (!into) return;

    const res = await fetch(`pendenzen.php?action=media_list&id=${id}`);
    const js = await res.json();
    if (!js.ok) return;

    const items = (js.items || []).filter(x => type === 'image' ? x.typ === 'image' : x.typ !== 'image');
    if (type === 'image') {
        into.className = 'media-grid-smart';
        into.innerHTML = items.map(x => `
            <div class="media-tile-smart" style="position:relative; width:100px;">
                <img src="<?= h($ASSET) ?>${x.pfad}" style="width:100%; height:80px; object-fit:cover; border-radius:6px;">
                <button type="button" class="btn-tile-delete" onclick="deleteMedia(${x.id}, ${id}, 'image')">✖</button>
                <div style="font-size:10px; text-align:center; margin-top:4px;">
                    <button type="button" class="btn-cover-star ${x.is_cover == 1 ? 'is-active' : ''}" 
                            data-fid="${x.id}" data-pid="${id}" onclick="setCover(${x.id}, ${id})">
                        ${x.is_cover == 1 ? '★' : '☆'}
                    </button>
                    ${h(x.titel || '')}
                </div>
            </div>
        `).join('');
    } else {
        into.innerHTML = items.map(x => `
            <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:6px 10px; border-radius:6px; margin-bottom:4px; border:1px solid #e2e8f0; font-size:12px;">
                <span>📎 ${x.titel || x.pfad.split('/').pop()}</span>
                <button type="button" class="btn btn-danger btn-xxs" onclick="deleteMedia(${x.id}, ${id}, 'file')">Löschen</button>
            </div>
        `).join('');
    }
};

(async () => {
    await window.loadMediaLists('file', 'mediaFiles');
    await window.loadMediaLists('image', 'mediaImages');
})();


// --- UI HELPERS ---
function toggleAdvancedFields(el) {
    const fields = el.nextElementSibling;
    if (fields.style.display === 'none') {
        fields.style.display = 'flex';
        el.querySelector('span').textContent = '➖ Weniger Details';
    } else {
        fields.style.display = 'none';
        el.querySelector('span').textContent = '➕ Erweiterte Details & Notiz';
    }
}

// --- SPREADSHEET INLINE EDITOR ---
(function() {
    let currentInput = null;

    document.addEventListener('click', e => {
        const td = e.target.closest('.inline-editable');
        if (td && !td.querySelector('.inline-editor')) startEditing(td);
    });

    window.startEditing = async (td) => {
        const field = td.dataset.field;
        const id = td.dataset.id;
        const type = td.dataset.type || 'text';
        const oldVal = td.dataset.value || td.textContent.trim();
        const isArea = field.includes('beschreibung') || field === 'notiz';

        let input;
        const opts = window.globalOptions[field];

        if (opts && (field.includes('_id') || field === 'tageszeit' || field === 'status')) {
            input = document.createElement('select');
            input.add(new Option("— leer —", ""));
            opts.forEach(o => {
                const opt = new Option(o.name || o, o.id || o);
                if (String(opt.value) === String(oldVal)) opt.selected = true;
                input.add(opt);
            });
        } else if (isArea) {
            input = document.createElement('textarea');
            input.value = oldVal;
            input.rows = 4;
        } else {
            input = document.createElement('input');
            input.type = type;
            input.value = oldVal;
            input.name = field; // Wichtig für den Trigger
            if (['startdatum','enddatum','dauer'].includes(field)) {
                input.classList.add('date-calc-trigger');
            }
        }

        input.className += ' inline-editor';
        const originalContent = td.innerHTML;

        const finish = async () => {
            if (currentInput !== input) return;
            const newVal = input.value;
            currentInput = null;

            if (newVal === oldVal) { td.innerHTML = originalContent; return; }

            td.innerHTML = '<span style="color:#0ea5e9; font-size:10px; font-weight:800;">SYNC...</span>';
            
            let bodyData = { id, field, value: newVal };
            
            // SPECIAL: Wenn es ein Termin-Feld ist, schicken wir das Trio
            if (['startdatum','enddatum','dauer'].includes(field)) {
                const getEl = (f) => td.closest('tr').querySelector(`[name="${f}"], [data-field="${f}"]`);
                const getVal = (el) => {
                    if (!el) return '';
                    if (el.tagName === 'INPUT' || el.tagName === 'SELECT') return el.value;
                    return el.dataset.value || el.innerText;
                };
                bodyData = {
                    id,
                    updates: {
                        startdatum: getVal(getEl('startdatum')),
                        enddatum:   getVal(getEl('enddatum')),
                        dauer:      getVal(getEl('dauer'))
                    }
                };
            }

            try {
                const res = await fetch('../api/pendenzen_inline_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(bodyData)
                });
                const js = await res.json();
                if (js.ok) {
                    td.innerText = (input.tagName === 'SELECT') ? input.options[input.selectedIndex].text : newVal;
                    td.dataset.value = newVal;
                    td.style.background = '#dcfce7';
                    setTimeout(() => td.style.background = '', 800);
                } else {
                    alert(js.error || "Fehler");
                    td.innerHTML = originalContent;
                }
            } catch (e) { td.innerHTML = originalContent; }
        };

        td.innerHTML = ''; td.appendChild(input); input.focus(); currentInput = input;
        input.onblur = finish;
        input.onkeydown = (ev) => {
            if (ev.key === 'Enter' && !isArea) {
                ev.preventDefault(); input.blur();
                const nextRowTd = td.parentElement.nextElementSibling?.querySelector(`.inline-editable[data-field="${field}"]`);
                if (nextRowTd) setTimeout(() => startEditing(nextRowTd), 50);
            }
            if (ev.key === 'Tab') {
                ev.preventDefault(); input.blur();
                const nextTd = td.nextElementSibling?.closest('.inline-editable') || td.parentElement.nextElementSibling?.querySelector('.inline-editable');
                if (nextTd) setTimeout(() => startEditing(nextTd), 50);
            }
            if (ev.key === 'Escape') { currentInput = null; td.innerHTML = originalContent; }
        };
    };
})();

// --- INLINE CREATE LOGIC ---
document.getElementById('inlineCreateBtn')?.addEventListener('click', async () => {
    const row = document.getElementById('inlineNewRow');
    const pid = document.getElementById('inline_project_id')?.value;
    const oid = document.getElementById('inline_objekt_id')?.value;
    const msg = document.getElementById('inlineMsg');
    if (!pid) { alert("Bitte Projekt wählen"); return; }

    const fd = new FormData();
    fd.append('projekt_id', pid);
    if (oid) fd.append('objekt_id', oid);
    fd.append('inline_new', '1');
    row.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.name) {
            if (el.type === 'file') {
                Array.from(el.files).forEach(f => fd.append(el.name, f));
            } else {
                fd.append(el.name, el.value);
            }
        }
    });

    if (msg) msg.textContent = "⏳ Speichern...";
    try {
        const res = await fetch('pendenzen.php', { method: 'POST', body: fd });
        const js = await res.json();
        if (js.ok) {
            if (msg) { msg.style.color = 'green'; msg.textContent = "✅ Erfolgreich!"; }
            setTimeout(() => location.reload(), 800);
        } else {
            if (msg) { msg.style.color = 'red'; msg.textContent = "❌ " + (js.error || "Fehler"); }
        }
    } catch (e) { if (msg) msg.textContent = "❌ Server-Fehler"; }
});

// --- WIDGET HELPERS ---
window.cycleStatus = async (id, cur) => {
    const states = ["offen", "in Bearbeitung", "erledigt", "archiviert", "wartend"];
    const next = states[(states.indexOf(cur) + 1) % states.length];
    const res = await fetch('../api/pendenzen_inline_save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, field: 'status', value: next })
    });
    if ((await res.json()).ok) location.reload();
};

window.updatePrio = async (id, val) => {
    const res = await fetch('../api/pendenzen_inline_save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, field: 'wichtigkeit', value: val })
    });
    if ((await res.json()).ok) {
        document.querySelectorAll(`.star-rating[data-id="${id}"] span`).forEach((s, i) => {
            s.style.color = (i < val) ? '#f59e0b' : '#cbd5e1';
        });
    }
};

// --- DRAG & DROP REORDER ---
if (<?= $hasSortIndex ? 'true' : 'false' ?>) {
    const tbody = document.querySelector('#pendenzenTable tbody');
    let dragRow = null;

    tbody.addEventListener('dragstart', e => {
        dragRow = e.target.closest('tr');
        if (dragRow) dragRow.classList.add('dragging');
    });
    tbody.addEventListener('dragend', () => dragRow?.classList.remove('dragging'));
    tbody.addEventListener('dragover', e => {
        e.preventDefault();
        const over = e.target.closest('tr');
        if (over && over !== dragRow && dragRow) {
            const box = over.getBoundingClientRect();
            if (e.clientY < box.top + box.height / 2) tbody.insertBefore(dragRow, over);
            else tbody.insertBefore(dragRow, over.nextSibling);
        }
    });

    document.getElementById('reorderSave')?.addEventListener('click', async () => {
        const ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(tr => tr.dataset.id);
        const fd = new FormData();
        ids.forEach(id => fd.append('ids[]', id));
        const res = await fetch('pendenzen.php?action=reorder', { method: 'POST', body: fd });
        if ((await res.json()).ok) alert("Reihenfolge gespeichert!");
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>









