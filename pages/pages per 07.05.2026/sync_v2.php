<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config.php';

$base = 'C:/Users/Nedim/Google Drive-Streaming/Meine Ablage/Helvetic Immo Treuhand';
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");

$items = scandir($base);
$pCount = 0;
foreach ($items as $p) {
    if ($p === '.' || $p === '..') continue;
    $pPath = $base . '/' . $p;
    if (is_dir($pPath)) {
        // Projekt
        $qP = "INSERT INTO projekte (name, root_path) VALUES (?, ?)";
        $stP = $mysqli->prepare($qP);
        $stP->bind_param("ss", $p, $pPath);
        if (!$stP->execute()) { echo "P-Error: " . $stP->error; }
        $pid = $mysqli->insert_id;
        $stP->close();

        // Objekt
        $qO = "INSERT INTO objekte (projekt_id, name) VALUES (?, ?)";
        $stO = $mysqli->prepare($qO);
        $stO->bind_param("is", $pid, $p);
        if (!$stO->execute()) { echo "O-Error: " . $stO->error; }
        $stO->close();
        
        $pCount++;
    }
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
echo "SYNC_V2_SUCCESS: $pCount";
