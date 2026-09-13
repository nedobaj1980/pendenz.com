<?php
// brain_migration_protokoll.php
require_once __DIR__ . '/config.php';

$sql = [
    "CREATE TABLE IF NOT EXISTS protokoll_typen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        icon VARCHAR(50) DEFAULT '📝'
    )",
    "CREATE TABLE IF NOT EXISTS protokoll_vorlagen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        typ_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        json_data TEXT,
        FOREIGN KEY (typ_id) REFERENCES protokoll_typen(id) ON DELETE CASCADE
    )"
];

foreach ($sql as $s) {
    if (!$mysqli->query($s)) {
        echo "Error: " . $mysqli->error . "\n";
    }
}

// Initial Data if empty
$res = $mysqli->query("SELECT COUNT(*) FROM protokoll_typen");
if ($res->fetch_row()[0] == 0) {
    $mysqli->query("INSERT INTO protokoll_typen (name, icon) VALUES ('Baustellenprotokoll', '🏗️'), ('Abnahmeprotokoll', '🔑'), ('Sitzungsprotokoll', '👥')");
}

echo "Migration done.\n";
