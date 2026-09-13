<?php
// pages/project_storage.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fs.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/project_ctx.php';

require_login();

$projectId = (int)($GLOBALS['__projekt_id'] ?? ($_GET['projekt_id'] ?? 0));
if ($projectId <= 0) die("Projekt ID fehlt.");

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  
  if (isset($_POST['root_path'])) {
    $path = trim((string)$_POST['root_path']);
    $type = $_POST['storage_type'] ?? 'local';
    $stU = $mysqli->prepare("UPDATE projekte SET root_path=?, storage_type=? WHERE id=?");
    $stU->bind_param("ssi", $path, $type, $projectId);
    $ok = $stU->execute(); $stU->close();
    $flash = $ok ? 'Speichereinstellungen aktualisiert.' : '❌ Fehler beim Speichern.';
  }
  
  if (isset($_POST['rescan'])) {
    $r = fs_scan_project($mysqli, $projectId, 0);
    $flash = $r['ok'] ? "Scan ok: {$r['count']} Einträge" : "❌ ".$r['msg'];
  }

  if (isset($_POST['clone_structure'])) {
    $root = project_root_path($mysqli, $projectId);
    if (!$root || !is_dir($root)) {
      $flash = "❌ Fehler: Root-Pfad nicht gefunden.";
    } else {
      $tplRes = $mysqli->query("SELECT id FROM ordner_vorlagen WHERE name='Liegenschafts-Standard' LIMIT 1");
      $tplId = ($row = $tplRes ? $tplRes->fetch_assoc() : null) ? (int)$row['id'] : 0;
      $tplNodes = [];
      if ($tplId) {
        $nr = $mysqli->query("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id=$tplId");
        if($nr) while($n = $nr->fetch_assoc()) $tplNodes[] = $n['rel_path'];
      }
      
      $units = $mysqli->query("SELECT w.name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$projectId");
      $countU = 0; $countF = 0;
      if($units) while($u = $units->fetch_assoc()) {
        $uPath = 'Wohnungen/' . $u['name'];
        $uAbs = fs_abs_from_rel($root, $uPath);
        if ($uAbs) {
          if (!is_dir($uAbs)) { @mkdir($uAbs, 0777, true); $countF++; }
          foreach($tplNodes as $tn) {
            $subAbs = fs_abs_from_rel($root, $uPath . '/' . $tn);
            if ($subAbs && !is_dir($subAbs)) { @mkdir($subAbs, 0777, true); $countF++; }
          }
          $countU++;
        }
      }
      fs_scan_project($mysqli, $projectId, 0);
      $flash = "✅ Klonen abgeschlossen: $countU Einheiten gespiegelt, $countF neue Ordner angelegt.";
    }
  }
  header('Location: ' . url('pages/project_storage.php?projekt_id=' . $projectId) . ($flash ? '&msg=' . rawurlencode($flash) : ''));
  exit;
}

// Data loading
$proj = ['name' => '', 'root_path' => null, 'storage_type' => 'local'];
$st = $mysqli->prepare("SELECT name, root_path, storage_type FROM projekte WHERE id=?");
$st->bind_param("i", $projectId);
$st->execute();
if ($row = $st->get_result()->fetch_assoc()) $proj = $row;
$st->close();

if (!empty($_GET['msg'])) $flash = (string)$_GET['msg'];
$currentRoot = project_root_path($mysqli, $projectId);
$csrf = csrf_token();

// Header & Nav
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

/* ===== Quick-Picks (Passe diese Liste an deine typischen Orte an) ===== */
function qp($label,$path){ return is_dir($path) ? ['label'=>$label,'path'=>$path] : null; }
$quickPicks = [];
// Windows-typische Orte (baue hier nach Wunsch aus)
if (stripos(PHP_OS_FAMILY,'Windows') !== false) {
  $home = rtrim((getenv('HOMEDRIVE').getenv('HOMEPATH')), '\\/');
  foreach ([
    qp('Nextcloud4',               $home.'\\Nextcloud4'),
    qp('Projekte',                 $home.'\\Nextcloud4\\Projekte'),
    qp('Dokumente',                $home.'\\Documents'),
    qp('Desktop',                  $home.'\\Desktop'),
    qp('Downloads',                $home.'\\Downloads'),
    qp('C:\\',                     'C:\\'),
  ] as $q) { if ($q) $quickPicks[] = $q; }
} else {
  $home = getenv('HOME') ?: '/';
  foreach ([
    qp('Home',        $home),
    qp('Dokumente',   $home.'/Documents'),
    qp('Downloads',   $home.'/Downloads'),
    qp('Projekte',    $home.'/Projects'),
    qp('/',           '/'),
  ] as $q) { if ($q) $quickPicks[] = $q; }
}
// Startordner für den Picker: zuerst aktueller Root, sonst 1. Quick-Pick, sonst C:\ / /
$pickerStart = $currentRoot ?: ($quickPicks[0]['path'] ?? (stripos(PHP_OS_FAMILY,'Windows')!==false ? 'C:\\' : '/'));
?>
<style>
/* --- Windows-Explorer Look (hell, Listenansicht) --- */
.picker-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(1.5px);display:none;align-items:center;justify-content:center;z-index:9999}
.picker{width:min(960px,96vw);height:min(620px,90vh);background:#fff;border:1px solid #e5e7eb;border-radius:12px;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.2)}
.picker header{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #eef2f7}
.picker .crumbs{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.picker .crumbs a{padding:4px 8px;background:#f3f4f6;border-radius:8px;text-decoration:none;color:#111827}
.picker .body{flex:1;display:grid;grid-template-columns:240px 1fr;min-height:0}
.picker .left{border-right:1px solid #eef2f7;overflow:auto;padding:8px}
.picker .left h4{margin:6px 6px 4px;color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
.picker .right{overflow:auto;min-height:0;padding:0}
.picker .fav, .picker .drv{display:flex;gap:8px;align-items:center;padding:6px 8px;border-radius:8px;text-decoration:none;color:#111827}
.picker .fav:hover, .picker .drv:hover{background:#f1f5f9}
.picker footer{padding:10px 12px;border-top:1px solid #eef2f7;display:flex;gap:8px;align-items:center;justify-content:flex-end}
.btn{border-radius:8px;padding:8px 12px;border:1px solid #e5e7eb;background:#fff;color:#111827;cursor:pointer}
.btn.primary{background:#111827;border-color:#111827;color:#fff}
.btn[disabled]{opacity:.6;cursor:not-allowed}
.small{font-size:12px;color:#64748b}

/* Listenansicht rechts */
.filelist{width:100%;border-collapse:collapse}
.filelist thead th{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;font-weight:600;text-align:left;padding:10px}
.filelist tbody td{padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.filelist tbody tr:hover{background:#f8fafc}
.filelist .icon{width:36px}
.sel{background:#eff6ff !important}
.qp-wrap{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}
.qp{border-radius:999px;padding:6px 10px;border:1px solid #e5e7eb;background:#f8fafc;cursor:pointer}
.qp:hover{background:#eef2ff}
</style>

<div class="container" style="max-width:900px;margin:24px auto;">
  <h1>Projekt-Speicherort</h1>

  <?php if ($flash): ?>
    <div style="padding:8px 12px;background:#eef;border:1px solid #ccd;margin:12px 0;border-radius:6px;"><?= e($flash) ?></div>
  <?php endif; ?>

  <div style="margin:8px 0;color:#666;">
    <strong>Projekt:</strong> <?= e($proj['name'] ?: ('#'.$projectId)) ?>
  </div>

  <form method="post">
    <?php csrf_field(); ?>
    
    <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:24px;">
      <label style="font-weight:700; margin-bottom:12px; display:block;">Speicher-Typ & Anbieter</label>
      <div style="display:flex; gap:20px;">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="radio" name="storage_type" value="local" <?= ($proj['storage_type'] ?? 'local') === 'local' ? 'checked' : '' ?>> 
          <span>🏠 Lokaler Server / NAS</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="radio" name="storage_type" value="cloud" <?= ($proj['storage_type'] ?? '') === 'cloud' ? 'checked' : '' ?>> 
          <span>☁️ Google Drive / Cloud Sync</span>
        </label>
      </div>
      <p class="small" style="margin-top:12px; line-height:1.4;">
        <strong>Hinweis zu Google Drive:</strong> Installiere „Google Drive für Desktop“, um dein Drive als lokales Laufwerk (z.B. <code>G:\</code>) zu nutzen. Wähle dann unten den entsprechenden Pfad aus. So bleiben alle Dateien synchron.
      </p>
    </div>

    <label style="font-weight:700;">Root-Ordner (Basis-Pfad für dieses Projekt):</label>
    <div style="display:flex;gap:8px;align-items:center;margin:8px 0;">
      <input id="root_path" type="text" name="root_path" value="<?= e((string)$currentRoot ?: (string)$proj['root_path']) ?>" style="flex:1;padding:8px; border-radius:8px; border:1px solid #d1d5db;">
      <button class="btn outline" type="button" id="btnPick">Ordner wählen…</button>
    </div>

    <!-- Quick Picks -->
    <?php if (!empty($quickPicks)): ?>
      <div class="qp-wrap" id="qpWrap" title="Klicken = Pfad übernehmen · Shift+Klick = im Picker öffnen">
        <?php foreach ($quickPicks as $qp): ?>
          <button type="button" class="qp" data-pick="<?= e($qp['path']) ?>">📁 <?= e($qp['label']) ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap; margin-top:20px;">
      <button class="btn primary" type="submit" style="padding:10px 24px;">Speichern & Übernehmen</button>
      <button class="btn outline" name="rescan" value="1">🔄 Index scannen / Refresh</button>
      <button class="btn secondary" name="clone_structure" value="1" onclick="return confirm('Soll die gesamte Wohnungs-Struktur (inkl. Unterordnern) jetzt physisch im Ziel-Pfad erstellt werden?')">📦 Struktur in Root klonen / spiegeln</button>
    </div>
  </form>
</div>



<!-- Picker -->
<div class="picker-overlay" id="pkOv" aria-hidden="true">
  <div class="picker" role="dialog" aria-modal="true" aria-labelledby="pkTitle">
    <header>
      <div class="crumbs" id="pkCrumbs"></div>
      <div style="display:flex;gap:6px;">
        <button class="btn" id="pkUp">↥ Hoch</button>
        <button class="btn" id="pkRefresh">Neu laden</button>
        <button class="btn" id="pkNew">Neuer Ordner</button>
        <button class="btn" id="pkClose">Schliessen</button>
      </div>
    </header>
    <div class="body">
      <div class="left">
        <h4>Favoriten</h4>
        <div id="pkFavs"></div>
        <h4 style="margin-top:10px;">Laufwerke</h4>
        <div id="pkDrives"></div>
      </div>
      <div class="right">
        <table class="filelist">
          <thead>
            <tr>
              <th class="icon"></th>
              <th>Name</th>
              <th>Pfad</th>
            </tr>
          </thead>
          <tbody id="pkList"></tbody>
        </table>
      </div>
    </div>
    <footer>
      <div style="margin-right:auto" class="small" id="pkCur">(kein Ordner)</div>
      <button class="btn primary" id="pkSelect">Diesen Ordner verwenden</button>
    </footer>
  </div>
</div>

<script>
(function(){
  const api = '<?= e(url("api/dirlist.php")) ?>';
  const csrf = '<?= e(csrf_token()) ?>';
  const favs = <?php
    function fav($l,$p){return is_dir($p)?['label'=>$l,'path'=>$p]:null;}
    $F=[]; if (stripos(PHP_OS_FAMILY,'Windows')!==false){ $home=rtrim((getenv('HOMEDRIVE').getenv('HOMEPATH')),'\\/');
      foreach([fav('Desktop',$home.'\\Desktop'),fav('Dokumente',$home.'\\Documents'),fav('Downloads',$home.'\\Downloads'),
               fav('Bilder',$home.'\\Pictures'),fav('Nextcloud',$home.'\\Nextcloud'),fav('Nextcloud4',$home.'\\Nextcloud4')] as $x){ if($x)$F[]=$x; }
    } else { $home=getenv('HOME')?:'/'; foreach([fav('Home',$home),fav('Dokumente',$home.'/Documents'),fav('Downloads',$home.'/Downloads'),fav('Bilder',$home.'/Pictures'),fav('Nextcloud',$home.'/Nextcloud')] as $x){ if($x)$F[]=$x; } }
    echo json_encode($F, JSON_UNESCAPED_UNICODE);
  ?>;
  const startPath = <?= json_encode($pickerStart) ?>;

  const $ = s => document.querySelector(s);
  const ov = $('#pkOv'), list = $('#pkList'), crumbs = $('#pkCrumbs'), cur = $('#pkCur'),
        favBox = $('#pkFavs'), drvBox = $('#pkDrives'), input = document.querySelector('#root_path');
  let current = '', selected = '';

  function lockBody(lock){ document.documentElement.style.overflow = lock ? 'hidden' : ''; }
  function show(){
    ov.style.display='flex'; lockBody(true); renderFavs();
    // Start: Vorzugsweise aktuelle Eingabe, sonst Startordner, sonst Roots
    const want = input.value && input.value.trim() ? input.value.trim() : (startPath || '');
    if (want) { load(want).catch(()=>loadRoots()); } else { loadRoots(); }
  }
  function hide(){ ov.style.display='none'; lockBody(false); }

  async function getJSON(url){ const r = await fetch(url,{credentials:'same-origin'}); return r.json(); }
  async function postJSON(url,data){
    const r = await fetch(url,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':csrf},
      body:new URLSearchParams(data)}); return r.json();
  }

  function renderFavs(){
    favBox.innerHTML=''; (favs||[]).forEach(f=>{
      const a=document.createElement('a'); a.className='fav'; a.href='#'; a.innerHTML='⭐ <span>'+f.label+'</span>';
      a.onclick=e=>{e.preventDefault(); load(f.path)}; favBox.appendChild(a);
    });
  }

  async function loadRoots(){
    crumbs.innerHTML=''; list.innerHTML=''; drvBox.innerHTML='';
    const data = await getJSON(api);
    current=''; selected=''; cur.textContent='(Wurzelebene)';
    (data.roots||[]).forEach(r=>{
      const a=document.createElement('a'); a.className='drv'; a.href='#'; a.innerHTML='🖴 <span>'+r.label+'</span>';
      a.onclick=e=>{e.preventDefault(); load(r.path)}; drvBox.appendChild(a);
    });
  }

  function renderRows(entries){
    list.innerHTML='';
    entries.forEach(e=>{
      const tr=document.createElement('tr');
      tr.innerHTML = `<td class="icon">📁</td><td>${e.name}</td><td><span class="small">${e.path}</span></td>`;
      tr.addEventListener('click', ()=>{ [...list.children].forEach(x=>x.classList.remove('sel')); tr.classList.add('sel'); selected=e.path; cur.textContent=selected; });
      tr.addEventListener('dblclick', ()=>{ load(e.path); }); // öffnen
      list.appendChild(tr);
    });
  }

  async function load(path){
    const data = await getJSON(api+'?p='+encodeURIComponent(path));
    if(!data.ok){ alert(data.msg||'Fehler'); return; }
    current = data.current || ''; selected = ''; cur.textContent = current || '(kein Ordner)';
    // Breadcrumbs
    crumbs.innerHTML=''; (data.crumbs||[]).forEach((c,i)=>{
      const a=document.createElement('a'); a.href='#'; a.textContent=c.label;
      a.onclick=e=>{e.preventDefault(); load(c.path)}; crumbs.appendChild(a);
      if (i < (data.crumbs||[]).length-1){ const sep=document.createElement('span'); sep.textContent='›'; sep.style.margin='0 4px'; crumbs.appendChild(sep); }
    });
    renderRows(data.entries||[]);
  }

  // Buttons
  document.querySelector('#btnPick').addEventListener('click', show);
  document.querySelector('#pkClose').addEventListener('click', hide);
  document.querySelector('#pkRefresh').addEventListener('click', ()=>{ current ? load(current) : loadRoots(); });
  document.querySelector('#pkSelect').addEventListener('click', ()=>{
    const pick = selected || current;
    if(!pick){ alert('Bitte Ordner wählen.'); return; }
    input.value = pick; hide();
  });
  document.querySelector('#pkUp').addEventListener('click', ()=>{
    if(!current){ loadRoots(); return; }
    let p = current;
    <?php if (stripos(PHP_OS_FAMILY,'Windows') !== false): ?>
      p = p.replace(/\//g,'\\'); if (/^[A-Za-z]:\\?$/.test(p)) { loadRoots(); return; }
      p = p.replace(/\\+$/,''); const i = p.lastIndexOf('\\'); p = (i>2) ? p.substring(0,i) : p.substring(0,3);
    <?php else: ?>
      p = p.replace(/\\/g,'/'); if (p === '/') { loadRoots(); return; }
      p = p.replace(/\/+$/,''); const i = p.lastIndexOf('/'); p = (i<=0)? '/' : p.substring(0,i);
    <?php endif; ?>
    load(p);
  });
  document.querySelector('#pkNew').addEventListener('click', async ()=>{
    const name = prompt('Neuer Ordnername:'); if(!name) return;
    const base = selected || current; if(!base){ alert('Bitte zuerst einen Basisordner wählen.'); return; }
    const data = await postJSON(api, {op:'mkdir', base, name});
    if(!data.ok){ alert(data.msg||'Ordner konnte nicht erstellt werden.'); return; }
    load(base);
  });

  // Quick-Pick Chips: Klick = übernehmen, Shift+Klick = Picker öffnen & dorthin springen
  document.querySelectorAll('.qp[data-pick]').forEach(btn=>{
    btn.addEventListener('click', (ev)=>{
      const p = btn.getAttribute('data-pick');
      if (ev.shiftKey) { // im Picker öffnen
        show();
        setTimeout(()=>{ load(p); }, 50);
      } else { // sofort übernehmen
        input.value = p;
      }
    });
  });

  // Esc schliesst
  document.addEventListener('keydown', (e)=>{ if (ov.style.display==='flex' && e.key==='Escape') hide(); });
})();
</script>
