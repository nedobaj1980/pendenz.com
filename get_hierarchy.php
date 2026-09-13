<?php
require_once __DIR__ . '/config.php';

$projects = $mysqli->query("SELECT id, name FROM projekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$objects = $mysqli->query("SELECT id, projekt_id, name FROM objekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$units = $mysqli->query("SELECT id, objekt_id, name FROM wohnungen ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$data = [
    'projects' => $projects,
    'objects' => [],
    'units' => []
];

foreach($objects as $o) {
    if (!isset($data['objects'][$o['projekt_id']])) $data['objects'][$o['projekt_id']] = [];
    $data['objects'][$o['projekt_id']][] = $o;
}

foreach($units as $u) {
    if (!isset($data['units'][$u['objekt_id']])) $data['units'][$u['objekt_id']] = [];
    $data['units'][$u['objekt_id']][] = $u;
}

echo "const HIERARCHY_DATA = " . json_encode($data, JSON_UNESCAPED_UNICODE) . ";\n";
?>
