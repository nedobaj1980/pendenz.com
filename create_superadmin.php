<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/config.php"; // stellt $mysqli bereit

// ✅ HIER deine Daten anpassen
$name      = "Nedim Bajramovski";
$email     = "admin@pendenz.com";   // neue E-Mail
$passwort  = "Test1234.";          // dein gewünschtes Passwort
$rolle     = "superadmin";
$adresse   = "Arbonerstrasse 32k, 8590 Romanshorn";
$telefon   = "0788931065";
$geburtsdatum = "1980-04-27";
$startdatum   = "2025-09-11";
$enddatum     = "2050-09-11";

// Passwort hashen
$hash = password_hash($passwort, PASSWORD_DEFAULT);

// Falls ein alter Eintrag mit derselben E-Mail existiert → löschen
$stmt = $mysqli->prepare("DELETE FROM benutzer WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->close();

// Neuen Benutzer einfügen
$stmt = $mysqli->prepare("
    INSERT INTO benutzer
    (name, adresse, telefonnummer, email, passwort, geburtsdatum, rolle, startdatum, enddatum, erstellt_am)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->bind_param("sssssssss", $name, $adresse, $telefon, $email, $hash, $geburtsdatum, $rolle, $startdatum, $enddatum);

if ($stmt->execute()) {
    echo "✅ Superadmin erfolgreich erstellt!<br>";
    echo "➡️ Login mit: <b>$email</b><br>";
    echo "➡️ Passwort: <b>$passwort</b><br>";
} else {
    echo "❌ Fehler: " . $stmt->error;
}
$stmt->close();
$mysqli->close();
