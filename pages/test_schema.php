<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once 'c:/xampp/htdocs/pendenz.com/config.php';
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
echo "\n--- BENUTZER ---\n";
$res = $mysqli->query('SHOW COLUMNS FROM benutzer');
while ($row = $res->fetch_assoc()) echo $row['Field'] . "\n";
echo "\n--- FIRMEN ---\n";
$res2 = $mysqli->query('SHOW COLUMNS FROM firmen');
while ($row = $res2->fetch_assoc()) echo $row['Field'] . "\n";
