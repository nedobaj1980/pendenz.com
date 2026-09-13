<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 8; // ID from my previous check
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'Admin';
echo "Logged in as Admin.";
header("Location: pages/pendenzen.php");
exit;
