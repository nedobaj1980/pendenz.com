<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$__uri    = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('e')) {
  function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('url')) {
  function url(string $path = ''): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $prefix = (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php') ? '/pendenz.com' : '';
    return rtrim($prefix, '/') . '/' . ltrim($path, '/');
  }
}
if (!function_exists('brand_url')) {
  function brand_url(string $f): string { return url('assets/brand/' . ltrim($f, '/')); }
}

$is_active = function($needle) use ($__uri) {
    return (strpos($__uri, $needle) !== false) ? 'active' : '';
};

$sid = (int)($_SESSION['user_id'] ?? 0);
$current_pid = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));

$nav_projects = [];
if (isset($mysqli)) {
    $res = $mysqli->query("SELECT id, name, nummer FROM projekte ORDER BY name ASC");
    if ($res) while($np = $res->fetch_assoc()) $nav_projects[] = $np;
}
?>
<style id="nav-admin-styles">
:root {
  --sidebar-width: 280px;
  --topbar-height: 75px;
  --nav-bg: rgba(15, 23, 42, 0.95);
  --nav-accent: #3b82f6;
  --nav-accent-glow: rgba(59, 130, 246, 0.4);
  --nav-text: #94a3b8;
  --nav-text-hover: #ffffff;
  --glass-border: rgba(255, 255, 255, 0.08);
}

body { transition: padding 0.3s ease; }
body.nav-side { padding-left: var(--sidebar-width); padding-top: 0; }
body.nav-top { padding-left: 0; padding-top: var(--topbar-height); }

.main-nav-container {
  background: var(--nav-bg); color: #fff; z-index: 10000;
  border: 1px solid var(--glass-border); backdrop-filter: blur(12px);
}
.main-nav-container.is-side {
  position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar-width);
  display: flex; flex-direction: column; overflow-y: auto;
}
.main-nav-container.is-top {
  position: fixed; top: 0; left: 0; right: 0; height: var(--topbar-height);
  display: flex; flex-direction: row; align-items: center; padding: 0 24px;
}

.nav-brand { padding: 20px; display: flex; align-items: center; gap: 12px; text-decoration: none; color: #fff; }
.nav-brand img { width: 32px; height: 32px; border-radius: 8px; }
.nav-brand span { font-weight: 800; font-size: 19px; }

.nav-menu { list-style: none; padding: 0; margin: 0; display: flex; }
.is-side .nav-menu { flex-direction: column; padding: 0 16px; gap: 4px; }
.is-top .nav-menu { flex-direction: row; margin-left: 20px; gap: 8px; }

.nav-menu a {
  display: flex; align-items: center; gap: 10px; padding: 10px 16px;
  color: var(--nav-text); text-decoration: none; font-size: 13px; font-weight: 600;
  border-radius: 12px; transition: 0.2s; white-space: nowrap;
}
.nav-menu a:hover { background: rgba(255,255,255,0.05); color: #fff; }
.nav-menu li.active > a { background: var(--nav-accent); color: #fff; box-shadow: 0 4px 15px var(--nav-accent-glow); }

.nav-utils { margin-left: auto; display: flex; align-items: center; gap: 15px; }

.project-switcher {
  margin: 10px 16px 20px; padding: 12px;
  background: rgba(255,255,255,0.03); border: 1px solid var(--glass-border);
  border-radius: 15px;
}
.is-top .project-switcher { margin:0; min-width:200px; }
.ps-select {
  background: transparent; border: 0; color: #fff; font-size: 12px; font-weight: 700; width: 100%; cursor: pointer; outline: none;
}
.ps-select option { background: #0f172a; color: #fff; }

.ai-toggle-btn {
  width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--glass-border);
  display: flex; align-items: center; justify-content: center; cursor: pointer; background: transparent; color: #fff; transition: 0.3s;
}
.ai-toggle-btn.active { background: var(--nav-accent); box-shadow: 0 0 15px var(--nav-accent-glow); border-color: var(--nav-accent); }

.mobile-trigger { 
  display: none; position: fixed; top: 15px; right: 15px; z-index: 1001; 
  background: var(--nav-accent); color: #fff; width: 44px; height: 44px; 
  border-radius: 12px; align-items: center; justify-content: center; border: 0; cursor: pointer;
  box-shadow: 0 4px 12px rgba(0,0,0,0.2); font-size: 20px;
}

@media (max-width: 1024px) {
  body.nav-side { margin-left: 0; padding-top: 0 !important; }
  body.nav-top { margin-left: 0; padding-top: 56px !important; }
  
  /* is-side: Left off-canvas */
  .main-nav-container.is-side { 
      position: fixed; top: 0; left: 0; bottom: 0; right: auto;
      width: var(--sidebar-width); height: 100vh;
      flex-direction: column; align-items: stretch; padding: 0;
      transform: translateX(-100%); transition: transform 0.3s;
      background: var(--nav-bg); z-index: 10000;
  }
  .main-sidebar-open .main-nav-container.is-side { transform: translateX(0); }

  /* is-top: Top fixed sticky bar exploding downwards */
  .main-nav-container.is-top { 
      position: fixed; top: 0; left: 0; right: 0; bottom: auto;
      width: 100vw; height: 56px;
      flex-direction: column; align-items: stretch; padding: 0;
      transition: height 0.3s; overflow-y: hidden;
      background: var(--nav-bg); z-index: 10000;
      box-shadow: 0 4px 15px rgba(0,0,0,0.3);
  }
  .main-sidebar-open .main-nav-container.is-top { height: 100vh; overflow-y: auto; }

  .mobile-trigger { display: flex; z-index: 10001; transition: 0.3s; }
  
  .is-top > div:first-child { height: 56px; padding:0 15px!important; }
  .is-top .nav-brand { padding: 0; gap: 8px; }
  .is-top .nav-brand img { width: 22px; height: 22px; }
  .is-top .nav-brand span { font-size: 15px; }

  /* Scale down the trigger in top mode to fit the 56px bar */
  .is-top ~ .mobile-trigger { top: 10px; right: 10px; width: 36px; height: 36px; font-size: 16px; border-radius: 8px; }

  .is-top .nav-menu { flex-direction: column; padding: 0 16px; margin-top:10px; }
  .is-top .nav-menu a { padding: 8px 12px; font-size: 12px; gap: 8px; border-radius: 8px; }
  .is-top .project-switcher { margin: 10px 16px 20px; }
  .is-top .nav-utils { flex-direction: column; gap: 10px; margin: auto 0 0 0; padding: 16px; align-items: stretch; border-top: 1px solid rgba(255,255,255,0.05); }
}

/* Submenu Styles */
.nav-menu .submenu {
  list-style: none;
  padding: 0 0 0 35px;
  margin: 5px 0;
  display: none;
}
.nav-menu li.submenu-open .submenu {
  display: block;
}
.nav-menu .submenu a {
  padding: 8px 12px;
  font-size: 11px;
  opacity: 0.8;
}
.nav-menu .submenu a:hover {
  opacity: 1;
}
.nav-menu .submenu li.active a {
  background: rgba(255,255,255,0.08);
  box-shadow: none;
  color: #fff;
}
</style>

<button class="mobile-trigger" id="master-mobile-trigger" onclick="toggleMobileNav()">☰</button>

<script>
/**
 * Sofort-Korrektur des Layouts um Flackern zu vermeiden
 */
(function() {
  const savedLayout = localStorage.getItem('nav-layout') || '<?= $nav_mode ?>';
  document.body.classList.remove('nav-side', 'nav-top');
  document.body.classList.add('nav-' + savedLayout);
  const nav = document.getElementById('mainNav');
  if (nav) {
    nav.classList.remove('is-side', 'is-top');
    nav.classList.add('is-' + savedLayout);
  }
})();
</script>

<div id="mainNav" class="main-nav-container <?= $nav_mode === 'top' ? 'is-top' : 'is-side' ?>">
  <div style="display:flex; align-items:center; justify-content:space-between; padding-right:15px;">
    <a href="<?= e(url('index_admin.php')) ?>" class="nav-brand">
      <img src="<?= e(brand_url('logo-mark.png')) ?>" alt="Logo">
      <span>pendenz.com</span>
    </a>
    <button class="ai-toggle-btn" id="ai-toggle-btn" onclick="toggleAiAssistant()" title="Ask gimi">🤖</button>
  </div>

  <div class="project-switcher">
    <select class="ps-select" onchange="const u=new URL(window.location.href); if(u.searchParams.has('id')) u.searchParams.set('id',this.value); u.searchParams.set('projekt_id',this.value); window.location.href=u.toString();">
      <option value="0">📍 Projekt wählen</option>
      <?php foreach($nav_projects as $np): ?>
        <option value="<?= $np['id'] ?>" <?= ($current_pid == $np['id']) ? 'selected' : '' ?>>
          <?= e($np['nummer'] ? ($np['nummer'] . ' - ' . $np['name']) : $np['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <ul class="nav-menu">
    <li class="<?= $is_active('index_admin.php') ?>"><a href="<?= e(url('index_admin.php')) ?>">🏛️ Dashboard</a></li>
    <li class="<?= $is_active('profil.php') ?>"><a href="<?= e(url('pages/profil.php')) ?>">👤 Mein Profil</a></li>
    
    <li class="has-submenu <?= ($is_active('benutzer.php')||$is_active('firmen.php')||$is_active('bkp_codes.php')||$is_active('wohnungen_liste.php'))?'submenu-open active':'' ?>">
      <a href="#">👥 Partner & Bestand</a>
      <ul class="submenu">
        <li class="<?= $is_active('benutzer.php') ?>"><a href="<?= e(url('pages/benutzer.php')) ?>">👥 Partner/User</a></li>
        <li class="<?= $is_active('firmen.php') ?>"><a href="<?= e(url('pages/firmen.php')) ?>">🏢 Firmen</a></li>
        <li class="<?= $is_active('bkp_codes.php') ?>"><a href="<?= e(url('pages/bkp_codes.php')) ?>">🛠️ BKP 2 Gebäude</a></li>
        <li class="<?= $is_active('wohnungen_liste.php') ?>"><a href="<?= e(url('pages/wohnungen_liste.php')) ?>">🏘️ Einheiten/Bestand</a></li>
      </ul>
    </li>

    <li class="<?= $is_active('pendenzen.php') ?>"><a href="<?= e(url('pages/pendenzen.php')) ?>">📜 Aufgaben</a></li>
    <li class="<?= $is_active('terminprogramm.php') ?>"><a href="<?= e(url('pages/terminprogramm.php')) ?>">📅 Terminprogramm</a></li>
    <li class="has-submenu <?= ($is_active('files.php') || $is_active('ordner_vorlagen.php') || $is_active('ordner_verknuepfen.php')) ? 'submenu-open active' : '' ?>">
      <a href="<?= e(url('pages/files.php')) ?>">📁 Ablage &amp; Drive</a>
      <ul class="submenu">
        <li><a href="<?= e(url('pages/files.php')) ?>">📁 Google Drive Explorer</a></li>
        <li><a href="<?= e(url('pages/ordner_vorlagen.php')) ?>">📦 Ordner-Vorlagen</a></li>
        <li><a href="<?= e(url('pages/ordner_verknuepfen.php')) ?>">🔗 Ordner-Verknüpfungen</a></li>
      </ul>
    </li>
    <li class="has-submenu <?= ($is_active('finanzen.php') || $is_active('mietkontrolle') || $is_active('konto_verwaltung') || $is_active('liegenschaftsabrechnung')) ? 'submenu-open active' : '' ?>">
      <a href="<?= e(url('pages/finanzen.php')) ?>">📈 Finanzen</a>
      <ul class="submenu">
        <li class="<?= $is_active('finanzen.php') ?>"><a href="<?= e(url('pages/finanzen.php')) ?>">📊 Übersicht</a></li>
        <li><a href="<?= e(url('tools/mietkontrolle/index.php')) ?>">💰 Mietkontrolle</a></li>
        <li><a href="<?= e(url('tools/konto_verwaltung/index.php')) ?>">🏦 Bankkonto &amp; CSV</a></li>
        <li><a href="<?= e(url('tools/liegenschaftsabrechnung/index.php')) ?>">📑 Liegenschaftsabrechnung</a></li>
      </ul>
    </li>
  </ul>

  <div class="nav-utils" style="margin-top:auto; padding: 20px; display:flex; flex-direction:column; gap:10px;">
    <button onclick="toggleNavLayout()" style="background:transparent; border:1px solid var(--glass-border); color:#fff; border-radius:8px; padding:5px; cursor:pointer;">🌓 Layout</button>
    <a href="<?= e(url('logout.php')) ?>" style="color:#f87171; text-decoration:none; font-size:12px; font-weight:700;">🚪 Logout</a>
  </div>
</div>

<script>
function toggleAiAssistant() {
  const btn = document.getElementById('ai-toggle-btn');
  const isActive = btn.classList.toggle('active');
  localStorage.setItem('ai-assistant-active', isActive ? '1' : '0');
  if (typeof toggleGimi === 'function') toggleGimi();
}

function toggleNavLayout() {
  const current = localStorage.getItem('nav-layout') || 'side';
  const next = current === 'side' ? 'top' : 'side';
  applyNavLayout(next);
}

function applyNavLayout(layout) {
  const nav = document.getElementById('mainNav');
  const body = document.body;
  if (layout === 'top') {
    nav.classList.remove('is-side'); nav.classList.add('is-top');
    body.classList.remove('nav-side'); body.classList.add('nav-top');
  } else {
    nav.classList.remove('is-top'); nav.classList.add('is-side');
    body.classList.remove('nav-top'); body.classList.add('nav-side');
  }
  localStorage.setItem('nav-layout', layout);
  document.cookie = "nav-layout=" + layout + "; path=/; max-age=" + (365*24*60*60);
}

function toggleMobileNav() {
  const isOpen = document.body.classList.toggle('main-sidebar-open');
  const btn = document.getElementById('master-mobile-trigger');
  if (btn) btn.innerHTML = isOpen ? '✕' : '☰';
}

document.addEventListener('DOMContentLoaded', () => {
  const saved = localStorage.getItem('nav-layout') || 'side';
  const body = document.body;
  body.classList.add(saved === 'top' ? 'nav-top' : 'nav-side');
  applyNavLayout(saved);

  if (localStorage.getItem('ai-assistant-active') === '1') {
    document.getElementById('ai-toggle-btn').classList.add('active');
  }

  // Mobile Submenu Toggler
  document.querySelectorAll('.has-submenu > a').forEach(a => {
      const toggler = document.createElement('span');
      toggler.innerHTML = '▼';
      toggler.style.cssText = 'margin-left:auto; padding:4px 10px; background:rgba(255,255,255,0.05); border-radius:6px; font-size:10px; cursor:pointer; min-width:30px; text-align:center;';
      
      a.style.display = 'flex';
      a.style.alignItems = 'center';
      a.appendChild(toggler);
      
      toggler.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const li = a.parentElement;
          li.classList.toggle('submenu-open');
      });
  });
});
</script>
