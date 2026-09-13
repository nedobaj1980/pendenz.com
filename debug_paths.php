<?php
require_once __DIR__ . '/config.php';
$res = $mysqli->query("SELECT * FROM fs_folder_meta");
echo "--- DB ENTRIES ---\n";
while($r = $res->fetch_assoc()) {
    print_r($r);
}
echo "--- PATH TEST ---\n";
// Test default project 1
$res2 = $mysqli->query("SELECT rel_path FROM fs_nodes WHERE project_id=1 AND is_dir=1 LIMIT 5");
while($r2 = $res2->fetch_assoc()) {
    echo "FS Node: " . $r2['rel_path'] . "\n";
}
unlink(__FILE__);
