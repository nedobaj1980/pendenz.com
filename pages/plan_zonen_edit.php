<?php
// Datei: c:\xampp\htdocs\pendenz.com\pages\plan_zonen_edit.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
if (!is_admin()) {
    die("Keine Berechtigung.");
}

$plan_id = (int)($_GET['plan_id'] ?? 0);
ensure_projekt_plaene_tables($mysqli);
if (!$plan_id) die("Keine Plan-ID.");

// Plan laden
$stmt = $mysqli->prepare("SELECT * FROM projekt_plaene WHERE id = ?");
$stmt->bind_param("i", $plan_id);
$stmt->execute();
$plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$plan) die("Plan nicht gefunden.");
$projekt_id = (int)$plan['projekt_id'];

// AJAX Speichern verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_zone') {
    $w_id = (int)$_POST['wohnung_id'];
    $x = (float)$_POST['x_pct'];
    $y = (float)$_POST['y_pct'];
    $w = (float)$_POST['width_pct'];
    $h = (float)$_POST['height_pct'];
    
    $stmt = $mysqli->prepare("INSERT INTO plan_zonen (plan_id, wohnung_id, x_pct, y_pct, width_pct, height_pct) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iidddd", $plan_id, $w_id, $x, $y, $w, $h);
    $stmt->execute();
    $stmt->close();
    
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

// AJAX Löschen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_zone') {
    $zone_id = (int)$_POST['zone_id'];
    $stmt = $mysqli->prepare("DELETE FROM plan_zonen WHERE id = ? AND plan_id = ?");
    $stmt->bind_param("ii", $zone_id, $plan_id);
    $stmt->execute();
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}

// Wohnungen laden
$wohnungen = [];
$stmt = $mysqli->prepare("SELECT w.id, CONCAT(o.name, ' - ', w.name) as name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE o.projekt_id = ? ORDER BY o.name ASC, w.name ASC");
if ($stmt) {
    $stmt->bind_param("i", $projekt_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $wohnungen[] = $row;
    $stmt->close();
}

// Existierende Zonen laden
$zonen = [];
$stmt = $mysqli->prepare("SELECT pz.*, w.name as wohnung_name FROM plan_zonen pz LEFT JOIN wohnungen w ON pz.wohnung_id = w.id WHERE pz.plan_id = ?");
$stmt->bind_param("i", $plan_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) $zonen[] = $row;
$stmt->close();

$PAGE_TITLE = "Zonen markieren: " . h($plan['name']);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
.shell { display: grid; grid-template-columns: 280px 1fr; gap: 16px; max-width: 1300px; margin: 24px auto; }
.aside { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; padding: 10px; }
.main { min-width: 0; }
.card { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; overflow: hidden; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); }
.card-header { background: #f8fafc; padding: 12px 16px; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
.card-body { padding: 16px; }
.btn-primary { background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; width: 100%; text-align: center; }
.btn-primary:hover { background: #2563eb; }
.btn-outline { border: 1px solid #cbd5e1; background: white; color: #475569; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; width: 100%; text-align: center; }
.btn-outline:hover { background: #f1f5f9; }

.plan-container {
    position: relative;
    display: inline-block;
    max-width: 100%;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: crosshair;
    user-select: none;
    background: #f8fafc;
    overflow: hidden;
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1);
}
.plan-image {
    display: block;
    max-width: 100%;
    height: auto;
    pointer-events: none;
}
.zone-box {
    position: absolute;
    border: 2px dashed #ef4444;
    background: rgba(239, 68, 68, 0.2);
    pointer-events: none;
    box-shadow: 0 0 0 1px rgba(255,255,255,0.5);
}
.zone-box.saved {
    border: 2px solid #3b82f6;
    background: rgba(59, 130, 246, 0.3);
    pointer-events: auto;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 700;
    font-size: 11px;
    text-align: center;
    padding: 4px;
    line-height: 1.2;
    text-shadow: 0 1px 3px rgba(0,0,0,0.8);
    transition: all 0.2s;
}
.zone-box.saved:hover {
    background: rgba(59, 130, 246, 0.5);
    border-color: #2563eb;
    transform: scale(1.02);
    z-index: 10;
}
</style>

<main class="shell">
    <aside class="aside">
        <div style="padding:10px;">
            <h4 style="margin:0 0 10px 0; color:#475569;">Navigation</h4>
            <a href="<?= page_url('projekt_plaene.php?projekt_id=' . $projekt_id) ?>" class="btn-outline" style="margin-bottom:8px;">🔙 Zurück zu Plänen</a>
            
            <div style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:15px;">
                <h4 style="margin:0 0 10px 0; color:#475569;">Zonen anlegen</h4>
                <div id="zoneForm" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; padding:12px; border-radius:8px;">
                    <p style="font-size:13px; font-weight:600; margin-top:0;">Wähle die Wohnung für das markierte Rechteck:</p>
                    <select id="wohnungSelect" class="form-control" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; margin-bottom:12px;">
                        <option value="">-- Wohnung wählen --</option>
                        <?php foreach ($wohnungen as $w): ?>
                            <option value="<?= $w['id'] ?>"><?= h($w['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-primary" onclick="saveZone()">Speichern</button>
                    <button class="btn-outline" style="margin-top:8px;" onclick="cancelDraw()">Abbrechen</button>
                </div>
                
                <div id="zoneHelp" style="background:#eff6ff; border:1px solid #bfdbfe; padding:12px; border-radius:8px;">
                    <strong style="color:#1e3a8a; font-size:14px;">Anleitung:</strong>
                    <ol style="margin:8px 0 0 0; padding-left:20px; font-size:13px; color:#1e40af; line-height:1.5;">
                        <li>Klicke auf den Plan und halte die Maustaste gedrückt.</li>
                        <li>Ziehe ein Rechteck über eine Wohnung.</li>
                        <li>Wähle hier die passende Wohnung aus.</li>
                    </ol>
                    <p style="margin:10px 0 0 0; font-size:12px; color:#3b82f6;"><em>Tipp: Klicke auf eine blaue Zone, um sie zu löschen.</em></p>
                </div>
            </div>
        </div>
    </aside>

    <section class="main">
        <div style="margin-bottom:20px;">
            <h2 style="margin:0;">🎯 Zonen markieren: <?= h($plan['name']) ?></h2>
            <p style="color:#64748b; margin:4px 0 0 0;">Verknüpfe Bereiche auf dem Plan mit deinen Mieteinheiten, um später Pendenzen exakt verorten zu können.</p>
        </div>

        <div class="plan-container" id="planContainer">
            <img src="<?= url($plan['datei_pfad']) ?>" class="plan-image" id="planImage">
            
            <!-- Existierende Zonen -->
            <?php foreach ($zonen as $z): ?>
                <div class="zone-box saved" 
                     style="left: <?= $z['x_pct'] ?>%; top: <?= $z['y_pct'] ?>%; width: <?= $z['width_pct'] ?>%; height: <?= $z['height_pct'] ?>%;"
                     onclick="deleteZone(<?= $z['id'] ?>, '<?= h($z['wohnung_name']) ?>')">
                    <?= h($z['wohnung_name'] ?: 'Unbekannt') ?>
                </div>
            <?php endforeach; ?>
            
            <!-- Temporäre Zeichen-Box -->
            <div id="drawBox" class="zone-box" style="display:none;"></div>
        </div>
    </section>
</main>

<script>
const container = document.getElementById('planContainer');
const drawBox = document.getElementById('drawBox');
const zoneForm = document.getElementById('zoneForm');
const zoneHelp = document.getElementById('zoneHelp');

let isDrawing = false;
let startX, startY;
let currentZone = null;

container.addEventListener('mousedown', (e) => {
    // Ignoriere Klicks auf bereits gespeicherte Zonen (fürs Löschen)
    if (e.target.classList.contains('saved')) return;
    
    const rect = container.getBoundingClientRect();
    startX = e.clientX - rect.left;
    startY = e.clientY - rect.top;
    
    isDrawing = true;
    drawBox.style.display = 'block';
    drawBox.style.left = startX + 'px';
    drawBox.style.top = startY + 'px';
    drawBox.style.width = '0px';
    drawBox.style.height = '0px';
    
    zoneForm.style.display = 'none';
    zoneHelp.style.display = 'block';
});

container.addEventListener('mousemove', (e) => {
    if (!isDrawing) return;
    
    const rect = container.getBoundingClientRect();
    let currentX = e.clientX - rect.left;
    let currentY = e.clientY - rect.top;
    
    // Begrenzungen
    currentX = Math.max(0, Math.min(currentX, rect.width));
    currentY = Math.max(0, Math.min(currentY, rect.height));
    
    const width = Math.abs(currentX - startX);
    const height = Math.abs(currentY - startY);
    const left = Math.min(currentX, startX);
    const top = Math.min(currentY, startY);
    
    drawBox.style.left = left + 'px';
    drawBox.style.top = top + 'px';
    drawBox.style.width = width + 'px';
    drawBox.style.height = height + 'px';
});

container.addEventListener('mouseup', (e) => {
    if (!isDrawing) return;
    isDrawing = false;
    
    const rect = container.getBoundingClientRect();
    const boxRect = drawBox.getBoundingClientRect();
    
    // Nur akzeptieren, wenn groß genug gezogen
    if (boxRect.width > 20 && boxRect.height > 20) {
        // Prozentwerte berechnen
        currentZone = {
            x_pct: ((boxRect.left - rect.left) / rect.width) * 100,
            y_pct: ((boxRect.top - rect.top) / rect.height) * 100,
            width_pct: (boxRect.width / rect.width) * 100,
            height_pct: (boxRect.height / rect.height) * 100
        };
        
        zoneForm.style.display = 'block';
        zoneHelp.style.display = 'none';
    } else {
        cancelDraw();
    }
});

function cancelDraw() {
    drawBox.style.display = 'none';
    zoneForm.style.display = 'none';
    zoneHelp.style.display = 'block';
    currentZone = null;
    document.getElementById('wohnungSelect').value = '';
}

function saveZone() {
    const wid = document.getElementById('wohnungSelect').value;
    if (!wid || !currentZone) {
        alert("Bitte eine Wohnung auswählen!");
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'save_zone');
    formData.append('wohnung_id', wid);
    formData.append('x_pct', currentZone.x_pct);
    formData.append('y_pct', currentZone.y_pct);
    formData.append('width_pct', currentZone.width_pct);
    formData.append('height_pct', currentZone.height_pct);
    
    fetch('', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if(data.status === 'ok') location.reload();
    });
}

function deleteZone(id, name) {
    if (confirm("Zone für '" + name + "' wirklich löschen?")) {
        const formData = new FormData();
        formData.append('action', 'delete_zone');
        formData.append('zone_id', id);
        fetch('', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'ok') location.reload();
        });
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
