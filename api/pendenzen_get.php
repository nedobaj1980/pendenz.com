<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'missing_id']); exit; }

$stmt = $pdo->prepare("SELECT p.*, b.name AS zustaendig_name, pr.name AS projekt_name
                       FROM pendenzen p
                       LEFT JOIN benutzer b ON b.id = p.zustaendig_id
                       LEFT JOIN projekte pr ON pr.id = p.projekt_id
                       WHERE p.id = :id AND p.deleted_at IS NULL");
$stmt->execute([':id'=>$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

echo json_encode(['ok'=>true,'row'=>$row]);
