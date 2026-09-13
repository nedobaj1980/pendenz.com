<?php
if (!function_exists('wf_now')) {
  function wf_now(): string { return (new DateTime())->format('Y-m-d H:i:s'); }
}

if (!function_exists('wf_actor')) {
  function wf_actor(): string {
    if (!empty($_SESSION['user_id'])) return 'user:' . (int)$_SESSION['user_id'];
    return 'role:external';
  }
}

if (!function_exists('wf_add_event')) {
  function wf_add_event(mysqli $db, int $pendenzId, string $event, array $details = []): void {
    $actor = wf_actor();
    $j = json_encode($details, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("INSERT INTO pendenz_events (pendenz_id,event,actor,details) VALUES (?,?,?,?)");
    $stmt->bind_param("isss", $pendenzId, $event, $actor, $j);
    $stmt->execute();
  }
}

if (!function_exists('wf_notify')) {
  /**
   * Schreibt eine Inbox-Zeile (später kannst du user_id konkret setzen).
   */
  function wf_notify(mysqli $db, string $type, int $pendenzId, string $message, ?int $userId = null): void {
    $refType = 'pendenz';
    $stmt = $db->prepare("INSERT INTO notifications (user_id, ref_type, ref_id, type, message) VALUES (?,?,?,?,?)");
    $stmt->bind_param("isiss", $userId, $refType, $pendenzId, $type, $message);
    $stmt->execute();
  }
}

if (!function_exists('wf_change_status')) {
  /**
   * Wechselt Workflow-Status & schreibt Events/Inbox.
   */
  function wf_change_status(mysqli $db, int $pendenzId, string $newStatus, array $opts = []): void {
    $allowed = ['offen','zugewiesen','eingereicht','in_pruefung','freigegeben','nacharbeit','abgelehnt'];
    if (!in_array($newStatus, $allowed, true)) throw new Exception("Ungültiger Status: $newStatus");

    $actor = wf_actor();
    $now   = wf_now();

    // Update pendenzen
    if ($newStatus === 'eingereicht') {
      $stmt = $db->prepare("UPDATE pendenzen SET status_workflow=?, submitted_by=?, submitted_at=? WHERE id=?");
      $stmt->bind_param("sssi", $newStatus, $actor, $now, $pendenzId);
    } elseif (in_array($newStatus, ['in_pruefung','freigegeben','nacharbeit','abgelehnt'], true)) {
      $uid  = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      $stmt = $db->prepare("UPDATE pendenzen SET status_workflow=?, reviewed_by=?, reviewed_at=? WHERE id=?");
      $stmt->bind_param("sisi", $newStatus, $uid, $now, $pendenzId);
    } else {
      $stmt = $db->prepare("UPDATE pendenzen SET status_workflow=? WHERE id=?");
      $stmt->bind_param("si", $newStatus, $pendenzId);
    }
    $stmt->execute();

    // Event
    wf_add_event($db, $pendenzId, 'status_changed', [
      'to'    => $newStatus,
      'note'  => $opts['note'] ?? null
    ]);

    // Inbox-Nachricht
    $msg = 'Pendenz #' . $pendenzId . ' → Status: ' . $newStatus;
    wf_notify($db, 'status_changed', $pendenzId, $msg, $opts['notify_user_id'] ?? null);
  }
}
