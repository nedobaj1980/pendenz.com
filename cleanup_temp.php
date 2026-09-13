<?php
$files = [
    'get_active_obj4.php', 'get_obj4.php', 'get_proj4_path.php', 
    'check_proj_cols.php', 'check_proj_cols_v2.php', 'check_proj_cols_v3.php', 
    'create_unit_folders.php', 'create_unit_folders_v2.php', 
    'list_objs_proj4.php', 'list_projects.php', 'list_units_proj4.php', 
    'sync_homepage_units.php', 'update_proj4_root.php'
];
foreach ($files as $f) {
    if (file_exists($f)) unlink($f);
}
echo "Cleanup done.";
