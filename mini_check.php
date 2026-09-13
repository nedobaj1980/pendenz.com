<?php
$c = new mysqli('localhost','root','','pendenz_com');
$path = '01_Rechnungen';
$res = $c->query("SELECT COUNT(*) as c FROM fs_nodes WHERE project_id=1 AND parent_rel_path='$path' AND is_dir=1");
$row = $res->fetch_assoc();
echo "Folders in $path: " . $row['c'] . "\n";

$res2 = $c->query("SELECT rel_path FROM fs_nodes WHERE project_id=1 AND rel_path LIKE '01_Rechnungen%' LIMIT 5");
echo "Samples:\n";
while($r = $res2->fetch_assoc()) echo $r['rel_path'] . "\n";
