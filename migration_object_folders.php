<?php
require_once "config.php";

echo "<h1>Migration: Objekt-Ordner (Haus-Ebene)</h1>";
echo "<pre>";

// 1. Spalte folder_name zu objekte hinzufügen
$mysqli->query("ALTER TABLE objekte ADD COLUMN folder_name VARCHAR(255) DEFAULT NULL AFTER name");
echo "Spalte 'folder_name' zu Tabelle 'objekte' hinzugefügt.\n";

// 2. Bestehende Objekte initialisieren (verwende Namen als Ordnername, falls leer)
$mysqli->query("UPDATE objekte SET folder_name = name WHERE folder_name IS NULL");
echo "Bestehende Objekt-Ordner initialisiert.\n";

echo "\nMigration abgeschlossen.";
echo "</pre>";
?>
