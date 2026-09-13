<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

$actorRole = $_SESSION['rolle'] ?? 'gast';
$data = json_decode(file_get_contents("php://input"), true);

$name = trim($data['name'] ?? '');
$adresse = trim($data['adresse'] ?? '');
$telefon = trim($data['telefonnummer'] ?? '');
$email = trim($data['email'] ?? '');
$rolle = $data['rolle'] ?? 'gast';
$startdatum = $data['startdatum'] ?: null;
$enddatum = $data['enddatum'] ?: null;

if ($name === '' || $email === '') {
    http_response_code(400);
    exit("Name und Email sind erforderlich.");
}

// erlaubte Rollen prüfen
$allowed = [];
switch ($actorRole) {
  case 'superadmin': $allowed = ['superadmin','admin','benutzer','gast']; break;
  case 'admin':      $allowed = ['benutzer','gast']; break;
  case 'benutzer':   $allowed = ['gast']; break;
  default:           $allowed = []; break;
}

if (!in_array($rolle, $allowed, true)) {
    http_response_code(403);
    exit("❌ Diese Rolle darfst du nicht anlegen.");
}

// Default-Passwort (kann später geändert werden)
$passwort = password_hash("start123", PASSWORD_BCRYPT);

$stmt = $mysqli->prepare("INSERT INTO benutzer 
  (name, adresse, telefonnummer, email, passwort, rolle, startdatum, enddatum) 
  VALUES (?,?,?,?,?,?,?,?)");
$stmt->bind_param("ssssssss", $name, $adresse, $telefon, $email, $passwort, $rolle, $startdatum, $enddatum);

if ($stmt->execute()) {
    echo "✅ Benutzer erfolgreich angelegt (Standard-Passwort: start123)";
} else {
    http_response_code(500);
    echo "Fehler beim Anlegen: " . $mysqli->error;
}
