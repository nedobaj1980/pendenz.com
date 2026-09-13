<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$__uri = $_SERVER['REQUEST_URI'] ?? '';

/* === Helper sicher laden + Fallbacks ==================================== */
$__fn = __DIR__ . '/functions.php';
if (file_exists($__fn)) require_once $__fn;

if (!function_exists('e')) {
  function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
if (!function_exists('site_prefix')) {
  function site_prefix(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    return (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php')
      ? '/pendenz.com' : '';
  }
}
if (!function_exists('url')) {
  function url(string $path = ''): string {
    return rtrim(site_prefix(), '/') . '/' . ltrim($path, '/');
  }
}
if (!function_exists('brand_url')) {
  function brand_url(string $file): string {
    // Standard-Pfad zu deinen Brand-Assets
    return url('assets/brand/' . ltrim($file, '/'));
  }
}

/* === Nav-Utils =========================================================== */
if (!function_exists('nav_active')) {
  function nav_active(string $needle, string $uri): string {
    return (strpos($uri, $needle) !== false) ? ' class="active"' : '';
  }
}

/* DB laden, falls nicht vorhanden */
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  require_once __DIR__ . '/../config.php';
}

/* Rollenranking */
if (!function_exists('roleRank')) {
  function roleRank($r){ return ['gast'=>0,'benutzer'=>1,'admin'=>2,'superadmin'=>3][$r] ?? 0; }
}
$realRole = $_SESSION['rolle'] ?? 'gast';
$effRole  = $realRole;
$rr       = roleRank($effRole);

/* Projektkontext (optional) */
$projektId = 0;
if (isset($_GET['projekt_id'])) {
  $projektId = (int)$_GET['projekt_id'];
} elseif (!empty($_SESSION['current_project_id'])) {
  $projektId = (int)$_SESSION['current_project_id'];
}

/* Helper: projekt_id an URL anhängen (falls vorhanden) */
$withPid = function(string $href) use ($projektId): string {
  if ($projektId <= 0) return $href;
  return (strpos($href,'?') === false) ? ($href.'?projekt_id='.$projektId) : ($href.'&projekt_id='.$projektId);
};

/* Sichtbarkeit Chat */
$isLogged   = !empty($_SESSION['user_id']);
$canSeeChat = $isLogged && ($rr >= roleRank('benutzer'));
?>
<nav class="main-nav">
  <!-- BRAND -->
  <a class="brand" href="<?= e(url('index_admin.php')) ?>">
    <picture>
      <source srcset="<?= e(brand_url('logo-mark.webp')) ?>" type="image/webp">
      <img src="<?= e(brand_url('logo-mark.png')) ?>" alt="pendenz.com" width="28" height="28" loading="lazy">
    </picture>
    <span>pendenz.com</span>
  </a>

  <ul class="menu">
    <li<?= nav_active('/index_admin.php', $__uri) ?>>
      <a href="<?= e(url('index_admin.php')) ?>">Admin-Start</a>
    </li>
    <li<?= nav_active('/pages/benutzer.php', $__uri) ?>>
      <a href="<?= e(url('pages/benutzer.php')) ?>">Benutzer</a>
    </li>
    <li<?= nav_active('/pages/projekte.php', $__uri) ?>>
      <a href="<?= e(url('pages/projekte.php')) ?>">Projekte</a>
    </li>
    <li<?= nav_active('/pages/pendenzen.php', $__uri) ?>>
      <a href="<?= e(url('pages/pendenzen.php')) ?>">Pendenzen</a>
    </li>
    <li<?= nav_active('/pages/pendenzen_liste.php', $__uri) ?>>
      <!-- FIX: korrektes href-Attribut -->
      <a href="<?= e(url('pages/pendenzen_liste.php')) ?>">Pendenzen-Listen</a>
    </li>

    <?php if ($canSeeChat): ?>
      <li<?= nav_active('/pages/chat.php', $__uri) ?>>
        <a href="<?= e($withPid(url('pages/chat.php'))) ?>">Chat</a>
      </li>
    <?php endif; ?>

    <li<?= nav_active('/tools/konto_verwaltung', $__uri) ?>>
      <a href="<?= e(url('tools/konto_verwaltung/index.php')) ?>">Konto-Verwaltung</a>
    </li>

    <?php if (file_exists(__DIR__.'/nav_notifications.php')) require __DIR__ . '/nav_notifications.php'; ?>

    <li class="spacer"></li>
    <li><a class="muted" href="<?= e(url('logout.php')) ?>">Logout</a></li>
  </ul>
</nav>
