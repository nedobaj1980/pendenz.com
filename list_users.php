<?php
require_once __DIR__ . '/config.php';
$res = $mysqli->query("SELECT id, name, email FROM benutzer");
echo "USERS IN DB:\n";
while($row = $res->fetch_assoc()) {
    echo "ID " . $row['id'] . " | " . $row['name'] . " (" . $row['email'] . ")\n";
}
?>
