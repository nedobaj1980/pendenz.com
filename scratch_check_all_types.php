<?php
require_once 'config.php';
$res = $mysqli->query("SELECT * FROM einheit_typen");
echo "Full list of unit types:\n";
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]' | Icon: '$row[icon]'\n";
}
