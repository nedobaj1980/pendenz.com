<?php
require_once __DIR__ . '/config.php';
$res = $mysqli->query("DESCRIBE benutzer");
$out = "";
while($row = $res->fetch_assoc()) {
    $out .= $row['Field'] . "\n";
}
file_put_contents(__DIR__ . '/logs/benutzer_schema.txt', $out);
echo "OK";
