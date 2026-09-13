<?php
// Einfaches Audit-Log

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * log_action($db, 'pendenz', 123, 'update', ['status'=>'erledigt'])
 */
function log_action(mysqli $db, string $entity, int $entity_id, string $action, array $changes = []): void {
  $actor = $_SESSION['user_id'] ?? null;
  $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
  $json  = $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;

  // Validierung: Existiert der User in der benutzer-Tabelle?
  $validActor = null;
  if ($actor !== null) {
      $chk = $db->query("SELECT id FROM benutzer WHERE id = " . (int)$actor);
      if ($chk && $chk->num_rows > 0) $validActor = (int)$actor;
  }

  $stmt = $db->prepare("INSERT INTO audit_log (actor_id, entity, entity_id, action, changes, ip) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param("isisss", $validActor, $entity, $entity_id, $action, $json, $ip);
  $stmt->execute();
}
