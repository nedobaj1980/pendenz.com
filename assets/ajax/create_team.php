<?php
// assets/ajax/create_team.php
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

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim((string)($data['name'] ?? ''));
$projektId = isset($data['projekt_id']) && $data['projekt_id'] !== '' ? (int)$data['projekt_id'] : null;

if ($name === '') { echo json_encode(['success'=>false,'error'=>'Teamname fehlt']); exit; }

try {
  $st = $mysqli->prepare("INSERT INTO teams (name) VALUES (?)");
  $st->bind_param('s', $name);
  $st->execute();
  $teamId = $st->insert_id;
  $st->close();

  if ($projektId) {
    $st = $mysqli->prepare("INSERT IGNORE INTO team_projekte (team_id, projekt_id) VALUES (?,?)");
    $st->bind_param('ii', $teamId, $projektId);
    $st->execute(); $st->close();
  }

  echo json_encode(['success'=>true,'team_id'=>$teamId]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
