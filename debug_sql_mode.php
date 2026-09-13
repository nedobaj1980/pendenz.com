<?php
require 'config.php';
$r = $mysqli->query('SELECT @@sql_mode');
print_r($r->fetch_assoc());
