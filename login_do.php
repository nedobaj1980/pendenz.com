<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/auth.php';
// … hier prüfst du Benutzername/Passwort …

// Bei Erfolg:
$_SESSION['user_id'] = $user['id'];
$_SESSION['rolle']   = $user['rolle'] ?? 'benutzer';

// IMMER aufs Dashboard:
login_success_redirect();
