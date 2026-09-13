<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("DESCRIBE benutzer");
while($row = $res->fetch_assoc()){
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
