<?php
require_once "config.php";
echo "<h1>Full Schema Status</h1><pre>";
$tables = ['bkp_codes', 'bkp_kategorien', 'bkp_vorlagen_texte', 'benutzer'];
foreach ($tables as $t) {
    echo "<h2>Table: $t</h2>";
    $res = $mysqli->query("DESCRIBE $t");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            print_r($row);
        }
    } else {
        echo "ERROR: " . $mysqli->error . "\n";
    }
}
echo "</pre>";
?>
