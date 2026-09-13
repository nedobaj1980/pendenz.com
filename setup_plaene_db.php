<?php
require_once __DIR__ . '/config.php';

echo "Starte Datenbank-Update für Plan-Management...\n";

global $mysqli;
if (!isset($mysqli)) {
    die("Datenbankverbindung ($mysqli) nicht verfügbar.");
}

// 1. Tabelle für Pläne erstellen
$sql1 = "CREATE TABLE IF NOT EXISTS projekt_plaene (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projekt_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    datei_pfad VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($mysqli->query($sql1)) {
    echo "- Tabelle 'projekt_plaene' OK.\n";
} else {
    echo "- Fehler bei 'projekt_plaene': " . $mysqli->error . "\n";
}

// 2. Tabelle für Zonen (Wohnungen auf dem Plan) erstellen
$sql2 = "CREATE TABLE IF NOT EXISTS plan_zonen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    wohnung_id INT NOT NULL,
    x_pct FLOAT NOT NULL,
    y_pct FLOAT NOT NULL,
    width_pct FLOAT NOT NULL,
    height_pct FLOAT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

if ($mysqli->query($sql2)) {
    echo "- Tabelle 'plan_zonen' OK.\n";
} else {
    echo "- Fehler bei 'plan_zonen': " . $mysqli->error . "\n";
}

// 3. Tabelle Pendenzen erweitern (falls Spalten nicht existieren)
$cols = [
    'plan_id' => 'INT NULL DEFAULT NULL',
    'pin_x' => 'FLOAT NULL DEFAULT NULL',
    'pin_y' => 'FLOAT NULL DEFAULT NULL'
];

foreach ($cols as $col => $def) {
    $check = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE '$col'");
    if ($check->num_rows == 0) {
        if ($mysqli->query("ALTER TABLE pendenzen ADD $col $def")) {
            echo "- Spalte '$col' zu 'pendenzen' hinzugefügt.\n";
        } else {
            echo "- Fehler bei Spalte '$col': " . $mysqli->error . "\n";
        }
    } else {
        echo "- Spalte '$col' existiert bereits.\n";
    }
}

echo "Datenbank-Update abgeschlossen.\n";
