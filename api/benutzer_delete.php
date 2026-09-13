<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Nicht angemeldet']));
}

if (!is_admin()) {
    http_response_code(403);
    exit(json_encode(['error' => 'Nur Administratoren dürfen Benutzer löschen']));
}

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
$currentUid = (int)current_user_id();

if ($id <= 0) {
    http_response_code(400);
    exit(json_encode(['error' => 'Ungültige Benutzer-ID']));
}

if ($id === $currentUid) {
    http_response_code(400);
    exit(json_encode(['error' => 'Das eigene Konto kann nicht gelöscht werden']));
}

// Zielbenutzer prüfen
$stmtTarget = $mysqli->prepare("SELECT id, rolle FROM benutzer WHERE id = ?");
$stmtTarget->bind_param("i", $id);
$stmtTarget->execute();
$targetUser = $stmtTarget->get_result()->fetch_assoc();
$stmtTarget->close();

if (!$targetUser) {
    http_response_code(404);
    exit(json_encode(['error' => 'Benutzer nicht gefunden']));
}

// Superadmin-Schutz: Nur Superadmins dürfen Superadmins löschen
if (($targetUser['rolle'] ?? '') === 'superadmin' && !is_superadmin()) {
    http_response_code(403);
    exit(json_encode(['error' => 'Nur Superadmins dürfen Superadmin-Konten löschen']));
}

$stmt = $mysqli->prepare("DELETE FROM benutzer WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
echo "OK";
