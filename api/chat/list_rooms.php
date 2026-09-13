<?php
// api/chat/list_rooms.php
// Liefert alle Räume des Users inkl. Unread-Infos und Timestamps

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'not_logged_in'], JSON_UNESCAPED_UNICODE);
  exit;
}

$sql = "
SELECT
  r.id,
  r.name,
  r.room_type,
  r.project_id,
  r.updated_at,
  COALESCE(MAX(cm.id),0)      AS last_msg_id,
  COALESCE(cr.last_read_id,0) AS last_read_id
FROM chat_rooms r
JOIN chat_members m ON m.room_id = r.id AND m.user_id = ?
LEFT JOIN chat_messages cm ON cm.room_id = r.id
LEFT JOIN chat_reads cr ON cr.room_id = r.id AND cr.user_id = ?
GROUP BY r.id, r.name, r.room_type, r.project_id, r.updated_at, cr.last_read_id
ORDER BY r.updated_at DESC, r.id DESC
";

$st = $mysqli->prepare($sql);
$st->bind_param("ii", $uid, $uid);
$st->execute();
$res = $st->get_result();

$rooms = [];
while ($row = $res->fetch_assoc()) {
  $last_msg_id  = (int)$row['last_msg_id'];
  $last_read_id = (int)$row['last_read_id'];
  $unread_count = ($last_msg_id > $last_read_id) ? ($last_msg_id - $last_read_id) : 0;

  $rooms[] = [
    'id'           => (int)$row['id'],
    'name'         => $row['name'] ?: ($row['room_type'].' #'.$row['id']),
    'room_type'    => $row['room_type'],
    'project_id'   => isset($row['project_id']) ? (int)$row['project_id'] : null,
    'updated_at'   => $row['updated_at'],
    'last_msg_id'  => $last_msg_id,
    'last_read_id' => $last_read_id,
    'unread_count' => $unread_count,
  ];
}
$st->close();

echo json_encode(['ok'=>true, 'rooms'=>$rooms], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
