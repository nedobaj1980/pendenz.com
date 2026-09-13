<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Nicht angemeldet');
}

$pm_id = (int)($_POST['pm_id'] ?? ($_GET['pm_id'] ?? 0));
$callerId = (int)current_user_id();

if ($pm_id <= 0) { 
    http_response_code(400); 
    exit('Ungültig'); 
}

// Projekt-ID der Mitgliedschaft ermitteln
$st = $mysqli->prepare("SELECT projekt_id, benutzer_id FROM projekt_mitglieder WHERE id = ?");
$st->bind_param("i", $pm_id);
$st->execute();
$pm = $st->get_result()->fetch_assoc();
$st->close();

if (!$pm) {
    http_response_code(404);
    exit('Mitgliedschaft nicht gefunden');
}

$pid = (int)$pm['projekt_id'];

// Berechtigung: Admin oder Owner/Manager des Projekts (oder eigenes Verlassen des Projekts)
if (!is_admin() && (int)$pm['benutzer_id'] !== $callerId) {
    $myRole = project_role($mysqli, $pid, $callerId);
    if (!in_array($myRole, ['owner', 'manager'], true)) {
        http_response_code(403);
        exit('Keine Berechtigung zur Mitgliederverwaltung in diesem Projekt');
    }
}

$stmt = $mysqli->prepare("DELETE FROM projekt_mitglieder WHERE id = ?");
$stmt->bind_param("i", $pm_id);
$stmt->execute();
$stmt->close();

log_action($mysqli, 'mitgliedschaft', $pm_id, 'remove_member');
echo "OK";
