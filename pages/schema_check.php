<?php
require_once __DIR__ . '/../config.php';
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$res = $mysqli->query('SHOW COLUMNS FROM firmen');
echo "FIRMEN: ";
while ($row = $res->fetch_assoc()) echo $row['Field'] . ' | ';
echo "\nBENUTZER: ";
$res2 = $mysqli->query('SHOW COLUMNS FROM benutzer');
while ($row = $res2->fetch_assoc()) echo $row['Field'] . ' | ';
