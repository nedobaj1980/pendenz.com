<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
$mysqli = db();
$pid = 1;
$path = '01_Rechnungen';
$res = $mysqli->query("SELECT name, rel_path, parent_rel_path, is_dir FROM fs_nodes WHERE project_id=$pid AND parent_rel_path='$path'");
echo "Children of $path:\n";
while($r = $res->fetch_assoc()) {
    print_r($r);
}

// Check count query
$st = $mysqli->prepare("SELECT SUM(is_dir=1) d, SUM(is_dir=0) f FROM fs_nodes WHERE project_id=? AND parent_rel_path=?");
$st->bind_param("is",$pid,$path); $st->execute();
$cnt = $st->get_result()->fetch_assoc();
echo "\nStats for $path:\n";
print_r($cnt);
