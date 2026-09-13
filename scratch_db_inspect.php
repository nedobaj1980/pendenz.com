<?php
require_once 'config.php';
echo "--- TABELLEN IN DER DATENBANK ---\n";
$res = $mysqli->query("SHOW TABLES");
while($row = $res->fetch_row()) {
    echo "Tabelle: " . $row[0] . "\n";
}

echo "\n--- FREMDSCHLÜSSEL AUF 'wohnungen' ---\n";
$res = $mysqli->query("
    SELECT TABLE_NAME, COLUMN_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE REFERENCED_TABLE_NAME = 'wohnungen' 
    AND TABLE_SCHEMA = DATABASE()
");
while($row = $res->fetch_assoc()) {
    echo "Abhängigkeit: " . $row['TABLE_NAME'] . " -> " . $row['COLUMN_NAME'] . "\n";
}
?>
