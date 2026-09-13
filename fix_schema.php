<?php
require_once 'config.php';

$cols_to_add = [
    'objekt_id' => 'INT NULL AFTER projekt_id',
    'wohnung_id' => 'INT NULL AFTER objekt_id',
    'kategorie_id' => 'INT NULL',
    'subkategorie_id' => 'INT NULL',
    'uhrzeit' => 'TIME NULL',
    'tageszeit' => 'VARCHAR(50) NULL',
    'dauer' => 'VARCHAR(50) NULL',
    'vorgaenger_id' => 'INT NULL'
];

echo "<pre>";
foreach ($cols_to_add as $col => $def) {
    $res = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE '$col'");
    if ($res->num_rows == 0) {
        echo "Adding $col...\n";
        $mysqli->query("ALTER TABLE pendenzen ADD COLUMN `$col` $def");
    } else {
        echo "$col already exists.\n";
    }
}
echo "Done.";
?>
