---
name: drive-filesystem-manager
description: >-
  Procedures for managing the local Google Drive mount, file archiving, directory tree synchronization, and fs_nodes database indexing in pendenz.com. Use when saving files to Drive, scanning folders, or debugging file access.
---

# Google Drive & File System Manager (pendenz.com)

Dieses Runbook beschreibt die Architektur und Workflows zur Anbindung von Google Drive an die Plattform.

## 1. Mount-Architektur & Pfadauflösung
* **Standard-Mount**: `G:\Meine Ablage\Helvetic Immo Treuhand`
* **Robuste Pfadfindung**:
  * Immer `project_root_path($mysqli, $projectId)` aus `includes/fs.php` nutzen.
  * Diese Funktion prüft automatisch alternative Laufwerksbuchstaben (`G:`, `C:`, `D:`, `E:`, `F:`) und stellt sicher, dass der Pfad existiert.
  * Sollte kein Google Drive gemountet sein, muss ein Graceful Fallback auf `uploads/` im Webroot greifen.

## 2. Speicherorte nach Dokumententyp
* **Mietverträge**:
  `<ProjektRoot>/<Objekt>/10_Mietsache/<Einheit>/04_Vertraege/`
* **Abnahmeprotokolle**:
  `<ProjektRoot>/<Objekt>/10_Mietsache/<Einheit>/05_Abnahmen/`
* **Mieterspiegel**:
  `<ProjektRoot>/Mieterspiegel_<ProjektName>_<Ymd_His>.csv`
* **Liegenschaftsabrechnung**:
  `<ProjektRoot>/Liegenschaftsabrechnung_<ProjektName>_<Jahr>_<Ymd_His>.csv`
* **Pendenzenlisten-PDF**:
  `<ProjektRoot>/00_Pool/Pendenzenliste_<ProjektName>_<Ymd_His>.pdf`

## 3. Datenbank-Indexierung (`fs_nodes`)
Nach jeder Dateiablage auf Google Drive:
1. `require_once __DIR__ . '/../includes/fs.php';`
2. `fs_scan_project($mysqli, $projectId);`
Dies stellt sicher, dass die neue Datei sofort im Web-Explorer (`pages/files.php`) angezeigt, durchsucht und heruntergeladen werden kann.
