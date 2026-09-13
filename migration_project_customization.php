<?php
require_once "config.php";

$queries = [
    "ALTER TABLE projekte ADD COLUMN pendenzen_profile_id INT DEFAULT NULL AFTER status",
    "ALTER TABLE pendenz_kategorien ADD COLUMN projekt_id INT DEFAULT NULL AFTER name",
    "ALTER TABLE pendenz_subkategorien ADD COLUMN projekt_id INT DEFAULT NULL AFTER name",
    "ALTER TABLE pendenz_kategorien ADD INDEX (projekt_id)",
    "ALTER TABLE pendenz_subkategorien ADD INDEX (projekt_id)"
];

foreach ($queries as $q) {
    if ($mysqli->query($q)) {
        echo "✅ OK: " . substr($q, 0, 50) . "...\n";
    } else {
        echo "❌ Error: " . $mysqli->error . "\n";
    }
}
echo "Done.";
