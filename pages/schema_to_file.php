<?php
$mysqli = new mysqli('localhost', 'root', '', 'pendenz_com');
if ($mysqli->connect_error) {
    file_put_contents('c:/xampp/htdocs/pendenz.com/pages/schema_out.txt', "DB Error");
    die();
}
$out = "\n--- BENUTZER ---\n";
$res = $mysqli->query("SHOW COLUMNS FROM benutzer LIKE '%bkp%'");
while ($row = $res->fetch_assoc()) $out .= $row['Field'] . "\n";
$out .= "\n--- FIRMEN ---\n";
$res2 = $mysqli->query("SHOW COLUMNS FROM firmen LIKE '%bkp%'");
while ($row = $res2->fetch_assoc()) $out .= $row['Field'] . "\n";
file_put_contents('c:/xampp/htdocs/pendenz.com/pages/schema_out.txt', $out);
