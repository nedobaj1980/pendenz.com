<?php
$db = mysqli_connect('127.0.0.1', 'root', '', 'pendenz_com');
if (!$db) die("Conn failed");

echo "PROJECT 4 OBJECTS:\n";
$resO = mysqli_query($db, "SELECT id, name FROM objekte WHERE projekt_id=4");
while ($o = mysqli_fetch_assoc($resO)) {
    echo "ID: " . $o['id'] . " Name: " . $o['name'] . "\n";
    $oid = $o['id'];
    $resW = mysqli_query($db, "SELECT id, name FROM wohnungen WHERE objekt_id=$oid LIMIT 2");
    while ($w = mysqli_fetch_assoc($resW)) {
        echo "  - Whg ID: " . $w['id'] . " Name: " . $w['name'] . "\n";
    }
}
mysqli_close($db);
