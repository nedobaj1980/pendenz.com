<?php
require_once 'config.php';
$res = $mysqli->query("DESCRIBE pendenzen");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
