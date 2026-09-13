<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 8; // ID of superadmin
$_SESSION['user_role'] = 'admin';
$_SESSION['role'] = 'superadmin'; // Just in case
$_SESSION['user_name'] = 'System Admin';
header("Location: pages/hard_reset.php");
exit;
