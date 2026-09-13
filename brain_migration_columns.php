<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/config.php';

$cols = [
    'vorgangsart_id' => 'INT NULL',
    'objekt_id' => 'INT NULL',
    'wohnung_id' => 'INT NULL',
    'zustaendig_id' => 'INT NULL',
    'bkp_id' => 'INT NULL',
    'mieter_kategorie_id' => 'INT NULL',
    'mieter_subkategorie_id' => 'INT NULL',
    'vermieter_kategorie_id' => 'INT NULL',
    'vermieter_subkategorie_id' => 'INT NULL',
    'extra_json' => 'TEXT NULL',
    'startdatum' => 'DATE NULL',
    'enddatum' => 'DATE NULL',
    'uhrzeit' => 'TIME NULL',
    'dauer' => 'VARCHAR(50) NULL',
    'confirmation_required' => 'TINYINT(1) DEFAULT 0',
    'external_can_view' => 'TINYINT(1) DEFAULT 1',
    'external_can_upload' => 'TINYINT(1) DEFAULT 0',
    'public_enabled' => 'TINYINT(1) DEFAULT 0'
];

foreach ($cols as $col => $type) {
    $res = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE '$col'");
    if (!$res) {
        echo "Error checking $col: " . $mysqli->error . "\n";
        continue;
    }
    if ($res->num_rows === 0) {
        echo "Adding $col...\n";
        if (!$mysqli->query("ALTER TABLE pendenzen ADD COLUMN $col $type")) {
            echo "Error adding $col: " . $mysqli->error . "\n";
        }
    }
}
echo "Done.";
