<?php
// assets/ajax/firmen_overrides.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_login();

$role = $_SESSION['rolle'] ?? 'gast';
if (!in_array($role, ['admin','superadmin'], true)) {
  http_response_code(403);
  echo json_encode(['success'=>false,'error'=>'Keine Berechtigung']); exit;
}

csrf_require_for_state_changing_requests();

// Tabelle sicherstellen (falls nicht schon vorhanden)
$mysqli->query("CREATE TABLE IF NOT EXISTS firmen_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  firma_id INT NOT NULL,
  benutzer_id INT NOT NULL,
  mode ENUM('include','exclude') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_firma_user (firma_id, benutzer_id),
  CONSTRAINT fk_fo_firma FOREIGN KEY (firma_id) REFERENCES firmen(id) ON DELETE CASCADE,
  CONSTRAINT fk_fo_user  FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $data['action'] ?? '';
$firmaId = (int)($data['firma_id'] ?? 0);
$userId  = (int)($data['user_id']  ?? 0);

if (!$action || !$firmaId || !$userId) { echo json_encode(['success'=>false,'error'=>'Ungültiger Payload']); exit; }

try {
  if ($action === 'include_add') {
    // Upsert auf 'include'
    $st = $mysqli->prepare("INSERT INTO firmen_overrides (firma_id, benutzer_id, mode)
                            VALUES (?, ?, 'include')
                            ON DUPLICATE KEY UPDATE mode='include'");
    $st->bind_param('ii', $firmaId, $userId); $st->execute(); $st->close();
  } elseif ($action === 'include_remove') {
    $st = $mysqli->prepare("DELETE FROM firmen_overrides WHERE firma_id=? AND benutzer_id=? AND mode='include'");
    $st->bind_param('ii', $firmaId, $userId); $st->execute(); $st->close();
  } elseif ($action === 'exclude_add') {
    $st = $mysqli->prepare("INSERT INTO firmen_overrides (firma_id, benutzer_id, mode)
                            VALUES (?, ?, 'exclude')
                            ON DUPLICATE KEY UPDATE mode='exclude'");
    $st->bind_param('ii', $firmaId, $userId); $st->execute(); $st->close();
  } elseif ($action === 'exclude_remove') {
    $st = $mysqli->prepare("DELETE FROM firmen_overrides WHERE firma_id=? AND benutzer_id=? AND mode='exclude'");
    $st->bind_param('ii', $firmaId, $userId); $st->execute(); $st->close();
  } else {
    throw new Exception('Unbekannte Action');
  }

  echo json_encode(['success'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
