<?php
// api/chat/read.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/csrf.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit; }
if (!csrf_validate_request()) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'csrf']); exit; }

$roomId = (int)($_POST['room_id'] ?? 0);
$lastId = (int)($_POST['last_id'] ?? 0);
if ($roomId <= 0 || $lastId < 0) { echo json_encode(['ok'=>false,'error'=>'params']); exit; }

// Mitgliedschaft prüfen
$st = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
$st->bind_param("ii", $roomId, $uid);
$st->execute();
if (!$st->get_result()->fetch_row()) { $st->close(); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
$st->close();

$sql = "INSERT INTO chat_reads (room_id, user_id, last_read_id, updated_at)
        VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE last_read_id=VALUES(last_read_id), updated_at=VALUES(updated_at)";
$st = $mysqli->prepare($sql);
$st->bind_param("iii", $roomId, $uid, $lastId);
$st->execute();
$st->close();

echo json_encode(['ok'=>true]);
