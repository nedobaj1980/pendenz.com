<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("SHOW COLUMNS FROM benutzer");
while($row = $res->fetch_assoc()) echo $row['Field']."\n";
