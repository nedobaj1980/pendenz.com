<?php
require_once __DIR__ . '/../config.php';
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$mysqli->query("TRUNCATE TABLE projekte");
$mysqli->query("TRUNCATE TABLE objekte");
$mysqli->query("TRUNCATE TABLE wohnungen");
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
echo "CLEANUP_DONE";
