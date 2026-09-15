<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Config
require_once __DIR__ . '/../config.php';

// Optional: zentrale Helper laden
$helpers = __DIR__ . '/url_helpers.php';
if (is_file($helpers)) require_once $helpers;

$fn = __DIR__ . '/functions.php';
if (is_file($fn)) require_once $fn;

$cspFn = __DIR__ . '/csp.php';
$nonce = '';
if (is_file($cspFn)) {
    require_once $cspFn;
    $nonce = csp_send_header();
}

/* ===== Fallbacks (idempotent) ===== */
if (!function_exists('e')) {
  function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('site_prefix')) {
  function site_prefix(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    return (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php')
      ? '/pendenz.com' : '';
  }
}
if (!function_exists('url')) {
  function url(string $file): string {
    return rtrim(site_prefix(), '/') . '/' . ltrim($file, '/');
  }
}
if (!function_exists('page_url')) {
  function page_url(string $file): string { return url('pages/' . ltrim($file, '/')); }
}
if (!function_exists('asset_url')) {
  function asset_url(string $f): string { return url('assets/' . ltrim($f, '/')); }
}
if (!function_exists('brand_url')) {
  function brand_url(string $f): string { return asset_url('brand/' . ltrim($f, '/')); }
}

/* ===== Seitentitel ===== */
$title = isset($PAGE_TITLE) && $PAGE_TITLE ? ($PAGE_TITLE . ' – pendenz.com') : 'pendenz.com';

// (Optional) Standard-Header für bessere Sicherheit
// header('X-Content-Type-Options: nosniff');
// header('Referrer-Policy: no-referrer-when-downgrade');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="manifest" href="<?= e(url('manifest.json')) ?>">
  <title><?= e($title) ?></title>

  <!-- Favicons für Browser-Tab (Cache-Buster hinzugefügt, um altes Logo zu überschreiben) -->
  <?php $cb = time(); ?>
  <link rel="icon" type="image/webp" href="<?= e(brand_url('logo-mark%20-%20Kopie.webp?v=' . $cb)) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(brand_url('favicon-32.png?v=' . $cb)) ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= e(brand_url('favicon-16.png?v=' . $cb)) ?>">
  <link rel="apple-touch-icon" href="<?= e(brand_url('apple-touch-icon.png?v=' . $cb)) ?>">

  <!-- ✅ Basis-CSS -->
  <link rel="stylesheet" href="<?= e(asset_url('nav.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('style.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('css/notifications.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('css/ai_assistant.css')) ?>" />

  <!-- (optional) globale JS -->
  <script src="<?= e(asset_url('js/notifications_dropdown.js')) ?>" defer></script>
  <!-- Prevent FOUC: apply saved image sizes before page renders -->
  <script>(function(){
    var de = localStorage.getItem('p_img_desktop');
    var mo = localStorage.getItem('p_img_mobile');
    if (de) document.documentElement.style.setProperty('--thumb-width-desktop', de + 'px');
    if (mo) document.documentElement.style.setProperty('--thumb-width-mobile', mo + 'px');
  })();</script>
</head>
<?php
// Fix: Layout schon im Body-Tag festlegen um Springen zu vermeiden
$nav_mode_raw = $nav_mode ?? $_COOKIE['nav-layout'] ?? 'top';
$body_class = 'nav-mode-' . $nav_mode_raw;
?>
<body class="<?= e($body_class) ?>">
