<?php
require_once 'config.php';
require_once 'includes/functions.php';

echo "--- DATENBANK REPARATUR ---\n";

// 1. Spalten sicherstellen
$cols = [
    'kueche_details' => "TEXT NULL",
    'bad_details' => "TEXT NULL"
];
foreach ($cols as $c => $def) {
    if ($mysqli->query("SHOW COLUMNS FROM wohnungen LIKE '$c'")->num_rows === 0) {
        if ($mysqli->query("ALTER TABLE wohnungen ADD $c $def")) {
            echo "✅ Spalte $c wurde angelegt.\n";
        } else {
            echo "❌ Fehler bei Spalte $c: " . $mysqli->error . "\n";
        }
    } else {
        echo "ℹ️ Spalte $c existiert bereits.\n";
    }
}

// 2. Wohnung 16 manuell löschen (Tiefenreinigung)
$wid = 16;
echo "\n--- VERSUCHE LÖSCHUNG VON WOHNUNG $wid ---\n";

try {
    // In der richtigen Reihenfolge löschen (Foreign Key Safe)
    $mysqli->query("DELETE FROM gegenstaende WHERE zimmer_id IN (SELECT id FROM zimmer WHERE wohnung_id = $wid)");
    $mysqli->query("DELETE FROM abnahme_mangel_link WHERE abnahme_id IN (SELECT id FROM abnahmen WHERE wohneinheit_id = $wid)");
    $mysqli->query("DELETE FROM zimmer WHERE wohnung_id = $wid");
    $mysqli->query("DELETE FROM abnahmen WHERE wohneinheit_id = $wid");
    $mysqli->query("DELETE FROM mietvertraege WHERE wohnung_id = $wid");
    $mysqli->query("DELETE FROM mietverhaeltnisse WHERE wohnung_id = $wid");
    $mysqli->query("DELETE FROM wohnung_bilder WHERE wohnung_id = $wid");
    $mysqli->query("DELETE FROM wohnung_dokumente WHERE wohnung_id = $wid");
    if (table_exists($mysqli, 'miet_interessenten')) {
        $mysqli->query("DELETE FROM miet_interessenten WHERE wohnung_id = $wid");
    }
    if (table_exists($mysqli, 'interessenten')) {
        $mysqli->query("DELETE FROM interessenten WHERE wohnung_id = $wid");
    }
    $mysqli->query("DELETE FROM wohnung_mieter WHERE wohnung_id = $wid");
    $mysqli->query("UPDATE pendenzen SET wohnung_id = NULL WHERE wohnung_id = $wid");
    
    if ($mysqli->query("DELETE FROM wohnungen WHERE id = $wid")) {
        echo "💥 Wohnung $wid erfolgreich aus MySQL gesprengt!\n";
    } else {
        echo "🛑 MySQL blockiert weiterhin: " . $mysqli->error . "\n";
    }
} catch (Exception $e) {
    echo "🚨 Fataler Fehler: " . $e->getMessage() . "\n";
}
?>
