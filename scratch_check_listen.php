<?php
require_once 'config.php';
echo "Checking list templates in table 'listen':\n";
$res = $mysqli->query("SELECT id, name, table_name, shared, owner_id FROM listen");
if ($res) {
    if ($res->num_rows === 0) {
        echo "The table 'listen' is EMPTY.\n";
    } else {
        while($row = $res->fetch_assoc()) {
            echo "ID: $row[id] | Name: '$row[name]' | Table: '$row[table_name]' | Owner: $row[owner_id]\n";
        }
    }
} else {
    echo "ERROR: Table 'listen' does not exist!\n";
}
