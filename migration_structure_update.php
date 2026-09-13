<?php
require_once "config.php";

echo "<h1>Migration: Struktur-Update</h1>";
echo "<pre>";

function run_query($mysqli, $sql) {
    echo "Running: " . substr($sql, 0, 100) . "... ";
    if ($mysqli->query($sql)) {
        echo "✅ OK\n";
    } else {
        echo "❌ Error: " . $mysqli->error . "\n";
    }
}

function column_exists($mysqli, $table, $column) {
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res->num_rows > 0;
}

// 1. Pendenzen-Arten (Mangel, Punkt, Wunsch, etc.)
run_query($mysqli, "CREATE TABLE IF NOT EXISTS pendenzen_arten (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    farbe VARCHAR(20) DEFAULT '#3b82f6',
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Standard-Arten einfügen
$mysqli->query("INSERT IGNORE INTO pendenzen_arten (id, name, farbe, sort_order) VALUES 
(1, 'Mangel', '#ef4444', 1),
(2, 'Offener Punkt', '#f59e0b', 2),
(3, 'Wunsch', '#10b981', 3),
(4, 'Abnahme', '#6366f1', 4),
(5, 'Garantie', '#8b5cf6', 5)");

// 2. BKP-Codes & Arbeitsgattungen
run_query($mysqli, "CREATE TABLE IF NOT EXISTS bkp_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    bezeichnung VARCHAR(255) NOT NULL,
    parent_id INT DEFAULT NULL,
    FOREIGN KEY (parent_id) REFERENCES bkp_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Einige Beispiel-BKP einfügen
$mysqli->query("INSERT IGNORE INTO bkp_codes (code, bezeichnung) VALUES 
('271', 'Gipserarbeiten'),
('272', 'Metallbauarbeiten'),
('273', 'Schreinerarbeiten'),
('281', 'Bodenbeläge'),
('285', 'Malerarbeiten')");

// 3. Räume (Wohnungsspezifisch)
run_query($mysqli, "CREATE TABLE IF NOT EXISTS raeume (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (wohnung_id) REFERENCES wohnungen(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// 4. Unternehmer-Projekt-Zuweisung
run_query($mysqli, "CREATE TABLE IF NOT EXISTS unternehmer_projekte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    benutzer_id INT NOT NULL,
    projekt_id INT NOT NULL,
    bkp_id INT DEFAULT NULL,
    FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE,
    FOREIGN KEY (projekt_id) REFERENCES projekte(id) ON DELETE CASCADE,
    FOREIGN KEY (bkp_id) REFERENCES bkp_codes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// 5. Pendenzen Tabelle erweitern
if (!column_exists($mysqli, 'pendenzen', 'art_id')) {
    run_query($mysqli, "ALTER TABLE pendenzen ADD COLUMN art_id INT DEFAULT NULL AFTER status");
    run_query($mysqli, "ALTER TABLE pendenzen ADD CONSTRAINT fk_pendenz_art FOREIGN KEY (art_id) REFERENCES pendenzen_arten(id) ON DELETE SET NULL");
}
if (!column_exists($mysqli, 'pendenzen', 'raum_id')) {
    run_query($mysqli, "ALTER TABLE pendenzen ADD COLUMN raum_id INT DEFAULT NULL AFTER art_id");
    run_query($mysqli, "ALTER TABLE pendenzen ADD CONSTRAINT fk_pendenz_raum FOREIGN KEY (raum_id) REFERENCES raeume(id) ON DELETE SET NULL");
}
if (!column_exists($mysqli, 'pendenzen', 'bkp_id')) {
    run_query($mysqli, "ALTER TABLE pendenzen ADD COLUMN bkp_id INT DEFAULT NULL AFTER raum_id");
    run_query($mysqli, "ALTER TABLE pendenzen ADD CONSTRAINT fk_pendenz_bkp FOREIGN KEY (bkp_id) REFERENCES bkp_codes(id) ON DELETE SET NULL");
}

// 6. Benutzer Tabelle erweitern (Berechtigungen)
if (!column_exists($mysqli, 'benutzer', 'permissions_json')) {
    run_query($mysqli, "ALTER TABLE benutzer ADD COLUMN permissions_json TEXT DEFAULT NULL AFTER rolle");
}

echo "\nMigration abgeschlossen.";
echo "</pre>";
?>
