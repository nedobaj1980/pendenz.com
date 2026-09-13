<?php
// pages/sync_drive_real.php
require_once __DIR__ . '/../config.php';
$base = 'C:/Users/Nedim/Google Drive-Streaming/Meine Ablage/Helvetic Immo Treuhand';
if (!is_dir($base)) die("Base dir not found");
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$projectsInserted = 0;
$objectsInserted = 0;
$items = scandir($base);
foreach ($items as $p) {
    if ($p === '.' || $p === '..') continue;
    $pPath = $base . '/' . $p;
    if (is_dir($pPath)) {
        // 1. Projekt anlegen
        $stP = $mysqli->prepare("INSERT INTO projekte (name, root_path) VALUES (?, ?)");
        $stP->bind_param("ss", $p, $pPath);
        $stP->execute();
        $pid = $mysqli->insert_id;
        $stP->close();
        $projectsInserted++;
        // 2. Objekt anlegen (gleicher Name wie Projekt)
        $stO = $mysqli->prepare("INSERT INTO objekte (projekt_id, name) VALUES (?, ?)");
        $stO->bind_param("is", $pid, $p);
        $stO->execute();
        $objectsInserted++;
        $stO->close();
    }
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
echo "OK: $projectsInserted Projects, $objectsInserted Objects Created.";
