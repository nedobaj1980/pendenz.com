<?php
require_once 'config.php';
echo "Searching for ALL '10_Wohnungen' objects in the entire database:\n";
$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte WHERE name LIKE '%Wohnungen%'");
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Project ID: $row[projekt_id] | Name: '$row[name]'\n";
}
echo "\nTotal found: " . $res->num_rows . "\n";
