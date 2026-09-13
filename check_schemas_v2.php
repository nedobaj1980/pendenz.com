<?php
require_once 'config.php';
$mysqli = new mysqli('127.0.0.1', DB_USER, DB_PASS, DB_NAME, DB_PORT);

function desc($table) {
    global $mysqli;
    echo "\nTable: $table\n";
    $result = $mysqli->query("DESCRIBE $table");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            print_r($row);
        }
    } else {
        echo "Error: " . $mysqli->error;
    }
}

desc('pendenzen');
desc('abnahme_protokolle');
