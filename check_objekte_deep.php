<?php
require_once 'config.php';
$r = $mysqli->query("SHOW TRIGGERS LIKE 'objekte'");
while($row = $r->fetch_assoc()) echo "TRIGGER: " . json_encode($row) . "\n";

$r = $mysqli->query("SHOW CREATE TABLE objekte");
$row = $r->fetch_row();
echo "CREATE TABLE: " . $row[1] . "\n";
