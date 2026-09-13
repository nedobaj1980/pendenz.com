<?php
require_once __DIR__ . '/config.php';

// Add columns to bkp_codes if they don't exist
$cols = $mysqli->query("SHOW COLUMNS FROM bkp_codes");
$hasTitel = false;
$hasBeschreibung = false;

while ($col = $cols->fetch_assoc()) {
    if ($col['Field'] === 'titel') $hasTitel = true;
    if ($col['Field'] === 'beschreibung') $hasBeschreibung = true;
}

if (!$hasTitel) {
    $mysqli->query("ALTER TABLE bkp_codes ADD COLUMN titel VARCHAR(255) AFTER bezeichnung");
}
if (!$hasBeschreibung) {
    $mysqli->query("ALTER TABLE bkp_codes ADD COLUMN beschreibung TEXT AFTER titel");
}

echo "Migration for bkp_codes completed.";
