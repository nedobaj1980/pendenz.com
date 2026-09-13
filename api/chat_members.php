<?php
// /api/chat_members.php
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

if ($method==='GET' && $action==='list') {
  $room_id = (int)($_GET['room_id'] ?? 0);
  if ($room_id<=0) json_err("room_id fehlt.");
  // Nur Mitglieder (oder Admin/Superadmin global) dürfen sehen
  $role = $_SESSION['rolle'] ?? 'gast';
  if (!in_array($role, ['superadmin','admin'], true)) {
    $chk = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
    $chk->bind_param("ii", $room_id, $user_id);
    $chk->execute();
    if (!$chk->get_result()->fetch_column()) json_err("Kein Zugriff.", 403);
  }
  $sql = "SELECT m.*, b.name, b.email
          FROM chat_members m
          JOIN benutzer b ON b.id=m.user_id
          WHERE m.room_id=? ORDER BY b.name ASC";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("i", $room_id);
  $stmt->execute();
  $rows=[]; $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $rows[]=$r;
  json_ok($rows);
}

if ($method==='POST' && $action==='add') {
  $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $room_id = (int)($payload['room_id'] ?? 0);
  $add_user_id = (int)($payload['user_id'] ?? 0);
  if ($room_id<=0 || $add_user_id<=0) json_err("room_id und user_id erforderlich.");
  // nur Raum-Admin oder superadmin
  $role = $_SESSION['rolle'] ?? 'gast';
  $stmt = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=? AND role='admin'");
  $stmt->bind_param("ii", $room_id, $user_id);
  $stmt->execute();
  $is_admin = (bool)$stmt->get_result()->fetch_column();
  if (!$is_admin && $role!=='superadmin') json_err("Keine Berechtigung.", 403);

  $ins = $mysqli->prepare("INSERT INTO chat_members(room_id, user_id, role) VALUES(?,?,'member') ON DUPLICATE KEY UPDATE role=VALUES(role)");
  $ins->bind_param("ii", $room_id, $add_user_id);
  $ins->execute();
  json_ok(['room_id'=>$room_id,'user_id'=>$add_user_id]);
}

if ($method==='POST' && $action==='remove') {
  $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
  $room_id = (int)($payload['room_id'] ?? 0);
  $rem_user_id = (int)($payload['user_id'] ?? 0);
  if ($room_id<=0 || $rem_user_id<=0) json_err("room_id und user_id erforderlich.");
  $role = $_SESSION['rolle'] ?? 'gast';
  $stmt = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=? AND role='admin'");
  $stmt->bind_param("ii", $room_id, $user_id);
  $stmt->execute();
  $is_admin = (bool)$stmt->get_result()->fetch_column();
  if (!$is_admin && $role!=='superadmin' && $rem_user_id!==$user_id) json_err("Keine Berechtigung.", 403);

  $del = $mysqli->prepare("DELETE FROM chat_members WHERE room_id=? AND user_id=?");
  $del->bind_param("ii", $room_id, $rem_user_id);
  $del->execute();
  json_ok(['room_id'=>$room_id,'user_id'=>$rem_user_id,'removed'=>true]);
}

json_err("Unbekannte Aktion.", 404);
