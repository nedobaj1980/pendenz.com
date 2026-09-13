<?php
// assets/ajax/delete_team.php
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
$teamId = (int)($data['team_id'] ?? 0);
if (!$teamId) { echo json_encode(['success'=>false,'error'=>'team_id fehlt']); exit; }

try {
  // Aufräumen (falls keine ON DELETE CASCADE gesetzt ist)
  $st = $mysqli->prepare("DELETE FROM benutzer_teams WHERE team_id=?");
  $st->bind_param('i', $teamId); $st->execute(); $st->close();

  $st = $mysqli->prepare("DELETE FROM team_projekte WHERE team_id=?");
  $st->bind_param('i', $teamId); $st->execute(); $st->close();

  $st = $mysqli->prepare("DELETE FROM teams WHERE id=?");
  $st->bind_param('i', $teamId); $st->execute(); $st->close();

  echo json_encode(['success'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
