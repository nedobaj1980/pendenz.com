<?php
require_once "config.php";

echo "<h1>Migration: BKP Kategorien & Vorlagentexte</h1>";
echo "<pre>";

$sqls = [
    "CREATE TABLE IF NOT EXISTS bkp_kategorien (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bkp_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        FOREIGN KEY (bkp_id) REFERENCES bkp_codes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "CREATE TABLE IF NOT EXISTS bkp_vorlagen_texte (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kategorie_id INT NOT NULL,
        titel VARCHAR(255) NULL,
        beschreibung TEXT NULL,
        text TEXT NOT NULL,
        FOREIGN KEY (kategorie_id) REFERENCES bkp_kategorien(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    "ALTER TABLE bkp_vorlagen_texte ADD COLUMN IF NOT EXISTS titel VARCHAR(255) NULL AFTER kategorie_id",
    "ALTER TABLE bkp_vorlagen_texte ADD COLUMN IF NOT EXISTS beschreibung TEXT NULL AFTER titel"
];

foreach ($sqls as $sql) {
    if ($mysqli->query($sql)) {
        echo "Tabelle angelegt/geprüft.\n";
    } else {
        echo "Fehler: " . $mysqli->error . "\n";
    }
}

echo "\nMigration abgeschlossen.";
echo "</pre>";
?>
