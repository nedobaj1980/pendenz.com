<?php
require_once __DIR__ . '/config.php';
$res = $mysqli->query("SELECT * FROM folders LIMIT 50");
echo "FOLDERS:\n";
while($row = $res->fetch_assoc()) {
    echo "ID " . $row['id'] . " | PID " . ($row['parent_id']??"NULL") . " | Name: " . $row['name'] . " | Path: " . $row['path'] . "\n";
}
?>
