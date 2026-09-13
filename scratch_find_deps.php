<?php
require_once 'config.php';
$res = $mysqli->query("
    SELECT TABLE_NAME, COLUMN_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE REFERENCED_TABLE_NAME = 'wohnungen' 
    AND TABLE_SCHEMA = DATABASE()
");
echo "Verknüpfte Tabellen zu 'wohnungen':\n";
while($row = $res->fetch_assoc()) {
    echo "- " . $row['TABLE_NAME'] . " (" . $row['COLUMN_NAME'] . ")\n";
}
?>
