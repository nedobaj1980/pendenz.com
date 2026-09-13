<?php
require_once 'config.php';
$pid = 1; 
echo "Project 1 Objects Detail Audit:\n";
$res = $mysqli->query("SELECT id, name, LENGTH(name) as len FROM objekte WHERE projekt_id = $pid");
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]' | Length: $row[len]\n";
}
echo "\nTotal rows: " . $res->num_rows . "\n";
