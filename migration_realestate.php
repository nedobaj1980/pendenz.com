<?php
require_once "config.php";

$queries = [
    // 1) Upgrade wohnungen table to match the code
    "ALTER TABLE wohnungen 
        CHANGE COLUMN bezeichnung name VARCHAR(255) NOT NULL,
        ADD COLUMN etage VARCHAR(50) DEFAULT NULL AFTER name,
        ADD COLUMN zimmer DECIMAL(3,1) DEFAULT NULL AFTER etage,
        ADD COLUMN badezimmer DECIMAL(3,1) DEFAULT NULL AFTER zimmer,
        ADD COLUMN balkon TINYINT(1) DEFAULT 0 AFTER badezimmer,
        ADD COLUMN wintergarten TINYINT(1) DEFAULT 0 AFTER balkon,
        ADD COLUMN letzter_renovation DATE DEFAULT NULL AFTER wintergarten,
        ADD COLUMN gesamtzustand VARCHAR(100) DEFAULT NULL AFTER letzter_renovation,
        ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    
    // 2) Related tables for wohnungen (images/docs)
    "CREATE TABLE IF NOT EXISTS wohnung_bilder (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wohnung_id INT NOT NULL,
        pfad VARCHAR(500) NOT NULL,
        is_cover TINYINT(1) DEFAULT 0,
        FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    "CREATE TABLE IF NOT EXISTS wohnung_dokumente (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wohnung_id INT NOT NULL,
        name VARCHAR(255) DEFAULT NULL,
        pfad VARCHAR(500) NOT NULL,
        typ VARCHAR(50) DEFAULT 'pdf',
        FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 3) Link pendenzen to wohnungen
    "ALTER TABLE pendenzen ADD COLUMN wohnung_id INT DEFAULT NULL AFTER projekt_id",
    "ALTER TABLE pendenzen ADD CONSTRAINT fk_pendenz_wohnung FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id) ON DELETE SET NULL",

    // 4) Prospective Tenants (Interessenten)
    "CREATE TABLE IF NOT EXISTS interessenten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wohnung_id INT DEFAULT NULL,
        vorname VARCHAR(100) NOT NULL,
        nachname VARCHAR(100) NOT NULL,
        email VARCHAR(255) NOT NULL,
        telefon VARCHAR(50) DEFAULT NULL,
        geburtsdatum DATE DEFAULT NULL,
        gehalt_monat DECIMAL(12,2) DEFAULT NULL,
        beruf VARCHAR(255) DEFAULT NULL,
        arbeitgeber VARCHAR(255) DEFAULT NULL,
        anzahl_personen INT DEFAULT 1,
        haustiere TEXT DEFAULT NULL,
        instrumente TEXT DEFAULT NULL,
        bemerkungen TEXT DEFAULT NULL,
        status ENUM('neu','eingeladen','abgelehnt','angenommen') DEFAULT 'neu',
        erstellt_am TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 5) Protocols (linked to tasks)
    "ALTER TABLE pendenzen ADD COLUMN is_protocol TINYINT(1) DEFAULT 0",
    "ALTER TABLE pendenzen ADD COLUMN protocol_type ENUM('none','abnahme','uebergabe','besichtigung') DEFAULT 'none'"
];

echo "<pre>";
foreach ($queries as $q) {
    echo "Running: " . substr($q, 0, 80) . "...\n";
    if ($mysqli->query($q)) {
        echo "✅ OK\n";
    } else {
        echo "❌ Error: " . $mysqli->error . "\n";
    }
}
echo "</pre>";
