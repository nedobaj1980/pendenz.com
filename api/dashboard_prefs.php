<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once "C:/xampp/htdocs/pendenz.com/config.php";
require_once "C:/xampp/htdocs/pendenz.com/includes/auth.php";

if (($_SESSION['rolle'] ?? '') !== 'superadmin') {
  echo json_encode(['ok'=>false,'error'=>'Nur Superadmin']); exit;
}
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) { echo json_encode(['ok'=>false,'error'=>'Kein Benutzer']); exit; }

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$key = trim($in['key'] ?? '');
$data = $in['data'] ?? null;
if ($key === '' || $data === null) { echo json_encode(['ok'=>false,'error'=>'Missing']); exit; }

$prefs_json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

/* Tabelle anlegen, falls nicht vorhanden */
$mysqli->query("
  CREATE TABLE IF NOT EXISTS user_prefs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    prefs_key VARCHAR(100) NOT NULL,
    prefs_json JSON NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_key (user_id, prefs_key)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Upsert */
$stmt = $mysqli->prepare("
  INSERT INTO user_prefs (user_id, prefs_key, prefs_json)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE prefs_json=VALUES(prefs_json), updated_at=CURRENT_TIMESTAMP
");
if (!$stmt) { echo json_encode(['ok'=>false,'error'=>'DB-Fehler (prepare)']); exit; }
$stmt->bind_param("iss", $uid, $key, $prefs_json);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok'=>$ok]);
