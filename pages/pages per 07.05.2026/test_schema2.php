<?php
$mysqli = new mysqli('localhost', 'root', '', 'pendenz_com');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
echo "\n--- BENUTZER ---\n";
$res = $mysqli->query('SHOW COLUMNS FROM benutzer');
while ($row = $res->fetch_assoc()) echo $row['Field'] . "\n";
echo "\n--- FIRMEN ---\n";
$res2 = $mysqli->query('SHOW COLUMNS FROM firmen');
while ($row = $res2->fetch_assoc()) echo $row['Field'] . "\n";
