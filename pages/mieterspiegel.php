<?php
// pages/mieterspiegel.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/room_taxonomy.php';
require_login();

// Initialisierung der Raum-Tabellen
raum_taxonomy_ensure_tables($mysqli);

// --- AUTO-MIGRATION (Ensure new columns exist) ---
$migration_cols = [
    'baujahr' => "INT NULL",
    'heizungsart' => "VARCHAR(100) NULL",
    'bodenbelag' => "VARCHAR(255) NULL",
    'kueche_details' => "TEXT NULL",
    'bad_details' => "TEXT NULL",
    'lift' => "TINYINT(1) DEFAULT 0",
    'barrierefrei' => "TINYINT(1) DEFAULT 0",
    'haustiere_erlaubt' => "TINYINT(1) DEFAULT 0",
    'waschmaschine' => "VARCHAR(100) NULL",
    'keller_vorhanden' => "TINYINT(1) DEFAULT 0",
    'parkplatz' => "VARCHAR(100) NULL",
    'minergie' => "TINYINT(1) DEFAULT 0",
    'glasfaser' => "TINYINT(1) DEFAULT 0",
    'besonnerung' => "VARCHAR(100) NULL",
    'aussicht' => "VARCHAR(100) NULL",
    'laermpegel' => "VARCHAR(100) NULL",
    'titelbild_pfad' => "VARCHAR(255) NULL",
    'grundriss_pfad' => "VARCHAR(255) NULL",
    'mietzins_netto_soll' => "DECIMAL(10,2) DEFAULT 0",
    'mietzins_nk_soll' => "DECIMAL(10,2) DEFAULT 0",
    'ausstattung_details' => "TEXT NULL",
    'available_from' => "VARCHAR(50) NULL"
];
foreach ($migration_cols as $col => $def) {
    if ($mysqli->query("SHOW COLUMNS FROM wohnungen LIKE '$col'")->num_rows === 0) {
        $mysqli->query("ALTER TABLE wohnungen ADD $col $def");
    }
}

$pid = (int)($_GET['projekt_id'] ?? 0);
if ($pid <= 0) {
    // Falls kein Projekt gewählt wurde, zeigen wir eine Auswahlseite an
    $allProjectsRes = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/nav_dispatch.php';
    ?>
    <div class="container" style="max-width:800px; margin-top:100px; text-align:center;">
        <div class="glass-card" style="padding:60px 40px; border-radius:40px; background:rgba(255,255,255,0.8); backdrop-filter:blur(20px); border:1px solid rgba(255,255,255,0.5); box-shadow:0 25px 50px -12px rgba(0,0,0,0.1);">
            <div style="background:#3b82f6; color:white; width:80px; height:80px; border-radius:24px; display:flex; align-items:center; justify-content:center; font-size:40px; margin: 0 auto 30px; box-shadow:0 15px 30px rgba(59,130,246,0.3);">📈</div>
            <h1 style="font-weight:900; letter-spacing:-1.5px; color:#0f172a; margin-bottom:15px; font-size:36px;">Mieterspiegel öffnen</h1>
            <p style="color:#64748b; font-weight:600; margin-bottom:40px; font-size:18px;">Bitte wählen Sie ein Projekt aus, um den Mieter- & Wohnungsspiegel anzuzeigen.</p>
            
            <div style="max-width:400px; margin:0 auto;">
                <select onchange="if(this.value) location.href='?projekt_id='+this.value" style="width:100%; padding:18px 25px; border-radius:20px; border:2px solid #e2e8f0; font-size:18px; font-weight:700; color:#1e293b; background:#fff; cursor:pointer; appearance:none; background-image:url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%233b82f6%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.3c0%204.9%201.8%209.1%205.4%2012.7l128%20128c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.4-12.8z%22%2F%3E%3C%2Fsvg%3E'); background-repeat:no-repeat; background-position:right%2025px%20top%2050%25; background-size:14px%20auto; transition:0.3s; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <option value="">-- Projekt auswählen --</option>
                    <?php while($pRow = $allProjectsRes->fetch_assoc()): ?>
                        <option value="<?= $pRow['id'] ?>"><?= htmlspecialchars($pRow['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div style="margin-top:50px; border-top:2px solid #f1f5f9; padding-top:30px;">
                <a href="projekte.php" style="color:#3b82f6; text-decoration:none; font-weight:800; font-size:15px; display:inline-flex; align-items:center; gap:8px;">
                    <span>📂 Zur Projektübersicht</span>
                    <span style="font-size:20px;">→</span>
                </a>
            </div>
        </div>
    </div>
    <style>
        select:hover { border-color: #3b82f6; box-shadow: 0 10px 15px -3px rgba(59,130,246,0.1); }
        .glass-card { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
    </style>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$projQ = $mysqli->query("SELECT name, root_path, wohnungen_rel_path FROM projekte WHERE id = $pid");
$proj = $projQ->fetch_assoc();
$projName = $proj['name'] ?? "Unbekanntes Projekt";
$relBase = $proj['root_path'] ?? '';
$unitsSubPath = $proj['wohnungen_rel_path'] ?? '';

// Alle Projekte für Schnellwahl
$allProjectsRes = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
$allProjects = [];
while($pRow = $allProjectsRes->fetch_assoc()) $allProjects[] = $pRow;

// Alle Benutzer für Mieter-Auswahl
$allUsersSearch = $mysqli->query("SELECT id, name, email FROM benutzer ORDER BY name ASC");
$allUsers = [];
while($uSearch = $allUsersSearch->fetch_assoc()) $allUsers[] = $uSearch;

// Pfad-Browser Logik
$browsePath = isset($_GET['browse_path']) ? ltrim(str_replace('\\','/', trim($_GET['browse_path'])), '/') : $unitsSubPath;
$absCurrent = fs_abs_from_rel($relBase, $browsePath);
$folders = [];
if ($absCurrent && is_dir($absCurrent)) {
    $items = @scandir($absCurrent) ?: [];
    foreach ($items as $item) {
        if ($item==='.' || $item==='..') continue;
        if (is_dir($absCurrent . DIRECTORY_SEPARATOR . $item)) $folders[] = $item;
    }
}

$message = "";

// Aktionen (Speichern von Änderungen)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_unit') {
        $name = trim($_POST['name'] ?? '');
        $obj_id = (int)($_POST['objekt_id'] ?? 0);
        if ($name !== '' && $obj_id > 0) {
            $stmt = $mysqli->prepare("INSERT INTO wohnungen (objekt_id, name) VALUES (?, ?)");
            $stmt->bind_param("is", $obj_id, $name);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                require_once __DIR__ . '/../includes/fs.php';
                ensure_unit_folder($mysqli, $new_id);
                $message = "✅ Einheit '$name' wurde erfolgreich angelegt und der entsprechende Ordner im Drive erstellt.";
            } else {
                $error = "❌ Fehler beim Anlegen: " . $mysqli->error;
            }
            $stmt->close();
        } else {
            $error = "❌ Bitte geben Sie einen Namen an und wählen Sie ein Objekt aus.";
        }
    }

    if ($action === 'fast_update') {
        if (isset($_POST['u']) && is_array($_POST['u'])) {
            foreach ($_POST['u'] as $wid => $data) {
                $wid = (int)$wid;
                $zimmer = (float)($data['zimmer'] ?? 0);
                $flaeche = (float)($data['flaeche'] ?? 0);
                $etage = $mysqli->real_escape_string($data['etage'] ?? '');
                
                $balkon = isset($data['balkon']) ? 1 : 0;
                $terrasse = isset($data['terrasse']) ? 1 : 0;
                $wintergarten = isset($data['wintergarten']) ? 1 : 0;
                $published = isset($data['is_published']) ? 1 : 0;
                $avail = $data['available_from'] ? "'".$mysqli->real_escape_string($data['available_from'])."'" : "NULL";

                $netto_soll = (float)($data['netto_soll'] ?? 0);
                $nk_soll = (float)($data['nk_soll'] ?? 0);

                $mysqli->query("UPDATE wohnungen SET 
                    zimmer = $zimmer, 
                    flaeche = $flaeche, 
                    mietzins_netto_soll = $netto_soll,
                    mietzins_nk_soll = $nk_soll,
                    etage = '$etage', 
                    balkon = $balkon, 
                    terrasse = $terrasse, 
                    wintergarten = $wintergarten, 
                    is_published = $published, 
                    available_from = $avail,
                    baujahr = ".((int)($data['baujahr'] ?? 0)).",
                    heizungsart = '".$mysqli->real_escape_string($data['heizungsart'] ?? '')."',
                    ausstattung_details = '".$mysqli->real_escape_string($data['ausstattung'] ?? '')."'
                    WHERE id = $wid");

                // Ordner-Automatisierung & Umbenennung
                require_once __DIR__ . '/../includes/fs.php';
                $sync = ensure_unit_folder($mysqli, $wid);
                
                // Wir erzwingen nun, dass folder_name dem aktuellen Namen entspricht
                // (Logik ist jetzt tiefer in ensure_unit_folder integriert)

                if (isset($data['netto']) || isset($data['nk'])) {
                    $netto = (float)($data['netto'] ?? 0);
                    $nk = (float)($data['nk'] ?? 0);
                    
                    $check = $mysqli->query("SELECT id FROM wohnung_mieter WHERE wohnung_id = $wid AND status = 'aktiv'");
                    if ($check->num_rows > 0) {
                        $mysqli->query("UPDATE wohnung_mieter SET mietzins_netto = $netto, nk_akonto = $nk WHERE wohnung_id = $wid AND status = 'aktiv'");
                    } else {
                        $mysqli->query("INSERT INTO wohnung_mieter (wohnung_id, mietzins_netto, nk_akonto, status, startdatum) VALUES ($wid, $netto, $nk, 'aktiv', CURDATE())");
                    }
                }
            }
            $message = "✅ Alle Änderungen wurden erfolgreich gespeichert.";
        }
    }

    if ($action === 'sync_fs') {
        require_once __DIR__ . '/../includes/fs.php';
        $prune = isset($_POST['prune']) && $_POST['prune'] == '1';
        $stats = sync_project_folders($mysqli, $pid, $prune);
        $message = "📂 Dateisystem-Abgleich abgeschlossen: " . $stats['total'] . " Einheiten geprüft, " . $stats['created_disk'] . " Ordner erstellt, " . $stats['created_db'] . " Wohnungen importiert, " . $stats['deleted_db'] . " aus DB entfernt.";
        if($stats['errors'] > 0) $error = "⚠️ " . $stats['errors'] . " Fehler aufgetreten.";
    }

    if ($action === 'delete_unit_force' || $action === 'delete_unit') {
        $wid = (int)($_POST['w_id'] ?? 0);
        if ($wid > 0) {
            try {
                // 1. Level 3: Enkel-Elemente (Ganz tief)
                if (table_exists($mysqli, 'gegenstaende')) {
                    $mysqli->query("DELETE FROM gegenstaende WHERE zimmer_id IN (SELECT id FROM zimmer WHERE wohnung_id = $wid)");
                }
                if (table_exists($mysqli, 'abnahme_mangel_link')) {
                    $mysqli->query("DELETE FROM abnahme_mangel_link WHERE abnahme_id IN (SELECT id FROM abnahmen WHERE wohneinheit_id = $wid)");
                }

                // 2. Level 2: Kinder-Elemente
                if (table_exists($mysqli, 'zimmer')) {
                    $mysqli->query("DELETE FROM zimmer WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'abnahmen')) {
                    $mysqli->query("DELETE FROM abnahmen WHERE wohneinheit_id = $wid");
                }
                if (table_exists($mysqli, 'mietvertraege')) {
                    $mysqli->query("DELETE FROM mietvertraege WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'mietverhaeltnisse')) {
                    $mysqli->query("DELETE FROM mietverhaeltnisse WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'wohnung_bilder')) {
                    $mysqli->query("DELETE FROM wohnung_bilder WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'wohnung_dokumente')) {
                    $mysqli->query("DELETE FROM wohnung_dokumente WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'miet_interessenten')) {
                    $mysqli->query("DELETE FROM miet_interessenten WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'interessenten')) {
                    $mysqli->query("DELETE FROM interessenten WHERE wohnung_id = $wid");
                }
                if (table_exists($mysqli, 'wohnung_mieter')) {
                    $mysqli->query("DELETE FROM wohnung_mieter WHERE wohnung_id = $wid");
                }

                // 3. Pendenzen: Nur Entkoppeln (Single Source of Truth)
                if (column_exists($mysqli, 'pendenzen', 'wohnung_id')) {
                    $mysqli->query("UPDATE pendenzen SET wohnung_id = NULL WHERE wohnung_id = $wid");
                }

                // 4. Final: Die Wohnung selbst
                if ($mysqli->query("DELETE FROM wohnungen WHERE id = $wid")) {
                    $message = "✅ Einheit wurde inklusive aller Verknüpfungen erfolgreich gelöscht.";
                } else {
                    throw new Exception($mysqli->error);
                }
            } catch (Exception $e) {
                $error = "❌ Löschen fehlgeschlagen: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'sync') {
        $syncPath = $_POST['sync_path'] ?? $unitsSubPath;
        if (!$relBase || !$syncPath) {
            $message = "❌ Bitte zuerst einen Ordner im Browser unten wählen.";
        } else {
            $absUnits = fs_abs_from_rel($relBase, $syncPath);
            $mysqli->query("UPDATE projekte SET wohnungen_rel_path = '".$mysqli->real_escape_string($syncPath)."' WHERE id = $pid");
            $unitsSubPath = $syncPath;
            if (is_dir($absUnits)) {
                $foundFolders = array_filter(glob($absUnits . '/*'), 'is_dir');
                $syncedCount = 0;
                $pathParts = explode('/', str_replace('\\', '/', $syncPath));
                $objName = (count($pathParts) >= 1) ? end($pathParts) : "Standard Objekt";

                $mysqli->query("INSERT IGNORE INTO objekte (projekt_id, name) VALUES ($pid, '".$mysqli->real_escape_string($objName)."')");
                $objIdRes = $mysqli->query("SELECT id FROM objekte WHERE projekt_id = $pid AND name = '".$mysqli->real_escape_string($objName)."'");
                $objId = $objIdRes->fetch_assoc()['id'];

                foreach($foundFolders as $ff) {
                    $folderName = basename($ff);
                    if (sync_folder_as_unit($mysqli, $folderName, $objId, $absUnits, $pid)) $syncedCount++;
                }
                $message = "✅ Synchronisation abgeschlossen. $syncedCount neue Einheiten gefunden inkl. Mieter-Historie.";
            }
        }
    }

    if ($action === 'assign_tenant') {
        $wid = (int)($_POST['w_id'] ?? 0);
        $benutzer_id = (int)($_POST['benutzer_id'] ?? 0);
        $netto = (float)($_POST['mietzins_netto'] ?? 0);
        $nk = (float)($_POST['nk_akonto'] ?? 0);
        $rawBeginn = $_POST['beginn'] ?? date('Y-m-d');
        $beginn = date('Y-m-d', strtotime($rawBeginn) ?: time());
        
        if ($wid > 0 && $benutzer_id > 0) {
            $stmtHist = $mysqli->prepare("UPDATE wohnung_mieter SET status = 'historisch', enddatum = ? WHERE wohnung_id = ? AND status = 'aktiv'");
            $stmtHist->bind_param("si", $beginn, $wid);
            $stmtHist->execute();
            $stmtHist->close();
            
            $wRes = $mysqli->query("SELECT w.folder_name, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE w.id = $wid");
            $wData = $wRes->fetch_assoc();
            $uNameRes = $mysqli->query("SELECT name FROM benutzer WHERE id = $benutzer_id");
            $userName = $uNameRes->fetch_assoc()['name'] ?? "Mieter_$benutzer_id";
            $safeUserName = str_replace(['/', '\\', ',', '.', ' '], '_', $userName);
            
            $mieterFolder = "Mieter_" . $safeUserName;
            
            // Korrigierter Pfad für Wohnungen
            $objPart = $wData['obj_name'] ? ($wData['obj_name'] . "/Wohnungen/") : "Wohnungen/";
            $relMieterPath = $unitsSubPath . "/" . $objPart . $wData['folder_name'] . "/" . $mieterFolder;
            $absMieter = fs_abs_from_rel($relBase, $relMieterPath);

            // 1. Suche Ordner im Pool (Interessenten)
            $root = project_root_path($mysqli, $pid);
            $poolFolderFound = "";
            if ($root) {
                $poolDir = $root . DIRECTORY_SEPARATOR . '00_Pool' . DIRECTORY_SEPARATOR . 'Interessenten';
                if (is_dir($poolDir)) {
                    $items = @scandir($poolDir);
                    if ($items) {
                        foreach($items as $item) {
                            if (strpos($item, "_" . $benutzer_id) !== false) {
                                $poolFolderFound = $item;
                                break;
                            }
                        }
                    }
                }
            }

            if ($absMieter) {
                if (!is_dir(dirname($absMieter))) @mkdir(dirname($absMieter), 0777, true);
                
                if ($poolFolderFound) {
                    $srcPool = $poolDir . DIRECTORY_SEPARATOR . $poolFolderFound;
                    @rename($srcPool, $absMieter); // Verschiebe Pool-Ordner zur Wohnung
                } elseif (!is_dir($absMieter)) {
                    @mkdir($absMieter, 0777, true); // Neuer Ordner falls kein Pool-Ordner da
                }
            }
            
            // 2. Benutzer-Phasen Update
            $mysqli->query("UPDATE benutzer SET mieter_phase = 'mieter' WHERE id = $benutzer_id");

            $qi = $mysqli->prepare("INSERT INTO wohnung_mieter (wohnung_id, benutzer_id, mieter_name, mietzins_netto, nk_akonto, rolle, startdatum, status) 
                                    VALUES (?, ?, ?, ?, ?, 'mieter', ?, 'aktiv')");
            $qi->bind_param("iisdds", $wid, $benutzer_id, $userName, $netto, $nk, $beginn);
            if ($qi->execute()) {
                $message = "✅ Mieter '$userName' erfolgreich zugewiesen und Ordner verschoben.";
            } else {
                $message = "❌ Fehler bei Zuweisung: " . $qi->error;
            }
        }
    }

    if ($action === 'move_to_history') {
        $wmid = (int)($_POST['pv_id'] ?? 0); // Behalte param name zur Kompabilität
        $q = $mysqli->query("SELECT wm.*, w.folder_name, o.name as obj_name FROM wohnung_mieter wm JOIN wohnungen w ON wm.wohnung_id = w.id JOIN objekte o ON w.objekt_id = o.id WHERE wm.id = $wmid");
        if ($wm = $q->fetch_assoc()) {
            // Wir suchen den absoluten Pfad der Wohnung, um den Mieterordner zu finden
            $absUnit = fs_abs_from_rel($relBase, $unitsSubPath . "/" . $wm['obj_name'] . "/Wohnungen/" . $wm['folder_name']);
            
            // Suche den Ordner des Mieters (fängt mit Mieter_ an)
            $mFolder = "";
            $items = @scandir($absUnit);
            if ($items) {
                foreach($items as $item) {
                    if (strpos($item, 'Mieter_') === 0) {
                        $mFolder = $item;
                        break;
                    }
                }
            }

            if ($mFolder) {
                $srcAbs = $absUnit . DIRECTORY_SEPARATOR . $mFolder;
                $destRel = $unitsSubPath . "/" . $wm['obj_name'] . "/Wohnungen/" . $wm['folder_name'] . "/Vormieter/" . $mFolder . "_bis_" . date('Y-m-d');
                $destAbs = fs_abs_from_rel($relBase, $destRel);
                
                if (!is_dir(dirname($destAbs))) @mkdir(dirname($destAbs), 0777, true);
                if (@rename($srcAbs, $destAbs)) {
                    $mysqli->query("UPDATE wohnung_mieter SET status = 'historisch', enddatum = CURRENT_DATE WHERE id = $wmid");
                    $message = "📦 Mieter erfolgreich in Historie verschoben und Ordner archiviert.";
                }
            } else {
                // Nur DB Status ändern falls kein Ordner gefunden
                $mysqli->query("UPDATE wohnung_mieter SET status = 'historisch', enddatum = CURRENT_DATE WHERE id = $wmid");
                $message = "📦 Mieter-Status auf historisch gesetzt (kein physischer Ordner gefunden).";
            }
        }
    }

    if ($action === 'create_missing_folders') {
        $res = $mysqli->query("SELECT w.*, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE o.projekt_id = $pid");
        $createdCount = 0;
        if ($relBase) {
            $baseAbsPath = fs_abs_from_rel($relBase, $unitsSubPath);
            if ($baseAbsPath && !is_dir($baseAbsPath)) @mkdir($baseAbsPath, 0777, true);
            
            while($w = $res->fetch_assoc()) {
                $wid = $w['id'];
                if (empty($w['folder_name'])) {
                    $safeName = str_replace(['/', '\\', ',', '.'], '_', $w['name']);
                    $fName = sprintf("%02d_W-%s, %s %s Zi. Wohnung", $wid, $safeName, $w['etage'] ?: 'unbekannt', $w['zimmer'] ?: '0');
                    $mysqli->query("UPDATE wohnungen SET folder_name = '".$mysqli->real_escape_string($fName)."' WHERE id = $wid");
                } else {
                    $fName = $w['folder_name'];
                }
                
                $finalAbsPath = fs_abs_from_rel($baseAbsPath, $fName);
                if ($finalAbsPath && !is_dir($finalAbsPath)) {
                    if (@mkdir($finalAbsPath, 0777, true)) $createdCount++;
                }
            }
            $message = "✅ $createdCount neue Ordner wurden erfolgreich angelegt.";
        } else {
            $message = "❌ Projekt-Root-Pfad nicht konfiguriert.";
        }
    }

    if ($action === 'sync_single') {
        $folderName = $_POST['folder_name'] ?? '';
        $syncPath = $_POST['sync_path'] ?? '';
        if ($folderName && $syncPath) {
            $pathParts = explode('/', str_replace('\\', '/', $syncPath));
            $objName = (count($pathParts) >= 1) ? end($pathParts) : "Objekt " . end($pathParts);
            
            $mysqli->query("INSERT IGNORE INTO objekte (projekt_id, name) VALUES ($pid, '".$mysqli->real_escape_string($objName)."')");
            $objIdRes = $mysqli->query("SELECT id FROM objekte WHERE projekt_id = $pid AND name = '".$mysqli->real_escape_string($objName)."'");
            $objId = $objIdRes->fetch_assoc()['id'];

            $absUnits = fs_abs_from_rel($relBase, dirname($syncPath) . "/" . basename($syncPath)); // Stabilisierung
            $absUnits = fs_abs_from_rel($relBase, $syncPath);

            if (sync_folder_as_unit($mysqli, $folderName, $objId, $absUnits, $pid)) {
                $message = "✅ Wohnung '$folderName' erfolgreich hinzugefügt/aktualisiert inkl. Mieter-Struktur.";
            } else {
                $message = "ℹ️ Wohnung war bereits vorhanden oder konnte nicht verarbeitet werden.";
            }
        }
    }

    if ($action === 'save_unit_dash') {
        $wid = (int)($_POST['w_id'] ?? 0);
        $netto = (float)($_POST['netto_soll'] ?? 0);
        $nk = (float)($_POST['nk_soll'] ?? 0);
        $desc = $mysqli->real_escape_string($_POST['beschreibung'] ?? '');
        $pub = isset($_POST['is_published']) ? 1 : 0;
        $tbild = $mysqli->real_escape_string($_POST['titelbild_pfad'] ?? '');
        $gpfd = $mysqli->real_escape_string($_POST['grundriss_pfad'] ?? '');
        
        $mysqli->query("UPDATE wohnungen SET 
            mietzins_netto_soll = $netto, 
            mietzins_nk_soll = $nk, 
            ausstattung_details = '$desc', 
            is_published = $pub,
            titelbild_pfad = '$tbild',
            grundriss_pfad = '$gpfd'
            WHERE id = $wid");
        
        $message = "✅ Einheitsdetails & Medien-Pfade erfolgreich gespeichert.";
    }

    if ($action === 'delete_applicant') {
        $aid = (int)($_POST['a_id'] ?? 0);
        $tbl = "miet_interessenten";
        $c = $mysqli->query("SHOW TABLES LIKE 'miet_interessenten'");
        if (!$c || $c->num_rows == 0) {
            $c2 = $mysqli->query("SHOW TABLES LIKE 'interessenten'");
            if ($c2 && $c2->num_rows > 0) $tbl = "interessenten";
        }
        $mysqli->query("DELETE FROM $tbl WHERE id = $aid");
        $message = "✅ Interessent wurde gelöscht.";
    }

    if ($action === 'save_unit_specs') {
        $wid = (int)($_POST['w_id'] ?? 0);
        $zimmer = (float)($_POST['zimmer'] ?? 0);
        $flaeche = (float)($_POST['flaeche'] ?? 0);
        $etage = $mysqli->real_escape_string($_POST['etage'] ?? '');
        $baujahr = (int)($_POST['baujahr'] ?? 0);
        $heizung = $mysqli->real_escape_string($_POST['heizungsart'] ?? '');
        
        $balkon = isset($_POST['balkon']) ? 1 : 0;
        $terrasse = isset($_POST['terrasse']) ? 1 : 0;
        $wintergarten = isset($_POST['wintergarten']) ? 1 : 0;
        $lift = isset($_POST['lift']) ? 1 : 0;
        $keller = isset($_POST['keller_vorhanden']) ? 1 : 0;
        $barriere = isset($_POST['barrierefrei']) ? 1 : 0;
        
        $mysqli->query("UPDATE wohnungen SET 
            zimmer = $zimmer, 
            flaeche = $flaeche, 
            etage = '$etage', 
            baujahr = $baujahr,
            heizungsart = '$heizung',
            balkon = $balkon, 
            terrasse = $terrasse, 
            wintergarten = $wintergarten,
            lift = $lift,
            keller_vorhanden = $keller,
            barrierefrei = $barriere
            WHERE id = $wid");
        
        $message = "✅ Einheits-Spezifikationen erfolgreich gespeichert.";
    }
}

function sync_folder_as_unit($mysqli, $folderName, $objId, $absParentPath, $pid) {
    global $relBase, $unitsSubPath;
    $wName = $folderName;
    $zimmer = 0;
    $etage = '';
    if (preg_match('/^(?:\d+_)?(.+?),\s*(.+?)\s+(\d+(?:\.\d+)?)\s*Zi\./i', $folderName, $matches)) {
        $wName = trim($matches[1]);
        $etage = trim($matches[2]);
        $zimmer = (float)$matches[3];
    }
    
    // Wohnung in DB
    $check = $mysqli->query("SELECT id FROM wohnungen WHERE objekt_id = $objId AND (folder_name = '".$mysqli->real_escape_string($folderName)."' OR name = '".$mysqli->real_escape_string($wName)."')");
    if ($check->num_rows === 0) {
        $mysqli->query("INSERT INTO wohnungen (objekt_id, name, status, folder_name, zimmer, etage) 
                        VALUES ($objId, '".$mysqli->real_escape_string($wName)."', 'verfuegbar', '".$mysqli->real_escape_string($folderName)."', $zimmer, '".$mysqli->real_escape_string($etage)."')");
        $wid = $mysqli->insert_id;
        $isNew = true;
    } else {
        $wid = $check->fetch_assoc()['id'];
        $mysqli->query("UPDATE wohnungen SET folder_name = '".$mysqli->real_escape_string($folderName)."' WHERE id = $wid AND (folder_name IS NULL OR folder_name = '')");
        $isNew = false;
    }

    // Rekursiver Mieter-Import
    $absUnitPath = $absParentPath . DIRECTORY_SEPARATOR . $folderName;
    if (is_dir($absUnitPath)) {
        // Wir brauchen den relativen Pfad der Wohnung für die DB (relativ zum Projekt-Root $relBase)
        // Aber für die Mieter-Einträge brauchen wir den Pfad ab Root.
        $projQ = $mysqli->query("SELECT root_path, wohnungen_rel_path FROM projekte WHERE id = $pid");
        $proj = $projQ->fetch_assoc();
        $uRelBase = $proj['wohnungen_rel_path']; // z.B. 10_Wohnungen/Objekt_A
        
        // Objekt-Name finden für Pfad-Konstruktion
        $oRes = $mysqli->query("SELECT name FROM objekte WHERE id = $objId");
        $oName = $oRes->fetch_assoc()['name'] ?? 'Unknown';

        $items = @scandir($absUnitPath) ?: [];
        foreach($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $absUnitPath . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                if (strtolower($item) === 'vormieter') {
                    // Historie scannen
                    $vItems = @scandir($full) ?: [];
                    foreach($vItems as $vi) {
                        if ($vi === '.' || $vi === '..') continue;
                        if (is_dir($full . DIRECTORY_SEPARATOR . $vi)) {
                            $mPath = $uRelBase . "/" . $oName . "/Wohnungen/" . $folderName . "/Vormieter/" . $vi;
                            $mysqli->query("INSERT IGNORE INTO projekt_verknuepfungen (projekt_id, einheit_id, status, fs_rel_path, beginn) 
                                            VALUES ($pid, $wid, 'historisch', '".$mysqli->real_escape_string($mPath)."', '1970-01-01')");
                        }
                    }
                } else {
                    // Potenziell aktiver Mieter
                    $mPath = $uRelBase . "/" . $oName . "/Wohnungen/" . $folderName . "/" . $item;
                    $mysqli->query("INSERT IGNORE INTO projekt_verknuepfungen (projekt_id, einheit_id, status, fs_rel_path, beginn) 
                                    VALUES ($pid, $wid, 'aktiv', '".$mysqli->real_escape_string($mPath)."', '".date('Y-m-d')."')");
                }
            }
        }
    }

    return $isNew;
}

// Daten abrufen - Basierend auf dem markierten Ordner
$units = [];
$res = $mysqli->query("
    SELECT o.name as obj_name, w.id as w_id, w.name as w_name, w.zimmer, w.flaeche, w.etage, w.folder_name, w.balkon, w.terrasse, w.wintergarten,
           w.is_published, w.available_from, w.apply_token, w.mietzins_netto_soll, w.mietzins_nk_soll, w.ausstattung_details,
           w.baujahr, w.heizungsart, w.lift, w.keller_vorhanden, w.barrierefrei
    FROM wohnungen w 
    JOIN objekte o ON w.objekt_id = o.id 
    WHERE o.projekt_id = $pid 
    ORDER BY o.name, w.name
");
require_once __DIR__ . '/../includes/fs.php';
while($row = $res->fetch_assoc()) {
    $wid = $row['w_id'];
    
    // Unified Path Service
    $pathState = fs_get_entity_path($mysqli, 'wohnung', $wid);
    $row['folder_exists'] = ($pathState['abs'] && is_dir($pathState['abs']));
    $row['rel_path'] = $pathState['rel'];

    // Aktueller Mieter (Unified Contacts)
    $mRes = $mysqli->query("
        SELECT wm.id as pv_id, COALESCE(k.nachname, wm.mieter_name) as nachname, k.vorname, k.email, k.telefon,
               wm.mietzins_netto, wm.nk_akonto as mietzins_nk, wm.startdatum as einzug_datum,
               k.id as kontakt_id
        FROM wohnung_mieter wm 
        LEFT JOIN kontakte k ON wm.kontakt_id = k.id 
        WHERE wm.wohnung_id = $wid AND wm.status = 'aktiv' 
        LIMIT 1
    ");
    if($mRes && $mRes->num_rows > 0) {
        $mr = $mRes->fetch_assoc();
        $mr['name'] = trim(($mr['vorname'] ?? '') . ' ' . ($mr['nachname'] ?? ''));
        if(empty($mr['name'])) $mr['name'] = "Unbekannter Mieter";
        $row['mieter'] = $mr;
    } else {
        $row['mieter'] = null;
    }
    
    // Historie (Unified Contacts)
    $hRes = $mysqli->query("
        SELECT COALESCE(k.nachname, wm.mieter_name) as nachname, k.vorname, wm.mietzins_netto, wm.startdatum as einzug_datum, wm.enddatum as auszug_datum
        FROM wohnung_mieter wm 
        LEFT JOIN kontakte k ON wm.kontakt_id = k.id 
        WHERE wm.wohnung_id = $wid AND wm.status = 'historisch' 
        ORDER BY wm.enddatum DESC
    ");
    $history = [];
    if($hRes) {
        while($hr = $hRes->fetch_assoc()) {
           $hr['name'] = trim(($hr['vorname'] ?? '') . ' ' . ($hr['nachname'] ?? ''));
           if(empty($hr['name'])) $hr['name'] = "Ehem. Mieter";
           $history[] = $hr;
        }
    }
    $row['history'] = $history;

    // Anfragen
    $row['applicant_count'] = 0;
    // Prüfe beide Tabellen-Namen
    $intTable = "";
    $checkMiInt = $mysqli->query("SHOW TABLES LIKE 'miet_interessenten'");
    if($checkMiInt && $checkMiInt->num_rows > 0) $intTable = "miet_interessenten";
    else {
        $checkInt = $mysqli->query("SHOW TABLES LIKE 'interessenten'");
        if($checkInt && $checkInt->num_rows > 0) $intTable = "interessenten";
    }

    if($intTable) {
        $aRes = $mysqli->query("SELECT COUNT(*) as applicant_count FROM $intTable WHERE wohnung_id = $wid AND status != 'abgelehnt'");
        if($aRes) {
           $aRow = $aRes->fetch_assoc();
           $row['applicant_count'] = $aRow['applicant_count'] ?? 0;
        }
    }
    
    $units[] = $row;
}

// Global consistency check
$orphans = [];
foreach($units as $u) {
    if(!($u['folder_exists'] ?? false)) $orphans[] = $u;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');

    body { 
        background: radial-gradient(at 0% 0%, rgba(241,245,249,1) 0, transparent 50%), 
                    radial-gradient(at 50% 0%, rgba(219,234,254,1) 0, transparent 50%), 
                    radial-gradient(at 100% 0%, rgba(241,245,249,1) 0, transparent 50%);
        background-attachment: fixed;
        min-height: 100vh;
        color: #1e293b; 
        font-family: 'Outfit', sans-serif;
    }

    .spiegel-wrap { max-width: 98%; margin: 40px auto; padding: 0 20px; }
    .top-bar { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 35px; }
    
    .glass-card { 
        background: rgba(255, 255, 255, 0.7); 
        backdrop-filter: blur(12px); 
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.3); 
        border-radius: 24px; 
        box-shadow: 0 10px 40px -10px rgba(31, 38, 135, 0.08);
        padding: 30px;
        margin-bottom: 30px;
        overflow: hidden;
    }

    .spiegel-table { 
        width: 100%; 
        border-collapse: collapse; 
        background: #fff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .spiegel-table thead th { 
        padding: 12px 15px; 
        background: #f8fafc;
        color: #475569; 
        font-size: 11px; 
        text-transform: uppercase; 
        letter-spacing: 0.1em; 
        text-align: left;
        font-weight: 800;
        border-bottom: 2px solid #e2e8f0;
    }
    
    .spiegel-table tbody td { 
        padding: 12px 15px; 
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        transition: 0.15s;
        font-size: 13px;
    }
    .spiegel-table tbody tr:last-child td { border-bottom: none; }
    
    .spiegel-table tr.row-main:hover td { 
        background: #f1f5f9; 
    }
    
    .badge-unit { font-size: 15px; font-weight: 700; color: #1e293b; letter-spacing: -0.01em; }
    .badge-obj { font-size: 10px; color: #64748b; display: block; font-weight: 700; text-transform: uppercase; margin-bottom: 1px; }
    
    .status-pill { 
        padding: 6px 14px; 
        border-radius: 30px; 
        font-size: 10px; 
        font-weight: 800; 
        white-space:nowrap;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        border: 1px solid transparent;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .status-vermietet { background: #dcfce7; color: #15803d; border-color: #bef26444; }
    .status-frei { background: #fee2e2; color: #b91c1c; border-color: #f8717133; }
    .status-reserviert { background: #fef9c3; color: #a16207; border-color: #facc1533; }

    .row-history { display: none; }
    .history-inner { padding: 20px; background: #f8fafc; border-radius: 0 0 12px 12px; border: 1px solid #e2e8f0; border-top: none; }

    .input-edit { 
        border: 1px solid #e2e8f0; 
        background: #fff; 
        padding: 6px 10px; 
        border-radius: 8px; 
        width: 100%; 
        font-family: inherit; 
        font-size: 13px; 
        font-weight: 500;
        transition: 0.2s; 
    }
    .input-edit:hover { border-color: #cbd5e1; }
    .input-edit:focus { 
        border-color: #3b82f6; 
        outline: none; 
        box-shadow: 0 0 0 3px rgba(59,130,246,0.1); 
    }

    .unit-modal-grid { display: grid; grid-template-columns: 320px 1fr; gap: 0; min-height: 650px; }
    .unit-sidebar { 
        background: rgba(248, 250, 252, 0.5); 
        border-right: 1px solid rgba(226, 232, 240, 0.8); 
        padding: 40px 30px; 
    }
    .unit-tabs { list-style:none; padding:0; margin:0; }
    .unit-tabs li { 
        padding: 16px 20px; 
        border-radius: 16px; 
        cursor: pointer; 
        font-weight: 700; 
        color: #64748b; 
        margin-bottom: 10px; 
        transition: 0.3s; 
        display: flex; 
        align-items: center; 
        gap: 14px; 
        font-size: 15px;
    }
    .unit-tabs li:hover { background: rgba(255,255,255,0.9); color: #1e293b; transform: translateX(5px); }
    .unit-tabs li.active { 
        background: #fff; 
        color: #3b82f6; 
        box-shadow: 0 10px 25px -5px rgba(59,130,246,0.15);
        transform: translateX(12px);
    }
    
    .tab-content { display:none; animation: slideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); padding: 40px; }
    @keyframes slideIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
    
    .btn-icon { 
        width: 38px; 
        height: 38px; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        border-radius: 12px; 
        border: 1px solid rgba(0,0,0,0.05); 
        background: #fff; 
        cursor: pointer; 
        transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
        font-size: 18px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .btn-icon:hover { 
        border-color: #3b82f6; 
        color: #3b82f6; 
        background: #fff; 
        transform: translateY(-3px) rotate(8deg) scale(1.1);
        box-shadow: 0 8px 15px rgba(59,130,246,0.15);
    }
    
    .folder-nav { background: rgba(255,255,255,0.4); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.5); border-radius: 20px; padding: 25px; margin-bottom: 30px; }
    .breadcrumb { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #64748b; margin-bottom: 20px; font-weight: 600; }
    .breadcrumb a { color: #3b82f6; text-decoration: none; }
    
    .folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
    .folder-item { 
        background: rgba(255,255,255,0.7); 
        border: 1px solid rgba(255,255,255,0.8); 
        padding: 12px 18px; 
        border-radius: 14px; 
        display: flex; 
        align-items: center; 
        gap: 12px; 
        text-decoration: none; 
        color: #1e293b; 
        font-size: 14px; 
        font-weight: 700; 
        transition: 0.3s; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.02);
    }
    .folder-item:hover { 
        background: #fff;
        border-color: #3b82f6; 
        box-shadow: 0 10px 20px rgba(0,0,0,0.05); 
        transform: translateY(-2px); 
    }
    .folder-item.is-unit { border-left: 5px solid #10b981; }

    .report-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        padding: 40px;
        margin-top: 20px;
        border: 1px solid #e2e8f0;
    }

    .spiegel-table tfoot td {
        background: #f8fafc;
        border-top: 2px solid #e2e8f0;
        font-weight: 800;
        padding: 15px;
        color: #1e293b;
    }

    @media print {
        body { background: #fff !important; }
        .spiegel-wrap { margin: 0; padding: 0; max-width: 100%; width: 100%; }
        .top-bar, .folder-nav, .btn-icon, .btn-blue, .btn-outline, .btn-tiny, select, .eye-icon, .breadcrumb { display: none !important; }
        .report-card { box-shadow: none; padding: 0; border: none; margin: 0; }
        .spiegel-table { border: 1px solid #000; font-size: 8pt; width: 100%; table-layout: fixed; }
        .spiegel-table thead th { background: #eee !important; color: #000 !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; }
        .spiegel-table td { border: 1px solid #ddd !important; padding: 4px !important; }
        .input-edit { border: none !important; padding: 0 !important; font-size: 8pt !important; background: transparent !important; }
        .row-history { display: none !important; }
        .status-pill { border: 1px solid #ccc !important; background: transparent !important; color: #000 !important; padding: 2px !important; }
        tfoot td { background: #eee !important; font-weight: bold; border-top: 1px solid #000 !important; }
    }
</style>

<div class="spiegel-wrap">
    <div class="top-bar">
        <div>
            <h1 style="margin:0; letter-spacing:-1px;">📈 Mieter- & Wohnungsspiegel</h1>
            <div style="display:flex; align-items:center; gap:10px; margin-top:5px;">
                <div style="color:#64748b; font-weight:600;"><?= htmlspecialchars($projName) ?></div>
                <select onchange="location.href='?projekt_id='+this.value" style="padding:4px 8px; border-radius:8px; border:1px solid #e2e8f0; font-size:12px; font-weight:600; color:#3b82f6; background:#f0f9ff; cursor:pointer;">
                    <option value="">-- Projekt wechseln --</option>
                    <?php foreach($allProjects as $ap): ?>
                        <option value="<?= $ap['id'] ?>" <?= $ap['id'] == $pid ? 'selected' : '' ?>><?= htmlspecialchars($ap['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex; gap:10px;">
            <button class="btn btn-blue" onclick="document.getElementById('addUnitModal').style.display='block'" style="background: #10b981; border:none;">➕ Neue Einheit</button>
            <button class="btn btn-blue" onclick="saveAllChanges()">💾 Speichern</button>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="sync_fs">
                <button type="submit" class="btn btn-outline" title="Prüft ob alle Ordner auf Drive existieren und legt fehlende an">🔄 Drive-Sync</button>
            </form>
            <button class="btn btn-outline" onclick="window.print()">🖨️ Export</button>
            <a href="<?= e(url('tools/mietkontrolle/index.php?projekt_id=' . $pid)) ?>" class="btn btn-outline" style="border-color:#10b981; color:#059669; font-weight:700;">💰 Mietkontrolle</a>
            <a href="projekt_dashboard.php?id=<?= $pid ?>" class="btn btn-outline">🏠 Dashboard</a>
        </div>
    </div>

    <!-- Neue Einheit Modal -->
    <div id="addUnitModal" class="modal" style="display:none; position:fixed; z-index:20000; left:0; top:0; width:100%; height:100%; overflow:auto; background:rgba(0,0,0,0.5);">
        <div class="modal-content" style="background:#fff; margin:10% auto; padding:30px; border-radius:24px; width:400px; box-shadow:0 25px 50px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="margin:0; font-weight:800;">➕ Neue Einheit</h2>
                <span style="cursor:pointer; font-size:24px;" onclick="document.getElementById('addUnitModal').style.display='none'">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_unit">
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:8px;">Bezeichnung</label>
                    <input type="text" name="name" required placeholder="z.B. Wohnung 01" style="width:100%; padding:14px; border-radius:12px; border:2px solid #e2e8f0; font-size:16px;">
                </div>
                <div style="margin-bottom:30px;">
                    <label style="display:block; font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:8px;">Objekt (Haus)</label>
                    <select name="objekt_id" required style="width:100%; padding:14px; border-radius:12px; border:2px solid #e2e8f0; font-size:16px;">
                        <?php 
                        $objs = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id = $pid ORDER BY name");
                        if ($objs->num_rows === 0) {
                            // Falls kein Objekt existiert, legen wir ein Standard-Objekt an oder warnen
                            echo '<option value="">-- Kein Objekt vorhanden --</option>';
                        }
                        while($o = $objs->fetch_assoc()): ?>
                            <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                    <?php if ($objs->num_rows === 0): ?>
                        <p style="font-size:11px; color:#ef4444; margin-top:8px;">⚠️ Bitte legen Sie zuerst ein Objekt im Dashboard an.</p>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-blue" style="width:100%; padding:15px; border-radius:14px; font-weight:700;">Einheit erstellen & Ordner anlegen</button>
            </form>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background:#dcfce7; color:#15803d; padding:15px 25px; border-radius:12px; margin-bottom:25px; font-weight:600; border:1px solid #bbf7d0;">
            <?= $message ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div style="background:#fee2e2; color:#b91c1c; padding:15px 25px; border-radius:12px; margin-bottom:25px; font-weight:600; border:1px solid #fecaca;">
            <?= $error ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($orphans)): ?>
        <div class="flash warning" style="background:#fff7ed; border-color:#fed7aa; color:#9a3412; padding:15px 25px; border-radius:12px; margin-bottom:25px; font-weight:600; border:1px solid #fed7aa;">
            <strong>⚠️ Diskrepanz erkannt:</strong> Für <?= count($orphans) ?> Einheiten in der Liste wurde kein entsprechender Ordner im Drive gefunden.
            <div style="margin-top:10px; display:flex; gap:10px;">
                <form method="POST">
                    <input type="hidden" name="action" value="sync_fs">
                    <button type="submit" class="btn btn-outline" style="color:#d97706; border-color:#d97706;">🔄 Ordner im Drive wiederherstellen</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="sync_fs">
                    <input type="hidden" name="prune" value="1">
                    <button type="submit" class="btn btn-outline" style="color:#ef4444; border-color:#ef4444;" onclick="return confirm('Möchten Sie alle Wohnungen, die im Drive fehlen, wirklich auch aus der Datenbank löschen?')">🗑️ Fehlende aus Datenbank entfernen</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Import-Werkzeug (Standardmäßig eingeklappt) -->
    <details style="margin-top:40px; border-top:1px solid #e2e8f0; padding-top:20px;">
        <summary style="font-weight:700; color:#64748b; cursor:pointer; list-style:none; display:flex; align-items:center; gap:8px;">
            <span>➕ Neue Wohnungen aus Drive importieren</span>
            <span style="font-size:10px; background:#f1f5f9; padding:2px 8px; border-radius:50px;">Nur für Ersteinrichtung</span>
        </summary>
        
        <div class="folder-nav" style="margin-top:20px; border:1px solid #e2e8f0; border-radius:16px; padding:20px; background:rgba(255,255,255,0.5);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <div class="breadcrumb">
                    <a href="?projekt_id=<?= $pid ?>&browse_path=">📁 <?= htmlspecialchars($projName) ?></a>
                    <?php 
                    $parts = explode('/', $browsePath);
                    $cur = '';
                    foreach($parts as $p): 
                        if(!$p) continue;
                        $cur .= ($cur?'/':'').$p;
                    ?>
                        <span>/</span>
                        <a href="?projekt_id=<?= $pid ?>&browse_path=<?= rawurlencode($cur) ?>"><?= htmlspecialchars($p) ?></a>
                    <?php endforeach; ?>
                </div>
                
                <form method="POST" style="margin:0; display:flex; gap:10px;">
                    <input type="hidden" name="action" value="sync">
                    <input type="hidden" name="sync_path" value="<?= htmlspecialchars($browsePath) ?>">
                    <button type="submit" class="btn btn-blue" style="background:#0ea5e9; border:none; padding:8px 20px; border-radius:10px;">🚀 Unterordner als Wohnungen importieren</button>
                </form>
            </div>
            
            <div class="folder-grid">
                <?php 
                // Liste der bereits existierenden Folder-Namen für das visuelle Feedback
                $existingFolders = [];
                $exRes = $mysqli->query("SELECT w.folder_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE o.projekt_id = $pid");
                while($ex = $exRes->fetch_assoc()) if($ex['folder_name']) $existingFolders[] = $ex['folder_name'];

                foreach($folders as $f): 
                    $isLinked = in_array($f, $existingFolders);
                ?>
                    <div class="folder-item <?= $isLinked ? 'is-unit' : '' ?>">
                        <a href="?projekt_id=<?= $pid ?>&browse_path=<?= rawurlencode($browsePath ? $browsePath.'/'.$f : $f) ?>" style="flex:1; text-decoration:none; color:inherit; display:flex; align-items:center; gap:10px;">
                            <span style="font-size:18px;">📁</span>
                            <?= htmlspecialchars($f) ?>
                            <?php if($isLinked): ?> <span style="color:#10b981; margin-left:auto;">✅</span><?php endif; ?>
                        </a>
                        
                        <form method="POST" style="margin:0; display:flex;">
                            <input type="hidden" name="action" value="sync_single">
                            <input type="hidden" name="folder_name" value="<?= htmlspecialchars($f) ?>">
                            <input type="hidden" name="sync_path" value="<?= htmlspecialchars($browsePath) ?>">
                            <button type="submit" class="btn-tiny" title="Als einzelne Wohnung importieren/aktualisieren">🏠+</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if(empty($folders)): ?>
                    <div style="color:#94a3b8; font-size:12px; font-style:italic;">Keine weiteren Unterordner vorhanden.</div>
                <?php endif; ?>
            </div>
        </div>
    </details>

    <div class="report-card">
        <form id="spiegelForm" method="POST">
        <input type="hidden" name="action" value="fast_update">
        <table class="spiegel-table">
            <thead>
                <tr>
                    <th width="40"></th>
                    <th>Einheit</th>
                    <th width="100">Etage</th>
                    <th width="70">Zi.</th>
                    <th width="100">m²</th>
                    <th width="100">Ausstattung</th>
                    <th>Mieter (Ist)</th>
                    <th width="120">Netto (Ist/Soll)</th>
                    <th width="120">NK (Ist/Soll)</th>
                    <th width="110">Brutto (Ist)</th>
                    <th width="100">Status</th>
                    <th width="60" title="Online im Inserat">Web</th>
                    <th width="120">Verfügbar</th>
                    <th width="130">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                    $totalNettoSoll = 0;
                    $totalNkSoll = 0;
                    $totalBruttoIst = 0;
                    foreach($units as $u): 
                        $brutto = ($u['mieter']) ? ($u['mieter']['mietzins_netto'] + $u['mieter']['mietzins_nk']) : 0;
                        $totalNettoSoll += (float)$u['mietzins_netto_soll'];
                        $totalNkSoll += (float)$u['mietzins_nk_soll'];
                        $totalBruttoIst += $brutto;
                ?>
                    <tr class="row-main">
                        <td align="center">
                            <button type="button" class="btn-icon" onclick="toggleHistory(<?= $u['w_id'] ?>)">
                                <span id="icon_<?= $u['w_id'] ?>">+</span>
                            </button>
                        </td>
                        <td style="cursor:pointer;" onclick="openUnitDashboard(<?= $u['w_id'] ?>)">
                            <span class="badge-obj"><?= htmlspecialchars($u['obj_name']) ?></span>
                            <span class="badge-unit" style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($u['w_name']) ?></span>
                        </td>
                        <td><input type="text" class="input-edit" name="u[<?= $u['w_id'] ?>][etage]" value="<?= htmlspecialchars($u['etage']) ?>" placeholder="-"></td>
                        <td><input type="number" step="0.5" class="input-edit" name="u[<?= $u['w_id'] ?>][zimmer]" value="<?= $u['zimmer'] ?>" placeholder="0"></td>
                        <td><input type="number" class="input-edit" name="u[<?= $u['w_id'] ?>][flaeche]" value="<?= $u['flaeche'] ?>" placeholder="0"></td>
                        <td>
                            <div style="display:flex; gap:6px; font-size:18px;">
                                <label title="Balkon" style="cursor:pointer; opacity: <?= $u['balkon'] ? '1':'0.2' ?>; filter: <?= $u['balkon'] ? 'none':'grayscale(1)' ?>;">
                                    <input type="checkbox" name="u[<?= $u['w_id'] ?>][balkon]" value="1" <?= $u['balkon'] ? 'checked':'' ?> style="display:none;" onchange="this.parentElement.style.opacity = this.checked ? '1':'0.2'; this.parentElement.style.filter = this.checked ? 'none':'grayscale(1)';">
                                    🏙️
                                </label>
                                <label title="Terrasse" style="cursor:pointer; opacity: <?= $u['terrasse'] ? '1':'0.2' ?>; filter: <?= $u['terrasse'] ? 'none':'grayscale(1)' ?>;">
                                    <input type="checkbox" name="u[<?= $u['w_id'] ?>][terrasse]" value="1" <?= $u['terrasse'] ? 'checked':'' ?> style="display:none;" onchange="this.parentElement.style.opacity = this.checked ? '1':'0.2'; this.parentElement.style.filter = this.checked ? 'none':'grayscale(1)';">
                                    🌴
                                </label>
                                <label title="Wintergarten" style="cursor:pointer; opacity: <?= $u['wintergarten'] ? '1':'0.2' ?>; filter: <?= $u['wintergarten'] ? 'none':'grayscale(1)' ?>;">
                                    <input type="checkbox" name="u[<?= $u['w_id'] ?>][wintergarten]" value="1" <?= $u['wintergarten'] ? 'checked':'' ?> style="display:none;" onchange="this.parentElement.style.opacity = this.checked ? '1':'0.2'; this.parentElement.style.filter = this.checked ? 'none':'grayscale(1)';">
                                    ❄️
                                </label>
                            </div>
                        </td>
                        <td>
                            <?php if($u['mieter']): ?>
                                <strong><?= htmlspecialchars($u['mieter']['name']) ?></strong>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-style:italic;">Leerstehend</span>
                            <?php endif; ?>
                            <?php if($u['applicant_count'] > 0): ?>
                                <span style="background:#fef08a; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:800; margin-left:5px;">🔥 <?= $u['applicant_count'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <input type="number" step="0.05" class="input-edit" name="u[<?= $u['w_id'] ?>][netto]" value="<?= $u['mieter'] ? $u['mieter']['mietzins_netto'] : '' ?>" placeholder="Ist: 0.00" title="Aktueller Mietzins (Ist)">
                                <div style="display:flex; align-items:center; gap:5px;">
                                    <span style="font-size:10px; color:#94a3b8; font-weight:700;">SOLL:</span>
                                    <input type="number" step="0.05" class="input-edit" name="u[<?= $u['w_id'] ?>][netto_soll]" value="<?= (float)$u['mietzins_netto_soll'] ?>" placeholder="0.00" style="font-size:11px; color:#64748b; padding:4px 8px;" title="Soll-Mietzins laut Planung">
                                </div>
                            </div>
                        </td>
                        <td>
                             <div style="display:flex; flex-direction:column; gap:4px;">
                                <input type="number" step="0.05" class="input-edit" name="u[<?= $u['w_id'] ?>][nk]" value="<?= $u['mieter'] ? $u['mieter']['mietzins_nk'] : '' ?>" placeholder="Ist: 0.00" title="Aktuelle NK (Ist)">
                                <div style="display:flex; align-items:center; gap:5px;">
                                    <span style="font-size:10px; color:#94a3b8; font-weight:700;">SOLL:</span>
                                    <input type="number" step="0.05" class="input-edit" name="u[<?= $u['w_id'] ?>][nk_soll]" value="<?= (float)$u['mietzins_nk_soll'] ?>" placeholder="0.00" style="font-size:11px; color:#64748b; padding:4px 8px;" title="Soll-NK laut Planung">
                                </div>
                            </div>
                        </td>
                        <td style="font-weight:700; color:#1e293b;">
                            <?= number_format($brutto, 2, '.', "'") ?>.-
                        </td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:2px;">
                                <?php
                                    $st = $u['mieter'] ? 'VERMIETET' : 'FREI';
                                    if (!$u['mieter'] && $u['applicant_count'] > 0) $st = 'RESERVIERT';
                                ?>
                                <span class="status-pill status-<?= strtolower($st) ?>">
                                    <?= $st ?>
                                </span>
                            </div>
                        </td>
                        <td align="center">
                             <label style="cursor:pointer; font-size:20px;">
                                <input type="checkbox" name="u[<?= $u['w_id'] ?>][is_published]" value="1" <?= $u['is_published'] ? 'checked' : '' ?> style="display:none;" onchange="this.parentElement.querySelector('.eye-icon').style.opacity = this.checked ? '1' : '0.2'; this.parentElement.querySelector('.eye-icon').innerText = this.checked ? '👁️' : '🕶️';">
                                <span class="eye-icon" style="opacity: <?= $u['is_published'] ? '1' : '0.2' ?>;"><?= $u['is_published'] ? '👁️' : '🕶️' ?></span>
                             </label>
                        </td>
                        <td>
                            <input type="date" class="input-edit" name="u[<?= $u['w_id'] ?>][available_from]" value="<?= $u['available_from'] ?>" style="font-size:11px;">
                        </td>
                        <td>
                            <div style="display:flex; gap:5px;">
                                <?php 
                                    $iconColor = '#94a3b8'; // Standard grau
                                    $iconTitle = "Mieter verwalten (frei)";
                                    if ($u['mieter']) {
                                        $iconColor = '#10b981'; // Grün = Aktuell vermietet
                                        $iconTitle = "Mieter: " . $u['mieter']['name'];
                                    } elseif (!empty($u['history'])) {
                                        $iconColor = '#f59e0b'; // Orange = Hat Vormieter
                                        $iconTitle = "Leerstehend (Historie vorhanden)";
                                    }
                                ?>
                                <button type="button" class="btn-icon" title="Einheit öffnen (Command Center)" onclick="openUnitDashboard(<?= $u['w_id'] ?>)">🚀</button>
                                <a href="wohnung_edit.php?id=<?= $u['w_id'] ?>" class="btn-icon" title="Zentralen Datenspeicher bearbeiten (Vollansicht)">📐</a>

                                <button type="button" class="btn-icon" 
                                        style="color:<?= $iconColor ?>; border-color:<?= $iconColor ?>44; background:<?= $iconColor ?>11;" 
                                        title="<?= $iconTitle ?>" 
                                        onclick="openTenantModal(<?= $u['w_id'] ?>)">👤</button>
                                
                                <button type="button" class="btn-icon" title="Anfrage-Link kopieren" onclick="copyLink(<?= $u['w_id'] ?>)">🔗</button>

                                <a href="abnahme.php?projekt_id=<?= $pid ?>&unit_id=<?= $u['w_id'] ?>" class="btn-icon" title="Protokoll / Abnahme">📝</a>
                                <a href="wohnungsabnahme_protokoll.php?projekt_id=<?= $pid ?>&unit_id=<?= $u['w_id'] ?>" class="btn-icon" title="Wohnungsabnahmeprotokoll (Neu)">🔑</a>
                                <a href="vertrag_gen.php?projekt_id=<?= $pid ?>&unit_id=<?= $u['w_id'] ?>" class="btn-icon" title="Mietvertrag">📜</a>

                                <?php if($u['folder_exists']): ?>
                                    <a href="quick_folder_editor.php?projekt_id=<?= $pid ?>&path=<?= rawurlencode($u['obj_name'].'/Wohnungen/'.$u['folder_name']) ?>" class="btn-icon" title="Ordner">📂</a>
                                <?php endif; ?>

                                <div class="delete-confirm-wrap" id="del_wrap_<?= $u['w_id'] ?>" style="display:inline-flex; align-items:center; gap:5px;">
                                    <button type="button" class="btn-icon" style="color:#ef4444;" title="Einheit löschen" onclick="confirmDelete(<?= $u['w_id'] ?>)">🗑️</button>
                                </div>
                                <div class="delete-actual-wrap" id="del_act_<?= $u['w_id'] ?>" style="display:none; align-items:center; gap:5px;">
                                    <button type="button" class="btn btn-sm" style="background:#ef4444; color:white; border:none; padding:4px 8px; font-size:10px; font-weight:800; border-radius:6px;" onclick="executeDelete(<?= $u['w_id'] ?>)">SICHER LÖSCHEN?</button>
                                    <button type="button" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:16px;" onclick="cancelDelete(<?= $u['w_id'] ?>)">✕</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <!-- Historie Zeile -->
                    <tr id="hist_<?= $u['w_id'] ?>" class="row-history">
                        <td colspan="11">
                            <div class="history-inner">
                                <h4 style="margin:0 0 10px 0; font-size:12px; text-transform:uppercase; color:#94a3b8;">📜 Mieter-Historie (Vormieter)</h4>
                                <?php if(empty($u['history'])): ?>
                                    <div style="color:#cbd5e1; font-size:12px; font-style:italic;">Keine Einträge vorhanden.</div>
                                <?php else: ?>
                                    <table width="100%" style="font-size:13px; color:#475569;">
                                        <?php foreach($u['history'] as $h): ?>
                                            <tr>
                                                <td width="200"><strong><?= htmlspecialchars($h['name']) ?></strong></td>
                                                <td width="150"><?= date('d.m.Y', strtotime($h['einzug_datum'])) ?> - <?= date('d.m.Y', strtotime($h['auszug_datum'])) ?></td>
                                                <td>Mietzins: <?= number_format($h['mietzins_netto'],2,'.',"'") ?>.-</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" align="right">TOTAL</td>
                    <td><?= number_format($totalNettoSoll, 2, '.', "'") ?>.-</td>
                    <td><?= number_format($totalNkSoll, 2, '.', "'") ?>.-</td>
                    <td><?= number_format($totalBruttoIst, 2, '.', "'") ?>.-</td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
            </tbody>
        </table>
    </form>
</div>

<!-- Unit Command Center Modal -->
<div id="unitDashboardModal" class="modal">
    <div class="modal-content" style="max-width:1100px; width:95%; padding: 0; overflow:hidden;">
        <div class="unit-modal-grid">
            <div class="unit-sidebar" style="background:#fafafa; padding: 30px 20px;">
                <div id="dash_unit_header" style="margin-bottom:30px;">
                    <h2 id="dash_title" style="margin:0; font-size:20px;">Wohnung 01</h2>
                    <span id="dash_subtitle" style="color:#64748b; font-size:13px;">Objekt A | EG</span>
                    <div id="dash_features" style="margin-top:5px; font-size:16px;"></div>
                </div>
                <ul class="unit-tabs">
                    <li class="active" onclick="switchTab('tab_ov')">🏠 Übersicht</li>
                    <li onclick="switchTab('tab_market')">📢 Vermarktung</li>
                    <li onclick="switchTab('tab_applicants')">👥 Interessenten</li>
                    <li onclick="switchTab('tab_rooms')">🚪 Räume</li>
                    <li onclick="switchTab('tab_docs')">📂 Dokumente</li>
                    <li onclick="switchTab('tab_config')">⚙️ Einstellungen</li>
                </ul>
                <div style="margin-top:auto; padding-top:40px;">
                    <button class="btn btn-outline" style="width:100%" onclick="closeUnitDashboard()">Schliessen</button>
                </div>
            </div>
            <div id="dash_main_content" style="padding: 0; overflow-y:auto; max-height:85vh;">
                <!-- Tab Overview -->
                <div id="tab_ov" class="tab-content active">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_unit_specs">
                        <input type="hidden" name="w_id" id="dash_w_id_ov">
                        <h2 style="margin:0 0 5px 0; font-weight:800; font-size:32px; letter-spacing:-1px;">Eckdaten & Ausstattung</h2>
                        <p style="color:#64748b; font-weight:600; margin-bottom:30px;">Verwalten Sie die technischen Daten der Wohneinheit.</p>
                        
                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:20px; margin-bottom:30px;">
                            <div class="glass-card" style="padding:20px; margin-bottom:0; background:rgba(255,255,255,0.4);">
                                <label style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:block;">Zimmer</label>
                                <input type="number" step="0.5" name="zimmer" id="dash_rooms_val" class="input-edit" style="font-size:24px; font-weight:800; background:none; border:none; padding:0; height:auto;">
                            </div>
                            <div class="glass-card" style="padding:20px; margin-bottom:0; background:rgba(255,255,255,0.4);">
                                <label style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:block;">Fläche ($m^2$)</label>
                                <input type="number" name="flaeche" id="dash_m2_val" class="input-edit" style="font-size:24px; font-weight:800; background:none; border:none; padding:0; height:auto;">
                            </div>
                            <div class="glass-card" style="padding:20px; margin-bottom:0; background:rgba(255,255,255,0.4);">
                                <label style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:block;">Baujahr</label>
                                <input type="number" name="baujahr" id="dash_baujahr_val" class="input-edit" style="font-size:24px; font-weight:800; background:none; border:none; padding:0; height:auto;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:20px; margin-bottom:30px;">
                             <div class="glass-card" style="padding:20px; margin-bottom:0; background:rgba(255,255,255,0.4);">
                                <label style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:block;">Heizung</label>
                                <input type="text" name="heizungsart" id="dash_heizung_val" class="input-edit" style="font-size:18px; font-weight:700;">
                            </div>
                            <div class="glass-card" style="padding:20px; margin-bottom:0; background:rgba(255,255,255,0.4);">
                                <label style="font-size:11px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:block;">Etage</label>
                                <input type="text" name="etage" id="dash_floor_val" class="input-edit" style="font-size:18px; font-weight:700;">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:20px; margin-bottom:40px; background:rgba(255,255,255,0.3); padding:20px; border-radius:15px; border:1px solid rgba(255,255,255,0.5);">
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700;">
                                <input type="checkbox" name="balkon" id="dash_balkon" value="1" style="width:20px; height:20px;"> Balkon 🏙️
                            </label>
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700;">
                                <input type="checkbox" name="terrasse" id="dash_terrasse" value="1" style="width:20px; height:20px;"> Terrasse 🌴
                            </label>
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700;">
                                <input type="checkbox" name="wintergarten" id="dash_wintergarten" value="1" style="width:20px; height:20px;"> Wintergarten ❄️
                            </label>
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700;">
                                <input type="checkbox" name="lift" id="dash_lift" value="1" style="width:20px; height:20px;"> Lift 🛗
                            </label>
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700;">
                                <input type="checkbox" name="keller_vorhanden" id="dash_keller" value="1" style="width:20px; height:20px;"> Keller 📦
                            </label>
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-weight:700;">
                                <input type="checkbox" name="barrierefrei" id="dash_barrierefrei" value="1" style="width:20px; height:20px;"> Rollstuhlg. ♿
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-blue" style="padding:15px 40px; font-weight:800; border-radius:20px; font-size:16px;">💾 Speichern</button>
                    </form>
                </div>

                <!-- Tab Marketing -->
                <div id="tab_market" class="tab-content">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_unit_dash">
                        <input type="hidden" name="w_id" id="dash_w_id">
                        <h2 style="margin:0 0 5px 0; font-weight:800; font-size:32px; letter-spacing:-1px;">📢 Vermarktung</h2>
                        <p style="color:#64748b; font-weight:600; margin-bottom:30px;">Steuern Sie das Online-Inserat und die Preisgestaltung.</p>
                        
                        <div style="display:grid; gap:30px;">
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                                <div class="form-group">
                                    <label style="font-weight:700; color:#64748b; margin-bottom:8px; display:block;">Soll-Mietzins (Netto)</label>
                                    <input type="number" name="netto_soll" id="dash_netto_soll" class="input-edit" style="font-size:20px; font-weight:700;">
                                </div>
                                <div class="form-group">
                                    <label style="font-weight:700; color:#64748b; margin-bottom:8px; display:block;">Soll-NK</label>
                                    <input type="number" name="nk_soll" id="dash_nk_soll" class="input-edit" style="font-size:20px; font-weight:700;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label style="font-weight:700; color:#64748b; margin-bottom:8px; display:block;">Beschreibung (Inseratstext)</label>
                                <textarea name="beschreibung" id="dash_desc" class="input-edit" style="height:200px; padding:20px;" placeholder="Beschreiben Sie Vorzüge, Ausstattung und Lage..."></textarea>
                            </div>
                            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                                <div class="form-group">
                                    <label style="font-weight:700; color:#64748b; margin-bottom:8px; display:block;">🖼️ Titelbild-Pfad (Relativ)</label>
                                    <input type="text" name="titelbild_pfad" id="dash_tbild" class="input-edit" placeholder="Ordner/bild.jpg">
                                </div>
                                <div class="form-group">
                                    <label style="font-weight:700; color:#64748b; margin-bottom:8px; display:block;">📐 Grundriss-Pfad (Relativ)</label>
                                    <input type="text" name="grundriss_pfad" id="dash_gpfd" class="input-edit" placeholder="Ordner/plan.pdf">
                                </div>
                            </div>
                            <div style="background:rgba(255,255,255,0.3); padding:20px; border-radius:20px; border:1px solid rgba(0,0,0,0.05);">
                                <h4 style="margin:0 0 15px 0;">📸 Bilder-Gallerie (aus Ordner '02_Bilder')</h4>
                                <div id="dash_gallery_preview" style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px;">
                                    <div style="color:#94a3b8; font-size:12px; font-style:italic;">Keine Bilder im Ordner gefunden.</div>
                                </div>
                                <div style="margin-top:15px;" id="dash_upload_hint"></div>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(59,130,246,0.05); padding:20px; border-radius:20px; border:1px solid rgba(59,130,246,0.1);">
                                <div style="display:flex; align-items:center; gap:15px;">
                                    <input type="checkbox" name="is_published" id="dash_published" value="1" style="width:24px; height:24px;">
                                    <div>
                                        <div style="font-weight:800; color:#1e293b;">Online publizieren</div>
                                        <div style="font-size:12px; color:#64748b;">Sichtbar auf der Projekt-Webseite</div>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-blue" style="padding:15px 30px; border-radius:15px;">💾 Inserat aktualisieren</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Tab Applicants -->
                <div id="tab_applicants" class="tab-content">
                    <h2 style="margin:0 0 5px 0; font-weight:800; font-size:32px; letter-spacing:-1px;">👥 Mietinteressenten</h2>
                    <p style="color:#64748b; font-weight:600; margin-bottom:30px;">Aktuelle Bewerbungen für diese Einheit.</p>
                    <div id="dash_applicants_list"></div>
                </div>

                <!-- Tab Rooms -->
                <div id="tab_rooms" class="tab-content">
                    <h2 style="margin:0 0 5px 0; font-weight:800; font-size:32px; letter-spacing:-1px;">🚪 Raum-Konfiguration</h2>
                    <p style="color:#64748b; font-weight:600; margin-bottom:30px;">Definieren Sie alle Räume dieser Einheit für Pendenzen, Abnahmen und Inserate.</p>
                    
                    <div class="glass-card" style="padding:20px; background:rgba(59,130,246,0.05); border-color:rgba(59,130,246,0.1); margin-bottom:30px;">
                        <h4 style="margin:0 0 10px 0; font-size:14px;">Raum hinzufügen</h4>
                        <div style="display:flex; gap:10px;">
                            <input type="text" id="new_room_name" class="input-edit" placeholder="z.B. Wohnzimmer, Bad 1..." style="flex:1;">
                            <button type="button" class="btn btn-blue" onclick="addRoomManual()">Hinzufügen</button>
                        </div>
                        <div style="margin-top:15px; display:flex; flex-wrap:wrap; gap:8px;" id="room_master_pool">
                            <!-- Hier kommen die Vorlagen rein -->
                        </div>
                    </div>

                    <div id="dash_rooms_list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:15px;">
                        <!-- Hier kommen die Räume rein -->
                    </div>
                </div>

                <!-- Tab Docs -->
                <div id="tab_docs" class="tab-content">
                    <h2 style="margin:0 0 5px 0; font-weight:800; font-size:32px; letter-spacing:-1px;">📂 Dokumente</h2>
                    <p style="color:#64748b; font-weight:600; margin-bottom:30px;">Dokumente, Pläne und Bilder dieser Einheit.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="tenantModal" class="modal">
    <div class="modal-content" style="max-width:500px;">
        <span class="close" onclick="closeTenantModal()">&times;</span>
        <h2 id="modalTitle">👤 Mieter verwalten</h2>
        <div id="unitInfo" style="margin-bottom:15px; border-bottom:1px solid #eef2f7; padding-bottom:10px;"></div>

        <div id="currentTenantDiv" style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:15px; display:none; border-left:4px solid #10b981;">
            <h4 style="margin:0 0 5px 0; font-size:11px; text-transform:uppercase; color:#64748b;">Aktueller Mieter record:</h4>
            <div id="currentTenantName" style="font-weight:700; font-size:16px;"></div>
            <div id="currentTenantDates" style="font-size:12px; color:#64748b; margin-top:2px;"></div>
            <form method="POST" style="margin-top:10px;">
                <input type="hidden" name="action" value="move_to_history">
                <input type="hidden" name="pv_id" id="modal_pv_id">
                <button type="submit" class="btn" style="background:#ef4444; font-size:12px; padding:6px 12px;">📦 In Historie verschieben (Auszug)</button>
            </form>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="assign_tenant">
            <input type="hidden" name="w_id" id="modal_w_id">
            
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Neuen Mieter zuweisen</label>
                <select name="benutzer_id" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-family:inherit;">
                    <option value="">-- Mieter wählen --</option>
                    <?php foreach($allUsers as $usr): ?>
                        <option value="<?= $usr['id'] ?>"><?= htmlspecialchars($usr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:4px;">Netto (CHF)</label>
                    <input type="number" step="0.05" name="mietzins_netto" id="modal_netto" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:4px;">NK (CHF)</label>
                    <input type="number" step="0.05" name="nk_akonto" id="modal_nk" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:4px;">Einzugsdatum</label>
                <input type="date" name="beginn" value="<?= date('Y-m-d') ?>" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
            </div>

            <button type="submit" class="btn btn-blue" style="width:100%; padding:12px; font-weight:700;">✅ Einzug abschliessen & Ordner anlegen</button>
        </form>
    </div>
</div>

<!-- Interessent Einladen Modal -->
<div id="inviteModal" class="modal">
    <div class="modal-content" style="max-width:400px; border-top: 5px solid #007a3d;">
        <span class="close" onclick="closeInviteModal()">&times;</span>
        <h2 style="color:#007a3d; margin:0 0 5px 0;">📧 Interessent einladen</h2>
        <p id="inviteUnitInfo" style="font-size:13px; color:#64748b; margin-bottom:20px;"></p>
        
        <form id="inviteForm">
            <input type="hidden" name="projekt_id" value="<?= $pid ?>">
            <input type="hidden" name="wohnung_id" id="invite_w_id">
            
            <div style="margin-bottom:15px;">
                <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Vorname</label>
                <input type="text" name="vorname" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
            </div>
            <div style="margin-bottom:15px;">
                <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">Nachname</label>
                <input type="text" name="nachname" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
            </div>
            <div style="margin-bottom:15px;">
                <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:4px;">E-Mail Adresse</label>
                <input type="email" name="email" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;">
            </div>
            
            <button type="submit" class="btn btn-blue" style="width:100%; background:#007a3d; border:none; padding:12px; color:white; font-weight:700; border-radius:8px; cursor:pointer;">Einladung generieren</button>
        </form>
        
        <div id="inviteResult" style="margin-top:20px; display:none; padding:15px; background:#f0f9ff; border-radius:8px; border:1px solid #bae6fd;">
            <p style="font-size:12px; color:#0369a1; margin-bottom:10px;">Link kopieren und an Interessenten senden:</p>
            <input type="text" id="inviteLinkUrl" readonly style="width:100%; padding:8px; font-size:11px; border:1px solid #bae6fd; background:white; border-radius:4px;">
            <button type="button" class="btn" onclick="copyInviteLink()" style="margin-top:10px; font-size:12px; padding:8px; background:#0369a1; color:white; border:none; width:100%; border-radius:4px; cursor:pointer;">📋 Link kopieren</button>
        </div>
    </div>
</div>

<style>
.modal { display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); transition: 0.3s; }
.modal-content { background:#fff; margin:8% auto; padding:25px; border-radius:16px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); position:relative; animation: slideDown 0.3s ease-out; }
@keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.close { position:absolute; right:20px; top:20px; font-size:24px; font-weight:700; cursor:pointer; color:#94a3b8; }
.close:hover { color:#ef4444; }
</style>

<script>
const unitsArr = <?= json_encode($units, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function confirmDelete(id) {
    document.getElementById('del_wrap_' + id).style.display = 'none';
    document.getElementById('del_act_' + id).style.display = 'inline-flex';
}

function cancelDelete(id) {
    document.getElementById('del_wrap_' + id).style.display = 'inline-flex';
    document.getElementById('del_act_' + id).style.display = 'none';
}

function executeDelete(wid) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = window.location.href;
    f.innerHTML = '<input type="hidden" name="action" value="delete_unit_force">' +
                  '<input type="hidden" name="w_id" value="' + wid + '">';
    document.body.appendChild(f);
    f.submit();
}

function openTenantModal(wid) {
    const unit = unitsArr.find(u => u.w_id == wid);
    if (!unit) return;
    document.getElementById('modal_w_id').value = wid;
    document.getElementById('modalTitle').innerText = '👤 Mieter verwalten: ' + unit.w_name;
    document.getElementById('unitInfo').innerHTML = `<span class="badge-obj">${unit.obj_name}</span> <span class="badge-unit">${unit.etage} | ${unit.zimmer} Zi.</span>`;
    const curTD = document.getElementById('currentTenantDiv');
    if (unit.mieter) {
        curTD.style.display = 'block';
        document.getElementById('currentTenantName').innerText = unit.mieter.name;
        document.getElementById('currentTenantDates').innerText = "Seit: " + unit.mieter.einzug_datum;
        document.getElementById('modal_pv_id').value = unit.mieter.pv_id; 
        document.getElementById('modal_netto').value = unit.mieter.mietzins_netto;
        document.getElementById('modal_nk').value = unit.mieter.mietzins_nk;
    } else {
        curTD.style.display = 'none';
        document.getElementById('modal_netto').value = '';
        document.getElementById('modal_nk').value = '';
    }
    document.getElementById('tenantModal').style.display = 'block';
}

function closeTenantModal() { document.getElementById('tenantModal').style.display = 'none'; }

function openInviteModal(wid, wname) {
    document.getElementById('invite_w_id').value = wid;
    document.getElementById('inviteUnitInfo').innerText = 'Einladung für Wohnung: ' + wname;
    document.getElementById('inviteModal').style.display = 'block';
    document.getElementById('inviteResult').style.display = 'none';
    document.getElementById('inviteForm').reset();
}

function closeInviteModal() { document.getElementById('inviteModal').style.display = 'none'; }

document.getElementById('inviteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = this.querySelector('button');
    btn.disabled = true;
    btn.innerText = 'Wird generiert...';

    fetch('interessent_invite_save.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = 'Einladung generieren';
        if (data.success) {
            document.getElementById('inviteResult').style.display = 'block';
            document.getElementById('inviteLinkUrl').value = data.invite_url;
        } else {
            alert('Fehler: ' + data.error);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = 'Einladung generieren';
        alert('Ein Fehler ist aufgetreten.');
    });
});


function copyInviteLink() {
    const copyText = document.getElementById("inviteLinkUrl");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(() => alert("Link kopiert!"));
}

window.onclick = function(event) {
    const tModal = document.getElementById('tenantModal');
    const iModal = document.getElementById('inviteModal');
    const dModal = document.getElementById('unitDashboardModal');
    if (event.target == tModal) closeTenantModal();
    if (event.target == iModal) closeInviteModal();
    if (event.target == dModal) closeUnitDashboard();
}

function openUnitDashboard(wid) {
    const unit = unitsArr.find(u => u.w_id == wid);
    if (!unit) return;
    currentDashWid = wid;

    // Fill Modal
    document.getElementById('dash_title').innerText = unit.w_name;
    document.getElementById('dash_subtitle').innerText = unit.obj_name + ' | ' + unit.etage;
    
    document.getElementById('dash_rooms_val').value = unit.zimmer;
    document.getElementById('dash_m2_val').value = unit.flaeche;
    document.getElementById('dash_floor_val').value = unit.etage;
    
    document.getElementById('dash_baujahr_val').value = unit.baujahr || "";
    document.getElementById('dash_heizung_val').value = unit.heizungsart || "";

    document.getElementById('dash_balkon').checked = unit.balkon == 1;
    document.getElementById('dash_terrasse').checked = unit.terrasse == 1;
    document.getElementById('dash_wintergarten').checked = unit.wintergarten == 1;
    document.getElementById('dash_lift').checked = unit.lift == 1;
    document.getElementById('dash_keller').checked = unit.keller_vorhanden == 1;
    document.getElementById('dash_barrierefrei').checked = unit.barrierefrei == 1;

    document.getElementById('dash_w_id').value = wid; // Hidden field for Marketing form
    document.getElementById('dash_w_id_ov').value = wid; // Hidden field for Specs form
    
    document.getElementById('dash_netto_soll').value = unit.mietzins_netto_soll;
    document.getElementById('dash_nk_soll').value = unit.mietzins_nk_soll;
    document.getElementById('dash_desc').value = unit.ausstattung_details || "";
    document.getElementById('dash_published').checked = unit.is_published == 1;

    document.getElementById('dash_tbild').value = unit.titelbild_pfad || "";
    document.getElementById('dash_gpfd').value = unit.grundriss_pfad || "";

    // Gallery & Upload Hint
    const gallery = document.getElementById('dash_gallery_preview');
    const hint = document.getElementById('dash_upload_hint');
    if (unit.folder_exists) {
        hint.innerHTML = `<a href="quick_folder_editor.php?projekt_id=${pid}&path=${encodeURIComponent(unit.obj_name + '/Wohnungen/' + unit.folder_name + '/02_Bilder')}" class="btn btn-outline btn-sm">➕ Bilder hochladen / verwalten</a>`;
        // AJAX zum Laden der Bilder-Vorschau (optional)
        gallery.innerHTML = '<div style="color:#64748b; font-size:12px;">Ordner verknüpft. Bitte Dateisystem prüfen.</div>';
    } else {
        hint.innerHTML = '<span style="color:#ef4444; font-size:12px;">Ordner auf Drive fehlt - bitte "Eckdaten speichern", um Ordner anzulegen.</span>';
        gallery.innerHTML = '';
    }

    // Features
    let featHtml = '';
    if(unit.balkon == 1) featHtml += '🏙️';
    if(unit.terrasse == 1) featHtml += '🌴';
    if(unit.wintergarten == 1) featHtml += '❄️';
    document.getElementById('dash_features').innerHTML = featHtml || '<small style="color:#94a3b8">Keine Besonderheiten</small>';

    switchTab('tab_ov');
    document.getElementById('unitDashboardModal').style.display = 'block';
    loadApplicants(wid);
    loadRooms(wid);
}

function loadRooms(wid) {
    const list = document.getElementById('dash_rooms_list');
    const pool = document.getElementById('room_master_pool');
    list.innerHTML = 'Lade...';
    
    fetch('ajax_unit_rooms.php?wohnung_id=' + wid + '&v=' + Date.now())
    .then(r => r.json())
    .then(res => {
        if(!res.success) {
            list.innerHTML = 'Fehler beim Laden.';
            return;
        }
        const data = res.data;

        // Pool füllen
        pool.innerHTML = '<span style="font-size:11px; color:#64748b; width:100%; margin-bottom:10px; font-weight:700; text-transform:uppercase;">Schnellauswahl:</span>';
        data.master_data.forEach(m => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-outline';
            btn.style.cssText = 'display:flex; flex-direction:column; align-items:center; gap:5px; padding:15px; min-width:85px; border-radius:15px; background:white; cursor:pointer; font-family:inherit; transition:0.2s;';
            btn.innerHTML = `
                <span style="font-size:28px;">${m.icon || '📦'}</span>
                <span style="font-size:11px; font-weight:700; color:#475569;">${m.name}</span>
            `;
            btn.onclick = () => addRoom(m.name);
            pool.appendChild(btn);
        });

        // Liste füllen
        list.innerHTML = '';
        if(!data.rooms || data.rooms.length === 0) {
            list.innerHTML = '<div style="grid-column:1/-1; padding:20px; text-align:center; color:#94a3b8;">Noch keine Räume definiert.</div>';
        } else {
            data.rooms.forEach(r => {
                const master = data.master_data.find(m => m.name === r.name);
                const icon = master ? master.icon : '▫️';
                const div = document.createElement('div');
                div.className = 'glass-card';
                div.style.padding = '15px';
                div.style.margin = '0';
                div.style.display = 'flex';
                div.style.justifyContent = 'space-between';
                div.style.alignItems = 'center';
                div.style.background = '#fff';
                div.innerHTML = `
                    <span style="font-weight:700;">${icon} ${r.name}</span>
                    <button class="btn btn-sm" style="color:#ef4444; background:none; border:none; padding:5px; font-size:16px;" onclick="deleteRoom(${r.id})">🗑️</button>
                `;
                list.appendChild(div);
            });
        }
    });
}

function addRoomManual() {
    const name = document.getElementById('new_room_name').value;
    if(name) addRoom(name);
}

function addRoom(name) {
    const fd = new FormData();
    fd.append('action', 'add_room');
    fd.append('w_id', currentDashWid);
    fd.append('room_name', name);
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            document.getElementById('new_room_name').value = '';
            loadRooms(currentDashWid);
        } else {
            alert("Fehler: " + res.error);
        }
    });
}

function deleteRoom(rid) {
    if(!confirm("Raum wirklich entfernen?")) return;
    const fd = new FormData();
    fd.append('action', 'delete_room');
    fd.append('room_id', rid);
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(() => loadRooms(currentDashWid));
}

function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
    document.querySelectorAll('.unit-tabs li').forEach(li => {
        li.classList.remove('active');
        if(li.getAttribute('onclick') && li.getAttribute('onclick').includes(tabId)) li.classList.add('active');
    });
    document.getElementById(tabId).classList.add('active');
}

function closeUnitDashboard() { document.getElementById('unitDashboardModal').style.display = 'none'; }

function loadApplicants(wid) {
    const list = document.getElementById('dash_applicants_list');
    list.innerHTML = '<div style="padding:40px; text-align:center; color:#94a3b8;">Lade Anfragen...</div>';
    
    fetch('ajax_unit_applicants.php?wohnung_id=' + wid)
    .then(r => r.json())
    .then(data => {
        if(!data || data.length === 0) {
            list.innerHTML = '<div style="padding:40px; text-align:center; color:#94a3b8;">Keine aktiven Anfragen.</div>';
        } else {
            let html = '<table class="spiegel-table"><thead><tr><th>Name</th><th>Status</th><th>Aktion</th></tr></thead><tbody>';
            data.forEach(a => {
                html += `<tr>
                    <td><strong>${a.vorname} ${a.nachname}</strong><br><small>${a.email}</small></td>
                    <td><span class="status-pill status-reserviert">${a.status}</span></td>
                    <td>
                        <div style="display:flex; gap:5px;">
                            <button class='btn btn-sm btn-blue' onclick='openTenantModal(${wid})'>Zuweisen</button>
                            <button class='btn btn-sm' style='background:#fee2e2; color:#b91c1c;' onclick='deleteApplicant(${a.id})'>🗑️</button>
                        </div>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            list.innerHTML = html;
        }
    });
}

function deleteApplicant(aid) {
    if (confirm("Möchten Sie diesen Interessenten wirklich löschen?")) {
        const f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = '<input type="hidden" name="action" value="delete_applicant">' +
                      '<input type="hidden" name="a_id" value="' + aid + '">';
        document.body.appendChild(f);
        f.submit();
    }
}


let currentDashWid = 0;

function toggleHistory(wid) {
    const row = document.getElementById('hist_' + wid);
    const icon = document.getElementById('icon_' + wid);
    if(row.style.display === 'table-row') {
        row.style.display = 'none';
        icon.innerText = '+';
    } else {
        row.style.display = 'table-row';
        icon.innerText = '-';
    }
}

function copyLink(wid) {
    const url = window.location.origin + "/pendenz.com/pages/anfrage.php?wohnung_id=" + wid;
    navigator.clipboard.writeText(url).then(() => alert("Anfrage-Link kopiert!"));
}

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
