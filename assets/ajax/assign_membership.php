<?php
// assets/ajax/assign_membership.php
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

/**
 * Payload-Varianten:
 *  - { action:'add'|'remove', user_id, projekt_id? }
 *  - { action:'add'|'remove', user_id, team_id? }
 *  - { tp_action:'add'|'remove', team_id, projekt_id }  // Team<->Projekt
 */

try {
  // Team <-> Projekt
  if (isset($data['tp_action'])) {
    $tpAction = $data['tp_action'];
    $teamId   = (int)($data['team_id']   ?? 0);
    $projId   = (int)($data['projekt_id']?? 0);
    if (!$teamId || !$projId) throw new Exception('Ungültige IDs');

    if ($tpAction === 'add') {
      $st = $mysqli->prepare("INSERT IGNORE INTO team_projekte (team_id, projekt_id) VALUES (?,?)");
      $st->bind_param('ii', $teamId, $projId);
      $st->execute(); $st->close();
    } elseif ($tpAction === 'remove') {
      $st = $mysqli->prepare("DELETE FROM team_projekte WHERE team_id=? AND projekt_id=?");
      $st->bind_param('ii', $teamId, $projId);
      $st->execute(); $st->close();
    } else {
      throw new Exception('Unbekannte tp_action');
    }
    echo json_encode(['success'=>true]); exit;
  }

  // Benutzer <-> Projekt/Team
  $action = $data['action'] ?? null;
  $userId = (int)($data['user_id'] ?? 0);
  if (!$action || !$userId) throw new Exception('Ungültiger Payload');

  if (!empty($data['projekt_id'])) {
    $projId = (int)$data['projekt_id'];
    if ($action === 'add') {
      $st = $mysqli->prepare("INSERT IGNORE INTO benutzer_projekte (benutzer_id, projekt_id) VALUES (?,?)");
      $st->bind_param('ii', $userId, $projId);
      $st->execute(); $st->close();
    } else {
      $st = $mysqli->prepare("DELETE FROM benutzer_projekte WHERE benutzer_id=? AND projekt_id=?");
      $st->bind_param('ii', $userId, $projId);
      $st->execute(); $st->close();
    }
  } elseif (!empty($data['team_id'])) {
    $teamId = (int)$data['team_id'];
    if ($action === 'add') {
      $st = $mysqli->prepare("INSERT IGNORE INTO benutzer_teams (benutzer_id, team_id) VALUES (?,?)");
      $st->bind_param('ii', $userId, $teamId);
      $st->execute(); $st->close();
    } else {
      $st = $mysqli->prepare("DELETE FROM benutzer_teams WHERE benutzer_id=? AND team_id=?");
      $st->bind_param('ii', $userId, $teamId);
      $st->execute(); $st->close();
    }
  } else {
    throw new Exception('Weder projekt_id noch team_id übergeben');
  }

  echo json_encode(['success'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
