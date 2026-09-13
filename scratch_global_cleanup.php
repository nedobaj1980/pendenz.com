<?php
require_once 'config.php';

// Alle "10_Wohnungen" Objekte finden
$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte WHERE name = '10_Wohnungen' OR name LIKE '%Wohnungen%'");
$toDelete = [];

echo "Auditing objects...\n";
while($row = $res->fetch_assoc()) {
    $id = $row['id'];
    // Check apartments
    $w = $mysqli->query("SELECT COUNT(*) as count FROM wohnungen WHERE objekt_id = $id")->fetch_assoc();
    // Check pendenzen
    $p = $mysqli->query("SELECT COUNT(*) as count FROM pendenzen WHERE objekt_id = $id")->fetch_assoc();
    
    if ($w['count'] == 0 && $p['count'] == 0) {
        $toDelete[] = $id;
        echo "Marked for deletion: ID $id ($row[name]) - Project $row[projekt_id]\n";
    } else {
        echo "KEEPING: ID $id ($row[name]) - Has " . $w['count'] . " apartments and " . $p['count'] . " pendenzen.\n";
    }
}

if (!empty($toDelete)) {
    $ids = implode(',', $toDelete);
    $mysqli->query("DELETE FROM objekte WHERE id IN ($ids)");
    echo "\nSUCCESS: Deleted " . count($toDelete) . " ghost objects.\n";
} else {
    echo "\nNo ghost objects found for deletion.\n";
}
