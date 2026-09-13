<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_POST['id'] ?? 0);
if ($id<=0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_id']); exit; }

$stmt = $mysqli->prepare("DELETE FROM listen WHERE id=?");
$stmt->bind_param('i',$id);
$stmt->execute();
$stmt->close();

echo json_encode(['ok'=>true]);
