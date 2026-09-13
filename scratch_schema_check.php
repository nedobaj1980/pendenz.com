<?php
require_once 'config.php';

function show_cols($table, $db) {
    echo "\nTABLE: $table\n";
    $res = $db->query("SHOW COLUMNS FROM $table");
    if ($res) {
        while($r = $res->fetch_assoc()) {
            echo "{$r['Field']} - {$r['Type']} - {$r['Null']} - {$r['Key']}\n";
        }
    } else {
        echo "Error or table not found.\n";
    }
}

show_cols('benutzer', $mysqli);
show_cols('unternehmer_projekte', $mysqli);
show_cols('projekte', $mysqli);
show_cols('objekte', $mysqli);
show_cols('wohnungen', $mysqli);
show_cols('bkp_codes', $mysqli);
?>
