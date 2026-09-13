<?php
require_once __DIR__ . "/config.php";
$res = $mysqli->query("SELECT email, rolle FROM benutzer");
while($row = $res->fetch_assoc()){
    echo $row['email'] . " (" . $row['rolle'] . ")\n";
}
