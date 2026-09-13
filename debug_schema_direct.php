<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');
if ($mysqli->connect_error) die('Connect Error: ' . $mysqli->connect_error);

echo "--- wohnungen ---\n";
$res = $mysqli->query("DESCRIBE wohnungen");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
echo "\n--- wohnung_mieter ---\n";
$res = $mysqli->query("DESCRIBE wohnung_mieter");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
