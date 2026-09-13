<?php
require 'config.php';
$stmt = $mysqli->prepare("SELECT rel_path, name FROM fs_nodes WHERE project_id=? AND is_dir=1 ORDER BY rel_path ASC");
if (!$stmt) { echo $mysqli->error; } else { echo "OK"; }
