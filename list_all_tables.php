<?php
$db = mysqli_connect('127.0.0.1', 'root', '', 'pendenz_com');
$r = mysqli_query($db, "SHOW TABLES");
while ($row = mysqli_fetch_row($r)) {
    echo $row[0] . "\n";
}
mysqli_close($db);
