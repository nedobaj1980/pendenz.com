<?php
// api/save_pdf_mask.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || (!isset($data['blocks']) && !isset($data['sections']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

$json = json_encode($data);
$stmt = $mysqli->prepare("INSERT INTO settings (k, v) VALUES ('pdf_mask_default', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
$stmt->bind_param("s", $json);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $mysqli->error]);
}
