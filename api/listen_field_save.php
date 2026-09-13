<?php
// api/listen_field_save.php - SaaS Feld-Generator
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$name = trim($data['name'] ?? '');
$type = trim($data['type'] ?? 'text');

if (!$name) {
    echo json_encode(['ok' => false, 'error' => 'Feldname darf nicht leer sein']);
    exit;
}

// Erlaubte Typen validieren
$allowedTypes = ['text', 'number', 'date', 'select', 'checkbox'];
if (!in_array($type, $allowedTypes)) {
    $type = 'text';
}

$safeName = preg_replace('/[^a-zA-Z0-9_ -]/', '', $name);
$fieldKey = strtolower(preg_replace('/\s+/', '_', $safeName));

// Prüfen ob bereits vorhanden
$st = $mysqli->prepare("SELECT id FROM pendenz_field_defs WHERE field_key = ?");
$st->bind_param("s", $fieldKey);
$st->execute();
if ($st->get_result()->fetch_row()) {
    echo json_encode(['ok' => false, 'error' => 'Dieses Feld existiert bereits.']);
    exit;
}
$st->close();

// Mandant ID (Für SaaS Isolation) - Vorerst User ID als Tenant-Ersatz
$mandantId = (int)($_SESSION['user_id'] ?? 0);

// Feld anlegen
$st = $mysqli->prepare("INSERT INTO pendenz_field_defs (mandant_id, field_key, label, type, enabled) VALUES (?, ?, ?, ?, 1)");
$st->bind_param("isss", $mandantId, $fieldKey, $name, $type);

if ($st->execute()) {
    echo json_encode(['ok' => true, 'key' => 'json:' . $fieldKey]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Datenbank-Fehler']);
}
$st->close();
