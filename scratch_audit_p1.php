<?php
require_once 'config.php';
$pid = 1; // Romanshorn
echo "Auditing Project 1 (Arbonerstrasse):\n";

$res = $mysqli->query("SELECT o.id, o.name, COUNT(w.id) as w_count 
                       FROM objekte o 
                       LEFT JOIN wohnungen w ON w.objekt_id = o.id 
                       WHERE o.projekt_id = $pid 
                       GROUP BY o.id");

while($row = $res->fetch_assoc()) {
    echo "Object ID $row[id]: '$row[name]' has $row[w_count] apartments.\n";
}
