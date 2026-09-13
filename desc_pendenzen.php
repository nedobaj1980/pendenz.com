<?php
require_once 'config.php';
$res = $mysqli->query("DESCRIBE pendenzen");
while($r = $res->fetch_assoc()) echo $r['Field'] . " (" . $r['Type'] . ")\n";
?>
