<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/pendenz.com/pages/pendenzen.php';
$_GET['action'] = 'context';
$_GET['projekt_id'] = 4;
$_GET['objekt_id'] = 0;
// fake session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
require 'c:/xampp/htdocs/pendenz.com/pages/pendenzen.php';
