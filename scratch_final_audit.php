<?php
require_once 'config.php';
$pid = 1; 
echo "ALL objects for Project 1:\n";
$res = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id = $pid");
while($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . " | Name: '" . $row['name'] . "'\n";
}
echo "\nTotal rows found: " . $res->num_rows . "\n";
