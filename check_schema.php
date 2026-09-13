<?php
$c = new mysqli('localhost','root','','pendenz_com');
$res = $c->query('DESCRIBE benutzer');
while($r = $res->fetch_assoc()) echo $r['Field'] . ' ';
