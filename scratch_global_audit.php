<?php
require_once 'config.php';
echo "Global Object Audit (All Projects):\n";

$res = $mysqli->query("SELECT o.id, o.projekt_id, p.name as p_name, o.name as o_name 
                       FROM objekte o 
                       LEFT JOIN projekte p ON p.id = o.projekt_id 
                       ORDER BY o.projekt_id, o.name");

while($row = $res->fetch_assoc()) {
    echo "ID $row[id] | Project $row[projekt_id] ($row[p_name]) | Object: '$row[o_name]'\n";
}
