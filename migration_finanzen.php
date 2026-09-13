<?php
require_once "config.php";

$mysqli->query("CREATE TABLE IF NOT EXISTS finanzen_konto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wohnung_id INT NULL,
    benutzer_id INT NULL,
    datum DATE NOT NULL,
    beleg_nr VARCHAR(50) NULL,
    text VARCHAR(255) NOT NULL,
    soll DECIMAL(12,2) DEFAULT 0,
    haben DECIMAL(12,2) DEFAULT 0,
    status ENUM('offen', 'ausgeglichen', 'storniert') DEFAULT 'offen',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (wohnung_id),
    INDEX (benutzer_id)
) ENGINE=InnoDB");

echo "Done.";
