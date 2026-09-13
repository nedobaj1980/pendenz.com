<?php
// pages/ordner_verknuepfen.php — kompakt & übersichtlich: 1-Zeilen-Toolbar, 2-Zeilen-Editbar, Einklappen
// + Ordner-Existenz, Kategorie, Breadcrumbs, Copy-Buttons

if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();

require_once __DIR__ . '/../includes/functions.php'; // e(), url(), page_url()
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/fs.php'; // project_root_path(), fs_abs_from_rel(), fs_scan_project()

// optional: Miete/Konto
$rent_available = false;
$rent_funcs = ['rent_history','konto_list','saldo_bis','rent_upsert_period','rent_book_month','rent_add_booking'];
$rent_available = @include_once __DIR__.'/../includes/rent.php';
foreach ($rent_funcs as $fn) { if (!function_exists($fn)) { $rent_available=false; break; } }

/* ---- helpers ---- */
if (!function_exists('en')) { function en($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('page_url')) { function page_url($p){ return '/pendenz.com/pages/'.ltrim($p,'/'); } }
function csrf_token_value(){ if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['csrf_token']; }
function csrf_field(){ echo '<input type="hidden" name="csrf" value="'.en(csrf_token_value()).'">'; }
function csrf_validate(){ $tok=$_POST['csrf']??''; if(!$tok||!hash_equals($_SESSION['csrf_token']??'', $tok)) throw new Exception('Ungültiger CSRF-Token'); }

function table_exists(mysqli $db, $t){
  $st=$db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
  $st->bind_param("s",$t); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function column_exists(mysqli $db, $t, $c){
  $st=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $st->bind_param("ss",$t,$c); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/* ---- Eingaben ---- */
$projekt_id = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_GET['id'] ?? 0);
$ctxRel     = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';
if ($projekt_id<=0) die('projekt_id fehlt.');

/* ---- POST: speichern / aktualisieren / rent-actions ---- */
$flash='';
try{
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_validate();
    $act=$_POST['action'] ?? '';

    if ($act==='save_link'){ // neuer Link
      $fs_rel_path  = trim((string)($_POST['fs_rel_path'] ?? ''));
      $typ          = 'benutzer';
      $benutzer_id  = ($_POST['benutzer_id'] ?? '')!=='' ? (int)$_POST['benutzer_id'] : null;
      $label_mode   = $_POST['label_mode'] ?? 'auto';
      $label_choose = trim((string)($_POST['label_choose'] ?? ''));
      $label_custom = trim((string)($_POST['label_custom'] ?? ''));
      $bemerkung    = trim((string)($_POST['bemerkung'] ?? ''));

      if(!$fs_rel_path) throw new Exception('Kein Ordnerpfad.');
      if(!$benutzer_id) throw new Exception('Kein Benutzer gewählt.');

      if(!table_exists($mysqli,'fs_nodes')) throw new Exception('fs_nodes fehlt.');
      $chk=$mysqli->prepare("SELECT 1 FROM fs_nodes WHERE project_id=? AND rel_path=? AND is_dir=1 LIMIT 1");
      $chk->bind_param('is',$projekt_id,$fs_rel_path); $chk->execute();
      $exists=(bool)$chk->get_result()->fetch_row(); $chk->close();
      if(!$exists) throw new Exception('Ordner existiert nicht (fs_nodes).');

      // Benutzer laden für Auto-Label
      $has_name=column_exists($mysqli,'benutzer','name');
      $has_vor=column_exists($mysqli,'benutzer','vorname');
      $has_nach=column_exists($mysqli,'benutzer','nachname');
      $has_roll=column_exists($mysqli,'benutzer','rolle');
      $has_ptyp=column_exists($mysqli,'benutzer','personen_typ');
      $has_stat=column_exists($mysqli,'benutzer','status');

      $cols=['id']; if($has_name)$cols[]='name'; if($has_nach)$cols[]='nachname'; if($has_vor)$cols[]='vorname';
      if($has_roll)$cols[]='rolle'; if($has_ptyp)$cols[]='personen_typ'; if($has_stat)$cols[]='status';
      $stU=$mysqli->prepare("SELECT ".implode(',',$cols)." FROM benutzer WHERE id=? LIMIT 1");
      $stU->bind_param('i',$benutzer_id); $stU->execute(); $user=$stU->get_result()->fetch_assoc(); $stU->close();

      // Label bestimmen
      $label='';
      if ($label_mode==='custom') $label = $label_custom ?: 'Kontakt';
      elseif ($label_mode==='choose') $label = $label_choose ?: 'Kontakt';
      else {
        $cand=[];
        if($has_ptyp && !empty($user['personen_typ'])) $cand[]=$user['personen_typ'];
        if($has_stat && !empty($user['status'])) $cand[]=$user['status'];
        if($has_roll && !empty($user['rolle'])) $cand[]=$user['rolle'];
        $label='Kontakt';
        foreach($cand as $c){ if(mb_strtolower($c,'UTF-8')==='mieter'){ $label='Mieter'; break; } }
        if($label==='Kontakt' && $cand) $label=$cand[0];
      }

      if(!table_exists($mysqli,'ordner_links')){
        $sql="CREATE TABLE ordner_links (
          id INT AUTO_INCREMENT PRIMARY KEY,
          project_id INT NOT NULL,
          fs_rel_path VARCHAR(1024) NOT NULL,
          typ ENUM('benutzer','unternehmen','konto','sonstiges') NOT NULL DEFAULT 'benutzer',
          benutzer_id INT NULL,
          ziel_id INT NULL,
          status VARCHAR(64) NULL,
          label VARCHAR(255) NULL,
          beginn DATE NULL,
          ende   DATE NULL,
          bemerkung TEXT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_link (project_id, fs_rel_path, typ, benutzer_id, ziel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        if(!$mysqli->query($sql)) throw new Exception('Tabelle ordner_links konnte nicht erstellt werden: '.$mysqli->error);
      }

      $nil=null;
      // 7 Variablen -> Typstring 'issiiss' (i,s,s,i,i,s,s)
      $st=$mysqli->prepare("INSERT IGNORE INTO ordner_links (project_id, fs_rel_path, typ, benutzer_id, ziel_id, label, bemerkung) VALUES (?,?,?,?,?,?,?)");
      $st->bind_param('issiiss', $projekt_id, $fs_rel_path, $typ, $benutzer_id, $nil, $label, $bemerkung);
      $st->execute(); $dup=($st->affected_rows===0); $st->close();

      $flash = $dup ? 'ℹ️ Verknüpfung existierte bereits.' : '✅ Verknüpfung gespeichert.';
    }

    if ($act==='update_link'){ // aktualisieren + ggf. verschieben/umbenennen
      $id=(int)($_POST['id'] ?? 0);
      if($id<=0) throw new Exception('Ungültige ID.');

      // alt laden
      $q=$mysqli->prepare("SELECT * FROM ordner_links WHERE id=? AND project_id=?");
      $q->bind_param('ii',$id,$projekt_id); $q->execute(); $L=$q->get_result()->fetch_assoc(); $q->close();
      if(!$L) throw new Exception('Datensatz nicht gefunden.');

      $new_label = trim((string)($_POST['label'] ?? ''));
      $new_status= trim((string)($_POST['status'] ?? ''));
      $new_beginn= ($_POST['beginn'] ?? '') ?: null;
      $new_ende  = ($_POST['ende'] ?? '') ?: null;
      $new_bem   = trim((string)($_POST['bemerkung'] ?? ''));

      // Ordner verschieben bei Status-Änderung:  Mieter→Vormieter etc.
      $old_rel = $L['fs_rel_path'];
      $rootAbs = project_root_path($mysqli,$projekt_id);
      $final_rel = $old_rel;

      if ($rootAbs && $old_rel!=='') {
        $srcAbs = fs_abs_from_rel($rootAbs, $old_rel);
        if ($srcAbs && is_dir($srcAbs)) {
          // Ziel-Basis: gleicher Einheitspfad; Kategorie-Folder wählen
          $unitRel = $old_rel;
          $lower = mb_strtolower($unitRel,'UTF-8');
          foreach (['/mieter/','/vormieter/','/unternehmer/','/kontakt/','/interessent/'] as $needle) {
            if (($p=strpos($lower,$needle))!==false){ $unitRel = substr($unitRel,0,$p); break; }
          }
          $category = 'Mieter';
          if ($new_status) {
            $map = [
              'mietinteressent'=>'Interessent',
              'mieter'=>'Mieter',
              'vormieter'=>'Vormieter',
              'unternehmer'=>'Unternehmer',
              'kontakt'=>'Kontakt'
            ];
            $category = $map[strtolower($new_status)] ?? 'Mieter';
          }

          $catRel = rtrim($unitRel,'/').'/'.$category;
          $catAbs = fs_abs_from_rel($rootAbs, $catRel);
          if ($catAbs && !is_dir($catAbs)) @mkdir($catAbs,0777,true);

          // Neuer Ordnername: <id>_<Label>
          $safeLabel = preg_replace('~[^\p{L}\p{N}\s\-_]+~u','', (string)($new_label ?: ($L['label'] ?: 'Kontakt')));
          $safeLabel = trim(preg_replace('~\s+~',' ', $safeLabel));
          if ($safeLabel==='') $safeLabel = 'Kontakt';
          $newBase = $id.'_'.$safeLabel;

          $dstRel = $catRel.'/'.$newBase;
          $dstAbs = fs_abs_from_rel($rootAbs, $dstRel);
          $i=2;
          while($dstAbs && is_dir($dstAbs)){
            $dstRel = $catRel.'/'.$newBase.'-'.$i;
            $dstAbs = fs_abs_from_rel($rootAbs,$dstRel);
            $i++; if($i>200) break;
          }

          if ($dstAbs) {
            if (!is_dir(dirname($dstAbs))) @mkdir(dirname($dstAbs),0777,true);
            if (@rename($srcAbs, $dstAbs)) {
              $final_rel = $dstRel;
              if (function_exists('fs_scan_project')) { @fs_scan_project($mysqli,$projekt_id,0); }
            }
          }
        }
      }

      $st=$mysqli->prepare("UPDATE ordner_links SET label=?, status=?, beginn=?, ende=?, bemerkung=?, fs_rel_path=? WHERE id=? AND project_id=?");
      $st->bind_param('ssssssii',$new_label,$new_status,$new_beginn,$new_ende,$new_bem,$final_rel,$id,$projekt_id);
      $st->execute(); $st->close();

      $flash='✅ Aktualisiert'.($final_rel!==$old_rel?' & verschoben: <code>'.en($final_rel).'</code>':'').'.';
    }

    // --- rent actions (nur wenn rent.php verfügbar) ---
    if ($rent_available) {
      if ($act==='rent_add_period') {
        $link_id=(int)($_POST['link_id'] ?? 0);
        rent_upsert_period($mysqli, $link_id, $_POST['rent_from'] ?? null, $_POST['rent_miete'] ?? null, $_POST['rent_nk'] ?? null, $_POST['rent_note'] ?? '');
        $flash='✅ Tarif gespeichert.';
      }
      if ($act==='rent_book_month') {
        $link_id=(int)($_POST['link_id'] ?? 0);
        rent_book_month($mysqli, $link_id, (int)($_POST['book_year']??date('Y')), (int)($_POST['book_month']??date('n')));
        $flash='✅ Sollstellung gebucht.';
      }
      if ($act==='rent_add_booking') {
        $link_id=(int)($_POST['link_id'] ?? 0);
        rent_add_booking($mysqli, $link_id, $_POST['bk_date'] ?? date('Y-m-d'), $_POST['bk_typ'] ?? 'zahlung', (float)($_POST['bk_amount']??0), $_POST['bk_ref'] ?? '', $_POST['bk_note'] ?? '');
        $flash='✅ Buchung erfasst.';
      }
    }

  }
} catch(Throwable $e){ $flash='❌ '.$e->getMessage(); }

/* ---- Benutzerliste für Auswahl ---- */
$users=[];
if (table_exists($mysqli,'benutzer')) {
  $has_name = column_exists($mysqli,'benutzer','name');
  $has_nach = column_exists($mysqli,'benutzer','nachname');
  $has_vor  = column_exists($mysqli,'benutzer','vorname');
  $has_roll = column_exists($mysqli,'benutzer','rolle');
  $has_ptyp = column_exists($mysqli,'benutzer','personen_typ');
  $has_stat = column_exists($mysqli,'benutzer','status');

  $select = "id";
  $select .= $has_name ? ", name" : "";
  $select .= $has_nach ? ", nachname" : "";
  $select .= $has_vor  ? ", vorname" : "";
  $select .= $has_roll ? ", rolle" : "";
  $select .= $has_ptyp ? ", personen_typ" : "";
  $select .= $has_stat ? ", status" : "";

  $st=$mysqli->prepare("SELECT $select FROM benutzer ORDER BY ".($has_name?"name":"id").", id");
  $st->execute(); $res=$st->get_result();
  while($r=$res->fetch_assoc()){
    $label='';
    if ($has_name && !empty($r['name'])) $label=$r['name'];
    if ($label===''){
      $parts=[]; if($has_nach && !empty($r['nachname'])) $parts[]=$r['nachname'];
      if($has_vor && !empty($r['vorname'])) $parts[]=$r['vorname'];
      $label=trim(implode(' ',$parts));
      if($label==='') $label='#'.$r['id'];
    }
    $r['_label']=$label;
    $r['_rolle']=$has_roll?($r['rolle']??''):'';  $r['_ptyp'] =$has_ptyp?($r['personen_typ']??''):'';
    $r['_status']=$has_stat?($r['status']??''):'';
    $users[]=$r;
  }
  $st->close();
}

/* ---- Links dieser Einheit laden ---- */
$links=[];
if (table_exists($mysqli,'ordner_links')) {
  $q=$mysqli->prepare("SELECT ol.*, b.name,
    TRIM(CONCAT_WS(' ', COALESCE(b.nachname,''), COALESCE(b.vorname,''))) AS nf
    FROM ordner_links ol
    LEFT JOIN benutzer b ON b.id=ol.benutzer_id
    WHERE ol.project_id=? AND ol.fs_rel_path LIKE CONCAT(?, '%')
    ORDER BY COALESCE(ol.beginn,'1900-01-01') DESC, ol.id DESC");
  $prefix = $ctxRel ?: '';
  $q->bind_param('is',$projekt_id,$prefix);
  $q->execute(); $links=$q->get_result()->fetch_all(MYSQLI_ASSOC); $q->close();
}

// Projekt-Root (für Existenzcheck & Breadcrumbs)
$rootAbs = project_root_path($mysqli, $projekt_id);

/* ---- Label-Pool für Vorschlag ---- */
$default_label_pool = ['Mieter','Hauptmieter','Mitbewohner','Unternehmer','Elektriker','Hauswart','Reinigung','Stromrechnung','Konto','Kontakt'];

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Ordner verknüpfen</title>
<link rel="stylesheet" href="../assets/app.css">
<style>
/* Seite & Grundlayout */
html,body{height:100%}
body{background:#f5f7fb}
.container{max-width:1600px;margin:20px auto;padding:10px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
.btn{display:inline-block;padding:8px 10px;border-radius:10px;background:#0a2a6e;color:#fff;border:0;cursor:pointer;text-decoration:none}
.btn:hover{background:#071c4a}
.small{font-size:12px;color:#6b7280}
.badge{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px;background:#f9fafb}
input,select,textarea{padding:8px;border:1px solid #e5e7eb;border-radius:10px}

/* Toolbar: alles in 1 Zeile (scrollbar wenn eng) */
.toolbar-1line{display:flex;align-items:center;gap:10px;flex-wrap:nowrap;overflow:auto;padding:10px}
.toolbar-1line .spacer{flex:1}
.toolbar-1line .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;background:#fff;white-space:nowrap}

/* Grid für Karten */
.group .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(1060px,1fr));gap:14px;margin-top:12px}

/* Personenkarte */
.person-card{border:1px solid #e5e7eb;border-radius:16px;padding:12px;background:#fff;display:flex;flex-direction:column;gap:10px;box-shadow:0 1px 0 rgba(17,24,39,.03), 0 1px 3px rgba(17,24,39,.06)}
.person-head{display:flex;align-items:center;gap:10px;justify-content:space-between;cursor:pointer}
.person-title{font-weight:700;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:70%}
.person-meta{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.person-actions{display:flex;gap:8px;flex-wrap:wrap}
.person-body{display:none;border-top:1px dashed #e5e7eb;padding-top:10px}
.person-card.open .person-body{display:block}

/* Bearbeiten-&-verschieben: 2-Zeilen-Grid */
.editbar2{
  display:grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(160px, auto);
  grid-template-rows: auto auto;
  gap:8px 10px;
  align-items:end;
  background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px
}
.editbar2 .f{display:flex;flex-direction:column;gap:4px}
.editbar2 .f label{font-size:11px;color:#6b7280}
.editbar2 input[type="text"], .editbar2 input[type="date"], .editbar2 select{padding:6px 8px;border-radius:8px;border:1px solid #e5e7eb;min-width:160px}
.editbar2 .wide{min-width:260px}
.editbar2 .actions{display:flex;gap:8px;align-items:center}

/* Kompakte Miete-&-Konto-Leiste */
.rentbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:6px}
.rentbar .grp{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:4px 6px;background:#fff;border:1px solid #e5e7eb;border-radius:8px}
.rentbar .gtitle{font-weight:600;font-size:12px;color:#374151}
.rentbar input[type="date"], .rentbar input[type="number"], .rentbar input[type="text"], .rentbar select{width:auto;min-width:96px;max-width:150px;padding:6px 8px;border-radius:8px;font-size:13px}
.rentbar .btn{padding:6px 9px;border-radius:8px}

.rent-sections{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
.rent-sections details{flex:1 1 520px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:8px}
.rent-sections summary{cursor:pointer;font-weight:600;color:#374151;list-style:none;margin:0 0 6px}
.rentlist{max-height:220px;overflow:auto}
.rentpill{display:inline-flex;gap:8px;align-items:center;font-size:12px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;margin:3px 6px 3px 0}
</style>
</head>
<body>
<div class="container">

  <!-- Navigationsleiste -->
  <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
    <div><strong>🔗 Ordner mit Benutzer verknüpfen</strong></div>
    <div>
      <a class="badge" href="<?= en(page_url('projekt_dashboard.php'.($projekt_id?('?id='.$projekt_id):''))) ?>">Projekt-Dashboard</a>
      <a class="badge" href="<?= en(page_url('projekte.php')) ?>">Projekte</a>
      <a class="badge" href="<?= en(page_url('benutzer.php')) ?>">Benutzer</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="card" style="margin-top:10px;<?= strpos($flash,'✅')!==false?'border-color:#cde;background:#effaf0':'' ?>"><?= $flash ?></div>
  <?php endif; ?>

  <!-- Toolbar: 1 Zeile -->
  <div class="card toolbar-1line" style="margin-top:10px">
    <input type="search" id="search_input" placeholder="Suchen (Name / Label / Pfad) …" style="min-width:280px">
    <div class="chip">
      <label for="sort_sel" style="margin-right:6px">Sortieren:</label>
      <select id="sort_sel">
        <option value="begin_desc">Beginn: neu → alt</option>
        <option value="begin_asc">Beginn: alt → neu</option>
        <option value="label_asc">Label A→Z</option>
        <option value="label_desc">Label Z→A</option>
      </select>
    </div>
    <div class="spacer"></div>
    <label class="chip"><input type="checkbox" id="collapse_all"> alle einklappen</label>
    <label class="chip"><input type="checkbox" class="typeflt" value="interessent" checked> Mietinteressent</label>
    <label class="chip"><input type="checkbox" class="typeflt" value="mieter" checked> Mieter</label>
    <label class="chip"><input type="checkbox" class="typeflt" value="vormieter" checked> Vormieter</label>
    <label class="chip"><input type="checkbox" class="typeflt" value="unternehmer" checked> Unternehmer</label>
    <label class="chip"><input type="checkbox" class="typeflt" value="kontakt" checked> Kontakt</label>
  </div>

  <!-- Neu anlegen -->
  <div class="card" style="margin-top:10px">
    <form method="post">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="save_link">
      <input type="hidden" name="projekt_id" value="<?= (int)$projekt_id ?>">
      <input type="hidden" name="typ" value="benutzer">

      <div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:10px">
        <div>
          <label>Ordner (fs_rel_path)</label>
          <input id="fs_rel_path" name="fs_rel_path" type="text" placeholder="z. B. Projekt/Objekt 1/Wohnungen/Wohnung1" value="<?= en($ctxRel) ?>">
        </div>
        <div>
          <label>Benutzer</label>
          <select id="benutzer_id" name="benutzer_id" required>
            <option value="">– wählen –</option>
            <?php foreach($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>">
                <?= en($u['_label']) ?>
                <?php
                  $tagz=[]; if($u['_ptyp'])$tagz[]=$u['_ptyp']; if($u['_status'])$tagz[]=$u['_status']; if($u['_rolle'])$tagz[]=$u['_rolle'];
                  echo $tagz?(' · '.en(implode(' / ',$tagz))):'';
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Label</label>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <label class="badge" style="cursor:pointer"><input type="radio" name="label_mode" value="auto" checked> Auto</label>
            <label class="badge" style="cursor:pointer"><input type="radio" name="label_mode" value="choose"> Vorschlag</label>
            <label class="badge" style="cursor:pointer"><input type="radio" name="label_mode" value="custom"> Eigen</label>
            <select id="label_choose" name="label_choose" style="min-width:180px;display:none"></select>
            <input id="label_custom" name="label_custom" type="text" placeholder="z. B. Hauptmieter" style="display:none;min-width:180px">
            <span style="flex:1"></span>
            <button class="btn">Speichern</button>
          </div>
        </div>
      </div>
    </form>
  </div>

  <!-- Karten-Liste -->
  <section class="group" style="margin-top:10px">
    <header class="left">
      <span class="badge"><?= en(ucfirst($ctxRel ? $ctxRel : 'Root')) ?></span>
    </header>
    <div class="grid">
      <?php if(!$links): ?>
        <div class="card">Keine Verknüpfungen gefunden.</div>
      <?php else: foreach($links as $L):
        $uName   = $L['name'] ?: $L['nf'] ?: ('#'.$L['benutzer_id']);
        $status  = strtolower($L['status'] ?? '');
        if (!$status) $status = 'kontakt';
        $label   = $L['label'] ?: '—';
        $periode = (($L['beginn']??'')!==''?$L['beginn']:'—').' — '.(($L['ende']??'')!==''?$L['ende']:'—');

        // ---- Zusatzinfos: Existenz, Kategorie, Breadcrumbs, Links
        $rel = (string)$L['fs_rel_path'];
        $abs = ($rootAbs && $rel!=='') ? fs_abs_from_rel($rootAbs, $rel) : null;
        $exists = ($abs && is_dir($abs));

        $cat = null;
        if (preg_match('~/(Mieter|Vormieter|Unternehmer|Kontakt|Interessent)(?:/|$)~u', $rel, $m)) { $cat = $m[1]; }

        $crumbs = array_values(array_filter(explode('/', $rel), 'strlen'));
        $bc = ''; $acc = '';
        foreach ($crumbs as $i => $p) {
          $acc .= ($i ? '/' : '') . $p;
          $url = page_url('storage_manager.php?projekt_id='.$projekt_id.'&path='.rawurlencode($acc));
          $bc .= '<a class="badge" href="'.en($url).'">'.en($p).'</a> ';
        }
        $openStorageUrl = page_url('storage_manager.php?projekt_id='.$projekt_id.'&path='.rawurlencode($rel));
        ?>
        <div class="person-card"
             data-type="<?= en($status) ?>"
             data-beginn="<?= en($L['beginn'] ?: '') ?>"
             data-label="<?= en($label) ?>">
          <div class="person-head">
            <div class="person-title"><?= en($uName) ?></div>
            <div class="person-meta">
              <span class="badge"><?= en(ucfirst($status)) ?></span>
              <span class="badge">Label: <?= en($label) ?></span>
              <span class="badge">Zeitraum: <?= en($periode) ?></span>
              <span class="badge"><?= en(basename($L['fs_rel_path'])) ?></span>
              <span class="badge"><?= $exists ? '✅ Ordner vorhanden' : '⚠️ Ordner fehlt' ?></span>
              <?php if ($cat): ?><span class="badge">Kategorie: <?= en($cat) ?></span><?php endif; ?>
            </div>
            <div class="person-actions">
              <a class="badge toggle-card" href="#">Details ▾</a>
            </div>
          </div>

          <div class="person-body">
            <!-- Ort / Breadcrumb -->
            <div style="margin:4px 0 10px">
              <div class="small" style="margin-bottom:6px;color:#374151">
                <strong>Ort:</strong>
                <?= $bc ?: '<span class="badge">—</span>' ?>
                <a class="badge" href="<?= en($openStorageUrl) ?>">im Storage-Manager öffnen</a>
              </div>

              <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                <label class="badge">relativ</label>
                <input type="text" readonly value="<?= en($rel) ?>" style="min-width:320px" onclick="this.select()">
                <button type="button" class="btn" data-copy="<?= en($rel) ?>">Kopieren</button>

                <?php if ($abs): ?>
                  <label class="badge">absolut</label>
                  <input type="text" readonly value="<?= en($abs) ?>" style="min-width:420px" onclick="this.select()">
                  <button type="button" class="btn" data-copy="<?= en($abs) ?>">Kopieren</button>
                <?php endif; ?>
              </div>
            </div>

            <!-- Bearbeiten & verschieben (max 2 Zeilen) -->
            <h4 style="margin:6px 0">Bearbeiten &amp; verschieben</h4>
            <form method="post" class="editbar2">
              <?php csrf_field(); ?>
              <input type="hidden" name="action" value="update_link">
              <input type="hidden" name="id" value="<?= (int)$L['id'] ?>">

              <div class="f">
                <label>Label</label>
                <input type="text" name="label" value="<?= en($L['label'] ?: '') ?>" placeholder="z. B. Mieter">
              </div>

              <div class="f">
                <label>Status</label>
                <select name="status">
                  <?php foreach (['mietinteressent','mieter','vormieter','unternehmer','kontakt'] as $opt): ?>
                    <option value="<?= en($opt) ?>" <?= strtolower($L['status']??'')===$opt?'selected':'' ?>><?= ucfirst($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="f">
                <label>Beginn</label>
                <input type="date" name="beginn" value="<?= en($L['beginn'] ?: '') ?>">
              </div>

              <div class="f">
                <label>Ende</label>
                <input type="date" name="ende" value="<?= en($L['ende'] ?: '') ?>">
              </div>

              <div class="f" style="grid-column:auto / span 2">
                <label>Bemerkung</label>
                <input class="wide" type="text" name="bemerkung" value="<?= en($L['bemerkung'] ?: '') ?>" placeholder="Bemerkung">
              </div>

              <div class="actions">
                <button class="btn">Update &amp; verschieben</button>
              </div>
            </form>

            <?php if ($rent_available): ?>
            <fieldset style="margin-top:8px">
              <legend>💶 Miete &amp; Konto (ID: <?= (int)$L['id'] ?>)</legend>
              <div class="rentbar">
                <!-- Tarif -->
                <form class="grp" method="post">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="rent_add_period">
                  <input type="hidden" name="link_id" value="<?= (int)$L['id'] ?>">
                  <span class="gtitle">Tarif</span>
                  <input type="date"   name="rent_from"  title="ab" required>
                  <input type="number" step="0.01" name="rent_miete" placeholder="Miete" title="Mietzins netto" required>
                  <input type="number" step="0.01" name="rent_nk"    placeholder="NK"    title="Nebenkosten Akonto">
                  <input type="text"   name="rent_note"  placeholder="Notiz" style="min-width:140px">
                  <button class="btn">OK</button>
                </form>
                <!-- Soll -->
                <form class="grp" method="post">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="rent_book_month">
                  <input type="hidden" name="link_id" value="<?= (int)$L['id'] ?>">
                  <span class="gtitle">Soll</span>
                  <input type="number" name="book_year"  value="<?= (int)date('Y') ?>" min="2000" max="2100" title="Jahr">
                  <input type="number" name="book_month" value="<?= (int)date('n') ?>" min="1" max="12" title="Monat">
                  <button class="btn">Buchen</button>
                </form>
                <!-- Buchung -->
                <form class="grp" method="post">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="action" value="rent_add_booking">
                  <input type="hidden" name="link_id" value="<?= (int)$L['id'] ?>">
                  <span class="gtitle">Buchung</span>
                  <input type="date"   name="bk_date"   value="<?= en(date('Y-m-d')) ?>" title="Datum">
                  <select name="bk_typ" title="Typ">
                    <?php foreach(['zahlung','nachzahlung','reduktion','gutschrift','kaution','kaution_rueck'] as $t): ?>
                      <option value="<?= en($t) ?>"><?= en($t) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="number" step="0.01" name="bk_amount"  placeholder="Betrag">
                  <input type="text"   name="bk_ref"     placeholder="Referenz">
                  <input type="text"   name="bk_note"    placeholder="Bemerkung" style="min-width:140px">
                  <button class="btn">OK</button>
                </form>
              </div>

              <div class="rent-sections">
                <details>
                  <summary>Tarif-Historie</summary>
                  <div class="rentlist">
                    <?php
                      $history = rent_history($mysqli, (int)$L['id']);
                      if(!$history): ?>
                        <div class="small" style="color:#666">Keine Einträge.</div>
                    <?php else:
                        foreach($history as $h): ?>
                          <div class="rentpill">
                            <span><?= en($h['valid_from']) ?> → <?= en($h['valid_to'] ?: '—') ?></span>
                            <span>Miete: <?= number_format((float)$h['mietzins_netto'],2,'.',' ') ?></span>
                            <span>NK: <?= $h['nk_akonto']!==null ? number_format((float)$h['nk_akonto'],2,'.',' ') : '—' ?></span>
                            <?php if (!empty($h['bemerkung'])): ?><span>„<?= en($h['bemerkung']) ?>”</span><?php endif; ?>
                          </div>
                    <?php endforeach; endif; ?>
                  </div>
                </details>

                <details>
                  <?php
                    $konto = konto_list($mysqli, (int)$L['id'], null, null);
                    if (count($konto) > 6) $konto = array_slice($konto, -6);
                    $saldo = saldo_bis($mysqli, (int)$L['id'], date('Y-m-d'));
                  ?>
                  <summary>Letzte Buchungen · Saldo: <?= number_format($saldo,2,'.',' ') ?></summary>
                  <div class="rentlist">
                    <?php if(!$konto): ?>
                      <div class="small" style="color:#666">Keine Buchungen.</div>
                    <?php else: foreach($konto as $k): ?>
                      <div class="rentpill">
                        <span><?= en($k['datum']) ?></span>
                        <span><?= en($k['typ']) ?></span>
                        <span><?= number_format((float)$k['betrag'],2,'.',' ') ?></span>
                        <?php if(!empty($k['referenz'])): ?><span><?= en($k['referenz']) ?></span><?php endif; ?>
                      </div>
                    <?php endforeach; endif; ?>
                  </div>
                </details>
              </div>
            </fieldset>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </section>
</div>

<script>
// --- Label-Vorschläge bei Neuerfassung --------------------------------------
const DEFAULT_LABEL_POOL = <?= json_encode($default_label_pool, JSON_UNESCAPED_UNICODE) ?>;
const USERS = <?php
  $slim = array_map(function($u){
    return [
      'id'=>(int)$u['id'],
      'label'=>$u['_label'],
      'rolle'=>$u['_rolle'],
      'personen_typ'=>$u['_ptyp'],
      'status'=>$u['_status'],
    ];
  }, $users);
  echo json_encode($slim, JSON_UNESCAPED_UNICODE);
?>;

function uniqueNonEmpty(arr){
  const out=[]; const seen=new Set();
  arr.forEach(v=>{ const s=(v||'').toString().trim(); if(!s) return; const k=s.toLowerCase(); if(!seen.has(k)){ seen.add(k); out.push(s);} });
  return out;
}
function suggestionsForUser(uid){
  const u = USERS.find(x=>x.id===uid);
  if(!u) return DEFAULT_LABEL_POOL;
  const derived = uniqueNonEmpty([u.personen_typ, u.status, u.rolle]);
  const pool = [...derived.filter(x=>x.toLowerCase()==='mieter'),
                ...derived.filter(x=>x.toLowerCase()!=='mieter'),
                ...DEFAULT_LABEL_POOL];
  return uniqueNonEmpty(pool);
}
(function initNewLinkUI(){
  const userSel = document.getElementById('benutzer_id');
  const choose = document.getElementById('label_choose');
  const custom = document.getElementById('label_custom');
  function rebuild(){
    const id = parseInt(userSel.value||'0',10);
    const opts = suggestionsForUser(id);
    choose.innerHTML='';
    opts.forEach(v=>{ const o=document.createElement('option'); o.value=v; o.textContent=v; choose.appendChild(o); });
  }
  document.querySelectorAll('input[name="label_mode"]').forEach(r=>{
    r.addEventListener('change', ()=>{
      const mode = document.querySelector('input[name="label_mode"]:checked')?.value || 'auto';
      choose.style.display = (mode==='choose')?'inline-block':'none';
      custom.style.display = (mode==='custom')?'inline-block':'none';
    });
  });
  userSel.addEventListener('change', rebuild);
  rebuild();
})();

// Karten Toggle (Header & Button)
function isInteractive(el){ return !!(el.closest('button, input, select, textarea, a, label, .editbar2, .rentbar')); }
document.addEventListener('click', function(e){
  const head = e.target.closest('.person-head');
  if (!head) return;
  if (isInteractive(e.target)) return;
  const card = head.closest('.person-card');
  if (!card) return;
  card.classList.toggle('open');
});
document.addEventListener('click', function(e){
  const btn = e.target.closest('.toggle-card');
  if(!btn) return;
  e.preventDefault();
  const card = btn.closest('.person-card');
  if (card) card.classList.toggle('open');
});

// Alle einklappen / ausklappen
const collapseAll = document.getElementById('collapse_all');
if (collapseAll){
  collapseAll.addEventListener('change', function(){
    document.querySelectorAll('.person-card').forEach(c=>{
      if (this.checked) c.classList.remove('open'); else c.classList.add('open');
    });
  });
}
document.addEventListener('DOMContentLoaded', function(){
  const startCollapsed = !!(collapseAll && collapseAll.checked);
  document.querySelectorAll('.person-card').forEach(c=>{
    if (startCollapsed) c.classList.remove('open'); else c.classList.add('open');
  });
});

// Live-Suche
const searchInput = document.getElementById('search_input');
if (searchInput){
  searchInput.addEventListener('input', function(){
    const s = this.value.trim().toLowerCase();
    document.querySelectorAll('.person-card').forEach(c=>{
      const txt = (c.innerText||'').toLowerCase();
      c.style.display = txt.includes(s) ? '' : 'none';
    });
  });
}

// Typ-Filter (Chips) – data-type am Card-Container
function applyTypeFilter(){
  const allowed = new Set(
    Array.from(document.querySelectorAll('.typeflt:checked')).map(x=>x.value.toLowerCase())
  );
  document.querySelectorAll('.person-card').forEach(c=>{
    const t = (c.getAttribute('data-type')||'').toLowerCase();
    c.style.display = allowed.has(t) ? '' : 'none';
  });
}
document.querySelectorAll('.typeflt').forEach(cb=>cb.addEventListener('change', applyTypeFilter));
applyTypeFilter();

// Sortierung (Frontend) – data-beginn / data-label
const sortSel = document.getElementById('sort_sel');
if (sortSel){
  sortSel.addEventListener('change', function(){
    const grid = document.querySelector('.group .grid');
    const cards = Array.from(document.querySelectorAll('.person-card'));
    const mode = this.value;
    function toDate(s){ return s ? new Date(s) : new Date(0); }
    cards.sort((a,b)=>{
      if (mode==='begin_desc'){ return toDate(b.getAttribute('data-beginn')) - toDate(a.getAttribute('data-beginn')); }
      if (mode==='begin_asc'){  return toDate(a.getAttribute('data-beginn')) - toDate(b.getAttribute('data-beginn')); }
      if (mode==='label_asc'){  return (a.getAttribute('data-label')||'').localeCompare(b.getAttribute('data-label')||'', 'de', {sensitivity:'base'}); }
      if (mode==='label_desc'){ return (b.getAttribute('data-label')||'').localeCompare(a.getAttribute('data-label')||'', 'de', {sensitivity:'base'}); }
      return 0;
    });
    cards.forEach(c=>grid.appendChild(c));
  });
}

// Copy-Buttons (relativer/absoluter Pfad)
document.addEventListener('click', function(e){
  const btn = e.target.closest('button[data-copy]');
  if(!btn) return;
  const txt = btn.getAttribute('data-copy') || '';
  navigator.clipboard.writeText(txt).then(()=>{
    const old = btn.textContent;
    btn.textContent = 'Kopiert!';
    setTimeout(()=>btn.textContent = old, 1200);
  }).catch(()=>{ /* ignore */ });
});
</script>
</body>
</html>
