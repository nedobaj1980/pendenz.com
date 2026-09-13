<?php
require_once 'config.php';
$mysqli = new mysqli('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT);

$res = $mysqli->query("DESCRIBE abnahme_protokolle");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo "Field: " . $row['Field'] . " | Type: " . $row['Type'] . "\n";
    }
} else {
    echo "Error: " . $mysqli->error;
}
