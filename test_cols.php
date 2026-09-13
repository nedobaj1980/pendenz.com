<?php
require_once 'config.php';
$mysqli = new mysqli('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT);
$res = $mysqli->query("DESCRIBE pendenzen");
$out = "";
while($row = $res->fetch_assoc()) {
    $out .= $row['Field'] . "\n";
}
file_put_contents('test_cols_out.txt', $out);
echo "Done";
