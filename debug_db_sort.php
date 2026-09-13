<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
$res = $mysqli->query("DESCRIBE pendenzen");
$cols = [];
if ($res) {
    while($row = $res->fetch_assoc()) {
        $cols[] = $row['Field'];
    }
}
sort($cols);
print_r($cols);
