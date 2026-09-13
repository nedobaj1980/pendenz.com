<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();
$realRole = $_SESSION['rolle'] ?? 'gast';
$simRole = ($realRole === 'superadmin' && !empty($_SESSION['simulate_role'])) ? $_SESSION['simulate_role'] : null;
$effRole = $simRole ?: $realRole;
$__uri = $_SERVER['REQUEST_URI'] ?? '';

if (!isset($nav_mode)) {
  $nav_mode = $_COOKIE['nav-layout'] ?? 'top';
}

if (!function_exists('e')) {
  function e($s): string
  {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
if (!function_exists('url')) {
  function url(string $path = ''): string
  {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $prefix = (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php') ? '/pendenz.com' : '';
    return rtrim($prefix, '/') . '/' . ltrim($path, '/');
  }
}

$is_active = function ($needle) use ($__uri) {
  return (strpos($__uri, $needle) !== false) ? 'active' : '';
};

// Aktuelle Projekte für Switcher & Menü laden
$nav_projects = [];
$current_pid = (int) ($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));
if (isset($mysqli)) {
  try {
    $res = $mysqli->query("SELECT id, name, nummer FROM projekte ORDER BY name ASC");
    if ($res)
      while ($np = $res->fetch_assoc())
        $nav_projects[] = $np;
  } catch (Throwable $e) {
    // Fallback or ignore
  }
}
?>
<style id="nav-layout-styles">
  /* === Gemeinsame Variablen === */
  :root {
    --sidebar-width: 280px;
    --topbar-height: 75px;
    --nav-bg: rgba(15, 23, 42, 0.95);
    --nav-accent: #3b82f6;
    --nav-accent-glow: rgba(59, 130, 246, 0.4);
    --nav-text: #94a3b8;
    --nav-text-hover: #ffffff;
    --glass-border: rgba(255, 255, 255, 0.08);
    --body-bg: #f8fafc;
  }

  body {
    background: var(--body-bg);
  }

  /* === SIDEBAR MODE === */
  body.nav-mode-side,
  body.nav-mode-left {
    margin-left: var(--sidebar-width);
    padding-top: 0;
    transition: none !important;
  }

  .main-nav-container.is-side {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    width: var(--sidebar-width);
    background: var(--nav-bg);
    display: flex;
    flex-direction: column;
    z-index: 10000;
    box-shadow: 4px 0 25px rgba(0, 0, 0, 0.1);
    overflow-y: auto;
  }

  /* === TOPBAR MODE === */
  body.nav-mode-top {
    margin-left: 0;
    padding-top: var(--topbar-height);
    transition: none !important;
  }

  .main-nav-container.is-top {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: var(--topbar-height);
    background: var(--nav-bg);
    backdrop-filter: blur(12px);
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    z-index: 10000;
    box-shadow: 0 4px 30px rgba(0, 0, 0, 0.2);
    padding: 0 24px;
    border-bottom: 1px solid var(--glass-border);
    box-sizing: border-box;
  }

  /* === Brand & Toggle === */
  .nav-brand {
    padding: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: #fff;
  }

  .is-top .nav-brand {
    padding: 0 20px 0 0;
  }

  .nav-brand img {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    filter: drop-shadow(0 4px 12px rgba(59, 130, 246, 0.3));
    transition: 0.3s;
  }

  .nav-brand:hover img {
    transform: scale(1.1) rotate(-5deg);
    filter: drop-shadow(0 0 15px var(--nav-accent));
  }

  .nav-brand span {
    font-size: 19px;
    font-weight: 800;
    color: #fff;
  }

  .layout-toggle {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #fff;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.3s;
  }

  .layout-toggle:hover {
    background: var(--nav-accent);
    box-shadow: 0 0 10px var(--nav-accent);
    transform: scale(1.05);
  }

  .ai-symbol-btn {
    width: 36px;
    height: 36px;
    background: rgba(59, 130, 246, 0.05);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    text-decoration: none;
    border: 1px solid rgba(255, 255, 255, 0.1);
    transition: 0.3s;
    cursor: pointer;
    position: relative;
  }

  .ai-symbol-btn.active {
    background: var(--nav-accent);
    border-color: var(--nav-accent);
    box-shadow: 0 0 15px var(--nav-accent-glow);
    transform: scale(1.1);
  }

  .ai-symbol-btn:hover {
    transform: scale(1.05);
  }

  /* === Navigation List === */
  .nav-sections {
    flex: 1 1 auto;
    display: flex;
    min-width: 0;
  }

  .is-side .nav-sections {
    flex-direction: column;
    padding: 0 16px;
  }

  .is-top .nav-sections {
    flex-direction: row;
    align-items: center;
    gap: 4px;
    overflow: visible;
    justify-content: flex-start;
  }

  .is-top .nav-section {
    display: flex;
    align-items: center;
    min-width: 0;
  }

  .nav-section-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: #475569;
    letter-spacing: 1px;
    padding: 10px 12px;
  }

  .is-top .nav-section-title {
    display: none;
  }

  .nav-menu {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    min-width: 0;
  }

  .is-side .nav-menu {
    flex-direction: column;
    gap: 4px;
  }

  .is-top .nav-menu {
    flex-direction: row;
    gap: 2px;
  }

  .is-top .nav-menu li {
    flex-shrink: 1;
    min-width: 0;
    display: flex;
  }

  .nav-menu a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    color: var(--nav-text);
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border-radius: 12px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    white-space: nowrap;
    border: 1px solid transparent;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .is-top .nav-menu a {
    padding: 8px 10px;
    font-size: 12px;
    gap: 6px;
  }

  .nav-menu a:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #fff;
    border-color: var(--glass-border);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
  }

  .nav-menu li.active>a {
    background: linear-gradient(135deg, var(--nav-accent), #2563eb);
    color: #fff;
    box-shadow: 0 4px 15px var(--nav-accent-glow);
  }

  /* === Simulation & User === */
  .nav-footer {
    padding: 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    display: flex;
    flex-shrink: 0;
  }

  .is-side .nav-footer {
    flex-direction: column;
    gap: 10px;
    background: rgba(0, 0, 0, 0.2);
  }

  .is-top .nav-footer {
    flex-direction: row;
    align-items: center;
    border: 0;
    background: transparent;
    padding: 0;
    margin-left: auto;
    gap: 10px;
  }

  .sim-box {
    padding: 8px 12px;
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.2);
    border-radius: 10px;
    font-size: 11px;
    min-width: 120px;
  }

  .is-top .sim-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 5px 12px;
  }

  .sim-label {
    color: var(--nav-accent);
    font-weight: 800;
    font-size: 9px;
    text-transform: uppercase;
  }

  .sim-val {
    font-weight: 700;
    color: #fff;
    margin: 0 5px;
  }

  .sim-btns {
    display: flex;
    gap: 4px;
    margin-top: 5px;
  }

  .is-top .sim-btns {
    margin: 0;
  }

  .sim-btns a {
    padding: 4px 8px;
    font-size: 10px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
    color: #fff;
    text-decoration: none;
    transition: 0.2s;
  }

  .sim-btns a:hover {
    background: var(--nav-accent);
  }

  @media (max-width: 1024px) {
    body.nav-side {
      margin-left: 0;
      padding-top: 56px !important;
    }

    /* is-side & override is-top: Left off-canvas */
    .main-nav-container.is-side,
    .main-nav-container.is-top {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      right: auto !important;
      width: var(--sidebar-width) !important;
      max-width: 85vw !important;
      height: 100vh !important;
      flex-direction: column !important;
      align-items: stretch !important;
      padding: 0 !important;
      transform: translateX(-100%);
      transition: transform 0.3s;
      background: var(--nav-bg) !important;
      z-index: 10001 !important;
      border-right: 1px solid var(--glass-border) !important;
    }

    .main-sidebar-open .main-nav-container.is-side,
    .main-sidebar-open .main-nav-container.is-top {
      transform: translateX(0);
      box-shadow: 4px 0 25px rgba(0, 0, 0, 0.5);
    }

    /* Reset Desktop is-top Flex properties back to column for Sidebar rendering */
    .is-top .nav-sections {
      flex-direction: column !important;
      align-items: stretch !important;
      padding: 0 16px !important;
    }

    .is-top .nav-menu {
      flex-direction: column !important;
      gap: 4px !important;
    }

    .is-top .nav-footer {
      flex-direction: column !important;
      align-items: stretch !important;
      background: rgba(0, 0, 0, 0.2) !important;
      padding: 16px !important;
      margin-left: 0 !important;
    }

    .is-top .nav-section-title {
      display: block !important;
    }

    .is-top .project-switcher {
      margin: 0 24px 20px !important;
    }

    .is-top .sim-box {
      flex-direction: column !important;
      align-items: flex-start !important;
      padding: 8px 12px !important;
      gap: 0 !important;
    }

    .is-top .sim-btns {
      margin-top: 5px !important;
    }

    .is-top .submenu {
      display: none;
      position: static !important;
      box-shadow: none !important;
      width: 100% !important;
      margin-top: 5px !important;
      padding: 0 !important;
      background: rgba(30, 41, 59, 0.4) !important;
    }

    .is-top .submenu::before {
      display: none !important;
    }

    .is-top .has-submenu:hover>.submenu {
      animation: none !important;
    }

    .layout-toggle {
      display: none !important;
    }

    /* Overlay backdrop */
    .mobile-sidebar-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.4);
      z-index: 10000;
      backdrop-filter: blur(4px);
      transition: opacity 0.3s ease;
      opacity: 0;
      pointer-events: none;
    }

    .main-sidebar-open .mobile-sidebar-overlay {
      opacity: 1;
      pointer-events: auto;
    }

    .mobile-close-sidebar {
      display: flex !important;
    }

    /* Activate mobile app bar */
    .mobile-app-bar {
      display: flex !important;
    }
  }

  /* === Mobile App Bar (Hidden on Desktop) === */
  .mobile-app-bar {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 56px;
    background: var(--nav-bg);
    z-index: 9999;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
    align-items: center;
    padding: 0 clamp(5px, 2vw, 10px);
    width: 100vw;
    box-sizing: border-box;
    justify-content: space-between;
    overflow: hidden;
  }

  .mab-hamburger {
    width: clamp(32px, 8vw, 36px);
    height: clamp(32px, 8vw, 36px);
    border-radius: 8px;
    background: var(--nav-accent);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 0;
    cursor: pointer;
    font-size: clamp(14px, 4vw, 16px);
    margin-right: clamp(5px, 3vw, 15px);
    flex-shrink: 0;
  }

  .mab-logo {
    margin-right: auto;
    display: flex;
    align-items: center;
    text-decoration: none;
    flex-shrink: 1;
    min-width: 0;
  }

  .mab-logo img {
    width: clamp(24px, 6vw, 28px);
    height: clamp(24px, 6vw, 28px);
    border-radius: 8px;
    filter: drop-shadow(0 0 10px rgba(59, 130, 246, 0.3));
    flex-shrink: 0;
  }

  .mab-icons {
    display: flex;
    gap: clamp(2px, 1vw, 4px);
    align-items: center;
    margin: 0 clamp(2px, 2vw, 10px);
    flex-shrink: 1;
    min-width: 0;
    justify-content: flex-end;
  }

  .mab-icons>.mab-icon {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: clamp(28px, 7vw, 34px);
    height: clamp(28px, 7vw, 34px);
    border-radius: 8px;
    font-size: clamp(12px, 3vw, 14px);
    text-decoration: none;
    color: #fff;
    cursor: pointer;
    flex-shrink: 0;
  }

  .mab-icons>.mab-icon:hover,
  .mab-icons>.mab-icon.active,
  .mab-icons>.mab-icon.submenu-open {
    background: rgba(255, 255, 255, 0.1);
  }

  .mab-icons .submenu {
    position: fixed;
    top: 65px;
    left: 15px;
    right: 15px;
    width: auto;
    background: rgba(30, 41, 59, 0.98);
    border: 1px solid rgba(255, 255, 255, 0.05);
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
    border-radius: 12px;
    padding: 5px;
    display: none;
    z-index: 10000;
    flex-direction: column;
    overflow-y: auto;
    max-height: calc(100vh - 80px);
  }

  .mab-icons .mab-icon.submenu-open .submenu {
    display: flex;
    animation: mabDrop 0.2s ease-out;
  }

  @keyframes mabDrop {
    from {
      opacity: 0;
      transform: translateY(-10px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .mab-icons .submenu a {
    padding: 10px 12px;
    font-size: 13px;
    text-decoration: none;
    color: var(--nav-text);
    border-radius: 8px;
    display: block;
    border: 1px solid transparent;
    transition: 0.2s;
  }
  .mab-icons .submenu a:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #fff;
    border-color: rgba(255, 255, 255, 0.1);
  }

  .mab-project-group {
    border-bottom: 1px solid rgba(255,255,255,0.05);
    padding: 2px 0;
  }
  .mab-project-main {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    color: #fff !important;
    text-decoration: none;
    font-size: 12px !important;
    font-weight: 700;
    cursor: pointer;
  }
  .mab-project-subs {
    display: none;
    padding-left: 15px;
    background: rgba(255,255,255,0.02);
    flex-direction: column;
    margin: 0 5px 5px;
    border-radius: 8px;
  }
  .mab-project-group.open .mab-project-subs {
    display: flex;
  }
  .mab-project-group .mab-toggle-icon {
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    font-size: 10px;
    transition: 0.3s;
    opacity: 0.8;
    border: 1px solid rgba(255,255,255,0.1);
  }
  .mab-project-group.open .mab-toggle-icon {
    transform: rotate(180deg);
    background: var(--nav-accent);
    opacity: 1;
    border-color: var(--nav-accent);
  }
  .mab-project-subs a {
    padding: 6px 12px !important;
    font-size: 11px !important;
    opacity: 0.8;
  }

  .mab-robot {
    width: clamp(32px, 8vw, 36px);
    height: clamp(32px, 8vw, 36px);
    background: rgba(59, 130, 246, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: clamp(14px, 4vw, 18px);
    text-decoration: none;
    flex-shrink: 0;
    margin-left: clamp(2px, 1vw, 10px);
    cursor: pointer;
  }

  .mab-robot.active {
    background: var(--nav-accent);
    border-color: var(--nav-accent);
    box-shadow: 0 0 15px var(--nav-accent-glow);
  }

  /* Also for side mode on mobile, allow submenu toggling */
  @media (max-width: 1024px) {
    .is-side .submenu {
      display: none;
    }

    .has-submenu.submenu-open>.submenu {
      display: block !important;
    }
  }

  /* === Submenus === */
  .has-submenu {
    position: relative;
  }

  .submenu {
    display: none;
    background: rgba(30, 41, 59, 0.4);
    backdrop-filter: blur(20px);
    min-width: 240px;
    border-radius: 12px;
    padding: 5px;
    list-style: none;
    border: 1px solid var(--glass-border);
    margin: 5px 0;
  }

  /* Sidebar: Akkordeon-Style (nach unten aufklappen) */
  .is-side .submenu {
    position: static;
    box-shadow: none;
    width: 100%;
  }

  /* Topbar: Pop-out Style (nach unten ploppen) */
  .is-top .submenu {
    display: none;
    position: absolute;
    left: 0;
    top: 100%;
    margin-top: 5px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    z-index: 1100;
    background: rgba(30, 41, 59, 0.98);
  }

  .is-top .submenu::before {
    content: "";
    position: absolute;
    left: 0;
    top: -15px;
    width: 100%;
    height: 15px;
  }

  .has-submenu:hover>.submenu {
    display: block;
    animation: navIn 0.3s ease-out;
  }

  @keyframes navIn {
    from {
      opacity: 0;
      transform: translateY(-5px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .is-top .has-submenu:hover>.submenu {
    animation: navInTop 0.2s ease-out;
  }

  @keyframes navInTop {
    from {
      opacity: 0;
      transform: translateY(10px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .submenu li a {
    padding: 8px 12px;
    font-size: 12px;
    opacity: 0.8;
  }

  .submenu li a:hover {
    opacity: 1;
    background: var(--nav-accent);
  }

  /* === Project Switcher === */
  .project-switcher {
    margin: 0 24px 20px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--glass-border);
    border-radius: 15px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    transition: 0.3s;
  }

  .project-switcher:hover {
    border-color: var(--nav-accent);
    background: rgba(59, 130, 246, 0.05);
  }

  .is-top .project-switcher {
    margin: 0 20px;
    min-width: 220px;
  }

  .ps-label {
    font-size: 9px;
    font-weight: 800;
    color: var(--nav-accent);
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  .ps-select {
    background: transparent;
    border: 0;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    width: 100%;
    cursor: pointer;
    outline: none;
    appearance: none;
  }

  .ps-select option {
    background: var(--nav-bg);
    color: #fff;
  }
</style>

<nav class="mobile-app-bar">
  <button class="mab-hamburger" id="master-mobile-trigger" onclick="toggleMobileNav()">☰</button>
  <a href="<?= e(url('index_superadmin.php')) ?>" class="mab-logo">
    <picture style="display:flex;">
      <source srcset="<?= e(url('assets/brand/logo-mark.webp')) ?>" type="image/webp">
      <img src="<?= e(url('assets/brand/logo-mark.png')) ?>" alt="P">
    </picture>
  </a>
  <div class="mab-icons">
    <a class="mab-icon <?= $is_active('index_superadmin.php') ? 'active' : '' ?>"
      href="<?= e(url('index_superadmin.php')) ?>">🏛️</a>
    <div class="mab-icon mab-has-sub <?= $is_active('pendenzen.php') ? 'active' : '' ?>">
      📜<span style="font-size:8px;margin-left:2px;opacity:.5;">▼</span>
      <div class="submenu">
        <a href="<?= e(url('pages/pendenzen.php')) ?>">📊 Pendenz-Cockpit</a>
        <a href="<?= e(url('pages/pendenzen_liste.php')) ?>">📜 Alle Listen</a>
        <a href="<?= e(url('pages/terminprogramm.php')) ?>">📅 Terminprogramm</a>
        <a href="<?= e(url('pages/listen_settings.php')) ?>">⚙️ Listen-Einstellungen</a>
        <a href="<?= e(url('pages/protokoll_manager.php')) ?>">📝 Protokoll-Konfigurator</a>
        <a href="<?= e(url('pages/abnahmen.php')) ?>">📋 Abnahmen & Protokolle</a>
      </div>
    </div>
    <div class="mab-icon mab-has-sub">
      🏗️<span style="font-size:8px;margin-left:2px;opacity:.5;">▼</span>
      <div class="submenu">
        <a href="<?= e(url('pages/projekte.php')) ?>">📂 Projekt-Übersicht</a>
        <a href="<?= e(url('pages/projekt_neu.php')) ?>">➕ Neu anlegen</a>
        <div style="border-top:1px solid rgba(255,255,255,0.1); margin:5px 0;"></div>
        <?php foreach ($nav_projects as $np): ?>
            <div class="mab-project-group js-mab-group">
              <div class="mab-project-main" onclick="event.stopPropagation(); this.parentElement.classList.toggle('open')">
                <span>🏢 <?= e($np['name']) ?></span>
                <span class="mab-toggle-icon">▼</span>
              </div>
              <div class="mab-project-subs">
                <a href="<?= e(url('pages/projekt_dashboard.php?id=' . $np['id'])) ?>" onclick="event.stopPropagation()">📊 Dashboard</a>
                <a href="<?= e(url('pages/mieterspiegel.php?projekt_id=' . $np['id'])) ?>" onclick="event.stopPropagation()">📈 Mieterspiegel</a>
                <a href="<?= e(url('tools/mietkontrolle/index.php?projekt_id=' . $np['id'])) ?>" onclick="event.stopPropagation()">💰 Mietkontrolle</a>
                <a href="<?= e(url('pages/pendenzen.php?projekt_id=' . $np['id'])) ?>" onclick="event.stopPropagation()">📋 Pendenzen</a>
              </div>
            </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="mab-icon mab-has-sub">
      🤝<span style="font-size:8px;margin-left:2px;opacity:.5;">▼</span>
      <div class="submenu">
        <a href="<?= e(url('pages/benutzer.php')) ?>">👥 Partner & Personen</a>
        <a href="<?= e(url('pages/firmen.php')) ?>">🏢 Firmen</a>
        <a href="<?= e(url('pages/bkp_codes.php')) ?>">🛠️ BKP 2 Gebäude</a>
        <a href="<?= e(url('pages/wohnungen_liste.php')) ?>">🏘️ Bestand & Einheiten</a>
        <a href="<?= e(url('pages/interessenten.php')) ?>">📈 Leads & CRM</a>
      </div>
    </div>
    <div class="mab-icon mab-has-sub">
      📈<span style="font-size:8px;margin-left:2px;opacity:.5;">▼</span>
      <div class="submenu">
        <a href="<?= e(url('pages/finanzen.php')) ?>">📈 Finanzen</a>
        <a href="<?= e(url('tools/mietkontrolle/index.php')) ?>">💰 Mietkontrolle</a>
        <a href="<?= e(url('tools/konto_verwaltung/index.php')) ?>">🏦 Bankkonto</a>
      </div>
    </div>
    <div class="mab-icon mab-has-sub">
      🛡️<span style="font-size:8px;margin-left:2px;opacity:.5;">▼</span>
      <div class="submenu">
        <a href="<?= e(url('pages/audit.php')) ?>">🛡️ System-Audit</a>
        <a href="<?= e(url('pages/ai_assistant.php')) ?>">🤖 KI-Kommandozentrale</a>
      </div>
    </div>
  </div>
  <button class="mab-robot" id="mab-ai-btn" onclick="toggleAiAssistant()">🤖</button>
</nav>

<div class="mobile-sidebar-overlay" onclick="toggleMobileNav()"></div>

<script>
  /**
   * Sofort-Korrektur des Layouts um Flackern zu vermeiden
   */
  (function () {
    let savedLayout = localStorage.getItem('nav-layout');
    if (!savedLayout) {
        savedLayout = "<?= e($nav_mode) ?>";
        localStorage.setItem('nav-layout', savedLayout);
    }
    document.body.classList.remove('nav-mode-top', 'nav-mode-side', 'nav-mode-left', 'nav-top', 'nav-side');
    document.body.classList.add('nav-mode-' + savedLayout, 'nav-' + savedLayout);
  })();
</script>

<div id="mainNav" class="main-nav-container <?= $nav_mode === 'top' ? 'is-top' : 'is-side' ?>">
<script>
  (function(){
    let savedLayout = localStorage.getItem('nav-layout') || "<?= e($nav_mode) ?>";
    let nav = document.getElementById('mainNav');
    if(savedLayout === 'top') { nav.classList.remove('is-side'); nav.classList.add('is-top'); }
    else { nav.classList.remove('is-top'); nav.classList.add('is-side'); }
  })();
</script>
  <!-- Brand & Utility -->
  <div
    style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding: <?= $nav_mode === 'top' ? '0 20px 0 0' : '20px 24px' ?>;">
    <a href="<?= e(url('index_superadmin.php')) ?>" class="nav-brand" style="padding:0; gap:8px;">
      <picture>
        <source srcset="<?= e(url('assets/brand/logo-mark.webp')) ?>" type="image/webp">
        <img src="<?= e(url('assets/brand/logo-mark.png')) ?>" alt="Logo" style="width:28px; height:28px;">
      </picture>
      <span style="font-size:17px; letter-spacing: -0.5px;">pendenz<span
          style="color:var(--nav-accent);">.com</span></span>
    </a>

    <div class="nav-utils" style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
      <!-- Close button mobile -->
      <button class="mobile-close-sidebar" onclick="toggleMobileNav()"
        style="display:none; background:transparent; border:none; color:var(--nav-text); font-size:24px; cursor:pointer; padding:0 8px; line-height:1; opacity:0.8;">✕</button>
      <button class="layout-toggle" onclick="toggleNavLayout()" title="Layout wechseln">🌓</button>
      <button class="ai-symbol-btn" id="ai-toggle-btn" onclick="toggleAiAssistant()"
        title="KI-Assistent ein/aus">🤖</button>
    </div>
  </div>

  <!-- Project Switcher -->
  <div class="project-switcher">
    <span class="ps-label">📍 Aktuelles Projekt</span>
    <select class="ps-select" id="globalProjectSwitcher" name="global_project_id"
      onchange="switchGlobalProject(this.value)">
      <option value="0">-- Projekt wählen --</option>
      <?php foreach ($nav_projects as $np): ?>
          <option value="<?= $np['id'] ?>" <?= ($current_pid == $np['id']) ? 'selected' : '' ?>>
            <?= e($np['nummer'] ? ($np['nummer'] . ' - ' . $np['name']) : $np['name']) ?>
          </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="nav-sections">
    <!-- Strategie -->
    <div class="nav-section">
      <ul class="nav-menu">
        <li class="<?= $is_active('index_superadmin.php') ?>"><a href="<?= e(url('index_superadmin.php')) ?>">🏛️ <span
              class="nav-text">Portfolio-Zentrale</span></a></li>
      </ul>
    </div>

    <!-- CORE: Listen & Pendenzen -->
    <div class="nav-section">
      <ul class="nav-menu">
        <li class="<?= $is_active('pendenzen.php') ?> has-submenu">
          <a href="<?= e(url('pages/pendenzen.php')) ?>">📜 <span class="nav-text">Listen & Pendenzen</span></a>
          <ul class="submenu">
            <li><a href="<?= e(url('pages/pendenzen.php')) ?>">📊 Pendenz-Cockpit</a></li>
            <li><a href="<?= e(url('pages/pendenzen_liste.php')) ?>">📜 Alle Listen</a></li>
            <li><a href="<?= e(url('pages/terminprogramm.php')) ?>">📅 Terminprogramm (Gantt)</a></li>
            <li><a href="<?= e(url('pages/listen_settings.php')) ?>">⚙️ Listen-Einstellungen</a></li>
            <li><a href="<?= e(url('pages/protokoll_manager.php')) ?>">📝 Protokoll-Konfigurator</a></li>
            <li><a href="<?= e(url('pages/abnahmen.php')) ?>">📋 Abnahmen & Protokolle</a></li>
          </ul>
        </li>
      </ul>
    </div>

    <!-- Management -->
    <div class="nav-section">
      <ul class="nav-menu">
        <li class="has-submenu <?= $is_active('projekte.php') ?>">
          <a href="<?= e(url('pages/projekte.php')) ?>">🏗️ <span class="nav-text">Projekte</span></a>
          <ul class="submenu">
            <li><a href="<?= e(url('pages/projekte.php')) ?>">📂 Projekt-Übersicht</a></li>
            <li><a href="<?= e(url('pages/projekt_neu.php')) ?>">➕ Neu anlegen</a></li>
            <li style="border-top:1px solid rgba(255,255,255,0.1); margin:5px 0;"></li>
            <?php foreach ($nav_projects as $np): ?>
                <li class="has-submenu">
                  <a href="<?= e(url('pages/projekt_dashboard.php?id=' . $np['id'])) ?>">🏢 <?= e($np['name']) ?></a>
                  <ul class="submenu">
                    <li><a href="<?= e(url('pages/projekt_dashboard.php?id=' . $np['id'])) ?>">📊 Dashboard</a></li>
                    <li><a href="<?= e(url('pages/mieterspiegel.php?projekt_id=' . $np['id'])) ?>">📈 Mieterspiegel</a></li>
                    <li><a href="<?= e(url('tools/mietkontrolle/index.php?projekt_id=' . $np['id'])) ?>">💰 Mietkontrolle</a></li>
                    <li><a href="<?= e(url('pages/pendenzen.php?projekt_id=' . $np['id'])) ?>">📋 Pendenzen</a></li>
                  </ul>
                </li>
            <?php endforeach; ?>
          </ul>
        </li>

        <li class="has-submenu <?= $is_active('benutzer.php') ?>">
          <a href="<?= e(url('pages/benutzer.php')) ?>">🤝 <span class="nav-text">Partner & Bestand</span></a>
          <ul class="submenu">
            <li><a href="<?= e(url('pages/benutzer.php')) ?>">👥 Partner & Personen</a></li>
            <li><a href="<?= e(url('pages/firmen.php')) ?>">🏢 Firmen</a></li>
            <li><a href="<?= e(url('pages/bkp_codes.php')) ?>">🛠️ BKP 2 Gebäude</a></li>
            <li><a href="<?= e(url('pages/wohnungen_liste.php')) ?>">🏘️ Bestand & Einheiten</a></li>
            <li><a href="<?= e(url('pages/interessenten.php')) ?>">📈 Leads & CRM</a></li>
          </ul>
        </li>
      </ul>
    </div>

    <!-- Controlling -->
    <div class="nav-section">
      <ul class="nav-menu">
        <li class="has-submenu <?= ($is_active('finanzen.php') || $is_active('mietkontrolle') || $is_active('konto_verwaltung')) ? 'active' : '' ?>">
          <a href="<?= e(url('pages/finanzen.php')) ?>">📈 <span class="nav-text">Finanzen</span></a>
          <ul class="submenu">
            <li><a href="<?= e(url('pages/finanzen.php')) ?>">📈 Finanz-Übersicht</a></li>
            <li><a href="<?= e(url('tools/mietkontrolle/index.php')) ?>">💰 Mietkontrolle &amp; Zahlungen</a></li>
            <li><a href="<?= e(url('tools/konto_verwaltung/index.php')) ?>">🏦 Bankkonto &amp; CSV-Abgleich</a></li>
          </ul>
        </li>
        <li class="has-submenu <?= $is_active('audit.php') ?>">
          <a href="<?= e(url('pages/audit.php')) ?>">🛡️ <span class="nav-text">Admin</span></a>
          <ul class="submenu">
            <li><a href="<?= e(url('pages/audit.php')) ?>">🛡️ System-Audit</a></li>
            <li class="<?= $is_active('system_settings.php') ?>"><a href="<?= e(url('pages/system_settings.php')) ?>">⚙️ System-Einstellungen</a></li>
            <li class="<?= $is_active('ai_assistant.php') ?>"><a href="<?= e(url('pages/ai_assistant.php')) ?>">🤖
                KI-Kommandozentrale</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>

  <!-- Footer / Simulation -->
  <div class="nav-footer">
    <div class="sim-box">
      <div>
        <span class="sim-label"><?= $simRole ? '🔁 SIM' : '🧪 SIM' ?></span>
        <span class="sim-val"><?= $simRole ? e($simRole) : 'Admin' ?></span>
      </div>
      <div class="sim-btns">
        <?php if (!$simRole): ?>
            <a href="<?= e(url('simulate.php?role=gast')) ?>" title="Gast">G</a>
            <a href="<?= e(url('simulate.php?role=benutzer')) ?>" title="Benutzer">B</a>
            <a href="<?= e(url('simulate.php?role=admin')) ?>" title="Admin">A</a>
        <?php else: ?>
            <a href="<?= e(url('simulate.php?reset=1')) ?>">Reset</a>
        <?php endif; ?>
      </div>
    </div>
    <ul class="nav-menu">
      <li><a href="<?= e(url('tools/mietkontrolle/index.php')) ?>">💰 Mietkontrolle</a></li>
      <li><a href="<?= e(url('tools/konto_verwaltung/index.php')) ?>">⚙️ Konto</a></li>
      <li><a href="<?= e(url('logout.php')) ?>" style="color:#f87171;">🚪 Logout</a></li>
    </ul>
  </div>
</div>

<script>
  function switchGlobalProject(pid) {
    if (pid == 0) return;
    const url = new URL(window.location.href);
    // Falls wir auf einer Seite sind, die 'id' statt 'projekt_id' nutzt (z.B. Dashboard)
    if (url.searchParams.has('id')) {
      url.searchParams.set('id', pid);
    }
    url.searchParams.set('projekt_id', pid);
    window.location.href = url.toString();
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
      nav.classList.remove('is-side');
      nav.classList.add('is-top');
      body.classList.remove('nav-side', 'nav-mode-side', 'nav-mode-left');
      body.classList.add('nav-top', 'nav-mode-top');
      if (document.querySelector('.layout-toggle')) document.querySelector('.layout-toggle').title = 'Layout: Seite';
    } else {
      nav.classList.remove('is-top');
      nav.classList.add('is-side');
      body.classList.remove('nav-top', 'nav-mode-top');
      body.classList.add('nav-side', 'nav-mode-side');
      if (document.querySelector('.layout-toggle')) document.querySelector('.layout-toggle').title = 'Layout: Oben';
    }

    localStorage.setItem('nav-layout', layout);
    // Cookie setzen für Server-Side Rendering (Flicker prevention)
    document.cookie = "nav-layout=" + layout + "; path=/; max-age=" + (365 * 24 * 60 * 60);
  }

  function toggleAiAssistant() {
    const btn1 = document.getElementById('ai-toggle-btn');
    const btn2 = document.getElementById('mab-ai-btn');
    let isActive = false;
    if (btn1) isActive = btn1.classList.toggle('active');
    if (btn2) isActive = btn2.classList.toggle('active');
    localStorage.setItem('ai-assistant-active', isActive ? '1' : '0');

    if (typeof toggleGimi === 'function') toggleGimi();
    window.dispatchEvent(new CustomEvent('aiStateChanged', { detail: { active: isActive } }));
  }

  function toggleMobileNav() {
    const isOpen = document.body.classList.toggle('main-sidebar-open');
    const btn = document.getElementById('master-mobile-trigger');
    if (btn) btn.innerHTML = isOpen ? '✕' : '☰';
  }

  document.addEventListener('DOMContentLoaded', () => {
    const saved = localStorage.getItem('nav-layout') || 'side';
    applyNavLayout(saved);

    const aiSaved = localStorage.getItem('ai-assistant-active') === '1';
    if (aiSaved) {
      if (document.getElementById('ai-toggle-btn')) document.getElementById('ai-toggle-btn').classList.add('active');
      if (document.getElementById('mab-ai-btn')) document.getElementById('mab-ai-btn').classList.add('active');
    }

    // Desktop Mobile Submenu Toggler
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

    // Mobile App Bar Submenu Toggler
    document.querySelectorAll('.mab-has-sub').forEach(icon => {
      icon.addEventListener('click', (e) => {
        // If clicking exactly the parent element, toggle the state
        if (e.target === icon || e.target.tagName.toLowerCase() === 'span') {
          e.preventDefault();
          e.stopPropagation();

          // Close others
          document.querySelectorAll('.mab-has-sub.submenu-open').forEach(other => {
            if (other !== icon) other.classList.remove('submenu-open');
          });

          icon.classList.toggle('submenu-open');
        }
      });
    });

    // Close submenus on external click
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.mab-has-sub')) {
        document.querySelectorAll('.mab-has-sub.submenu-open').forEach(el => el.classList.remove('submenu-open'));
      }
    });
  });
</script>