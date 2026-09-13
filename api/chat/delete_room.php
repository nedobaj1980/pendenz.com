<?php
// api/chat/delete_room.php
// Löscht einen Chatraum inkl. Messages/Members/Reads/Typing.
// Erlaubt nur, wenn der Aufrufer in diesem Raum "admin" ist ODER den Raum erstellt hat.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/csrf.php';

function jexit($ok, array $extra = [], int $code = 200) {
  http_response_code($code);
  echo json_encode(['ok'=>$ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) jexit(false, ['error'=>'not_logged_in'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jexit(false, ['error'=>'method'], 405);
if (!csrf_validate_request()) jexit(false, ['error'=>'csrf'], 400);

$roomId = (int)($_POST['room_id'] ?? 0);
if ($roomId <= 0) jexit(false, ['error'=>'room_id required'], 400);

// Raum laden + Berechtigung prüfen
$st = $mysqli->prepare("SELECT r.id, r.name, r.created_by FROM chat_rooms r WHERE r.id=? LIMIT 1");
$st->bind_param("i", $roomId);
$st->execute();
$room = $st->get_result()->fetch_assoc();
$st->close();
if (!$room) jexit(false, ['error'=>'not_found'], 404);

// Ist User Admin in diesem Raum?
$st = $mysqli->prepare("SELECT role FROM chat_members WHERE room_id=? AND user_id=? LIMIT 1");
$st->bind_param("ii", $roomId, $uid);
$st->execute();
$m = $st->get_result()->fetch_assoc();
$st->close();

$allowed = ($room['created_by'] == $uid) || ($m && $m['role'] === 'admin');
if (!$allowed) jexit(false, ['error'=>'forbidden'], 403);

// Delete in einer Transaktion
$mysqli->begin_transaction();
try {
  // Reihenfolge: child -> parent
  $st = $mysqli->prepare("DELETE FROM chat_messages WHERE room_id=?");
  $st->bind_param("i", $roomId); $st->execute(); $st->close();

  if ($mysqli->query("SHOW TABLES LIKE 'chat_reads'")->num_rows) {
    $st = $mysqli->prepare("DELETE FROM chat_reads WHERE room_id=?");
    $st->bind_param("i", $roomId); $st->execute(); $st->close();
  }
  if ($mysqli->query("SHOW TABLES LIKE 'chat_typing'")->num_rows) {
    $st = $mysqli->prepare("DELETE FROM chat_typing WHERE room_id=?");
    $st->bind_param("i", $roomId); $st->execute(); $st->close();
  }

  $st = $mysqli->prepare("DELETE FROM chat_members WHERE room_id=?");
  $st->bind_param("i", $roomId); $st->execute(); $st->close();

  $st = $mysqli->prepare("DELETE FROM chat_rooms WHERE id=?");
  $st->bind_param("i", $roomId); $st->execute(); $st->close();

  $mysqli->commit();
  jexit(true, ['deleted_room_id'=>$roomId, 'name'=>$room['name']]);
} catch (Throwable $e) {
  $mysqli->rollback();
  jexit(false, ['error'=>'sql','message'=>$e->getMessage()], 500);
}
