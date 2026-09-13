<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

// URL-Helper zentral, damit Pfade auf allen Seiten gleich sind
if (!function_exists('url')) {
  function url(string $file): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php')
      ? '/pendenz.com' : '';
    return rtrim($base,'/') . '/' . ltrim($file,'/');
  }
}
if (!function_exists('asset_url'))  { function asset_url(string $f): string { return url('assets/'.ltrim($f,'/')); } }
if (!function_exists('brand_url'))  { function brand_url(string $f): string { return asset_url('brand/'.ltrim($f,'/')); } }
