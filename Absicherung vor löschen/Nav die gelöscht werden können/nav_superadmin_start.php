<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();
require_once __DIR__ . '/includes/functions.php';

// Nur für Superadmin
if (($_SESSION['rolle'] ?? null) !== 'superadmin') {
    die("Zugriff verweigert: Nur Superadmin hat Zugriff auf diese Seite.");
}

// Layout + Navi
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/nav_superadmin.php';
?>
