<?php
require_once 'config.php';
$ids = [48, 49, 50, 51];
foreach($ids as $id) {
    echo "Checking ID $id:\n";
    $w = $mysqli->query("SELECT COUNT(*) as count FROM wohnungen WHERE objekt_id = $id")->fetch_assoc();
    $p = $mysqli->query("SELECT COUNT(*) as count FROM pendenzen WHERE objekt_id = $id")->fetch_assoc();
    echo "  Apartments: " . $w['count'] . "\n";
    echo "  Pendenzen: " . $p['count'] . "\n\n";
}
