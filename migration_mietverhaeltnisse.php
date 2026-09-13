<?php
require_once "config.php";
$mysqli->query("CREATE TABLE IF NOT EXISTS mietverhaeltnisse (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    wohnung_id INT NOT NULL, 
    benutzer_id INT NOT NULL, 
    startdatum DATE NULL, 
    enddatum DATE NULL, 
    status ENUM('geplant', 'aktiv', 'beendet') DEFAULT 'geplant', 
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, 
    INDEX (wohnung_id), 
    INDEX (benutzer_id)
) ENGINE=InnoDB");
echo "Done.";
