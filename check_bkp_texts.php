<?php
require_once 'config.php';
$mysqli = new mysqli('127.0.0.1', DB_USER, DB_PASS, DB_NAME, DB_PORT);

$res = $mysqli->query("DESCRIBE bkp_vorlagen_texte");
if ($res) {
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Error: " . $mysqli->error;
}
