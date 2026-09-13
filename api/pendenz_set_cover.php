<?php
// api/pendenz_set_cover.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$fileId = (int)($_POST['file_id'] ?? 0);
if ($fileId <= 0) { echo json_encode(['ok'=>false,'msg'=>'file_id fehlt']); exit; }

$st=$mysqli->prepare("SELECT pendenz_id FROM pendenz_dateien WHERE id=?");
$st->bind_param("i",$fileId); $st->execute(); $st->bind_result($pid);
if(!$st->fetch()){ echo json_encode(['ok'=>false,'msg'=>'Datei nicht gefunden']); exit; }
$st->close();

// Berechtigung rudimentär: darf Pendenz sehen/bearbeiten?
$mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id=".$pid);
$mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id=".$fileId);
echo json_encode(['ok'=>true]);
