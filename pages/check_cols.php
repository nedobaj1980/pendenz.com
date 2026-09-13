<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("SHOW COLUMNS FROM pendenzen");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
unlink(__FILE__);
