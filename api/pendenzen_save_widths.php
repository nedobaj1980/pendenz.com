<?php
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$widths = $in['widths'] ?? [];

if (is_array($widths)) {
    $_SESSION['pendenzen_col_widths'] = $widths;
    // Optional: Save to DB for persistent user settings
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Invalid data']);
}
