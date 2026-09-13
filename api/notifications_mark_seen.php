<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) { echo json_encode(['ok'=>false]); exit; }

$payload = json_decode(file_get_contents('php://input'), true);
$now = date('Y-m-d H:i:s');

if (!empty($payload['all'])) {
  $stmt = $mysqli->prepare("UPDATE user_notifications SET seen_at=? WHERE user_id=? AND seen_at IS NULL");
  $stmt->bind_param('si', $now, $userId);
  $ok = $stmt->execute(); $stmt->close();
  echo json_encode(['ok'=>$ok]); exit;
}

$id = isset($payload['id']) ? (int)$payload['id'] : 0;
if ($id > 0) {
  $stmt = $mysqli->prepare("UPDATE user_notifications SET seen_at=? WHERE id=? AND user_id=? AND seen_at IS NULL");
  $stmt->bind_param('sii', $now, $id, $userId);
  $ok = $stmt->execute(); $stmt->close();
  echo json_encode(['ok'=>$ok]); exit;
}

echo json_encode(['ok'=>false]);
