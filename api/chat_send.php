<?php
// api/chat_send.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);

try {
  csrf_validate_or_throw($_POST['csrf'] ?? null);

  $roomId  = (int)($_POST['room_id'] ?? 0);
  $text    = trim($_POST['message'] ?? '');
  if ($roomId <= 0 || $text === '') throw new Exception("Fehlende Daten.");

  // Mitglied?
  $stmt = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
  $stmt->bind_param("ii", $roomId, $userId);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_column();
  $stmt->close();
  if (!$ok) throw new Exception("Kein Zugriff auf diesen Raum.");

  // Message speichern
  $stmt = $mysqli->prepare("INSERT INTO chat_messages (room_id, sender_id, message_type, message_text) VALUES (?, ?, 'text', ?)");
  $stmt->bind_param("iis", $roomId, $userId, $text);
  $stmt->execute();
  $msgId = $mysqli->insert_id;
  $stmt->close();

  // Room-Timestamp hochziehen
  $mysqli->query("UPDATE chat_rooms SET updated_at=NOW() WHERE id=". (int)$roomId);

  // Recipients anlegen: alle Mitglieder des Rooms
  $stmt = $mysqli->prepare("
    INSERT INTO chat_message_recipients (message_id, recipient_user_id, delivery_status)
    SELECT ?, m.user_id, 'sent'
    FROM chat_members m
    WHERE m.room_id=?");
  $stmt->bind_param("ii", $msgId, $roomId);
  $stmt->execute();
  $stmt->close();

  echo json_encode(['ok'=>true,'message_id'=>$msgId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
