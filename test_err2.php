<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id'] = 1;
$_SESSION['rolle'] = 'admin';
$_GET['projekt_id'] = 4;
require 'pages/pendenzen.php';
