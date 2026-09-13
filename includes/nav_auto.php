<?php
// includes/nav_auto.php
if (session_status() === PHP_SESSION_NONE) session_start();

// Basis-Helper laden
$fn = __DIR__ . '/functions.php';
if (is_file($fn)) require_once $fn;
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

// Effektive Rolle (Simulation hat Vorrang)
$real  = $_SESSION['rolle'] ?? 'gast';
$sim   = $_SESSION['simulate_role'] ?? null;
$role  = $sim ?: $real;

// Mapping: welche Nav-Datei für welche Rolle
$map = [
  'superadmin'    => __DIR__ . '/nav_superadmin.php',
  'admin'         => __DIR__ . '/nav_admin.php',
  'projektleiter' => __DIR__ . '/nav_projektleiter.php',
  'benutzer'      => __DIR__ . '/nav_user.php',
  'gast'          => __DIR__ . '/nav_guest.php',
];

// Datei wählen: erst spezifisch, sonst Fallbacks
$chosen = null;
if (isset($map[$role]) && is_file($map[$role])) {
  $chosen = $map[$role];
} else {
  // nächste beste Wahl
  foreach (['nav_admin.php','nav_user.php','nav.php'] as $fallback) {
    $p = __DIR__ . '/' . $fallback;
    if (is_file($p)) { $chosen = $p; break; }
  }
}

// Laden
if ($chosen) {
  require $chosen;
} else {
  // absolute Notlösung, damit die Seite nicht blank bleibt
  echo '<nav class="main-nav"><a class="brand" href="'.e(url('index_admin.php')).'">pendenz.com</a></nav>';
}
