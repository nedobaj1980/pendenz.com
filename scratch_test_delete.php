<?php
require_once 'config.php';
$wid = 100; // Change this to a real ID if needed, but let's just try to find one
$res = $mysqli->query("SELECT id FROM wohnungen ORDER BY id DESC LIMIT 1");
if ($row = $res->fetch_assoc()) {
    $wid = $row['id'];
    echo "Trying to delete unit ID: $wid\n";
    try {
        $mysqli->query("DELETE FROM wohnungen WHERE id = $wid");
        echo "Deleted successfully!\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
