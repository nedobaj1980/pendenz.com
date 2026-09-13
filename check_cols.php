<?php
$mysqli = new mysqli('localhost', 'root', '', 'pendenz_com');
if ($mysqli->connect_error) die("Connect failed: " . $mysqli->connect_error);
$res = $mysqli->query("SHOW COLUMNS FROM projekte");
while($r = $res->fetch_assoc()) {
    echo $r['Field'] . "\n";
}
