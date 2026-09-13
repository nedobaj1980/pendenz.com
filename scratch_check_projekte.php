<?php
require_once 'config.php';
echo "Checking for duplicate projects with same name:\n";
$res = $mysqli->query("SELECT id, name FROM projekte WHERE name LIKE '%Arbonerstrasse%'");
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]'\n";
}
echo "\nTotal projects found: " . $res->num_rows . "\n";
