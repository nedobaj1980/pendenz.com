<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

echo "<h1>Path Repair Utility</h1>";

// 1. Repair missing objekt_id from wohnung_id
$mysqli->query("UPDATE pendenzen p JOIN wohnungen w ON p.wohnung_id = w.id SET p.objekt_id = w.objekt_id WHERE p.objekt_id IS NULL AND p.wohnung_id IS NOT NULL");
echo "Updated missing objekt_ids from wohnungen.<br>";

// 2. Fetch building and unit info for paths
$res = $mysqli->query("
    SELECT p.id, p.fs_rel_path, p.fs_branch, p.objekt_id, p.wohnung_id, 
           o.folder_name as oPath, w.name as wPath
    FROM pendenzen p
    LEFT JOIN objekte o ON p.objekt_id = o.id
    LEFT JOIN wohnungen w ON p.wohnung_id = w.id
    WHERE p.objekt_id IS NOT NULL OR p.wohnung_id IS NOT NULL
");

$count = 0;
while ($row = $res->fetch_assoc()) {
    $id = $row['id'];
    $old = $row['fs_rel_path'];
    $branch = $row['fs_branch'] ?: '10_Mietsache';
    $oPath = $row['oPath'] ?: '';
    $wPath = $row['wPath'] ?: '';

    // Logic: Building / Branch / Unit (if Mietsache)
    $new = '';
    if ($oPath) {
        $new = $oPath . '/' . $branch;
        if ($branch === '10_Mietsache' && $wPath) {
            $new .= '/' . $wPath;
        }
    }

    if ($new && $new !== $old) {
        $stmt = $mysqli->prepare("UPDATE pendenzen SET fs_rel_path = ?, fs_branch = ? WHERE id = ?");
        $stmt->bind_param('ssi', $new, $branch, $id);
        $stmt->execute();
        echo "ID {$id}: Fixed '{$old}' -> '{$new}' (Branch: {$branch})<br>";
        $count++;
    }
}

echo "<h3>Finished! Fixed {$count} paths.</h3>";
