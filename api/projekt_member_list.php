<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Nicht angemeldet');
}

$data = json_decode(file_get_contents('php://input'), true);
$pid  = (int)($data['projekt_id'] ?? 0);
$email= trim($data['email'] ?? '');
$rolle= $data['rolle'] ?? 'mitarbeiter';
$adder= (int)current_user_id();

if ($pid <= 0 || $email === '') { 
    http_response_code(400); 
    exit('Fehlende Daten'); 
}

// Berechtigungsprüfung: Admin/Superadmin oder Owner/Manager des Projekts
if (!is_admin()) {
    $myRole = project_role($mysqli, $pid, $adder);
    if (!in_array($myRole, ['owner', 'manager'], true)) {
        http_response_code(403);
        exit('Keine Berechtigung zur Mitgliederverwaltung in diesem Projekt');
    }
}

$validRoles = ['owner', 'manager', 'mitarbeiter', 'gast'];
if (!in_array($rolle, $validRoles, true)) {
    $rolle = 'mitarbeiter';
}

$u = $mysqli->prepare("SELECT id FROM benutzer WHERE email=? LIMIT 1");
$u->bind_param("s", $email); 
$u->execute(); 
$usr = $u->get_result()->fetch_assoc();
$u->close();

if (!$usr) { 
    http_response_code(404); 
    exit('Benutzer nicht gefunden'); 
}

$stmt = $mysqli->prepare("INSERT IGNORE INTO projekt_mitglieder (projekt_id,benutzer_id,rolle,hinzugefuegt_von) VALUES (?,?,?,?)");
$stmt->bind_param("iisi", $pid, $usr['id'], $rolle, $adder);
$stmt->execute();
$stmt->close();

log_action($mysqli, 'mitgliedschaft', $pid, 'add_member', ['user' => $usr['id'], 'role' => $rolle]);
echo "OK";
