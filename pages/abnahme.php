<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/room_taxonomy.php';
require_once '../includes/vorgang_taxonomy.php';

require_login();

$pid = (int)($_GET['projekt_id'] ?? 0);
$uid = (int)($_GET['unit_id'] ?? 0);

if ($pid <= 0 || $uid <= 0) {
    die("Ungültige Projekt- oder Wohnungs-ID.");
}

// Projekt & Wohnung laden
$unit = $mysqli->query("SELECT w.*, p.name as p_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid AND p.id = $pid")->fetch_assoc();
if (!$unit)
    die("Wohnung nicht gefunden.");

// Echte Räume der Wohnung laden
$raeume = raeume_get_by_wohnung($mysqli, (int) $uid);
if (empty($raeume)) {
    $raeume = [['name' => 'Wohnzimmer'], ['name' => 'Schlafzimmer'], ['name' => 'Küche'], ['name' => 'Badezimmer'], ['name' => 'Keller']];
}

// Aktuellen Mieter finden
$mieter = $mysqli->query("SELECT * FROM wohnung_mieter WHERE wohnung_id = $uid AND (status = 'aktiv' OR (startdatum <= CURDATE() AND (enddatum IS NULL OR enddatum >= CURDATE()))) LIMIT 1")->fetch_assoc();

// Alle Mieter dieser Wohnung (für Dropdown)
$allTenants = $mysqli->query("SELECT * FROM wohnung_mieter WHERE wohnung_id = $uid ORDER BY mieter_name ASC")->fetch_all(MYSQLI_ASSOC);

// Self-Healing: Tabelle erstellen falls sie fehlt
$mysqli->query("CREATE TABLE IF NOT EXISTS abnahme_protokolle (
    id INT AUTO_INCREMENT PRIMARY KEY,
    projekt_id INT,
    wohnung_id INT,
    mieter_id INT,
    mieter_name_custom VARCHAR(255),
    daten_json LONGTEXT,
    erstellt_am DATETIME,
    erstellt_von INT,
    pdf_pfad VARCHAR(255)
)");

// Alle Benutzer (Vermieter/Verwalter)
$allUsers = $mysqli->query("SELECT id, name FROM benutzer ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Aktueller Benutzer & Firma
$userId = $_SESSION['user_id'] ?? 0;
$currentUser = $mysqli->query("SELECT name, firma_name FROM benutzer WHERE id = $userId")->fetch_assoc();
$currentUserName = $currentUser['name'] ?? 'Unbekannt';
$firmaName = $currentUser['firma_name'] ?? 'Baupartnerschaft AG';

// Vorgangsarten laden (Taxonomie)
vorgang_taxonomy_ensure_tables($mysqli);
$vorgangsarten = $mysqli->query("SELECT id, name as bezeichnung FROM pendenzen_arten ORDER BY sort_order ASC, name ASC")->fetch_all(MYSQLI_ASSOC);
$vaMap = [];
foreach($vorgangsarten as $va) $vaMap[$va['id']] = $va['bezeichnung'];

// BKP & Vorlagen Daten für JS
$mieterKats = $mysqli->query("SELECT id, name FROM pendenz_kategorien_mieter WHERE aktiv = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$mieterSubs = [];
$resMS = $mysqli->query("SELECT id, kategorie_id, name, beschreibung FROM pendenz_subkategorien_mieter WHERE aktiv = 1");
while($r = $resMS->fetch_assoc()) $mieterSubs[$r['kategorie_id']][] = $r;

$vermieterKats = $mysqli->query("SELECT id, name FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$vermieterSubs = [];
$resVS = $mysqli->query("SELECT id, kategorie_id, name, beschreibung FROM pendenz_subkategorien_vermieter WHERE aktiv = 1");
while($r = $resVS->fetch_assoc()) $vermieterSubs[$r['kategorie_id']][] = $r;

$bkpCodes = $mysqli->query("SELECT id, code, bezeichnung as name FROM bkp_codes ORDER BY code")->fetch_all(MYSQLI_ASSOC);
$bkpKats = [];
$resBK = $mysqli->query("SELECT id, bkp_id, name FROM bkp_kategorien ORDER BY name");
if (!$resBK) $resBK = $mysqli->query("SELECT id, bkp_id, bezeichnung as name FROM bkp_kategorien ORDER BY bezeichnung");
while($r = $resBK->fetch_assoc()) $bkpKats[$r['bkp_id']][] = $r;

$bkpTexts = [];
$resBT = $mysqli->query("SELECT id, kategorie_id, text as name, text FROM bkp_vorlagen_texte ORDER BY id ASC");
if (!$resBT) $resBT = $mysqli->query("SELECT id, kategorie_id, bezeichnung as name, text FROM bkp_vorlagen_texte ORDER BY id ASC");
while($r = $resBT->fetch_assoc()) $bkpTexts[$r['kategorie_id']][] = $r;

// Vorhandene Protokolle dieser Wohnung laden
$savedProtocols = $mysqli->query("SELECT id, erstellt_am, mieter_name_custom, daten_json FROM abnahme_protokolle WHERE wohnung_id = $uid ORDER BY erstellt_am DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
foreach ($savedProtocols as &$sp) {
    $spData = json_decode($sp['daten_json'], true);
    $sp['name_display'] = $spData['protokoll_bezeichnung'] ?? $sp['mieter_name_custom'] ?? 'Unbenannt';
}
unset($sp);

// Bestehendes Protokoll laden falls ID übergeben
$loadedData = null;
if (isset($_GET['protocol_id'])) {
    $pId = (int)$_GET['protocol_id'];
    $pRes = $mysqli->query("SELECT daten_json FROM abnahme_protokolle WHERE id = $pId AND wohnung_id = $uid");
    if ($pRow = $pRes->fetch_assoc()) {
        $loadedData = json_decode($pRow['daten_json'], true);
    }
}

// Navigation: Nächste / Vorherige Wohnung im gleichen Projekt
$allUnits = $mysqli->query("SELECT w.id FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE o.projekt_id = $pid ORDER BY w.name ASC, w.id ASC")->fetch_all(MYSQLI_ASSOC);
$prevUid = null;
$nextUid = null;
for ($i = 0; $i < count($allUnits); $i++) {
    if ($allUnits[$i]['id'] == $uid) {
        $prevUid = $allUnits[$i - 1]['id'] ?? null;
        $nextUid = $allUnits[$i + 1]['id'] ?? null;
        break;
    }
}

$PAGE_TITLE = 'Abnahme Protokoll - ' . ($unit['name'] ?? '');
require_once '../includes/header.php';
require_once '../includes/nav_dispatch.php';
?>
<main class="page">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #eff6ff;
            --card: #ffffff;
            --text: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
        }

        .abnahme-wrapper {
            padding: 20px;
            background: #f8fafc;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--card);
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        }

        .header {
            border-bottom: 3px solid var(--primary);
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: -0.5px;
            color: var(--text);
        }

        .unit-info {
            background: var(--primary-light);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            border: 1px solid #dbeafe;
        }

        .info-box label {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            display: block;
            margin-bottom: 4px;
        }

        .info-box div, .info-box select, .info-box input {
            font-weight: 600;
            font-size: 14px;
            width: 100%;
            border: none;
            background: transparent;
        }

        /* Nav-Bar integration */
        .page-nav-wrapper {
            margin: -20px -20px 20px -20px;
        }
        
        .unit-nav-buttons {
            display: flex;
            gap: 10px;
        }
        
        .nav-btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .nav-btn-prev {
            background: #f1f5f9;
            color: #475569;
        }
        
        .nav-btn-prev:hover {
            background: #e2e8f0;
        }
        
        .nav-btn-next {
            background: var(--primary);
            color: white;
        }
        
        .nav-btn-next:hover {
            background: var(--primary-dark);
        }
        
        .nav-btn.disabled {
        @media (max-width: 768px) {
            .item-row {
                grid-template-columns: 1fr;
            }

            .item-label {
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
            }

            .signature-grid {
                grid-template-columns: 1fr;
            }
        }
        /* Protocol Section Styles */
        .section {
            margin-bottom: 40px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        .section-header {
            background: #f8fafc;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            color: var(--text);
        }

        .section-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .room-card {
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .room-header {
            background: #f8fafc;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .room-header h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--primary);
        }

        .room-clean-label {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            color: var(--text-muted);
        }

        .room-body {
            padding: 0;
        }

        .item-row {
            display: grid;
            grid-template-columns: 180px 150px 1fr;
            border-bottom: 1px solid #f1f5f9;
            align-items: center;
            transition: background 0.2s;
        }

        .item-row:hover {
            background: #fafafa;
        }

        .item-row.is-pendenz {
            border-left: 4px solid var(--danger);
            background: #fff1f2;
        }

        .item-row:last-child {
            border-bottom: none;
        }

        .item-label {
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            background: #fafafa;
            border-right: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .item-status {
            padding: 8px 15px;
        }

        .item-content {
            padding: 8px 15px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .category-grid-container {
            width: 100%;
            margin-top: 10px;
            display: none;
            background: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 8px;
        }

        .cat-btn {
            background: white;
            border: 1px solid var(--border);
            padding: 6px 10px;
            font-size: 11px;
            border-radius: 4px;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
        }

        .cat-btn:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        /* Counters & Signatures */
        .counter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .counter-item {
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 15px;
        }

        .counter-item label {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 8px;
            display: block;
            color: var(--text-muted);
        }

        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 30px;
        }

        .sig-box {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 15px;
        }

        .sig-canvas {
            width: 100%;
            height: 120px;
            border-bottom: 1px dashed var(--border);
            margin-bottom: 10px;
            background: #fafafa;
        }

        .btn-save {
            background: var(--primary);
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-save:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .btn-save:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        /* Global Inputs */
        select, input[type="text"], input[type="number"] {
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 13px;
            width: 100%;
            box-sizing: border-box;
        }

        @media (max-width: 768px) {
            .item-row {
                grid-template-columns: 1fr;
            }

            .item-label {
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
            }

            .signature-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <div class="abnahme-wrapper">
    <div class="container">
        <div class="header">
            <div>
                <div style="font-size: 12px; font-weight: 700; color: var(--primary); margin-bottom: 5px;">
                    ABNAHMEPROTOKOLL</div>
                <h1><?= htmlspecialchars($unit['name']) ?></h1>
                <div style="font-size: 14px; color: var(--text-muted); margin-top: 5px;">
                    <?= htmlspecialchars($unit['p_name']) ?>
                </div>
            </div>
            <div class="unit-nav-buttons">
                <a href="<?= $prevUid ? "?projekt_id=$pid&unit_id=$prevUid" : '#' ?>" class="nav-btn nav-btn-prev <?= !$prevUid ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-left"></i> Vorherige
                </a>
                <a href="<?= $nextUid ? "?projekt_id=$pid&unit_id=$nextUid" : '#' ?>" class="nav-btn nav-btn-next <?= !$nextUid ? 'disabled' : '' ?>">
                    Nächste <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>

        <?php if (!empty($savedProtocols)): ?>
            <div
                style="margin-bottom: 30px; padding: 20px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                <label
                    style="font-size: 11px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 15px;">
                    <i class="fas fa-archive" style="margin-right: 5px;"></i> Gespeicherte Protokolle
                </label>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach ($savedProtocols as $sp): ?>
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9;">
                            <div>
                                <div style="font-weight: 700; font-size: 14px; color: #1e293b;">
                                    <?= htmlspecialchars($sp['name_display']) ?></div>
                                <div style="font-size: 11px; color: #64748b;">
                                    <?= date('d.m.Y, H:i', strtotime($sp['erstellt_am'])) ?> Uhr</div>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <a href="?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=<?= $sp['id'] ?>"
                                    title="Bearbeiten" style="color: var(--primary);"><i class="fas fa-edit"></i></a>
                                <a href="javascript:void(0)"
                                    onclick="renameProtocol(<?= $sp['id'] ?>, '<?= addslashes($sp['mieter_name_custom']) ?>')"
                                    title="Umbenennen" style="color: #64748b;"><i class="fas fa-pen-to-square"></i></a>
                                <a href="javascript:void(0)" onclick="deleteProtocol(<?= $sp['id'] ?>)" title="Löschen"
                                    style="color: #ef4444;"><i class="fas fa-trash-can"></i></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <form id="abnahmeForm" enctype="multipart/form-data">
            <div class="unit-info">
                <div class="info-box">
                    <label>Vermieter / Verwalter</label>
                    <select name="user_id"
                        style="border:none; background:transparent; font-weight:600; font-size:15px; padding:0; width:100%;">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= ($_SESSION['user_id'] == $u['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="info-box">
                    <label>Mieter</label>
                    <select name="mieter_id" id="mieter_picker"
                        style="border:none; background:transparent; font-weight:600; font-size:15px; padding:0; width:100%;"
                        onchange="checkCustomMieter(this)">
                        <option value="0">-- Mieter wählen / Manuell --</option>
                        <optgroup label="Zugeordnete Mieter">
                        <?php foreach ($allTenants as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($mieter['id'] == $t['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['mieter_name']) ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                        
                        <optgroup label="Andere Benutzer">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="user_<?= $u['id'] ?>">
                                <?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                        
                        <option value="custom">Neuer Mieter (Handeingabe)...</option>
                    </select>
                    <input type="text" name="mieter_name_custom" id="mieter_custom"
                        style="display:none; margin-top:5px; border-bottom:1px solid var(--primary); border-top:none; border-left:none; border-right:none;"
                        placeholder="Name eingeben...">
                </div>
                <div class="info-box">
                    <label>Datum Abnahme</label>
                    <div><?= date('d.m.Y, H:i') ?> Uhr</div>
                </div>
            </div>

        <div class="section">
            <div class="section-header">
                <i class="fas fa-home"></i>
                <h2>3. ZUSTAND DER RÄUMLICHKEITEN</h2>
            </div>
            
            <div class="checklist-header" style="display:grid; grid-template-columns: 200px 120px 1fr; gap:15px; padding:12px; background:#f8fafc; font-weight:bold; font-size:12px; margin-bottom:15px; border: 1px solid #e2e8f0; border-radius:6px; color: #475569;">
                <div>Raum / Bauteil</div>
                <div style="text-align:center;">Zustand</div>
                <div>Details (Titel & Beschreibung)</div>
            </div>

                <?php foreach ($raeume as $r):
                    $rName = $r['name']; ?>
                    <div class="room-card">
                        <div class="room-header">
                            <h3><?= htmlspecialchars($rName) ?></h3>
                            <label class="room-clean-label" style="font-size: 12px; font-weight: 600; color: var(--text-muted);">
                                <input type="checkbox" name="clean[<?= $rName ?>]" checked> Gereinigt
                            </label>
                        </div>
                        <div class="room-body">
                            <?php
                            $items = ['Bodenbelag', 'Wände / Decke', 'Fenster / Simse', 'Türen / Schlösser'];
                            if (strpos(strtolower($rName), 'küche') !== false) {
                                $items = array_merge($items, ['Küchengeräte', 'Schränke', 'Abwaschbecken']);
                            }
                            if (strpos(strtolower($rName), 'bad') !== false || strpos(strtolower($rName), 'wc') !== false) {
                                $items = array_merge($items, ['Sanitäre Anlagen', 'Armaturen', 'Spiegel']);
                            }

                            foreach ($items as $item):
                                $key = $rName . '_' . $item;
                                ?>
                                <div class="item-row" id="row_<?= md5($key) ?>">
                                    <input type="hidden" name="room_label[<?= $key ?>]" value="<?= htmlspecialchars($rName) ?>">
                                    <input type="hidden" name="item_label[<?= $key ?>]" value="<?= htmlspecialchars($item) ?>">
                                    
                                    <div class="item-label">
                                        <?= $item ?>
                                        <button type="button" class="btn-add-extra"
                                            onclick="addExtraRow('<?= $rName ?>', '<?= $item ?>', document.getElementById('row_<?= md5($key) ?>'))"
                                            title="Weiteren Mangel hinzufügen"
                                            style="border:none; background:none; color:var(--primary); cursor:pointer; padding:0 5px; font-size:14px;"><i
                                                class="fas fa-plus-circle"></i></button>
                                    </div>

                                    <div class="item-status">
                                        <select class="sel-vorgangsart" name="status[<?= $key ?>][]" onchange="handleVorgangsartChange(this)" style="width:100%; padding: 5px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px;">
                                            <option value="0">OK</option>
                                            <?php foreach($vorgangsarten as $va): ?>
                                            <option value="<?= $va['id'] ?>"><?= htmlspecialchars($va['bezeichnung']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="item-content">
                                        <div style="display:flex; flex-direction: column; gap:8px;">
                                            <div style="display:flex; gap:10px;">
                                                <input type="text" name="title[<?= $key ?>][]" placeholder="Titel / Betreff (z.B. 'Kratzer im Parkett')" style="flex: 1; padding: 5px 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; background-color: #fdfdfd;">
                                            </div>
                                            <textarea name="comment[<?= $key ?>][]" placeholder="Detaillierte Bemerkungen / Mängel / Vereinbarungen..." style="width: 100%; min-height: 40px; padding: 5px 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; resize: vertical; background-color: #fdfdfd;"></textarea>
                                        </div>

                                        <div class="item-meta">
                                            <label style="font-size: 11px; display: flex; align-items: center; gap: 4px; cursor: pointer; color: var(--text-muted); font-weight: 600;">
                                                <input type="checkbox" name="as_pendenz[<?= $key ?>][]" value="1" onchange="this.closest('.item-row').classList.toggle('is-pendenz', this.checked)"> <strong>PENDENZ</strong>
                                            </label>
                                            <span class="pendenz-id-badge" style="font-size: 10px; font-weight: bold; color: #007a3d; display: none;"></span>
                                            <input type="hidden" name="pendenz_id[<?= $key ?>][]" value="0">
                                            <input type="hidden" name="raum_id[<?= $key ?>][]" value="<?= $r['id'] ?? 0 ?>">

                                            
                                            <select name="responsible[<?= $key ?>][]" style="width: 110px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px;">
                                                <option value="">-- Wer? --</option>
                                                <?php foreach($allUsers as $u): ?>
                                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            
                                            <select name="priority[<?= $key ?>][]" style="width: 55px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px;" title="Priorität">
                                                <option value="">Prio</option>
                                                <option value="1">1</option>
                                                <option value="2">2</option>
                                                <option value="3">3</option>
                                                <option value="4">4</option>
                                                <option value="5">5</option>
                                            </select>
                                            
                                            <input type="date" name="start_date[<?= $key ?>][]" class="date-start" onchange="recalcEndDate(this)" style="width: 105px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px;" title="Startdatum">
                                            <input type="number" name="duration[<?= $key ?>][]" class="date-duration" oninput="recalcEndDate(this)" placeholder="T." style="width: 45px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px;" title="Dauer in Tagen">
                                            <input type="date" name="end_date[<?= $key ?>][]" class="date-end" onchange="recalcEndDate(this, true)" style="width: 105px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 11px; background-color: #f8fafc;" title="Enddatum">

                                            <label style="cursor:pointer; color:var(--primary); font-size:16px; margin-left: auto; display: flex; align-items: center; gap: 10px;">
                                                <i class="fas fa-camera"></i>
                                                <input type="file" name="photos[<?= $key ?>][]" accept="image/*" style="position:absolute; left:-9999px; width:1px; height:1px; opacity:0;" onchange="previewImage(this)">
                                                <div class="img-preview-container" style="font-size: 8px;">
                                                    <input type="hidden" name="existing_photos[<?= $key ?>][]" value="">
                                                </div>
                                            </label>
                                        </div>

                                        <!-- Container for Category Grid -->
                                        <div class="category-grid-container"></div>
                                        
                                        <!-- Hidden Inputs for logic -->
                                        <select class="sel-kategorie" name="kategorie[<?= $key ?>][]" style="display:none;"></select>
                                        <select class="sel-subkategorie" name="subkategorie[<?= $key ?>][]" style="display:none;"></select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="section">
                <div class="section-header">
                    <i class="fas fa-tachometer-alt"></i>
                    <h2>Zählerstände</h2>
                </div>
                <div class="counter-grid">
                    <div class="counter-item">
                        <label>Strom (kWh)</label>
                        <input type="number" name="zaehler_strom" step="0.01" placeholder="0.00">
                    </div>
                    <div class="counter-item">
                        <label>Wasser (m³)</label>
                        <input type="number" name="zaehler_wasser" step="0.01" placeholder="0.00">
                    </div>
                    <div class="counter-item">
                        <label>Heizung</label>
                        <input type="number" name="zaehler_heizung" step="0.01" placeholder="0.00">
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <i class="fas fa-key"></i>
                    <h2>Schlüsselübergabe</h2>
                </div>
                <div class="counter-grid">
                    <div class="counter-item">
                        <label>Hausschlüssel</label>
                        <input type="number" name="keys_house" value="3">
                    </div>
                    <div class="counter-item">
                        <label>Briefkastenschlüssel</label>
                        <input type="number" name="keys_mail" value="2">
                    </div>
                    <div class="counter-item">
                        <label>Kellerschlüssel</label>
                        <input type="number" name="keys_cellar" value="1">
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-header">
                    <i class="fas fa-signature"></i>
                    <h2>Bestätigung & Unterschrift</h2>
                </div>
                <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 25px;">
                    Die Vertragsparteien bestätigen mit ihrer Unterschrift die Richtigkeit der oben gemachten Angaben.
                    Mängel, welche nicht im Protokoll aufgeführt sind, müssen innerhalb von 10 Tagen schriftlich
                    gemeldet werden.
                </p>

                <div class="signature-grid">
                    <div class="sig-box">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <label style="margin:0;">Vermieter / Verwaltung</label>
                            <button type="button"
                                style="background:none; border:none; color:#ef4444; font-size:11px; cursor:pointer;"
                                onclick="clearSignature('sig_vermieter')">Löschen</button>
                        </div>
                        <canvas id="sig_vermieter" class="sig-canvas"></canvas>
                        <input type="hidden" name="signature_vermieter" id="input_sig_vermieter">
                    </div>
                    <div class="sig-box">
                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <label style="margin:0;">Mieter / Abnehmer</label>
                            <button type="button"
                                style="background:none; border:none; color:#ef4444; font-size:11px; cursor:pointer;"
                                onclick="clearSignature('sig_mieter')">Löschen</button>
                        </div>
                        <canvas id="sig_mieter" class="sig-canvas"></canvas>
                        <input type="hidden" name="signature_mieter" id="input_sig_mieter">
                    </div>
                </div>
            </div>

            <div class="section" style="margin-top: 30px; background-color: #f8fafc; border: 1px dashed #cbd5e1;">
                <div class="section-header" style="background-color: #64748b;">
                    <i class="fas fa-tag"></i>
                    <h2>Protokoll-Verwaltung</h2>
                </div>
                <div style="padding: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #475569;">Bezeichnung für dieses Protokoll (z.B. 'Zwischenstand 1')</label>
                    <input type="text" name="protokoll_bezeichnung" id="protokoll_bezeichnung" placeholder="Optional: Name vergeben..." style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;">
                </div>
            </div>

            <div style="margin-top: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <button type="button" class="btn-save" onclick="generatePDF(false, false)" style="grid-column: span 2; background-color: #007a3d; padding: 20px; font-size: 16px;">
                    <i class="fas fa-check-double" style="margin-right: 10px;"></i>
                    PROTOKOLL ABSCHLIESSEN & PDF GENERIEREN
                </button>
                
                <button type="button" class="btn-save" onclick="syncPendenzen()" style="background-color: #3b82f6; padding: 15px; grid-column: span 2;">
                    <i class="fas fa-sync" style="margin-right: 10px;"></i>
                    PENDENZEN IN LISTE ÜBERTRAGEN / AKTUALISIEREN
                </button>
                
                <button type="button" class="btn-save" onclick="generatePDF(true, false)" style="background-color: #64748b; padding: 15px;">
                    <i class="fas fa-save" style="margin-right: 10px;"></i>
                    SPEICHERN (Überschreiben)
                </button>
                
                <button type="button" class="btn-save" onclick="generatePDF(true, true)" style="background-color: #94a3b8; padding: 15px;">
                    <i class="fas fa-copy" style="margin-right: 10px;"></i>
                    SPEICHERN NEU ALS...
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
    <script>
        const pads = {};
        const loadedData = <?= $loadedData ? json_encode($loadedData) : 'null' ?>;
        
        // BKP Data for JS
        const bkpCodes = <?= json_encode($bkpCodes) ?>;
        const bkpKats = <?= json_encode($bkpKats) ?>;
        const bkpTexts = <?= json_encode($bkpTexts) ?>;
        const mieterKats = <?= json_encode($mieterKats) ?>;
        const mieterSubs = <?= json_encode($mieterSubs) ?>;
        const vermieterKats = <?= json_encode($vermieterKats) ?>;
        const vermieterSubs = <?= json_encode($vermieterSubs) ?>;

        function initPad(id) {
            const canvas = document.getElementById(id);
            if (!canvas) return;
            try {
                if (typeof SignaturePad !== 'undefined') {
                    const pad = new SignaturePad(canvas, { backgroundColor: 'rgba(255, 255, 255, 0)' });
                    pads[id] = pad;
                    function resize() {
                        const ratio = Math.max(window.devicePixelRatio || 1, 1);
                        canvas.width = canvas.offsetWidth * ratio;
                        canvas.height = canvas.offsetHeight * ratio;
                        canvas.getContext("2d").scale(ratio, ratio);
                        pad.clear();
                        if (loadedData && loadedData['signature_' + id.split('_')[1]]) {
                            pad.fromDataURL(loadedData['signature_' + id.split('_')[1]]);
                        }
                    }
                    window.addEventListener("resize", resize);
                    resize();
                }
            } catch (e) { console.error("SignaturePad Error:", e); }
        }

        function handleVorgangsartChange(select) {
            const row = select.closest('.item-row');
            const gridContainer = row.querySelector('.category-grid-container');
            const val = select.value;

            if (val == "0") {
                gridContainer.style.display = 'none';
                return;
            }

            gridContainer.style.display = 'block';
            gridContainer.innerHTML = '<div class="category-grid"></div>';
            const grid = gridContainer.querySelector('.category-grid');

            let items = [];
            if (val == "1") items = mieterKats;
            else if (val == "2") items = vermieterKats;
            else items = bkpCodes;

            items.forEach(it => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cat-btn';
                btn.innerHTML = (it.code ? it.code + ' ' : '') + it.name;
                btn.onclick = () => {
                    if (val == "1") renderSubGrid(gridContainer, it, mieterSubs[it.id] || [], 1);
                    else if (val == "2") renderSubGrid(gridContainer, it, vermieterSubs[it.id] || [], 2);
                    else renderSubGrid(gridContainer, it, bkpKats[it.id] || [], 3);
                };
                grid.appendChild(btn);
            });
        }

        function renderSubGrid(container, parent, subs, type) {
            container.innerHTML = `<div style="margin-bottom:10px; font-weight:700; font-size:12px; color:var(--primary); display:flex; align-items:center; gap:10px;">
                <button type="button" onclick="this.closest('.item-row').querySelector('.sel-vorgangsart').dispatchEvent(new Event('change'))" style="border:none; background:none; cursor:pointer; color:var(--text-muted);"><i class="fas fa-arrow-left"></i></button>
                ${parent.name}
            </div>
            <div class="category-grid"></div>`;
            const grid = container.querySelector('.category-grid');

            subs.forEach(s => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cat-btn';
                btn.innerText = s.name || s.bezeichnung || "Kategorie";
                btn.onclick = () => {
                    const row = container.closest('.item-row');
                    row.querySelector('.sel-kategorie').innerHTML = `<option value="${parent.id}" selected>${parent.name}</option>`;
                    row.querySelector('.sel-subkategorie').innerHTML = `<option value="${s.id}" selected>${s.name}</option>`;
                    
                    if (type === 3) renderBkpTextGrid(container, s);
                    else {
                        row.querySelector('[name^="title"]').value = parent.name;
                        row.querySelector('[name^="comment"]').value = (s.name + (s.beschreibung ? ": " + s.beschreibung : ""));
                        container.style.display = 'none';
                    }
                };
                grid.appendChild(btn);
            });
        }

        function renderBkpTextGrid(container, sub) {
            const texts = bkpTexts[sub.id] || [];
            container.innerHTML = `<div style="margin-bottom:10px; font-weight:700; font-size:12px; color:var(--primary); display:flex; align-items:center; gap:10px;">
                <button type="button" onclick="this.closest('.item-row').querySelector('.sel-vorgangsart').dispatchEvent(new Event('change'))" style="border:none; background:none; cursor:pointer; color:var(--text-muted);"><i class="fas fa-arrow-left"></i></button>
                ${sub.name} - Text wählen
            </div>
            <div class="category-grid"></div>`;
            const grid = container.querySelector('.category-grid');

            if (texts.length === 0) {
                const row = container.closest('.item-row');
                row.querySelector('input[name^="comment"]').value = sub.name;
                container.style.display = 'none';
                return;
            }

            texts.forEach(t => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'cat-btn';
                btn.innerText = t.name || t.text || "Text";
                btn.onclick = () => {
                    const row = container.closest('.item-row');
                    row.querySelector('[name^="title"]').value = sub.name;
                    row.querySelector('[name^="comment"]').value = t.text || t.name;
                    container.style.display = 'none';
                };
                grid.appendChild(btn);
            });
        }

        function addExtraRow(room, item, parentRow) {
            const newRow = parentRow.cloneNode(true);
            newRow.id = '';
            newRow.querySelectorAll('input, select, textarea').forEach(el => {
                if (el.name && room && item) {
                    const baseName = el.name.split('[')[0];
                    el.name = `${baseName}[${room}_${item}][]`;
                }
                if (el.tagName === 'INPUT') {
                    if (el.type !== 'checkbox' && el.type !== 'hidden') el.value = '';
                    else if (el.type === 'checkbox') el.checked = false;
                } else if (el.tagName === 'SELECT') el.selectedIndex = 0;
            });
            newRow.querySelector('.category-grid-container').innerHTML = '';
            newRow.querySelector('.category-grid-container').style.display = 'none';
            newRow.querySelector('.img-preview-container').innerHTML = '';
            const btn = newRow.querySelector('.btn-add-extra');
            btn.innerHTML = '<i class="fas fa-minus-circle"></i>';
            btn.style.color = 'var(--danger)';
            btn.onclick = () => newRow.remove();
            parentRow.parentNode.insertBefore(newRow, parentRow.nextSibling);
            return newRow;
        }

        window.addEventListener('load', () => {
            initPad('sig_vermieter');
            initPad('sig_mieter');

            if (loadedData) {
                restoreFormData(loadedData);
            }
        });

        function recalcEndDate(el, isEnd = false) {
            const row = el.closest('.item-meta');
            const s = row.querySelector('.date-start');
            const d = row.querySelector('.date-duration');
            const e = row.querySelector('.date-end');
            
            if (!s || !d || !e) return;
            
            const fmtDate = (date) => date.toISOString().split('T')[0];
            const parseDate = (str) => str ? new Date(str) : null;
            
            if (el.classList.contains('date-duration') && d.value && !s.value) {
                s.value = fmtDate(new Date());
            }

            let sDate = parseDate(s.value);
            let eDate = parseDate(e.value);
            let dur = parseInt(d.value) || 0;

            if (isEnd && sDate && eDate) {
                let diff = Math.max(1, Math.ceil((eDate - sDate) / 86400000));
                d.value = diff;
            } else if (sDate && dur) {
                let newEnd = new Date(sDate);
                newEnd.setDate(newEnd.getDate() + dur);
                e.value = fmtDate(newEnd);
            }
        }

        async function previewImage(input) {
            if (!input.files || !input.files[0]) return;

            const file = input.files[0];
            const container = input.closest('.item-meta').querySelector('.img-preview-container');
            const key = input.name.match(/\[(.*?)\]/)[1];

            // Sofort lokale Vorschau zeigen
            const reader = new FileReader();
            reader.onload = (e) => {
                container.innerHTML = `<div style="position:relative; display:inline-block; opacity:0.6;" title="Wird hochgeladen...">
                    <img src="${e.target.result}" style="width:50px; height:50px; object-fit:cover; border-radius:4px; margin-top:5px;">
                    <div style="position:absolute; top:0; left:0; width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:18px;">⏳</div>
                    <input type="hidden" name="existing_photos[${key}][]" value="">
                </div>`;
            };
            reader.readAsDataURL(file);

            // Bild sofort auf Server hochladen
            try {
                const fd = new FormData();
                fd.append('photo', file);
                const urlParams = new URLSearchParams(window.location.search);
                const pid = urlParams.get('projekt_id') || '';
                const uid = urlParams.get('unit_id') || '';
                const resp = await fetch(`abnahme_save.php?projekt_id=${pid}&unit_id=${uid}&action=upload_photo`, {
                    method: 'POST',
                    body: fd
                });
                const res = await resp.json();

                if (res.success && res.filename) {
                    // Upload erfolgreich: Dateiname im hidden field speichern
                    container.innerHTML = `<div style="position:relative; display:inline-block; cursor:pointer;" title="Klicken um Bild zu ersetzen">
                        <img src="../uploads/protocols/${res.filename}" style="width:50px; height:50px; object-fit:cover; border-radius:4px; margin-top:5px;">
                        <input type="hidden" name="existing_photos[${key}][]" value="${res.filename}">
                    </div>`;
                } else {
                    container.innerHTML = `<span style="color:red; font-size:10px;">Fehler: ${res.error || 'Unbekannt'}</span><input type="hidden" name="existing_photos[${key}][]" value="">`;
                }
            } catch(err) {
                container.innerHTML = `<span style="color:red; font-size:10px;">Upload-Fehler</span><input type="hidden" name="existing_photos[${key}][]" value="">`;
            }
        }

        function restoreFormData(data) {
            console.log("Restoring data...", data);
            const form = document.getElementById('abnahmeForm');
            
            // Basic fields
            if (data.protokoll_bezeichnung) form.querySelector('#protokoll_bezeichnung').value = data.protokoll_bezeichnung;
            if (data.mieter_id) {
                const picker = form.querySelector('select[name="mieter_id"]');
                picker.value = data.mieter_id;
                checkCustomMieter(picker);
            }
            if (data.mieter_name_custom) form.querySelector('input[name="mieter_name_custom"]').value = data.mieter_name_custom;
            if (data.zaehler_strom) form.querySelector('input[name="zaehler_strom"]').value = data.zaehler_strom;
            if (data.zaehler_wasser) form.querySelector('input[name="zaehler_wasser"]').value = data.zaehler_wasser;
            if (data.zaehler_heizung) form.querySelector('input[name="zaehler_heizung"]').value = data.zaehler_heizung;
            if (data.keys_house) form.querySelector('input[name="keys_house"]').value = data.keys_house;
            if (data.keys_mail) form.querySelector('input[name="keys_mail"]').value = data.keys_mail;
            if (data.keys_cellar) form.querySelector('input[name="keys_cellar"]').value = data.keys_cellar;

            // Items & Rows
            for (const key in data.status) {
                const statuses = data.status[key];
                statuses.forEach((statusVal, idx) => {
                    const inputs = document.querySelectorAll(`[name="status[${key}][]"]`);
                    let targetInput = inputs[idx];
                    
                    if (!targetInput && idx > 0) {
                        const parentInput = inputs[0];
                        if (parentInput) {
                            const parentRow = parentInput.closest('.item-row');
                            const room = parentRow.querySelector(`[name="room_label[${key}]"]`).value;
                            const item = parentRow.querySelector(`[name="item_label[${key}]"]`).value;
                            const newRow = addExtraRow(room, item, parentRow);
                            targetInput = newRow.querySelector('.sel-vorgangsart');
                        }
                    }

                    if (targetInput) {
                        const row = targetInput.closest('.item-row');
                        targetInput.value = statusVal;
                        
                        if (data.title && data.title[key] && data.title[key][idx]) {
                            const titleEl = row.querySelector(`[name="title[${key}][]"]`);
                            if (titleEl) titleEl.value = data.title[key][idx];
                        }
                        if (data.comment && data.comment[key] && data.comment[key][idx]) {
                            const commentEl = row.querySelector(`[name="comment[${key}][]"]`);
                            if (commentEl) commentEl.value = data.comment[key][idx];
                        }
                        if (data.as_pendenz && data.as_pendenz[key] && data.as_pendenz[key][idx]) {
                            const pendEl = row.querySelector(`[name="as_pendenz[${key}][]"]`);
                            if (pendEl) {
                                pendEl.checked = true;
                                row.classList.add('is-pendenz');
                            }
                        }
                        if (data.pendenz_id && data.pendenz_id[key] && data.pendenz_id[key][idx]) {
                            const pidVal = data.pendenz_id[key][idx];
                            if (pidVal > 0) {
                                const pidInput = row.querySelector(`[name="pendenz_id[${key}][]"]`);
                                if (pidInput) pidInput.value = pidVal;
                                const pidBadge = row.querySelector('.pendenz-id-badge');
                                if (pidBadge) {
                                    pidBadge.textContent = 'ID: #' + pidVal;
                                    pidBadge.style.display = 'inline-block';
                                }
                            }
                        }
                        if (data.responsible && data.responsible[key] && data.responsible[key][idx]) {
                            const respEl = row.querySelector(`[name="responsible[${key}][]"]`);
                            if (respEl) respEl.value = data.responsible[key][idx];
                        }
                        if (data.start_date && data.start_date[key] && data.start_date[key][idx]) {
                            const startEl = row.querySelector(`[name="start_date[${key}][]"]`);
                            if (startEl) startEl.value = data.start_date[key][idx];
                        }
                        if (data.duration && data.duration[key] && data.duration[key][idx]) {
                            const durEl = row.querySelector(`[name="duration[${key}][]"]`);
                            if (durEl) durEl.value = data.duration[key][idx];
                        }
                        
                        if (data.kategorie && data.kategorie[key] && data.kategorie[key][idx]) row.querySelector('.sel-kategorie').innerHTML = `<option value="${data.kategorie[key][idx]}" selected>Geladen</option>`;
                        if (data.subkategorie && data.subkategorie[key] && data.subkategorie[key][idx]) row.querySelector('.sel-subkategorie').innerHTML = `<option value="${data.subkategorie[key][idx]}" selected>Geladen</option>`;

                        if (data.priority && data.priority[key] && data.priority[key][idx]) {
                            const prioEl = row.querySelector(`[name="priority[${key}][]"]`);
                            if (prioEl) prioEl.value = data.priority[key][idx];
                        }
                        if (data.end_date && data.end_date[key] && data.end_date[key][idx]) {
                            const endEl = row.querySelector(`[name="end_date[${key}][]"]`);
                            if (endEl) endEl.value = data.end_date[key][idx];
                        }

                        if (data.saved_photo_names && data.saved_photo_names[key] && data.saved_photo_names[key][idx]) {
                            const photoName = data.saved_photo_names[key][idx];
                            if (photoName) {
                                row.querySelector('.img-preview-container').innerHTML = `<div style="position:relative; display:inline-block;"><img src="../uploads/protocols/${photoName}" style="width:50px; height:50px; object-fit:cover; border-radius:4px; margin-top:5px;" title="Klicken um Bild zu ersetzen"><input type="hidden" name="existing_photos[${key}][]" value="${photoName}"></div>`;
                            } else {
                                row.querySelector('.img-preview-container').innerHTML = `<input type="hidden" name="existing_photos[${key}][]" value="">`;
                            }
                        } else {
                            row.querySelector('.img-preview-container').innerHTML = `<input type="hidden" name="existing_photos[${key}][]" value="">`;
                        }
                    }
                });
            }
        }

        async function syncPendenzen() {
            const btn = event.currentTarget;
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PENDENZEN WERDEN SYNCHRONISIERT...';

            try {
                const formData = new FormData(document.getElementById('abnahmeForm'));
                
                const urlParams = new URLSearchParams(window.location.search);
                let protocolId = urlParams.get('protocol_id') || '';
                
                const fetchUrl = `abnahme_save.php?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=${protocolId}&action=sync_pendenzen`;

                const resp = await fetch(fetchUrl, {
                    method: 'POST',
                    body: formData
                });
                const res = await resp.json();

                if (res.success) {
                    alert('Pendenzen erfolgreich synchronisiert!');
                    if (res.protocol_id && protocolId !== res.protocol_id.toString()) {
                        const newUrl = window.location.pathname + `?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=${res.protocol_id}`;
                        window.history.replaceState({ path: newUrl }, '', newUrl);
                    }
                    location.reload();
                } else {
                    alert('Fehler: ' + res.error);
                }
            } catch (err) {
                alert('Kritischer Fehler: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }

        async function generatePDF(isDraft = false, isNew = false) {
            const btn = event.currentTarget;
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            
            if (isDraft) {
                btn.innerHTML = isNew ? '<i class="fas fa-spinner fa-spin"></i> KOPIE ERSTELLEN...' : '<i class="fas fa-spinner fa-spin"></i> SPEICHERN...';
            } else {
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROTOKOLL WIRD GENERIERT...';
            }

            try {
                if (!isDraft && pads['sig_mieter'].isEmpty() && !loadedData) {
                    alert('Bitte lassen Sie den Mieter unterschreiben.');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    return;
                }

                const formData = new FormData(document.getElementById('abnahmeForm'));
                formData.append('signature_vermieter', pads.sig_vermieter.toDataURL());
                formData.append('signature_mieter', pads.sig_mieter.toDataURL());
                if (isDraft) formData.append('is_draft', '1');
                if (isNew) formData.append('is_new_as', '1');

                const urlParams = new URLSearchParams(window.location.search);
                let protocolId = urlParams.get('protocol_id') || '';
                if (isNew) protocolId = ''; // Force new record
                
                const fetchUrl = `abnahme_save.php?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=${protocolId}`;

                const resp = await fetch(fetchUrl, {
                    method: 'POST',
                    body: formData
                });
                const res = await resp.json();

                if (res.success) {
                    if (isDraft) {
                        alert('Entwurf erfolgreich gespeichert!');
                        const newUrl = window.location.pathname + `?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=${res.protocol_id}`;
                        window.history.replaceState({ path: newUrl }, '', newUrl);
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    } else {
                        window.location.href = res.pdf_url;
                    }
                } else {
                    alert('Fehler: ' + res.error);
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            } catch (err) {
                alert('Kritischer Fehler: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }
        function checkCustomMieter(select) {
            const customInput = document.getElementById('mieter_custom');
            if (select.value === 'custom') {
                customInput.style.display = 'block';
                customInput.focus();
            } else if (select.value && select.value.toString().startsWith('user_')) {
                customInput.style.display = 'none';
                customInput.value = select.options[select.selectedIndex].text;
            } else {
                customInput.style.display = 'none';
                if (select.value !== '0') {
                   // Optional: clear if not needed
                }
            }
        }

        function clearSignature(id) {
            if (pads[id]) {
                pads[id].clear();
            }
        }

        async function deleteProtocol(id) {
            if (!confirm('Möchten Sie dieses Protokoll wirklich unwiderruflich löschen?')) return;
            try {
                const resp = await fetch(`abnahme_save.php?action=delete&id=${id}`);
                const res = await resp.json();
                if (res.success) {
                    location.reload();
                } else {
                    alert('Fehler beim Löschen: ' + res.error);
                }
            } catch (err) {
                alert('Kritischer Fehler beim Löschen: ' + err.message);
            }
        }

        async function renameProtocol(id, oldName) {
            const newName = prompt('Neuen Namen für das Protokoll eingeben:', oldName);
            if (newName === null || newName === oldName) return;
            try {
                const resp = await fetch(`abnahme_save.php?action=rename&id=${id}&name=${encodeURIComponent(newName)}`);
                const res = await resp.json();
                if (res.success) {
                    location.reload();
                } else {
                    alert('Fehler beim Umbenennen: ' + res.error);
                }
            } catch (err) {
                alert('Kritischer Fehler beim Umbenennen: ' + err.message);
            }
        }
    </script>

    </div><!-- .abnahme-wrapper -->
</main>
<?php include '../includes/footer.php'; ?>