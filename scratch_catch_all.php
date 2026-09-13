<?php
require_once 'config.php';
echo "Audit for Project 1, 0, or NULL projects:\n";
$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte WHERE projekt_id = 1 OR projekt_id = 0 OR projekt_id IS NULL");
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Project ID: " . ($row['projekt_id'] ?? 'NULL') . " | Name: '$row[name]'\n";
}
echo "\nTotal found: " . $res->num_rows . "\n";
