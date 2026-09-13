<?php
require_once 'config.php';
$res = $mysqli->query("DESCRIBE wohnungen");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
echo "--- wohnung_mieter ---\n";
$res = $mysqli->query("DESCRIBE wohnung_mieter");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
