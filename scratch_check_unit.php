<?php
require_once 'config.php';
$res = $mysqli->query("SELECT id, name, projekt_id FROM wohnungen WHERE id = 85");
if ($res && $row = $res->fetch_assoc()) {
    print_r($row);
} else {
    echo "Unit 85 not found or query failed.";
    if ($mysqli->error) echo " Error: " . $mysqli->error;
}
