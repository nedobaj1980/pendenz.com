<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$sim   = $_SESSION['simulate_role'] ?? null;
$__uri = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('nav_active')) {
  function nav_active(string $needle, string $uri): string {
    return (strpos($uri, $needle) !== false) ? ' class="active"' : '';
  }
}
?><!-- Lokale Styles NUR für diese öffentliche Navbar -->
<style>
  nav.main-nav{
    background:#0b1220;
    display:flex; align-items:center; gap:.5rem;
    padding:.25rem .5rem;
  }
  /* Brand links */
  nav.main-nav > a.brand{
    display:inline-flex !important; flex-direction:row !important; align-items:center !important; gap:.6rem !important; white-space:nowrap !important;
    min-height:48px; line-height:1; padding:0 .72rem; border-radius:10px; border:1px solid transparent; color:#fff !important; text-decoration:none;
  }
  nav.main-nav > a.brand span{ display:inline-block !important; line-height:1 !important; color:#fff !important; font-weight:800; }
  nav.main-nav a.brand > picture > img{ width:48px !important; height:48px !important; object-fit:contain; }

  /* Menü füllt die Breite → Spacer kann drücken */
  nav.main-nav .menu{
    display:flex; align-items:center; gap:.25rem;
    margin:0; padding:0; list-style:none;
    flex:1 1 auto;          /* <<< WICHTIG */
    min-width:0;
  }
  nav.main-nav .menu > li > a{
    color:#fff !important; text-decoration:none;
    min-height:48px; line-height:1; padding:0 .72rem;
    border-radius:10px; border:1px solid transparent;
    display:flex; align-items:center; gap:.35rem;
  }
  nav.main-nav .menu > li.active > a,
  nav.main-nav .menu > li > a:hover{
    background:rgba(59,130,246,.25); border-color:#3b82f6; color:#fff !important;
  }

  /* Spacer nimmt den ganzen Rest → alles danach (Login/Registrieren) rechts */
  nav.main-nav .menu .spacer{ flex:1 1 auto; }

  /* Button-Styling für Login/Registrieren */
  nav.main-nav .menu li a.nav-btn {
    padding: 0 1.2rem;
    height: 38px;
    min-height: 38px;
    align-self: center;
    border-radius: 8px;
    font-weight: 600;
  }
  nav.main-nav .menu li a.nav-btn--login {
    background: transparent;
    border: 1px solid #3b82f6;
    color: #3b82f6 !important;
  }
  nav.main-nav .menu li a.nav-btn--login:hover {
    background: rgba(59,130,246,0.1);
  }
  nav.main-nav .menu li a.nav-btn--register {
    background: #3b82f6;
    border: 1px solid #3b82f6;
    color: #fff !important;
    margin-left: 8px;
  }
  nav.main-nav .menu li a.nav-btn--register:hover {
    background: #2563eb;
    border-color: #2563eb;
  }

  /* optional mobil etwas kompakter */
  @media (max-width:760px){
    nav.main-nav{ flex-wrap:wrap; }
    nav.main-nav .menu{ width:100%; flex-wrap:wrap; }
    nav.main-nav .menu .spacer{ display:none; }
    nav.main-nav .menu li a.nav-btn--register { margin-left:0; margin-top:4px; }
  }
</style>

<nav class="main-nav">
  <!-- BRAND links -->
  <a class="brand" href="<?= htmlspecialchars(url('index_public.php')) ?>">
    <img src="<?= htmlspecialchars(asset_url('img/logo_pendenz_official.png')) ?>" alt="pendenz.com" width="48" height="48" loading="lazy">
    <span>pendenz.com</span>
  </a>

  <!-- Menü: links die Seiten, Spacer, rechts Login/Registrieren -->
  <ul class="menu">
    <li<?= nav_active('/index_public.php', $__uri) ?>><a href="<?= htmlspecialchars(url('index_public.php')) ?>">Home</a></li>
    <li<?= nav_active('/pages/idee.php', $__uri) ?>><a href="<?= htmlspecialchars(url('pages/idee.php')) ?>">Die Idee</a></li>
    <li<?= nav_active('/pages/ueber_uns.php', $__uri) ?>><a href="<?= htmlspecialchars(url('pages/ueber_uns.php')) ?>">Über uns</a></li>
    <li<?= nav_active('/pages/kontakt.php', $__uri) ?>><a href="<?= htmlspecialchars(url('pages/kontakt.php')) ?>">Kontakt</a></li>

    <li class="spacer"></li>
    <li<?= nav_active('/login.php', $__uri) ?>><a href="<?= htmlspecialchars(url('login.php')) ?>" class="nav-btn nav-btn--login">Login</a></li>
    <!-- Registration disabled manually -->
    <?php if (false): ?>
    <li<?= nav_active('/register.php', $__uri) ?>><a href="<?= htmlspecialchars(url('register.php')) ?>" class="nav-btn nav-btn--register">Registrieren</a></li>
    <?php endif; ?>

    <?php if ($sim): ?>
      <li class="sim">🔁 Simulation: <strong><?= htmlspecialchars($sim) ?></strong> &nbsp;|&nbsp; <a href="<?= htmlspecialchars(url('simulate.php?reset=1')) ?>">Zurück</a></li>
    <?php endif; ?>
  </ul>
</nav>
