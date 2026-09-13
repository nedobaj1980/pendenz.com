<?php
require_once 'config.php';
$res = $mysqli->query("SELECT * FROM einheit_typen");
echo "Current unit types and icons:\n";
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]' | Group: '$row[gruppe]' | Icon: '$row[icon]'\n";
}
