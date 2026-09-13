<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: text/plain');

foreach(['bkp_codes', 'bkp_kategorien', 'bkp_vorlagen_texte'] as $t) {
    echo "--- Table: $t ---\n";
    $res = $mysqli->query("SHOW CREATE TABLE `$t` ");
    if($res) {
        $row = $res->fetch_assoc();
        echo $row['Create Table'] . "\n\n";
    } else {
        echo "Error: " . $mysqli->error . "\n\n";
    }
}

echo "--- Check Foreign Keys ---\n";
$res = $mysqli->query("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = 'pendenz_com' AND REFERENCED_TABLE_NAME IN ('bkp_codes', 'bkp_kategorien', 'bkp_vorlagen_texte')");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
