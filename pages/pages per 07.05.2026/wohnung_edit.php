<?php
/**
 * pages/wohnung_edit.php
 * Vollansicht zur Bearbeitung einer Wohneinheit.
 * Inklusive zentraler Raum-Verwaltung.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
require_role(['admin', 'superadmin']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("❌ Ungültige ID.");

// Wohnung laden (mit JOIN für Projekt-Zugehörigkeit)
$res = $mysqli->query("
    SELECT w.*, o.projekt_id 
    FROM wohnungen w 
    LEFT JOIN objekte o ON o.id = w.objekt_id 
    WHERE w.id = $id
");
$wohnung = $res->fetch_assoc();
if (!$wohnung) die("❌ Wohnung nicht gefunden.");

// Geschwister-Wohnungen laden für Navigation
$objekt_id = (int)$wohnung['objekt_id'];
$siblings = [];
$prev_id = null;
$next_id = null;

if ($objekt_id > 0) {
    // Projekt-ID finden
    $pRow = $mysqli->query("SELECT projekt_id FROM objekte WHERE id = $objekt_id")->fetch_assoc();
    $pid = (int)($pRow['projekt_id'] ?? 0);
    
    if ($pid > 0) {
        $sib_res = $mysqli->query("
            SELECT w.id, w.name 
            FROM wohnungen w 
            JOIN objekte o ON o.id = w.objekt_id 
            WHERE o.projekt_id = $pid 
            ORDER BY w.name ASC
        ");
        while($s = $sib_res->fetch_assoc()) {
            $siblings[] = $s;
        }
        
        // Prev/Next finden
        for($i=0; $i < count($siblings); $i++) {
            if ($siblings[$i]['id'] == $id) {
                if ($i > 0) $prev_id = $siblings[$i-1]['id'];
                if ($i < count($siblings)-1) $next_id = $siblings[$i+1]['id'];
                break;
            }
        }
    }
}

// --- SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_all') {
    try {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception("Bezeichnung ist erforderlich.");

        // SQL Update vorbereiten
        $updates = [
            "name = ?", "etage = ?", "flaeche = ?", "zimmer = ?", "badezimmer = ?",
            "letzter_renovation = ?", "gesamtzustand = ?", "balkon = ?", "wintergarten = ?", "terrasse = ?",
            "baujahr = ?", "heizungsart = ?", "bodenbelag = ?", "kueche_details = ?", "bad_details = ?",
            "lift = ?", "barrierefrei = ?", "haustiere_erlaubt = ?", "waschmaschine = ?", "keller_vorhanden = ?",
            "parkplatz = ?", "minergie = ?", "glasfaser = ?", "besonnerung = ?", "aussicht = ?", "laermpegel = ?",
            "mietzins_netto_soll = ?", "mietzins_nk_soll = ?", "ausstattung_details = ?", "available_from = ?"
        ];
        
        $renov = ($_POST['letzter_renovation'] ?? '') ?: null;
        $params = [
            $name, 
            $_POST['etage'] ?? '', 
            (float)($_POST['flaeche'] ?? 0), 
            (float)($_POST['zimmer'] ?? 0), 
            (float)($_POST['badezimmer'] ?? 0),
            $renov, 
            $_POST['gesamtzustand'] ?? '', 
            isset($_POST['balkon'])?1:0, 
            isset($_POST['wintergarten'])?1:0, 
            isset($_POST['terrasse'])?1:0,
            (int)($_POST['baujahr'] ?? 0), 
            $_POST['heizungsart'] ?? '', 
            $_POST['bodenbelag'] ?? '', 
            $_POST['kueche_details'] ?? '', 
            $_POST['bad_details'] ?? '',
            isset($_POST['lift'])?1:0, 
            isset($_POST['barrierefrei'])?1:0, 
            isset($_POST['haustiere_erlaubt'])?1:0, 
            $_POST['waschmaschine'] ?? '', 
            isset($_POST['keller_vorhanden'])?1:0,
            $_POST['parkplatz'] ?? '', 
            isset($_POST['minergie'])?1:0, 
            isset($_POST['glasfaser'])?1:0, 
            $_POST['besonnerung'] ?? '', 
            $_POST['aussicht'] ?? '', 
            $_POST['laermpegel'] ?? '',
            (float)($_POST['mietzins_netto_soll'] ?? 0), 
            (float)($_POST['mietzins_nk_soll'] ?? 0), 
            $_POST['ausstattung_details'] ?? '', 
            $_POST['available_from'] ?? ''
        ];

        $sql = "UPDATE wohnungen SET " . implode(", ", $updates) . " WHERE id = $id";
        $stmt = $mysqli->prepare($sql);
        $types = "ssdddssiiiissssiiisisiisssddss"; 
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        header("Location: wohnung_edit.php?id=$id&success=1" . (isset($_POST['_hash']) ? "#".$_POST['_hash'] : ""));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = "Wohnung bearbeiten: " . h($wohnung['name']);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
:root {
    --primary: #3b82f6;
    --primary-dark: #2563eb;
    --slate-50: #f8fafc;
    --slate-100: #f1f5f9;
    --slate-200: #e2e8f0;
    --slate-300: #cbd5e1;
    --slate-400: #94a3b8;
    --slate-500: #64748b;
    --slate-600: #475569;
    --slate-700: #334155;
    --slate-800: #1e293b;
    --slate-900: #0f172a;
    --glass: rgba(255, 255, 255, 0.85);
    --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
}

body {
    background-color: #f1f5f9;
    background-image: radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.05) 0px, transparent 50%), radial-gradient(at 100% 0%, rgba(59, 130, 246, 0.05) 0px, transparent 50%);
    min-height: 100vh;
}

.page-header {
    margin-bottom: 30px;
    padding: 20px 0;
}

.premium-title {
    font-size: 32px;
    font-weight: 900;
    color: var(--slate-900);
    letter-spacing: -1px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn-back {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    background: white;
    border: 1.5px solid var(--slate-200);
    border-radius: 12px;
    color: var(--slate-600);
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
    margin-bottom: 15px;
    transition: 0.2s;
}

.btn-back:hover {
    border-color: var(--primary);
    color: var(--primary);
    transform: translateX(-3px);
}

.glass-card {
    background: var(--glass);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 30px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.main-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    min-height: 700px;
}

.sidebar-nav {
    background: rgba(241, 245, 249, 0.5);
    border-right: 1px solid var(--slate-200);
    padding: 30px 15px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-item {
    padding: 14px 20px;
    border-radius: 14px;
    color: var(--slate-600);
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: 0.2s;
    display: flex;
    align-items: center;
    gap: 12px;
}

.nav-item:hover {
    background: rgba(59, 130, 246, 0.08);
    color: var(--primary);
}

.nav-item.active {
    background: var(--primary);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
}

.content-panel {
    padding: 40px;
    position: relative;
}

.form-section {
    display: none;
    animation: fadeIn 0.3s ease-out;
}

.form-section.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--slate-800);
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.input-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.input-group label {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.form-control {
    width: 100%;
    padding: 14px 18px;
    background: white;
    border: 2px solid var(--slate-200);
    border-radius: 14px;
    font-size: 15px;
    font-weight: 600;
    color: var(--slate-800);
    transition: 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.textarea-custom {
    min-height: 120px;
    resize: vertical;
}

.checkbox-tile-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}

.checkbox-tile {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: white;
    border: 2px solid var(--slate-200);
    border-radius: 12px;
    cursor: pointer;
    transition: 0.2s;
    font-weight: 700;
    font-size: 13px;
    color: var(--slate-600);
}

.checkbox-tile:hover {
    border-color: var(--primary);
}

.checkbox-tile input {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

.checkbox-tile:has(input:checked) {
    background: rgba(59, 130, 246, 0.05);
    border-color: var(--primary);
    color: var(--primary);
}

.badge {
    background: var(--slate-200);
    color: var(--slate-700);
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
}

.btn-blue {
    background: var(--primary);
    color: white;
    border: none;
    transition: 0.2s;
}

.btn-blue:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(59, 130, 246, 0.3);
}

.btn-text-danger {
    background: none;
    border: none;
    color: #ef4444;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
}

    /* Navigation Top Bar */
    .unit-nav-bar {
        background: white;
        padding: 15px 30px;
        border-bottom: 2px solid var(--slate-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    .nav-group { display: flex; align-items: center; gap: 15px; }
    .nav-btn {
        padding: 10px 20px;
        background: var(--slate-50);
        border: 1.5px solid var(--slate-200);
        border-radius: 12px;
        color: var(--slate-600);
        font-weight: 700;
        text-decoration: none;
        font-size: 13px;
        transition: 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .nav-btn:hover { background: white; border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
    .nav-btn.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }
    
    .unit-selector {
        padding: 10px 40px 10px 20px;
        border-radius: 12px;
        border: 2px solid var(--primary);
        font-weight: 800;
        color: var(--primary);
        background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%233b82f6' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C2.185 5.355 2.401 5 2.736 5h9.528c.335 0 .551.355.285.658l-4.796 5.482a.5.5 0 0 1-.726 0z'/%3E%3C/svg%3E") no-repeat right 15px center;
        appearance: none;
        cursor: pointer;
        font-size: 15px;
        min-width: 220px;
        text-align: center;
    }
</style>

<!-- Navigation Bar Top -->
<div class="unit-nav-bar">
    <div class="nav-group">
        <a href="mieterspiegel.php?projekt_id=<?= (int)($wohnung['projekt_id'] ?? 0) ?>" class="nav-btn" style="background: var(--slate-600); color: white; border: none;">
            ⬅ Zurück zum Mieterspiegel
        </a>
    </div>

    <div class="nav-group">
        <a href="<?= $prev_id ? "?id=$prev_id" : "#" ?>" class="nav-btn <?= !$prev_id ? 'disabled' : '' ?>">
            ⬅ Vorherige
        </a>

        <div style="position: relative;">
            <select class="unit-selector" onchange="window.location.href='?id=' + this.value">
                <?php foreach($siblings as $sib): ?>
                    <option value="<?= $sib['id'] ?>" <?= $sib['id'] == $id ? 'selected' : '' ?>>
                        🏠 <?= h($sib['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <a href="<?= $next_id ? "?id=$next_id" : "#" ?>" class="nav-btn <?= !$next_id ? 'disabled' : '' ?>">
            Nächste ➡
        </a>
    </div>

    <div class="nav-group">
        <button type="button" onclick="document.getElementById('masterForm').submit()" class="btn btn-blue" style="margin:0; border-radius:12px; height:45px; padding: 0 25px; font-weight: 800;">
            💾 Speichern
        </button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">✅ Änderungen erfolgreich gespeichert.</div>
<?php endif; ?>

<div class="edit-container glass-card">
    <div class="main-grid">
        <aside class="sidebar-nav">
            <div class="nav-item active" onclick="showSection('basis', this)">💎 Basisdaten</div>
            <div class="nav-item" onclick="showSection('ausstattung', this)">🛋️ Ausstattung & Zustand</div>
            <div class="nav-item" onclick="showSection('finanzen', this)">💰 Finanzen & Miete</div>
            <div class="nav-item" onclick="showSection('technik', this)">⚙️ Technik & Gebäude</div>
            <div class="nav-item" onclick="showSection('umgebung', this)">🌳 Umgebung & Lage</div>
            <div class="nav-item" id="nav-item-rooms" onclick="showSection('rooms', this)" style="display:flex; justify-content:space-between; align-items:center;">
                <span>🚪 Räume</span>
                <span id="sidebar-room-summary" style="font-size:12px; opacity:0.7; font-weight:400; letter-spacing:1px;"></span>
            </div>
            <div class="nav-item" onclick="showSection('medien', this)">📸 Medien & Marketing</div>
        </aside>

        <form method="POST" id="masterForm">
            <input type="hidden" name="action" value="save_all">
            <input type="hidden" name="_hash" id="form_hash_field" value="basis">
            
            <div class="content-panel">
                
                <!-- BASISDATEN -->
                <div id="section-basis" class="form-section active">
                    <h3 class="section-title">💎 Basis-Informationen</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Bezeichnung*</label>
                            <input type="text" name="name" class="form-control" value="<?= h($wohnung['name']) ?>" required>
                        </div>
                        <div class="input-group">
                            <label>Etage / Stockwerk</label>
                            <input type="text" name="etage" class="form-control" value="<?= h($wohnung['etage']) ?>" placeholder="z.B. Attika, 2. OG rechts">
                        </div>
                        <div class="input-group">
                            <label>Anzahl Zimmer</label>
                            <input type="number" step="0.5" name="zimmer" class="form-control" value="<?= (float)$wohnung['zimmer'] ?>">
                        </div>
                        <div class="input-group">
                            <label>Fläche (m²)</label>
                            <input type="number" step="0.1" name="flaeche" class="form-control" value="<?= (float)$wohnung['flaeche'] ?>">
                        </div>
                        <div class="input-group">
                            <label>Anzahl Badezimmer</label>
                            <input type="number" step="0.5" name="badezimmer" class="form-control" value="<?= (float)$wohnung['badezimmer'] ?>">
                        </div>
                        <div class="input-group">
                            <label>Baujahr</label>
                            <input type="number" name="baujahr" class="form-control" value="<?= (int)$wohnung['baujahr'] ?>" placeholder="z.B. 2024">
                        </div>
                    </div>
                </div>

                <!-- AUSSTATTUNG -->
                <div id="section-ausstattung" class="form-section">
                    <h3 class="section-title">🛋️ Ausstattung & Zustand</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Gesamtzustand</label>
                            <select name="gesamtzustand" class="form-control">
                                <option value="neu" <?= $wohnung['gesamtzustand'] == 'neu' ? 'selected':'' ?>>✨ Neu / Erstbezug</option>
                                <option value="renoviert" <?= $wohnung['gesamtzustand'] == 'renoviert' ? 'selected':'' ?>>🔨 Frisch renoviert</option>
                                <option value="gut" <?= $wohnung['gesamtzustand'] == 'gut' ? 'selected':'' ?>>👍 Gut / Unterhalten</option>
                                <option value="getragen" <?= $wohnung['gesamtzustand'] == 'getragen' ? 'selected':'' ?>>🧥 Getragen / Gebraucht</option>
                                <option value="sanierungsbedürftig" <?= $wohnung['gesamtzustand'] == 'sanierungsbedürftig' ? 'selected':'' ?>>🚨 Sanierungsbedürftig</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Letzte Renovation (Datum)</label>
                            <input type="date" name="letzter_renovation" class="form-control" value="<?= h($wohnung['letzter_renovation']) ?>">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Merkmale & Aussenbereiche</label>
                        <div class="checkbox-tile-group">
                            <label class="checkbox-tile">
                                <input type="checkbox" name="balkon" <?= $wohnung['balkon'] ? 'checked':'' ?>>
                                <span>🏙️ Balkon</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="terrasse" <?= $wohnung['terrasse'] ? 'checked':'' ?>>
                                <span>🌴 Terrasse</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="wintergarten" <?= $wohnung['wintergarten'] ? 'checked':'' ?>>
                                <span>❄️ Wintergarten</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="keller_vorhanden" <?= $wohnung['keller_vorhanden'] ? 'checked':'' ?>>
                                <span>📦 Kellerabteil</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="lift" <?= $wohnung['lift'] ? 'checked':'' ?>>
                                <span>🛗 Liftzugang</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="barrierefrei" <?= $wohnung['barrierefrei'] ? 'checked':'' ?>>
                                <span>♿ Barrierefrei</span>
                            </label>
                        </div>
                    </div>

                    <div class="input-group" style="margin-top:20px;">
                        <label>Detaillierte Ausstattungs-Beschreibung</label>
                        <textarea name="ausstattung_details" class="form-control textarea-custom" placeholder="Details zu Bodenbelägen, Küche, Bad etc..."><?= h($wohnung['ausstattung_details']) ?></textarea>
                    </div>
                </div>

                <!-- FINANZEN -->
                <div id="section-finanzen" class="form-section">
                    <h3 class="section-title">💰 Finanzen & Miete</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Mietzins Netto (Soll)</label>
                            <input type="number" step="0.05" name="mietzins_netto_soll" class="form-control" value="<?= (float)$wohnung['mietzins_netto_soll'] ?>" placeholder="0.00">
                        </div>
                        <div class="input-group">
                            <label>Nebenkosten Akonto (Soll)</label>
                            <input type="number" step="0.05" name="mietzins_nk_soll" class="form-control" value="<?= (float)$wohnung['mietzins_nk_soll'] ?>" placeholder="0.00">
                        </div>
                        <div class="input-group">
                            <label>Parkplatz / Einstellplatz</label>
                            <input type="text" name="parkplatz" class="form-control" value="<?= h($wohnung['parkplatz']) ?>" placeholder="z.B. Nr. 12 oder 'inkl.'">
                        </div>
                        <div class="input-group">
                            <label>Verfügbar ab</label>
                            <input type="text" name="available_from" class="form-control" value="<?= h($wohnung['available_from']) ?>" placeholder="z.B. Sofort oder 01.07.2024">
                        </div>
                    </div>
                </div>

                <!-- TECHNIK -->
                <div id="section-technik" class="form-section">
                    <h3 class="section-title">⚙️ Technik & Gebäude</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Heizungsart</label>
                            <input type="text" name="heizungsart" class="form-control" value="<?= h($wohnung['heizungsart']) ?>" placeholder="z.B. Erdwärme, Bodenheizung">
                        </div>
                        <div class="input-group">
                            <label>Bodenbeläge</label>
                            <input type="text" name="bodenbelag" class="form-control" value="<?= h($wohnung['bodenbelag']) ?>" placeholder="z.B. Parkett Eiche, Feinsteinzeug">
                        </div>
                        <div class="input-group">
                            <label>Waschmöglichkeit</label>
                            <input type="text" name="waschmaschine" class="form-control" value="<?= h($wohnung['waschmaschine']) ?>" placeholder="z.B. Eigener Waschturm in WHG">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Gebäude-Features</label>
                        <div class="checkbox-tile-group">
                            <label class="checkbox-tile">
                                <input type="checkbox" name="minergie" <?= $wohnung['minergie'] ? 'checked':'' ?>>
                                <span>🌱 Minergie</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="glasfaser" <?= $wohnung['glasfaser'] ? 'checked':'' ?>>
                                <span>⚡ Glasfaser / FTTH</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- UMGEBUNG -->
                <div id="section-umgebung" class="form-section">
                    <h3 class="section-title">🌳 Umgebung & Lage</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Besonnerung</label>
                            <input type="text" name="besonnerung" class="form-control" value="<?= h($wohnung['besonnerung']) ?>" placeholder="z.B. Süd-West, sehr sonnig">
                        </div>
                        <div class="input-group">
                            <label>Aussicht</label>
                            <input type="text" name="aussicht" class="form-control" value="<?= h($wohnung['aussicht']) ?>" placeholder="z.B. Bergsicht, Seesicht">
                        </div>
                        <div class="input-group">
                            <label>Lärmpegel</label>
                            <input type="text" name="laermpegel" class="form-control" value="<?= h($wohnung['laermpegel']) ?>" placeholder="z.B. sehr ruhig, Sackgasse">
                        </div>
                    </div>
                </div>

                <div class="actions" style="margin-top:20px; padding-bottom:20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <button type="submit" class="btn btn-blue" style="padding:15px 40px; border-radius:15px; font-weight:700;">💾 Alle Basisdaten speichern</button>
                    <div style="font-size:12px; color:#64748b;">ID: #<?= $id ?></div>
                </div>
            </form>

            <div id="section-rooms" class="form-section">
                <div style="display:flex; align-items:center; gap:15px; margin-bottom:25px; border-bottom:2px solid #f1f5f9; padding-bottom:15px;">
                    <div style="background:var(--primary); color:white; width:45px; height:45px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px; box-shadow:0 10px 15px -3px rgba(59,130,246,0.3);">➕</div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#1e293b;">Räume hinzufügen</h3>
                        <p style="margin:0; font-size:13px; color:#64748b;">Klicken Sie auf ein Symbol, um es der Wohnung zuzuordnen.</p>
                    </div>
                </div>
                
                <div style="background:linear-gradient(135deg, rgba(59,130,246,0.03) 0%, rgba(59,130,246,0.08) 100%); border:1.5px solid rgba(59,130,246,0.1); padding:30px; border-radius:30px; margin-bottom:40px; position:relative;">
                    
                    <div id="room_master_pool" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:15px; margin-bottom:25px;">
                        <!-- Vorlagen werden hier geladen -->
                    </div>

                    <!-- MASTER TEMPLATE MANAGER -->
                    <div id="master_manager_ui" style="display:none; padding:25px; background:white; border:2px solid var(--primary); border-radius:24px; margin-bottom:30px; box-shadow:0 20px 40px rgba(59,130,246,0.15); z-index:100; position:relative;">
                        <h4 style="margin:0 0 20px 0; font-size:14px; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; gap:10px;">
                            <span style="background:var(--primary); color:white; width:24px; height:24px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:14px;">🛠️</span>
                            Symbol-Bibliothek verwalten
                        </h4>
                        
                        <div style="display:flex; gap:20px; align-items:flex-start; margin-bottom:25px;">
                            <div style="width:100px;">
                                <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:6px; text-transform:uppercase;">Icon</label>
                                <input type="text" id="new_master_icon" placeholder="🚗" style="width:100%; padding:15px; border:2px solid #f1f5f9; border-radius:14px; font-size:24px; text-align:center; background:#f8fafc;">
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:6px; text-transform:uppercase;">Name der neuen Vorlage</label>
                                <input type="text" id="new_master_name" placeholder="z.B. Hobbyraum, Weinkeller..." style="width:100%; padding:15px; border:2px solid #f1f5f9; border-radius:14px; font-size:15px; font-weight:600; background:#f8fafc;">
                            </div>
                            <div style="padding-top:21px;">
                                <button type="button" onclick="event.preventDefault(); addMasterTemplate();" class="btn btn-blue" style="height:55px; padding:0 30px; border-radius:14px; font-weight:700; box-shadow:0 8px 20px rgba(59,130,246,0.2);">Speichern</button>
                            </div>
                        </div>

                        <div id="quick_emojis_wrap" style="border-top:1.5px solid #f1f5f9; padding-top:20px;">
                             <div id="quick_emojis" style="display:flex; flex-direction:column; gap:15px; background:#f8fafc; padding:20px; border-radius:20px; border:1.5px solid #f1f5f9; max-height:250px; overflow-y:scroll; scrollbar-width:thin;">
                                <?php 
                                $emojiPalette = [
                                    'Wohnen' => ['🛋️','🛏️','🛌','🪑','🖼️','🧺','🧹','🕯️','🧸','🧱'],
                                    'Technik' => ['🔧','🔨','🛠️','📶','🔌','🔋','🌡️','💡','🔥','⚙️','📟'],
                                    'Außen' => ['🌳','🌴','🚲','🚗','🏍️','🅿️','🌅','🌻','🪴','🛹','🚜','⛲','⛱️'],
                                    'Bad' => ['🛁','🚿','🛀','🚽','🧼','🧻','💧','🧴','🪠'],
                                    'Lager' => ['🔒','🔑','📦','🧥','🕷️','🕸️','🪜','🪵','🍷','🍶']
                                ];
                                foreach($emojiPalette as $cat => $icons): ?>
                                    <div style="font-size:10px; font-weight:900; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                        <?= $cat ?> <div style="flex:1; height:1px; background:#e2e8f0;"></div>
                                    </div>
                                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                                        <?php foreach($icons as $qi): ?>
                                            <span style="cursor:pointer; font-size:20px; width:40px; height:40px; display:flex; align-items:center; justify-content:center; background:white; border:1.5px solid #fff; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.03); transition:0.2s;" 
                                                  onmouseenter="this.style.transform='scale(1.2)'" onmouseleave="this.style.transform='scale(1)'"
                                                  onclick="document.getElementById('new_master_icon').value = '<?= $qi ?>'"><?= $qi ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                             </div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid rgba(59,130,246,0.1); pt-20; margin-top:20px; padding-top:20px;">
                        <button type="button" id="btn_toggle_manage" onclick="toggleMasterEdit()" style="background:white; border:1.5px solid #e2e8f0; color:#64748b; padding:8px 15px; border-radius:10px; font-weight:800; font-size:11px; cursor:pointer; text-transform:uppercase; transition:0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05);">⚙️ Symbole verwalten</button>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="text" id="new_room_name" placeholder="Eigener Raumname..." style="width:200px; padding:10px 15px; border-radius:12px; border:1.5px solid #e2e8f0; font-size:13px;">
                            <button type="button" class="btn btn-blue" onclick="addRoomManual()" style="height:38px; padding:0 20px; border-radius:10px; font-weight:700; font-size:13px;">Hinzufügen</button>
                        </div>
                    </div>
                </div>

                <div style="display:flex; align-items:center; gap:15px; margin-bottom:25px; margin-top:50px; border-bottom:2px solid #f1f5f9; padding-bottom:15px;">
                    <div style="background:#475569; color:white; width:45px; height:45px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);">🏠</div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#1e293b;">Bestehende Räume dieser Wohnung</h3>
                        <p style="margin:0; font-size:13px; color:#64748b;">Verwalten und sortieren Sie die bereits zugeordneten Räume.</p>
                    </div>
                </div>

                <div id="dash_rooms_list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
                    <!-- Aktuelle Räume werden hier geladen -->
                </div>
            </div>

            <!-- EINHEIT LÖSCHEN (Eigener Bereich) -->
            <div style="margin-top:50px; padding:30px; background:#fff1f2; border:1px solid #fecaca; border-radius:24px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h4 style="margin:0; color:#991b1b; font-weight:800;">Gefahrenzone</h4>
                    <p style="margin:0; font-size:12px; color:#b91c1c;">Vorsicht: Das Löschen der Einheit kann nicht rückgängig gemacht werden.</p>
                </div>
                <div id="del_wrap_<?= $id ?>">
                    <button type="button" class="btn btn-danger" onclick="confirmDelete(<?= $id ?>)" style="padding:10px 25px; border-radius:12px; font-weight:700;">Einheit löschen</button>
                </div>
                <div id="del_act_<?= $id ?>" style="display:none; align-items:center; gap:10px;">
                    <span style="font-size:14px; color:#ef4444; font-weight:800;">Wirklich löschen?</span>
                    <button type="button" class="btn btn-danger" onclick="executeDelete(<?= $id ?>)">JA, weg damit</button>
                    <button type="button" class="btn btn-outline" onclick="cancelDelete(<?= $id ?>)">Abbrechen</button>
                </div>
            </div>
    </div>
</div>
        </form>
    </div>
</div>

<script>
/**
 * Tab-Navigation mit Hash-Routing
 */
function showSection(id, el) {
    if(!id) id = 'basis';
    
    // UI Update
    document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    
    const section = document.getElementById('section-' + id);
    if(section) {
        section.classList.add('active');
        // Falls el nicht übergeben wurde (z.B. durch Hash-Wechsel), suchen wir den passenden Nav-Item
        if(!el) {
            el = Array.from(document.querySelectorAll('.nav-item')).find(item => item.getAttribute('onclick')?.includes(`'${id}'`));
        }
        if(el) el.classList.add('active');
        
        // URL Hash aktualisieren & Hidden Field im Formular für Rücksprung
        if(window.location.hash !== '#' + id) {
            history.pushState(null, null, '#' + id);
        }
        document.getElementById('form_hash_field').value = id;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Reagieren auf Hash-Änderungen (Back-Button etc.)
window.addEventListener('popstate', () => {
    const hash = window.location.hash.replace('#', '') || 'basis';
    showSection(hash);
});

// Initialer Check beim Laden
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '') || 'basis';
    showSection(hash);
});


/**
 * Raum-Management Logik
 */
const currentWohnungId = <?= (int)$id ?>;

let isMasterEditMode = false;

function toggleMasterEdit() {
    isMasterEditMode = !isMasterEditMode;
    const ui = document.getElementById('master_manager_ui');
    const btn = document.getElementById('btn_toggle_manage');
    if(ui) ui.style.display = isMasterEditMode ? 'block' : 'none';
    if(btn) btn.innerText = isMasterEditMode ? '✅ Verwaltung beenden' : '⚙️ Symbole verwalten';
    loadRooms(); // Refresh to show/hide delete buttons on tiles
}

function addMasterTemplate() {
    const icon = document.getElementById('new_master_icon').value.trim();
    const name = document.getElementById('new_master_name').value.trim();
    if(!name) return alert("Bitte geben Sie einen Namen für den Raum ein.");
    
    console.log("RoomManager: Adding master template", {name, icon});
    
    const fd = new FormData();
    fd.append('action', 'add_template');
    fd.append('name', name);
    fd.append('icon', icon || '📦');
    
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            console.log("RoomManager: Master template added successfully");
            document.getElementById('new_master_icon').value = '';
            document.getElementById('new_master_name').value = '';
            loadRooms();
            // Automatisch Verwaltung schließen für sauberes Ergebnis
            // toggleMasterEdit(); 
        } else {
            alert("Fehler beim Speichern: " + (res.error || 'Unbekannter Fehler'));
        }
    })
    .catch(err => {
        console.error("RoomManager Error:", err);
        alert("Netzwerk-Fehler: " + err.message);
    });
}

function deleteMasterTemplate(tid) {
    console.log("RoomManager: deleteMasterTemplate DIRECT START", tid);
    // window.confirm entfernt zum Testen
    
    const fd = new FormData();
    fd.append('action', 'delete_template');
    fd.append('t_id', tid);
    
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(text => {
        console.log("RoomManager: RAW RESPONSE:", text);
        try {
            const res = JSON.parse(text);
            if(res.success) {
                console.log("RoomManager: Master template deleted successfully");
                loadRooms();
            } else {
                alert("Fehler vom Server: " + (res.error || "Unbekannt"));
            }
        } catch(e) {
            console.error("RoomManager: JSON Parse Error", e);
            alert("Kritischer Fehler: Server antwortet mit Text statt Daten. Siehe Konsole.");
        }
    })
    .catch(err => {
        console.error("RoomManager: Fetch Error", err);
        alert("Netzwerk-Fehler: " + err.message);
    });
}

function deleteRoom(rid) {
    console.log("RoomManager: deleteRoom DIRECT START for ID:", rid);
    // window.confirm entfernt fuer Testlauf
    
    const fd = new FormData();
    fd.append('action', 'delete_room');
    fd.append('room_id', rid);
    
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(text => {
        console.log("RoomManager: deleteRoom RESPONSE:", text);
        try {
            const res = JSON.parse(text);
            if(res.success) {
                console.log("RoomManager: Room deleted successfully");
                loadRooms();
            } else {
                alert("Fehler beim Löschen: " + (res.error || "Unbekannt"));
            }
        } catch(e) {
            console.error("RoomManager: JSON Parse Error", e, text);
            alert("Fehler: Server antwortet nicht im richtigen Format.");
        }
    })
    .catch(err => alert("Netzwerk-Fehler: " + err.message));
}

function loadRooms() {
    const list = document.getElementById('dash_rooms_list');
    const pool = document.getElementById('room_master_pool');
    const summary = document.getElementById('sidebar-room-summary');
    if(!list || !pool) return;
    
    list.innerHTML = '<div style="color:#94a3b8; font-style:italic; padding:20px;">⌛ Lade Raum-Struktur...</div>';
    
    fetch('ajax_unit_rooms.php?wohnung_id=' + currentWohnungId + '&_nc=' + Date.now())
    .then(r => r.json())
    .then(res => {
        if(!res.success) throw new Error(res.error || 'API Fehler');
        
        // Sicherheitshalber beides prüfen (data-Objekt oder Root)
        let data = res.data || res; 
        if(!data.rooms && res.rooms) data = res; 

        // 1. Schnellauswahl
        pool.innerHTML = '';
        if(data.master_data) {
            data.master_data.forEach(m => {
                const wrapper = document.createElement('div');
                wrapper.style.position = 'relative';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline';
                btn.style.cssText = 'display:flex; flex-direction:column; align-items:center; gap:8px; padding:18px 12px; width:100%; min-height:90px; border-radius:18px; border:1.5px solid #e2e8f0; background:white; cursor:pointer; font-family:inherit; transition:0.2s;';
                btn.innerHTML = `<span style="font-size:32px; pointer-events:none;">${m.icon || '📦'}</span><span style="font-size:11px; font-weight:800; color:#1e293b; pointer-events:none;">${m.name}</span>`;
                
                wrapper.appendChild(btn); // Erst den Knopf

                if(!isMasterEditMode) {
                    btn.onclick = (e) => { e.preventDefault(); addRoom(m.name); };
                } else {
                    btn.style.opacity = '0.3';
                    btn.style.cursor = 'default';
                    btn.style.pointerEvents = 'none';
                    btn.onclick = null; // Sicherstellen, dass er nichts tut

                    // Das rote X kommt ZULETZT, damit es GANZ OBEN liegt
                    const del = document.createElement('div');
                    del.style.cssText = 'position:absolute; top:-12px; right:-12px; background:#ef4444; color:white; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:22px; cursor:pointer !important; z-index:9999; border:3px solid white; font-weight:bold; box-shadow:0 5px 15px rgba(220,38,38,0.5); transition:0.2s; pointer-events: auto !important;';
                    del.innerHTML = '&times;';
                    
                    del.onmouseenter = () => { del.style.transform = 'scale(1.15)'; del.style.background = '#dc2626'; };
                    del.onmouseleave = () => { del.style.transform = 'scale(1)'; del.style.background = '#ef4444'; };
                    
                    del.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log("RoomManager: Delete template ID:", m.id);
                        deleteMasterTemplate(m.id);
                    });
                    wrapper.appendChild(del);
                }
                pool.appendChild(wrapper);
            });
        }

        // 2. Liste
        list.innerHTML = '';
        let icons = '';
        if(!data.rooms || data.rooms.length === 0) {
            list.innerHTML = '<div style="grid-column:1/-1; padding:50px; text-align:center; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:24px;">Noch keine Räume definiert.</div>';
        } else {
            data.rooms.forEach(r => {
                const m = data.master_data.find(master => master.name === r.name || r.name.startsWith(master.name));
                const ic = m ? m.icon : '▫️';
                icons += ic;

                const div = document.createElement('div');
                div.style.cssText = 'background:#fff; border:1.5px solid #e2e8f0; padding:18px; border-radius:20px; display:flex; justify-content:space-between; align-items:center; transition:0.2s;';
                
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.style.cssText = 'display:flex; align-items:center; justify-content:center; width:45px; height:45px; border-radius:12px; border:2px solid #fecaca; background:#fff; color:#ef4444; cursor:pointer !important; transition:0.2s; position:relative; z-index:100;';
                deleteBtn.innerHTML = '<span style="font-size:20px; pointer-events:none;">🗑️</span>';
                deleteBtn.onmouseenter = () => { deleteBtn.style.background = '#ef4444'; deleteBtn.style.color = '#fff'; };
                deleteBtn.onmouseleave = () => { deleteBtn.style.background = '#fff'; deleteBtn.style.color = '#ef4444'; };
                deleteBtn.onclick = function(e) {
                    e.preventDefault(); e.stopPropagation();
                    deleteRoom(r.id);
                };

                div.innerHTML = `
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span style="font-size:24px;">${ic}</span>
                        <span style="font-weight:700; color:#0f172a; font-size:16px;">${r.name}</span>
                    </div>
                `;
                div.appendChild(deleteBtn);
                list.appendChild(div);
            });
        }
        if(summary) summary.innerText = icons;
    })
    .catch(err => {
        list.innerHTML = `<div style="color:#ef4444; padding:20px;">⚠️ Ladefehler: ${err.message}</div>`;
    });
}

function addRoom(name) {
    console.log("RoomManager: Attempting to add", name);
    const fd = new FormData();
    fd.append('action', 'add_room');
    fd.append('w_id', currentWohnungId);
    fd.append('room_name', name);
    
    fetch('ajax_unit_rooms.php', { 
        method: 'POST', 
        body: fd 
    })
    .then(async r => {
        if(!r.ok) throw new Error("Netzwerk-Antwort war nicht OK (" + r.status + ")");
        return r.json();
    })
    .then(res => {
        if(res.success) {
            console.log("RoomManager: Successfully added", name);
            loadRooms();
        } else {
            console.error("RoomManager API Error:", res.error);
            alert("Fehler beim Speichern: " + res.error);
        }
    })
    .catch(err => {
        console.error("RoomManager Fetch Error:", err);
        alert("Klick erkannt, aber Server meldet Fehler: " + err.message);
    });
}

function addRoomManual() {
    const val = document.getElementById('new_room_name').value.trim();
    if(val) {
        addRoom(val);
        document.getElementById('new_room_name').value = '';
    }
}

/**
 * Lösch-Aktionen (Einheit)
 */
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
    f.innerHTML = '<input type="hidden" name="action" value="delete_unit_force">';
    document.body.appendChild(f);
    f.submit();
}

// Initial laden
loadRooms();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
