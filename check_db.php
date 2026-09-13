<?php
require 'config.php';
$res = $mysqli->query('DESCRIBE pendenzen');
while($row=$res->fetch_assoc()){
    echo $row['Field'] . " | " . $row['Type'] . "\n";
}
