<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Daten sammeln via $mysqli
$projekte = $mysqli->query("SELECT id, name FROM projekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$objekte = $mysqli->query("SELECT id, projekt_id, name FROM objekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$wohnungen = $mysqli->query("SELECT id, objekt_id, name FROM wohnungen ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$raeume = $mysqli->query("SELECT id, wohnung_id, name FROM raeume ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$bkp_codes = $mysqli->query("SELECT code, bezeichnung FROM bkp_codes ORDER BY code")->fetch_all(MYSQLI_ASSOC);
$mieter = $mysqli->query("SELECT id, name FROM mieter ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$benutzer = $mysqli->query("SELECT id, name, firma_name FROM benutzer ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <title>Live Test-Umgebung</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        :root {
            --bg: #94a3b8;
            --header-bg: #0f172a;
            --accent: #1abc9c;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 40px 0;
            /* Horizontaler Puffer wird via JS berechnet */
            font-size: 13px;
            overflow-x: hidden;
            width: 100%;
        }

        .pd-hidden {
            display: none !important;
        }

        .smart-flow-container {
            display: block;
        }

        .section-header {
            background: #0f172a;
            color: #fff;
            padding: 9px 14px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-radius: 6px 6px 0 0;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-section {
            margin-bottom: 18px;
        }

        #company-header {
            border-bottom: 3px solid #1abc9c;
            margin-bottom: 22px;
            padding-bottom: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pd-a4-preview {
            background: #fff;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            min-height: 1123px;
            padding: 50px;
            width: 794px;
            /* Fixe A4 Breite */
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
            transform-origin: top left;
            /* Wichtig für manuelle Zentrierung */
            transition: transform 0.2s ease-out;
        }

        .inline-input-wrapper {
            display: inline-block;
            vertical-align: middle;
            min-width: 100px;
            margin: 2px 0;
        }

        .inline-input-wrapper input,
        .inline-input-wrapper select,
        .inline-input-wrapper textarea {
            border: 1px solid transparent !important;
            background: transparent !important;
            padding: 2px 5px !important;
            border-radius: 4px !important;
            transition: all 0.2s;
            cursor: pointer;
            color: inherit;
            font-family: inherit;
        }

        .inline-input-wrapper:hover input,
        .inline-input-wrapper:hover select,
        .inline-input-wrapper:hover textarea {
            background: rgba(26, 188, 156, 0.05) !important;
            border-color: rgba(26, 188, 156, 0.2) !important;
        }

        .inline-input-wrapper input:focus,
        .inline-input-wrapper select:focus,
        .inline-input-wrapper textarea:focus {
            border-color: var(--accent) !important;
            background: #fff !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26, 188, 156, 0.1);
            cursor: text;
        }

        .pd-margin-box {
            position: absolute;
            border: 1px dashed rgba(26, 188, 156, 0.3);
            pointer-events: none;
            box-sizing: border-box;
            background: rgba(26, 188, 156, 0.01);
            z-index: 5;
        }

        .field label {
            display: block;
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .participants-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 11px;
        }

        .preview-block {
            border: 1px dashed rgba(148, 163, 184, 0.3);
            /* Dezente Umrandung */
            transition: border-color 0.2s;
            overflow: visible;
        }

        .inline-input-wrapper {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .inline-input-wrapper.field {
            padding: 0 !important;
            margin: 0 !important;
        }

        .inline-input-wrapper input,
        .inline-input-wrapper select,
        .inline-input-wrapper textarea {
            width: 100% !important;
            height: 100% !important;
            margin: 0 !important;
            border: none !important;
            background: transparent !important;
            padding: 5px 10px !important;
            box-sizing: border-box !important;
            font-family: inherit;
            font-size: inherit;
            font-weight: inherit;
            color: inherit;
            text-align: inherit;
        }

        .inline-input-wrapper.complex-field {
            height: auto !important;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        }

        .preview-block:hover {
            border-color: var(--accent);
        }
    </style>
</head>

<body>

    <div class="pd-a4-preview">
        <div id="company-header">
            <div>
                <div id="brand-name" style="font-weight:800; font-size:15px; color:#1e293b;">---</div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Protokoll / Bericht</div>
            </div>
            <div id="brand-logo" style="max-width:100px; max-height:55px;"></div>
        </div>
        <div id="smart-flow" class="smart-flow-container"></div>
    </div>

    <div id="all-fields-pool" class="pd-hidden">
        <!-- Spezial-Tags (Visual Previews) -->
        <div class="field" data-tag="LOGO">
            <div
                style="width: 120px; height: 60px; background: #f1f5f9; border: 1px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #94a3b8;">
                [FIRMENLOGO]</div>
        </div>
        <div class="field" data-tag="HEADER">
            <div
                style="border-bottom: 2px solid #1abc9c; padding-bottom: 10px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-end;">
                <div style="font-weight: 800; font-size: 20px;">BAU-PARTNERSCHAFT</div>
                <div style="text-align: right; font-size: 10px; color: #64748b;">Protokoll / Bericht</div>
            </div>
        </div>
        <div class="field" data-tag="SIG_KUNDE">
            <div style="width: 200px; height: 80px; border-bottom: 1px solid #000; position: relative;">
                <div style="position: absolute; bottom: 2px; left: 0; font-size: 8px;">Unterschrift Besteller</div>
                <div style="font-family: 'Cursive', sans-serif; font-size: 24px; padding-top: 20px; color: #1e3a8a;">Max
                    Mustermann</div>
            </div>
        </div>
        <div class="field" data-tag="SIG_UNTERNEHMER">
            <div style="width: 200px; height: 80px; border-bottom: 1px solid #000; position: relative;">
                <div style="position: absolute; bottom: 2px; left: 0; font-size: 8px;">Unterschrift Unternehmer</div>
                <div style="font-family: 'Cursive', sans-serif; font-size: 24px; padding-top: 20px; color: #1e3a8a;">Bau
                    AG Admin</div>
            </div>
        </div>
        <div class="form-section" data-section="STAMM"
            data-tags="TITEL,BETREFF,PROJEKT,OBJEKT,WOHNUNG,RAUM,DATUM,ORT,LEITUNG">
            <div class="section-header">📋 PROTOKOLL-VORBEREITUNG &amp; OBJEKTDATEN</div>
            <div class="section-body">
                <div class="grid-2">
                    <div class="field" data-tag="TITEL"><label for="m_title">Projekt-Titel {TITEL}</label><input type="text"
                            id="m_title" name="m_title" value="Baujournal" oninput="sync()"></div>
                    <div class="field" data-tag="BETREFF"><label for="m_subject">Betreff {BETREFF}</label><input type="text"
                            id="m_subject" name="m_subject" value="Tagesbericht" oninput="sync()"></div>
                </div>
                <div class="grid-2">
                    <div class="field" data-tag="PROJEKT">
                        <label for="m_project">🏗️ Projekt {PROJEKT}</label>
                        <select id="m_project" name="m_project" onchange="filterObjects(); sync()">
                            <option value="">-- wählen --</option>
                            <?php foreach ($projekte as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" data-tag="OBJEKT">
                        <label for="m_object">🏢 Objekt {OBJEKT}</label>
                        <select id="m_object" name="m_object" onchange="filterUnits(this.value); sync()">
                            <option value="">-- wählen --</option>
                        </select>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field" data-tag="WOHNUNG">
                        <label for="m_apartment">🏠 Wohnung {WOHNUNG}</label>
                        <select id="m_apartment" name="m_apartment" onchange="filterRooms(); sync()">
                            <option value="">-- Objekt wählen --</option>
                        </select>
                    </div>
                    <div class="field" data-tag="RAUM">
                        <label for="m_room">🚪 Raum {RAUM}</label>
                        <select id="m_room" name="m_room" onchange="sync()">
                            <option value="">-- Wohnung wählen --</option>
                        </select>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px;">
                    <div class="field" data-tag="DATUM"><label for="m_date">📅 Datum {DATUM}</label><input type="text" id="m_date" name="m_date"
                            class="datepicker" value="<?= date('d.m.Y H:i') ?>" onchange="sync()"></div>
                    <div class="field" data-tag="ORT"><label for="m_location">📍 Ort {ORT}</label><input type="text" id="m_location" name="m_location"
                            value="Baustelle" oninput="sync()"></div>
                    <div class="field" data-tag="LEITUNG">
                        <label for="m_leader">👤 Leitung {LEITUNG}</label>
                        <select id="m_leader" name="m_leader" onchange="sync()">
                            <?php foreach ($benutzer as $u): ?>
                                <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?>
                                </option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" data-tag="WEATHER">
                        <label style="display:flex; justify-content:space-between; align-items:center;">
                            <span id="weather_label">🌤️ Wetter {WEATHER}</span>
                            <button onclick="fetchWeather()" title="Wetter automatisch laden" style="background:none; border:none; cursor:pointer; font-size:12px; filter:grayscale(1) opacity(0.6);" onmouseover="this.style.filter='grayscale(0) opacity(1)'" onmouseout="this.style.filter='grayscale(1) opacity(0.6)'">🪄</button>
                        </label>
                        <select id="m_weather" name="m_weather" aria-labelledby="weather_label" onchange="sync()" style="font-size:11px;">
                            <option value="">-- wählen --</option>
                            <option value="☀️ Sonnig">☀️ Sonnig</option>
                            <option value="🌤️ Leicht bewölkt">🌤️ Leicht bewölkt</option>
                            <option value="☁️ Bewölkt">☁️ Bewölkt</option>
                            <option value="🌧️ Regen">🌧️ Regen</option>
                            <option value="🌨️ Schnee">🌨️ Schnee</option>
                        </select>
                    </div>
                    <div class="field" data-tag="TEMPERATUR">
                        <label id="temp_label">🌡️ Temp (°C)</label>
                        <div style="display:flex; gap:5px; align-items:center;">
                            <label for="m_temp_min" class="pd-hidden">Min Temp</label>
                            <input type="number" id="m_temp_min" name="m_temp_min" aria-labelledby="temp_label" placeholder="Min" style="width:50%; padding:5px; font-size:11px;" oninput="sync()">
                            <span>-</span>
                            <label for="m_temp_max" class="pd-hidden">Max Temp</label>
                            <input type="number" id="m_temp_max" name="m_temp_max" aria-labelledby="temp_label" placeholder="Max" style="width:50%; padding:5px; font-size:11px;" oninput="sync()">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="form-section" data-section="WERK" data-tags="OBJEKT_NR,WV_DATUM,WV_NR,BKP,GRUNDSTUECK,DEADLINE">
            <div class="section-header">📄 WERKVERTRAG &amp; OBJEKTNUMMERN</div>
            <div class="section-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                    <div class="field" data-tag="OBJEKT_NR"><label for="m_obj_nr">Objekt-Nr. {OBJEKT_NR}</label><input type="text"
                            id="m_obj_nr" name="m_obj_nr" oninput="sync()"></div>
                    <div class="field" data-tag="WV_DATUM"><label for="m_wv_date">WV-Datum {WV_DATUM}</label><input type="text"
                            id="m_wv_date" name="m_wv_date" class="datepicker-simple" onchange="sync()"></div>
                    <div class="field" data-tag="WV_NR"><label for="m_wv_nr">WV-Nr. {WV_NR}</label><input type="text" id="m_wv_nr" name="m_wv_nr"
                            oninput="sync()"></div>
                    <div class="field" data-tag="BKP">
                        <label for="m_bkp">BKP {BKP}</label>
                        <select id="m_bkp" name="m_bkp" onchange="sync()">
                            <option value="">-- BKP wählen --</option>
                            <?php foreach ($bkp_codes as $b): ?>
                                <option value="<?= htmlspecialchars($b['code']) ?>">
                                    <?= htmlspecialchars($b['code'] . ' ' . $b['bezeichnung']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" data-tag="GRUNDSTUECK"><label for="m_parzelle">Grundstück {GRUNDSTUECK}</label><input type="text"
                            id="m_parzelle" name="m_parzelle" oninput="sync()"></div>
                    <div class="field" data-tag="DEADLINE"><label for="m_deadline">Frist {DEADLINE}</label><input type="text"
                            id="m_deadline" name="m_deadline" class="datepicker-simple" onchange="sync()"></div>
                </div>
            </div>
        </div>
        <div class="form-section" data-section="TEXTE" data-tags="EINLEITUNG,SCHLUSSWORT">
            <div class="section-header">📝 TEXTE &amp; EINLEITUNG</div>
            <div class="section-body">
                <div class="field" data-tag="EINLEITUNG"><label for="m_intro">Einleitung {EINLEITUNG}</label><textarea id="m_intro" name="m_intro"
                        rows="3" oninput="sync()">Fortschritt der Arbeiten.</textarea></div>
                <div class="field" data-tag="SCHLUSSWORT"><label for="m_outro">Schlusswort {SCHLUSSWORT}</label><textarea id="m_outro" name="m_outro"
                        rows="3" oninput="sync()">Besonderheiten dokumentiert.</textarea></div>
            </div>
        </div>
        <div class="form-section" data-section="TEILNEHMER" data-tags="TEILNEHMER">
            <div class="section-header">👥 TEILNEHMER / Presente Unternehmer und Arbeiter</div>
            <div class="field" data-tag="TEILNEHMER">
                <div class="pd-box" style="border-radius:12px;">
                    <div style="display:flex; gap:10px; margin-bottom:15px;">
                        <label for="sel_contact" class="pd-hidden">Kontakt wählen</label>
                        <select id="sel_contact" name="sel_contact" class="sel_contact" style="flex:1; padding:8px; border-radius:6px; border:1px solid #ddd; font-size:13px;">
                            <option value="">-- Kontakt wählen --</option>
                            <optgroup label="Benutzer">
                                <?php foreach ($benutzer as $u): 
                                    $v = ($u['name']??'') . '||' . ($u['firma_name'] ?: 'Vermieter') . '||' . ($u['email'] ?? '');
                                ?>
                                    <option value="<?= htmlspecialchars($v) ?>">
                                        <?= htmlspecialchars($u['name'] ?? 'Unbenannt') ?> (<?= htmlspecialchars($u['firma_name'] ?: 'Intern') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Mieter">
                                <?php foreach ($mieter as $m): 
                                    $v = ($m['name']??'') . '||Mieter||' . ($m['email'] ?? '');
                                ?>
                                    <option value="<?= htmlspecialchars($v) ?>">
                                        <?= htmlspecialchars($m['name'] ?? 'Unbenannt') ?> (Mieter)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <button onclick="addContact()" style="padding:8px 20px; background:#1abc9c; color:#fff; border:none; border-radius:6px; font-weight:700; cursor:pointer;">Hinzufügen</button>
                    </div>
                    <table class="participants-table">
                        <thead><tr><th style="width:30px;">E.</th><th>Name</th><th>Rolle / Firma</th><th>Email</th><th style="width:50px;">Aktion</th></tr></thead>
                        <tbody class="participant_body"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="form-section" data-section="UNTERSCHRIFTEN" data-tags="UNTERSCHRIFTEN,SIG_UNTERNEHMER">
            <div class="section-header">✍️ UNTERSCHRIFTEN</div>
            <div class="field" data-tag="UNTERSCHRIFTEN">
                <div class="pd-box" style="border-radius:12px; padding:20px; text-align:center; border:2px dashed #cbd5e1; background:#f8fafc;">
                    <div style="font-size:24px; color:#94a3b8; margin-bottom:10px;">✍️</div>
                    <div style="font-weight:700; color:#475569;">Allgemeiner Unterschriften-Block</div>
                    <div style="font-size:11px; color:#64748b;">(Bauleitung, Bauherr, etc.)</div>
                </div>
            </div>
            <div class="field" data-tag="SIG_UNTERNEHMER">
                <div class="pd-box" style="border-radius:12px; padding:15px; border:1px solid #e2e8f0; background:#fff;">
                    <label for="m_sig_unternehmer" style="color:#1abc9c;">Unternehmer (Name)</label>
                    <input type="text" id="m_sig_unternehmer" name="m_sig_unternehmer" placeholder="Vorname Nachname..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; font-size:13px;" oninput="sync()">
                    <div style="margin-top:20px; border-bottom:1px solid #1e293b; height:1px;"></div>
                    <div style="font-size:9px; color:#64748b; text-transform:uppercase; margin-top:5px; font-weight:800;">Unterschrift Unternehmer</div>
                </div>
            </div>
        </div>
        <div class="form-section" data-section="BLOCKS" data-tags="BLOCKS">
            <div class="section-header">🔧 INHALTSBLÖCKE / BEMERKUNGEN</div>
            <div class="field" data-tag="BLOCKS">
                <div class="pd-box" style="border-radius:12px; padding-bottom:30px;">
                    <div style="text-align:right; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                        <button onclick="addBlock()" style="background:none; border:none; color:#1abc9c; font-weight:700; cursor:pointer; font-size:13px;">+ Block hinzufügen</button>
                    </div>
                    <div id="blocks_container"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const ALL_OBJEKTE = <?= json_encode($objekte) ?>;
        const ALL_WOHNUNGEN = <?= json_encode($wohnungen) ?>;
        const ALL_RAEUME = <?= json_encode($raeume) ?>;

        function filterObjects(pid) {
            const val = pid !== undefined ? pid : (document.querySelector('#m_project')?.value || '');
            console.log("Filtering objects for project:", val);

            const sel = document.querySelector('#all-fields-pool #m_object');
            if (!sel) return;

            // Wert im Master-Pool setzen
            const masterProject = document.querySelector('#all-fields-pool #m_project');
            if (masterProject) masterProject.value = val;

            sel.innerHTML = '<option value="">-- wählen --</option>';
            const filtered = ALL_OBJEKTE.filter(o => String(o.projekt_id) === String(val));
            console.log("Found objects:", filtered.length);

            filtered.forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.id; opt.textContent = o.name;
                sel.appendChild(opt);
            });

            // Immer Units mit zurücksetzen
            filterUnits('');
        }

        function filterUnits(oid) {
            const val = oid !== undefined ? oid : (document.querySelector('#m_object')?.value || '');
            const sel = document.querySelector('#all-fields-pool #m_apartment');
            if (!sel) return;

            const masterObject = document.querySelector('#all-fields-pool #m_object');
            if (masterObject) masterObject.value = val;

            sel.innerHTML = '<option value="">-- wählen --</option>';
            ALL_WOHNUNGEN.filter(w => String(w.objekt_id) === String(val)).forEach(w => {
                const opt = document.createElement('option');
                opt.value = w.id; opt.textContent = w.name;
                sel.appendChild(opt);
            });
            filterRooms('');
        }

        function filterRooms(aid) {
            const val = aid !== undefined ? aid : (document.querySelector('#m_apartment')?.value || '');
            const sel = document.querySelector('#all-fields-pool #m_room');
            if (!sel) return;

            const masterApartment = document.querySelector('#all-fields-pool #m_apartment');
            if (masterApartment) masterApartment.value = val;

            sel.innerHTML = '<option value="">-- wählen --</option>';
            ALL_RAEUME.filter(r => String(r.wohnung_id) === String(val)).forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id; opt.textContent = r.name;
                sel.appendChild(opt);
            });
        }

        function addContact() {
            const sel = document.getElementById('sel_contact');
            if (!sel.value) return;
            const [name, role] = sel.value.split('||');
            const tbody = document.querySelector('.participant_body');
            const idx = tbody.children.length;
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><label for="p_check_${idx}" class="pd-hidden">Präsent</label><input type="checkbox" id="p_check_${idx}" checked onchange="sync()"></td><td>${name}</td><td>${role}</td><td><button onclick="this.parentElement.parentElement.remove(); sync()">x</button></td>`;
            tbody.appendChild(tr);
            sync();
        }

        function addBlock() {
            const container = document.getElementById('blocks_container');
            const div = document.createElement('div');
            div.className = 'dynamic-block';
            div.style.background = "#fff";
            div.style.border = "1px solid #e2e8f0";
            div.style.borderRadius = "12px";
            div.style.padding = "15px";
            div.style.marginBottom = "15px";
            div.style.position = "relative";
            div.style.boxShadow = "0 4px 10px rgba(0,0,0,0.03)";
            
            const idx = container.children.length;
            div.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label for="b_title_${idx}" class="pd-hidden">Block Titel</label>
                    <input type="text" id="b_title_${idx}" class="b_title" placeholder="Block Titel (z.B. Besondere Vorkommnisse)" style="flex:1; border:none; font-weight:800; font-size:14px; color:#1e293b; outline:none;" oninput="sync()">
                    <button onclick="this.closest('.dynamic-block').remove(); sync()" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:18px;">&times;</button>
                </div>
                <label for="b_content_${idx}" class="pd-hidden">Block Inhalt</label>
                <textarea id="b_content_${idx}" class="b_content" placeholder="Beschreiben Sie hier die Details..." rows="3" style="width:100%; border:1px solid #f1f5f9; border-radius:8px; padding:10px; font-size:13px; resize:vertical; outline:none;" oninput="sync()"></textarea>
            `;
            container.appendChild(div);
            sync();
        }

        flatpickr(".datepicker", { enableTime: true, dateFormat: "d.m.Y H:i", time_24hr: true });
        flatpickr(".datepicker-simple", { dateFormat: "d.m.Y" });

        let CURRENT_CONFIG = null;
        let CURRENT_BRANDING = {};

        window.addEventListener('message', function (e) {
            if (e.data.type === 'sync_config') {
                CURRENT_CONFIG = e.data.config;
                rebuildFlow(e.data.tags);
                rebuildPreview();
            }
        });

        function rebuildFlow(activeTags) {
            const flow = document.getElementById('smart-flow');
            const pool = document.getElementById('all-fields-pool');
            const currentSections = flow.querySelectorAll('.form-section');
            currentSections.forEach(sec => {
                const fields = sec.querySelectorAll('.field');
                fields.forEach(f => f.classList.add('pd-hidden'));
                pool.appendChild(sec);
            });
            if (!activeTags || activeTags.length === 0) {
                flow.innerHTML = '<div style="padding:40px; text-align:center; color:#94a3b8; font-style:italic;">Keine Tags auf dem Canvas.</div>';
                return;
            }
            flow.innerHTML = '';
            activeTags.forEach(tag => {
                const field = pool.querySelector(`.field[data-tag="${tag}"]`);
                if (field) {
                    field.classList.remove('pd-hidden');
                    const section = field.closest('.form-section');
                    if (section && !flow.contains(section)) flow.appendChild(section);

                }
                if (tag === 'TEILNEHMER' || tag === 'BLOCKS') {
                    const section = pool.querySelector(`.form-section[data-section="${tag}"]`);
                    if (section) flow.appendChild(section);
                }
            });
        }

        function autoScale() {
            const preview = document.querySelector('.pd-a4-preview');
            if (!preview) return;

            const containerWidth = window.innerWidth;
            const containerHeight = window.innerHeight;
            const padding = 40;

            const targetWidth = containerWidth - padding;
            const targetHeight = containerHeight - padding;

            const originalWidth = (CURRENT_CONFIG && CURRENT_CONFIG.page) ? CURRENT_CONFIG.page.width : 794;
            const originalHeight = (CURRENT_CONFIG && CURRENT_CONFIG.page) ? CURRENT_CONFIG.page.height : 1123;

            const scaleW = targetWidth / originalWidth;
            const scaleH = targetHeight / originalHeight;

            // Skaliere so, dass die GESAMTE VORLAGE sichtbar ist
            let scale = Math.min(scaleW, scaleH);
            if (scale > 1.2) scale = 1.2; 

            preview.style.transform = `scale(${scale})`;

            // Manuelle Zentrierung
            const scaledWidth = originalWidth * scale;
            const leftPos = (containerWidth - scaledWidth) / 2;
            preview.style.left = leftPos + 'px';

            // Da wir die gesamte Vorlage sehen wollen, setzen wir die Body-Höhe fest auf die Viewport-Höhe
            document.body.style.height = '100vh';
            document.body.style.overflow = 'hidden';
        }

        function rebuildPreview() {
            if (!CURRENT_CONFIG) return;
            if (!CURRENT_CONFIG.page) return; // Sicherheitscheck: altes Format ohne page-Key
            const previewPage = document.querySelector('.pd-a4-preview');
            if (!previewPage) return;
            const scale = previewPage.offsetWidth / (CURRENT_CONFIG.page.width || 794);
            previewPage.style.minHeight = (CURRENT_CONFIG.page.height * scale) + 'px';


            // Margins (nur aktualisieren wenn nötig)
            let mBox = previewPage.querySelector('.pd-margin-box');
            if (!mBox) {
                mBox = document.createElement('div');
                mBox.className = 'pd-margin-box';
                previewPage.appendChild(mBox);
            }
            mBox.style.top = (CURRENT_CONFIG.page.marginTop * scale) + 'px';
            mBox.style.left = (CURRENT_CONFIG.page.marginLeft * scale) + 'px';
            mBox.style.width = ((CURRENT_CONFIG.page.width - CURRENT_CONFIG.page.marginLeft - CURRENT_CONFIG.page.marginRight) * scale) + 'px';
            mBox.style.height = ((CURRENT_CONFIG.page.height - CURRENT_CONFIG.page.marginTop - CURRENT_CONFIG.page.marginBottom) * scale) + 'px';

            const pool = document.getElementById('all-fields-pool');
            const existingIds = new Set();

            CURRENT_CONFIG.blocks.forEach(b => {
                const bId = String(b.id);
                existingIds.add(bId);

                let div = previewPage.querySelector(`.preview-block[data-id="${bId}"]`);
                if (!div) {
                    div = document.createElement('div');
                    div.className = 'preview-block';
                    div.setAttribute('data-id', bId);
                    div.style.position = 'absolute';
                    previewPage.appendChild(div);
                }

                // STYLE UPDATES (Immer live und ohne Re-Render des Inhalts)
                div.style.left = (b.x * scale) + 'px';
                div.style.top = (b.y * scale) + 'px';
                div.style.width = (b.w * scale) + 'px';
                div.style.height = (b.h * scale) + 'px';
                div.style.fontSize = ((b.fontSize || 12) * scale) + 'px';
                div.style.color = b.color || '#334155';
                div.style.backgroundColor = b.bgColor || 'transparent';
                div.style.fontWeight = b.bold ? 'bold' : 'normal';
                div.style.fontStyle = b.italic ? 'italic' : 'normal';
                div.style.textDecoration = b.underline ? 'underline' : 'none';
                div.style.textAlign = b.align || 'left';
                div.style.zIndex = bId;

                // NEU: Rahmen & Radius
                if (b.borderWidth > 0) {
                    div.style.border = `${b.borderWidth * scale}px solid ${b.borderColor || '#cbd5e1'}`;
                } else {
                    div.style.border = 'none';
                }
                if (b.radTL) div.style.borderTopLeftRadius = (b.radTL * scale) + 'px';
                if (b.radTR) div.style.borderTopRightRadius = (b.radTR * scale) + 'px';
                if (b.radBL) div.style.borderBottomLeftRadius = (b.radBL * scale) + 'px';
                if (b.radBR) div.style.borderBottomRightRadius = (b.radBR * scale) + 'px';

                div.style.cursor = 'pointer'; // Klickbar machen

                div.onclick = (e) => {
                    // Verhindere das Auslösen bei Klick in ein Input-Feld
                    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
                    window.parent.postMessage({ type: 'select_block', id: b.id }, '*');
                };

                if (b.valign) {
                    div.style.display = 'flex';
                    div.style.flexDirection = 'column';
                    div.style.justifyContent = b.valign === 'middle' ? 'center' : (b.valign === 'bottom' ? 'flex-end' : 'flex-start');
                } else {
                    div.style.display = 'block';
                }

                // CONTENT UPDATE (Nur wenn Text sich wirklich ändert)
                if (div.getAttribute('data-raw') !== b.text) {
                    div.setAttribute('data-raw', b.text);
                    let html = b.text;

                    // Branding Tags Ersetzen
                    if (CURRENT_BRANDING) {
                        const headerHtml = `<strong>${CURRENT_BRANDING.name}</strong><br><span style="font-size:0.9em; color:#64748b;">Protokoll / Bericht</span>`;
                        let logoHtml = '🚩 [LOGO]';
                        if (CURRENT_BRANDING.logo) {
                            let lp = CURRENT_BRANDING.logo;
                            if (!lp.startsWith('http') && !lp.startsWith('/pendenz.com/')) {
                                lp = '/pendenz.com/' + lp.replace(/^\//, '');
                            }
                            logoHtml = `<img src="${lp}" style="max-width:100%; max-height:100%; object-fit:contain;">`;
                        }
                        html = html.replace(/{HEADER}/gi, headerHtml);
                        html = html.replace(/{LOGO}/gi, logoHtml);
                        html = html.replace(/{COMPANY_NAME}/gi, CURRENT_BRANDING.name || '');
                        html = html.replace(/{COMPANY_EMAIL}/gi, CURRENT_BRANDING.email || '');
                    }

                    const foundTags = Array.from(html.matchAll(/\{([A-Za-z0-9_]+)\}/gi));
                    foundTags.forEach(m => {
                        const tag = m[1].toUpperCase();
                        const sourceField = pool.querySelector(`.field[data-tag="${tag}"]`);
                        if (sourceField) {
                            const input = sourceField.querySelector('input, select, textarea');
                            const isComplex = sourceField.querySelector('table, .pd-box') || tag === 'TEILNEHMER';
                            
                            if (input && !isComplex) {
                                html = html.replace(m[0], `<div class="inline-input-wrapper field" data-tag="${tag}">${input.outerHTML}</div>`);
                            } else {
                                html = html.replace(m[0], `<div class="inline-input-wrapper field complex-field" data-tag="${tag}">${sourceField.innerHTML}</div>`);
                            }
                        }
                    });
                    div.innerHTML = html;

                    // Sync Inputs
                    div.querySelectorAll('.inline-input-wrapper').forEach(wrapper => {
                        const tag = wrapper.dataset.tag;
                        const el = wrapper.querySelector('input, select, textarea');
                        const master = pool.querySelector(`.field[data-tag="${tag}"] input, .field[data-tag="${tag}"] select, .field[data-tag="${tag}"] textarea`);
                        if (el && master) {
                            el.value = master.value;
                            el.oninput = () => {
                                master.value = el.value;
                                if (tag === 'PROJEKT') filterObjects(el.value);
                                if (tag === 'OBJEKT') filterUnits(el.value);
                                if (tag === 'WOHNUNG') filterRooms(el.value);
                                sync();
                            };
                            el.onchange = el.oninput;
                            if (el.classList.contains('datepicker') || el.classList.contains('datepicker-simple')) {
                                flatpickr(el, {
                                    enableTime: el.classList.contains('datepicker'),
                                    dateFormat: el.classList.contains('datepicker') ? "d.m.Y H:i" : "d.m.Y",
                                    time_24hr: true,
                                    onClose: () => { master.value = el.value; sync(); }
                                });
                            }
                        }
                    });
                }
            });

            // Veraltete Blöcke entfernen
            previewPage.querySelectorAll('.preview-block').forEach(div => {
                if (!existingIds.has(div.getAttribute('data-id'))) div.remove();
            });

            autoScale();
            renderParticipantTables();
        }

        function renderParticipantTables() {
            const bodies = document.querySelectorAll('.participant_body');
            bodies.forEach(body => {
                body.innerHTML = '';
                (CURRENT_PARTICIPANTS || []).forEach((p, idx) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                    <td><input type="checkbox" ${p.checked ? 'checked' : ''} onchange="toggleParticipant(${idx})"></td>
                    <td><strong>${p.name}</strong></td>
                    <td>${p.role}</td>
                    <td style="color:#64748b; font-size:0.9em;">${p.email || '-'}</td>
                    <td style="text-align:center;"><button onclick="removeContact(${idx})" style="color:red; border:none; background:none; cursor:pointer; font-size:16px;">&times;</button></td>
                `;
                    body.appendChild(tr);
                });
            });
        }

        let CURRENT_PARTICIPANTS = [];

        function addContact(btn) {
            const field = btn.closest('.field');
            const sel = field.querySelector('.sel_contact');
            if (!sel || !sel.value) return;
            const [name, role, email] = sel.value.split('||');
            CURRENT_PARTICIPANTS.push({ name, role, email, checked: true });
            renderParticipantTables();
            sync();
        }

        function removeContact(idx) {
            CURRENT_PARTICIPANTS.splice(idx, 1);
            renderParticipantTables();
            sync();
        }

        function toggleParticipant(idx) {
            CURRENT_PARTICIPANTS[idx].checked = !CURRENT_PARTICIPANTS[idx].checked;
            sync();
        }

        function getFormData() {
            const getVal = (id) => document.getElementById(id)?.value || '';
            const getSelText = (id) => {
                const el = document.getElementById(id);
                return (el && el.selectedIndex >= 0) ? el.options[el.selectedIndex].text : '';
            };
            return {
                TITEL: getVal('m_title'),
                BETREFF: getVal('m_subject'),
                PROJEKT: getSelText('m_project'),
                OBJEKT: getSelText('m_object'),
                WOHNUNG: getSelText('m_apartment'),
                RAUM: getSelText('m_room'),
                DATUM: getVal('m_date'),
                ORT: getVal('m_location'),
                LEITUNG: getSelText('m_leader'),
                WEATHER: getVal('m_weather'),
                TEMP_MIN: getVal('m_temp_min'),
                TEMP_MAX: getVal('m_temp_max'),
                TEMP_RANGE: (getVal('m_temp_min') && getVal('m_temp_max')) ? (getVal('m_temp_min') + '°C - ' + getVal('m_temp_max') + '°C') : (getVal('m_temp_min') ? getVal('m_temp_min')+'°C' : (getVal('m_temp_max') ? getVal('m_temp_max')+'°C' : '')),
                Temperatur: (getVal('m_temp_min') && getVal('m_temp_max')) ? (getVal('m_temp_min') + '°C - ' + getVal('m_temp_max') + '°C') : (getVal('m_temp_min') ? getVal('m_temp_min')+'°C' : (getVal('m_temp_max') ? getVal('m_temp_max')+'°C' : '')),
                OBJEKT_NR: getVal('m_obj_nr'),
                WV_DATUM: getVal('m_wv_date'),
                WV_NR: getVal('m_wv_nr'),
                BKP: getVal('m_bkp'),
                GRUNDSTUECK: getVal('m_parzelle'),
                DEADLINE: getVal('m_deadline'),
                EINLEITUNG: getVal('m_intro'),
                SCHLUSSWORT: getVal('m_outro'),
                TEILNEHMER: CURRENT_PARTICIPANTS.filter(p => p.checked).map(p => p.name).join(', '),
                TEILNEHMER_LIST: CURRENT_PARTICIPANTS.filter(p => p.checked),
                SIG_UNTERNEHMER: getVal('m_sig_unternehmer'),
                BLOCKS: Array.from(document.querySelectorAll('#blocks_container .dynamic-block')).map(d => {
                    const t = d.querySelector('.b_title')?.value || '';
                    const c = d.querySelector('.b_content')?.value || '';
                    if(!t && !c) return '';
                    return `<div style="margin-bottom:15px;"><div style="font-weight:800; margin-bottom:4px;">${t}</div><div style="font-size:0.95em;">${c.replace(/\n/g, '<br>')}</div></div>`;
                }).join('')
            };
        }

        function sync() {
            const data = getFormData();
            updateValuesOnly(data);
            window.parent.postMessage({ type: 'sync_data', data: data }, '*');
        }

        function updateValuesOnly(data) {
            const activeId = document.activeElement?.id;
            const start = document.activeElement?.selectionStart;
            const end = document.activeElement?.selectionEnd;
            rebuildPreview();
            if (activeId) {
                const el = document.getElementById(activeId);
                if (el) { el.focus(); if (typeof start === 'number') el.setSelectionRange(start, end); }
            }
        }

        window.addEventListener('message', (event) => {
            if (event.data.type === 'sync_config') {
                CURRENT_CONFIG = event.data.config;
                CURRENT_BRANDING = event.data.branding || {};
                // Company header aktualisieren
                const bn = document.getElementById('brand-name');
                const bl = document.getElementById('brand-logo');
                if (bn && CURRENT_BRANDING.name) bn.textContent = CURRENT_BRANDING.name;
                if (bl) {
                    if (CURRENT_BRANDING.logo) {
                        let lp = CURRENT_BRANDING.logo;
                        if (!lp.startsWith('http') && !lp.startsWith('/pendenz.com/')) lp = '/pendenz.com/' + lp.replace(/^\//, '');
                        bl.innerHTML = `<img src="${lp}" style="max-width:100px;max-height:55px;object-fit:contain;">`;
                    } else if (CURRENT_BRANDING.name) {
                        bl.innerHTML = `<div style="font-size:11px;font-weight:800;color:#1abc9c;border:2px solid #1abc9c;padding:6px 10px;border-radius:6px;">${CURRENT_BRANDING.name.substring(0,2).toUpperCase()}</div>`;
                    }
                }
                // Smart-flow Sections basierend auf Canvas-Tags zeigen
                if (event.data.tags && event.data.tags.length > 0) {
                    rebuildFlow(event.data.tags);
                }
                rebuildPreview();
            }
            if (event.data.type === 'sync_tags') {
                rebuildFlow(event.data.tags);
            }
            if (event.data.type === 'resize') {
                autoScale();
            }
        });

        // GLOBAL HIERARCHY SYNC
        document.addEventListener('change', (e) => {
            const id = e.target.id;
            const val = e.target.value;
            if (id === 'm_project') filterObjects(val);
            if (id === 'm_object') filterUnits(val);
            if (id === 'm_apartment') filterRooms(val);
        });

        document.addEventListener('DOMContentLoaded', () => {
            // Initialer Check falls schon was gewählt ist
            const p = document.getElementById('m_project');
            if (p && p.value) filterObjects(p.value);
            autoScale();
            setTimeout(sync, 300);
        });

        async function fetchWeather() {
            const ort = document.getElementById('m_location')?.value || 'Zürich';
            const datum = document.getElementById('m_date')?.value || '';
            
            // Simulation eines API-Requests für die Demo
            // In einer produktiven Umgebung würde hier ein Fetch auf OpenWeatherMap oder wttr.in stehen
            const btn = event.currentTarget;
            btn.textContent = '⏳';
            
            try {
                // Wir simulieren eine kurze Ladezeit
                await new Promise(r => setTimeout(r, 800));
                
                // Demo-Logik: Generiere plausible Daten basierend auf dem Ort
                const mockWeather = ["☀️ Sonnig", "🌤️ Leicht bewölkt", "☁️ Bewölkt"][Math.floor(Math.random() * 3)];
                const mockMin = Math.floor(Math.random() * 5) + 10;
                const mockMax = mockMin + Math.floor(Math.random() * 8) + 2;
                
                document.getElementById('m_weather').value = mockWeather;
                document.getElementById('m_temp_min').value = mockMin;
                document.getElementById('m_temp_max').value = mockMax;
                
                sync();
                btn.textContent = '✅';
                setTimeout(() => { btn.textContent = '🪄'; }, 2000);
                
            } catch(e) {
                btn.textContent = '❌';
                setTimeout(() => { btn.textContent = '🪄'; }, 2000);
            }
        }

        window.addEventListener('resize', autoScale);
    </script>
</body>

</html>