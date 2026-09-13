<?php
require_once 'config.php';
// Tabelle leeren
$mysqli->query("TRUNCATE TABLE raum_vorlagen");

// Neue, vollständige Liste einfügen
$standards = [
    ['Wohnen/Essen/Küche', '🛋️🍳', 10],
    ['Wohnen/Essen', '🛋️🍽️', 20],
    ['Entrée', '🚪', 30],
    ['Küche', '🍳', 40],
    ['Essen', '🍽️', 50],
    ['Wohnen', '🛋️', 60],
    ['Elternzimmer', '🛌', 70],
    ['Zimmer', '🛏️', 80],
    ['Elternbad', '🛁', 90],
    ['Nasszelle (Bad/WC)', '🛁', 100],
    ['Gästebad / WC', '🚿', 110],
    ['Nasszelle (Dusche/WC)', '🚿', 120],
    ['Waschen / Technik', '🧺', 130],
    ['Korridor / Gang', '🚪', 140],
    ['Reduit', '📦', 150],
    ['Balkon', '🏙️', 160],
    ['Terrasse', '🌴', 170],
    ['Keller', '🍷', 180],
    ['Estrich', '🕸️', 190]
];

$stmt = $mysqli->prepare("INSERT INTO raum_vorlagen (name, icon, default_sort) VALUES (?, ?, ?)");
foreach ($standards as $s) {
    $stmt->bind_param("ssi", $s[0], $s[1], $s[2]);
    $stmt->execute();
}
echo "✅ Raumvorlagen erfolgreich auf " . count($standards) . " Einträge aktualisiert.";
