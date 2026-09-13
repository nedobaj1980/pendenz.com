<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
body { background:#f1f5f9; }
.ov-wrap { max-width:1280px; margin:0 auto; padding:24px; }
.ov-hero { background:linear-gradient(135deg,#1d4ed8,#2563eb); color:#fff; border-radius:18px; padding:24px 28px; margin-bottom:22px; }
.ov-hero h1 { margin:0; font-size:26px; font-weight:800; }
.ov-hero p { margin:8px 0 0; opacity:.92; }
.ov-toplinks { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; }
.ov-topbtn, .ov-btn { display:inline-flex; align-items:center; justify-content:center; padding:12px 16px; border-radius:12px; text-decoration:none; font-weight:800; }
.ov-topbtn { background:rgba(255,255,255,.14); color:#fff; border:1px solid rgba(255,255,255,.18); }
.ov-topbtn:hover { background:rgba(255,255,255,.22); }
.ov-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:18px; }
.ov-card { background:#fff; border:1px solid #e2e8f0; border-radius:18px; box-shadow:0 8px 24px rgba(15,23,42,.05); overflow:hidden; }
.ov-head { padding:18px 20px; color:#fff; font-weight:800; font-size:18px; }
.ov-body { padding:20px; }
.ov-body p { color:#475569; min-height:58px; }
.bg-mieter { background:linear-gradient(135deg,#0f766e,#14b8a6); }
.bg-vermieter { background:linear-gradient(135deg,#7c3aed,#a855f7); }
.bg-bkp { background:linear-gradient(135deg,#ea580c,#f59e0b); }
.ov-footerbox { margin-top:18px; background:#fff; border:1px solid #e2e8f0; border-radius:18px; padding:18px 20px; box-shadow:0 8px 24px rgba(15,23,42,.05); }
.ov-footerbox h3 { margin:0 0 10px; color:#0f172a; font-size:18px; }
.ov-footerbox p { margin:0; color:#64748b; }
@media (max-width:980px){ .ov-grid { grid-template-columns:1fr; } }
</style>

<div class="ov-wrap">
    <div class="ov-hero">
        <h1>🗂️ Vorlagen-Zentrale</h1>
        <p>Hier kommst du direkt zu Mietervorlagen, Vermietervorlagen, BKP-Vorlagen und den Vorgangsarten.</p>
        <div class="ov-toplinks">
            <a class="ov-topbtn" href="pendenzen.php">Pendenzen öffnen</a>
            <a class="ov-topbtn" href="vorgangsart_settings.php">Vorgangsarten</a>
            <a class="ov-topbtn" href="bkp_codes.php">BKP direkt</a>
        </div>
    </div>

    <div class="ov-grid">
        <div class="ov-card">
            <div class="ov-head bg-mieter">🏠 Mietervorlagen</div>
            <div class="ov-body">
                <p>Projekt- und objektspezifische Titel-Kategorien und Kurzbeschreibungen für Mieter.</p>
                <a class="ov-btn bg-mieter" href="pendenz_kategorien_mieter.php">Öffnen</a>
            </div>
        </div>

        <div class="ov-card">
            <div class="ov-head bg-vermieter">🏢 Vermietervorlagen</div>
            <div class="ov-body">
                <p>Projekt- und objektspezifische Titel-Kategorien und Kurzbeschreibungen für Vermieter.</p>
                <a class="ov-btn bg-vermieter" href="pendenz_kategorien_vermieter.php">Öffnen</a>
            </div>
        </div>

        <div class="ov-card">
            <div class="ov-head bg-bkp">🧱 Unternehmer / BKP</div>
            <div class="ov-body">
                <p>Zentrale BKP-Struktur für Unternehmer, Bauleiter, Architekt, Fachplaner und Bauingenieur.</p>
                <a class="ov-btn bg-bkp" href="pendenz_kategorien_unternehmer.php">Öffnen</a>
            </div>
        </div>
    </div>

    <div class="ov-footerbox">
        <h3>So greift es jetzt in der Liste</h3>
        <p>In <strong>pendenzen.php</strong> wird die Vorlagen-Welt jetzt über die gewählte Vorgangsart geladen. Danach erscheinen automatisch die passenden Titel und Kurzbeschreibungen aus BKP, Mieter oder Vermieter. Bei Mieter und Vermieter werden zusätzlich Projekt und Objekt berücksichtigt.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
