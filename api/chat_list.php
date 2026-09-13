<?php
// api/chat_list.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php'; // lädt config.php etc.
require_once __DIR__ . '/../includes/auth.php';
require_login();

$userId  = (int)($_SESSION['user_id'] ?? 0);
$roomId  = (int)($_GET['room_id'] ?? 0);
$limit   = (int)($_GET['limit'] ?? 50);
$before  = (int)($_GET['before_id'] ?? 0);
if ($limit < 1 || $limit > 200) $limit = 50;

try {
  // Darf der User den Raum sehen?
  $stmt = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
  $stmt->bind_param("ii", $roomId, $userId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_column();
  $stmt->close();
  if (!$ok) throw new Exception("Kein Zugriff auf diesen Raum.");

  // Nachrichten holen
  if ($before > 0) {
    $sql = "SELECT m.id,m.room_id,m.sender_id,m.message_type,m.message_text,m.created_at,
                   u.name AS sender_name
            FROM chat_messages m
            JOIN benutzer u ON u.id=m.sender_id
            WHERE m.room_id=? AND m.id < ?
            ORDER BY m.id DESC
            LIMIT ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("iii", $roomId, $before, $limit);
  } else {
    $sql = "SELECT m.id,m.room_id,m.sender_id,m.message_type,m.message_text,m.created_at,
                   u.name AS sender_name
            FROM chat_messages m
            JOIN benutzer u ON u.id=m.sender_id
            WHERE m.room_id=?
            ORDER BY m.id DESC
            LIMIT ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $roomId, $limit);
  }
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  // in chronologischer Reihenfolge ausgeben
  $rows = array_reverse($rows);

  echo json_encode(['ok'=>true,'messages'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
