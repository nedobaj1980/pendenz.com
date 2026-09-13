<?php
// includes/nav_dispatch.php
if (session_status() === PHP_SESSION_NONE) session_start();

$role = $_SESSION['rolle'] ?? 'gast';
$nav_mode = $_COOKIE['nav-layout'] ?? ($_SESSION['nav_layout'] ?? 'side');

// Simulation nur bei echtem Superadmin berücksichtigen
if ($role === 'superadmin' && !empty($_SESSION['simulate_role'])) {
  $role = $_SESSION['simulate_role'];
}

switch ($role) {
  case 'superadmin':
    require __DIR__ . '/nav_superadmin.php'; break;
  case 'admin':
    require __DIR__ . '/nav_admin.php'; break;
	
  case 'benutzer': // <— MySQL/Session-Rollenstring
    require __DIR__ . '/nav_benutzer.php'; break;
	
  case 'user':     // optional falls irgendwo noch 'user' gesetzt wird
    require __DIR__ . '/nav_benutzer.php'; break;
  default:
    require __DIR__ . '/nav_public.php';
}
