<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die("Keine Abnahme-ID angegeben.");

// Abnahme-Daten laden
$stmt = $mysqli->prepare("
    SELECT a.*, p.name as projekt_name, w.name as einheit_name, b.name as unternehmer_name 
    FROM abnahmen a
    LEFT JOIN projekte p ON a.projekt_id = p.id
    LEFT JOIN wohnungen w ON a.wohneinheit_id = w.id
    LEFT JOIN benutzer b ON a.unternehmer_id = b.id
    WHERE a.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$abnahme = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$abnahme) die("Abnahme nicht gefunden.");

// Zugehörige Pendenzen laden
$pendenzen = [];
$res = $mysqli->query("
    SELECT p.*, b.name as partner_name 
    FROM abnahme_mangel_link link
    JOIN pendenzen p ON link.pendenz_id = p.id
    LEFT JOIN benutzer b ON p.partner_id = b.id
    WHERE link.abnahme_id = $id
    ORDER BY p.id DESC
");
if ($res) while($row = $res->fetch_assoc()) $pendenzen[] = $row;

// Neuer Mangel erfassen?
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mangel') {
    $titel = $_POST['titel'];
    $p_id = $abnahme['projekt_id'];
    $u_id = $abnahme['unternehmer_id'];
    $w_id = $abnahme['wohneinheit_id'];
    
    // In Pendenzen-Tabelle schreiben
    $stmt = $mysqli->prepare("INSERT INTO pendenzen (projekt_id, partner_id, wohnung_id, titel, status, typ) VALUES (?, ?, ?, ?, 'offen', 'Mangel')");
    $stmt->bind_param("iiis", $p_id, $u_id, $w_id, $titel);
    $stmt->execute();
    $pend_id = $stmt->insert_id;
    $stmt->close();
    
    // Link erstellen
    $stmt = $mysqli->prepare("INSERT INTO abnahme_mangel_link (abnahme_id, pendenz_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $id, $pend_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: abnahme_edit.php?id=" . $id);
    exit;
}

$title = "Abnahme: " . $abnahme['einheit_name'] . " | pendenz.com";
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="main-content">
    <div class="abnahme-layout">
        <!-- Linke Spalte: Protokoll-Kopf & Erfassung -->
        <div class="abnahme-main">
            <header class="abnahme-header card">
                <div class="header-content">
                    <span class="type-badge"><?= $abnahme['abnahme_typ'] ?></span>
                    <h1><?= htmlspecialchars($abnahme['einheit_name'] ?: 'Gesamtprojekt') ?></h1>
                    <p><?= htmlspecialchars($abnahme['projekt_name']) ?> | <?= date('d.m.Y', strtotime($abnahme['datum'])) ?><br>
                    <strong>Unternehmer:</strong> <?= htmlspecialchars($abnahme['unternehmer_name']) ?></p>
                </div>
                <div class="header-actions">
                    <a href="abnahme_pdf.php?id=<?= $id ?>" class="btn-secondary">📄 Protokoll-Vorschau</a>
                    <button class="btn-primary" onclick="alert('Abnahme wird finalisiert...')">✅ Abnahme abschliessen</button>
                </div>
            </header>

            <section class="mangel-erfassung card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 style="margin:0;">➕ Neuen Punkt erfassen</h3>
                    <button class="btn-ai-small ai-feature" onclick="askAiForDescription()" style="display:none;">🤖 KI-Formulierung</button>
                </div>
                <form method="post" id="mangelForm" class="quick-form">
                    <input type="hidden" name="action" value="add_mangel">
                    <div class="voice-input-container" style="flex:1; display:flex; position:relative;">
                        <input type="text" name="titel" id="mangelTitel" placeholder="Mangel beschreiben oder Mikrofon nutzen..." required style="width:100%; pr:40px;">
                        <button type="button" id="voice-btn" class="voice-btn-inner ai-feature" title="Sprachaufnahme" style="display:none;">🎤</button>
                    </div>
                    <button type="submit">Speichern</button>
                </form>
            </section>

            <section class="ai-suggestions-panel card ai-feature" style="display:none;">
                <div class="ai-header">
                    <span class="ai-icon">✨</span>
                    <h4>KI-Assistent: Checkliste für <?= $abnahme['abnahme_typ'] ?></h4>
                </div>
                <div class="ai-content">
                    <p class="small">Basierend auf diesem Objekttyp empfehle ich, folgende Punkte zu prüfen:</p>
                    <div class="ai-tags">
                        <button onclick="addMangel('Silikonfugen im Bad auf Risse prüfen')">🚽 Silikonfugen</button>
                        <button onclick="addMangel('Fenstergriffe und Schließmechanismus testen')">🪟 Fensterfunktion</button>
                        <button onclick="addMangel('Elektro-Tableau: Beschriftung vorhanden?')">⚡ Sicherungskasten</button>
                        <button onclick="addMangel('Heizkörper: Entlüftet und Funktionsprüfung')">🔥 Heizung</button>
                    </div>
                </div>
            </section>

            <section class="mangel-liste">
                <div class="section-title">Erfasste Punkte (<?= count($pendenzen) ?>)</div>
                <div class="pendenz-grid">
                    <?php foreach($pendenzen as $p): ?>
                        <div class="pendenz-card">
                            <div class="p-status"></div>
                            <div class="p-content">
                                <div class="p-title"><?= htmlspecialchars($p['titel']) ?></div>
                                <div class="p-meta">Zuständig: <?= htmlspecialchars($p['partner_name']) ?></div>
                            </div>
                            <div class="p-actions">
                                <button class="btn-icon">📸</button>
                                <button class="btn-icon">🗑️</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <!-- Rechte Spalte: Details & Unterschriften -->
        <div class="abnahme-sidebar">
            <div class="card">
                <h3>Informationen</h3>
                <ul class="info-list">
                    <li><span>Datum:</span> <strong><?= date('d.m.Y', strtotime($abnahme['datum'])) ?></strong></li>
                    <li><span>Status:</span> <span class="badge blue"><?= $abnahme['status'] ?></span></li>
                </ul>
            </div>
            
            <div class="card signature-card">
                <h3>Unterschriften</h3>
                <div class="sig-box">Bauleitung / Experte</div>
                <div class="sig-box">Unternehmer</div>
                <p class="muted small text-center">Digitale Signatur-Funktion folgt in Kürze.</p>
            </div>
        </div>
    </div>
</div>

<style>
.main-content { padding: 30px; background: #f1f5f9; min-height: 100vh; }
.abnahme-layout { display: grid; grid-template-columns: 1fr 300px; gap: 25px; max-width: 1200px; margin: 0 auto; }

.card { background: #fff; padding: 25px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 25px; }
.abnahme-header { display: flex; justify-content: space-between; align-items: flex-start; }
.type-badge { background: #3b82f6; color: #fff; padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase; margin-bottom: 10px; display: inline-block; }
.abnahme-header h1 { font-size: 28px; font-weight: 800; color: #0f172a; margin-bottom: 5px; }
.abnahme-header p { color: #64748b; font-size: 14px; line-height: 1.5; }
.voice-input-container input { padding-right: 45px !important; }
.voice-btn-inner { 
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    background: transparent; border: 0; font-size: 18px; cursor: pointer; 
    opacity: 0.5; transition: 0.3s; padding: 5px;
}
.voice-btn-inner:hover { opacity: 1; transform: translateY(-50%) scale(1.1); }
.voice-btn-inner.active { color: #ef4444; opacity: 1; animation: blink 1s infinite; }
@keyframes blink { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }

.btn-ai-small { background: rgba(59, 130, 246, 0.1); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.2); padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 800; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: 0.3s; }
.btn-ai-small:hover { background: #2563eb; color: #fff; }

.ai-suggestions-panel { background: linear-gradient(to bottom right, #ffffff, #f0f7ff); border: 1px solid rgba(59, 130, 246, 0.2); }
.ai-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.ai-header h4 { margin: 0; font-size: 14px; color: #1e3a8a; }
.ai-tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.ai-tags button { background: #fff; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 20px; font-size: 12px; cursor: pointer; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
.ai-tags button:hover { border-color: #3b82f6; color: #3b82f6; transform: translateY(-2px); }

<script>
function addMangel(text) {
    document.getElementById('mangelTitel').value = text;
    document.getElementById('mangelTitel').focus();
}

function askAiForDescription() {
    const input = document.getElementById('mangelTitel');
    if(!input.value) { alert('Bitte gib zuerst ein Schlagwort ein.'); return; }
    
    // Simulation einer KI-Verbesserung
    const original = input.value;
    input.value = "Expert: " + original + " - Fachgerechte Nachbesserung/Instandsetzung durch Fachunternehmer erforderlich. Prüfung nach SIA-Normen.";
    input.style.borderColor = "#3b82f6";
    setTimeout(() => input.style.borderColor = "", 1000);
}

// KI State Management
function updateAiVisibility(active) {
    const features = document.querySelectorAll('.ai-feature');
    features.forEach(el => {
        el.style.display = active ? 'block' : 'none';
        if (active) {
            el.animate([
                { opacity: 0, transform: 'translateY(-10px)' },
                { opacity: 1, transform: 'translateY(0)' }
            ], { duration: 300, easing: 'ease-out' });
        }
    });
}

// Sprachsteuerung
const voiceBtn = document.getElementById('voice-btn');
const inputField = document.getElementById('mangelTitel');

if ('webkitSpeechRecognition' in window) {
    const recognition = new webkitSpeechRecognition();
    recognition.continuous = false;
    recognition.lang = 'de-CH'; // Schweizer Deutsch Support

    recognition.onstart = () => {
        voiceBtn.classList.add('active');
        inputField.placeholder = "Ich höre zu...";
    };

    recognition.onresult = (event) => {
        const text = event.results[0][0].transcript;
        inputField.value = text;
        voiceBtn.classList.remove('active');
        inputField.placeholder = "Mangel beschreiben...";
        
        // Sofort die KI zur Formatierung triggern
        setTimeout(askAiForDescription, 500);
    };

    recognition.onerror = () => {
        voiceBtn.classList.remove('active');
        alert("Spracherkennung fehlgeschlagen oder nicht erlaubt.");
    };

    voiceBtn.addEventListener('click', () => {
        recognition.start();
    });
} else {
    voiceBtn.style.display = 'none';
}

window.addEventListener('aiStateChanged', (e) => {
    updateAiVisibility(e.detail.active);
});

document.addEventListener('DOMContentLoaded', () => {
    const isActive = localStorage.getItem('ai-assistant-active') === '1';
    updateAiVisibility(isActive);
});
</script>

.header-actions { display: flex; gap: 10px; }
.btn-primary { background: #2563eb; color: #fff; border: 0; padding: 12px 20px; border-radius: 12px; font-weight: 700; cursor: pointer; }
.btn-secondary { background: #f1f5f9; color: #1e293b; border: 0; padding: 12px 20px; border-radius: 12px; font-weight: 700; text-decoration: none; display: inline-block; }

.mangel-erfassung h3 { font-size: 16px; margin-bottom: 15px; color: #1e293b; }
.quick-form { display: flex; gap: 10px; }
.quick-form input { flex: 1; padding: 12px 15px; border: 2px solid #f1f5f9; border-radius: 12px; outline: none; transition: 0.2s; }
.quick-form input:focus { border-color: #3b82f6; }
.quick-form button { background: #1e293b; color: #fff; border: 0; padding: 0 20px; border-radius: 12px; font-weight: 700; cursor: pointer; }

.section-title { font-size: 14px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 15px; }
.pendenz-grid { display: flex; flex-direction: column; gap: 12px; }
.pendenz-card { display: flex; align-items: center; background: #fff; padding: 15px; border-radius: 15px; border-left: 5px solid #ef4444; }
.p-content { flex: 1; }
.p-title { font-weight: 700; color: #1e293b; margin-bottom: 4px; }
.p-meta { font-size: 12px; color: #64748b; }
.p-actions { display: flex; gap: 12px; }

.info-list { list-style: none; padding: 0; margin-top: 15px; }
.info-list li { display: flex; justify-content: space-between; font-size: 14px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
.info-list li span { color: #64748b; }

.sig-box { height: 100px; background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; margin: 15px 0; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 12px; font-weight: 700; text-transform: uppercase; }
.badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; }
.badge.blue { background: #dbeafe; color: #1e40af; }

.small { font-size: 11px; }
.muted { color: #64748b; }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
