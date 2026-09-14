<?php
// pages/finanzen.php - Zentrale Finanz- & Zahlungsübersicht mit Barzahlungs-Erfassung
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_superadmin() && !is_admin()) { die("Keine Berechtigung."); }

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

global $mysqli, $db;
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $mysqli = $GLOBALS['mysqli'] ?? $GLOBALS['db'] ?? null;
}

$flash = "";
$flashType = "success";

// ==========================================
// 1. Manuelle Buchung / Barzahlung verarbeiten
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_entry') {
        $wid = (int)($_POST['wohnung_id'] ?? 0);
        $pid = (int)($_POST['projekt_id'] ?? 0);
        $uid = (int)($_POST['benutzer_id'] ?? 0);
        $datum = trim($_POST['datum'] ?? date('Y-m-d'));
        $text = trim($_POST['text'] ?? '');
        $betrag = (float)($_POST['betrag'] ?? 0);
        $typ = $_POST['typ'] ?? 'in'; // 'in' = Einnahme (+), 'out' = Ausgabe (-)
        $zahlungsart = trim($_POST['zahlungsart'] ?? 'Bar');
        $kategorie = trim($_POST['kategorie'] ?? 'Miete');

        if ($betrag <= 0) {
            $flash = "⚠️ Bitte einen Betrag größer als 0.00 CHF eingeben.";
            $flashType = "warning";
        } elseif ($datum === '') {
            $flash = "⚠️ Bitte ein gültiges Buchungsdatum angeben.";
            $flashType = "warning";
        } else {
            $betragSigned = ($typ === 'out') ? -abs($betrag) : abs($betrag);

            // Wohnungsdetails & Projekt ermitteln
            $wLabel = '';
            if ($wid > 0) {
                $wRow = $mysqli->query("SELECT w.name, o.projekt_id FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE w.id = $wid")->fetch_assoc();
                if ($wRow) {
                    $wLabel = $wRow['name'];
                    if ($pid <= 0) $pid = (int)$wRow['projekt_id'];
                }
            }

            // Falls Benutzer-ID angegeben, Existenz prüfen
            if ($uid > 0) {
                $chk = $mysqli->query("SELECT id FROM benutzer WHERE id = $uid");
                if (!$chk || $chk->num_rows === 0) $uid = null;
            } else {
                $uid = null;
            }

            // Bankkonto der Liegenschaft ermitteln (für Referenz)
            $kontoId = null;
            if ($pid > 0) {
                $kRow = $mysqli->query("SELECT id FROM kv_konten WHERE projekt_id = $pid OR liegenschaft_id = $pid LIMIT 1")->fetch_assoc();
                if ($kRow) $kontoId = (int)$kRow['id'];
            }

            if ($text === '') {
                $text = ($typ === 'in' ? 'Mietzahlung ' : 'Auslage ') . htmlspecialchars($zahlungsart) . ' ' . date('m/Y', strtotime($datum));
            }

            // In liegenschafts_konto einfügen (Live-Buchhaltung & Mietkontrolle!)
            $st = $mysqli->prepare("INSERT INTO liegenschafts_konto 
                (konto_id, projekt_id, liegenschaft_id, buchungsdatum, betrag, beschreibung, kategorie, zahlungsart, wohnung_id, mieter_id, wohnung_label) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->bind_param("iiisdsssiss", $kontoId, $pid, $pid, $datum, $betragSigned, $text, $kategorie, $zahlungsart, $wid, $uid, $wLabel);
            $st->execute();
            $newBookingId = $st->insert_id;
            $st->close();

            // Legacy-Sicherung in finanzen_konto
            $sollVal = ($betragSigned < 0) ? abs($betragSigned) : 0;
            $habenVal = ($betragSigned > 0) ? $betragSigned : 0;
            $st2 = $mysqli->prepare("INSERT INTO finanzen_konto (wohnung_id, benutzer_id, datum, text, soll, haben) VALUES (?,?,?,?,?,?)");
            $st2->bind_param("iissdd", $wid, $uid, $datum, $text, $sollVal, $habenVal);
            $st2->execute();
            $st2->close();

            $flash = "✅ Buchung #$newBookingId über CHF " . number_format(abs($betragSigned), 2, '.', "'") . " ($zahlungsart) erfolgreich gespeichert! Die Mietkontrolle wurde sofort aktualisiert.";
            $flashType = "success";
        }
    }
}

// ==========================================
// 2. Stammdaten für Dropdowns laden
// ==========================================
$projekte = [];
$resP = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
while ($r = $resP->fetch_assoc()) $projekte[] = $r;

// Wohnungen inkl. Soll-Mieten und aktiven Mietern
$allWohnungen = [];
$resW = $mysqli->query("
    SELECT w.id, w.name as wohnung_name, 
           COALESCE(w.mietzins_netto_soll,0) as netto_soll, 
           COALESCE(w.mietzins_nk_soll,0) as nk_soll,
           (COALESCE(w.mietzins_netto_soll,0) + COALESCE(w.mietzins_nk_soll,0)) as total_soll,
           o.projekt_id, p.name as projekt_name,
           wm.mieter_name, wm.benutzer_id
    FROM wohnungen w
    LEFT JOIN objekte o ON w.objekt_id = o.id
    LEFT JOIN projekte p ON o.projekt_id = p.id
    LEFT JOIN wohnung_mieter wm ON (wm.wohnung_id = w.id AND wm.status = 'aktiv')
    ORDER BY p.name ASC, w.name ASC
");
while ($r = $resW->fetch_assoc()) $allWohnungen[] = $r;

$allMieter = [];
$resM = $mysqli->query("SELECT id, name FROM benutzer WHERE rolle='benutzer' ORDER BY name ASC");
while ($r = $resM->fetch_assoc()) $allMieter[] = $r;

// ==========================================
// 3. Finanz-Kennzahlen (Portfolio Executive KPIs)
// ==========================================
$curMonth = (int)date('n');
$curYear = (int)date('Y');

// Soll-Mietertrag pro Monat aus allen Wohnungen
$sollRow = $mysqli->query("SELECT SUM(COALESCE(mietzins_netto_soll,0) + COALESCE(mietzins_nk_soll,0)) as soll_gesamt FROM wohnungen")->fetch_assoc();
$kpiSollMonat = (float)($sollRow['soll_gesamt'] ?? 0);

// Ist-Mieteingänge im aktuellen Monat aus liegenschafts_konto
$istRow = $mysqli->query("SELECT SUM(betrag) as ist_monat FROM liegenschafts_konto WHERE betrag > 0 AND YEAR(buchungsdatum) = $curYear AND MONTH(buchungsdatum) = $curMonth")->fetch_assoc();
$kpiIstMonat = (float)($istRow['ist_monat'] ?? 0);

// Gesamt-Saldo aller Liegenschaften
$saldoRow = $mysqli->query("SELECT 
    COALESCE(SUM(betrag),0) as saldo_gesamt,
    COALESCE(SUM(CASE WHEN betrag > 0 THEN betrag ELSE 0 END),0) as einnahmen_gesamt,
    COALESCE(SUM(CASE WHEN betrag < 0 THEN betrag ELSE 0 END),0) as ausgaben_gesamt,
    COUNT(*) as buchungen_gesamt
    FROM liegenschafts_konto")->fetch_assoc();
$kpiSaldoGesamt = (float)($saldoRow['saldo_gesamt'] ?? 0);
$kpiTotalBuchungen = (int)($saldoRow['buchungen_gesamt'] ?? 0);

// Barzahlungen Anzahl & Summe
$barRow = $mysqli->query("SELECT COUNT(*) as bar_cnt, COALESCE(SUM(betrag),0) as bar_sum FROM liegenschafts_konto WHERE zahlungsart = 'Bar' OR beschreibung LIKE '%Barzahlung%'")->fetch_assoc();
$kpiBarCnt = (int)($barRow['bar_cnt'] ?? 0);
$kpiBarSum = (float)($barRow['bar_sum'] ?? 0);

// ==========================================
// 4. Filter & Buchungsliste laden
// ==========================================
$filterProjekt = (int)($_GET['projekt_id'] ?? 0);
$filterZahlung = trim($_GET['zahlungsart'] ?? 'all');
$filterSuchtext = trim($_GET['q'] ?? '');

$where = [];
if ($filterProjekt > 0) {
    $where[] = "(k.liegenschaft_id = $filterProjekt OR k.projekt_id = $filterProjekt)";
}
if ($filterZahlung === 'Bar') {
    $where[] = "(k.zahlungsart = 'Bar' OR k.beschreibung LIKE '%Bar%')";
} elseif ($filterZahlung === 'Bank') {
    $where[] = "(k.zahlungsart != 'Bar' AND (k.zahlungsart IS NOT NULL OR k.beschreibung NOT LIKE '%Bar%'))";
} elseif ($filterZahlung === 'Twint') {
    $where[] = "(k.zahlungsart = 'Twint' OR k.beschreibung LIKE '%Twint%')";
}
if ($filterSuchtext !== '') {
    $safeQ = $mysqli->real_escape_string($filterSuchtext);
    $where[] = "(k.beschreibung LIKE '%$safeQ%' OR k.wohnung_label LIKE '%$safeQ%')";
}

$whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

$entries = $mysqli->query("
    SELECT k.*, 
           w.name AS wohnung_name, 
           p.name AS projekt_name,
           COALESCE(NULLIF(wm.mieter_name,''), b.name, k.wohnung_label) AS mieter_display_name
    FROM liegenschafts_konto k 
    LEFT JOIN wohnungen w ON w.id = k.wohnung_id 
    LEFT JOIN objekte o ON w.objekt_id = o.id 
    LEFT JOIN projekte p ON (k.liegenschaft_id = p.id OR k.projekt_id = p.id OR o.projekt_id = p.id)
    LEFT JOIN benutzer b ON b.id = k.mieter_id 
    LEFT JOIN wohnung_mieter wm ON (wm.wohnung_id = k.wohnung_id AND wm.status = 'aktiv')
    $whereSql
    GROUP BY k.id
    ORDER BY k.buchungsdatum DESC, k.id DESC 
    LIMIT 60
");

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
/* Scoped Styles für Finanzen / Zahlungszentrale */
.fin-container {
    max-width: 1540px;
    margin: 20px auto;
    padding: 0 16px;
}
.fin-header {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    border-radius: 14px;
    padding: 24px 28px;
    color: #fff;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}
.fin-header h1 {
    margin: 0 0 6px 0;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.fin-header p {
    margin: 0;
    color: #94a3b8;
    font-size: 14px;
}
.fin-header-nav {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.fin-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
}
.fin-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}
.fin-btn-emerald { background: #10b981; color: #fff; }
.fin-btn-blue { background: #3b82f6; color: #fff; }
.fin-btn-slate { background: #334155; color: #f8fafc; border: 1px solid #475569; }

/* KPI Grid */
.fin-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.fin-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.fin-kpi-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    color: #64748b;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.fin-kpi-val {
    font-size: 24px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: #0f172a;
}

/* Layout Split */
.fin-layout {
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 20px;
    align-items: flex-start;
}
@media (max-width: 1100px) {
    .fin-layout { grid-template-columns: 1fr; }
}

.fin-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}
.fin-panel-title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 16px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Form Styles */
.fin-form label {
    display: block;
    margin-bottom: 12px;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
}
.fin-form input[type="text"],
.fin-form input[type="date"],
.fin-form input[type="number"],
.fin-form select {
    width: 100%;
    margin-top: 4px;
    padding: 9px 12px;
    border: 1.5px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    background: #fff;
    color: #1e293b;
    box-sizing: border-box;
}
.fin-form input:focus,
.fin-form select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.fin-pill-radio-group {
    display: flex;
    gap: 6px;
    margin-top: 5px;
    flex-wrap: wrap;
}
.fin-pill-radio {
    flex: 1;
    min-width: 85px;
    text-align: center;
    border: 1.5px solid #cbd5e1;
    padding: 7px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    background: #f8fafc;
    user-select: none;
    transition: all 0.15s ease;
}
.fin-pill-radio input { display: none; }
.fin-pill-radio.active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1d4ed8;
}

/* Table */
.fin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.fin-table thead th {
    background: #f8fafc;
    padding: 10px 12px;
    text-align: left;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 2px solid #e2e8f0;
}
.fin-table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.fin-table tbody tr:hover {
    background: #f8fafc;
}
.badge-pay {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
}
.badge-pay-bar { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-pay-bank { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }
.badge-pay-twint { background: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; }
</style>

<div class="fin-container">
    <header class="fin-header">
        <div>
            <h1>📊 Finanzen &amp; Zahlungs-Cockpit</h1>
            <p>Mieteinnahmen, Barzahlungen, Kontobewegungen und Soll/Ist-Vergleich aller Liegenschaften</p>
        </div>
        <div class="fin-header-nav">
            <a href="<?= e(url('tools/mietkontrolle/index.php')) ?>" class="fin-btn fin-btn-emerald">💰 Zur Mietkontrolle</a>
            <a href="<?= e(url('tools/konto_verwaltung/index.php')) ?>" class="fin-btn fin-btn-blue">🏦 Bankkonto &amp; CSV-Import</a>
            <a href="<?= e(url('pages/mieterspiegel.php')) ?>" class="fin-btn fin-btn-slate">📋 Mieterspiegel</a>
        </div>
    </header>

    <?php if($flash): ?>
        <div style="background:<?= $flashType==='success' ? '#ecfdf5' : '#fffbeb' ?>; border-left:4px solid <?= $flashType==='success' ? '#10b981' : '#f59e0b' ?>; padding:12px 18px; border-radius:8px; margin-bottom:18px; font-weight:700; color:<?= $flashType==='success' ? '#065f46' : '#92400e' ?>;">
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <!-- Executive KPI Grid -->
    <div class="fin-kpi-grid">
        <div class="fin-kpi-card" style="border-top:3px solid #3b82f6;">
            <div class="fin-kpi-title">Soll-Mietertrag (Monat)</div>
            <div class="fin-kpi-val" style="color:#2563eb;">
                CHF <?= number_format($kpiSollMonat, 2, '.', "'") ?>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:3px;">
                Über alle <?= count($allWohnungen) ?> Einheiten
            </div>
        </div>

        <div class="fin-kpi-card" style="border-top:3px solid #10b981;">
            <div class="fin-kpi-title">Ist-Eingänge (<?= date('F Y') ?>)</div>
            <div class="fin-kpi-val" style="color:#10b981;">
                +CHF <?= number_format($kpiIstMonat, 2, '.', "'") ?>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:3px;">
                Echte Eingänge in diesem Monat
            </div>
        </div>

        <div class="fin-kpi-card" style="border-top:3px solid #d97706;">
            <div class="fin-kpi-title">Erfasste Barzahlungen 💵</div>
            <div class="fin-kpi-val" style="color:#b45309;">
                CHF <?= number_format($kpiBarSum, 2, '.', "'") ?>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:3px;">
                <?= $kpiBarCnt ?> Bar- &amp; Twint-Eingänge erfasst
            </div>
        </div>

        <div class="fin-kpi-card" style="border-top:3px solid <?= $kpiSaldoGesamt >= 0 ? '#10b981' : '#dc2626' ?>;">
            <div class="fin-kpi-title">Gesamtsaldo Konten</div>
            <div class="fin-kpi-val" style="color:<?= $kpiSaldoGesamt >= 0 ? '#10b981' : '#dc2626' ?>;">
                <?= ($kpiSaldoGesamt >= 0 ? '+' : '') . number_format($kpiSaldoGesamt, 2, '.', "'") ?> <span style="font-size:14px; font-weight:600;">CHF</span>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:3px;">
                <?= number_format($kpiTotalBuchungen, 0, ',', "'") ?> Buchungen verbucht
            </div>
        </div>
    </div>

    <!-- Hauptbereich: Letzte Buchungen (links) & Barzahlung/Schnellbuchung (rechts) -->
    <div class="fin-layout">
        <!-- Links: Echte Transaktions-Liste aus liegenschafts_konto -->
        <div class="fin-panel">
            <div class="fin-panel-title">
                <span>📋 Letzte Kontobewegungen &amp; Zahlungen</span>
                <span style="font-size:12px; font-weight:600; color:#64748b;">(Zeigt die neuesten 60 Einträge)</span>
            </div>

            <!-- Schnellfilter Bar / Liegenschaft -->
            <form method="get" style="display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; align-items:center; background:#f8fafc; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0;">
                <select name="projekt_id" onchange="this.form.submit()" style="padding:6px 10px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; font-weight:600;">
                    <option value="0">🌐 Alle Liegenschaften</option>
                    <?php foreach ($projekte as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $filterProjekt===(int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="zahlungsart" onchange="this.form.submit()" style="padding:6px 10px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; font-weight:600;">
                    <option value="all">Alle Zahlungsarten</option>
                    <option value="Bar" <?= $filterZahlung==='Bar' ? 'selected' : '' ?>>💵 Nur Barzahlungen</option>
                    <option value="Bank" <?= $filterZahlung==='Bank' ? 'selected' : '' ?>>🏦 Nur Banküberweisungen</option>
                    <option value="Twint" <?= $filterZahlung==='Twint' ? 'selected' : '' ?>>📱 Nur Twint</option>
                </select>

                <input type="text" name="q" value="<?= htmlspecialchars($filterSuchtext) ?>" placeholder="🔍 Suche..." style="padding:6px 10px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px; flex:1; min-width:140px;">
                
                <button type="submit" style="padding:6px 12px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer;">Filter</button>
                <?php if ($filterProjekt || $filterZahlung!=='all' || $filterSuchtext): ?>
                    <a href="<?= e(url('pages/finanzen.php')) ?>" style="font-size:12px; color:#64748b; text-decoration:none; padding:4px 8px;">⟲ Reset</a>
                <?php endif; ?>
            </form>

            <div style="overflow-x:auto;">
                <table class="fin-table">
                    <thead>
                        <tr>
                            <th style="width:85px;">Datum</th>
                            <th>Einheit / Mieter</th>
                            <th>Buchungstext</th>
                            <th style="width:95px; text-align:center;">Art</th>
                            <th style="width:115px; text-align:right;">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($entries && $entries->num_rows > 0): ?>
                            <?php while($e = $entries->fetch_assoc()): 
                                $betrag = (float)$e['betrag'];
                                $isPos = $betrag >= 0;
                                $zArt = trim((string)$e['zahlungsart']);
                                if ($zArt === '' && stripos($e['beschreibung'], 'Bar') !== false) $zArt = 'Bar';
                            ?>
                                <tr>
                                    <td style="white-space:nowrap; font-weight:700; color:#334155;">
                                        <?= date('d.m.Y', strtotime($e['buchungsdatum'])) ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:#0f172a;">
                                            <?= htmlspecialchars($e['wohnung_name'] ?: ($e['wohnung_label'] ?: '—')) ?>
                                        </div>
                                        <div style="font-size:11px; color:#64748b;">
                                            <?= htmlspecialchars($e['mieter_display_name'] ?: ($e['projekt_name'] ?: 'Kein Mieter')) ?>
                                        </div>
                                    </td>
                                    <td style="color:#334155; font-size:12px; max-width:280px; word-break:break-word;" title="<?= htmlspecialchars($e['beschreibung']) ?>">
                                        <?= htmlspecialchars($e['beschreibung']) ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if ($zArt === 'Bar'): ?>
                                            <span class="badge-pay badge-pay-bar">💵 Bar</span>
                                        <?php elseif ($zArt === 'Twint'): ?>
                                            <span class="badge-pay badge-pay-twint">📱 Twint</span>
                                        <?php else: ?>
                                            <span class="badge-pay badge-pay-bank">🏦 Bank</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; font-family:monospace; font-weight:800; font-size:13px; color:<?= $isPos ? '#16a34a' : '#dc2626' ?>; white-space:nowrap;">
                                        <?= ($isPos ? '+' : '') . number_format($betrag, 2, '.', "'") ?> CHF
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:35px; color:#94a3b8; font-size:13px;">
                                    Keine passenden Buchungen gefunden.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Rechts: Schnell-Erfassung (Barzahlung, Twint, Einzahlung) -->
        <aside class="fin-panel" style="border-top:4px solid #10b981;">
            <div class="fin-panel-title">
                <span>⚡ Zahlung erfassen (Bar / Manuell)</span>
            </div>

            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:10px 12px; font-size:11px; color:#166534; margin-bottom:14px; line-height:1.4;">
                💡 <strong>Tipp für Barzahler:</strong> Hier erfasste Barzahlungen fließen <strong>sofort in die Mietkontrolle</strong> und die Liegenschaftsbuchhaltung ein!
            </div>

            <form method="post" class="fin-form" id="manualBookingForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_entry">

                <!-- 1. Liegenschaft -->
                <label>1. Liegenschaft
                    <select name="projekt_id" id="sel_projekt" onchange="filterUnitsByProject(this.value)" required>
                        <option value="">— Liegenschaft auswählen —</option>
                        <?php foreach ($projekte as $p): ?>
                            <option value="<?= (int)$p['id'] ?>" <?= $filterProjekt===(int)$p['id'] ? 'selected' : '' ?>>
                                🏠 <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- 2. Wohnung / Einheit -->
                <label>2. Wohnung / Einheit
                    <select name="wohnung_id" id="sel_wohnung" onchange="onUnitChanged(this.value)" required>
                        <option value="">— Erst Liegenschaft wählen —</option>
                        <?php foreach ($allWohnungen as $w): ?>
                            <option value="<?= (int)$w['id'] ?>" 
                                    data-pid="<?= (int)$w['projekt_id'] ?>"
                                    data-soll="<?= (float)$w['total_soll'] ?>"
                                    data-mid="<?= (int)$w['benutzer_id'] ?>"
                                    data-mname="<?= htmlspecialchars($w['mieter_name'] ?? '') ?>">
                                <?= htmlspecialchars($w['wohnung_name']) ?> (Soll: CHF <?= number_format((float)$w['total_soll'], 2, '.', "'") ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- 3. Mieter -->
                <label>3. Mieter (wird automatisch ermittelt)
                    <select name="benutzer_id" id="sel_mieter">
                        <option value="">— Kein Mieter / Frei —</option>
                        <?php foreach ($allMieter as $m): ?>
                            <option value="<?= (int)$m['id'] ?>">👤 <?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <!-- 4. Zahlungsart Pills -->
                <label>4. Zahlungsart</label>
                <div class="fin-pill-radio-group">
                    <label class="fin-pill-radio active" onclick="setPayMethod('Bar', this)">
                        <input type="radio" name="zahlungsart" value="Bar" checked> 💵 Bar
                    </label>
                    <label class="fin-pill-radio" onclick="setPayMethod('Twint', this)">
                        <input type="radio" name="zahlungsart" value="Twint"> 📱 Twint
                    </label>
                    <label class="fin-pill-radio" onclick="setPayMethod('Bank', this)">
                        <input type="radio" name="zahlungsart" value="Bank"> 🏦 Bank
                    </label>
                </div>

                <!-- 5. Einnahme (+) oder Ausgabe (-) -->
                <div style="display:flex; gap:10px; margin-top:10px;">
                    <label style="flex:1;">Buchungstyp
                        <select name="typ" id="sel_typ" onchange="updateSuggestedText()">
                            <option value="in" selected>🟢 Mieteinnahme (+)</option>
                            <option value="out">🔴 Ausgabe / Barauslage (-)</option>
                        </select>
                    </label>
                    <label style="flex:1;">Kategorie
                        <select name="kategorie">
                            <option value="Miete" selected>Miete</option>
                            <option value="Nebenkosten">Nebenkosten</option>
                            <option value="Kaution">Kaution</option>
                            <option value="Handwerker / Reparatur">Handwerker</option>
                            <option value="Sonstiges">Sonstiges</option>
                        </select>
                    </label>
                </div>

                <!-- 6. Betrag & Datum -->
                <div style="display:flex; gap:10px; margin-top:4px;">
                    <label style="flex:1.2;">Betrag (CHF)
                        <input type="number" step="0.05" min="0.05" name="betrag" id="inp_betrag" placeholder="0.00" required style="font-weight:800; font-size:15px; color:#166534;">
                    </label>
                    <label style="flex:1;">Datum
                        <input type="date" name="datum" id="inp_datum" value="<?= date('Y-m-d') ?>" required>
                    </label>
                </div>

                <!-- 7. Buchungstext -->
                <label style="margin-top:4px;">Buchungstext / Verwendungszweck
                    <input type="text" name="text" id="inp_text" placeholder="z.B. Miete <?= date('m/Y') ?> bar erhalten" required>
                </label>

                <button type="submit" class="fin-btn fin-btn-emerald" style="width:100%; justify-content:center; padding:12px; margin-top:14px; font-size:14px;">
                    💾 Zahlung jetzt verbuchen
                </button>
            </form>
        </aside>
    </div>
</div>

<script>
// Wohnungs-Objekte für dynamische Filterung
const allUnits = Array.from(document.querySelectorAll('#sel_wohnung option')).map(opt => ({
    id: opt.value,
    text: opt.textContent,
    pid: opt.getAttribute('data-pid') || '',
    soll: opt.getAttribute('data-soll') || '0',
    mid: opt.getAttribute('data-mid') || '',
    mname: opt.getAttribute('data-mname') || ''
}));

function filterUnitsByProject(pid) {
    const selW = document.getElementById('sel_wohnung');
    selW.innerHTML = '<option value="">— Wohnung auswählen —</option>';
    
    const filtered = pid ? allUnits.filter(u => u.pid === pid || u.id === '') : allUnits;
    filtered.forEach(u => {
        if (!u.id) return;
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.text;
        opt.setAttribute('data-soll', u.soll);
        opt.setAttribute('data-mid', u.mid);
        opt.setAttribute('data-mname', u.mname);
        selW.appendChild(opt);
    });

    // Reset Betrag und Mieter
    document.getElementById('inp_betrag').value = '';
    document.getElementById('sel_mieter').value = '';
    updateSuggestedText();
}

function onUnitChanged(wid) {
    if (!wid) return;
    const opt = document.querySelector('#sel_wohnung option[value="' + wid + '"]');
    if (!opt) return;

    const soll = parseFloat(opt.getAttribute('data-soll') || 0);
    const mid = opt.getAttribute('data-mid');
    
    // Betrag vorausfüllen wenn noch leer oder Miete
    if (soll > 0) {
        document.getElementById('inp_betrag').value = soll.toFixed(2);
    }
    
    // Mieter vorauswählen
    if (mid) {
        document.getElementById('sel_mieter').value = mid;
    }
    
    updateSuggestedText();
}

function setPayMethod(method, element) {
    document.querySelectorAll('.fin-pill-radio').forEach(el => el.classList.remove('active'));
    element.classList.add('active');
    element.querySelector('input').checked = true;
    updateSuggestedText();
}

function updateSuggestedText() {
    const selW = document.getElementById('sel_wohnung');
    const selectedUnitText = selW.options[selW.selectedIndex]?.text?.split('(')[0]?.trim() || '';
    const typ = document.getElementById('sel_typ').value;
    const method = document.querySelector('input[name="zahlungsart"]:checked')?.value || 'Bar';
    const dateVal = document.getElementById('inp_datum').value;
    let dStr = '';
    if (dateVal) {
        const parts = dateVal.split('-');
        if (parts.length === 3) dStr = parts[1] + '/' + parts[0];
    }
    
    const textInput = document.getElementById('inp_text');
    if (typ === 'in') {
        textInput.value = 'Mietzahlung ' + (dStr ? dStr : '') + ' (' + method + ')' + (selectedUnitText ? ' - ' + selectedUnitText : '');
    } else {
        textInput.value = 'Barauslage ' + (dStr ? dStr : '') + (selectedUnitText ? ' - ' + selectedUnitText : '');
    }
}

// Initialer Filter falls Liegenschaft vorgewählt ist
document.addEventListener('DOMContentLoaded', () => {
    const initialPid = document.getElementById('sel_projekt').value;
    if (initialPid) {
        filterUnitsByProject(initialPid);
    }
    updateSuggestedText();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
