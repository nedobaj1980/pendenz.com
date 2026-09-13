<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');
$res = $mysqli->query("DESCRIBE pendenzen");
$cols = [];
if ($res) {
    while($row = $res->fetch_assoc()) {
        $cols[] = $row['Field'];
    }
}
echo json_encode($cols);
