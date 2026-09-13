<?php
// api/chat/list_team_rooms.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

$sql = "SELECT r.id, r.name
        FROM chat_rooms r
        JOIN chat_members m ON m.room_id=r.id AND m.user_id=?
        WHERE r.room_type='team'
        ORDER BY r.name ASC";
$st = $mysqli->prepare($sql);
$st->bind_param("i", $uid);
$st->execute();
$rs = $st->get_result();
$out = [];
while ($row = $rs->fetch_assoc()) {
  $out[] = ['id'=>(int)$row['id'], 'name'=>$row['name'] ?: ('Team #'.$row['id'])];
}
$st->close();

echo json_encode(['ok'=>true,'results'=>$out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
