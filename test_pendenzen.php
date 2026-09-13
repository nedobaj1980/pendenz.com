<?php
require_once 'config.php';
$r = $mysqli->query('SELECT COUNT(*) FROM pendenzen WHERE projekt_id=4');
$cnt = $r ? $r->fetch_row()[0] : 'ERROR: ' . $mysqli->error;
echo "PROJECT_4_PENDENZEN: " . $cnt;
?>
