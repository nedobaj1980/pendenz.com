<?php
// mini_debug.php
$db = new PDO('mysql:host=localhost;dbname=pendenz_com', 'root', '');
$rows = $db->query("SELECT rel_path, parent_rel_path, is_dir FROM fs_nodes WHERE project_id=1 LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
echo "### FS_NODES DATA (TOP 50) ###\n";
foreach($rows as $r) {
    echo "REL: [{$r['rel_path']}] | PARENT: [{$r['parent_rel_path']}] | DIR: {$r['is_dir']}\n";
}
