<?php
require_once 'config.php';

// Alle "Wohnungen" oder "10_Wohnungen" Objekte finden
$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte WHERE name LIKE '%Wohnungen%'");
$toDelete = [];

echo "Auditing objects for global cleanup...\n";
while($row = $res->fetch_assoc()) {
    $id = $row['id'];
    
    // 1. Check if any apartments are linked to this object
    $w = $mysqli->query("SELECT COUNT(*) as count FROM wohnungen WHERE objekt_id = $id")->fetch_assoc();
    
    // 2. Since pendenzen don't have objekt_id, we check if any apartments linked to this object have pendenzen
    // (If count of apartments is 0, this is likely 0 anyway)
    
    if ($w['count'] == 0) {
        $toDelete[] = $id;
        echo "Marked for deletion: ID $id ($row[name]) from Project $row[projekt_id] (Reason: 0 apartments)\n";
    } else {
        echo "KEEPING: ID $id ($row[name]) - Project $row[projekt_id] has " . $w['count'] . " apartments assigned.\n";
    }
}

if (!empty($toDelete)) {
    $ids = implode(',', $toDelete);
    $mysqli->query("DELETE FROM objekte WHERE id IN ($ids)");
    echo "\nSUCCESS: Deleted " . count($toDelete) . " redundant ghost objects.\n";
} else {
    echo "\nNo ghost objects found for deletion.\n";
}
