<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("SELECT id, name, firma_name, firmenlogo FROM benutzer");
echo "<table border=1>";
while($row = $res->fetch_assoc()){
    echo "<tr><td>".implode("</td><td>", $row)."</td></tr>";
}
echo "</table>";
