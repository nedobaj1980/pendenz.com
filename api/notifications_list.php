<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) { echo json_encode(['unseen'=>0,'items'=>[]]); exit; }

$items = [];
$unseen = 0;

$stmt = $mysqli->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND seen_at IS NULL");
$stmt->bind_param('i', $userId);
$stmt->execute(); $stmt->bind_result($unseen); $stmt->fetch(); $stmt->close();

$stmt = $mysqli->prepare("SELECT id, message, link_url, DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') AS created_at
                          FROM user_notifications
                          WHERE user_id=?
                          ORDER BY COALESCE(seen_at, created_at) DESC, id DESC
                          LIMIT 15");
$stmt->bind_param('i', $userId);
$stmt->execute(); $res=$stmt->get_result();
while($row=$res->fetch_assoc()) $items[]=$row;
$stmt->close();

echo json_encode(['unseen'=>$unseen, 'items'=>$items], JSON_UNESCAPED_UNICODE);
