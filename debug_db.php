<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
$res = $mysqli->query("DESCRIBE pendenzen");
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} else {
    echo "ERROR: " . $mysqli->error;
}
