<?php
require_once __DIR__ . '/config.php';
$res = $mysqli->query("SELECT id, name, email FROM benutzer WHERE id IN (4, 8)");
while($row = $res->fetch_assoc()) {
    echo "ID " . $row['id'] . ": " . $row['name'] . " (" . $row['email'] . ")\n";
}
?>
