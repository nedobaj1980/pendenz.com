<?php
require_once 'config.php';
$res = $mysqli->query("SELECT id, name FROM projekte");
echo "PROJEKTE:\n";
while($r = $res->fetch_assoc()) print_r($r);

$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte");
echo "\nOBJEKTE:\n";
while($r = $res->fetch_assoc()) print_r($r);

$res = $mysqli->query("SELECT id, objekt_id, name FROM wohnungen");
echo "\nWOHNUNGEN:\n";
while($r = $res->fetch_all(MYSQLI_ASSOC)) {
    foreach($r as $row) {
        echo "ID: {$row['id']}, OID: {$row['objekt_id']}, Name: {$row['name']}\n";
    }
}
?>
