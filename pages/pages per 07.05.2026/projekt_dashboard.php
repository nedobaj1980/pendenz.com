<?php
// pages/projekt_dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();

require_once __DIR__ . '/../includes/functions.php'; // e(), url(), page_url(), brand_url(), best_image_url(), handle_upload()
require_once __DIR__ . '/../includes/user_ui.php';
require_once __DIR__ . '/../includes/csrf.php';
// --- CSRF-Kompatibilität (Shim) ---------------------------------------------
if (!function_exists('csrf_field')) {
  // Falls dein csrf.php kein csrf_field() bereitstellt
  function csrf_field() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $t = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $t;
    echo '<input type="hidden" name="csrf" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
  }
}

if (!function_exists('csrf_validate')) {
  // Universeller Validator: nutzt vorhandene Alternativen oder prüft Session-Token
  function csrf_validate() {
    if (session_status() === PHP_SESSION_NONE) session_start();

    // Wenn eine "offizielle" Variante existiert, zuerst diese nutzen
    if (function_exists('csrf_validate_or_throw')) {
      $tok = $_POST['csrf'] ?? $_POST['csrf_token'] ?? null;
      csrf_validate_or_throw($tok);
      return;
    }

    $tok   = $_POST['csrf'] ?? $_POST['csrf_token'] ?? '';
    $sess  = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

    // Nur prüfen, wenn beides vorhanden ist; ansonsten dev-freundlich kein Fatal Error
    if ($tok !== '' && $sess !== '') {
      if (!hash_equals($sess, $tok)) {
        throw new Exception('Ungültiger CSRF-Token.');
      }
    }
    // Falls kein Token vorhanden ist (z. B. in einer lokalen Dev-Umgebung),
    // nicht fatal abbrechen – aber in Prod solltest du das erzwingen.
  }
}

require_once __DIR__ . '/../includes/fs.php'; // project_root_path(), fs_list_children_smart(), fs_abs_from_rel(), fs_scan_project()

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('brand_url')) { function brand_url(string $p=''){ return '/pendenz.com/assets/'.ltrim($p?:'logo-mark.png','/'); } }

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

/** ---------- Eingaben ---------- */
$projekt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projekt_id <= 0) die("❌ Kein Projekt gewählt.");
$ctxRel = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';

/** Auswahlmodus-Parameter (Pfad/Ordner „zurückgeben“) */
$return_to = (string)($_GET['return_to'] ?? '');
$scope     = (string)($_GET['scope'] ?? '');       // 'root' oder 've' (optional)
$ve_id     = (int)($_GET['ve_id'] ?? 0);           // falls scope=ve
$select_mode = $return_to !== '';

/** ---------- Helpers: DB-Introspektion ---------- */
function table_exists(mysqli $db, string $table): bool {
  $st=$db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
  $st->bind_param("s",$table); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $st=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $st->bind_param("ss",$table,$col); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/** ---------- Projekt laden ---------- */
$select = "SELECT id,name,adresse,status,bild";
if (column_exists($mysqli,'projekte','root_path')) $select .= ", root_path";
if (column_exists($mysqli,'projekte','ordner_vorlage_id')) $select .= ", ordner_vorlage_id";
$select .= " FROM projekte WHERE id=?";
$st=$mysqli->prepare($select); $st->bind_param("i",$projekt_id); $st->execute();
$proj = $st->get_result()->fetch_assoc(); $st->close();
if (!$proj) die("❌ Projekt (#{$projekt_id}) nicht gefunden.");

$root_path   = (string)($proj['root_path'] ?? '');    // evtl. absoluter Root-Pfad
$vorlage_id  = (int)($proj['ordner_vorlage_id'] ?? 0);
$vorlage_name = null;
if ($vorlage_id && table_exists($mysqli,'ordner_vorlagen')) {
  $q=$mysqli->prepare("SELECT name FROM ordner_vorlagen WHERE id=?"); $q->bind_param("i",$vorlage_id); $q->execute();
  $vorlage_name = $q->get_result()->fetch_column(); $q->close();
}

/** Absoluten Projekt-Root bestimmen (bevorzugt Helper) */
$id = $projekt_id; // Alias
$flash = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Globaler Rollout
  if (isset($_POST['action']) && $_POST['action'] === 'rollout_template') {
      $tplId = (int)$_POST['template_id'];
      $root = project_root_path($mysqli, $id);
      
      if ($root && is_dir($root)) {
          $resUnits = $mysqli->query("SELECT w.name, o.name as obj_name FROM wohnungen w JOIN objekte o ON o.id = w.objekt_id WHERE o.projekt_id = $id");
          $tplNodes = $mysqli->query("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id = $tplId");
          $folders = [];
          while($n = $tplNodes->fetch_assoc()) $folders[] = $n['rel_path'];
          
          $counter = 0;
          while($u = $resUnits->fetch_assoc()) {
              $uPath = "Wohnungen/" . $u['name'];
              $unitAbs = fs_abs_from_rel($root, $uPath);
              if ($unitAbs) {
                  if (!is_dir($unitAbs)) @mkdir($unitAbs, 0777, true);
                  foreach($folders as $f) {
                      $subAbs = $unitAbs . "/" . $f;
                      if (!is_dir($subAbs)) @mkdir($subAbs, 0777, true);
                  }
              }
              $counter++;
          }
          fs_scan_project($mysqli, $id, 0);
          $flash = "🚀 Rollout abgeschlossen: $counter Einheiten wurden mit dem Muster strukturiert.";
      } elseif ($act === 'set_icon_quick') {
      $rel = trim((string)($_POST['rel_path'] ?? ''));
      $icon = $_POST['icon'] ?? '';
      if ($rel !== '') {
        $mysqli->query("INSERT INTO fs_folder_meta (project_id, rel_path, icon) VALUES ($projekt_id, '".$mysqli->real_escape_string($rel)."', '".$mysqli->real_escape_string($icon)."') ON DUPLICATE KEY UPDATE icon='".$mysqli->real_escape_string($icon)."'");
        $flash = '✅ Symbol aktualisiert.';
      }
    }
  }
}

$absRoot = project_root_path($mysqli, $projekt_id);
if (!$absRoot && $root_path) $absRoot = $root_path;

/** Relativpfad (aus GET) + absoluter ausgewählter Pfad für „übernehmen“ */
$relPath = $ctxRel; // alias
$selected_full = '';
if ($absRoot) {
  $selected_full = rtrim($absRoot, "\\/"); // Root
  if ($relPath !== '') $selected_full .= DIRECTORY_SEPARATOR . ltrim($relPath, "\\/");
} else {
  // Fallback: kein absoluter Root bekannt -> nur Relativpfad zurückgeben
  $selected_full = $relPath;
}

/** Helper für Query-Anhängsel im Auswahlmodus */
$select_qs = $select_mode
  ? '&return_to='.rawurlencode($return_to)
    . ($scope!=='' ? '&scope='.rawurlencode($scope) : '')
    . ($ve_id ? '&ve_id='.$ve_id : '')
  : '';

/** ---------- Navigation / Baum-Helfer ---------- */
function crumbs(string $rel): array {
  $rel = ltrim($rel,'/');
  if($rel==='') return [];
  $parts = explode('/',$rel);
  $acc=[]; $out=[];
  foreach($parts as $p){ $acc[]=$p; $out[]=['label'=>$p,'rel'=>implode('/',$acc)]; }
  return $out;
}
function child_folders(mysqli $db, int $pid, string $parentRel=''): array {
  if ($parentRel==='') {
    $sql="SELECT name, rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND (parent_rel_path IS NULL OR parent_rel_path='') ORDER BY name ASC";
    $st=$db->prepare($sql); $st->bind_param("i",$pid);
  } else {
    $sql="SELECT name, rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND parent_rel_path=? ORDER BY name ASC";
    $st=$db->prepare($sql); $st->bind_param("is",$pid,$parentRel);
  }
  $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  
  // Icons aus Meta holen
  foreach ($rows as &$r) {
    $mQ = $db->query("SELECT icon FROM fs_folder_meta WHERE project_id=$pid AND rel_path='".$db->real_escape_string($r['rel_path'])."'");
    $r['icon'] = ($mR = $mQ->fetch_assoc()) ? $mR['icon'] : '📁';
  }
  return $rows;
}

/** ---------- Aktionen (Cover/Titel/Preview-Ordner, Cover aus FS) ---------- */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $act = $_POST['action'] ?? '';
  if ($act === 'set_title') {
    $rel = trim((string)($_POST['rel_path'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    if ($rel !== '') {
      $sv = $mysqli->prepare("
        INSERT INTO project_dash_cards (project_id, rel_path, title, show_in_dashboard)
        VALUES (?,?,?,1)
        ON DUPLICATE KEY UPDATE title=VALUES(title), show_in_dashboard=1
      ");
      $sv->bind_param("iss",$projekt_id,$rel,$title);
      $sv->execute(); $sv->close();
      $flash = '✅ Titel gespeichert.';
    }
  } elseif ($act === 'set_cover') {
    $rel = trim((string)($_POST['rel_path'] ?? ''));
    if ($rel !== '') {
      $old = $mysqli->prepare("SELECT cover_path FROM project_dash_cards WHERE project_id=? AND rel_path=?");
      $old->bind_param("is",$projekt_id,$rel); $old->execute();
      $oldPath = $old->get_result()->fetch_column(); $old->close();
      $uid = (int)($_SESSION['user_id'] ?? 0);
      try {
        $up = handle_upload('cover', $oldPath ?: null, $uid, 'Dashboard Cover',
          ['image/jpeg','image/png','image/gif','image/webp'], 8_000_000, 1600, 1200);
        if ($up) {
          $sv = $mysqli->prepare("
            INSERT INTO project_dash_cards (project_id, rel_path, cover_path, show_in_dashboard)
            VALUES (?,?,?,1)
            ON DUPLICATE KEY UPDATE cover_path=VALUES(cover_path), show_in_dashboard=1
          ");
          $sv->bind_param("iss",$projekt_id,$rel,$up);
          $sv->execute(); $sv->close();
          $flash = '✅ Cover hochgeladen.';
        } else $flash = '❌ Kein Bild gewählt.';
      } catch (Throwable $ex) { $flash = '❌ '.$ex->getMessage(); }
    }
  } elseif ($act === 'set_cover_from_fs') {
    // Cover direkt auf existierende Datei im Projekt zeigen
    $rel = trim((string)($_POST['rel_path'] ?? ''));
    $fileRel = trim((string)($_POST['file_rel_path'] ?? ''));
    if ($rel !== '' && $fileRel !== '') {
      $coverUrl = page_url('file.php?projekt_id='.$projekt_id.'&path='.rawurlencode($fileRel));
      $sv = $mysqli->prepare("
        INSERT INTO project_dash_cards (project_id, rel_path, cover_path, show_in_dashboard)
        VALUES (?,?,?,1)
        ON DUPLICATE KEY UPDATE cover_path=VALUES(cover_path), show_in_dashboard=1
      ");
      $sv->bind_param("iss",$projekt_id,$rel,$coverUrl);
      $sv->execute(); $sv->close();
      $flash = '✅ Cover aus Datei gesetzt.';
    }
  } elseif ($act === 'ensure_preview' && $ctxRel !== '') {
    // Lege <aktueller Ordner>/_preview physisch an und rescan
    $absRootX = project_root_path($mysqli,$projekt_id);
    if ($absRootX) {
      $abs = fs_abs_from_rel($absRootX, $ctxRel.'/_preview');
      if ($abs) {
        if (is_dir($abs) || @mkdir($abs,0777,true)) {
          $scan = fs_scan_project($mysqli,$projekt_id,0);
          $flash = '📷 Preview-Ordner angelegt. ' . ( $scan['ok'] ? ('Re-Scan OK ('.$scan['count'].' Einträge).') : ('Re-Scan-Fehler: '.$scan['msg']) );
        } else {
          $flash = '❌ Konnte _preview nicht anlegen.';
        }
      } else $flash = '❌ Zielpfad ausserhalb Root.';
    } else $flash = '❌ Kein gültiger Projekt-Root.';
  } elseif ($act === 'scan_now') {
    require_once __DIR__ . '/../includes/fs.php';
    $res = fs_scan_project($mysqli, $projekt_id, 0);
    $flash = ($res['ok'] ? '✅ Statistik aktualisiert ('.$res['count'].' Einträge).' : '❌ Fehler: '.$res['msg']);
  } elseif ($act === 'set_icon_quick') {
    $rel = trim((string)($_POST['rel_path'] ?? ''));
    $icon = trim((string)($_POST['icon'] ?? ''));
    if ($rel !== '') {
      $mysqli->query("INSERT INTO fs_folder_meta (project_id, rel_path, icon) VALUES ($projekt_id, '".$mysqli->real_escape_string($rel)."', '".$mysqli->real_escape_string($icon)."') ON DUPLICATE KEY UPDATE icon='".$mysqli->real_escape_string($icon)."'");
      $flash = '✨ Symbol '.$icon.' gespeichert.';
    }
  }
}

/** ---------- Smart KPIs (Zentrale Anzapfung) ---------- */
$kpis = ['objekte'=>0, 'wohnungen'=>0, 'mieter'=>0, 'pendenzen'=>0, 'vermietet_prozent'=>0, 'offene_betraege'=>0, 'sync_errors'=>0];

// Objekte & Wohnungen
$q1 = $mysqli->prepare("SELECT COUNT(*) c FROM objekte WHERE projekt_id=?"); $q1->bind_param("i",$projekt_id); $q1->execute(); $kpis['objekte']=(int)($q1->get_result()->fetch_column() ?? 0); $q1->close();
$q2 = $mysqli->prepare("SELECT COUNT(*) c FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=?"); $q2->bind_param("i",$projekt_id); $q2->execute(); $kpis['wohnungen']=(int)($q2->get_result()->fetch_column() ?? 0); $q2->close();

// Mieter-Quote (Live-Check)
$q3 = $mysqli->prepare("
  SELECT COUNT(DISTINCT wm.wohnung_id) c
  FROM wohnung_mieter wm
  JOIN wohnungen w ON wm.wohnung_id=w.id
  JOIN objekte o   ON w.objekt_id=o.id
  WHERE o.projekt_id=? AND wm.status = 'aktiv' AND (wm.enddatum IS NULL OR wm.enddatum >= CURDATE())");
$q3->bind_param("i",$projekt_id); $q3->execute(); $kpis['mieter']=(int)($q3->get_result()->fetch_column() ?? 0); $q3->close();

if ($kpis['wohnungen'] > 0) {
    $kpis['vermietet_prozent'] = round(($kpis['mieter'] / $kpis['wohnungen']) * 100);
}

// Pendenzen
$q4 = $mysqli->prepare("SELECT COUNT(*) c FROM pendenzen WHERE projekt_id=? AND status != 'erledigt' AND status != 'archiviert'"); $q4->bind_param("i",$projekt_id); $q4->execute(); $kpis['pendenzen']=(int)($q4->get_result()->fetch_column() ?? 0); $q4->close();

// Drive Health (Kurz-Check für Dashboard)
require_once __DIR__ . '/../includes/fs.php';
$orphansCount = 0;
$resOrph = $mysqli->query("SELECT w.id FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$projekt_id");
while($rowO = $resOrph->fetch_assoc()) {
    $p = fs_get_entity_path($mysqli, 'wohnung', (int)$rowO['id']);
    if (!$p['abs'] || !is_dir($p['abs'])) $orphansCount++;
}
$kpis['sync_errors'] = $orphansCount;

/** ---------- Mietvertrag-Radar (Ablaufende Verträge) ---------- */
$expiringLeases = [];
$qL = $mysqli->prepare("
    SELECT w.name as w_name, k.nachname, k.vorname, wm.enddatum, wm.startdatum
    FROM wohnung_mieter wm
    JOIN wohnungen w ON wm.wohnung_id = w.id
    JOIN objekte o ON w.objekt_id = o.id
    LEFT JOIN kontakte k ON wm.kontakt_id = k.id
    WHERE o.projekt_id = ? AND wm.status = 'aktiv' AND wm.enddatum IS NOT NULL AND wm.enddatum BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY wm.enddatum ASC
    LIMIT 5
");
$qL->bind_param("i", $projekt_id); $qL->execute(); $expiringLeases = $qL->get_result()->fetch_all(MYSQLI_ASSOC); $qL->close();

/** ---------- Unterordner + Previews ---------- */
function is_img(string $name): bool {
  return (bool)preg_match('/\.(jpe?g|png|gif|webp)$/i', $name);
}
function first_preview_for(mysqli $db, int $pid, string $rel): ?string {
  // 1) Deckblatt aus project_dash_cards
  $st=$db->prepare("SELECT cover_path FROM project_dash_cards WHERE project_id=? AND rel_path=? AND cover_path IS NOT NULL AND cover_path<>'' LIMIT 1");
  $st->bind_param("is",$pid,$rel); $st->execute();
  $cp = $st->get_result()->fetch_column(); $st->close();
  if ($cp) return $cp; // bereits URL/Pfad

  // 2) Bilder aus _preview/.preview/Vorschau
  $cands = [$rel.'/_preview', $rel.'/.preview', $rel.'/Vorschau', $rel.'/preview'];
  foreach ($cands as $p) {
    $q=$db->prepare("SELECT rel_path, name FROM fs_nodes WHERE project_id=? AND is_dir=0 AND parent_rel_path=? ORDER BY mtime DESC LIMIT 8");
    $q->bind_param("is",$pid,$p); $q->execute(); $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
    foreach ($rows as $r) if (is_img($r['name'])) return page_url('file.php?projekt_id='.$pid.'&path='.rawurlencode($r['rel_path']));
  }

  // 3) Bilder direkt im Ordner
  $q=$db->prepare("SELECT rel_path, name FROM fs_nodes WHERE project_id=? AND is_dir=0 AND parent_rel_path=? ORDER BY mtime DESC LIMIT 8");
  $q->bind_param("is",$pid,$rel); $q->execute(); $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
  foreach ($rows as $r) if (is_img($r['name'])) return page_url('file.php?projekt_id='.$pid.'&path='.rawurlencode($r['rel_path']));

  return null;
}

/** Daten für aktuelle Ebene */
$liveUsed = false;
$children = fs_list_children_smart($mysqli, $projekt_id, $ctxRel, true, $liveUsed);
$subfolders = array_values(array_filter($children, fn($r)=>((int)$r['is_dir'])===1));

/** ---------- Pendenzen in aktuellem Kontext ---------- */
function pendenzen_for(mysqli $db, int $pid, string $rel, int $limit=200): array {
  if ($rel==='') {
    $sql="SELECT id,titel,status,enddatum,fs_rel_path FROM pendenzen WHERE projekt_id=? AND status<>'archiviert'
          ORDER BY FIELD(status,'offen','in_bearbeitung','erledigt','archiviert'), enddatum IS NULL, enddatum ASC, id DESC LIMIT ?";
    $st=$db->prepare($sql); $st->bind_param("ii",$pid,$limit);
  } else {
    $sql="SELECT id,titel,status,enddatum,fs_rel_path FROM pendenzen
          WHERE projekt_id=? AND (fs_rel_path=? OR fs_rel_path LIKE CONCAT(?, '/%')) AND status<>'archiviert'
          ORDER BY FIELD(status,'offen','in_bearbeitung','erledigt','archiviert'), enddatum IS NULL, enddatum ASC, id DESC LIMIT ?";
    $st=$db->prepare($sql); $st->bind_param("issi",$pid,$rel,$rel,$limit);
  }
  $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  return $rows;
}
$pendenzen = pendenzen_for($mysqli, $projekt_id, $ctxRel, 200);

/** ---------- Bilderliste für „Cover aus Dateien wählen“ ---------- */
function image_candidates(mysqli $db, int $pid, string $rel): array {
  $list=[];
  $push=function($rows) use (&$list){
    foreach ($rows as $r) if (preg_match('/\.(jpe?g|png|gif|webp)$/i',$r['name'])) $list[]=$r;
  };
  // _preview / .preview / Vorschau
  foreach ([$rel.'/_preview', $rel.'/.preview', $rel.'/Vorschau', $rel.'/preview'] as $p) {
    $q=$db->prepare("SELECT rel_path,name,mtime FROM fs_nodes WHERE project_id=? AND is_dir=0 AND parent_rel_path=? ORDER BY mtime DESC LIMIT 20");
    $q->bind_param("is",$pid,$p); $q->execute(); $push($q->get_result()->fetch_all(MYSQLI_ASSOC)); $q->close();
  }
  // direkt im Ordner
  $q=$db->prepare("SELECT rel_path,name,mtime FROM fs_nodes WHERE project_id=? AND is_dir=0 AND parent_rel_path=? ORDER BY mtime DESC LIMIT 20");
  $q->bind_param("is",$pid,$rel); $q->execute(); $push($q->get_result()->fetch_all(MYSQLI_ASSOC)); $q->close();
  return $list;
}
$coverChoices = ($ctxRel!=='') ? image_candidates($mysqli,$projekt_id,$ctxRel) : [];

/** ---------- Styles ---------- */
?>
<style>
/* Layout: Seitenleiste + Hauptbereich */
.shell{display:grid;grid-template-columns:280px 1fr;gap:16px;max-width:1300px;margin:24px auto}
.aside{border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:auto;max-height:calc(100vh - 160px);padding:10px}
.main{min-width:0}

/* Buttons */
.btn{display:inline-block;padding:8px 10px;border-radius:8px;background:#0a2a6e;color:#fff;text-decoration:none;border:0;cursor:pointer}
.btn:hover{background:#071c4a}
.badge{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px;white-space:nowrap}
.muted{color:#6b7280}

/* Sidebar-Tree */
.tree h4{margin:6px 6px 8px;color:#374151;font-size:13px}
.tree details{border-radius:10px;margin:2px 0}
.tree summary{list-style:none;cursor:pointer;padding:6px 8px;border-radius:8px;display:flex;align-items:center;gap:6px}
.tree summary:hover{background:#f8fafc}
.tree .lvl{font-size:11px;color:#64748b;min-width:36px;text-align:center;border:1px solid #e5e7eb;border-radius:999px;padding:2px 6px;background:#fff}
.tree a{display:block;padding:6px 8px;border-radius:8px;text-decoration:none;color:#111827;margin:1px 0}
.tree a:hover{background:#f1f5f9}
.tree a.active{background:#111827;color:#fff}
.tree .children{padding:4px 0 4px 16px}

/* Header / Breadcrumbs */
.header-card{border:1px solid #e5e7eb;border-radius:14px;background:#f5f9ff;overflow:hidden}
.header-card .inner{display:flex;gap:20px;padding:16px;border-left:6px solid #3498db}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}
.crumbs{display:flex;gap:6px;align-items:center;color:#6b7280;margin:8px 0}
.crumbs a{text-decoration:none;color:#374151}

/* KPIs */
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:16px 0 8px}
.card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden}
.card .body{padding:10px 12px}

/* Folder Cards */
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:32px 20px;margin-top:24px;align-items:start}
.card{border:1px solid #e5e7eb;border-radius:10px;background:#fff;overflow:hidden; display:flex; flex-direction:column; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); height:auto; transition: transform 0.2s, box-shadow 0.2s; align-self: start;}
.card:hover{transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);}
.cover{height:120px; background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden; position:relative; flex-shrink:0;}
.cover img{width:100%;height:100%;object-fit:cover}
.cover .icon-placeholder { font-size: 40px; display:flex; align-items:center; justify-content:center; width:100%; height:100%; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); }
.card .body{padding:10px}
.card .title{font-weight:700; font-size:14px; color:#1e293b; margin-bottom:4px;}
.small{font-size:11px;color:#6b7280}

/* Full-width table */
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left}

/* Filebar (Quick-Access) */
.filebar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px}
.filebar .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:6px 10px;text-decoration:none;color:#111827}
.filebar .chip:hover{background:#f8fafc}

/* Simple grid for image chooser */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
.imgpick{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff}
.imgpick img{width:100%;height:100px;object-fit:cover;display:block}
.imgpick form{padding:6px}
</style>

<main class="shell">
  <!-- Sidebar: Ordnerbaum -->
  <aside class="aside">
    <div class="tree">
      <h4>Ordner-Navigation</h4>
      <?php
        // Baumpfade: Root + alle Ahnen + aktuelle Ebene
        $anc = crumbs($ctxRel);
        array_unshift($anc, ['label'=>'Hauptverzeichnis','rel'=>'']);
        // Für jede Ebene: alle Kinder listen, aktuelle markieren, Details öffnen
        $current = $ctxRel;
        foreach ($anc as $i=>$node) {
          $rel = $node['rel'];
          $childrenL = child_folders($mysqli, $projekt_id, $rel);
          $open = ($rel==='' || strpos($current, $rel.'/')===0 || $current===$rel);
          echo '<details '.($open?'open':'').'>';
          echo '<summary><span class="lvl">#'.($i).'</span> <strong>'.e($node['label']).'</strong></summary>';
          echo '<div class="children">';
          foreach ($childrenL as $ch) {
            $href = page_url('projekt_dashboard.php?id='.$projekt_id.'&path='.rawurlencode($ch['rel_path']).$select_qs);
            $active = ($current === $ch['rel_path']) ? 'active' : '';
            $iconCh = $ch['icon'] ?? '📁';
            echo '<a class="'.$active.'" href="'.e($href).'">'.$iconCh.' '.e($ch['name']).'</a>';
          }
          echo '</div>';
          echo '</details>';
        }
      ?>
    </div>
    </div>
  </aside>

  <!-- Hauptbereich -->
  <section class="main">
    <?php if ($flash): ?>
      <div style="padding:10px 12px;border:1px solid #cde;background:#effaf0;border-radius:10px;margin-bottom:12px;"><?= e($flash) ?></div>
    <?php endif; ?>

    <!-- Header -->
    <div class="header-card">
      <div class="inner">
        <?php if (!empty($proj['bild'])): ?>
          <img src="/pendenz.com/<?= ltrim($proj['bild'],'/') ?>" alt="" style="max-width:180px;border-radius:10px;border:1px solid #ddd;">
        <?php endif; ?>
        <div style="flex:1;min-width:0">
          <h1 style="margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($proj['name']) ?></h1>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;">
            <span class="badge">Status: <?= e($proj['status']) ?></span>
            <?php if ($vorlage_id): ?><span class="badge">Vorlage: <?= e($vorlage_name ?: ('#'.$vorlage_id)) ?></span><?php else: ?><span class="badge">Keine Vorlage</span><?php endif; ?>
            <?php if ($absRoot && is_dir($absRoot)): ?><span class="badge" style="background:#ecfdf5; color:#059669; border-color:#10b981;">✅ Verzeichnis bereit</span><?php else: ?><span class="badge" style="background:#fff1f2; border-color:#fecdd3; color:#e11d48">❌ Verzeichnis fehlt</span><?php endif; ?>
          </div>

          <!-- Breadcrumbs -->
          <?php $bc = crumbs($ctxRel); ?>
          <div class="crumbs">
            <a href="<?= e(page_url('projekt_dashboard.php?id='.$projekt_id.$select_qs)) ?>">🏠 Hauptverzeichnis</a>
            <?php foreach($bc as $idx=>$c): ?>
              <span style="color:#cbd5e1; margin:0 4px;">/</span>
              <a href="<?= e(page_url('projekt_dashboard.php?id='.$projekt_id.'&path='.$c['rel'].$select_qs)) ?>" style="<?= ($idx === count($bc)-1) ? 'font-weight:700; color:#1e293b;' : '' ?>"><?= e($c['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($ctxRel!==''): ?>
              <span style="margin-left:auto"></span>
              <?php
                $parent = ($ctxRel && strrpos($ctxRel,'/')!==false) ? substr($ctxRel,0,strrpos($ctxRel,'/')) : '';
                $upHref = page_url('projekt_dashboard.php?id='.$projekt_id.($parent!==''?'&path='.rawurlencode($parent):'').$select_qs);
              ?>
              <a class="badge" href="<?= e($upHref) ?>">↥ Eine Ebene hoch</a>
            <?php endif; ?>
          </div>

          <!-- Toolbar -->
          <div class="toolbar">
            <a class="btn" href="projekte.php?edit=<?= (int)$proj['id'] ?>">✏ Projekt bearbeiten</a>
            <a class="btn" href="pendenzen.php?projekt_id=<?= (int)$projekt_id ?>">📋 Pendenzen</a>
            <a class="btn" href="project_storage.php?projekt_id=<?= (int)$projekt_id ?>">🌳 Ordner &amp; Speicher</a>
            <a class="btn" href="files.php?projekt_id=<?= (int)$projekt_id ?><?= $ctxRel!==''?'&path='.rawurlencode($ctxRel):'' ?>">📁 Im Dateibrowser</a>
            <a class="btn" style="background:#3b82f6;" href="mieterspiegel.php?projekt_id=<?= (int)$projekt_id ?>">📋 Mieterspiegel</a>
            <a class="btn" style="background:linear-gradient(135deg, #1abc9c, #16a085);" href="quick_folder_editor.php?projekt_id=<?= (int)$projekt_id ?><?= $ctxRel!==''?'&path='.rawurlencode($ctxRel):'' ?>">⚡ Schneller Ordner-Editor</a>
            <?php if ($vorlage_id): ?>
              <a class="btn" href="ordner_vorlagen_edit.php?id=<?= (int)$vorlage_id ?>">🧰 Vorlage bearbeiten</a>
            <?php else: ?>
              <a class="btn" href="ordner_vorlagen.php?projekt_id=<?= (int)$projekt_id ?>">🧩 Vorlagen</a>
            <?php endif; ?>
              <form method="post" style="display:inline;">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="scan_now">
                <button class="btn" type="submit" style="background:#059669;">🔃 Statistik aktualisieren</button>
              </form>
          </div>

          <?php if ($select_mode): ?>
            <div class="card" style="margin:8px 0;padding:8px 10px;border-left:4px solid #3b82f6;background:#f8fbff">
              <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                <strong>Pfad auswählen &amp; übernehmen:</strong>
                <code><?= e($selected_full ?: '—') ?></code>
                <?php
                  $join = str_contains($return_to,'?') ? '&' : '?';
                  $okUrl = $return_to
                           . $join
                           . 'selected_path=' . urlencode($selected_full)
                           . ($scope!=='' ? '&scope='.urlencode($scope) : '')
                           . ($ve_id ? '&ve_id='.$ve_id : '');
                ?>
                <?php if ($selected_full): ?>
                  <a class="btn" href="<?= e($okUrl) ?>">✅ Pfad übernehmen</a>
                <?php else: ?>
                  <span class="muted">Wähle links im Baum einen Ordner.</span>
                <?php endif; ?>
                <span style="flex:1"></span>
                <a class="badge" href="<?= e($return_to) ?>">↩️ Zurück</a>
              </div>
            </div>
          <?php endif; ?>

          <!-- Filebar: schnelle Unterordner-Navigation -->
          <?php
            $liveUsed = false;
            $childrenQuick = fs_list_children_smart($mysqli, $projekt_id, $ctxRel, true, $liveUsed);
            $subQuick = array_values(array_filter($childrenQuick, fn($r)=>((int)$r['is_dir'])===1));
          ?>
          <?php if (!empty($subQuick)): ?>
            <div class="filebar">
              <?php foreach ($subQuick as $sf): $href = page_url('projekt_dashboard.php?id='.$projekt_id.'&path='.rawurlencode($sf['rel_path']).$select_qs); ?>
                <a class="chip" href="<?= e($href) ?>">📁 <?= e($sf['name']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- ===== Verknüpfungen-Block (Ordner ↔ Entitäten) ===== -->
          <?php if ($ctxRel !== '' && table_exists($mysqli, 'ordner_links')): ?>
            <?php
              $sqlL = "SELECT ol.*,
                              COALESCE(NULLIF(b.name,''), TRIM(CONCAT_WS(' ', NULLIF(b.nachname,''), NULLIF(b.vorname,'')))) AS benutzer_anzeige
                       FROM ordner_links ol
                       LEFT JOIN benutzer b ON b.id = ol.benutzer_id
                       WHERE ol.project_id=? AND ol.fs_rel_path=?
                       ORDER BY ol.typ, benutzer_anzeige, ol.id";
              $stL = $mysqli->prepare($sqlL);
              $stL->bind_param('is', $projekt_id, $ctxRel);
              $stL->execute();
              $links = $stL->get_result()->fetch_all(MYSQLI_ASSOC);
              $stL->close();
              $linkAdd = page_url('ordner_verknuepfen.php?projekt_id='.$projekt_id.'&path='.rawurlencode($ctxRel));
            ?>
            <div class="card" style="margin-top:10px;">
              <div class="body" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                <strong>🔗 Verknüpfungen für <code><?= e($ctxRel) ?></code></strong>
                <a class="badge" href="<?= e($linkAdd) ?>">＋ Verknüpfung hinzufügen</a>
              </div>
              <div class="body" style="padding-top:0">
                <?php if ($links): ?>
                  <ul style="margin:6px 0 0 18px">
                    <?php foreach ($links as $L):
                      $anzeige = $L['label'] ?: ($L['benutzer_anzeige'] ?: ('#'.$L['benutzer_id']));
                    ?>
                      <li><?= e($L['typ']) ?>: <?= e($anzeige) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <div class="small" style="color:#6b7280">Keine Verknüpfungen vorhanden.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <!-- ===== Ende Verknüpfungen-Block ===== -->

        </div>
      </div>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
      <div class="card" style="border-bottom: 4px solid #3b82f6;"><div class="body" style="text-align:center"><h2><?= (int)$kpis['vermietet_prozent'] ?>%</h2><div class="small">Vermietungsstand</div></div></div>
      <div class="card" style="border-bottom: 4px solid #f59e0b;"><div class="body" style="text-align:center"><h2><?= (int)$kpis['pendenzen'] ?></h2><div class="small">Offene Pendenzen</div></div></div>
      <div class="card" style="border-bottom: 4px solid #10b981;"><div class="body" style="text-align:center"><h2><?= (int)$kpis['wohnungen'] ?></h2><div class="small">Mieteinheiten</div></div></div>
      <div class="card" style="border-bottom: 4px solid <?= $kpis['sync_errors'] > 0 ? '#ef4444' : '#10b981' ?>;"><div class="body" style="text-align:center"><h2><?= (int)$kpis['sync_errors'] ?></h2><div class="small">Drive-Fehler</div></div></div>
    </div>

    <!-- Mietvertrag-Radar & Quick Insights -->
    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px; margin-top:20px;">
        <div class="card">
            <div class="body">
                <h3 style="margin:0 0 15px 0; font-size:14px; color:#64748b; text-transform:uppercase; letter-spacing:0.05em;">🛎️ Mietvertrag-Radar (Nächste 6 Monate)</h3>
                <?php if (empty($expiringLeases)): ?>
                    <div class="small muted" style="padding:20px; text-align:center;">Keine auslaufenden Verträge in Sicht.</div>
                <?php else: ?>
                    <table class="tbl">
                        <thead><tr><th>Wohnung</th><th>Mieter</th><th>Start</th><th>Ende</th></tr></thead>
                        <tbody>
                            <?php foreach($expiringLeases as $l): ?>
                                <tr>
                                    <td><strong><?= e($l['w_name']) ?></strong></td>
                                    <td><?= e($l['nachname']) ?> <?= e($l['vorname'] ?? '') ?></td>
                                    <td class="small"><?= date('d.m.Y', strtotime($l['startdatum'])) ?></td>
                                    <td class="small" style="color:#ef4444; font-weight:600;"><?= date('d.m.Y', strtotime($l['enddatum'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <div class="card" style="background: linear-gradient(135deg, #0ea5e9, #0284c7); color:#fff; border:0;">
            <div class="body">
                <h3 style="margin:0 0 15px 0; font-size:14px; opacity:0.8; text-transform:uppercase;">🏢 Objekt-Fokus</h3>
                <div style="font-size:24px; font-weight:800;"><?= (int)$kpis['objekte'] ?> Gebäude</div>
                <p style="font-size:13px; opacity:0.9; line-height:1.4;">Alle Gebäude sind aktuell synchronisiert. Nutzen Sie den Mieterspiegel für detaillierte Etagen-Pläne.</p>
                <a class="btn" href="mieterspiegel.php?projekt_id=<?= $projekt_id ?>" style="background:rgba(255,255,255,0.2); border:1px solid rgba(255,255,255,0.3); width:100%; text-align:center; margin-top:10px;">Zum Mieterspiegel →</a>
            </div>
        </div>
    </div>

    <!-- Ordner-Kacheln (mit Vorschaubild) -->
    <h2 style="margin:40px 0 16px;">Inhalt</h2>
    <div class="cards">
      <?php if (empty($subfolders)): ?>
        <div class="card"><div class="body">Keine Unterordner gefunden.</div></div>
      <?php else: foreach ($subfolders as $sf):
        $rel = $sf['rel_path'];
        $title = $sf['name'];
        $cover = first_preview_for($mysqli,$projekt_id,$rel) ?: brand_url('logo-mark.png');
        $st = $mysqli->prepare("SELECT SUM(is_dir=1) d, SUM(is_dir=0) f FROM fs_nodes WHERE project_id=? AND parent_rel_path=?");
        $st->bind_param("is",$projekt_id,$rel); $st->execute();
        $cnt = $st->get_result()->fetch_assoc() ?: ['d'=>0,'f'=>0]; $st->close();

        // Icon holen
        $mQ = $mysqli->query("SELECT icon FROM fs_folder_meta WHERE project_id=$projekt_id AND rel_path='".$mysqli->real_escape_string($rel)."'");
        $icon = ($mR = $mQ->fetch_assoc()) ? $mR['icon'] : '📁';
        if (!$icon) $icon = '📁';

        $navUrl   = page_url('projekt_dashboard.php?id='.$projekt_id.'&path='.rawurlencode($rel).$select_qs);
        $filesUrl = page_url('files.php?projekt_id='.$projekt_id).'&path='.rawurlencode($rel);
        $pendUrl  = page_url('pendenzen.php?projekt_id='.$projekt_id).'&fs_rel_path='.rawurlencode($rel);
        $useUrl = $select_mode
          ? ($return_to
              . $join2
              . 'selected_path=' . urlencode($card_abs)
              . ($scope!=='' ? '&scope='.urlencode($scope) : '')
              . ($ve_id ? '&ve_id='.$ve_id : ''))
          : '';
      ?>
        <div class="card">
          <a class="cover" href="<?= e($navUrl) ?>" title="Öffnen">
            <?php $realImg = first_preview_for($mysqli,$projekt_id,$rel); ?>
            <?php if($realImg): ?>
              <img src="<?= e($realImg) ?>" alt="">
            <?php else: ?>
              <div class="icon-placeholder"><?= $icon ?></div>
            <?php endif; ?>
          </a>
          <div class="body">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
              <div style="font-weight:600; font-size:16px;"><?= $icon ?> <?= e($title) ?></div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <a class="badge" href="<?= e($navUrl) ?>">Öffnen ›</a>
                <?php if ($select_mode): ?>
                  <a class="badge" style="background:#e6ffed;border-color:#b6f0c2" href="<?= e($useUrl) ?>">✅ Verwenden</a>
                <?php endif; ?>
              </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
              <span class="badge">📁 <?= (int)($cnt['d']??0) ?> Ordner</span>
              <span class="badge">📄 <?= (int)($cnt['f']??0) ?> Dateien</span>
              <a class="badge" href="<?= e($filesUrl) ?>">Dateien</a>
              <a class="badge" href="<?= e($pendUrl) ?>">Pendenzen</a>
            </div>

            <details style="margin-top:12px; border-top:1px solid #f1f5f9; padding-top:8px;">
              <summary class="small" style="cursor:pointer; color:#3b82f6; font-weight:600; list-style:none;">⚙️ Darstellung anpassen</summary>
              <div style="background:#f8fafc; border-radius:8px; padding:12px; margin-top:8px; border:1px solid #e2e8f0;">
                
                <!-- Titel ändern -->
                <form method="post" style="margin-bottom:12px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_title">
                  <input type="hidden" name="rel_path" value="<?= e($rel) ?>">
                  <div style="font-size:11px; font-weight:700; color:#64748b; margin-bottom:4px; text-transform:uppercase;">Titel</div>
                  <div style="display:flex; gap:6px;">
                    <input type="text" name="title" value="<?= e($title) ?>" style="flex:1; padding:6px; border:1px solid #cbd5e1; border-radius:4px; font-size:13px;">
                    <button type="submit" class="badge" style="background:#3b82f6; color:#fff; border:0; cursor:pointer;">Speichern</button>
                  </div>
                </form>

                <!-- Icon/Symbol Schnellauswahl -->
                <div style="margin-bottom:12px;">
                  <div style="font-size:11px; font-weight:700; color:#64748b; margin-bottom:4px; text-transform:uppercase;">Symbol</div>
                  <div style="display:flex; gap:4px; flex-wrap:wrap;">
                    <?php foreach(['📁','🏠','🏢','📦','📄','🏗️','🔧','🔑','⚡','📝','🧹'] as $emoji): ?>
                      <form method="post" style="margin:0;">
                         <?= csrf_field() ?>
                         <input type="hidden" name="action" value="set_icon_quick">
                         <input type="hidden" name="rel_path" value="<?= e($rel) ?>">
                         <input type="hidden" name="icon" value="<?= $emoji ?>">
                         <button type="submit" style="background:#fff; border:1px solid #e2e8f0; border-radius:4px; cursor:pointer; padding:4px; font-size:16px;"><?= $emoji ?></button>
                      </form>
                    <?php endforeach; ?>
                  </div>
                </div>

                <!-- Cover aus Dateien / Upload -->
                <div>
                  <div style="font-size:11px; font-weight:700; color:#64748b; margin-bottom:4px; text-transform:uppercase;">Bild aus Ordner / Upload</div>
                  <div style="display:flex; gap:6px; overflow-x:auto; padding-bottom:6px; margin-top:4px;">
                    <form method="post" enctype="multipart/form-data" style="margin:0; flex-shrink:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_cover">
                      <input type="hidden" name="rel_path" value="<?= e($rel) ?>">
                      <input type="file" name="cover" style="display:none;" id="up_<?= md5($rel) ?>" onchange="this.form.submit()">
                      <button type="button" class="btn-icon" onclick="document.getElementById('up_<?= md5($rel) ?>').click()" style="width:50px; height:40px; background:#e0f2fe; color:#0369a1; border-radius:4px; border:1px dashed #0369a1; cursor:pointer; font-size:18px;">➕</button>
                    </form>
                    <?php 
                      $cands = image_candidates($mysqli, $projekt_id, $rel);
                      foreach($cands as $can): 
                    ?>
                      <form method="post" style="margin:0; flex-shrink:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_cover_from_fs">
                        <input type="hidden" name="rel_path" value="<?= e($rel) ?>">
                        <input type="hidden" name="file_rel_path" value="<?= e($can['rel_path']) ?>">
                        <button type="submit" style="background:none; border:0; cursor:pointer; padding:0;">
                          <img src="<?= page_url('file.php?projekt_id='.$projekt_id.'&path='.rawurlencode($can['rel_path'])) ?>" style="width:50px; height:40px; object-fit:cover; border-radius:4px; border:1px solid #e2e8f0;">
                        </button>
                      </form>
                    <?php endforeach; ?>
                  </div>
                </div>

              </div>
            </details>

            <details style="margin-top:10px; border-top:1px solid #f1f5f9; padding-top:8px;">
              <summary class="small" style="cursor:pointer; color:#059669; font-weight:600; list-style:none;">📁 Inhalt anzeigen (<?= (int)($cnt['d']??0) ?> / <?= (int)($cnt['f']??0) ?>)</summary>
              <div style="background:#f0fdf4; border-radius:8px; padding:10px; margin-top:8px; border:1px solid #d1fae5; font-size:13px;">
                <?php 
                   $subSub = child_folders($mysqli, $projekt_id, $rel); 
                   if(empty($subSub)) echo '<div class="muted">Keine Unterordner.</div>';
                   foreach($subSub as $ss):
                ?>
                  <div style="padding:4px 0;"><a href="?id=<?= $projekt_id ?>&path=<?= rawurlencode($ss['rel_path']) ?>" style="text-decoration:none; color:#1e293b;"><?= $ss['icon'] ?> <?= e($ss['name']) ?></a></div>
                <?php endforeach; ?>
                <?php 
                   $subFiles = $mysqli->query("SELECT name FROM fs_nodes WHERE project_id=$projekt_id AND parent_rel_path='".$mysqli->real_escape_string($rel)."' AND is_dir=0 AND name NOT LIKE 'desktop.ini' AND name NOT LIKE '._%' AND name NOT LIKE '.DS_Store' ORDER BY name LIMIT 20")->fetch_all(MYSQLI_ASSOC);
                   if($subFiles) echo '<div style="margin-top:6px; border-top:1px dashed #d1fae5; padding-top:4px;">';
                   foreach($subFiles as $sf):
                ?>
                  <div style="padding:2px 0; font-size:12px; color:#475569;">📄 <?= e($sf['name']) ?></div>
                <?php endforeach; ?>
                <?php if($subFiles) echo '</div>'; ?>
              </div>
            </details>

          </div> <!-- Ende body -->
        </div> <!-- Ende card -->
      <?php endforeach; endif; ?>
    </div>


    <!-- Pendenzen (volle Breite) -->
    <h2 style="margin:22px 0 8px;">Pendenzen <?= $ctxRel!==''? 'in „'.e($ctxRel).'”' : '(Projektweit)' ?></h2>
    <div class="card">
      <div class="body" style="padding:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
          <div class="small">Gefiltert nach: <?= $ctxRel!==''? '<code>'.e($ctxRel).'</code>' : 'keinem Ordnerfilter' ?></div>
          <a class="btn" href="<?= e(page_url('pendenzen_liste.php').'?projekt_id='.$projekt_id.($ctxRel!==''?'&ordner='.rawurlencode($ctxRel):'')) ?>">Alle in Liste öffnen</a>
        </div>
        <table class="tbl" style="margin-top:10px;">
          <thead><tr><th>Titel</th><th>Fällig</th><th>Status</th><th>Ordner</th><th>Aktion</th></tr></thead>
          <tbody>
          <?php if (!$pendenzen): ?>
            <tr><td colspan="5" style="padding:10px;color:#666;">Keine Pendenzen gefunden.</td></tr>
          <?php else: foreach ($pendenzen as $p):
            $due = $p['enddatum'] ? date('d.m.Y', strtotime($p['enddatum'])) : '—';
            $pRel= $p['fs_rel_path'] ?: '';
            $pHref = page_url('projekt_dashboard.php?id='.$projekt_id.($pRel!==''?'&path='.rawurlencode($pRel):'').$select_qs);
          ?>
            <tr>
              <td><?= e($p['titel'] ?? '') ?></td>
              <td><?= e($due) ?></td>
              <td><?= e($p['status'] ?? '') ?></td>
              <td><?= $pRel!=='' ? '<a href="'.e($pHref).'">'.e($pRel).'</a>' : '—' ?></td>
              <td><a class="btn" href="pendenzen.php?edit=<?= (int)$p['id'] ?>">Bearbeiten</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Neueste Dateien -->
    <h2 style="margin:22px 0 8px;">Neueste Dateien <?= $ctxRel!==''? 'in „'.e($ctxRel).'”' : '(Projektweit)' ?></h2>
    <div class="card">
      <div class="body" style="padding:12px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
          <span class="small">Tipp: Lege <code>_preview</code> an – neue Bilder dienen als Cover.</span>
          <a class="btn" href="<?= e(page_url('files.php?projekt_id='.$projekt_id).($ctxRel!==''?'&path='.rawurlencode($ctxRel):'')) ?>">Dateibrowser öffnen</a>
        </div>
        <table class="tbl" style="margin-top:10px;">
          <thead><tr><th>Name</th><th>Ordner</th><th>Geändert</th><th>Größe</th><th>Aktion</th></tr></thead>
        <tbody>
          <?php
          $fmtSize=function($b){ $b=(int)$b; if($b<=0) return '—'; $u=['B','KB','MB','GB','TB']; $i=0; while($b>=1024 && $i<count($u)-1){$b/=1024; $i++;} return number_format($b,($i===0?0:1),',','.').' '.$u[$i]; };
          if ($ctxRel==='') {
            $stmtF=$mysqli->prepare("SELECT name, rel_path, parent_rel_path, mtime, size FROM fs_nodes WHERE project_id=? AND is_dir=0 AND name NOT LIKE 'desktop.ini' AND name NOT LIKE '._%' AND name NOT LIKE '.DS_Store' ORDER BY mtime DESC LIMIT 12");
            $stmtF->bind_param("i",$projekt_id);
          } else {
            $stmtF=$mysqli->prepare("SELECT name, rel_path, parent_rel_path, mtime, size FROM fs_nodes WHERE project_id=? AND is_dir=0 AND (parent_rel_path=? OR parent_rel_path LIKE CONCAT(?, '/%')) AND name NOT LIKE 'desktop.ini' AND name NOT LIKE '._%' AND name NOT LIKE '.DS_Store' ORDER BY mtime DESC LIMIT 12");
            $stmtF->bind_param("iss",$projekt_id,$ctxRel,$ctxRel);
          }
          $stmtF->execute(); $rf=$stmtF->get_result();
          if ($rf->num_rows===0): ?>
            <tr><td colspan="5" style="padding:10px;color:#666;">Keine Dateien gefunden.</td></tr>
          <?php else: while($f=$rf->fetch_assoc()): 
            $folder = $f['parent_rel_path'] ?: '(Root)';
            $mt = $f['mtime'] ? date('d.m.Y H:i', strtotime($f['mtime'])) : '—';
            $openFolderUrl = 'files.php?projekt_id='.(int)$projekt_id.'&path='.rawurlencode($f['parent_rel_path'] ?? '');
          ?>
            <tr>
              <td><a href="<?= e(page_url('file.php?projekt_id='.$projekt_id.'&path='.rawurlencode($f['rel_path']))) ?>">📄 <?= e($f['name']) ?></a></td>
              <td><a href="<?= e(page_url('projekt_dashboard.php?id='.$projekt_id.($f['parent_rel_path']? '&path='.rawurlencode($f['parent_rel_path']) : '').$select_qs)) ?>"><?= e($folder) ?></a></td>
              <td><?= e($mt) ?></td>
              <td><?= e($fmtSize($f['size'])) ?></td>
              <td><a class="btn" href="<?= e($openFolderUrl) ?>">Ordner öffnen</a></td>
            </tr>
          <?php endwhile; endif; $stmtF->close(); ?>
        </tbody>
        </table>
      </div>
    </div>
  </section>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
