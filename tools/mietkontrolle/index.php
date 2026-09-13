<?php
// tools/mietkontrolle/index.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/authz.php';
require_once __DIR__ . '/../../includes/functions.php';
require_login();

$role = $_SESSION['rolle'] ?? '';
if (!in_array($role, ['admin', 'superadmin'])) {
    die("Zugriff verweigert (nur Admin/Superadmin).");
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Parameter
$pid = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
$selMonth = isset($_GET['monat']) && preg_match('/^\d{4}-\d{2}$/', $_GET['monat']) ? $_GET['monat'] : date('Y-m');
$viewMode = $_GET['view'] ?? 'month'; // 'month' oder 'year'
$selYear = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)substr($selMonth, 0, 4);

$firstDay = $selMonth . '-01';
$lastDay = date('Y-m-t', strtotime($firstDay));

// Vorheriger / Nächster Monat
$prevMonth = date('Y-m', strtotime($firstDay . ' -1 month'));
$nextMonth = date('Y-m', strtotime($firstDay . ' +1 month'));

// Projekte für Dropdown
$projekte = [];
$resP = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
if ($resP) while ($r = $resP->fetch_assoc()) $projekte[] = $r;

// 1. Alle Einheiten für das Projekt (oder alle) laden
$whgSql = "
    SELECT w.id as wohnung_id, w.name as wohnung_name, w.flaeche, w.zimmer,
           w.mietzins_netto_soll, w.mietzins_nk_soll,
           o.id as objekt_id, o.name as objekt_name, o.projekt_id,
           p.name as projekt_name
    FROM wohnungen w
    JOIN objekte o ON w.objekt_id = o.id
    JOIN projekte p ON o.projekt_id = p.id
";
if ($pid > 0) {
    $whgSql .= " WHERE p.id = $pid";
}
$whgSql .= " ORDER BY p.name, o.name, w.name";
$resW = $mysqli->query($whgSql);
$wohnungen = [];
if ($resW) while ($w = $resW->fetch_assoc()) {
    $wohnungen[$w['wohnung_id']] = $w;
}

// 2. Mieter-Verträge laden
$mieterSql = "
    SELECT wm.id as wm_id, wm.wohnung_id, wm.benutzer_id, wm.mieter_name,
           wm.mietzins_netto, wm.nk_akonto, wm.startdatum, wm.enddatum, wm.rolle,
           b.telefonnummer, b.email
    FROM wohnung_mieter wm
    LEFT JOIN benutzer b ON wm.benutzer_id = b.id
    WHERE (wm.enddatum IS NULL OR wm.enddatum >= '$firstDay')
      AND (wm.startdatum IS NULL OR wm.startdatum <= '$lastDay')
    ORDER BY wm.startdatum DESC, wm.id DESC
";
$resM = $mysqli->query($mieterSql);
$activeTenants = [];
if ($resM) while ($m = $resM->fetch_assoc()) {
    $wid = (int)$m['wohnung_id'];
    if (!isset($activeTenants[$wid])) {
        $activeTenants[$wid] = $m;
    }
}

// 3. Buchungen aus liegenschafts_konto für den Monat laden
$kontoSql = "
    SELECT id, liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, kategorie,
           wohnung_id, mieter_id
    FROM liegenschafts_konto
    WHERE betrag > 0
      AND buchungsdatum BETWEEN '$firstDay' AND '$lastDay'
";
if ($pid > 0) {
    $kontoSql .= " AND (liegenschaft_id = $pid OR projekt_id = $pid)";
}
$resK = $mysqli->query($kontoSql);
$kontoRows = [];
if ($resK) while ($k = $resK->fetch_assoc()) {
    $kontoRows[] = $k;
}

// 4. Monatliche Abgleich-Berechnung pro Wohnung
$reconciliation = [];
$totSoll = 0.0;
$totIst = 0.0;
$cntBezahlt = 0;
$cntTeil = 0;
$cntOffen = 0;

foreach ($wohnungen as $wid => $w) {
    $tenant = $activeTenants[$wid] ?? null;

    // Soll-Miete
    if ($tenant) {
        $netto = (float)($tenant['mietzins_netto'] ?? 0);
        $nk = (float)($tenant['nk_akonto'] ?? 0);
        $soll = $netto + $nk;
        if ($soll <= 0) {
            $soll = (float)$w['mietzins_netto_soll'] + (float)$w['mietzins_nk_soll'];
        }
        $mieterName = $tenant['mieter_name'];
    } else {
        $soll = (float)$w['mietzins_netto_soll'] + (float)$w['mietzins_nk_soll'];
        $mieterName = 'Leerstand';
    }

    // Ist-Zahlung aus liegenschafts_konto ermitteln
    $ist = 0.0;
    $matchedPayments = [];

    foreach ($kontoRows as $k) {
        $isMatch = false;

        // 1. Direkte Zuweisung über wohnung_id
        if ((int)$k['wohnung_id'] === $wid) {
            $isMatch = true;
        }
        // 2. Direkte Zuweisung über mieter_id
        elseif ($tenant && !empty($tenant['benutzer_id']) && (int)$k['mieter_id'] === (int)$tenant['benutzer_id']) {
            $isMatch = true;
        }
        // 3. Namensabgleich im Buchungstext
        elseif ($tenant && !empty($tenant['mieter_name'])) {
            $words = preg_split('/[\s,\/&]+/', $tenant['mieter_name']);
            foreach ($words as $word) {
                $word = trim($word);
                if (mb_strlen($word) >= 4 && stripos($k['beschreibung'], $word) !== false) {
                    $isMatch = true;
                    break;
                }
            }
        }

        if ($isMatch) {
            $ist += (float)$k['betrag'];
            $matchedPayments[] = $k;
        }
    }

    $diff = round($ist - $soll, 2);
    $totSoll += $soll;
    $totIst += $ist;

    if ($soll > 0) {
        if ($ist >= $soll) {
            $status = ($diff > 0) ? 'overpaid' : 'paid';
            $cntBezahlt++;
        } elseif ($ist > 0) {
            $status = 'partial';
            $cntTeil++;
        } else {
            $status = 'open';
            $cntOffen++;
        }
    } else {
        $status = ($ist > 0) ? 'paid' : 'empty';
    }

    $reconciliation[] = [
        'wohnung_id' => $wid,
        'wohnung_name' => $w['wohnung_name'],
        'objekt_name' => $w['objekt_name'],
        'projekt_name' => $w['projekt_name'],
        'tenant' => $tenant,
        'mieter_name' => $mieterName,
        'soll' => $soll,
        'ist' => $ist,
        'diff' => $diff,
        'status' => $status,
        'payments' => $matchedPayments
    ];
}

$totOffen = max(0, $totSoll - $totIst);
$quote = ($totSoll > 0) ? round(($totIst / $totSoll) * 100, 1) : 100;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/nav_dispatch.php';
?>

<style>
.mk-wrap { max-width: 1400px; margin: 24px auto; padding: 0 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.mk-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
.kpi-card { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
.kpi-title { font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-bottom: 6px; }
.kpi-val { font-size: 24px; font-weight: 700; color: #0f172a; }

.badge-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
.badge-paid { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.badge-partial { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
.badge-open { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.badge-overpaid { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-empty { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

.filter-bar { background: #fff; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
.table-mk { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.table-mk th { background: #0f172a; color: #fff; padding: 12px 16px; text-align: left; font-size: 13px; font-weight: 600; }
.table-mk td { padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
.table-mk tr:hover { background: #f8fafc; }

.btn-nav { padding: 8px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; text-decoration: none; color: #334155; font-size: 13px; font-weight: 500; }
.btn-nav:hover { background: #f1f5f9; }
.btn-action { padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; cursor: pointer; border: none; }
</style>

<div class="mk-wrap">
  <div class="mk-header">
    <div>
      <h1 style="margin:0; font-size:24px; color:#0f172a;">📊 Liegenschafts-Mietkontrolle</h1>
      <p style="margin:4px 0 0; color:#64748b; font-size:14px;">Automatische Abstimmung zwischen Bankauszug (CSV) und Mietzins-Soll</p>
    </div>
    <div style="display:flex; gap:10px;">
      <button onclick="window.print()" class="btn-nav">🖨️ Drucken / PDF</button>
      <a href="../konto_verwaltung/import.php" class="btn-nav" style="background:#2563eb; color:#fff; border-color:#2563eb;">📥 Bank-CSV importieren</a>
      <a href="../konto_verwaltung/index.php" class="btn-nav">💳 Konto-Verwaltung</a>
    </div>
  </div>

  <!-- Filterleiste -->
  <form method="get" class="filter-bar">
    <div>
      <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">PROJEKT / LIEGENSCHAFT</label>
      <select name="projekt_id" onchange="this.form.submit()" style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px;">
        <option value="0">-- Alle Projekte / Liegenschaften --</option>
        <?php foreach ($projekte as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label style="font-size:12px; font-weight:600; color:#64748b; display:block; margin-bottom:4px;">KONTROLL-MONAT</label>
      <div style="display:flex; gap:6px; align-items:center;">
        <a href="?projekt_id=<?= $pid ?>&monat=<?= $prevMonth ?>" class="btn-nav" title="Vorheriger Monat">◀</a>
        <input type="month" name="monat" value="<?= htmlspecialchars($selMonth) ?>" onchange="this.form.submit()" style="padding:7px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px;">
        <a href="?projekt_id=<?= $pid ?>&monat=<?= $nextMonth ?>" class="btn-nav" title="Nächster Monat">▶</a>
      </div>
    </div>

    <div style="margin-left:auto; display:flex; gap:8px;">
      <a href="?projekt_id=<?= $pid ?>&monat=<?= date('Y-m') ?>" class="btn-nav">Heute</a>
    </div>
  </form>

  <!-- KPI Kacheln -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-title">Soll-Miete Gesamt</div>
      <div class="kpi-val" style="color:#0f172a;">CHF <?= number_format($totSoll, 2, '.', "'") ?></div>
      <div style="font-size:12px; color:#64748b; margin-top:4px;">Monat <?= date('m/Y', strtotime($firstDay)) ?></div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Ist-Eingang (Bank)</div>
      <div class="kpi-val" style="color:#16a34a;">CHF <?= number_format($totIst, 2, '.', "'") ?></div>
      <div style="font-size:12px; color:#16a34a; margin-top:4px;">Eingegangene Zahlungen</div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Ausstehend (Offen)</div>
      <div class="kpi-val" style="color:<?= $totOffen > 0 ? '#dc2626' : '#16a34a' ?>;">
        CHF <?= number_format($totOffen, 2, '.', "'") ?>
      </div>
      <div style="font-size:12px; color:<?= $totOffen > 0 ? '#dc2626' : '#64748b' ?>; margin-top:4px;">
        <?= $cntOffen ?> Einheit(en) ohne Zahlung
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-title">Zahlungsquote</div>
      <div class="kpi-val" style="color:<?= $quote >= 95 ? '#16a34a' : ($quote >= 70 ? '#d97706' : '#dc2626') ?>;">
        <?= $quote ?> %
      </div>
      <div style="font-size:12px; color:#64748b; margin-top:4px;">
        <?= $cntBezahlt ?> Bezahlt | <?= $cntTeil ?> Teil | <?= $cntOffen ?> Offen
      </div>
    </div>
  </div>

  <!-- Tabelle -->
  <table class="table-mk">
    <thead>
      <tr>
        <th style="width:110px;">Status</th>
        <th>Objekt / Einheit</th>
        <th>Mieter</th>
        <th style="text-align:right;">Soll (CHF)</th>
        <th style="text-align:right;">Ist (CHF)</th>
        <th style="text-align:right;">Saldo (CHF)</th>
        <th style="min-width:240px;">Bankbuchung / Details</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($reconciliation)): ?>
        <tr>
          <td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;">
            Keine Einheiten oder Mietverhältnisse für die gewählte Auswahl gefunden.
          </td>
        </tr>
      <?php else: foreach ($reconciliation as $r): ?>
        <tr>
          <td>
            <?php if ($r['status'] === 'paid'): ?>
              <span class="badge-status badge-paid">🟢 Bezahlt</span>
            <?php elseif ($r['status'] === 'overpaid'): ?>
              <span class="badge-status badge-overpaid">🔵 +<?= number_format($r['diff'], 2) ?></span>
            <?php elseif ($r['status'] === 'partial'): ?>
              <span class="badge-status badge-partial">🟡 Teilzahlung</span>
            <?php elseif ($r['status'] === 'open'): ?>
              <span class="badge-status badge-open">🔴 Offen</span>
            <?php else: ?>
              <span class="badge-status badge-empty">⚪ Leer</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="../pages/wohnung_detail.php?id=<?= $r['wohnung_id'] ?>" style="font-weight:600; color:#2563eb; text-decoration:none;">
              <?= htmlspecialchars($r['wohnung_name']) ?>
            </a>
            <div style="font-size:12px; color:#64748b;"><?= htmlspecialchars($r['objekt_name']) ?></div>
          </td>
          <td>
            <strong><?= htmlspecialchars($r['mieter_name']) ?></strong>
            <?php if (!empty($r['tenant']['telefonnummer'])): ?>
              <div style="font-size:12px; color:#64748b;">📞 <?= htmlspecialchars($r['tenant']['telefonnummer']) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:right; font-weight:600; color:#334155;">
            <?= number_format($r['soll'], 2, '.', "'") ?>
          </td>
          <td style="text-align:right; font-weight:600; color:<?= $r['ist'] >= $r['soll'] ? '#16a34a' : ($r['ist'] > 0 ? '#d97706' : '#dc2626') ?>;">
            <?= number_format($r['ist'], 2, '.', "'") ?>
          </td>
          <td style="text-align:right; font-weight:600; color:<?= $r['diff'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
            <?= ($r['diff'] > 0 ? '+' : '') . number_format($r['diff'], 2, '.', "'") ?>
          </td>
          <td>
            <?php if (!empty($r['payments'])): ?>
              <?php foreach ($r['payments'] as $p): ?>
                <div style="font-size:12px; background:#f8fafc; border-radius:6px; padding:4px 8px; margin-bottom:4px; border:1px solid #e2e8f0;">
                  <span style="font-weight:600; color:#16a34a;">+<?= number_format((float)$p['betrag'], 2) ?> CHF</span>
                  <span style="color:#64748b; margin-left:6px;"><?= htmlspecialchars($p['buchungsdatum']) ?>:</span>
                  <span style="color:#334155;" title="<?= htmlspecialchars($p['beschreibung']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($p['beschreibung'], 0, 40, '...')) ?>
                  </span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color:#94a3b8; font-size:12px;">Keine Buchung im Monat</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
