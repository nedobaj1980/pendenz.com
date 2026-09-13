<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("SELECT id, firma_name, firmenlogo FROM benutzer WHERE id=4");
$u = $res->fetch_assoc();
print_r($u);
