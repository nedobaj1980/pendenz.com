<?php
require_once "config.php";
$res = $mysqli->query("SELECT id, titel, startdatum, enddatum, projekt_id, vorgaenger_id FROM pendenzen WHERE projekt_id = 2");
while($row = $res->fetch_assoc()) {
    echo "ID: " . $row['id'] . " | Start: '" . $row['startdatum'] . "' | End: '" . $row['enddatum'] . "'\n";
}
?>
