<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');
$res = $mysqli->query("DESCRIBE pendenzen");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . "\n";
    }
}
