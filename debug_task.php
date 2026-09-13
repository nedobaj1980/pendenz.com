<?php
require 'config.php';
$id = 9;
$res = $mysqli->query("SELECT id, public_enabled, public_token FROM pendenzen WHERE id=$id");
$data = $res->fetch_assoc();
header('Content-Type: text/plain');
print_r($data);
