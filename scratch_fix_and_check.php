<?php
require_once 'config.php';

// 1. Fragezeichen bei Einheitstypen korrigieren
echo "Fixing unit type icons...\n";
$types = [
    'Wohnen' => '🏠',
    'Wohnung' => '🏠',
    'Maisonette' => '🏠',
    'Gewerbe' => '🏢',
    'Büroflaeche' => '🏢',
    'Lager' => '📦',
    'Lagerraum' => '📦',
    'Kellerabteil' => '📦',
    'Parken' => '🚗',
    'Tiefgaragenplatz' => '🚗',
    'Aussenplatz' => '🅿️',
    'Einzelgarage' => '🚗',
    'Allgemein' => '📍',
    'Treppenhaus' => '🪜',
    'Spielplatz' => '🛝',
    'Veloraum' => '🚲'
];

foreach ($types as $name => $icon) {
    $mysqli->query("UPDATE einheit_typen SET icon = '$icon' WHERE name = '$name' OR gruppe = '$name'");
}

// 2. Vorlagen (Listen) prüfen
echo "\nChecking custom list templates:\n";
$res = $mysqli->query("SELECT id, name, filter_json FROM pendenz_listen");
if ($res->num_rows === 0) {
    echo "WARNING: No list templates found in DB!\n";
} else {
    while($row = $res->fetch_assoc()) {
        echo "Found Template: ID $row[id] | Name: $row[name]\n";
    }
}
