<?php
require_once 'config.php';
$res = $mysqli->query('ALTER TABLE listen_spalten CHANGE list_id listen_id INT NOT NULL');
if($res) echo "SUCCESS: list_id renamed to listen_id in listen_spalten";
else echo "ERROR: " . $mysqli->error;
?>
