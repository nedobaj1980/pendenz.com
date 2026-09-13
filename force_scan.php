<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/fs.php';
$mysqli = db();
$pid = 1;

echo "Scanning project $pid...\n";
$res = fs_scan_project($mysqli, $pid, 0);
print_r($res);
