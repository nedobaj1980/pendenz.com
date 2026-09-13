<?php
require 'config.php';
$pid = 4;
echo "Objects for Project $pid:\n";
$res = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=$pid");
while ($r = $res->fetch_assoc()) {
    echo "ID: " . $r['id'] . " Name: " . $r['name'] . "\n";
    $oid = (int)$r['id'];
    $resW = $mysqli->query("SELECT id, name, objekt_id FROM wohnungen WHERE objekt_id=$oid");
    while ($w = $resW->fetch_assoc()) {
        echo "  - Wohnung ID: " . $w['id'] . " Name: " . $w['name'] . " (Object ID: " . $w['objekt_id'] . ")\n";
    }
}
