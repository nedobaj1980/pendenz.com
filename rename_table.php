<?php
require_once 'config.php';
$res = $mysqli->query('RENAME TABLE listen_columns TO listen_spalten');
if($res) echo "SUCCESS: listen_columns renamed to listen_spalten";
else echo "ERROR: " . $mysqli->error;
?>
