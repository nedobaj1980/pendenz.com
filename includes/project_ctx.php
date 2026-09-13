<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';

$__back = $_SERVER['REQUEST_URI'] ?? '/';
$projektId = 0;

if (isset($_GET['projekt_id'])) {
  $projektId = (int)$_GET['projekt_id'];
  if ($projektId > 0) $_SESSION['current_project_id'] = $projektId;
} elseif (!empty($_SESSION['current_project_id'])) {
  $projektId = (int)$_SESSION['current_project_id'];
}

if ($projektId <= 0) {
  header('Location: ' . url('pages/projekt_waehlen.php?back=' . rawurlencode($__back)));
  exit;
}

$GLOBALS['__projekt_id'] = $projektId; // optional global verwenden
