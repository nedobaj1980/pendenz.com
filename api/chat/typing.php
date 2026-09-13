<?php
// api/chat/typing.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/csrf.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { echo json_encode(['ok'=>true]); exit; }
if (!csrf_validate_request()) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

$roomId = (int)($_POST['room_id'] ?? 0);
if ($roomId <= 0) { echo json_encode(['ok'=>false]); exit; }

// Mitgliedschaft ist optional „best effort“ – kann auch streng geprüft werden
$until = date('Y-m-d H:i:s', time()+5);
$st = $mysqli->prepare("INSERT INTO chat_typing (room_id, user_id, `until`) VALUES (?,?,?)
                        ON DUPLICATE KEY UPDATE `until`=VALUES(`until`)");
$st->bind_param("iis", $roomId, $uid, $until);
$st->execute();
$st->close();
echo json_encode(['ok'=>true]);
