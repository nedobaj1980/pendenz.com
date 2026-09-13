<?php
// pages/protokoll_designer.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Auto‑login for development if no session
if (!is_logged_in()) {
    $admin = $mysqli->query("SELECT id, name, rolle, email FROM benutzer WHERE rolle='superadmin' LIMIT 1")->fetch_assoc();
    if ($admin) {
        set_login_session((int)$admin['id'], $admin['name'], $admin['rolle'], $admin['email']);
    }
}


// Self-Healing: Prüfen ob is_default Spalte existiert
$check = $mysqli->query("SHOW COLUMNS FROM protokoll_vorlagen LIKE 'is_default'");
if ($check && $check->num_rows === 0) {
    $mysqli->query("ALTER TABLE protokoll_vorlagen ADD COLUMN is_default TINYINT(1) DEFAULT 0");
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: protokoll_manager.php');
    exit;
}

// Alle verfügbaren Vorlagen für den Manager laden
$templates = $mysqli->query("SELECT * FROM protokoll_vorlagen ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Aktuelle Vorlage laden
$stmt = $mysqli->prepare("SELECT v.*, t.name as typ_name, t.icon as typ_icon FROM protokoll_vorlagen v JOIN protokoll_typen t ON v.typ_id = t.id WHERE v.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$current_template = $stmt->get_result()->fetch_assoc();

if (!$current_template) {
    die("Vorlage nicht gefunden.");
}

$savedCfg = $current_template['json_data'] ?: null;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

// Eigene Firma / Branding Daten laden
$uId = current_user_id();
$userData = $mysqli->query("SELECT * FROM benutzer WHERE id = $uId")->fetch_assoc();
$firmaData = null;
if (!empty($userData['firma_id'])) {
    $firmaData = $mysqli->query("SELECT * FROM firmen WHERE id = " . (int) $userData['firma_id'])->fetch_assoc();
}

$branding = [
    'name' => $firmaData['name'] ?? $userData['firma_name'] ?? 'BAU-PARTNERSCHAFT',
    'email' => $firmaData['email'] ?? $userData['firma_email'] ?? '',
    'phone' => $firmaData['telefon'] ?? $userData['firma_telefon'] ?? '',
    'address' => $firmaData['adresse'] ?? $userData['firma_adresse'] ?? '',
    'logo' => $firmaData['logo'] ?? $userData['firmenlogo'] ?? ''
];
?>

<style>
    :root {
        --accent: #1abc9c;
        --sidebar-bg: #ffffff;
        --panel-bg: #f8fafc;
        --border: #e2e8f0;
    }

    .pd-architect-container {
        display: flex;
        background: #cbd5e1;
        height: calc(100vh - 100px);
        font-family: 'Inter', sans-serif;
        overflow: hidden;
        position: relative;
    }

    .pd-sidebar-left {
        width: 420px;
        min-width: 380px;
        background: var(--sidebar-bg);
        border-right: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        height: 100%;
        z-index: 50;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
        flex-shrink: 0;
    }

    .pd-sidebar-right {
        width: 650px;
        min-width: 400px;
        background: #fff;
        border-left: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        height: 100%;
        z-index: 50;
        box-shadow: -2px 0 10px rgba(0, 0, 0, 0.05);
        flex-shrink: 0;
    }

    .pd-panel-header {
        padding: 12px 20px;
        background: #0f172a;
        color: #fff;
        flex-shrink: 0;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .pd-panel-content {
        flex: 1;
        overflow-y: auto;
        padding: 15px;
        scrollbar-width: thin;
    }

    /* Sections */
    .section-title {
        font-size: 13px;
        font-weight: 800;
        color: #1e293b;
        margin: 15px 0 10px 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .pd-box {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 12px;
    }

    /* Manager Styles */
    .manager-select {
        width: 100%;
        padding: 10px;
        border: 2px solid var(--border);
        border-radius: 10px;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .btn-row {
        display: flex;
        gap: 5px;
        margin-bottom: 10px;
    }

    /* Input Grid */
    .inp-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 8px;
        margin-bottom: 10px;
    }

    .inp-grid label {
        display: block;
        font-size: 9px;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .inp-grid input {
        width: 100%;
        padding: 6px;
        border: 1px solid var(--border);
        border-radius: 6px;
        font-size: 11px;
    }

    .btn-group {
        display: flex;
        gap: 2px;
        margin-bottom: 10px;
        background: #f1f5f9;
        padding: 2px;
        border-radius: 6px;
    }

    .btn-group-item {
        flex: 1;
        padding: 6px;
        background: #fff;
        border: 1px solid #e2e8f0;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        text-align: center;
        border-radius: 4px;
        transition: all 0.2s;
        color: #475569;
    }

    .btn-group-item:hover {
        background: #f8fafc;
        border-color: var(--accent);
        color: var(--accent);
    }

    .btn-group-item.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }

    .nudge-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 2px;
        width: 100px;
        margin: 0 auto 10px;
    }

    .nudge-btn {
        padding: 8px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        cursor: pointer;
        text-align: center;
        font-size: 14px;
    }

    .nudge-btn:hover {
        background: #f1f5f9;
        border-color: var(--accent);
    }

    .color-picker-row {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
        align-items: center;
    }

    .color-item {
        flex: 1;
    }

    .color-item label {
        display: block;
        font-size: 9px;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 4px;
    }

    .color-inp {
        width: 100%;
        height: 30px;
        padding: 2px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        cursor: pointer;
        background: #fff;
    }

    /* Tags */
    .tag-group-title {
        font-size: 10px;
        font-weight: 800;
        color: #94a3b8;
        text-transform: uppercase;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 3px;
        margin: 12px 0 5px 0;
    }

    .pd-tag {
        display: inline-block;
        padding: 5px 10px;
        background: #f1f5f9;
        border-radius: 8px;
        font-size: 11px;
        margin: 2px;
        cursor: pointer;
        transition: 0.2s;
        font-weight: 600;
    }

    .pd-tag:hover {
        background: var(--accent);
        color: #fff;
    }

    /* Canvas */
    .pd-canvas-area {
        flex: 1;
        display: flex;
        justify-content: center;
        overflow: auto;
        padding: 60px;
        background: #94a3b8;
    }

    .pd-a4-sheet {
        width: 794px;
        height: 1123px;
        background: #fff;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
        flex-shrink: 0;
    }

    .pd-block {
        position: absolute;
        border: 1px dashed #cbd5e1;
        padding: 4px;
        background: rgba(255, 255, 255, 0.7);
        cursor: move;
        box-sizing: border-box;
        z-index: 10;
    }

    .pd-block.selected {
        border: 2px solid var(--accent);
        background: #fff;
        z-index: 100;
        box-shadow: 0 10px 25px rgba(26, 188, 156, 0.2);
    }

    .pd-margin-box {
        position: absolute;
        border: 1px dashed rgba(26, 188, 156, 0.4);
        pointer-events: none;
        box-sizing: border-box;
        background: rgba(26, 188, 156, 0.02);
        z-index: 5;
    }

    .pd-resizer-v {
        width: 8px;
        cursor: col-resize;
        background: #94a3b8;
        z-index: 1000;
    }

    .pd-resizer-v:hover {
        background: var(--accent);
    }

    #pd-resize-mask {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        z-index: 9999;
        display: none;
    }

    .btn-dark {
        background: #0f172a;
        color: #fff;
    }

    /* Auto-Scale Support */
    .pd-a4-sheet {
        transform-origin: top center;
        transition: transform 0.1s ease-out;
    }
</style>

<div id="pd-resize-mask"></div>

<div class="pd-architect-container">
    <!-- Links: Manager & Designer -->
    <div class="pd-sidebar-left" id="pd-sidebar-left">
        <div class="pd-panel-header">
            <span>📋 Vorlagen-Manager</span>
        </div>
        <div class="pd-panel-content">
            <!-- Vorlagen Auswahl -->
            <div class="pd-box">
                <label for="tplName" style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase;">Aktuelle
                    Vorlage: Name</label>
                <input type="text" id="tplName" name="tplName" class="manager-select"
                    value="<?= htmlspecialchars($current_template['name']) ?>">

                <label for="tplSelect"
                    style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; margin-top:10px; display:block;">Vorlage
                    wechseln</label>
                <select class="manager-select" id="tplSelect" name="tplSelect">
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>    <?= (!empty($t['is_default'])) ? ' (Standard)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="btn-row">
                    <button class="btn btn-primary" style="flex:1;" onclick="loadTemplate()">Laden</button>
                    <button class="btn btn-outline" style="flex:1;" onclick="setAsDefault()">Als Standard</button>
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <button class="btn btn-dark" onclick="importBaujournal()">🏗️ Baujournal Layout laden</button>
                    <button class="btn btn-primary" onclick="saveConfig()">Aktuelles Layout speichern</button>
                    <button class="btn btn-outline" onclick="saveAsNew()">💾 Als Vorlage speichern</button>
                </div>
            </div>

            <!-- Designer Section -->
            <div class="section-title">🎨 Layout Designer</div>

            <div class="pd-box" style="background:#fff7ed; border-color:#fdba74;">
                <div
                    style="font-size:11px; font-weight:800; color:#9a3412; text-transform:uppercase; margin-bottom:10px;">
                    📄 Blattgrösse & Ränder</div>
                <div class="btn-row">
                    <button class="btn btn-outline" id="btnPortrait" style="flex:1;"
                        onclick="setOrientation('portrait')">▯ Hochformat</button>
                    <button class="btn btn-outline" id="btnLandscape" style="flex:1;"
                        onclick="setOrientation('landscape')">▭ Querformat</button>
                </div>

                <table style="width:100%; border-collapse: collapse; margin-top:10px;">
                    <tr
                        style="text-align:left; font-size:9px; color:#9a3412; text-transform:uppercase; font-weight:800;">
                        <th>Dimension</th>
                        <th style="text-align:center;">Pixel (px)</th>
                        <th style="text-align:center;">Zentimeter (cm)</th>
                    </tr>
                    <tr>
                        <td style="font-size:11px; font-weight:600;"><label for="inpPageW">Breite</label></td>
                        <td><input type="number" id="inpPageW" name="inpPageW" class="manager-select"
                                style="margin:0; text-align:center;" oninput="syncDim('w','px')"></td>
                        <td><label for="inpPageWcm" class="pd-hidden">Breite cm</label><input type="number" id="inpPageWcm" name="inpPageWcm" class="manager-select"
                                style="margin:0; text-align:center;" step="0.1" oninput="syncDim('w','cm')"></td>
                    </tr>
                    <tr>
                        <td style="font-size:11px; font-weight:600;"><label for="inpPageH">Höhe</label></td>
                        <td><input type="number" id="inpPageH" name="inpPageH" class="manager-select"
                                style="margin:0; text-align:center;" oninput="syncDim('h','px')"></td>
                        <td><label for="inpPageHcm" class="pd-hidden">Höhe cm</label><input type="number" id="inpPageHcm" name="inpPageHcm" class="manager-select"
                                style="margin:0; text-align:center;" step="0.1" oninput="syncDim('h','cm')"></td>
                    </tr>
                </table>

                <div
                    style="font-size:10px; font-weight:800; color:#9a3412; text-transform:uppercase; margin-top:15px; margin-bottom:5px;">
                    Ränder</div>
                <table style="width:100%; border-collapse: collapse;">
                    <tr
                        style="text-align:left; font-size:9px; color:#9a3412; text-transform:uppercase; font-weight:800;">
                        <th>Richtung</th>
                        <th style="text-align:center;">Pixel (px)</th>
                        <th style="text-align:center;">Zentimeter (cm)</th>
                    </tr>
                    <?php foreach (['Top' => 'Oben', 'Left' => 'Links', 'Right' => 'Rechts', 'Bottom' => 'Unten'] as $key => $label): ?>
                        <tr>
                            <td style="font-size:11px; font-weight:600;"><label for="inpMargin<?= $key ?>"><?= $label ?></label></td>
                            <td><input type="number" id="inpMargin<?= $key ?>" name="inpMargin<?= $key ?>" class="manager-select"
                                    style="margin:0; text-align:center;" oninput="syncDim('m<?= $key ?>','px')"></td>
                            <td><label for="inpMargin<?= $key ?>cm" class="pd-hidden"><?= $label ?> cm</label><input type="number" id="inpMargin<?= $key ?>cm" name="inpMargin<?= $key ?>cm" class="manager-select"
                                    style="margin:0; text-align:center;" step="0.1" oninput="syncDim('m<?= $key ?>','cm')">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <div id="workspaceInfo"
                    style="margin-top:10px; font-size:10px; font-weight:700; color:#9a3412; text-align:center;"></div>
            </div>

            <!-- Block Inspector -->
            <div id="inspector">
                <div id="noSelection" class="pd-box" style="text-align:center; color:#64748b;">Kein Element ausgewählt
                </div>
                <div id="selectionPanel" class="pd-box pd-hidden">
                    <div
                        style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:10px;">
                        Block Einstellungen</div>

                    <div class="inp-grid">
                        <div><label for="inpX">X (px)</label><input type="number" id="inpX" name="inpX" oninput="debounceUpdate()"
                                onblur="updateBlock(true)"></div>
                        <div><label for="inpY">Y (px)</label><input type="number" id="inpY" name="inpY" oninput="debounceUpdate()"
                                onblur="updateBlock(true)"></div>
                        <div><label for="inpW">B (px)</label><input type="number" id="inpW" name="inpW" oninput="debounceUpdate()"
                                onblur="updateBlock(true)"></div>
                        <div><label for="inpH">H (px)</label><input type="number" id="inpH" name="inpH" oninput="debounceUpdate()"
                                onblur="updateBlock(true)"></div>
                    </div>

                    <label style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Seite Snap
                        (H/V)</label>
                    <div class="btn-group">
                        <div class="btn-group-item" title="Links" onclick="snapBlock('left')">L</div>
                        <div class="btn-group-item" title="Center" onclick="snapBlock('center')">C</div>
                        <div class="btn-group-item" title="Rechts" onclick="snapBlock('right')">R</div>
                    </div>
                    <div class="btn-group">
                        <div class="btn-group-item" title="Oben" onclick="snapBlock('top')">↑</div>
                        <div class="btn-group-item" title="Vertikal Zentriert" onclick="snapBlock('middle')">↔</div>
                        <div class="btn-group-item" title="Unten" onclick="snapBlock('bottom')">↓</div>
                    </div>

                    <label
                        style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Bewegen</label>
                    <div class="nudge-grid">
                        <div></div>
                        <div class="nudge-btn" onclick="nudgeBlock(0, -5)">↑</div>
                        <div></div>
                        <div class="nudge-btn" onclick="nudgeBlock(-5, 0)">←</div>
                        <div class="nudge-btn" onclick="snapBlock('center')">↔</div>
                        <div class="nudge-btn" onclick="nudgeBlock(5, 0)">→</div>
                        <div></div>
                        <div class="nudge-btn" onclick="nudgeBlock(0, 5)">↓</div>
                        <div></div>
                    </div>

                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                        <div style="flex:1;"><label for="inpFS"
                                style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Schriftgrösse</label><input
                                type="number" id="inpFS" name="inpFS" class="manager-select" style="margin:0;"
                                oninput="updateBlock()"></div>
                        <div class="btn-group" style="flex:1.5; margin:0; margin-top:14px;">
                            <div class="btn-group-item" id="btnBold" onclick="toggleStyle('bold')">B</div>
                            <div class="btn-group-item" id="btnItalic" onclick="toggleStyle('italic')">I</div>
                            <div class="btn-group-item" id="btnUnderline" onclick="toggleStyle('underline')">U</div>
                        </div>
                    </div>

                    <label style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Ausrichtung
                        (H / V)</label>
                    <div class="btn-group">
                        <div class="btn-group-item" id="btnAlignLeft" onclick="setAlign('left')">L</div>
                        <div class="btn-group-item" id="btnAlignCenter" onclick="setAlign('center')">C</div>
                        <div class="btn-group-item" id="btnAlignRight" onclick="setAlign('right')">R</div>
                    </div>
                    <div class="btn-group">
                        <div class="btn-group-item" id="btnValignTop" onclick="setValign('top')">↑</div>
                        <div class="btn-group-item" id="btnValignMiddle" onclick="setValign('middle')">↔</div>
                        <div class="btn-group-item" id="btnValignBottom" onclick="setValign('bottom')">↓</div>
                    </div>

                    <div class="color-picker-row">
                        <div class="color-item"><label for="inpColor">Schriftfarbe</label><input type="color" id="inpColor" name="inpColor"
                                class="color-inp" oninput="updateBlock()"></div>
                        <div class="color-item">
                            <label for="inpBgColor">Hintergrund</label>
                            <input type="color" id="inpBgColor" name="inpBgColor" class="color-inp" oninput="updateBlock()">
                            <div style="font-size:9px; cursor:pointer; color:var(--accent); margin-top:2px;"
                                onclick="document.getElementById('inpBgColor').value='#ffffff00'; updateBlock()">× Kein
                                Hintergrund</div>
                        </div>
                    </div>

                    <!-- NEU: Rahmen & Ecken -->
                    <div class="color-picker-row"
                        style="border-top:1px solid var(--border); padding-top:10px; margin-top:10px;">
                        <div class="color-item"><label for="inpBorderColor">Rahmenfarbe</label><input type="color" id="inpBorderColor" name="inpBorderColor"
                                class="color-inp" oninput="updateBlock()"></div>
                        <div class="color-item"><label for="inpBorderWidth">Rahmen (px)</label><input type="number" id="inpBorderWidth" name="inpBorderWidth"
                                class="manager-select" style="margin:0;" min="0" max="20" oninput="updateBlock()"></div>
                    </div>

                    <div style="margin-top:10px;">
                        <label style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Ecken
                            abrunden (px)</label>
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:5px; margin-top:4px;">
                            <div style="position:relative;"><label for="inpRadTL" class="pd-hidden">Ecke oben links</label><input type="number" id="inpRadTL" name="inpRadTL" placeholder="TL"
                                    class="manager-select" style="margin:0; font-size:10px;"
                                    oninput="updateBlock()"><span
                                    style="position:absolute; right:5px; top:5px; font-size:8px; color:#999;">↖</span>
                            </div>
                            <div style="position:relative;"><label for="inpRadTR" class="pd-hidden">Ecke oben rechts</label><input type="number" id="inpRadTR" name="inpRadTR" placeholder="TR"
                                    class="manager-select" style="margin:0; font-size:10px;"
                                    oninput="updateBlock()"><span
                                    style="position:absolute; right:5px; top:5px; font-size:8px; color:#999;">↗</span>
                            </div>
                            <div style="position:relative;"><label for="inpRadBL" class="pd-hidden">Ecke unten links</label><input type="number" id="inpRadBL" name="inpRadBL" placeholder="BL"
                                    class="manager-select" style="margin:0; font-size:10px;"
                                    oninput="updateBlock()"><span
                                    style="position:absolute; right:5px; top:5px; font-size:8px; color:#999;">↙</span>
                            </div>
                            <div style="position:relative;"><label for="inpRadBR" class="pd-hidden">Ecke unten rechts</label><input type="number" id="inpRadBR" name="inpRadBR" placeholder="BR"
                                    class="manager-select" style="margin:0; font-size:10px;"
                                    oninput="updateBlock()"><span
                                    style="position:absolute; right:5px; top:5px; font-size:8px; color:#999;">↘</span>
                            </div>
                        </div>
                    </div>

                    <label for="inpText" style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Inhalt /
                        Tags</label>
                    <textarea id="inpText" name="inpText"
                        style="width:100%; height:80px; margin-top:4px; border:1px solid var(--border); border-radius:8px; padding:10px; font-size:12px;"
                        oninput="updateBlock()"></textarea>
                    <button class="btn btn-outline"
                        style="width:100%; margin-top:10px; color:#b91c1c; font-size:11px; padding:6px;"
                        onclick="deleteBlock()">🗑️ Block löschen</button>
                </div>
            </div>

            <button class="btn btn-primary" style="width:100%; padding:15px; font-size:13px;" onclick="addBlock()">➕
                Neuer Text/Bild Block</button>

            <!-- Tags -->
            <div class="section-title">📊 Daten-Felder (Tags)</div>
            <div class="pd-box">
                <label for="inpTagSearch" class="pd-hidden">Tags suchen</label>
                <input type="text" id="inpTagSearch" name="inpTagSearch" placeholder="Feld suchen..."
                    style="width:100%; padding:8px; border:1px solid var(--border); border-radius:8px; margin-bottom:10px;"
                    oninput="filterTags(this.value)">

                <div class="tag-group-title">⏱️ Termine & Planung</div>
                <span class="pd-tag" onclick="addTag('{DATUM}')">📅 Datum</span>
                <span class="pd-tag" onclick="addTag('{UHRZEIT}')">🕐 Uhrzeit</span>

                <div class="tag-group-title">🏠 Stammdaten & Bilder</div>
                <span class="pd-tag" onclick="addTag('{PROJEKT}')">🏗️ Projekt</span>
                <span class="pd-tag" onclick="addTag('{OBJEKT}')">🏢 Objekt</span>
                <span class="pd-tag" onclick="addTag('{WOHNUNG}')">🚪 Wohnung</span>
                <span class="pd-tag" onclick="addTag('{RAUM}')">🛋️ Raum</span>
                <span class="pd-tag" onclick="addTag('{LOGO}')">🖼️ Firmenlogo</span>
                <span class="pd-tag" onclick="addTag('{COVER}')">🖼️ Cover-Bild</span>

                <div class="tag-group-title">🏛️ Eigene Firma / Branding</div>
                <span class="pd-tag" onclick="addTag('{COMPANY_NAME}')">🏢 Firma Name</span>
                <span class="pd-tag" onclick="addTag('{COMPANY_EMAIL}')">📧 Firma E-Mail</span>
                <span class="pd-tag" onclick="addTag('{HEADER}')">🏛️ Header (Auto)</span>

                <div class="tag-group-title">🏗️ Bau-Spezifisch (Baujournal)</div>
                <span class="pd-tag" onclick="addTag('{OBJ_NR}')">🔢 Objekt-Nr.</span>
                <span class="pd-tag" onclick="addTag('{WV_DATE}')">📅 Werkvertrag Datum</span>
                <span class="pd-tag" onclick="addTag('{WV_NR}')">📄 WV-Nr.</span>
                <span class="pd-tag" onclick="addTag('{BKP}')">🏗️ Betr. BKP</span>
                <span class="pd-tag" onclick="addTag('{PARZELLE}')">🗺️ Grundstück</span>
                <span class="pd-tag" onclick="addTag('{WEATHER}')">🌦️ Witterung/Wetter</span>
                <span class="pd-tag" onclick="addTag('{DEADLINE}')">🏁 Behebung bis</span>
                <span class="pd-tag" onclick="addTag('{INTRO}')">📝 Einleitung Text</span>
                <span class="pd-tag" onclick="addTag('{OUTRO}')">📝 Schlusswort Text</span>
                <span class="pd-tag" onclick="addTag('{TEILNEHMER}')">👥 Teilnehmer-Liste</span>
                <span class="pd-tag" onclick="addTag('{SIA}')">⚖️ SIA Regelungen</span>

                <div class="tag-group-title">📋 Automatisches Formular</div>
                <span class="pd-tag" style="background:#fdf2f8; color:#be185d;" onclick="addTag('{ALL_DATA}')">✨ ALLE
                    DATEN ✨</span>
            </div>

            <!-- Footer Actions -->
            <div class="pd-box" style="background:#f0fdf4; border-color:#bbf7d0;">
                <label for="cfgColor" style="font-size:11px; font-weight:bold; color:#166534;">🎨 Themen-Farbe</label>
                <input type="color" id="cfgColor" name="cfgColor"
                    style="width:100%; height:40px; border:none; background:none; cursor:pointer;">
            </div>

            <div class="pd-box" style="background:#f0f9ff; border-color:#bae6fd;">
                <div style="font-size:11px; font-weight:bold; color:#0369a1; margin-bottom:10px;">🖼️ Bild-Einstellungen
                </div>
                <div style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label for="inpCoverH" style="font-size:9px; font-weight:800; color:#0369a1;">Cover-Höhe (px)</label>
                        <input type="number" id="inpCoverH" name="inpCoverH" class="manager-select" style="margin:0;"
                            oninput="updateGlobal()">
                    </div>
                    <div style="flex:1;">
                        <label for="inpGalleryH" style="font-size:9px; font-weight:800; color:#0369a1;">Galerie Höhe (px)</label>
                        <input type="number" id="inpGalleryH" name="inpGalleryH" class="manager-select" style="margin:0;"
                            oninput="updateGlobal()">
                    </div>
                </div>
            </div>

            <button class="btn btn-primary" style="width:100%; padding:15px; font-size:14px; margin-bottom:10px;"
                onclick="saveConfig()">💾 Layout Speichern</button>
            <div style="display:flex; gap:5px; margin-bottom:30px;">
                <button class="btn btn-dark" style="flex:1;" onclick="toggleRightSidebar()">👁️
                    Echtzeit-Vorschau</button>
                <button class="btn btn-outline" style="flex:1;" onclick="testPdf()">📄 Echtes PDF testen</button>
            </div>
        </div>
    </div>

    <div class="pd-resizer-v" id="pd-resizer-left"></div>

    <!-- Mitte: Canvas -->
    <div style="flex: 1; display: flex; flex-direction: column; min-width: 0;">
        <div class="pd-panel-header" style="background: #334155;">
            <span>🎨 STUDIO CANVAS</span>
        </div>
        <div class="pd-canvas-area">
            <div class="pd-a4-sheet" id="pd-canvas"></div>
        </div>
    </div>

    <div class="pd-resizer-v" id="pd-resizer-right"></div>

    <!-- Rechts: Test-Umgebung -->
    <div class="pd-sidebar-right" id="pd-sidebar-right">
        <div class="pd-panel-header">
            <span>🧪 Live Test-Umgebung</span>
            <button class="btn btn-outline" style="padding:4px 8px; font-size:9px;"
                onclick="document.getElementById('pd-test-frame').contentWindow.location.reload();">🔄 Refresh</button>
        </div>
        <div style="flex: 1; position: relative;">
            <iframe id="pd-test-frame" src="protokoll_test.php?id=<?= $id ?>&embed=1&_t=<?= time() ?>" onload="render()"
                style="width: 100%; height: 100%; border: none;"></iframe>

        </div>
    </div>
</div>

<script>
    const PX_TO_CM = 37.795;
    let selectedIds = []; // Array für Multi-Selection
    let currentScale = 1;
    const SAVED = <?= $savedCfg ?: 'null' ?>;
    let pageConfig = { width: 794, height: 1123, marginTop: 53, marginBottom: 53, marginLeft: 53, marginRight: 53 };
    let CONFIG = SAVED || { page: pageConfig, color: '#1abc9c', coverH: 220, galleryH: 110, blocks: [] };

    const BRANDING = <?= json_encode($branding) ?>;

    // LIVE SYNC DATA
    let TEST_DATA = {};
    window.addEventListener('message', (event) => {
        if (event.data.type === 'sync_data') {
            TEST_DATA = event.data.data;
            render();
        }
        if (event.data.type === 'select_block') {
            const multi = event.data.ctrlKey && event.data.altKey;
            selectBlock(event.data.id, multi);
        }
    });

    function replaceTags(text) {
        if (!text) return '';
        let out = text;

        const fullData = (TEST_DATA.INTRO || '') + "\n\n" + (TEST_DATA.CONTENT || '') + "\n" + (TEST_DATA.OUTRO || '');

        // Logo HTML aufbereiten
        let logoHtml = '🚩 [LOGO]';
        if (BRANDING.logo) {
            let logoPath = BRANDING.logo;
            // Verhindere doppelte Präfixe
            if (!logoPath.startsWith('http') && !logoPath.startsWith('/pendenz.com/')) {
                logoPath = '/pendenz.com/' + logoPath.replace(/^\//, '');
            }
            logoHtml = `<img src="${logoPath}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
        }

        const headerHtml = `<strong>${BRANDING.name}</strong><br><span style="font-size:0.9em; color:#64748b;">Protokoll / Bericht</span>`;

        let teilnehmerHtml = TEST_DATA.TEILNEHMER || '{TEILNEHMER}';
        if (TEST_DATA.TEILNEHMER_LIST && TEST_DATA.TEILNEHMER_LIST.length > 0) {
            teilnehmerHtml = `<table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:10px; font-family:inherit;">
                <thead><tr style="border-bottom:2px solid #1abc9c; color:#1e293b; font-weight:800; text-align:left; font-size:10px; text-transform:uppercase;">
                    <th style="padding:8px 0;">Name</th>
                    <th style="padding:8px 0;">Rolle / Firma</th>
                    <th style="padding:8px 0;">Email</th>
                </tr></thead>
                <tbody>` + TEST_DATA.TEILNEHMER_LIST.map(t => `
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td style="padding:8px 0;"><strong>${t.name}</strong></td>
                    <td style="padding:8px 0; color:#64748b;">${t.role}</td>
                    <td style="padding:8px 0; color:#94a3b8; font-size:0.9em;">${t.email || '-'}</td>
                </tr>`).join('') + `</tbody></table>`;
        }

        // Standard Tags Map
        const map = {
            'TITEL': TEST_DATA.TITEL || '{TITEL}',
            'BETREFF': TEST_DATA.BETREFF || '{BETREFF}',
            'PROJEKT': TEST_DATA.PROJEKT || '{PROJEKT}',
            'OBJEKT': TEST_DATA.OBJEKT || '{OBJEKT}',
            'OBJ_NR': TEST_DATA.OBJ_NR || '{OBJ_NR}',
            'BKP': TEST_DATA.BKP || '{BKP}',
            'WV_NR': TEST_DATA.WV_NR || '{WV_NR}',
            'WV_DATE': TEST_DATA.WV_DATE || '{WV_DATE}',
            'PARZELLE': TEST_DATA.PARZELLE || '{PARZELLE}',
            'WEATHER': TEST_DATA.WEATHER || '{WEATHER}',
            'TEMP_MIN': (TEST_DATA.TEMP_MIN || 'min') + '°C',
            'TEMP_MAX': (TEST_DATA.TEMP_MAX || 'max') + '°C',
            'TEMP_RANGE': TEST_DATA.TEMP_RANGE || '🌡️ [TEMP] °C',
            'Temperatur': TEST_DATA.TEMP_RANGE || '🌡️ [TEMP] °C',
            'DATUM': TEST_DATA.DATUM || '{DATUM}',
            'UHRZEIT': TEST_DATA.UHRZEIT || '{UHRZEIT}',
            'WOHNUNG': TEST_DATA.WOHNUNG || '{WOHNUNG}',
            'RAUM': TEST_DATA.RAUM || '{RAUM}',
            'ORT': TEST_DATA.ORT || '{ORT}',
            'LEITUNG': TEST_DATA.LEITUNG || '{LEITUNG}',
            'DEADLINE': TEST_DATA.DEADLINE || '{DEADLINE}',
            'INTRO': TEST_DATA.INTRO || '{INTRO}',
            'OUTRO': TEST_DATA.OUTRO || '{OUTRO}',
            'TEILNEHMER': teilnehmerHtml,
            'UNTERSCHRIFTEN': `<div style="border-top:1px solid #1e293b; margin-top:40px; padding-top:5px; font-size:10px; color:#64748b; width:200px;">Unterschrift Bauleitung</div>`,
            'SIG_UNTERNEHMER': `<div style="margin-top:20px;"><div style="border-bottom:1px solid #1e293b; height:30px; width:250px;"></div><div style="font-size:10px; color:#64748b; margin-top:5px; text-transform:uppercase; font-weight:800;">Unterschrift Unternehmer ${TEST_DATA.SIG_UNTERNEHMER ? '('+TEST_DATA.SIG_UNTERNEHMER+')' : ''}</div></div>`,
            'COMPANY_NAME': BRANDING.name,
            'COMPANY_EMAIL': BRANDING.email || '📧 [EMAIL]',
            'COMPANY_PHONE': BRANDING.phone || '📞 [TELEFON]',
            'COMPANY_ADDRESS': BRANDING.address || '📍 [ADRESSE]',
            'LOGO': logoHtml,
            'HEADER': headerHtml,
            'ALL_DATA': fullData.trim() || '✨ [ALLE DATEN] ✨'
        };
        for (const [tag, val] of Object.entries(map)) {
            const regex = new RegExp('{' + tag + '}', 'gi');
            out = out.replace(regex, val);
        }
        return out;
    }

    window.addEventListener('load', () => {
        // Restore sidebar widths
        const savedL = localStorage.getItem('pd_side_l');
        const savedR = localStorage.getItem('pd_side_r');
        if (savedL) document.getElementById('pd-sidebar-left').style.width = savedL + 'px';
        if (savedR) document.getElementById('pd-sidebar-right').style.width = savedR + 'px';

        if (CONFIG.page) pageConfig = CONFIG.page;
        initInputs();

        // Erkennen ob JSON im alten Format (kein 'page'-Key, oder Blöcke ohne x/y Canvas-Koordinaten)
        // Das betrifft ALLE alten Vorlagen aus dem Manager (old form-data format, not canvas format)
        const hasDesignerBlocks = SAVED &&
            SAVED.page &&
            Array.isArray(SAVED.blocks) &&
            SAVED.blocks.length > 0 &&
            typeof SAVED.blocks[0].x === 'number'; // Canvas-Block hat immer x-Koordinate

        if (!hasDesignerBlocks) {
            // KEIN Auto-Import mehr: jede Vorlage beginnt leer (der Nutzer wählt selbst das Layout)
            if (SAVED) {
                showToast('📋 Vorlage im alten Format. Klicken Sie "🏗️ Baujournal Layout laden" um das Standard-Layout zu übernehmen.');
            } else {
                showToast('🆕 Neue leere Vorlage – Block hinzufügen oder Layout laden.');
            }
        }

        render();
        autoScaleCanvas();
    });


    window.addEventListener('resize', autoScaleCanvas);

    function autoScaleCanvas() {
        const area = document.querySelector('.pd-canvas-area');
        const sheet = document.querySelector('.pd-a4-sheet');
        if (!area || !sheet) return;

        const padding = 60; // 30px pro Seite
        const targetW = area.clientWidth - padding;
        const targetH = area.clientHeight - padding;
        const originalW = pageConfig.width;
        const originalH = pageConfig.height;

        let scaleW = targetW / originalW;
        let scaleH = targetH / originalH;
        currentScale = Math.min(scaleW, scaleH, 1); // Maximal 100%

        sheet.style.transform = `scale(${currentScale})`;
    }

    function initInputs() {
        document.getElementById('inpPageW').value = pageConfig.width;
        document.getElementById('inpPageH').value = pageConfig.height;
        document.getElementById('inpMarginTop').value = pageConfig.marginTop;
        document.getElementById('inpMarginBottom').value = pageConfig.marginBottom;
        document.getElementById('inpMarginLeft').value = pageConfig.marginLeft;
        document.getElementById('inpMarginRight').value = pageConfig.marginRight;

        document.getElementById('cfgColor').value = CONFIG.color || '#1abc9c';
        document.getElementById('inpCoverH').value = CONFIG.coverH || 220;
        document.getElementById('inpGalleryH').value = CONFIG.galleryH || 110;

        syncDim('w', 'px'); syncDim('h', 'px');
        syncDim('mTop', 'px'); syncDim('mLeft', 'px'); syncDim('mRight', 'px'); syncDim('mBottom', 'px');
    }

    function syncDim(key, unit) {
        const pxEl = document.getElementById('inpPage' + key.toUpperCase()) || document.getElementById('inpMargin' + key.replace('m', ''));
        const cmEl = document.getElementById('inpPage' + key.toUpperCase() + 'cm') || document.getElementById('inpMargin' + key.replace('m', '') + 'cm');

        if (!pxEl || !cmEl) return;

        if (unit === 'px') {
            cmEl.value = (pxEl.value / PX_TO_CM).toFixed(1);
        } else {
            pxEl.value = Math.round(cmEl.value * PX_TO_CM);
        }

        // Update Page Config
        if (key === 'w') pageConfig.width = parseInt(pxEl.value);
        if (key === 'h') pageConfig.height = parseInt(pxEl.value);
        if (key.startsWith('m')) {
            const prop = 'margin' + key.replace('m', '');
            pageConfig[prop] = parseInt(pxEl.value);
        }

        updateWorkspaceInfo();
        render();
    }

    function updateWorkspaceInfo() {
        const w = pageConfig.width - pageConfig.marginLeft - pageConfig.marginRight;
        const wcm = (w / PX_TO_CM).toFixed(1);
        document.getElementById('workspaceInfo').innerText = `Arbeitsbereich Breite: ${w} px / ${wcm} cm`;
    }

    function render() {
        const canvas = document.getElementById('pd-canvas');
        canvas.innerHTML = '';
        canvas.style.width = pageConfig.width + 'px';
        canvas.style.height = pageConfig.height + 'px';
        canvas.style.position = 'relative';   // Nötig damit absolute Blöcke korrekt positioniert werden
        canvas.style.background = '#fff';
        canvas.style.overflow = 'hidden';


        // Satzspiegel / Margin Box zeichnen
        const mBox = document.createElement('div');
        mBox.className = 'pd-margin-box';
        mBox.style.top = pageConfig.marginTop + 'px';
        mBox.style.left = pageConfig.marginLeft + 'px';
        mBox.style.width = (pageConfig.width - pageConfig.marginLeft - pageConfig.marginRight) + 'px';
        mBox.style.height = (pageConfig.height - pageConfig.marginTop - pageConfig.marginBottom) + 'px';
        canvas.appendChild(mBox);

        // Sicherheitscheck: blocks muss ein Array sein
        if (!Array.isArray(CONFIG.blocks)) CONFIG.blocks = [];

        CONFIG.blocks.forEach(b => {

            const div = document.createElement('div');
            const isSelected = selectedIds.includes(b.id);
            div.className = 'pd-block' + (isSelected ? ' selected' : '');
            div.style.left = b.x + 'px'; div.style.top = b.y + 'px';
            div.style.width = b.w + 'px'; div.style.height = b.h + 'px';
            div.style.fontSize = (b.fontSize || 12) + 'px';

            // Styles anwenden
            if (b.bold) div.style.fontWeight = 'bold';
            if (b.italic) div.style.fontStyle = 'italic';
            if (b.underline) div.style.textDecoration = 'underline';
            if (b.color) div.style.color = b.color;
            if (b.bgColor) div.style.backgroundColor = b.bgColor;
            if (b.align) div.style.textAlign = b.align;

            // Rahmen & Radius
            if (b.borderWidth > 0) {
                div.style.border = `${b.borderWidth}px solid ${b.borderColor || '#cbd5e1'}`;
            } else {
                div.style.border = '1px dashed #cbd5e1'; // Default Hilfslinie
            }
            if (b.radTL) div.style.borderTopLeftRadius = b.radTL + 'px';
            if (b.radTR) div.style.borderTopRightRadius = b.radTR + 'px';
            if (b.radBL) div.style.borderBottomLeftRadius = b.radBL + 'px';
            if (b.radBR) div.style.borderBottomRightRadius = b.radBR + 'px';

            if (b.valign) {
                div.style.display = 'flex';
                div.style.flexDirection = 'column';
                div.style.justifyContent = b.valign === 'middle' ? 'center' : (b.valign === 'bottom' ? 'flex-end' : 'flex-start');
            }

            div.innerHTML = replaceTags(b.text);
            div.onmousedown = (e) => startDrag(e, b.id);
            div.onclick = (e) => {
                const multi = e.ctrlKey && e.altKey;
                selectBlock(b.id, multi);
            };
            canvas.appendChild(div);
        });

        // Sync active tags to test frame in correct order (Y then X)
        const sortedBlocks = [...CONFIG.blocks].sort((a, b) => (a.y - b.y) || (a.x - b.x));
        const allText = sortedBlocks.map(b => b.text).join(' ');
        const tags = Array.from(allText.matchAll(/\{([A-Z_]+)\}/gi)).map(m => m[1].toUpperCase());

        const frame = document.getElementById('pd-test-frame');
        if (frame && frame.contentWindow) {
            frame.contentWindow.postMessage({
                type: 'sync_config',
                config: CONFIG,
                branding: BRANDING,
                tags: [...new Set(tags)]
            }, '*');
        }
    }

    function selectBlock(id, multi = false) {
        if (multi) {
            if (selectedIds.includes(id)) {
                selectedIds = selectedIds.filter(x => x !== id);
            } else {
                selectedIds.push(id);
            }
        } else {
            selectedIds = [id];
        }

        const b = CONFIG.blocks.find(x => x.id === id);
        if (!b && selectedIds.length === 0) {
            document.getElementById('noSelection').classList.remove('pd-hidden');
            document.getElementById('selectionPanel').classList.add('pd-hidden');
            render();
            return;
        }

        document.getElementById('noSelection').classList.add('pd-hidden');
        document.getElementById('selectionPanel').classList.remove('pd-hidden');

        // Inspector zeigt immer die Daten des ZULETZT gewählten Blocks (oder des einzigen)
        const displayBlock = b || CONFIG.blocks.find(x => x.id === selectedIds[selectedIds.length - 1]);
        if (displayBlock) {
            document.getElementById('inpX').value = displayBlock.x;
            document.getElementById('inpY').value = displayBlock.y;
            document.getElementById('inpW').value = displayBlock.w;
            document.getElementById('inpH').value = displayBlock.h;
            document.getElementById('inpFS').value = displayBlock.fontSize || 12;
            // Farb-Picker braucht 6-stelliges Hex (#rrggbb), kein Alpha
            const toHex6 = c => (c && c.length === 9) ? c.substring(0, 7) : (c || '#334155');
            document.getElementById('inpColor').value = toHex6(displayBlock.color || '#334155');
            document.getElementById('inpBgColor').value = toHex6(displayBlock.bgColor || '#ffffff');

            document.getElementById('inpBorderColor').value = displayBlock.borderColor || '#cbd5e1';
            document.getElementById('inpBorderWidth').value = displayBlock.borderWidth || 0;
            document.getElementById('inpRadTL').value = displayBlock.radTL || 0;
            document.getElementById('inpRadTR').value = displayBlock.radTR || 0;
            document.getElementById('inpRadBL').value = displayBlock.radBL || 0;
            document.getElementById('inpRadBR').value = displayBlock.radBR || 0;
            document.getElementById('inpText').value = displayBlock.text;

            // Button States aktualisieren
            document.getElementById('btnBold').classList.toggle('active', !!displayBlock.bold);
            document.getElementById('btnItalic').classList.toggle('active', !!displayBlock.italic);
            document.getElementById('btnUnderline').classList.toggle('active', !!displayBlock.underline);

            document.querySelectorAll('[id^="btnAlign"]').forEach(btn => btn.classList.remove('active'));
            if (displayBlock.align) document.getElementById('btnAlign' + displayBlock.align.charAt(0).toUpperCase() + displayBlock.align.slice(1))?.classList.add('active');

            document.querySelectorAll('[id^="btnValign"]').forEach(btn => btn.classList.remove('active'));
            if (displayBlock.valign) document.getElementById('btnValign' + displayBlock.valign.charAt(0).toUpperCase() + displayBlock.valign.slice(1))?.classList.add('active');
        }

        if (selectedIds.length > 1) {
            const title = document.querySelector('#selectionPanel div:first-child');
            if (title) title.innerText = `Block Einstellungen (${selectedIds.length} ausgewählt)`;
        } else {
            const title = document.querySelector('#selectionPanel div:first-child');
            if (title) title.innerText = `Block Einstellungen`;
        }

        render();
    }

    function startDrag(e, id) {
        if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') return;

        const multi = e.ctrlKey && e.altKey;
        if (!selectedIds.includes(id)) {
            selectBlock(id, multi);
        }

        // DUPLIZIEREN mit CTRL-Taste (nur wenn Alt NICHT gedrückt ist, sonst ist es Multi-Selection)
        if (e.ctrlKey && !e.altKey) {
            const original = CONFIG.blocks.find(x => x.id === id);
            if (original) {
                const clone = JSON.parse(JSON.stringify(original));
                clone.id = 'b_' + Date.now();
                CONFIG.blocks.push(clone);
                selectBlock(clone.id, false);
            }
        }

        const dragGroup = selectedIds.map(sid => CONFIG.blocks.find(x => x.id === sid)).filter(x => !!x);
        let startX = e.clientX, startY = e.clientY;

        document.onmousemove = (me) => {
            const dx = (me.clientX - startX) / currentScale;
            const dy = (me.clientY - startY) / currentScale;

            dragGroup.forEach(b => {
                b.x += dx;
                b.y += dy;

                // Clamping für jedes Element in der Gruppe
                const minX = pageConfig.marginLeft;
                const maxX = pageConfig.width - pageConfig.marginRight - b.w;
                const minY = pageConfig.marginTop;
                const maxY = pageConfig.height - pageConfig.marginBottom - b.h;
                b.x = Math.max(minX, Math.min(b.x, maxX));
                b.y = Math.max(minY, Math.min(b.y, maxY));
            });

            startX = me.clientX; startY = me.clientY;
            if (dragGroup.length === 1) updateInspectorPos(dragGroup[0]);
            render();
        };
        document.onmouseup = () => { document.onmousemove = null; };
    }

    function updateInspectorPos(b) {
        document.getElementById('inpX').value = b.x;
        document.getElementById('inpY').value = b.y;
        updateBlock(true);
    }

    let updateTimer = null;
    function debounceUpdate() {
        if (updateTimer) clearTimeout(updateTimer);
        updateTimer = setTimeout(() => {
            updateBlock();
        }, 1000); // 1 Sekunde Verzögerung beim Tippen
    }

    function updateBlock(immediate = false) {
        if (selectedIds.length === 0) return;
        const b = CONFIG.blocks.find(x => x.id === selectedIds[selectedIds.length - 1]);
        if (!b) return;

        let newX = parseInt(document.getElementById('inpX').value) || 0;
        let newY = parseInt(document.getElementById('inpY').value) || 0;
        let newW = parseInt(document.getElementById('inpW').value) || b.w;
        let newH = parseInt(document.getElementById('inpH').value) || b.h;

        const minX = pageConfig.marginLeft;
        const maxX = pageConfig.width - pageConfig.marginRight - newW;
        const minY = pageConfig.marginTop;
        const maxY = pageConfig.height - pageConfig.marginBottom - newH;

        if (immediate) {
            b.x = Math.max(minX, Math.min(newX, maxX));
            b.y = Math.max(minY, Math.min(newY, maxY));
            b.w = newW;
            b.h = newH;
            document.getElementById('inpX').value = b.x;
            document.getElementById('inpY').value = b.y;
        } else {
            // Beim Tippen lassen wir den Wert erst mal so, damit man "1033" tippen kann
            b.x = newX;
            b.y = newY;
            b.w = newW;
            b.h = newH;
        }

        b.fontSize = parseInt(document.getElementById('inpFS').value);
        b.color = document.getElementById('inpColor').value;
        b.bgColor = document.getElementById('inpBgColor').value;

        b.borderColor = document.getElementById('inpBorderColor').value;
        b.borderWidth = parseInt(document.getElementById('inpBorderWidth').value) || 0;
        b.radTL = parseInt(document.getElementById('inpRadTL').value) || 0;
        b.radTR = parseInt(document.getElementById('inpRadTR').value) || 0;
        b.radBL = parseInt(document.getElementById('inpRadBL').value) || 0;
        b.radBR = parseInt(document.getElementById('inpRadBR').value) || 0;

        b.text = document.getElementById('inpText').value;
        render();
    }

    function snapBlock(type) {
        if (selectedIds.length === 0) return;
        selectedIds.forEach(sid => {
            const b = CONFIG.blocks.find(x => x.id === sid);
            if (!b) return;
            if (type === 'left') b.x = pageConfig.marginLeft;
            if (type === 'center') b.x = pageConfig.marginLeft + (pageConfig.width - pageConfig.marginLeft - pageConfig.marginRight) / 2 - b.w / 2;
            if (type === 'right') b.x = pageConfig.width - pageConfig.marginRight - b.w;

            if (type === 'top') b.y = pageConfig.marginTop;
            if (type === 'middle') b.y = pageConfig.marginTop + (pageConfig.height - pageConfig.marginTop - pageConfig.marginBottom) / 2 - b.h / 2;
            if (type === 'bottom') b.y = pageConfig.height - pageConfig.marginBottom - b.h;
        });
        selectBlock(selectedIds[selectedIds.length - 1], true);
    }

    function nudgeBlock(dx, dy) {
        if (selectedIds.length === 0) return;
        selectedIds.forEach(sid => {
            const b = CONFIG.blocks.find(x => x.id === sid);
            if (b) { b.x += dx; b.y += dy; }
        });
        updateBlock(true);
    }

    function toggleStyle(s) {
        if (selectedIds.length === 0) return;
        selectedIds.forEach(sid => {
            const b = CONFIG.blocks.find(x => x.id === sid);
            if (b) b[s] = !b[s];
        });
        selectBlock(selectedIds[selectedIds.length - 1], true);
    }

    function setAlign(a) {
        if (selectedIds.length === 0) return;
        selectedIds.forEach(sid => {
            const b = CONFIG.blocks.find(x => x.id === sid);
            if (b) b.align = a;
        });
        selectBlock(selectedIds[selectedIds.length - 1], true);
    }

    function setValign(v) {
        if (selectedIds.length === 0) return;
        selectedIds.forEach(sid => {
            const b = CONFIG.blocks.find(x => x.id === sid);
            if (b) b.valign = v;
        });
        selectBlock(selectedIds[selectedIds.length - 1], true);
    }

    function addBlock() {
        const id = Date.now();
        CONFIG.blocks.push({ id, x: 100, y: 100, w: 200, h: 50, text: 'Neuer Block', fontSize: 14 });
        selectBlock(id);
    }

    function deleteBlock() {
        if (selectedIds.length === 0) return;
        CONFIG.blocks = CONFIG.blocks.filter(b => !selectedIds.includes(b.id));
        selectedIds = [];
        document.getElementById('selectionPanel').classList.add('pd-hidden');
        document.getElementById('noSelection').classList.remove('pd-hidden');
        render();
    }

    // Keyboard Shortcuts
    window.addEventListener('keydown', (e) => {
        // Ignorieren wenn in einem Input/Textarea getippt wird
        if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;

        if (e.key === 'Delete' || e.key === 'Backspace') {
            deleteBlock();
        }
    });

    function addTag(t) {
        if (selectedIds.length === 0) return;
        const lastId = selectedIds[selectedIds.length - 1];
        const b = CONFIG.blocks.find(x => x.id === lastId);
        if (b) {
            b.text += t;
            document.getElementById('inpText').value = b.text;
            render();
        }
    }

    function filterTags(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.pd-tag').forEach(t => {
            t.style.display = t.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    function setOrientation(o) {
        if (o === 'portrait') { pageConfig.width = 794; pageConfig.height = 1123; }
        else { pageConfig.width = 1123; pageConfig.height = 794; }
        initInputs(); render();
    }

    function updateGlobal() {
        CONFIG.color = document.getElementById('cfgColor').value;
        CONFIG.coverH = parseInt(document.getElementById('inpCoverH').value);
        CONFIG.galleryH = parseInt(document.getElementById('inpGalleryH').value);
    }

    // Resizers
    const mask = document.getElementById('pd-resize-mask');
    const sideL = document.getElementById('pd-sidebar-left');
    const sideR = document.getElementById('pd-sidebar-right');
    let isResL = false, isResR = false;

    document.getElementById('pd-resizer-left').onmousedown = () => { isResL = true; mask.style.display = 'block'; };
    document.getElementById('pd-resizer-right').onmousedown = () => { isResR = true; mask.style.display = 'block'; };

    document.addEventListener('mousemove', (e) => {
        if (isResL) {
            let nw = e.clientX;
            sideL.style.width = nw + 'px';
            localStorage.setItem('pd_side_l', nw);
            autoScaleCanvas();
        }
        if (isResR) {
            let nw = window.innerWidth - e.clientX;
            sideR.style.width = nw + 'px';
            localStorage.setItem('pd_side_r', nw);
            autoScaleCanvas();
            // Iframe benachrichtigen für Scaling
            const ifr = document.getElementById('pd-test-frame');
            if (ifr && ifr.contentWindow) ifr.contentWindow.postMessage({ type: 'resize' }, '*');
        }
    });
    document.addEventListener('mouseup', () => { isResL = isResR = false; mask.style.display = 'none'; });

    // Manager Actions
    function loadTemplate() { window.location.href = 'protokoll_designer.php?id=' + document.getElementById('tplSelect').value; }

    async function setAsDefault() {
        const id = document.getElementById('tplSelect').value;
        const resp = await fetch('../api/manage_protocol_templates.php?action=set_default&id=' + id);
        const res = await resp.json();
        if (res.success) { alert('Als Standard gesetzt!'); location.reload(); }
    }

    function importBaujournal(silent = false) {
        const doImport = () => {
            // Sicherstellen dass CONFIG eine vollständige Designer-Struktur hat
            if (!CONFIG.page) {
                CONFIG.page = { width: pageConfig.width, height: pageConfig.height, marginTop: pageConfig.marginTop, marginBottom: pageConfig.marginBottom, marginLeft: pageConfig.marginLeft, marginRight: pageConfig.marginRight };
            }
            if (!CONFIG.color)    CONFIG.color    = '#1abc9c';
            if (!CONFIG.coverH)   CONFIG.coverH   = 220;
            if (!CONFIG.galleryH) CONFIG.galleryH = 110;
            CONFIG.blocks = [
                // Header & Logo
                { id: 'b_1', x: 53, y: 53, w: 420, h: 70, text: '{HEADER}', fontSize: 11, align: 'left' },
                { id: 'b_2', x: 581, y: 53, w: 160, h: 70, text: '{LOGO}', align: 'right' },
                // Titel & Betreff
                { id: 'b_3', x: 53, y: 140, w: 340, h: 45, text: '{TITEL}', fontSize: 22, bold: true, color: '#1e293b' },
                { id: 'b_4', x: 401, y: 140, w: 340, h: 45, text: '{BETREFF}', fontSize: 16, color: '#64748b' },
                // Stammdaten Zeile 1
                { id: 'b_5', x: 53, y: 198, w: 335, h: 40, text: '{PROJEKT}', fontSize: 12, bold: true, bgColor: '#f8fafc' },
                { id: 'b_6', x: 406, y: 198, w: 335, h: 40, text: '{OBJEKT}', fontSize: 12, bold: true, bgColor: '#f8fafc' },
                // Stammdaten Zeile 2
                { id: 'b_7', x: 53, y: 246, w: 335, h: 40, text: '{WOHNUNG}', fontSize: 11 },
                { id: 'b_8', x: 406, y: 246, w: 335, h: 40, text: '{RAUM}', fontSize: 11 },
                // Stammdaten Zeile 3
                { id: 'b_9',  x: 53,  y: 294, w: 200, h: 40, text: '{DATUM}', fontSize: 11 },
                { id: 'b_10', x: 261, y: 294, w: 200, h: 40, text: '{ORT}',   fontSize: 11 },
                { id: 'b_11', x: 469, y: 294, w: 272, h: 40, text: '{LEITUNG}', fontSize: 11 },
                // Teilnehmer
                { id: 'b_12', x: 53, y: 350, w: 688, h: 110, text: '{TEILNEHMER}', fontSize: 11 },
                // Texte
                { id: 'b_13', x: 53,  y: 475, w: 335, h: 80, text: '{EINLEITUNG}',  fontSize: 11 },
                { id: 'b_14', x: 406, y: 475, w: 335, h: 80, text: '{SCHLUSSWORT}', fontSize: 11 },
                // Inhaltsblöcke
                { id: 'b_15', x: 53, y: 570, w: 688, h: 280, text: '{BLOCKS}', fontSize: 11 },
                // Unterschriften
                { id: 'b_16', x: 53, y: 865, w: 688, h: 100, text: '{UNTERSCHRIFTEN}', fontSize: 11 }
            ];
            // pageConfig auch aktualisieren damit autoScaleCanvas korrekt arbeitet
            pageConfig = CONFIG.page;
            render();
        };
        if (silent) {
            doImport();
        } else if (confirm('Baujournal Premium-Layout laden? Aktuelle Änderungen am Layout gehen verloren.')) {
            doImport();
        }
    }

    async function saveAsNew() {
        const name = prompt('Name für die neue Vorlage:', document.getElementById('tplSelect').options[document.getElementById('tplSelect').selectedIndex].text + ' Kopie');
        if (!name) return;

        CONFIG.page = pageConfig;
        updateGlobal();

        const resp = await fetch('../api/manage_protocol_templates.php?action=save', {
            method: 'POST',
            body: JSON.stringify({ name: name, typ_id: 1, json_data: JSON.stringify(CONFIG) })
        });
        const res = await resp.json();
        if (res.success) { alert('Als neue Vorlage gespeichert!'); window.location.href = 'protokoll_designer.php?id=' + res.id; }
    }

    async function saveConfig() {
        try {
            CONFIG.page = pageConfig;
            updateGlobal();
            const nameEl = document.getElementById('tplName');
            const name = nameEl ? nameEl.value : 'Unbenannt';

            const resp = await fetch('../api/manage_protocol_templates.php?action=update_template', {
                method: 'POST',
                body: JSON.stringify({ id: <?= $id ?>, name: name, json_data: JSON.stringify(CONFIG) })
            });
            const res = await resp.json();
            if (res.success) alert('Layout erfolgreich gespeichert!'); else alert('Fehler: ' + res.error);
        } catch (e) {
            alert('Speichern fehlgeschlagen.');
        }
    }

    function toggleRightSidebar() {
        const side = document.getElementById('pd-sidebar-right');
        const res = document.getElementById('pd-resizer-right');
        if (side.style.display === 'none') {
            side.style.display = 'flex';
            if (res) res.style.display = 'block';
        } else {
            side.style.display = 'none';
            if (res) res.style.display = 'none';
        }
    }

    function showToast(msg) {
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#1abc9c;color:#fff;padding:12px 24px;border-radius:8px;font-size:13px;font-weight:700;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.2);';
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 3500);
    }

    function testPdf() {
        const data = JSON.stringify({ layout: CONFIG, test_mode: true });
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'pendenzen_list_pdf.php?preview=1';
        form.target = '_blank';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'protocol_data';
        input.value = data;

        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>