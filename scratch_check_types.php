<?php
require_once 'config.php';
echo "Checking unit types:\n";
$res = $mysqli->query("SELECT id, name, gruppe FROM einheit_typen");
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]' | Group: '$row[gruppe]'\n";
}
