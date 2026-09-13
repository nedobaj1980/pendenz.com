<?php
// api/dashboard_upload.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die(json_encode(['success'=>false, 'error'=>'Invalid request']));
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0 || empty($_FILES['image'])) {
    die(json_encode(['success'=>false, 'error'=>'Missing data']));
}

$file = $_FILES['image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg','jpeg','png','webp'];

if (!in_array($ext, $allowed)) {
    die(json_encode(['success'=>false, 'error'=>'Format not allowed']));
}

$targetDir = "../uploads/pendenzen/";
if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

$newName = "pendenz_" . $id . "_" . time() . "." . $ext;
$targetPath = $targetDir . $newName;
$dbPath = "uploads/pendenzen/" . $newName;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Set all existing images for this pendenz to is_cover=0
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id=$id");
    
    // Insert new cover image
    $st = $mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id, pfad, typ, mimetype, is_cover) VALUES (?, ?, 'image', ?, 1)");
    $mime = $file['type'];
    $st->bind_param("iss", $id, $dbPath, $mime);
    $st->execute();
    
    echo json_encode(['success'=>true, 'path'=>$dbPath]);
} else {
    echo json_encode(['success'=>false, 'error'=>'Upload failed']);
}
