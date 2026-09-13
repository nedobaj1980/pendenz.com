<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

$queries = [
    "ALTER TABLE pendenzen ADD COLUMN uhrzeit TIME NULL DEFAULT NULL AFTER enddatum",
    "ALTER TABLE pendenzen ADD COLUMN tageszeit VARCHAR(50) NULL DEFAULT NULL AFTER uhrzeit"
];

foreach ($queries as $q) {
    echo "Running: $q\n";
    if ($mysqli->query($q)) {
        echo "✅ SUCCESS\n";
    } else {
        echo "❌ ERROR: " . $mysqli->error . "\n";
    }
}
