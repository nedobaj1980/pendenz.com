<?php
require 'config.php';
$rs = $mysqli->query("SHOW COLUMNS FROM listen");
echo "=== listen ===\n";
if ($rs) {
    while($r = $rs->fetch_assoc()) { print_r($r); }
} else { echo "Tabelle existiert nicht: " . $mysqli->error; }
