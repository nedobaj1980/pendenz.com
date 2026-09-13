<?php
require_once 'config.php';
$mysqli = new mysqli('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT);
$res = $mysqli->query("DESCRIBE pendenz_anhaenge");
$out = "pendenz_anhaenge:\n";
if ($res) {
    while($row = $res->fetch_array()) { $out .= $row[0] . "\n"; }
} else {
    $out .= "Does not exist\n";
}
$out .= "\npendenz_dateien:\n";
$res = $mysqli->query("DESCRIBE pendenz_dateien");
if ($res) {
    while($row = $res->fetch_array()) { $out .= $row[0] . "\n"; }
} else {
    $out .= "Does not exist\n";
}
file_put_contents('test_tables_out.txt', $out);
echo "Done";
