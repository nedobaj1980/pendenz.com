<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');
foreach(['wohnung_bilder','wohnung_dokumente'] as $t) {
    echo "--- $t ---\n";
    $res = $mysqli->query("DESCRIBE $t");
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}
