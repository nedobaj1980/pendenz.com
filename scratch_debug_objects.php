<?php
require_once 'config.php';
$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte WHERE projekt_id = 4");
echo "Objects for Project 4:\n";
while($row = $res->fetch_assoc()) {
    print_r($row);
}

$res2 = $mysqli->query("SELECT COUNT(*) as total FROM objekte WHERE projekt_id = 4");
$row2 = $res2->fetch_assoc();
echo "\nTotal count: " . $row2['total'] . "\n";
