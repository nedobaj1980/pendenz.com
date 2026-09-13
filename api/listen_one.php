<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_id']); exit; }

$stmt = $mysqli->prepare("SELECT * FROM listen WHERE id=?");
$stmt->bind_param('i',$id);
$stmt->execute();
$list = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$list) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

$stmt = $mysqli->prepare("SELECT col_name, sort_order, width_desktop, width_ipad, width_mobile, visible_desktop, visible_ipad, visible_mobile FROM listen_spalten WHERE listen_id=? ORDER BY sort_order ASC, id ASC");
$stmt->bind_param('i',$id);
$stmt->execute();
$cols = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['ok'=>true,'list'=>$list,'columns'=>$cols]);
