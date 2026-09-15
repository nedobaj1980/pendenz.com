<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

$helpers = __DIR__ . '/url_helpers.php';
if (is_file($helpers)) {
    require_once $helpers;
}

$fn = __DIR__ . '/functions.php';
if (is_file($fn)) {
    require_once $fn;
}

$cspFn = __DIR__ . '/csp.php';
$nonce = '';
if (is_file($cspFn)) {
    require_once $cspFn;
    $nonce = csp_send_header();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self)');
}

if (!function_exists('e')) {
    function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('site_prefix')) {
    function site_prefix(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        return (strpos($scriptName, '/pendenz.com/') === 0
            || $scriptName === '/pendenz.com'
            || $scriptName === '/pendenz.com/index.php')
            ? '/pendenz.com'
            : '';
    }
}

if (!function_exists('url')) {
    function url(string $file): string
    {
        return rtrim(site_prefix(), '/') . '/' . ltrim($file, '/');
    }
}

if (!function_exists('page_url')) {
    function page_url(string $file): string
    {
        return url('pages/' . ltrim($file, '/'));
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $file): string
    {
        return url('assets/' . ltrim($file, '/'));
    }
}

if (!function_exists('brand_url')) {
    function brand_url(string $file): string
    {
        return asset_url('brand/' . ltrim($file, '/'));
    }
}

if (!function_exists('asset_version')) {
    function asset_version(string $relativePath): string
    {
        $fullPath = __DIR__ . '/../assets/' . ltrim($relativePath, '/');
        $mtime = is_file($fullPath) ? filemtime($fullPath) : false;
        return $mtime !== false ? (string) $mtime : '1';
    }
}

$title = isset($PAGE_TITLE) && $PAGE_TITLE
    ? ($PAGE_TITLE . ' – pendenz.com')
    : 'pendenz.com';

$brandVersion = asset_version('brand/favicon-32.png');
$cssVersion = max(
    (int) asset_version('nav.css'),
    (int) asset_version('style.css'),
    (int) asset_version('css/responsive-hardening.css')
);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="theme-color" content="#0b1220" />
  <link rel="manifest" href="<?= e(url('manifest.json')) ?>">
  <title><?= e($title) ?></title>

  <link rel="icon" type="image/webp" href="<?= e(brand_url('logo-mark%20-%20Kopie.webp?v=' . $brandVersion)) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(brand_url('favicon-32.png?v=' . $brandVersion)) ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= e(brand_url('favicon-16.png?v=' . $brandVersion)) ?>">
  <link rel="apple-touch-icon" href="<?= e(brand_url('apple-touch-icon.png?v=' . $brandVersion)) ?>">

  <link rel="stylesheet" href="<?= e(asset_url('nav.css?v=' . $cssVersion)) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('style.css?v=' . $cssVersion)) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('css/notifications.css?v=' . $cssVersion)) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('css/ai_assistant.css?v=' . $cssVersion)) ?>" />
  <link rel="stylesheet" href="<?= e(asset_url('css/responsive-hardening.css?v=' . $cssVersion)) ?>" />

  <script src="<?= e(asset_url('js/notifications_dropdown.js?v=' . $cssVersion)) ?>" defer></script>
  <script<?= $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '' ?>>
    (function () {
      var desktop = localStorage.getItem('p_img_desktop');
      var mobile = localStorage.getItem('p_img_mobile');
      if (desktop) document.documentElement.style.setProperty('--thumb-width-desktop', desktop + 'px');
      if (mobile) document.documentElement.style.setProperty('--thumb-width-mobile', mobile + 'px');
    })();
  </script>
</head>
<?php
$navModeRaw = $nav_mode ?? $_COOKIE['nav-layout'] ?? 'top';
$allowedNavModes = ['top', 'side'];
if (!in_array($navModeRaw, $allowedNavModes, true)) {
    $navModeRaw = 'top';
}
$bodyClass = 'nav-mode-' . $navModeRaw;
?>
<body class="<?= e($bodyClass) ?>">