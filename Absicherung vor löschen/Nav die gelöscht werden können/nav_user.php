<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$__uri = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('nav_active')) {
  function nav_active(string $needle, string $uri): string {
    return (strpos($uri, $needle) !== false) ? ' class="active"' : '';
  }
}

/* Config/DB (falls nicht schon vorhanden) */
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  require_once __DIR__ . '/../config.php';
}

/* Rollenranking (für Chat-Sichtbarkeit etc.) */
if (!function_exists('roleRank')) {
  function roleRank($r){ return ['gast'=>0,'benutzer'=>1,'admin'=>2,'superadmin'=>3][$r] ?? 0; }
}
$realRole = $_SESSION['rolle'] ?? 'gast';
$effRole  = $realRole;
$rr       = roleRank($effRole);

/* Nur eigenes Profil verlinken */
$sid = (int)($_SESSION['user_id'] ?? 0);

/* Projektkontext (optional, falls Chat projektgebunden ist) */
$projektId = 0;
if (isset($_GET['projekt_id'])) {
  $projektId = (int)$_GET['projekt_id'];
} elseif (!empty($_SESSION['current_project_id'])) {
  $projektId = (int)$_SESSION['current_project_id'];
}
$withPid = function(string $href) use ($projektId): string {
  if ($projektId <= 0) return $href;
  return (strpos($href,'?') === false) ? ($href.'?projekt_id='.$projektId) : ($href.'&projekt_id='.$projektId);
};

$isLogged   = !empty($_SESSION['user_id']);
$canSeeChat = $isLogged && ($rr >= roleRank('benutzer')); // user darf chat sehen (falls ihr das wollt)
?>
<nav class="main-nav">
  <!-- BRAND -->
  <a class="brand" href="<?= htmlspecialchars(url('index_private.php')) ?>">
    <picture>
      <source srcset="<?= htmlspecialchars(brand_url('logo-mark.webp')) ?>" type="image/webp">
      <img src="<?= htmlspecialchars(brand_url('logo-mark.png')) ?>" alt="pendenz.com" width="28" height="28" loading="lazy">
    </picture>
    <span>pendenz.com</span>
  </a>

  <ul class="menu">
    <li<?= nav_active('/index_private.php', $__uri) ?>>
      <a href="<?= htmlspecialchars(url('index_private.php')) ?>">Start</a>
    </li>

    <!-- Kernaufgaben -->
    <li<?= nav_active('/pages/pendenzen.php', $__uri) ?>>
      <a href="<?= htmlspecialchars(url('pages/pendenzen.php')) ?>">Pendenzen</a>
    </li>
    <li<?= nav_active('/pages/pendenzen_liste.php', $__uri) ?>>
      <a href="<?= htmlspecialchars(url('pages/pendenzen_liste.php')) ?>">Pendenzen-Listen</a>
    </li>

    <!-- Optional: Chat -->
    <?php if ($canSeeChat): ?>
      <li<?= nav_active('/pages/chat.php', $__uri) ?>>
        <a href="<?= htmlspecialchars($withPid(url('pages/chat.php'))) ?>">Chat</a>
      </li>
    <?php endif; ?>

    <!-- Eigenes Profil -->
    <li<?= nav_active('/pages/benutzer.php', $__uri) ?>>
      <a href="<?= htmlspecialchars(url('pages/benutzer.php?view='.$sid)) ?>">Mein Profil</a>
    </li>

    <li class="spacer"></li>
    <li><a class="muted" href="<?= htmlspecialchars(url('logout.php')) ?>">Logout</a></li>
  </ul>
</nav>
