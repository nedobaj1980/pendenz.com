<?php
require_once 'config.php';
$res = $mysqli->query("SELECT COUNT(*) as total FROM objekte");
$row = $res->fetch_assoc();
echo "Total objects in DB: " . $row['total'] . "\n";
