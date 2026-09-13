<?php
if (!function_exists('site_prefix')) {
  function site_prefix(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($sn === '/pendenz.com' || $sn === '/pendenz.com/index.php' || strpos($sn, '/pendenz.com/') === 0) {
      return '/pendenz.com/';
    }
    return '/';
  }
}

if (!function_exists('current_origin')) {
  function current_origin(): string {
    // 1) Bevorzugt feste SITE_URL aus config.php (z.B. https://deinedomain.tld/pendenz.com)
    if (defined('SITE_URL') && SITE_URL) {
      return rtrim(SITE_URL, '/');
    }
    // 2) Fallback: vom Request ableiten
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    // Wichtig: Kein doppeltes /pendenz.com anhängen; Pfad kommt separat
    return $scheme . '://' . $host;
  }
}

if (!function_exists('absolute_url')) {
  /**
   * Baut aus einem relativen Pfad (beginnend mit / oder ohne) eine absolute URL mit Schema + Host.
   * Beispiel: absolute_url('/pendenz.com/pages/pendenz_work.php?t=...') → http://localhost/pendenz.com/pages/pendenz_work.php?t=...
   */
  function absolute_url(string $path): string {
    $p = '/' . ltrim($path, '/');
    return current_origin() . $p;
  }
}

if (!function_exists('url')) {
  function url(string $file): string {
    return rtrim(site_prefix(), '/') . '/' . ltrim($file, '/');
  }
}
if (!function_exists('page_url')) {
  function page_url(string $file): string {
    return rtrim(site_prefix(), '/') . '/pages/' . ltrim($file, '/');
  }
}
if (!function_exists('asset_url')) {
  function asset_url(string $file): string {
    return rtrim(site_prefix(), '/') . '/assets/' . ltrim($file, '/');
  }
}
if (!function_exists('brand_url')) {
  function brand_url(string $file): string {
    return asset_url('brand/' . ltrim($file, '/'));
  }
}
