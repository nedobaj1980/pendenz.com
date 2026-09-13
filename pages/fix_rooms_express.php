<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/room_taxonomy.php';

// Wir löschen alles und setzen es neu
$mysqli->query("TRUNCATE TABLE raum_vorlagen");

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

echo "<h1>🚀 Datenbank erfolgreich aktualisiert!</h1>";
echo "<p>Es wurden " . count($standards) . " Raum-Vorlagen neu geladen.</p>";
echo "<p><a href='wohnung_edit.php?id=" . (int)($_GET['id'] ?? 85) . "#rooms'>Zurück zur Wohnung</a></p>";
?>
