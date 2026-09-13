<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$title = "Abnahmen & Protokolle | pendenz.com";
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';

// Projektkontext
$current_pid = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));

// Abnahmen laden
$abnahmen = [];
if ($mysqli) {
    $sql = "SELECT a.*, p.name as projekt_name, b.name as unternehmer_name 
            FROM abnahmen a
            LEFT JOIN projekte p ON a.projekt_id = p.id
            LEFT JOIN benutzer b ON a.unternehmer_id = b.id";
    if ($current_pid > 0) $sql .= " WHERE a.projekt_id = $current_pid";
    $sql .= " ORDER BY a.datum DESC";
    $res = $mysqli->query($sql);
    if ($res) while($row = $res->fetch_assoc()) $abnahmen[] = $row;
}
?>

<div class="main-content">
    <header class="page-header" style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h1>📜 Abnahmen & Protokolle</h1>
            <p class="muted">Verwalte Unternehmer-Abnahmen und generiere rechtssichere Protokolle.</p>
        </div>
        <a href="abnahme_neu.php<?= $current_pid ? '?projekt_id='.$current_pid : '' ?>" class="btn-primary">➕ Neue Abnahme starten</a>
    </header>

    <div class="stats-overview" style="display:grid; grid-template-columns: repeat(4, 1fr); gap:20px; margin: 25px 0;">
        <div class="stat-card">
            <span class="label">Gesamt Abnahmen</span>
            <span class="value"><?= count($abnahmen) ?></span>
        </div>
        <div class="stat-card">
            <span class="label">Pendenzen offen</span>
            <span class="value" style="color:#ef4444;">12</span>
        </div>
        <div class="stat-card highlight">
            <span class="label">Projekt-Fortschritt</span>
            <div class="progress-info">
                <span class="value">64%</span>
                <div class="progress-bar-mini"><div class="fill" style="width:64%;"></div></div>
            </div>
        </div>
        <div class="stat-card ai-insight-card ai-feature" style="display:none;">
            <span class="label">🤖 AI Insight</span>
            <p class="small">3 Abnahmen für <strong>Sanitär</strong> überfällig.</p>
        </div>
    </div>

    <div class="control-strip card">
        <div class="tabs">
            <button class="tab-btn active">Alle (<?= count($abnahmen) ?>)</button>
            <button class="tab-btn">Offen</button>
            <button class="tab-btn">In Prüfung</button>
            <button class="tab-btn">Abgeschlossen</button>
        </div>
        <div class="search-mini">
            <input type="text" placeholder="Abnahme oder Unternehmer suchen...">
        </div>
    </div>

    <div class="card overflow-hidden">
        <table class="premium-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Projekt</th>
                    <th>Typ</th>
                    <th>Unternehmer</th>
                    <th>Status</th>
                    <th align="right">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($abnahmen)): ?>
                    <tr><td colspan="6" align="center" style="padding:50px;">Keine Abnahmen gefunden. Starte jetzt deine erste Abnahme!</td></tr>
                <?php else: ?>
                    <?php foreach($abnahmen as $a): ?>
                        <tr>
                            <td><strong><?= date('d.m.Y', strtotime($a['datum'])) ?></strong></td>
                            <td><?= htmlspecialchars($a['projekt_name']) ?></td>
                            <td><span class="badge gray"><?= $a['abnahme_typ'] ?></span></td>
                            <td><?= htmlspecialchars($a['unternehmer_name'] ?: 'N/A') ?></td>
                            <td>
                                <span class="badge <?= $a['status'] === 'Abgeschlossen' ? 'green' : 'blue' ?>">
                                    <?= $a['status'] ?>
                                </span>
                            </td>
                            <td align="right">
                                <a href="abnahme_edit.php?id=<?= $a['id'] ?>" class="btn-icon">✏️</a>
                                <a href="abnahme_pdf.php?id=<?= $a['id'] ?>" class="btn-icon">📄</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.main-content { padding: 30px; max-width: 1400px; margin: 0 auto; }
.stat-card { background: #fff; padding: 20px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; }
.stat-card .label { font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 800; display: block; margin-bottom: 8px; letter-spacing: 0.5px; }
.stat-card .value { font-size: 26px; font-weight: 900; color: #0f172a; }
.stat-card.highlight { background: linear-gradient(135deg, #1e293b, #0f172a); border: 0; }
.stat-card.highlight * { color: #fff; }

.progress-info { display: flex; align-items: center; gap: 12px; margin-top: 5px; }
.progress-bar-mini { flex: 1; height: 6px; background: rgba(255,255,255,0.1); border-radius: 10px; overflow: hidden; }
.progress-bar-mini .fill { height: 100%; background: #3b82f6; border-radius: 10px; }

.ai-insight-card { background: rgba(59, 130, 246, 0.03); border: 1px dashed rgba(59, 130, 246, 0.3); }
.ai-insight-card p { margin: 5px 0 0; font-size: 13px; color: #1e40af; line-height: 1.4; }

.control-strip { padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.tabs { display: flex; gap: 5px; }
.tab-btn { background: transparent; border: 0; padding: 8px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; transition: 0.2s; }
.tab-btn:hover { background: #f1f5f9; color: #1e293b; }
.tab-btn.active { background: #1e293b; color: #fff; }

.search-mini input { padding: 8px 15px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 13px; width: 250px; outline: none; transition: 0.2s; }
.search-mini input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }

.badge { padding: 5px 10px; border-radius: 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
.badge.gray { background: #f1f5f9; color: #475569; }
.badge.green { background: #dcfce7; color: #15803d; }
.badge.blue { background: #dbeafe; color: #1d4ed8; }

.premium-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.premium-table th { padding: 18px 20px; background: #f8fafc; text-align: left; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 900; letter-spacing: 0.5px; border-bottom: 1px solid #edf2f7; }
.premium-table td { padding: 18px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #334155; transition: 0.2s; }
.premium-table tr:hover td { background: #fcfdfe; }

.btn-icon { text-decoration: none; font-size: 18px; margin-left: 12px; display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; transition: 0.2s; }
.btn-icon:hover { background: rgba(59, 130, 246, 0.1); transform: translateY(-2px); }
</style>


<script>
function updateAiVisibility(active) {
    const features = document.querySelectorAll('.ai-feature');
    features.forEach(el => {
        el.style.display = active ? 'block' : 'none';
        if (active) {
            el.animate([
                { opacity: 0, scale: 0.95 },
                { opacity: 1, scale: 1 }
            ], { duration: 300, easing: 'ease-out' });
        }
    });
}

window.addEventListener('aiStateChanged', (e) => {
    updateAiVisibility(e.detail.active);
});

document.addEventListener('DOMContentLoaded', () => {
    const isActive = localStorage.getItem('ai-assistant-active') === '1';
    updateAiVisibility(isActive);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
