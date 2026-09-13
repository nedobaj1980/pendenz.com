<?php
require 'config.php';
$tbl = $_GET['t'] ?? 'projekte';
$res = $mysqli->query("SHOW COLUMNS FROM `$tbl` ");
echo "<pre>";
while($row = $res->fetch_assoc()) { print_r($row); }
echo "</pre>";
