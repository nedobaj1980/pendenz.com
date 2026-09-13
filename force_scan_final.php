<?php
// force_scan_final.php
$mysqli = new mysqli('localhost','root','','pendenz_com');
if($mysqli->connect_error) die("DB failed");

require_once __DIR__ . '/includes/fs.php';

$pid = 1;
echo "Starting scan for project $pid...\n";
$res = fs_scan_project($mysqli, $pid, 0);
echo "Result: " . ($res['ok'] ? "SUCCESS" : "FAILED") . " - " . $res['msg'] . "\n";
if(isset($res['count'])) echo "Indexed " . $res['count'] . " items.\n";
