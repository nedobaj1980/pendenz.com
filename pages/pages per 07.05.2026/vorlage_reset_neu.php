<?php
if(session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_login();

$alteVorlageId = 1; // ID der alten Vorlage
$neueVorlageName = "Architektur Standard Neu";

// --- 1. Alte Vorlage löschen ---

// Unterkategorien löschen
$stmt = $mysqli->prepare("DELETE FROM unterkategorien WHERE vorlage_id=?");
$stmt->bind_param("i",$alteVorlageId);
$stmt->execute();
$stmt->close();

// Vorlage löschen
$stmt = $mysqli->prepare("DELETE FROM struktur_vorlagen WHERE id=?");
$stmt->bind_param("i",$alteVorlageId);
$stmt->execute();
$stmt->close();

// Optional: physische Ordner löschen
$folder = __DIR__."/../uploads/vorlagen/{$alteVorlageId}";
if(is_dir($folder)){
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach($files as $fileinfo){
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        $todo($fileinfo->getRealPath());
    }
    rmdir($folder);
}

// --- 2. Neue Vorlage anlegen ---
$stmt = $mysqli->prepare("INSERT INTO struktur_vorlagen (name) VALUES (?)");
$stmt->bind_param("s",$neueVorlageName);
$stmt->execute();
$neueVorlageId = $stmt->insert_id;
$stmt->close();

// --- 3. Unterkategorien hinzufügen (max 10 pro Ebene) ---
$hauptkategorien = [
    'allgemeinraeume' => ['Lobby','Treppenhaus','Technikraum'],
    'wohnungen' => ['Wohnung 1','Wohnung 2','Wohnung 3'],
    'mieter' => ['Hauptmieter','Untermieter']
];

$position = 1;

foreach($hauptkategorien as $parentVar => $kinder){
    // Hauptknoten
    $stmt = $mysqli->prepare("INSERT INTO unterkategorien (vorlage_id, parent_id, name_variable, label_default, position) VALUES (?,?,?,?,?)");
    $stmt->bind_param("iissi",$neueVorlageId,$null,$parentVar,$parentVar,$position);
    $null = NULL;
    $stmt->execute();
    $hauptId = $stmt->insert_id;
    $stmt->close();

    $kindPos = 1;
    foreach($kinder as $kindName){
        if($kindPos>10) break; // max 10 pro Ebene
        $stmt = $mysqli->prepare("INSERT INTO unterkategorien (vorlage_id, parent_id, name_variable, label_default, position) VALUES (?,?,?,?,?)");
        $stmt->bind_param("iissi",$neueVorlageId,$hauptId,$kindName,$kindName,$kindPos);
        $stmt->execute();
        $stmt->close();
        $kindPos++;
    }
    $position++;
}

echo "✅ Alte Vorlage gelöscht und neue Vorlage '{$neueVorlageName}' erstellt. Neue Vorlage-ID: {$neueVorlageId}";
