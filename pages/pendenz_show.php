<?php
// pages/pendenz_show.php
if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('memory_limit', '512M');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/functions.php';

$id=(int)($_GET['id'] ?? 0);
if ($id > 0) {
    // Glocke zurücksetzen, falls Admin die Seite aufruft
    if (isset($_SESSION['user_id'])) {
        $mysqli->query("UPDATE pendenzen SET unt_new_input = 0 WHERE id = $id");
    }
}

// --- Selbstheilung für neue Unternehmer-Spalten ---
try {
    $mysqli->query("SELECT unt_new_input FROM pendenzen LIMIT 1");
} catch (Throwable $e) {
    $res = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE 'unt_bemerkung'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE pendenzen ADD unt_bemerkung TEXT DEFAULT NULL");
    }
    $res = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE 'unt_new_input'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE pendenzen ADD unt_new_input TINYINT(1) DEFAULT 0");
    }
}

require_once __DIR__ . '/../includes/media.php';
if ($id<=0) { header('Location: pendenzen.php'); exit; }

// Wir holen die Pendenz zuerst, um zu prüfen, ob sie öffentlich ist
$q = $mysqli->query("
  SELECT p.*, pr.name AS projekt_name, p.erstellt_von AS created_by, p.zustaendig_id AS assignee_id
  FROM pendenzen p
  LEFT JOIN projekte pr ON pr.id=p.projekt_id
  LEFT JOIN wohnungen w ON w.id=p.wohnung_id
  WHERE p.id={$id} LIMIT 1
");
$p=$q?$q->fetch_assoc():null;
if(!$p){ http_response_code(404); exit('Not found'); }

// --- NEU: Wenn Admin reinschaut, Benachrichtigungs-Flag zurücksetzen ---
if (isset($_SESSION['user_id'])) {
    $mysqli->query("UPDATE pendenzen SET unt_new_input = 0 WHERE id = " . (int)$p['id']);
    $p['unt_new_input'] = 0;
}

// Falls nicht öffentlich aktiv, Login erzwingen
$isPublic = ((int)($p['public_enabled']??0) === 1);
if (!$isPublic) {
    require_login();
    if(!can_view_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) { http_response_code(403); exit('Forbidden'); }
}
// Falls öffentlich aber nicht eingeloggt -> wir sind im "Public Mode"
$isLoggedIn = is_logged_in();

$hideEmpty = isset($_GET['hide_empty']) ? (int)$_GET['hide_empty'] : 1;

// Helpers
function fetch_members_by_project(mysqli $db, int $pid){
  $stmt=$db->prepare("
    SELECT DISTINCT b.id,b.name,b.email
    FROM benutzer b
    LEFT JOIN benutzer_projekte bp ON bp.benutzer_id=b.id AND bp.projekt_id=?
    LEFT JOIN team_projekte tp ON tp.projekt_id=?
    LEFT JOIN benutzer_teams bt ON bt.benutzer_id=b.id AND bt.team_id=tp.team_id
    WHERE bp.projekt_id IS NOT NULL OR bt.team_id IS NOT NULL
    ORDER BY b.name
  "); $stmt->bind_param("ii",$pid,$pid); $stmt->execute(); return $stmt->get_result();
}
function fetch_teams_by_project(mysqli $db, int $pid){
  $stmt=$db->prepare("SELECT t.id,t.name FROM team_projekte tp JOIN teams t ON t.id=tp.team_id WHERE tp.projekt_id=? ORDER BY t.name");
  $stmt->bind_param("i",$pid); $stmt->execute(); return $stmt->get_result();
}

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$fmtDate=function($d){ if(!$d) return ''; $ts=strtotime($d); return $ts?date('d.m.Y',$ts):$d; };

$createdUser = get_user_with_company($mysqli, (int)($p['created_by']??0));
$assigneeUser = get_user_with_company($mysqli, (int)($p['assignee_id']??0));

// Konfiguration laden/sane defaults
$cfg = json_decode($p['extra_json'] ?? 'null', true) ?: [];
$view = $cfg['_view'] ?? [];
$view += [
  'layout'        => 'classic',      // classic|compact|gallery
  'font_size'     => 'normal',       // small|normal|large
  'brand_source'  => 'firma_logo',   // firma_logo|user_titelbild|user_profilbild|none
  'brand_from'    => 'ersteller',    // ersteller|zustaendiger
  'hero'          => 'cover',        // cover|first
  'sections'      => ['meta','kurzbeschreibung','langbeschreibung','notiz','anhaenge'],
  'sections_order'=> ['meta','kurzbeschreibung','langbeschreibung','notiz','anhaenge'],
  'meta_fields'   => ['status','wichtigkeit','startdatum','enddatum','erstellt_am','geaendert_am','projekt_name','zustaendig_name','erstellt_von_name'],
  'meta_order'    => ['status','wichtigkeit','startdatum','enddatum','erstellt_am','geaendert_am','projekt_name','zustaendig_name','erstellt_von_name'],
  'vis_mode'      => 'projekt',      // projekt|privat|admin|team|users|public
  'vis_team_id'   => null,
  'vis_user_ids'  => []
];
// Admin-only Flag beachten
$adminOnly = !empty($cfg['_vis_admin_only']);
if ($adminOnly && !(is_superadmin() || is_admin())) { http_response_code(403); exit('Nur für Admins.'); }

// Aktionen (Anzeige/ACL/Public) speichern
$flash="";
try{
  if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_view'){
    if (!can_edit_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) throw new Exception("Keine Berechtigung zum Ändern.");

    // Anzeige
    $layout = in_array($_POST['layout']??'classic',['classic','compact','gallery'],true)?$_POST['layout']:'classic';
    $font   = in_array($_POST['font_size']??'normal',['small','normal','large'],true)?$_POST['font_size']:'normal';
    $hero   = in_array($_POST['hero']??'cover',['cover','first'],true)?$_POST['hero']:'cover';
    $brand_source = in_array($_POST['brand_source']??'firma_logo',['firma_logo','user_titelbild','user_profilbild','none'],true)?$_POST['brand_source']:'firma_logo';
    $brand_from   = in_array($_POST['brand_from']??'ersteller',['ersteller','zustaendiger'],true)?$_POST['brand_from']:'ersteller';

    // Bereiche (Checkbox + Order via hidden)
    $sections_enabled = array_values(array_unique(array_map('strval', $_POST['sections_enabled'] ?? [])));
    $sections_order   = array_values(array_filter(explode(',', $_POST['sections_order'] ?? ''), 'strlen'));
    if(!$sections_order) $sections_order=$view['sections_order'];
    $sections = array_values(array_intersect($sections_order, $sections_enabled)); // Reihenfolge ∩ aktiv

    // Meta-Felder
    $meta_enabled = array_values(array_unique(array_map('strval', $_POST['meta_enabled'] ?? [])));
    $meta_order   = array_values(array_filter(explode(',', $_POST['meta_order'] ?? ''), 'strlen'));
    if(!$meta_order) $meta_order=$view['meta_order'];
    $meta_fields  = array_values(array_intersect($meta_order, $meta_enabled));

    // Zugriff/ACL
    $access = $_POST['access_mode'] ?? $view['vis_mode'];
    $access = in_array($access,['projekt','privat','admin','team','users','public'],true)?$access:'projekt';
    $team_id   = ($_POST['vis_team_id']??'')!=='' ? (int)$_POST['vis_team_id'] : null;
    $user_ids  = array_values(array_filter(array_map('intval', $_POST['vis_user_ids'] ?? []), fn($v)=>$v>0));
    $regen_pub = !empty($_POST['regen_public_token']);

    // Public Schalter - IMMER aktiv gemäß Nutzerwunsch
    $public_enabled = 1;
    $public_token   = $p['public_token'];
    if (!$public_token || $regen_pub) $public_token = bin2hex(random_bytes(16));
    // falls token da aber public_enabled=0, wird er in pendenz_pdf.php/pendenz_public.php trotzdem abgelehnt (für Gäste)

    // Sichtbarkeit + ACL anwenden
    $sichtbarkeit = $p['sichtbarkeit'];
    $cfg['_vis_admin_only'] = ($access==='admin') ? 1 : 0;
    if($access==='projekt'){ $sichtbarkeit='projekt'; $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$id); }
    elseif($access==='privat'){ $sichtbarkeit='privat'; $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$id); }
    elseif($access==='public'){ $sichtbarkeit='projekt'; $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$id); } // intern weiterhin Projekt
    elseif($access==='admin'){  $sichtbarkeit='privat'; $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$id); }
    elseif($access==='team'){
      $sichtbarkeit='custom';
      $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$id);
      if($team_id){
        $q=$mysqli->prepare("SELECT benutzer_id FROM benutzer_teams WHERE team_id=?");
        $q->bind_param("i",$team_id); $q->execute(); $rs=$q->get_result();
        $ins=$mysqli->prepare("INSERT INTO pendenz_acl (pendenz_id,benutzer_id,can_view,can_edit) VALUES (?,?,1,0)");
        while($row=$rs->fetch_assoc()){ $uid=(int)$row['benutzer_id']; $ins->bind_param("ii",$id,$uid); $ins->execute(); }
      }
    } elseif($access==='users'){
      $sichtbarkeit='custom';
      $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$id);
      if($user_ids){
        $ins=$mysqli->prepare("INSERT INTO pendenz_acl (pendenz_id,benutzer_id,can_view,can_edit) VALUES (?,?,1,0)");
        foreach($user_ids as $uid){ $ins->bind_param("ii",$id,$uid); $ins->execute(); }
      }
    }

    // view speichern
    $cfg['_view'] = [
      'layout'=>$layout,'font_size'=>$font,'brand_source'=>$brand_source,'brand_from'=>$brand_from,'hero'=>$hero,
      'sections'=>$sections,'sections_order'=>$sections_order,
      'meta_fields'=>$meta_fields,'meta_order'=>$meta_order,
      'vis_mode'=>$access,'vis_team_id'=>$team_id,'vis_user_ids'=>$user_ids
    ];
    $jx = json_encode($cfg, JSON_UNESCAPED_UNICODE);

    // DB updaten
    $st=$mysqli->prepare("UPDATE pendenzen SET extra_json=?, sichtbarkeit=?, public_enabled=?, public_token=? WHERE id=?");
    $st->bind_param("ssisi",$jx,$sichtbarkeit,$public_enabled,$public_token,$id);
    $st->execute();

    log_action($mysqli,'pendenz',$id,'update_view_acl',['view'=>$cfg['_view'],'sichtbarkeit'=>$sichtbarkeit,'public_enabled'=>$public_enabled]);

    header("Location: pendenz_show.php?id=".$id); exit;
  }

  // Cover setzen
  if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='set_cover'){
    if (!can_edit_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) throw new Exception("Keine Berechtigung.");
    $fid=(int)($_POST['file_id']??0);
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id=".$id);
    $st=$mysqli->prepare("UPDATE pendenz_dateien SET is_cover=1 WHERE id=? AND pendenz_id=?");
    $st->bind_param("ii",$fid,$id); $st->execute();
    header("Location: pendenz_show.php?id=".$id); exit;
  }

} catch(Throwable $e){ $flash="❌ ".$e->getMessage(); }

// Medien
$imgs = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$id} AND typ='image' ORDER BY CASE WHEN titel='Plan-Ausschnitt' THEN 1 ELSE 0 END ASC, is_cover DESC, id ASC");
$files= $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$id} AND typ='file' ORDER BY sort_index IS NULL, sort_index, id");

// Hero
$cover = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$id} AND is_cover=1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$firstImg = ($imgs && $imgs->num_rows) ? $imgs->fetch_assoc() : null; if($imgs) $imgs->data_seek(0);
$heroImg = ($view['hero']==='first') ? ($firstImg ?: $cover) : ($cover ?: $firstImg);

// Branding
$brandUser = ($view['brand_from']==='zustaendiger' ? $assigneeUser : $createdUser) ?: $createdUser ?: $assigneeUser;
$brandPath = null;
if ($view['brand_source']==='firma_logo' && !empty($brandUser['firmenlogo']))     $brandPath = $brandUser['firmenlogo'];
if ($view['brand_source']==='user_titelbild' && !empty($brandUser['titelbild']))   $brandPath = $brandUser['titelbild'];
if ($view['brand_source']==='user_profilbild' && !empty($brandUser['profilbild'])) $brandPath = $brandUser['profilbild'];

// Spalten
$colsRes = $mysqli->query("SHOW COLUMNS FROM pendenzen");
$allCols=[]; while($c=$colsRes->fetch_assoc()) $allCols[]=$c['Field'];
$smart = [
  'projekt_name'=>'Projekt',
  'ordner_path'=>'Ordner',
  'erstellt_von_name'=>'Erstellt von',
  'zustaendig_name'=>'Zuständig',
  'wohnung_name'=>'Wohnung',
  'is_protocol'=>'Protokoll',
  'protocol_type'=>'Protokoll-Typ',
];
$labels = array_merge(array_fill_keys($allCols,''), $smart);
foreach ($labels as $k=>$_) { if(isset($smart[$k])) $labels[$k]=$smart[$k]; else $labels[$k]=ucwords(str_replace(['_','-'],' ',$k)); }

$values = $p;
$values['projekt_name'] = $p['projekt_name'] ?? '';
$values['wohnung_name'] = $p['wohnung_name'] ?? '';
$values['is_protocol']  = !empty($p['is_protocol']) ? 'Ja' : 'Nein';
$values['protocol_type'] = $p['protocol_type'] ?? 'none';
$values['ordner_path'] = '';
if (!empty($p['ordner_id'])) {
  $map=[]; $r=$mysqli->query("SELECT id,name,parent_id FROM pendenz_ordner WHERE projekt_id=".(int)$p['projekt_id']);
  while($row=$r->fetch_assoc()) $map[(int)$row['id']]=$row;
  $x=(int)$p['ordner_id']; $parts=[];
  while($x && isset($map[$x])){ $parts[]=$map[$x]['name']; $x=(int)($map[$x]['parent_id']??0); }
  $values['ordner_path']=implode(' / ', array_reverse($parts));
}
$values['erstellt_von_name'] = $createdUser['name'] ?? '';
$values['zustaendig_name']   = $assigneeUser['name'] ?? '';
$formatValue = function($k,$v) use ($fmtDate){
  if (in_array($k,['startdatum','enddatum','erstellt_am','geaendert_am','created_at','updated_at','deleted_at','confirmation_at','submitted_at','reviewed_at'],true) && $v) {
    return $fmtDate($v);
  }
  if ($k==='status' && $v==='in Bearbeitung') return 'in Bearbeitung';
  return (string)$v;
};

// Plan Info Fetching
$planInfo = null;
if (!empty($p['plan_id'])) {
    $planRes = $mysqli->query("SELECT id, name, datei_pfad FROM projekt_plaene WHERE id=".(int)$p['plan_id']);
    if ($planRes && $planRes->num_rows) {
        $planInfo = $planRes->fetch_assoc();
        // Check if there is a zone for this apartment on this plan
        if (!empty($p['wohnung_id'])) {
            $zoneRes = $mysqli->query("SELECT x_pct, y_pct, width_pct, height_pct FROM plan_zonen WHERE plan_id=".(int)$p['plan_id']." AND wohnung_id=".(int)$p['wohnung_id']);
            if ($zoneRes && $zoneRes->num_rows) {
                $planInfo['zone'] = $zoneRes->fetch_assoc();
            }
        }
    }
}
if ($planInfo && !in_array('plan', $view['sections'])) {
    // Falls Plan da ist, aber nicht in den Sections (weil alte Konfig), temporär hinzufügen für die Anzeige
    $view['sections'][] = 'plan';
    if (!in_array('plan', $view['sections_order'])) {
        array_splice($view['sections_order'], 1, 0, 'plan'); // Nach Meta einfügen
    }
}

// Reihenfolge der Anzeige
$sectionsOrder = $view['sections_order'] ?: ['meta','plan','kurzbeschreibung','langbeschreibung','notiz','bilder','anhaenge'];
$sectionsShow  = array_values(array_intersect($sectionsOrder, $view['sections']));
$metaOrder     = $view['meta_order'] ?: $view['meta_fields'];
$metaShow      = array_values(array_intersect($metaOrder, $view['meta_fields']));

// QR-Code Logik
$PREFIX = site_prefix();
$host = (is_https_request() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$publicUrl = null; $qrUrl=null;
if ((int)($p['public_enabled']??0)===1 && !empty($p['public_token'])) {
  $publicUrl = $host . rtrim($PREFIX, '/') . "/pages/pendenz_public.php?t=" . rawurlencode($p['public_token']);
}
// Immer einen QR-Code erzeugen (wenn kein public link, dann permalink)
$qrData = $publicUrl ?: $host . rtrim($PREFIX, '/') . '/pages/pendenz_show.php?id=' . $id;
$qrUrl = "https://quickchart.io/qr?text=".rawurlencode($qrData)."&size=180";


// Projekt-Kontext (für ACL-UI)
$members = $p['projekt_id'] ? fetch_members_by_project($mysqli,(int)$p['projekt_id']) : null;
$teams   = $p['projekt_id'] ? fetch_teams_by_project($mysqli,(int)$p['projekt_id'])   : null;

// Ausgabe
require_once __DIR__ . '/../includes/header.php';
if ($isLoggedIn) {
    require_once __DIR__ . '/../includes/nav_dispatch.php';
} else {
    echo '<style>.site-header, .app-shell-nav { display:none !important; } .container { margin-top:20px !important; }</style>';
}
?>
<style>
  .grid { display:grid; grid-template-columns:1fr 340px; gap:20px; }
  .hero-img { border:1px solid #ddd; border-radius:10px; overflow:hidden; cursor:pointer; background:#f8fafc; }
  .hero-img img { width:100%; height:420px; object-fit:cover; display:block; transition: transform 0.3s ease; }
  .hero-img:hover img { transform: scale(1.01); }
  .branding-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 15px; }
  .header-brand { display:flex; justify-content:space-between; align-items:flex-end; padding-bottom:15px; margin-bottom:15px; border-bottom:1px solid #e5e7eb; }
  .header-brand-info { font-size:13px; color:#4b5563; line-height:1.4; }
  .header-brand-logo img { max-width:180px; max-height:80px; object-fit:contain; }
  .thumbs { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:8px; margin-top:10px; }
  .thumbs img { width:100%; height:90px; object-fit:cover; border:1px solid #eee; border-radius:6px; cursor:pointer; transition: opacity 0.2s; }
  .thumbs img:hover { opacity:0.8; }
  .meta-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px }
  .meta-item{ padding:8px; border:1px solid #e5e7eb; border-radius:8px; background:#fff }
  
  /* Mobile Optimierung */
  @media (max-width:900px){ 
    .container { max-width: 100% !important; padding: 0 !important; width: 100% !important; margin: 0 !important; }
    .grid { grid-template-columns: 1fr !important; gap: 0 !important; display: flex; flex-direction: column; }
    .hero-img { border-radius: 0; border-left: 0; border-right: 0; }
    .hero-img img { height: auto; max-height: 380px; }
    .meta-grid { grid-template-columns:1fr; } 
    .card, .branding-card { border-radius: 0; border-left: 0; border-right: 0; margin-bottom: 8px; width: 100% !important; box-sizing: border-box; }
    header.hero { border-radius: 0; padding: 15px; }
    .header-brand { padding: 15px; }
    #quick-pendenz-fab { display: none !important; }
    .grid > div:last-child { order: 10; } /* Sidebar nach unten auf mobile */
  }

  .order-list{list-style:none;padding:6px;margin:0;border:1px solid #ddd;border-radius:8px;min-height:42px}
  .order-item{display:flex;align-items:center;gap:8px;padding:6px 8px;margin:4px 0;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:grab}
  .order-item.dragging{opacity:0.5;background:#f0fdf4;border-color:#1abc9c}
  .section-block { transition: all 0.2s ease; }
  .meta-item { transition: all 0.2s ease; }
  .designer-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px; margin: -15px -15px 15px -15px; border-radius: 10px 10px 0 0; }

  /* Lightbox Styles */
  #lightbox-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.95); display: none; z-index: 10000;
    flex-direction: column; align-items: center; justify-content: center;
  }
  #lightbox-img { max-width: 95%; max-height: 85%; object-fit: contain; border-radius: 4px; box-shadow: 0 0 30px rgba(0,0,0,0.5); }
  .lightbox-close { position: absolute; top: 20px; right: 20px; color: #fff; font-size: 40px; cursor: pointer; user-select: none; z-index: 10001; }
  .lightbox-nav { position: absolute; top: 50%; width: 100%; display: flex; justify-content: space-between; padding: 0 20px; transform: translateY(-50%); pointer-events: none; }
  .lightbox-btn { pointer-events: auto; background: rgba(255,255,255,0.1); color: #fff; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 24px; cursor: pointer; user-select: none; transition: background 0.2s; }
  .lightbox-btn:hover { background: rgba(255,255,255,0.2); }
  .lightbox-caption { color: #ccc; margin-top: 15px; font-size: 14px; text-align: center; max-width: 80%; }
</style>

<div class="container">
  <!-- NEW TOP HEADER BRANDING -->
  <div class="header-brand">
    <div class="header-brand-info">
      <?php if($brandUser): ?>
        <?php if(!empty($brandUser['firmenname'])): ?><strong style="font-size:18px; color:#1e293b;"><?= h($brandUser['firmenname']) ?></strong><br><?php endif; ?>
        <?php if(!empty($brandUser['strasse'])): ?><?= h($brandUser['strasse']) ?><br><?php endif; ?>
        <?php if(!empty($brandUser['plz']) || !empty($brandUser['ort'])): ?><?= h(($brandUser['plz']??'').' '.($brandUser['ort']??'')) ?><br><?php endif; ?>
        <div style="margin-top:4px; font-size:12px; color:#64748b;">
          <?php if(!empty($brandUser['telefon'])): ?>📞 <?= h($brandUser['telefon']) ?> &nbsp;<?php endif; ?>
          <?php if(!empty($brandUser['email'])): ?>✉️ <?= h($brandUser['email']) ?><?php endif; ?>
        </div>
      <?php else: ?>
        <span style="color:#94a3b8; font-style:italic;">Kein Branding konfiguriert</span>
      <?php endif; ?>
    </div>
    <div class="header-brand-logo">
      <?php if($brandPath && is_file(__DIR__.'/../'.ltrim($brandPath,'/'))): ?>
        <img src="<?= h('../'.ltrim($brandPath,'/')) ?>" alt="Logo">
      <?php endif; ?>
    </div>
  </div>

  <header class="hero hero-teal" style="display:flex;gap:8px;justify-content:space-between;align-items:center;">
    <div>
      <h1 style="margin:0;"><?= h($p['titel']) ?></h1>
      <div style="color:#555">
        Projekt: <strong><?= h($p['projekt_name'] ?? '') ?></strong>
        • Status: <strong><?= $p['status']==='in Bearbeitung' ? 'in Bearbeitung' : h($p['status']) ?></strong>
        • Fällig: <strong><?= h($fmtDate($p['enddatum'] ?? '')) ?></strong>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <?php if($isLoggedIn): ?>
        <a class="btn" href="pendenzen.php">← Zur Liste</a>
        <a class="btn" href="pendenzen.php?edit=<?= (int)$id ?>">✏️ Bearbeiten</a>
      <?php endif; ?>
      <a class="btn" href="pendenz_pdf.php?id=<?= (int)$id ?><?= (!empty($p['public_token'])) ? '&t='.h($p['public_token']) : '' ?>">📄 PDF</a>
    </div>
  </header>

  <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c;"><?= h($flash) ?></div><?php endif; ?>

  <div class="grid">
    <div>
      <div class="card" style="padding:0; overflow:hidden;">
        <div class="hero-img" onclick="openLightbox(0)">
          <?php if($heroImg): ?>
            <img src="<?= h('../'.ltrim($heroImg['pfad'],'/')) ?>" alt="Cover" class="gallery-img" data-index="0">
          <?php else: ?>
            <div style="height:360px; display:flex; align-items:center; justify-content:center; color:#777">Kein Bild</div>
          <?php endif; ?>
        </div>

        <?php if($imgs && $imgs->num_rows>1): ?>
          <div class="thumbs" style="padding:10px;">
            <?php while($img=$imgs->fetch_assoc()): if($heroImg && $img['id']==$heroImg['id']) continue; ?>
              <div>
                <a href="javascript:void(0)" onclick="openLightbox(<?= $idx = ($idx ?? 0) + 1 ?>)">
                  <img src="<?= h('../'.ltrim($img['pfad'],'/')) ?>" alt="" class="gallery-img" data-index="<?= $idx ?>">
                </a>
                <?php if(can_edit_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))): ?>
                <form method="post" style="margin-top:4px">
                  <input type="hidden" name="action" value="set_cover">
                  <input type="hidden" name="file_id" value="<?= (int)$img['id'] ?>">
                  <button class="btn btn-small" type="submit">Cover</button>
                </form>
                <?php endif; ?>
              </div>
            <?php endwhile; $imgs->data_seek(0); ?>
          </div>
        <?php endif; ?>
      </div>

      <?php foreach($sectionsShow as $sec): ?>
        <div class="section-block" id="sec-<?= h($sec) ?>">
        <?php if($sec==='meta'): ?>
          <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
              <h3 style="margin:0;">Details</h3>
              <a href="?id=<?= $id ?>&hide_empty=<?= $hideEmpty?0:1 ?>" class="btn btn-small" style="background:<?= $hideEmpty?'#f3f4f6':'#1abc9c' ?>; color:<?= $hideEmpty?'#374151':'#fff' ?>;">
                <?= $hideEmpty ? '👁️ Alle Felder' : '🎯 Nur ausgefüllte' ?>
              </a>
            </div>
            <div class="meta-grid" id="meta-container" style="<?= $view['layout']==='compact'?'grid-template-columns:1fr;':'' ?><?= $view['font_size']==='small'?'font-size:13px;':($view['font_size']==='large'?'font-size:17px;':'') ?>">
              <?php foreach($metaShow as $f):
                $lbl = $labels[$f] ?? ucwords(str_replace('_',' ',$f));
                $val = isset($values[$f]) ? $values[$f] : ($p[$f] ?? '');
                $val = $formatValue($f,$val);
                if ($hideEmpty && (empty($val) || $val === '—')) continue;
              ?>
                <div class="meta-item" id="meta-<?= h($f) ?>">
                  <div style="color:#666"><?= h($lbl) ?></div>
                  <div><strong><?= h($val) ?></strong></div>
                </div>
              <?php endforeach; 
              if (count($metaShow) === 0 || array_reduce($metaShow, fn($carry, $f) => $carry && empty($formatValue($f, $values[$f] ?? ($p[$f] ?? ''))), true)): ?>
                <div id="meta-empty-hint" style="padding:10px; color:#94a3b8; font-style:italic;">Keine Details hinterlegt.</div>
              <?php endif; ?>
            </div>
          </div>
        <?php elseif($sec==='plan' && $planInfo): 
            if (!empty($cfg['plan_snapshot_path']) && file_exists('../'.$cfg['plan_snapshot_path'])):
        ?>
          <div class="card" style="overflow:hidden;">
            <h3 style="margin-top:0;">📍 Ort auf Plan: <?= h($planInfo['name']) ?></h3>
            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                <img src="<?= h('../'.$cfg['plan_snapshot_path']) ?>?t=<?= time() ?>" style="display:block; width:100%; height:auto;">
            </div>
          </div>
        <?php else:
            $px = (float)($p['pin_x'] ?? 0);
            $py = (float)($p['pin_y'] ?? 0);
            $url = h('../'.ltrim($planInfo['datei_pfad'], '/'));
            $hasZone = !empty($planInfo['zone']);
            
            // Default 100% (Ganzer Plan) wenn keine Wohnung/Zone hinterlegt
            $zx = $hasZone ? (float)$planInfo['zone']['x_pct'] : 0;
            $zy = $hasZone ? (float)$planInfo['zone']['y_pct'] : 0;
            $zw = $hasZone ? (float)$planInfo['zone']['width_pct'] : 100;
            $zh = $hasZone ? (float)$planInfo['zone']['height_pct'] : 100;

            if ($zw <= 0) $zw = 100;
            if ($zh <= 0) $zh = 100;

            $scaleX = 100 / $zw;
            $scaleY = 100 / $zh;
        ?>
          <div class="card" style="overflow:hidden;">
            <h3 style="margin-top:0;">📍 Ort auf Plan: <?= h($planInfo['name']) ?></h3>
            <div style="position:relative; width:100%; padding-bottom:<?= min(150, ($zh/$zw)*100) ?>%; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                <div style="position:absolute; top:0; left:0; width:100%; height:100%;">
                    <img src="<?= $url ?>" style="position:absolute; width:<?= $scaleX*100 ?>%; height:<?= $scaleY*100 ?>%; left:-<?= $zx*$scaleX ?>%; top:-<?= $zy*$scaleY ?>%; max-width:none; pointer-events:none; border:none; box-shadow:none;">
                    <?php 
                      $pinsList = !empty($cfg['plan_pins']) ? $cfg['plan_pins'] : [];
                      if (empty($pinsList) && !empty($p['pin_x']) && !empty($p['pin_y'])) {
                          $pinsList[] = ['plan_id' => $p['plan_id'], 'x' => $p['pin_x'], 'y' => $p['pin_y']];
                      }
                      foreach($pinsList as $pinObj):
                          if ($pinObj['plan_id'] != $p['plan_id']) continue;
                          $px = (float)($pinObj['x'] ?? 0);
                          $py = (float)($pinObj['y'] ?? 0);
                          if (!$px || !$py) continue;
                          
                          // Pin position relative to the cropped area
                          $pinLeft = ($px - $zx) * $scaleX;
                          $pinTop = ($py - $zy) * $scaleY;
                          
                          // Optional: only render if pin is inside the cropped visible area (allow a bit of overflow)
                          if ($pinLeft >= -5 && $pinLeft <= 105 && $pinTop >= -5 && $pinTop <= 105):
                    ?>
                    <div style="position:absolute; transform:translate(-50%, -100%); left:<?= $pinLeft ?>%; top:<?= $pinTop ?>%; z-index:10;">
                        <svg viewBox="0 0 24 24" width="32" height="32" style="filter: drop-shadow(0 4px 6px rgba(0,0,0,0.5));">
                            <path fill="#ef4444" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" stroke="#ffffff" stroke-width="1.5"/>
                            <circle cx="12" cy="9" r="3.5" fill="#ffffff"/>
                        </svg>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
          </div>
        <?php endif; // End Snapshot Check ?>
        <?php elseif($sec==='kurzbeschreibung' && !empty($p['kurzbeschreibung'])): ?>
          <div class="card"><h3>Kurzbeschreibung</h3><div class="meta-item"><?= nl2br(h($p['kurzbeschreibung'])) ?></div></div>
        <?php elseif($sec==='langbeschreibung' && !empty($p['langbeschreibung'])): ?>
          <div class="card"><h3>Beschreibung</h3><div class="meta-item"><?= nl2br(h($p['langbeschreibung'])) ?></div></div>
        <?php elseif($sec==='notiz' && !empty($p['notiz'])): ?>
          <div class="card"><h3>Notiz</h3><div class="meta-item"><?= nl2br(h($p['notiz'])) ?></div></div>
        <?php elseif($sec==='anhaenge'): ?>
          <div class="card"><h3>Anhänge</h3>
            <?php if($files && $files->num_rows): ?>
              <ul style="margin-left:18px">
                <?php while($f=$files->fetch_assoc()): ?>
                  <li><a href="<?= h('../'.ltrim($f['pfad'],'/')) ?>" target="_blank"><?= h($f['titel'] ?: basename($f['pfad'])) ?></a>
                    <span style="color:#666">— <?= h($f['mimetype'] ?: 'Datei') ?>, <?= number_format((int)($f['groesse']??0)/1024,0,'\'','.') ?> KB</span></li>
                <?php endwhile; $files->data_seek(0); ?>
              </ul>
            <?php else: ?><div class="meta-item" style="color:#666">Keine Anhänge</div><?php endif; ?>
          </div>
        <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <!-- Unternehmer Rückmeldung Anzeigen -->
      <?php if (!empty($p['unt_bemerkung'])): ?>
        <div class="card" style="border-left: 4px solid #10b981; background: #f0fdf4; margin-top:15px;">
          <h3 style="color: #059669; margin-top:0;">👷 Unternehmer-Rückmeldung</h3>
          <div class="meta-item" style="background:transparent; border:none; padding:0;">
             <?= nl2br(h($p['unt_bemerkung'])) ?>
          </div>
          <?php if (!empty($p['confirmation_at'])): ?>
            <div style="margin-top:10px; font-size:12px; color:#6b7280;">Bestätigt am: <?= h($fmtDate($p['confirmation_at'])) ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- RESPONSE SECTION FOR ASSIGNEE -->
      <?php if ($assigneeUser && (int)$assigneeUser['id'] === (int)($_SESSION['user_id']??0)): ?>
        <div class="card" style="border-top: 4px solid #3b82f6; background: #f0f7ff;">
          <h3 style="color: #1d4ed8; margin-top: 0;">Rückmeldung / Erledigung</h3>
          <p style="font-size: 14px; color: #1e40af;">Hier können Sie den Status aktualisieren und eine Bemerkung hinterlassen.</p>
          <form method="post" enctype="multipart/form-data" action="pendenz_response.php">
            <input type="hidden" name="pendenz_id" value="<?= $id ?>">
            <div style="margin-bottom:12px;">
              <label style="display:block; font-weight:bold; margin-bottom:4px;">Neuer Status</label>
              <select name="new_status" class="input" style="width:100%">
                <option value="in_bearbeitung" <?= $p['status']==='in_bearbeitung'?'selected':'' ?>>In Bearbeitung</option>
                <option value="Unt. Erledigt." <?= $p['status']==='Unt. Erledigt.'?'selected':'' ?>>Unt. Erledigt.</option>
                <option value="erledigt" <?= $p['status']==='erledigt'?'selected':'' ?>>Erledigt (gemeldet)</option>
                <option value="wartend" <?= $p['status']==='wartend'?'selected':'' ?>>Wartend / Rückfrage</option>
              </select>
            </div>
            <div style="margin-bottom:12px;">
              <label style="display:block; font-weight:bold; margin-bottom:4px;">Bemerkung / Nachricht</label>
              <textarea name="message" rows="3" class="input" style="width:100%" placeholder="Was wurde gemacht?"></textarea>
            </div>
            <div style="margin-bottom:12px;">
              <label style="display:block; font-weight:bold; margin-bottom:4px;">Foto / Datei hochladen</label>
              <input type="file" name="attachment" class="input" style="width:100%">
            </div>
            <button type="submit" class="btn primary" style="background:#2563eb; width:100%;">Absenden</button>
          </form>
        </div>
      <?php endif; ?>

    </div>


    <?php if($isLoggedIn): ?>
    <div class="card" style="position:sticky; top:20px;">
      <div class="designer-header">
        <h3 style="margin:0; color:#0f172a;">🎨 Bericht-Designer</h3>
        <small style="color:#64748b;">PDF & Web-Vorschau anpassen</small>
        <div style="margin-top:10px;">
          <a href="pdf_designer.php" class="btn btn-small" style="background:#0ea5e9; color:#fff; width:100%; display:block; text-align:center; text-decoration:none; font-weight:bold;">📐 PDF-Maske (A4-Designer)</a>
        </div>
      </div>
      <form method="post" id="viewForm" style="display:grid; gap:10px">
        <input type="hidden" name="action" value="save_view">

        <div class="cfg-grid">
          <label>Layout
            <select name="layout">
              <option value="classic" <?= $view['layout']==='classic'?'selected':'' ?>>Classic</option>
              <option value="compact" <?= $view['layout']==='compact'?'selected':'' ?>>Kompakt</option>
              <option value="gallery" <?= $view['layout']==='gallery'?'selected':'' ?>>Galerie</option>
            </select>
          </label>
          <label>Schriftgröße
            <select name="font_size">
              <option value="small"  <?= $view['font_size']==='small'?'selected':'' ?>>Klein</option>
              <option value="normal" <?= $view['font_size']==='normal'?'selected':'' ?>>Normal</option>
              <option value="large"  <?= $view['font_size']==='large'?'selected':'' ?>>Groß</option>
            </select>
          </label>
          <label>Hero-Bild
            <select name="hero">
              <option value="cover" <?= $view['hero']==='cover'?'selected':'' ?>>Cover (markiertes Bild)</option>
              <option value="first" <?= $view['hero']==='first'?'selected':'' ?>>Erstes Bild</option>
            </select>
          </label>
          <label>Brand-Quelle
            <select name="brand_source">
              <option value="firma_logo" <?= $view['brand_source']==='firma_logo'?'selected':'' ?>>Firmenlogo</option>
              <option value="user_titelbild" <?= $view['brand_source']==='user_titelbild'?'selected':'' ?>>Titelbild</option>
              <option value="user_profilbild" <?= $view['brand_source']==='user_profilbild'?'selected':'' ?>>Profilbild</option>
              <option value="none" <?= $view['brand_source']==='none'?'selected':'' ?>>Keins</option>
            </select>
          </label>
          <label>Brand von
            <select name="brand_from">
              <option value="ersteller" <?= $view['brand_from']==='ersteller'?'selected':'' ?>>Ersteller</option>
              <option value="zustaendiger" <?= $view['brand_from']==='zustaendiger'?'selected':'' ?>>Zuständiger</option>
            </select>
          </label>
          <div></div>
        </div>

        <fieldset style="border:1px solid #e5e7eb;padding:8px;border-radius:8px">
          <legend>Bereiche (Checkbox + Reihenfolge)</legend>
          <input type="hidden" name="sections_order" id="sections_order" value="<?= h(implode(',',$sectionsOrder)) ?>">
          <ul id="sectionsList" class="order-list">
            <?php
              $allSections=['meta'=>'Meta','plan'=>'Plandetail','kurzbeschreibung'=>'Kurzbeschreibung','langbeschreibung'=>'Beschreibung','notiz'=>'Notiz','anhaenge'=>'Anhänge'];
              // Reihenfolge: erst vorhandene Order, dann restliche
              $secKeys = array_values(array_unique(array_merge($sectionsOrder,array_keys($allSections))));
              foreach($secKeys as $k):
                $lbl = $allSections[$k] ?? ucwords($k);
                $checked = in_array($k,$view['sections'],true) ? 'checked' : '';
            ?>
              <li class="order-item" draggable="true" data-key="<?= h($k) ?>">
                <span class="drag">☰</span>
                <label style="display:flex;align-items:center;gap:6px">
                  <input type="checkbox" name="sections_enabled[]" value="<?= h($k) ?>" <?= $checked ?>>
                  <span><?= h($lbl) ?> <small style="color:#777">(<?= h($k) ?>)</small></span>
                </label>
              </li>
            <?php endforeach; ?>
          </ul>
        </fieldset>

        <fieldset style="border:1px solid #e5e7eb;padding:8px;border-radius:8px">
          <legend>Meta-Felder (Checkbox + Reihenfolge)</legend>
          <input type="hidden" name="meta_order" id="meta_order" value="<?= h(implode(',',$metaOrder)) ?>">
          <ul id="metaList" class="order-list" style="max-height:300px;overflow:auto">
            <?php
              // Optionen: smart zuerst, dann DB-Spalten
              $allOptions = array_merge($smart, array_fill_keys($allCols,null));
              // Reihenfolge: zuerst gespeicherte Reihenfolge, dann Rest
              $keys = array_values(array_unique(array_merge($metaOrder, array_keys($allOptions))));
              foreach($keys as $k){
                $label = $labels[$k] ?? ucwords(str_replace('_',' ',$k));
                $checked = in_array($k,$view['meta_fields'],true) ? 'checked' : '';
                echo '<li class="order-item" draggable="true" data-key="'.h($k).'"><span class="drag">☰</span>
                        <label style="display:flex;align-items:center;gap:6px">
                          <input type="checkbox" name="meta_enabled[]" value="'.h($k).'" '.$checked.'>
                          <span>'.h($label).' <small style="color:#777">('.h($k).')</small></span>
                        </label></li>';
              }
            ?>
          </ul>
        </fieldset>

        <fieldset style="border:1px solid #e5e7eb;padding:8px;border-radius:8px">
          <legend>Zugriff</legend>
          <?php $mode=$view['vis_mode']; ?>
          <div class="cfg-grid">
            <label style="grid-column:1 / -1">
              <input type="radio" name="access_mode" value="public" <?= $mode==='public'?'checked':''; ?>> Öffentlich (mit Link &amp; QR)
            </label>
            <label><input type="radio" name="access_mode" value="projekt" <?= $mode==='projekt'?'checked':''; ?>> Projekt (alle Mitglieder)</label>
            <label><input type="radio" name="access_mode" value="privat"  <?= $mode==='privat'?'checked':''; ?>> Privat (Ersteller &amp; Zuständiger)</label>
            <label><input type="radio" name="access_mode" value="admin"   <?= $mode==='admin'?'checked':''; ?>> Nur Admin</label>
            <label><input type="radio" name="access_mode" value="team"    <?= $mode==='team'?'checked':''; ?>> Team (wählen)</label>
            <label><input type="radio" name="access_mode" value="users"   <?= $mode==='users'?'checked':''; ?>> Ausgewählte Benutzer</label>
          </div>

          <div id="teamWrap" style="margin-top:8px; display:<?= $mode==='team'?'block':'none' ?>">
            <label>Team wählen
              <select name="vis_team_id">
                <option value="">— wählen —</option>
                <?php if($teams){ while($t=$teams->fetch_assoc()): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= ((int)$view['vis_team_id']===(int)$t['id'])?'selected':''; ?>><?= h($t['name']) ?></option>
                <?php endwhile; } ?>
              </select>
            </label>
          </div>

          <div id="usersWrap" style="margin-top:8px; display:<?= $mode==='users'?'block':'none' ?>">
            <label>Benutzer wählen (Mehrfach)
              <select name="vis_user_ids[]" multiple size="6" style="width:100%">
                <?php if($members){ while($m=$members->fetch_assoc()):
                  $sel = in_array((int)$m['id'], array_map('intval',$view['vis_user_ids']??[]), true) ? 'selected' : '';
                ?>
                  <option value="<?= (int)$m['id'] ?>" <?= $sel ?>><?= h($m['name'].' ('.$m['email'].')') ?></option>
                <?php endwhile; } ?>
              </select>
              <small style="color:#666">Tipp: Strg/Cmd für Mehrfachauswahl.</small>
            </label>
          </div>

          <?php if($mode==='public' || (int)($p['public_enabled']??0)===1): ?>
            <div style="margin-top:8px">
              <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="regen_public_token" value="1"> Public-Token neu erzeugen (alte Links verlieren Gültigkeit)
              </label>
            </div>
          <?php endif; ?>
        </fieldset>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <button class="btn">Speichern</button>
          <a class="btn" href="pendenz_pdf.php?id=<?= (int)$id ?><?= (!empty($p['public_token'])) ? '&t='.h($p['public_token']) : '' ?>">📄 PDF erzeugen</a>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- QR & BRANDING CARD -->
    <div class="branding-card">
      <div style="margin-bottom:15px">
        <a class="btn" href="pendenz_pdf.php?id=<?= (int)$id ?><?= (!empty($p['public_token'])) ? '&t='.h($p['public_token']) : '' ?>" style="width:100%; text-align:center; background:#10b981; color:#fff; font-weight:bold; border-radius:8px; padding:10px 0; display:block; text-decoration:none;">📄 PDF erzeugen</a>
      </div>
      
      <div style="font-size:13px; color:#1e293b; margin-bottom:12px;">
        <strong style="display:block; margin-bottom:4px; font-size:11px; text-transform:uppercase; color:#64748b; letter-spacing:0.02em;">Permalink</strong>
        <?php $pLink = (is_https_request() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim($PREFIX, '/') . '/pages/pendenz_show.php?id=' . $id; ?>
        <a href="<?= h($pLink) ?>" target="_blank" style="word-break:break-all; color:#2563eb; text-decoration:none; font-weight:500;"><?= h($pLink) ?></a>
      </div>

      <?php if($qrUrl): ?>
        <div style="border-top:1px solid #f1f5f9; padding-top:15px; text-align:center;">
          <?php if($publicUrl): ?>
            <div style="font-size:12px; color:#16a34a; font-weight:bold; margin-bottom:8px;">✅ Öffentlicher Link Aktiv</div>
            <div style="font-size:10px; margin-bottom:12px;"><a href="<?= h($publicUrl) ?>" target="_blank" style="color:#64748b; text-decoration:none;"><?= h($publicUrl) ?></a></div>
          <?php else: ?>
            <div style="font-size:11px; color:#64748b; font-weight:600; margin-bottom:10px; text-transform:uppercase; letter-spacing:0.02em;">Interner QR-Code</div>
          <?php endif; ?>
          
          <img src="<?= h($qrUrl) ?>" alt="QR" style="width:200px; height:200px; padding:12px; border:1px solid #e2e8f0; border-radius:16px; background:#fff; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);">
          <div style="font-size:11px; color:#94a3b8; margin-top:10px;">Scan für mobilen Zugriff</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  // Drag&Drop für Reihenfolge-Listen
  function makeDnD(listId, hiddenId, targetContainerId, targetPrefix){
    const list=document.getElementById(listId);
    const hidden=document.getElementById(hiddenId);
    if(!list||!hidden) return;
    let dragEl=null;
    list.addEventListener('dragstart',e=>{
      const li=e.target.closest('li[draggable="true"]'); if(!li) return;
      dragEl=li; e.dataTransfer.effectAllowed='move'; li.classList.add('dragging');
    });
    list.addEventListener('dragover',e=>{
      e.preventDefault(); const over=e.target.closest('li'); if(!over||over===dragEl) return;
      const r=over.getBoundingClientRect(); (e.clientY-r.top)>(r.height/2) ? over.after(dragEl) : over.before(dragEl);
    });
    function sync(){ 
      const keys = [...list.querySelectorAll('li')].map(li=>li.dataset.key);
      hidden.value = keys.join(','); 
      if (targetPrefix) {
        keys.forEach(key => {
            const el = document.getElementById(targetPrefix + key);
            if (el) el.parentNode.appendChild(el);
        });
      }
    }
    list.addEventListener('drop',()=> { dragEl.classList.remove('dragging'); sync(); });
    list.addEventListener('dragend',()=> { dragEl.classList.remove('dragging'); sync(); });
    sync();
  }

  // Draggable-Listen Init (Nur wenn Elemente da sind)
  if (document.getElementById('sectionsList')) {
    const mainContent = document.querySelector('.grid > div:first-child');
    if (mainContent) {
      const sections = [...mainContent.querySelectorAll('.section-block')];
      sections.forEach(s => mainContent.appendChild(s));
    }
    makeDnD('sectionsList','sections_order', null, 'sec-');
    makeDnD('metaList','meta_order', 'meta-container', 'meta-');
  }

  // Zugriff-UI toggles
  const radios=[...document.querySelectorAll('input[name="access_mode"]')];
  if (radios.length > 0) {
    const teamWrap=document.getElementById('teamWrap');
    const usersWrap=document.getElementById('usersWrap');
    function updateAccess(){
      const v=(radios.find(r=>r.checked)||{}).value||'projekt';
      if(teamWrap) teamWrap.style.display=(v==='team')?'block':'none';
      if(usersWrap) usersWrap.style.display=(v==='users')?'block':'none';
    }
    radios.forEach(r=>r.addEventListener('change',updateAccess));
    updateAccess();
  }

  // --- Lightbox Funktionalität ---
  window.openLightbox = function(idx) {
    const overlay = document.getElementById('lightbox-overlay');
    const lbImg = document.getElementById('lightbox-img');
    const lbCap = document.getElementById('lightbox-caption');
    const imgs = [...document.querySelectorAll('.gallery-img')];
    
    if (!overlay || !lbImg) return;
    if (imgs.length === 0) { console.error("No .gallery-img found"); return; }
    
    const curIdx = (typeof idx === 'number' && idx >= 0 && idx < imgs.length) ? idx : 0;
    const img = imgs[curIdx];
    if (!img) return;
    
    lbImg.src = img.src;
    if (lbCap) lbCap.innerText = 'Bild ' + (curIdx + 1) + ' von ' + imgs.length;
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    window._lbCurrentIdx = curIdx;
    window._lbImgs = imgs;
  };

  window.closeLightbox = function() {
    const overlay = document.getElementById('lightbox-overlay');
    if (overlay) overlay.style.display = 'none';
    document.body.style.overflow = '';
  };

  window.nextImg = function() {
    if (typeof window._lbCurrentIdx === 'undefined' || !window._lbImgs) return;
    const next = (window._lbCurrentIdx + 1) % window._lbImgs.length;
    window.openLightbox(next);
  };

  window.prevImg = function() {
    if (typeof window._lbCurrentIdx === 'undefined' || !window._lbImgs) return;
    const prev = (window._lbCurrentIdx - 1 + window._lbImgs.length) % window._lbImgs.length;
    window.openLightbox(prev);
  };

  document.addEventListener('keydown', function(e) {
    const overlay = document.getElementById('lightbox-overlay');
    if (!overlay || overlay.style.display === 'none') return;
    if (e.key === 'Escape') window.closeLightbox();
    if (e.key === 'ArrowRight') window.nextImg();
    if (e.key === 'ArrowLeft') window.prevImg();
  });

  document.addEventListener('click', function(e) {
    const overlay = document.getElementById('lightbox-overlay');
    if (e && e.target === overlay) window.closeLightbox();
  });
</script>

<!-- Lightbox Overlay -->
<div id="lightbox-overlay">
  <div class="lightbox-close" onclick="closeLightbox()">&times;</div>
  <div class="lightbox-nav">
    <div class="lightbox-btn" onclick="prevImg()">&#10094;</div>
    <div class="lightbox-btn" onclick="nextImg()">&#10095;</div>
  </div>
  <img id="lightbox-img" src="" alt="">
  <div class="lightbox-caption" id="lightbox-caption"></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
