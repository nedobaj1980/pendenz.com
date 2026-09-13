<?php
require_once '../config.php';
$tables = ['benutzer', 'interessenten'];
echo "<pre>";
foreach($tables as $t) {
    echo "--- $t ---\n";
    $res = $mysqli->query("DESCRIBE $t");
    while($row = $res->fetch_assoc()) {
        echo str_pad($row['Field'], 25) . " | " . $row['Type'] . "\n";
    }
    echo "\n";
}
echo "</pre>";
