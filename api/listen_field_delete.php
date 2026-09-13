<?php
// api/listen_field_delete.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$key = trim($_POST['key'] ?? '');

if (!$key) {
    echo json_encode(['ok' => false, 'error' => 'Kein Feld angegeben']);
    exit;
}

// Remove the json: prefix if passed
$fieldKey = str_replace('json:', '', $key);

$st = $mysqli->prepare("DELETE FROM pendenz_field_defs WHERE field_key = ?");
$st->bind_param("s", $fieldKey);

if ($st->execute()) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Datenbank-Fehler beim Löschen']);
}
$st->close();
