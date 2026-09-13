<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit;
}

$pdo = db();
$in  = json_decode(file_get_contents('php://input'), true);
$id  = (int)($in['id'] ?? 0);
$userId = (int)($_SESSION['user']['id'] ?? 0); // falls vorhanden

if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'missing_id']); exit; }

$stmt = $pdo->prepare("UPDATE pendenzen SET deleted_at = NOW(), deleted_by = :uid WHERE id = :id AND deleted_at IS NULL");
$stmt->execute([':id'=>$id, ':uid'=>$userId ?: null]);

echo json_encode(['ok'=>true]);
