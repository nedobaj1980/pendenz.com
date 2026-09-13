<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// DB + Helpers laden (falls nicht schon da)
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  require_once __DIR__ . '/../config.php';
}

/**
 * Leichte Helper – so wie bei dir, subfolder-sicher für /pendenz.com
 * (Nur definieren, falls nicht bereits vorhanden)
 */
if (!function_exists('url')) {
  function url(string $file): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php')
      ? '/pendenz.com' : '';
    return rtrim($base, '/') . '/' . ltrim($file, '/');
  }
}
if (!function_exists('asset_url')) {
  function asset_url(string $file): string { return url('assets/' . ltrim($file, '/')); }
}
if (!function_exists('brand_url')) {
  function brand_url(string $file): string { return asset_url('brand/' . ltrim($file, '/')); }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>pendenz.com</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Basis-Styles -->
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('style.css')) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('nav.css')) ?>">

  <!-- 🔔 Benachrichtigungen (CSS + JS) -->
  <link rel="stylesheet" href="<?= htmlspecialchars(asset_url('css/notifications.css')) ?>">
  <script src="<?= htmlspecialchars(asset_url('js/notifications_dropdown.js')) ?>" defer></script>

  <!-- Hinweis:
       Die alte Einbindung /assets/js/chat.js war 404 und wurde entfernt.
       Das Chat-Widget wird (falls vorhanden) im FOOTER über assets/js/chat_widget.js geladen. -->
</head>
<body>
