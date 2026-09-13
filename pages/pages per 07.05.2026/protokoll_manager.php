<?php
// pages/protokoll_manager.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// Initialisierung der Daten
$projekte = $mysqli->query("SELECT * FROM projekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$objekte = $mysqli->query("SELECT * FROM objekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$wohnungen = $mysqli->query("SELECT * FROM wohnungen ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$raeume = $mysqli->query("SELECT * FROM raeume ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Aktueller Benutzer mit Firmen-Join für das Branding
$user_id = (int) $_SESSION['user_id'];
$currentUser_sql = "SELECT b.*, f.name as f_name, f.adresse as f_adresse, f.ort as f_ort, f.telefon as f_tel, f.email as f_email, f.website as f_web, f.logo as f_logo
                    FROM benutzer b
                    LEFT JOIN firmen f ON b.firma_id = f.id
                    WHERE b.id = $user_id";
$currentUser = $mysqli->query($currentUser_sql)->fetch_assoc();

$brand = [
    'name' => $currentUser['f_name'] ?? $currentUser['firma_name'] ?? $currentUser['firma'] ?? 'Pendenz.com',
    'adresse' => $currentUser['f_adresse'] ?? $currentUser['firma_adresse'] ?? $currentUser['adresse'] ?? '',
    'ort' => $currentUser['f_ort'] ?? $currentUser['firma_ort'] ?? $currentUser['ort'] ?? '',
    'tel' => $currentUser['f_tel'] ?? $currentUser['firma_telefon'] ?? $currentUser['telefonnummer'] ?? $currentUser['telefon'] ?? '',
    'email' => $currentUser['f_email'] ?? $currentUser['firma_email'] ?? $currentUser['email'] ?? '',
    'web' => $currentUser['f_web'] ?? $currentUser['firma_website'] ?? $currentUser['website'] ?? '',
    'logo' => $currentUser['f_logo'] ?? $currentUser['firmenlogo'] ?? $currentUser['logo'] ?? ''
];
$brandLogo = !empty($brand['logo']) ? '../' . ltrim($brand['logo'], '/') : null;

// Benutzer laden
$user_sql = "SELECT b.*, f.name as join_firma 
             FROM benutzer b
             LEFT JOIN firma_user fu ON b.id = fu.user_id AND fu.is_primary = 1
             LEFT JOIN firmen f ON fu.firma_id = f.id
             ORDER BY b.name";
$benutzer_raw = $mysqli->query($user_sql)->fetch_all(MYSQLI_ASSOC);

$benutzer = [];
foreach ($benutzer_raw as $u) {
    $u['firma'] = $u['join_firma'] ?? $u['firma_name'] ?? $u['firma'] ?? '-';
    $u['telefon'] = $u['telefonnummer'] ?? $u['telefon'] ?? '';
    $u['mobile'] = $u['mobile'] ?? $u['mobil'] ?? '';
    $benutzer[] = $u;
}

// Migration Check
$mysqli->query("CREATE TABLE IF NOT EXISTS protokoll_typen (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, icon VARCHAR(50) DEFAULT '📝')");
$mysqli->query("CREATE TABLE IF NOT EXISTS protokoll_vorlagen (id INT AUTO_INCREMENT PRIMARY KEY, typ_id INT NOT NULL, name VARCHAR(255) NOT NULL, json_data TEXT)");

$resTypen = $mysqli->query("SELECT * FROM protokoll_typen ORDER BY name");
if ($resTypen->num_rows == 0) {
    $mysqli->query("INSERT INTO protokoll_typen (name, icon) VALUES ('Baustellenprotokoll', '🏗️'), ('Abnahmeprotokoll', '🔑'), ('Sitzungsprotokoll', '👥')");
    $resTypen = $mysqli->query("SELECT * FROM protokoll_typen ORDER BY name");
}
$typen = $resTypen->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../includes/header.php';
?>
<!-- Signature Pad Library -->
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>

<style>
    :root {
        --sidebar-bg: #0f172a;
        --accent: #1abc9c;
        --accent-hover: #16a085;
        --bg-main: #f8fafc;
        --text-main: #1e293b;
        --text-muted: #64748b;
    }

    body { font-family: 'Inter', sans-serif; background: var(--bg-main); color: var(--text-main); }
    .manager-container { display: grid; grid-template-columns: 350px 1fr; height: calc(100vh - 60px); background: var(--bg-main); }
    .sidebar { background: var(--sidebar-bg); color: #fff; padding: 0; overflow-y: auto; border-right: 1px solid #1e293b; display: flex; flex-direction: column; }
    .sidebar-header { padding: 25px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); }
    .sidebar-header h2 { font-size: 14px; margin: 0; color: var(--accent); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 800; }
    .sidebar-content { padding: 15px; flex: 1; }
    .proto-type { margin-bottom: 15px; }
    .proto-type-header { display: flex; align-items: center; gap: 12px; font-weight: 600; padding: 12px 15px; background: rgba(255, 255, 255, 0.03); border-radius: 8px; cursor: pointer; transition: all 0.2s ease; border: 1px solid rgba(255, 255, 255, 0.05); }
    .proto-type-header:hover { background: rgba(255, 255, 255, 0.08); transform: translateX(5px); }
    .template-list { margin-top: 8px; padding-left: 10px; border-left: 2px solid rgba(255, 255, 255, 0.05); margin-left: 20px; }
    .template-item { padding: 10px 15px; font-size: 13px; color: #94a3b8; cursor: pointer; border-radius: 6px; transition: all 0.2s; display: flex; flex-direction: column; gap: 5px; margin-bottom: 5px; }
    .template-item:hover { background: rgba(255, 255, 255, 0.05); color: #fff; }
    .template-item.active { background: rgba(26, 188, 156, 0.1); color: #fff; box-shadow: inset 3px 0 0 var(--accent); }
    .form-list { margin-top: 8px; margin-left: 10px; border-left: 1px dashed rgba(255, 255, 255, 0.1); padding-left: 15px; }
    .form-item { padding: 8px 12px; font-size: 12px; color: #64748b; cursor: pointer; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s; margin-bottom: 3px; }
    .form-item:hover { background: rgba(255, 255, 255, 0.05); color: #fff; }
    .form-item.active { background: rgba(99, 102, 241, 0.15); color: #818cf8; font-weight: 600; }
    .main-content { padding: 40px; overflow-y: auto; background: #f1f5f9; }
    .editor-wrapper { max-width: 1000px; margin: 0 auto; position: relative; }
    .mode-indicator { position: absolute; top: -15px; right: 0; padding: 6px 15px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); z-index: 10; }
    .mode-template { background: var(--accent); color: #fff; }
    .mode-form { background: #6366f1; color: #fff; }
    .mode-new { background: #94a3b8; color: #fff; }
    .form-card { background: #fff; border-radius: 16px; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08); padding: 50px; border: 1px solid #e2e8f0; }
    .form-section { margin-bottom: 40px; }
    .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; }
    .section-title { font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 10px; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 20px; }
    .field-group { display: flex; flex-direction: column; gap: 8px; }
    .field-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .field-group input, .field-group select, .field-group textarea { border: 2px solid #f1f5f9; padding: 12px 15px; border-radius: 10px; font-size: 14px; transition: all 0.2s; background: #f8fafc; font-weight: 500; }
    .field-group input:focus, .field-group select:focus, .field-group textarea:focus { border-color: var(--accent); outline: none; background: #fff; box-shadow: 0 0 0 4px rgba(26, 188, 156, 0.1); }
    .btn-action { padding: 12px 24px; border-radius: 10px; font-weight: 700; cursor: pointer; border: none; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 10px; font-size: 13px; }
    .btn-action:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1); }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-secondary { background: #6366f1; color: #fff; }
    .btn-ghost { background: #f1f5f9; color: #475569; }
    .custom-section-card { background: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; position: relative; }
    .remove-section { position: absolute; top: 10px; right: 10px; color: #ef4444; cursor: pointer; opacity: 0.3; }
    .remove-section:hover { opacity: 1; }
    .switch-small { position: relative; display: inline-block; width: 34px; height: 18px; }
    .switch-small input { opacity: 0; width: 0; height: 0; }
    .slider-small { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 18px; }
    .slider-small:before { position: absolute; content: ""; height: 14px; width: 14px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked+.slider-small { background-color: var(--accent); }
    input:checked+.slider-small:before { transform: translateX(16px); }
    .participant-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
    .participant-table th { text-align: left; background: #f8fafc; padding: 12px; color: #64748b; font-size: 10px; text-transform: uppercase; }
    .participant-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; }

    /* Designer Styles */
    .designer-block { position: absolute; border: 1px dashed #cbd5e1; cursor: move; overflow: hidden; background: rgba(255,255,255,0.7); }
    .designer-block.selected { border: 2px solid var(--accent); background: #fff; z-index: 100; }
    .tag-pill { font-size: 10px; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; cursor: pointer; color: #475569; border: 1px solid #e2e8f0; }
</style>

<div class="manager-container">
    <div class="sidebar">
        <div class="sidebar-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h2>Protokoll-Manager</h2>
            <button onclick="location.href='protokoll_vorlagen.php'" class="btn-action btn-ghost" style="padding:5px 10px; font-size:10px; border:1px solid rgba(255,255,255,0.2); color:#fff; background:rgba(255,255,255,0.05);">✏️ Verwalten</button>
        </div>
        <div class="sidebar-content">
            <?php foreach ($typen as $typ):
                $icon = '📄';
                if (strpos($typ['name'], 'Abnahme') !== false) $icon = '🔑';
                if (strpos($typ['name'], 'Baustelle') !== false) $icon = '🏗️';
                if (strpos($typ['name'], 'Sitzung') !== false) $icon = '👥';
                if (strpos($typ['name'], 'Pendenzen') !== false) $icon = '📋';
                ?>
                <div class="proto-type" data-id="<?= $typ['id'] ?>">
                    <div class="proto-type-header" onclick="toggleType(<?= $typ['id'] ?>)">
                        <span><?= $icon ?></span>
                        <span style="flex:1; font-weight:700;"><?= h($typ['name']) ?></span>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button onclick="event.stopPropagation(); createQuickInstance(<?= $typ['id'] ?>)" style="background:var(--accent); border:none; border-radius:4px; color:#fff; font-size:10px; padding:4px 8px; cursor:pointer;">+ NEU</button>
                            <span style="font-size:10px; opacity:0.5;">▼</span>
                        </div>
                    </div>
                    <div class="template-list" id="tpl_list_<?= $typ['id'] ?>" style="display:none;"></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="main-content">
        <div class="editor-wrapper">
            <div id="modeIndicator" class="mode-indicator mode-new">STARTSEITE</div>

            <div class="form-card" id="mainForm">
                <div id="welcome_dashboard" style="display: block; text-align: center; padding: 40px 20px;">
                    <h2 style="color:var(--accent); margin-bottom:10px;">Willkommen im Protokoll-Manager</h2>
                    <p style="opacity:0.7; margin-bottom:30px;">Wählen Sie links eine Vorlage oder laden Sie unsere Profi-Standards.</p>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; max-width:800px; margin: 0 auto;">
                        <div class="dashboard-card" onclick="document.querySelector('.proto-type-header')?.click()" style="background:#fff; padding:20px; border-radius:12px; cursor:pointer; border:1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                            <span style="font-size:30px; display:block; margin-bottom:10px;">📋</span>
                            <h3 style="font-size:16px; margin-bottom:5px;">Neues Protokoll</h3>
                            <p style="font-size:12px; opacity:0.6;">Starten Sie mit einem fertigen Dokument.</p>
                        </div>
                        <div class="dashboard-card" onclick="seedProfessionalTemplates()" style="background:rgba(26, 188, 156, 0.1); padding:20px; border-radius:12px; cursor:pointer; border:1px solid var(--accent); box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);">
                            <span style="font-size:30px; display:block; margin-bottom:10px;">✨</span>
                            <h3 style="font-size:16px; margin-bottom:5px; color:var(--accent);">Profi-Vorlagen</h3>
                            <p style="font-size:12px; opacity:0.6; color:var(--accent);">Laden Sie Standard-Vorlagen.</p>
                        </div>
                    </div>
                </div>

                <div id="editor_content" style="display:none;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:30px; gap:30px;">
                        <div style="flex:1; display:flex; flex-direction:column; gap:10px;">
                            <div style="display:flex; align-items:center; gap:20px;">
                                <select id="protocol_context_select" onchange="switchContext()" style="border:2px solid #e2e8f0; border-radius:30px; padding:8px 20px; font-weight:700; color:#0f172a; outline:none; min-width:250px;">
                                    <optgroup label="Aktuell" id="optgroup_active_item"></optgroup>
                                </select>
                                <input type="text" id="formTitleInput" value="Neues Protokoll" style="font-size:28px; font-weight:900; color:#0f172a; border:none; background:none; flex:1;">
                            </div>
                        </div>
                        <div style="display:flex; gap:12px;">
                            <button class="btn-action btn-secondary" onclick="saveActive()">💾 Speichern</button>
                            <button class="btn-action btn-primary" onclick="generateProtocol()">📄 PDF</button>
                        </div>
                    </div>

                    <!-- BASIS DATEN -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">📄 PROTOKOLL-VORBEREITUNG & OBJEKTDATEN</div>
                        </div>
                        <div style="background:#fff; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px;">
                            <div class="field-row">
                                <div class="field-group"><label>PROJEKT-TITEL (PDF)</label><input type="text" id="pdf_header_title" value="Protokoll"></div>
                                <div class="field-group"><label>BETREFF</label><input type="text" id="pdf_subject"></div>
                            </div>
                            <div class="field-row">
                                <div class="field-group"><label>🏗️ PROJEKT</label>
                                    <select id="sel_projekt" onchange="filterContextDropdowns('projekt')">
                                        <option value="">-- wählen --</option>
                                        <?php foreach($projekte as $p) echo "<option value='{$p['id']}'>".h($p['name'])."</option>"; ?>
                                    </select>
                                </div>
                                <div class="field-group"><label>🏢 OBJEKT</label>
                                    <select id="sel_objekt" onchange="filterContextDropdowns('objekt')">
                                        <option value="">-- wählen --</option>
                                        <?php foreach($objekte as $o) echo "<option value='{$o['id']}' data-projekt-id='{$o['projekt_id']}'>".h($o['name'])."</option>"; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="field-row" id="row_project_context" style="background:rgba(26,188,156,0.03); padding:20px; border-radius:12px;">
                                <div class="field-group"><label>🏠 WOHNUNG</label>
                                    <select id="sel_wohnung" onchange="filterContextDropdowns('wohnung')">
                                        <option value="">-- wählen --</option>
                                        <?php foreach($wohnungen as $w) echo "<option value='{$w['id']}' data-objekt-id='{$w['objekt_id']}'>".h($w['name'])."</option>"; ?>
                                    </select>
                                </div>
                                <div class="field-group"><label>🚪 RAUM</label>
                                    <select id="sel_raum">
                                        <option value="">-- wählen --</option>
                                        <?php foreach($raeume as $r) echo "<option value='{$r['id']}' data-wohnung-id='{$r['wohnung_id']}'>".h($r['name'])."</option>"; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="field-row" style="grid-template-columns: 1fr 1fr 1fr;">
                                <div class="field-group"><label>📅 DATUM</label><input type="text" id="p_datetime" value="<?=date('d.m.Y, H:i')?> Uhr"></div>
                                <div class="field-group"><label>📍 ORT</label><input type="text" id="p_location" value="Baubüro"></div>
                                <div class="field-group"><label>👤 LEITUNG</label>
                                    <select id="p_leader">
                                        <?php foreach($benutzer as $u) echo "<option value='".h($u['name'])."' ".($_SESSION['user_id']==$u['id']?'selected':'').">".h($u['name'])."</option>"; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ERWEITERTE DETAILS -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">🏗️ ERWEITERTE WERKDETAILS</div>
                        </div>
                        <div style="background:#f8fafc; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px;">
                            <div class="field-row" style="grid-template-columns: 1fr 1fr 1fr;">
                                <div class="field-group"><label>Objekt-Nr.</label><input type="text" id="p_obj_nr"></div>
                                <div class="field-group"><label>Werkvertrag vom</label><input type="text" id="p_wv_date"></div>
                                <div class="field-group"><label>WV-Nr.</label><input type="text" id="p_wv_nr"></div>
                            </div>
                            <div class="field-row" style="grid-template-columns: 1fr 1fr 1fr;">
                                <div class="field-group"><label>Betr. BKP</label><input type="text" id="p_bkp"></div>
                                <div class="field-group"><label>Grundstück</label><input type="text" id="p_land"></div>
                                <div class="field-group"><label>Behebung bis</label><input type="text" id="p_fix_date" style="color:#ef4444; font-weight:800;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- TEXTE -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">📝 TEXTE & EINLEITUNG</div>
                        </div>
                        <div style="background:#fff; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px;">
                            <div class="field-group" style="margin-bottom:15px;"><label>EINLEITUNG</label><textarea id="p_intro" rows="3"></textarea></div>
                            <div class="field-group"><label>SCHLUSSWORT</label><textarea id="p_outro" rows="2"></textarea></div>
                        </div>
                    </div>

                    <!-- PARTICIPANTS -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">👥 TEILNEHMER</div>
                        </div>
                        <div style="background:#fff; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px;">
                            <div style="display:flex; gap:15px; margin-bottom:20px;">
                                <select id="userPicker" style="flex:1; border:2px solid #f1f5f9; border-radius:10px; padding:12px;">
                                    <option value="">-- Teilnehmer auswählen --</option>
                                    <?php foreach ($benutzer as $u): ?>
                                        <option value="<?=$u['id']?>" data-name="<?=h($u['name'])?>" data-company="<?=h($u['firma'])?>" data-email="<?=h($u['email'])?>" data-pos="<?=h($u['position']?:'-')?>"><?=h($u['name'])?> (<?=h($u['firma'])?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn-action btn-primary" onclick="addParticipant()">Hinzufügen</button>
                            </div>
                            <table class="participant-table" id="participantTable"><thead><tr><th>Entsch.</th><th>Name</th><th>Position</th><th>Firma</th><th></th></tr></thead><tbody></tbody></table>
                        </div>
                    </div>

                    <!-- CUSTOM BLOCKS -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">🔧 INHALTSBLÖCKE</div>
                            <button onclick="addCustomSection()" class="btn-action btn-ghost" style="padding:5px 12px; font-size:11px; color:#fff; border-color:rgba(255,255,255,0.3);">+ Block</button>
                        </div>
                        <div style="background:#fff; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px;" id="customSectionsContainer"></div>
                    </div>

                    <!-- SIA REGELN -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">⚖️ SIA REGELUNGEN (ABNAHME)</div>
                        </div>
                        <div style="background:#fff; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px; display:flex; flex-direction:column; gap:10px;">
                            <label style="display:flex; gap:10px; cursor:pointer;"><input type="checkbox" id="p_art159"> Art. 159 (Abnahme nach Vollendung)</label>
                            <label style="display:flex; gap:10px; cursor:pointer;"><input type="checkbox" id="p_art160"> Art. 160 (Erstellung Protokoll)</label>
                            <label style="display:flex; gap:10px; cursor:pointer;"><input type="checkbox" id="p_art161"> Art. 161 (Werk gilt als abgenommen)</label>
                        </div>
                    </div>

                    <!-- UNTERSCHRIFTEN -->
                    <div class="form-section">
                        <div class="section-header" style="background:#0f172a; padding:12px 25px; border-radius:12px 12px 0 0; margin-bottom:0;">
                            <div class="section-title" style="color:#fff;">✍️ UNTERSCHRIFTEN</div>
                        </div>
                        <div style="background:#fff; border:2px solid #0f172a; border-top:none; border-radius:0 0 16px 16px; padding:30px; display:grid; grid-template-columns:1fr 1fr; gap:30px;">
                            <div><label>Besteller</label><canvas id="sigBesteller" width="400" height="150" style="border:1px solid #ddd; width:100%;"></canvas><button onclick="signaturePadBesteller.clear()" class="btn-ghost" style="font-size:10px;">Leeren</button></div>
                            <div><label>Unternehmer</label><canvas id="sigUnternehmer" width="400" height="150" style="border:1px solid #ddd; width:100%;"></canvas><button onclick="signaturePadUnternehmer.clear()" class="btn-ghost" style="font-size:10px;">Leeren</button></div>
                        </div>
                    </div>

                    <div style="margin-top:30px; display:flex; gap:20px;">
                        <button class="btn-action btn-secondary" onclick="saveForm()" style="flex:1;">Protokoll speichern</button>
                        <button class="btn-action btn-primary" onclick="generateProtocol()" style="flex:1;">PDF erstellen</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DESIGNER OVERLAY -->
<div id="designer_overlay" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:#f1f5f9; z-index:9999; flex-direction:column;">
    <div style="height:60px; background:#0f172a; display:flex; align-items:center; justify-content:space-between; padding:0 30px; color:#fff;">
        <span style="font-weight:800;">🎨 LAYOUT DESIGNER</span>
        <button onclick="closeDesigner()" style="background:#ef4444; color:#fff; border:none; padding:8px 20px; border-radius:6px; font-weight:800; cursor:pointer;">Speichern & Schließen</button>
    </div>
    <div style="display: grid; grid-template-columns: 350px 1fr; flex:1; overflow:hidden;">
        <div style="background:#fff; border-right:1px solid #e2e8f0; padding:25px; overflow-y:auto;">
            <div id="inspector_block">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:15px;">
                    <div><label style="font-size:10px;">X</label><input type="number" id="inp_x"></div>
                    <div><label style="font-size:10px;">Y</label><input type="number" id="inp_y"></div>
                    <div><label style="font-size:10px;">W</label><input type="number" id="inp_w"></div>
                    <div><label style="font-size:10px;">H</label><input type="number" id="inp_h"></div>
                    <div style="grid-column: span 2;"><label style="font-size:10px;">Grösse</label><input type="number" id="inp_fs"></div>
                </div>
                <textarea id="inp_text" style="width:100%; height:80px; margin-bottom:15px;"></textarea>
                <button onclick="deleteSelectedBlock()" style="width:100%; padding:10px; background:#fee2e2; color:#ef4444; border:none; border-radius:8px;">Block löschen</button>
            </div>
            <button onclick="addDesignerBlock()" style="width:100%; padding:12px; background:var(--accent); color:#fff; border:none; border-radius:8px; margin-top:15px;">+ Block</button>
            <div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;">
                <label style="font-size:10px; font-weight:800;">TAGS:</label>
                <div style="display:flex; flex-wrap:wrap; gap:5px; margin-top:5px;">
                    <span class="tag-pill" onclick="insertTag('{TITEL}')">{TITEL}</span><span class="tag-pill" onclick="insertTag('{PROJEKT}')">{PROJEKT}</span><span class="tag-pill" onclick="insertTag('{LOGO}')">{LOGO}</span><span class="tag-pill" onclick="insertTag('{HEADER}')">{HEADER}</span>
                </div>
            </div>
        </div>
        <div style="background:#cbd5e1; padding:50px; overflow:auto; display:flex; justify-content:center;">
            <div id="designer_canvas" style="width:595px; height:842px; background:#fff; position:relative; box-shadow:0 10px 30px rgba(0,0,0,0.2);"></div>
        </div>
    </div>
</div>

<form id="pdfExportForm" method="POST" action="pendenzen_list_pdf.php" target="_blank" style="display:none;">
    <input type="hidden" name="protocol_data" id="protocol_data_input">
</form>

<script>
    let currentTemplateId = null;
    let currentFormId = null;
    let participants = [];
    let customSections = [];
    let designerBlocks = [];
    let selectedBlockId = null;
    let signaturePadBesteller, signaturePadUnternehmer;

    function initSignatures() {
        const cB = document.getElementById('sigBesteller');
        const cU = document.getElementById('sigUnternehmer');
        if (cB) signaturePadBesteller = new SignaturePad(cB);
        if (cU) signaturePadUnternehmer = new SignaturePad(cU);
    }

    function toggleType(id) {
        const list = document.getElementById('tpl_list_' + id);
        list.style.display = (list.style.display === 'block' ? 'none' : 'block');
    }

    async function loadTemplates() {
        const resp = await fetch('../api/manage_protocol_templates.php?action=list');
        const res = await resp.json();
        if (res.success) {
            document.querySelectorAll('.template-list').forEach(l => l.innerHTML = '');
            const sel = document.getElementById('protocol_context_select');
            const groups = {};
            res.templates.forEach(t => {
                if (!groups[t.typ_id]) groups[t.typ_id] = { name: t.typ_name, icon: t.typ_icon, templates: [] };
                groups[t.typ_id].templates.push(t);
            });
            sel.querySelectorAll('.dynamic-type-group').forEach(g => g.remove());
            for (const typId in groups) {
                const g = groups[typId];
                const optGroup = document.createElement('optgroup');
                optGroup.className = 'dynamic-type-group';
                optGroup.label = (g.icon || '📝') + ' ' + g.name;
                g.templates.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value = 'create_form_' + t.id;
                    opt.textContent = '    📐 ' + t.name;
                    optGroup.appendChild(opt);
                    const container = document.getElementById('tpl_list_' + t.typ_id);
                    if (container) {
                        const div = document.createElement('div');
                        div.className = 'template-item' + (currentTemplateId == t.id ? ' active' : '');
                        
                        let formsHtml = '';
                        res.forms.filter(f => f.vorlage_id == t.id).forEach(f => {
                            const dateStr = new Date(f.created_at).toLocaleDateString('de-CH');
                            formsHtml += `
                                <div class="form-item ${currentFormId == f.id ? 'active' : ''}" 
                                     onclick="event.stopPropagation(); loadFormInstance(${f.id})"
                                     style="display:flex; justify-content:space-between; align-items:center;">
                                    <span>📐 ${f.name}</span>
                                    <span style="font-size:9px; opacity:0.5;">${dateStr}</span>
                                </div>`;
                        });

                        div.innerHTML = `
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span style="flex:1; font-weight:700; cursor:pointer;" onclick="loadTemplate(${t.id})">${t.name}</span>
                                <span style="cursor:pointer; font-size:11px; opacity:0.6;" onclick="event.stopPropagation(); location.href='protokoll_designer.php?id=${t.id}'">✏️</span>
                            </div>
                            <div class="form-list" style="margin-top:5px; border-left:1px dashed #475569; padding-left:10px;">${formsHtml}</div>
                        `;
                        container.appendChild(div);
                    }
                });
                sel.appendChild(optGroup);
            }
        }
    }

    async function loadTemplate(id) {
        const resp = await fetch('../api/manage_protocol_templates.php?action=load&id=' + id);
        const res = await resp.json();
        if (res.success) {
            currentTemplateId = id; currentFormId = null;
            const data = JSON.parse(res.template.json_data);
            applyData(data);
            designerBlocks = data.layout?.blocks || [];
            document.getElementById('formTitleInput').value = res.template.name;
            updateModeIndicator();
        }
    }

    async function loadFormInstance(id) {
        const resp = await fetch('../api/manage_protocol_templates.php?action=load_form&id=' + id);
        const res = await resp.json();
        if (res.success) {
            currentFormId = id; currentTemplateId = res.form.vorlage_id;
            const data = JSON.parse(res.form.json_data);
            applyData(data);
            designerBlocks = data.layout?.blocks || [];
            document.getElementById('formTitleInput').value = res.form.name;
            updateModeIndicator();
        }
    }

    function applyData(d) {
        document.getElementById('pdf_header_title').value = d.title || '';
        document.getElementById('pdf_subject').value = d.subject || '';
        document.getElementById('sel_projekt').value = d.project_id || '';
        document.getElementById('sel_objekt').value = d.object_id || '';
        document.getElementById('sel_wohnung').value = d.apartment_id || '';
        document.getElementById('sel_raum').value = d.room_id || '';
        document.getElementById('p_intro').value = d.intro || '';
        document.getElementById('p_outro').value = d.outro || '';
        document.getElementById('p_obj_nr').value = d.obj_nr || '';
        document.getElementById('p_wv_date').value = d.wv_date || '';
        document.getElementById('p_wv_nr').value = d.wv_nr || '';
        document.getElementById('p_bkp').value = d.bkp || '';
        document.getElementById('p_land').value = d.land || '';
        document.getElementById('p_fix_date').value = d.fix_date || '';
        participants = d.participants || [];
        customSections = d.customSections || [];
        renderParticipants(); renderCustomSections();
    }

    function collectData() {
        return {
            title: document.getElementById('pdf_header_title').value,
            subject: document.getElementById('pdf_subject').value,
            project_id: document.getElementById('sel_projekt').value,
            object_id: document.getElementById('sel_objekt').value,
            apartment_id: document.getElementById('sel_wohnung').value,
            room_id: document.getElementById('sel_raum').value,
            intro: document.getElementById('p_intro').value,
            outro: document.getElementById('p_outro').value,
            obj_nr: document.getElementById('p_obj_nr').value,
            wv_date: document.getElementById('p_wv_date').value,
            wv_nr: document.getElementById('p_wv_nr').value,
            bkp: document.getElementById('p_bkp').value,
            land: document.getElementById('p_land').value,
            fix_date: document.getElementById('p_fix_date').value,
            participants, customSections,
            layout: { blocks: designerBlocks }
        };
    }

    async function saveForm() {
        if (!currentTemplateId) return alert("Vorlage wählen!");
        const data = collectData();
        await fetch('../api/manage_protocol_templates.php?action=save_form', {
            method: 'POST',
            body: JSON.stringify({ id: currentFormId, vorlage_id: currentTemplateId, name: document.getElementById('formTitleInput').value, json_data: JSON.stringify(data) })
        }).then(r => r.json()).then(res => { if(res.success) { currentFormId = res.id; alert("Gespeichert!"); loadTemplates(); }});
    }

    async function saveActive() {
        saveForm();
    }



    function updateModeIndicator() {
        document.getElementById('welcome_dashboard').style.display = 'none';
        document.getElementById('editor_content').style.display = 'block';
        const ind = document.getElementById('modeIndicator');
        if (currentFormId) { ind.textContent = 'DOKUMENT'; ind.className = 'mode-indicator mode-form'; }
        else { ind.textContent = 'MUSTER'; ind.className = 'mode-indicator mode-template'; }
    }

    function generateProtocol() {
        const data = collectData();
        document.getElementById('protocol_data_input').value = JSON.stringify(data);
        document.getElementById('pdfExportForm').submit();
    }

    function seedProfessionalTemplates() { if(confirm('Profi-Vorlagen laden?')) window.location.href='run_seed.php'; }

    function toggleType(id) {
        const list = document.getElementById('tpl_list_' + id);
        if (list) list.style.display = (list.style.display === 'block' ? 'none' : 'block');
    }

    function createQuickInstance(typId) {
        // Find first template of this type
        fetch('../api/manage_protocol_templates.php?action=list')
            .then(r => r.json())
            .then(res => {
                const tpl = res.templates.find(t => t.typ_id == typId);
                if (tpl) loadTemplate(tpl.id);
                else alert("Keine Vorlage für diesen Typ gefunden.");
            });
    }

    // Participants & Sections
    function addParticipant() {
        const p = document.getElementById('userPicker'); const opt = p.options[p.selectedIndex]; if (!opt.value) return;
        participants.push({ id: opt.value, name: opt.dataset.name, company: opt.dataset.company, email: opt.dataset.email, position: opt.dataset.pos, status: 'ja' });
        renderParticipants();
    }
    function renderParticipants() {
        const b = document.querySelector('#participantTable tbody'); b.innerHTML = '';
        participants.forEach((p, i) => { const tr = document.createElement('tr'); tr.innerHTML = `<td><input type="checkbox" ${p.status=='nein'?'checked':''} onchange="participants[${i}].status=this.checked?'nein':'ja'"></td><td>${p.name}</td><td>${p.position}</td><td>${p.company}</td><td><button onclick="participants.splice(${i},1);renderParticipants()">✕</button></td>`; b.appendChild(tr); });
    }
    function addCustomSection() { customSections.push({ id: Date.now(), title: '', content: '' }); renderCustomSections(); }
    function renderCustomSections() {
        const c = document.getElementById('customSectionsContainer'); c.innerHTML = '';
        customSections.forEach(s => { const d = document.createElement('div'); d.className = 'custom-section-card'; d.innerHTML = `<input type="text" value="${s.title}" onchange="s.title=this.value" placeholder="Titel"><textarea onchange="s.content=this.value">${s.content}</textarea>`; c.appendChild(d); });
    }

    function switchContext() {
        const val = document.getElementById('protocol_context_select').value;
        if (val.startsWith('create_form_')) loadTemplate(val.replace('create_form_', ''));
    }

    function filterContextDropdowns(level) { /* logic */ }

    initSignatures();
    loadTemplates();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>