<?php
require_once __DIR__ . '/config.php';

// Add columns to bkp_vorlagen_texte if they don't exist
$cols = $mysqli->query("SHOW COLUMNS FROM bkp_vorlagen_texte");
$hasTitel = false;
$hasBeschreibung = false;

while ($col = $cols->fetch_assoc()) {
    if ($col['Field'] === 'titel') $hasTitel = true;
    if ($col['Field'] === 'beschreibung') $hasBeschreibung = true;
}

if (!$hasTitel) {
    $mysqli->query("ALTER TABLE bkp_vorlagen_texte ADD COLUMN titel VARCHAR(255) AFTER kategorie_id");
}
if (!$hasBeschreibung) {
    $mysqli->query("ALTER TABLE bkp_vorlagen_texte ADD COLUMN beschreibung TEXT AFTER titel");
}

// Data migration: move 'text' to 'titel' if it's short, or to 'beschreibung' if it's long?
// Actually, let's just keep 'text' for now or migrate it.
// The user says "Muss auch titel und beschreibung haben".

echo "Migration for bkp_vorlagen_texte completed.";
