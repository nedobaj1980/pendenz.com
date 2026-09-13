<?php
$base = 'C:/Users/Nedim/Google Drive-Streaming/Meine Ablage/Helvetic Immo Treuhand';
if (!is_dir($base)) die("Base dir not found: $base");

$projects = [];
foreach (scandir($base) as $p) {
    if ($p === '.' || $p === '..') continue;
    $pPath = $base . '/' . $p;
    if (is_dir($pPath)) {
        $objects = [];
        foreach (scandir($pPath) as $o) {
            if ($o === '.' || $o === '..') continue;
            $oPath = $pPath . '/' . $o;
            if (is_dir($oPath)) {
                $units = [];
                $wPath = $oPath . '/Wohnungen';
                if (is_dir($wPath)) {
                    foreach (scandir($wPath) as $u) {
                        if ($u === '.' || $u === '..') continue;
                        if (is_dir($wPath . '/' . $u)) $units[] = $u;
                    }
                }
                $objects[] = ['name' => $o, 'units' => $units];
            }
        }
        $projects[] = ['name' => $p, 'objects' => $objects];
    }
}
echo json_encode($projects, JSON_PRETTY_PRINT);
