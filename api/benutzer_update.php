<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit(json_encode(['error' => 'Nicht angemeldet']));
}

$data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$id = (int)($data['id'] ?? 0);
$field = trim($data['field'] ?? '');
$value = $data['value'] ?? '';

$currentUid = (int)current_user_id();
$callerIsSuperadmin = is_superadmin();
$callerIsAdmin = is_admin();

if ($id <= 0) {
    http_response_code(400);
    exit(json_encode(['error' => 'Ungültige Benutzer-ID']));
}

// Benutzer darf nur sich selbst ändern, sofern er kein Admin ist
if (!$callerIsAdmin && $currentUid !== $id) {
    http_response_code(403);
    exit(json_encode(['error' => 'Keine Berechtigung für fremde Benutzer']));
}

// Rollenänderungen dürfen NIEMALS von normalen Benutzern durchgeführt werden
if ($field === 'rolle') {
    if (!$callerIsAdmin) {
        http_response_code(403);
        exit(json_encode(['error' => 'Keine Berechtigung zur Rollenänderung']));
    }

    $validRoles = ['superadmin', 'admin', 'benutzer', 'gast'];
    if (!in_array($value, $validRoles, true)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Ungültige Rolle']));
    }

    // Nur Superadmins dürfen die Rolle 'superadmin' vergeben
    if ($value === 'superadmin' && !$callerIsSuperadmin) {
        http_response_code(403);
        exit(json_encode(['error' => 'Nur Superadmins dürfen die Superadmin-Rolle vergeben']));
    }

    // Prüfen, welche Rolle der Ziel-Benutzer aktuell hat
    $stmtTarget = $mysqli->prepare("SELECT rolle FROM benutzer WHERE id = ?");
    $stmtTarget->bind_param("i", $id);
    $stmtTarget->execute();
    $targetUser = $stmtTarget->get_result()->fetch_assoc();
    $stmtTarget->close();

    if ($targetUser && ($targetUser['rolle'] ?? '') === 'superadmin' && !$callerIsSuperadmin) {
        http_response_code(403);
        exit(json_encode(['error' => 'Nur Superadmins dürfen Superadmin-Konten verändern']));
    }
}

$allowed = ['name', 'adresse', 'telefonnummer', 'rolle', 'startdatum', 'enddatum'];
// Normale Benutzer dürfen nur Kontaktdaten anpassen
if (!$callerIsAdmin) {
    $allowed = ['name', 'adresse', 'telefonnummer'];
}

if (in_array($field, $allowed, true)) {
    $stmt = $mysqli->prepare("UPDATE benutzer SET $field = ? WHERE id = ?");
    $stmt->bind_param("si", $value, $id);
    $stmt->execute();
    $stmt->close();
    echo "OK";
} else {
    http_response_code(400);
    echo "Ungültiges Feld";
}
