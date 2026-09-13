<?php
// pages/quick_folder_editor.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$pid = (int)($_GET['projekt_id'] ?? 0);
if ($pid <= 0) die("Projekt-ID fehlt.");

$root = project_root_path($mysqli, $pid);
if (!$root || !is_dir($root)) {
    die("Kein gültiger Projekt-Root gefunden. Bitte zuerst in den Projekt-Einstellungen festlegen.");
}

// Projektdaten holen
$projQ = $mysqli->query("SELECT name, wohnungen_rel_path FROM projekte WHERE id = $pid");
$proj = $projQ->fetch_assoc();
$projName = $proj['name'] ?? "Unbekanntes Projekt";
$unitsRootPath = $proj['wohnungen_rel_path'] ?? '';

$path = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';
$absCurrent = fs_abs_from_rel($root, $path);

if (!$absCurrent || !is_dir($absCurrent)) {
    die("Pfad ungültig oder kein Verzeichnis: " . htmlspecialchars($path));
}

// Dateien/Ordner auflisten
$items = @scandir($absCurrent) ?: [];
$folders = [];
foreach ($items as $item) {
    if ($item==='.' || $item==='..') continue;
    if (is_dir($absCurrent . DIRECTORY_SEPARATOR . $item)) {
        $folders[] = $item;
    }
}

// Meta-Daten für Icons holen
$meta = [];
$relBase = $path === '' ? '' : $path . '/';
$resMeta = $mysqli->query("SELECT rel_path, icon FROM fs_folder_meta WHERE project_id = $pid AND rel_path LIKE '".$mysqli->real_escape_string($relBase)."%'");
while($m = $resMeta->fetch_assoc()) {
    $meta[$m['rel_path']] = $m['icon'];
}

$message = "";

function ensure_dir($abs) {
    if (!is_dir($abs)) @mkdir($abs, 0777, true);
}

// Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- WOHNUNGEN BASIS SETZEN ---
    if ($action === 'set_units_root') {
        $mysqli->query("UPDATE projekte SET wohnungen_rel_path = '".$mysqli->real_escape_string($path)."' WHERE id = $pid");
        $message = "📍 Dieser Ordner wurde als Wohnungs-Basis markiert.";
        $unitsRootPath = $path;
    }

    // --- IMPORT ALS EINHEITEN ---
    if ($action === 'import_as_units') {
        // 1. Basis setzen
        $mysqli->query("UPDATE projekte SET wohnungen_rel_path = '".$mysqli->real_escape_string($path)."' WHERE id = $pid");
        $unitsRootPath = $path;

        // 2. Synchronisieren
        $syncedCount = 0;
        $pathParts = explode('/', str_replace('\\', '/', $path));
        $objName = (count($pathParts) >= 2) ? $pathParts[count($pathParts)-2] : "Standard Objekt";

        $mysqli->query("INSERT IGNORE INTO objekte (projekt_id, name) VALUES ($pid, '".$mysqli->real_escape_string($objName)."')");
        $objIdRes = $mysqli->query("SELECT id FROM objekte WHERE projekt_id = $pid AND name = '".$mysqli->real_escape_string($objName)."'");
        $objId = $objIdRes->fetch_assoc()['id'];

        foreach($folders as $folderName) {
            $wName = $folderName;
            $zimmer = 0;
            $etage = '';
            if (preg_match('/^(?:\d+_)?(.+?),\s*(.+?)\s+(\d+(?:\.\d+)?)\s*Zi\./i', $folderName, $matches)) {
                $wName = trim($matches[1]);
                $etage = trim($matches[2]);
                $zimmer = (float)$matches[3];
            }

            $check = $mysqli->query("SELECT id FROM wohnungen WHERE objekt_id = $objId AND name = '".$mysqli->real_escape_string($wName)."'");
            if ($check->num_rows === 0) {
                $mysqli->query("INSERT INTO wohnungen (objekt_id, name, status, zimmer, etage) 
                                VALUES ($objId, '".$mysqli->real_escape_string($wName)."', 'verfuegbar', $zimmer, '".$mysqli->real_escape_string($etage)."')");
                $syncedCount++;
            }
        }
        $message = "🚀 Basis gesetzt und $syncedCount Einheiten erfolgreich importiert!";
    }

    // --- ICON SETZEN ---
    if ($action === 'set_icon') {
        $fName = trim($_POST['name'] ?? '');
        $icon = $_POST['icon'] ?? '';
        if ($fName !== '') {
            $fRel = $path === '' ? $fName : $path . '/' . $fName;
            $mysqli->query("INSERT INTO fs_folder_meta (project_id, rel_path, icon) VALUES ($pid, '".$mysqli->real_escape_string($fRel)."', '".$mysqli->real_escape_string($icon)."') ON DUPLICATE KEY UPDATE icon='".$mysqli->real_escape_string($icon)."'");
            $message = "✨ Symbol aktualisiert.";
        }
    }

    // --- UMBENENNEN ---
    if ($action === 'rename') {
        $oldName = trim($_POST['old_name'] ?? '');
        $newName = trim($_POST['new_name'] ?? '');
        if ($oldName !== '' && $newName !== '' && $oldName !== $newName) {
            $oldAbs = $absCurrent . DIRECTORY_SEPARATOR . $oldName;
            $newAbs = $absCurrent . DIRECTORY_SEPARATOR . $newName;
            if (is_dir($oldAbs) && !is_dir($newAbs)) {
                if (@rename($oldAbs, $newAbs)) {
                    $message = "✅ Umbenannt in '$newName'.";
                    // Pfade in Meta-DB updaten
                    $oldRel = $path === '' ? $oldName : $path . '/' . $oldName;
                    $newRel = $path === '' ? $newName : $path . '/' . $newName;
                    $mysqli->query("UPDATE fs_folder_meta SET rel_path = REPLACE(rel_path, '".$mysqli->real_escape_string($oldRel)."', '".$mysqli->real_escape_string($newRel)."') WHERE project_id = $pid AND rel_path LIKE '".$mysqli->real_escape_string($oldRel)."%'");
                    fs_scan_project($mysqli, $pid, 0);
                }
            }
        }
    }

    // --- MIETER ROTATION (Backup & Clear) ---
    if ($action === 'rotate_tenant') {
        $fName = trim($_POST['name'] ?? '');
        if ($fName !== '') {
            $curAbs = $absCurrent . DIRECTORY_SEPARATOR . $fName;
            $backupName = "Archiv_Vormieter_" . date('Y-m-d_H-i');
            $backupAbs = $curAbs . DIRECTORY_SEPARATOR . $backupName;
            ensure_dir($backupAbs);
            
            $subItems = scandir($curAbs);
            foreach($subItems as $si) {
                if($si==='.' || $si==='..' || $si===$backupName) continue;
                rename($curAbs . DIRECTORY_SEPARATOR . $si, $backupAbs . DIRECTORY_SEPARATOR . $si);
            }
            $message = "🔄 Mieter-Ordner archiviert und für neuen Mieter geleert.";
            fs_scan_project($mysqli, $pid, 0);
        }
    }

    // --- MUSTER SPEICHERN ---
    if ($action === 'save_as_template') {
        $tName = trim($_POST['new_template_name'] ?? '');
        if ($tName !== '') {
            $mysqli->query("INSERT INTO ordner_vorlagen (name, beschreibung) VALUES ('".$mysqli->real_escape_string($tName)."', 'Vom Quick Editor erstellt')");
            $tid = $mysqli->insert_id;
            if ($tid && !empty($folders)) {
                foreach ($folders as $f) {
                    $mysqli->query("INSERT INTO ordner_vorlagen_nodes (vorlage_id, rel_path, is_dir) VALUES ($tid, '".$mysqli->real_escape_string($f)."', 1)");
                }
                $message = "⭐ Muster '$tName' wurde gespeichert.";
            }
        }
    }

    // --- MUSTER LÖSCHEN ---
    if ($action === 'delete_template') {
        $tid = (int)($_POST['template_id'] ?? 0);
        if ($tid > 0) {
            $mysqli->query("DELETE FROM ordner_vorlagen WHERE id = $tid");
            $message = "🗑️ Muster wurde gelöscht.";
        }
    }

    // --- MUSTER HIER EINFÜGEN ---
    if ($action === 'apply_template_here') {
        $tid = (int)($_POST['template_id'] ?? 0);
        if ($tid > 0) {
            $resNodes = $mysqli->query("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id = $tid");
            while($rn = $resNodes->fetch_assoc()) {
                ensure_dir($absCurrent . DIRECTORY_SEPARATOR . $rn['rel_path']);
            }
            $message = "📥 Muster eingefügt.";
            fs_scan_project($mysqli, $pid, 0);
        }
    }

    // --- LÖSCHEN ---
    if ($action === 'delete') {
        $delName = trim($_POST['name'] ?? '');
        if ($delName !== '') {
            $delPath = $absCurrent . DIRECTORY_SEPARATOR . $delName;
            if (is_dir($delPath)) {
                function rrmdir($dir) {
                    if (is_dir($dir)) {
                        foreach (scandir($dir) as $obj) {
                            if ($obj != "." && $obj != "..") {
                                if (is_dir($dir.DIRECTORY_SEPARATOR.$obj)) rrmdir($dir.DIRECTORY_SEPARATOR.$obj);
                                else unlink($dir.DIRECTORY_SEPARATOR.$obj);
                            }
                        }
                        rmdir($dir);
                    }
                }
                rrmdir($delPath);
                $message = "🗑️ Ordner gelöscht.";
                fs_scan_project($mysqli, $pid, 0);
            }
        }
    }

    if ($action === 'create_folder') {
        $n = trim($_POST['folder_name'] ?? '');
        if ($n !== '') {
            ensure_dir($absCurrent . DIRECTORY_SEPARATOR . $n);
            $message = "✅ Ordner '$n' erstellt.";
            fs_scan_project($mysqli, $pid, 0);
        }
    }

    // Refresh Liste & Meta
    $items = @scandir($absCurrent) ?: [];
    $folders = [];
    foreach ($items as $item) {
        if ($item==='.' || $item==='..') continue;
        if (is_dir($absCurrent . DIRECTORY_SEPARATOR . $item)) $folders[] = $item;
    }
    $resMeta = $mysqli->query("SELECT rel_path, icon FROM fs_folder_meta WHERE project_id = $pid AND rel_path LIKE '".$mysqli->real_escape_string($relBase)."%'");
    $meta = []; while($m = $resMeta->fetch_assoc()) $meta[$m['rel_path']] = $m['icon'];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
    .quick-editor { max-width: 1000px; margin: 30px auto; padding: 20px; font-family: 'Inter', system-ui; }
    .folder-row { display: flex; align-items: center; gap: 12px; padding: 10px 15px; border-bottom: 1px solid #f1f5f9; background: #fff; border-radius: 10px; margin-bottom: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: 0.2s; }
    .folder-row:hover { transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    
    .icon-box { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; position: relative; font-size: 20px; }
    .icon-box:hover { background: #e2e8f0; }
    
    .folder-input { flex: 1; border: 1px solid transparent; padding: 8px; font-weight: 600; font-size: 15px; border-radius: 6px; color: #1e293b; }
    .folder-input:focus { border-color: #3b82f6; background: #f0f9ff; outline: none; }
    
    .btn-group { display: flex; gap: 5px; opacity: 0.4; transition: 0.2s; }
    .folder-row:hover .btn-group { opacity: 1; }
    
    .picker-popover { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; z-index: 1000; box-shadow: 0 10px 25px rgba(0,0,0,0.1); grid-template-columns: repeat(4, 1fr); gap: 5px; margin-top: 5px; }
    .picker-popover.active { display: grid; }
    .picker-popover button { background: none; border: 1px solid #f1f5f9; cursor: pointer; padding: 8px; border-radius: 6px; font-size: 18px; line-height: 1; }
    .picker-popover button:hover { background: #f1f5f9; }

    .tag-btn { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; color: #64748b; transition: 0.2s; }
    .tag-btn:hover { border-color: #3b82f6; color: #3b82f6; }
    .tag-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }

    .template-box { background: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 15px; padding: 25px; margin-top: 40px; }
    .alert { padding: 12px 20px; border-radius: 10px; margin-bottom: 20px; background: #ecfdf5; color: #065f46; display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px; }
</style>

<div class="quick-editor">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;">
        <h1 style="margin:0; font-size:24px; color:#0f172a;">⚡ Struktur-Master Pro</h1>
        <a href="projekt_dashboard.php?id=<?= $pid ?>&path=<?= rawurlencode($path) ?>" class="btn btn-gray" style="border-radius:10px;">Fertig & Zurück</a>
    </div>

    <?php if ($message): ?>
        <div class="alert">✨ <?= $message ?></div>
    <?php endif; ?>

    <div style="background:#fff; padding:15px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; display:flex; align-items:center; gap:15px;">
        <div style="flex:1;">
            <p style="margin:0 0 4px 0; font-size:12px; color:#94a3b8; font-weight:700; text-transform:uppercase;">Ort:</p>
            <div style="font-weight:600; font-size:16px; color:#334155;">
                🏠 <span style="color:#cbd5e1;"><?= htmlspecialchars($projName) ?> /</span> <?= htmlspecialchars($path) ?: 'Basis' ?>
            </div>
        </div>
        <form method="POST" style="display:flex; gap:8px;">
            <input type="hidden" name="action" value="create_folder">
            <input type="text" name="folder_name" placeholder="+ Neuer Ordner..." style="padding:10px 15px; border-radius:10px; border:1px solid #cbd5e1; width:200px;">
            <button type="submit" class="btn btn-blue" style="border-radius:10px;">Hinzufügen</button>
        </form>
    </div>

    <div style="background:#f8fafc; padding:15px; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:25px; display:flex; justify-content:space-between; align-items:center;">
        <div style="font-size:13px; color:#64748b;">
            <strong style="color:#1e293b;">Optionen für diesen Ort:</strong><br>
            Markiere diesen Pfad, um ihn mit dem Mieterspiegel zu verknüpfen.
        </div>
        <div style="display:flex; gap:10px;">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="import_as_units">
                <button type="submit" class="tag-btn" style="background:#f0f9ff; border-color:#3b82f6; color:#1d4ed8;">🚀 Unterordner als Einheiten importieren</button>
            </form>
            <?php if($path !== $unitsRootPath): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="set_units_root">
                    <button type="submit" class="tag-btn">📍 Nur als Basis markieren</button>
                </form>
            <?php else: ?>
                <div class="tag-btn active" style="background:#3b82f6; color:#fff; border-color:#3b82f6;">📍 Aktive Wohnungs-Basis</div>
            <?php endif; ?>
            <button class="tag-btn" onclick="openSaveTemplateModal()">💾 Muster speichern</button>
        </div>
    </div>

    <!-- Ordner-Liste -->
    <div class="folder-container">
        <?php foreach ($folders as $f): 
            $fRel = $path === '' ? $f : $path . '/' . $f;
            $icon = $meta[$fRel] ?? '📁';
        ?>
            <div class="folder-row">
                <!-- Icon Picker -->
                <div class="icon-box" onclick="togglePicker(this)">
                    <?= htmlspecialchars($icon) ?>
                    <div class="picker-popover">
                        <?php foreach(['📁','🏢','🏠','👤','📋','💰','🧱','📅','🔧','📷','📄','🔒'] as $emoji): ?>
                            <button onclick="setIcon('<?= htmlspecialchars($f) ?>', '<?= $emoji ?>')"><?= $emoji ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Name & Umbenennen -->
                <form method="POST" style="flex:1; margin:0; display:flex; align-items:center; gap:10px;" id="form_rename_<?= md5($f) ?>">
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="old_name" value="<?= htmlspecialchars($f) ?>">
                    <input type="text" name="new_name" value="<?= htmlspecialchars($f) ?>" 
                           class="folder-input" 
                           onchange="this.form.submit()" 
                           placeholder="Ordnername...">
                    
                    <div class="btn-group">
                        <button type="button" class="tag-btn <?= ($icon=='🏠')?'active':'' ?>" onclick="setIcon('<?= htmlspecialchars($f) ?>', '🏠')">Wohnung</button>
                        <button type="button" class="tag-btn <?= ($icon=='👤')?'active':'' ?>" onclick="setIcon('<?= htmlspecialchars($f) ?>', '👤')">Mieter</button>
                        <?php if($icon=='👤'): ?>
                            <button type="button" class="tag-btn" style="background:#fef9c3;" onclick="rotateTenant('<?= htmlspecialchars($f) ?>')">🔄 Archivieren</button>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Aktionen Buttons -->
                <div style="display:flex; gap:10px;">
                    <a href="?projekt_id=<?= $pid ?>&path=<?= rawurlencode($path === '' ? $f : $path . '/' . $f) ?>" class="btn btn-gray" style="padding:8px 15px; border-radius:8px; font-size:13px;">Inhalt ›</a>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="name" value="<?= htmlspecialchars($f) ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" style="background:none; border:none; cursor:pointer; font-size:18px; padding:5px;" onclick="return confirm('Sicher löschen?')">🗑️</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Versteckte Helper Forms -->
    <form id="helperForm" method="POST" style="display:none;">
        <input type="hidden" name="action" id="helperAction">
        <input type="hidden" name="name" id="helperName">
        <input type="hidden" name="icon" id="helperIcon">
    </form>

    <!-- Muster & Vorlagen -->
    <div class="template-box">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:30px;">
            <div>
                <h3 style="margin:0 0 5px 0; font-size:16px;">📥 Muster auf diesen Ordner anwenden</h3>
                <p style="font-size:12px; color:#64748b; margin-bottom:15px;">Struktur aus Vorlage hier hinzufügen.</p>
                <form method="POST">
                    <select name="template_id" style="width:100%; padding:12px; border-radius:10px; border:1px solid #cbd5e1; margin-bottom:12px;">
                        <option value="">-- Vorlage wählen --</option>
                        <?php
                        $templates = $mysqli->query("SELECT id, name FROM ordner_vorlagen ORDER BY name");
                        while($t = $templates->fetch_assoc()): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" name="action" value="apply_template_here" class="btn btn-blue" style="flex:1; border-radius:10px;">Hier anwenden</button>
                        <button type="submit" name="action" value="delete_template" class="btn btn-red" style="padding:10px; border-radius:10px;" onclick="return confirm('Muster löschen?')">🗑️</button>
                    </div>
                </form>
            </div>

            <div style="padding-left:30px; border-left:1px solid #e2e8f0;">
                <h3 style="margin:0 0 5px 0; font-size:16px;">⭐ Aktuelle Ansicht als Muster</h3>
                <p style="font-size:12px; color:#64748b; margin-bottom:15px;">Alle oben gelisteten Ordner als Vorlage speichern.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="save_as_template">
                    <input type="text" name="new_template_name" placeholder="Name für neues Muster..." required style="width:100%; padding:12px; border-radius:10px; border:1px solid #cbd5e1; margin-bottom:12px; box-sizing:border-box;">
                    <button type="submit" class="btn btn-blue" style="width:100%; border-radius:10px;">Struktur speichern</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function togglePicker(el) {
    const popover = el.querySelector('.picker-popover');
    document.querySelectorAll('.picker-popover').forEach(p => { if(p !== popover) p.classList.remove('active'); });
    popover.classList.toggle('active');
}

function setIcon(name, icon) {
    document.getElementById('helperAction').value = 'set_icon';
    document.getElementById('helperName').value = name;
    document.getElementById('helperIcon').value = icon;
    document.getElementById('helperForm').submit();
}

function rotateTenant(name) {
    if(confirm('Achtung: Der Inhalt wird archiviert und der Ordner für einen neuen Mieter geleert. Fortfahren?')) {
        document.getElementById('helperAction').value = 'rotate_tenant';
        document.getElementById('helperName').value = name;
        document.getElementById('helperForm').submit();
    }
}

// Close pickers on click outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.icon-box')) {
        document.querySelectorAll('.picker-popover').forEach(p => p.classList.remove('active'));
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
