<?php
// C:\xampp\htdocs\pendenz.com\api\chat\rooms.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'not_logged_in']); exit; }

$sql = "SELECT r.id,r.name,r.room_type,r.project_id,r.updated_at,
        COALESCE(cr.last_read_id,0) AS last_read_id,
        (SELECT MAX(id) FROM chat_messages cm WHERE cm.room_id=r.id) AS last_msg_id,
        (SELECT JSON_OBJECT('id',cm2.id,'user_id',cm2.user_id,'body',SUBSTRING(cm2.body,1,140),'created_at',cm2.created_at)
           FROM chat_messages cm2 WHERE cm2.room_id=r.id ORDER BY cm2.id DESC LIMIT 1) AS last_message
        FROM chat_rooms r
        JOIN chat_members m ON m.room_id=r.id AND m.user_id=?
        LEFT JOIN chat_reads cr ON cr.room_id=r.id AND cr.user_id=?
        ORDER BY r.updated_at DESC, r.id DESC";
$st = $mysqli->prepare($sql);
$st->bind_param("ii", $uid, $uid);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

foreach ($rows as &$r) {
  $max = (int)($r['last_msg_id'] ?? 0);
  $read= (int)($r['last_read_id'] ?? 0);
  $r['unread'] = max(0, $max - $read);
}
echo json_encode(['ok'=>true,'rooms'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
