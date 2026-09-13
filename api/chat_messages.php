<?php
// /api/chat_messages.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$user_id = (int)($_SESSION['user_id'] ?? 0);
function json_ok($d=[]) { echo json_encode(['ok'=>true,'data'=>$d], JSON_UNESCAPED_UNICODE); exit; }
function json_err($m,$c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ensure_member(mysqli $db, int $room_id, int $user_id): void {
  $q = $db->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
  $q->bind_param("ii", $room_id, $user_id);
  $q->execute();
  if (!$q->get_result()->fetch_column()) json_err("Kein Raumzugriff.", 403);
}

if ($method==='GET' && $action==='list') {
  $room_id = (int)($_GET['room_id'] ?? 0);
  $after_id = isset($_GET['after_id']) ? (int)$_GET['after_id'] : 0;
  $limit = min(max((int)($_GET['limit'] ?? 50), 1), 200);
  if ($room_id<=0) json_err("room_id fehlt.");
  ensure_member($mysqli, $room_id, $user_id);

  if ($after_id>0) {
    $sql = "SELECT m.*, s.name AS sender_name
            FROM chat_messages m
            JOIN benutzer s ON s.id=m.sender_id
            WHERE m.room_id=? AND m.id>?
            ORDER BY m.id ASC
            LIMIT ?";
    $stmt=$mysqli->prepare($sql);
    $stmt->bind_param("iii", $room_id, $after_id, $limit);
  } else {
    $sql = "SELECT m.*, s.name AS sender_name
            FROM chat_messages m
            JOIN benutzer s ON s.id=m.sender_id
            WHERE m.room_id=?
            ORDER BY m.id DESC
            LIMIT ?";
    $stmt=$mysqli->prepare($sql);
    $stmt->bind_param("ii", $room_id, $limit);
  }
  $stmt->execute();
  $rows=[]; $res=$stmt->get_result();
  while($r=$res->fetch_assoc()) $rows[]=$r;
  if ($after_id===0) $rows = array_reverse($rows); // aufsteigend zurück
  json_ok($rows);
}

if ($method==='POST' && $action==='send') {
  $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $room_id = (int)($payload['room_id'] ?? 0);
  $text    = trim($payload['message_text'] ?? '');
  $type    = in_array(($payload['message_type'] ?? 'text'), ['text','system'], true) ? $payload['message_type'] : 'text';
  if ($room_id<=0 || $text==='') json_err("room_id und message_text erforderlich.");
  ensure_member($mysqli, $room_id, $user_id);

  // Nachricht speichern
  $stmt = $mysqli->prepare("INSERT INTO chat_messages (room_id, sender_id, message_type, message_text) VALUES (?,?,?,?)");
  $stmt->bind_param("iiss", $room_id, $user_id, $type, $text);
  $stmt->execute();
  $msg_id = $mysqli->insert_id;

  // Zustellzeilen erzeugen (für alle Mitglieder)
  $ins = $mysqli->prepare("INSERT INTO chat_message_recipients (message_id, recipient_user_id, delivery_status)
                           SELECT ?, m.user_id, 'sent' FROM chat_members m WHERE m.room_id=?");
  $ins->bind_param("ii", $msg_id, $room_id);
  $ins->execute();

  // Raum bumpen
  $mysqli->query("UPDATE chat_rooms SET updated_at=NOW() WHERE id=".$room_id);

  json_ok(['message_id'=>$msg_id]);
}

if ($method==='POST' && $action==='mark_read') {
  $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $room_id = (int)($payload['room_id'] ?? 0);
  $up_to_id = (int)($payload['up_to_id'] ?? 0);
  if ($room_id<=0 || $up_to_id<=0) json_err("room_id und up_to_id erforderlich.");
  ensure_member($mysqli, $room_id, $user_id);

  $sql = "UPDATE chat_message_recipients r
          JOIN chat_messages m ON m.id=r.message_id AND m.room_id=?
          SET r.delivery_status='read', r.read_at=NOW()
          WHERE r.recipient_user_id=? AND r.message_id<=?";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("iii", $room_id, $user_id, $up_to_id);
  $stmt->execute();
  json_ok(['room_id'=>$room_id,'up_to_id'=>$up_to_id,'updated'=>$stmt->affected_rows]);
}

json_err("Unbekannte Aktion.", 404);
