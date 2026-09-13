<?php
require_once 'config.php';
$res = $mysqli->query("SELECT id, name, typ_id FROM wohnungen WHERE objekt_id IN (SELECT id FROM objekte WHERE projekt_id = 1)");
echo "Units for Project 1:\n";
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]' | Typ-ID: $row[typ_id]\n";
}
