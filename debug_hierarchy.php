<?php
require_once __DIR__ . '/config.php';
$tables = ['projekte', 'objekte', 'wohnungen'];
foreach($tables as $t) {
    echo "TABLE: $t\n";
    $res = $mysqli->query("DESCRIBE $t");
    while($row = $res->fetch_assoc()) echo $row['Field'] . " | " . $row['Type'] . "\n";
    echo "\n";
}
?>
