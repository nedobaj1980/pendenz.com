<?php
require_once 'config.php';
$mysqli = new mysqli('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT);

$table = 'pendenzen';
$res = $mysqli->query("DESCRIBE $table");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
