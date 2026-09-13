<?php
require '../config.php';
$res = $mysqli->query('SHOW COLUMNS FROM pendenz_dateien');
if ($res) {
    while($row = $res->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "Table pendenz_dateien does not exist\n";
    $res = $mysqli->query('SHOW COLUMNS FROM pendenz_anhaenge');
    if ($res) {
        while($row = $res->fetch_assoc()) {
            echo json_encode($row) . "\n";
        }
    } else {
        echo "Neither table exists\n";
    }
}
