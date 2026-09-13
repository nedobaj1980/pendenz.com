<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');
echo "Object 3:\n";
$res = $mysqli->query("SELECT * FROM objekte WHERE id=3");
print_r($res->fetch_assoc());
echo "\nApartments for Object 3:\n";
$resW = $mysqli->query("SELECT w.id, w.name, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE w.objekt_id=3");
while($w = $resW->fetch_assoc()) {
    echo "ID: " . $w['id'] . " Name: " . $w['name'] . " Object: " . $w['obj_name'] . "\n";
}
echo "\nObject 4 (likely MFH Bajramoski?):\n";
$res4 = $mysqli->query("SELECT * FROM objekte WHERE id=4");
print_r($res4->fetch_assoc());
