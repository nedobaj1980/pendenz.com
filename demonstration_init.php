<?php
$db = mysqli_connect('127.0.0.1', 'root', '', 'pendenz_com');
$r = mysqli_query($db, 'SELECT id, email FROM benutzer LIMIT 1');
$u = mysqli_fetch_assoc($r);
echo json_encode($u);
mysqli_close($db);
