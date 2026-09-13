<?php
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'pendenz_com');
define('DB_USER', 'root');
define('DB_PASS', '');
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($mysqli->connect_error) {
    die("Connect Error: " . $mysqli->connect_error);
}
$res = $mysqli->query("SHOW COLUMNS FROM pendenzen");
$cols = [];
while($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
file_put_contents(__DIR__ . '/pendenzen_cols.txt', implode("\n", $cols));
echo "OK";
