<?php
// tools/mietkontrolle/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PENDENZ_SESSID');
    session_start();
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/authz.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/property_scope.php';
require_login();

$role = $_SESSION['rolle'] ?? '';
if (!in_array($role, ['admin', 'superadmin'])) {
    die("Zugriff verweigert (nur Admin/Superadmin).");
}

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

// 1. Parameter & Jahres-/Monats-Handling
$pid = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
$viewMode = (isset($_GET['view']) && $_GET['view'] === 'year') ? 'year' : 'month';

// Alle Buchungsjahre aus liegenschafts_konto ermitteln
$dbYears = [];
$yrRes = $mysqli->query("SELECT DISTINCT YEAR(buchungsdatum) AS yr FROM liegenschafts_konto WHERE buchungsdatum IS NOT NULL AND buchungsdatum > '1970-01-01' ORDER BY yr DESC");
if ($yrRes) {
    while ($r = $yrRes->fetch_assoc()) {
        $y = (int)$r['yr'];
        if ($y >= 2000 && $y <= 2099) $dbYears[] = $y;
    }
}
$availableYears = array_unique(array_merge([(int)date('Y'), (int)date('Y') - 1], $dbYears));
rsort($availableYears);

// Gewähltes Jahr bestimmen
$selYear = isset($_GET['jahr']) && (int)$_GET['jahr'] > 2000 ? (int)$_GET['jahr'] : 0;

if (isset($_GET['monat']) && preg_match('/^\d{4}-\d{2}$/', $_GET['monat'])) {
    $selMonth = $_GET['monat'];
    if ($selYear <= 0) {
        $selYear = (int)substr($selMonth, 0, 4);
    }
} elseif ($selYear > 0) {
    // Wenn Jahr angegeben, aber kein Monat: prüfe neuesten Monat mit Buchungen in diesem Jahr
    $chkSql = "SELECT DISTINCT DATE_FORMAT(buchungsdatum, '%Y-%m') as ym 
               FROM liegenschafts_konto 
               WHERE YEAR(buchungsdatum) = $selYear " . ($pid > 0 ? "AND (liegenschaft_id=$pid OR projekt_id=$pid)" : "") . " 
               ORDER BY buchungsdatum DESC LIMIT 1";
    $chkRes = $mysqli->query($chkSql);
    if ($chkRes && $rm = $chkRes->fetch_assoc()) {
        $selMonth = $rm['ym'];
    } else {
        $selMonth = sprintf('%04d-01', $selYear);
    }
} else {
    // Kein Jahr und kein Monat: Standardmäßig das neueste Buchungsjahr
    $selYear = !empty($dbYears) ? $dbYears[0] : (int)date('Y');
    $chkSql = "SELECT DISTINCT DATE_FORMAT(buchungsdatum, '%Y-%m') as ym 
               FROM liegenschafts_konto 
               WHERE YEAR(buchungsdatum) = $selYear " . ($pid > 0 ? "AND (liegenschaft_id=$pid OR projekt_id=$pid)" : "") . " 
               ORDER BY buchungsdatum DESC LIMIT 1";
    $chkRes = $mysqli->query($chkSql);
    if ($chkRes && $rm = $chkRes->fetch_assoc()) {
        $selMonth = $rm['ym'];
    } else {
        $selMonth = sprintf('%04d-%02d', $selYear, (int)date('m'));
    }
}

$firstDay = $selMonth . '-01';
$lastDay = date('Y-m-t', strtotime($firstDay));

// Vorheriger / Nächster Monat
$prevMonth = date('Y-m', strtotime($firstDay . ' -1 month'));
$nextMonth = date('Y-m', strtotime($firstDay . ' +1 month'));

// 2. Projekte für Dropdown
$projekte = [];
$resP = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
if ($resP) while ($r = $resP->fetch_assoc()) $projekte[] = $r;

// 3. Bankkonto der Liegenschaft laden (kv_konten)
$activeKonto = null;
if ($pid > 0) {
    $kRes = $mysqli->query("SELECT * FROM kv_konten WHERE projekt_id = $pid OR liegenschaft_id = $pid LIMIT 1");
    if ($kRes && $kr = $kRes->fetch_assoc()) {
        $activeKonto = $kr;
    }
}

// 4. Alle Einheiten für das Projekt laden
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
    if (!property_scope_is_tenant_unit($w['wohnung_name'] ?? '')) continue;
    $wohnungen[$w['wohnung_id']] = $w;
}

// Monatsübersicht für Monats-Pills (wie viele Zahlungen in welchem Monat des Jahres)
$yearMonthCounts = [];
$ymSql = "SELECT MONTH(buchungsdatum) as m, COUNT(*) as cnt, SUM(betrag) as s 
          FROM liegenschafts_konto 
          WHERE YEAR(buchungsdatum) = $selYear AND betrag > 0 "
          . ($pid > 0 ? "AND (liegenschaft_id = $pid OR projekt_id = $pid) " : "")
          . "GROUP BY MONTH(buchungsdatum)";
$ymRes = $mysqli->query($ymSql);
if ($ymRes) while ($ym = $ymRes->fetch_assoc()) {
    $yearMonthCounts[(int)$ym['m']] = $ym;
}

// 5. Monats-Abgleich (wenn view == 'month')
$reconciliation = [];
$totSoll = 0.0;
$totIst = 0.0;
$cntBezahlt = 0;
$cntTeil = 0;
$cntOffen = 0;

if ($viewMode === 'month') {
    // Mieter-Verträge für den gewählten Monat
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

    // Buchungen für den Monat
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

        $ist = 0.0;
        $matchedPayments = [];

        foreach ($kontoRows as $k) {
            $isMatch = false;
            // Direkte Zuweisung über wohnung_id
            if ((int)$k['wohnung_id'] === $wid) {
                $isMatch = true;
            }
            // Direkte Zuweisung über mieter_id
            elseif ($tenant && !empty($tenant['benutzer_id']) && (int)$k['mieter_id'] === (int)$tenant['benutzer_id']) {
                $isMatch = true;
            }
            // Namensabgleich im Buchungstext
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
}

// 6. Ganzjahres-Matrix (wenn view == 'year')
$yearMatrix = [];
$yearTotSoll = 0.0;
$yearTotIst = 0.0;
$monthColTotals = array_fill(1, 12, ['soll' => 0.0, 'ist' => 0.0]);

if ($viewMode === 'year') {
    // Alle Buchungen des gesamten Jahres
    $allYearBookings = [];
    $allBkSql = "SELECT id, liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, wohnung_id, mieter_id, MONTH(buchungsdatum) as m 
                 FROM liegenschafts_konto 
                 WHERE YEAR(buchungsdatum) = $selYear AND betrag > 0 "
                 . ($pid > 0 ? "AND (liegenschaft_id = $pid OR projekt_id = $pid) " : "");
    $allBkRes = $mysqli->query($allBkSql);
    if ($allBkRes) while ($bk = $allBkRes->fetch_assoc()) {
        $allYearBookings[] = $bk;
    }

    // Aktive Mieter je Wohnung
    $tenantsByWhg = [];
    $mSql = "SELECT wm.id as wm_id, wm.wohnung_id, wm.benutzer_id, wm.mieter_name,
                    wm.mietzins_netto, wm.nk_akonto, wm.startdatum, wm.enddatum
             FROM wohnung_mieter wm
             WHERE (wm.enddatum IS NULL OR YEAR(wm.enddatum) >= $selYear)
               AND (wm.startdatum IS NULL OR YEAR(wm.startdatum) <= $selYear)
             ORDER BY wm.startdatum DESC";
    $mRes = $mysqli->query($mSql);
    if ($mRes) while ($tm = $mRes->fetch_assoc()) {
        $wid = (int)$tm['wohnung_id'];
        if (!isset($tenantsByWhg[$wid])) {
            $tenantsByWhg[$wid] = $tm;
        }
    }

    foreach ($wohnungen as $wid => $w) {
        $tenant = $tenantsByWhg[$wid] ?? null;
        $monthlySoll = 0.0;
        if ($tenant) {
            $netto = (float)($tenant['mietzins_netto'] ?? 0);
            $nk = (float)($tenant['nk_akonto'] ?? 0);
            $monthlySoll = $netto + $nk;
            if ($monthlySoll <= 0) {
                $monthlySoll = (float)$w['mietzins_netto_soll'] + (float)$w['mietzins_nk_soll'];
            }
            $mieterName = $tenant['mieter_name'];
        } else {
            $monthlySoll = (float)$w['mietzins_netto_soll'] + (float)$w['mietzins_nk_soll'];
            $mieterName = 'Leerstand';
        }

        $rowMonths = [];
        $rowTotIst = 0.0;
        $rowTotSoll = $monthlySoll * 12;

        for ($m = 1; $m <= 12; $m++) {
            $mIst = 0.0;
            foreach ($allYearBookings as $bk) {
                if ((int)$bk['m'] !== $m) continue;
                $isMatch = false;
                if ((int)$bk['wohnung_id'] === $wid) {
                    $isMatch = true;
                } elseif ($tenant && !empty($tenant['benutzer_id']) && (int)$bk['mieter_id'] === (int)$tenant['benutzer_id']) {
                    $isMatch = true;
                } elseif ($tenant && !empty($tenant['mieter_name'])) {
                    $words = preg_split('/[\s,\/&]+/', $tenant['mieter_name']);
                    foreach ($words as $word) {
                        $word = trim($word);
                        if (mb_strlen($word) >= 4 && stripos($bk['beschreibung'], $word) !== false) {
                            $isMatch = true;
                            break;
                        }
                    }
                }
                if ($isMatch) $mIst += (float)$bk['betrag'];
            }

            $mDiff = round($mIst - $monthlySoll, 2);
            $mStatus = 'empty';
            if ($monthlySoll > 0) {
                if ($mIst >= $monthlySoll) $mStatus = ($mDiff > 0) ? 'overpaid' : 'paid';
                elseif ($mIst > 0) $mStatus = 'partial';
                else $mStatus = 'open';
            } else {
                $mStatus = ($mIst > 0) ? 'paid' : 'empty';
            }

            $rowMonths[$m] = [
                'ist' => $mIst,
                'soll' => $monthlySoll,
                'status' => $mStatus,
                'diff' => $mDiff
            ];
            $rowTotIst += $mIst;

            $monthColTotals[$m]['soll'] += $monthlySoll;
            $monthColTotals[$m]['ist'] += $mIst;
        }

        $yearTotSoll += $rowTotSoll;
        $yearTotIst += $rowTotIst;

        $yearMatrix[] = [
            'wohnung_id' => $wid,
            'wohnung_name' => $w['wohnung_name'],
            'objekt_name' => $w['objekt_name'],
            'projekt_name' => $w['projekt_name'],
            'mieter_name' => $mieterName,
            'monthly_soll' => $monthlySoll,
            'months' => $rowMonths,
            'tot_soll' => $rowTotSoll,
            'tot_ist' => $rowTotIst,
            'tot_diff' => round($rowTotIst - $rowTotSoll, 2)
        ];
    }
}

$totOffen = max(0, $totSoll - $totIst);
$quote = ($totSoll > 0) ? round(($totIst / $totSoll) * 100, 1) : 100;

$monthNamesShort = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr',
    5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
    9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez'
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/nav_dispatch.php';
?>

<style>
.mk-wrap { max-width: 1440px; margin: 24px auto; padding: 0 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
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

.filter-bar { background: #fff; padding: 16px 20px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
.table-mk { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
.table-mk th { background: #0f172a; color: #fff; padding: 12px 14px; text-align: left; font-size: 13px; font-weight: 600; }
.table-mk td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
.table-mk tr:hover { background: #f8fafc; }

.btn-nav { padding: 8px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; text-decoration: none; color: #334155; font-size: 13px; font-weight: 600; transition: all 0.15s ease; }
.btn-nav:hover { background: #f1f5f9; }
.btn-nav.active { background: #2563eb; color: #fff; border-color: #2563eb; }

.m-pill { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 8px; border: 1px solid #e2e8f0; text-decoration: none; color: #334155; font-size: 13px; font-weight: 700; background: #fff; min-width: 48px; }
.m-pill:hover { background: #f8fafc; border-color: #cbd5e1; }
.m-pill.active { background: #2563eb; color: #fff; border-color: #2563eb; box-shadow: 0 2px 4px rgba(37,99,235,0.3); }
.m-pill.has-data { border-bottom: 3px solid #10b981; }
.m-pill.active.has-data { border-bottom: 3px solid #6ee7b7; }
</style>

<div class="mk-wrap">
  <div class="mk-header">
    <div>
      <h1 style="margin:0; font-size:24px; color:#0f172a;">📊 Liegenschafts-Mietkontrolle</h1>
      <p style="margin:4px 0 0; color:#64748b; font-size:14px;">Automatische Abstimmung zwischen Bankauszug (CSV) und Mietzins-Soll je Liegenschaft und Jahr</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a href="?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>&view=month&monat=<?= $selMonth ?>" class="btn-nav <?= $viewMode === 'month' ? 'active' : '' ?>">
        📅 Monats-Detail
      </a>
      <a href="?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>&view=year" class="btn-nav <?= $viewMode === 'year' ? 'active' : '' ?>">
        📊 Jahres-Matrix (12 Monate)
      </a>
      <button onclick="window.print()" class="btn-nav">🖨️ Drucken / PDF</button>
      <a href="../konto_verwaltung/import.php" class="btn-nav" style="background:#0284c7; color:#fff; border-color:#0284c7;">📥 Bank-CSV importieren</a>
      <a href="../konto_verwaltung/index.php?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>" class="btn-nav" style="background:#10b981; color:#fff; border-color:#10b981;">💳 Zum Liegenschaftskonto</a>
      <a href="../liegenschaftsabrechnung/index.php?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>" class="btn-nav" style="background:#7c3aed; color:#fff; border-color:#6d28d9; font-weight:600;">📑 Zur Liegenschaftsabrechnung</a>
    </div>
  </div>

  <!-- Liegenschafts- und Bankkonto Status-Banner -->
  <?php if ($pid > 0): ?>
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #2563eb; border-radius:10px; padding:12px 18px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div>
        <div style="font-size:11px; font-weight:700; color:#2563eb; text-transform:uppercase;">Ausgewählte Liegenschaft:</div>
        <div style="font-size:16px; font-weight:800; color:#0f172a; margin-top:2px;">
          🏠 <?= htmlspecialchars($wohnungen[array_key_first($wohnungen)]['projekt_name'] ?? 'Liegenschaft #'.$pid) ?>
        </div>
        <div style="font-size:13px; color:#475569; margin-top:3px;">
          <?php if ($activeKonto): ?>
            💳 Bankkonto: <strong><?= htmlspecialchars($activeKonto['name']) ?></strong> (<?= htmlspecialchars($activeKonto['bank'] ?: 'Bank') ?>) &bull; 
            IBAN: <span style="font-family:monospace; font-weight:700; color:#0f172a;"><?= htmlspecialchars($activeKonto['iban'] ? chunk_split($activeKonto['iban'], 4, ' ') : '—') ?></span>
          <?php else: ?>
            <span style="color:#d97706;">⚠️ Noch kein dediziertes Bankkonto in kv_konten hinterlegt.</span>
          <?php endif; ?>
        </div>
      </div>
      <div>
        <a href="../konto_verwaltung/index.php?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>" class="btn-nav" style="font-size:12px;">
          🔍 Buchungen anzeigen (<?= $selYear ?>) ➔
        </a>
      </div>
    </div>
  <?php endif; ?>

  <!-- Jahres-Tabs Bar -->
  <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px 16px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <span style="font-weight:700; font-size:13px; color:#475569;">📅 Kontroll-Jahr:</span>
      <?php foreach ($availableYears as $yr): 
        $isActiveYr = ($selYear === $yr);
      ?>
        <a href="?projekt_id=<?= $pid ?>&jahr=<?= $yr ?>&view=<?= $viewMode ?>" 
           class="btn-nav <?= $isActiveYr ? 'active' : '' ?>">
          <?= $yr ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- 12-Monats Schnellwahltasten -->
    <?php if ($viewMode === 'month'): ?>
      <div style="display:flex; gap:4px; flex-wrap:wrap; align-items:center;">
        <?php for ($m = 1; $m <= 12; $m++): 
          $mStr = sprintf('%04d-%02d', $selYear, $m);
          $isActiveM = ($selMonth === $mStr);
          $hasData = isset($yearMonthCounts[$m]) && $yearMonthCounts[$m]['cnt'] > 0;
        ?>
          <a href="?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>&monat=<?= $mStr ?>&view=month" 
             class="m-pill <?= $isActiveM ? 'active' : '' ?> <?= $hasData ? 'has-data' : '' ?>"
             title="<?= $monthNamesShort[$m] ?> <?= $selYear ?><?= $hasData ? ': ' . $yearMonthCounts[$m]['cnt'] . ' Buchungen' : '' ?>">
            <span><?= $monthNamesShort[$m] ?></span>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Filterleiste: Liegenschaft -->
  <form method="get" class="filter-bar">
    <input type="hidden" name="jahr" value="<?= $selYear ?>">
    <input type="hidden" name="view" value="<?= $viewMode ?>">
    <?php if ($viewMode === 'month'): ?>
      <input type="hidden" name="monat" value="<?= htmlspecialchars($selMonth) ?>">
    <?php endif; ?>

    <div style="flex:1; min-width:280px;">
      <label style="font-size:12px; font-weight:700; color:#64748b; display:block; margin-bottom:4px; text-transform:uppercase;">
        Liegenschaft / Projekt filtern:
      </label>
      <select name="projekt_id" onchange="this.form.submit()" style="padding:9px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; width:100%; max-width:500px; background:#fff;">
        <option value="0">-- Alle Liegenschaften anzeigen --</option>
        <?php foreach ($projekte as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>>
            🏠 <?= htmlspecialchars($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($viewMode === 'month'): ?>
      <div>
        <label style="font-size:12px; font-weight:700; color:#64748b; display:block; margin-bottom:4px; text-transform:uppercase;">
          Monat manuell wählen:
        </label>
        <div style="display:flex; gap:6px; align-items:center;">
          <a href="?projekt_id=<?= $pid ?>&jahr=<?= (int)substr($prevMonth,0,4) ?>&monat=<?= $prevMonth ?>&view=month" class="btn-nav" title="Vorheriger Monat">◀</a>
          <input type="month" name="monat" value="<?= htmlspecialchars($selMonth) ?>" onchange="this.form.submit()" style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
          <a href="?projekt_id=<?= $pid ?>&jahr=<?= (int)substr($nextMonth,0,4) ?>&monat=<?= $nextMonth ?>&view=month" class="btn-nav" title="Nächster Monat">▶</a>
        </div>
      </div>
    <?php endif; ?>
  </form>

  <?php if ($viewMode === 'month'): ?>
    <!-- Monats-Ansicht Kacheln & Tabelle -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-title">Soll-Miete Gesamt (Monat)</div>
        <div class="kpi-val" style="color:#0f172a;">CHF <?= number_format($totSoll, 2, '.', "'") ?></div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">Monat <?= date('m/Y', strtotime($firstDay)) ?> (<?= count($wohnungen) ?> Einheiten)</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-title">Ist-Eingang (Bank)</div>
        <div class="kpi-val" style="color:#16a34a;">CHF <?= number_format($totIst, 2, '.', "'") ?></div>
        <div style="font-size:12px; color:#16a34a; margin-top:4px;">Eingegangene Mietzahlungen</div>
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
          <?= $cntBezahlt ?> Bezahlt &bull; <?= $cntTeil ?> Teil &bull; <?= $cntOffen ?> Offen
        </div>
      </div>
    </div>

    <!-- Tabelle der Einheiten für den Monat -->
    <table class="table-mk">
      <thead>
        <tr>
          <th style="width:120px;">Status</th>
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
              <a href="../pages/wohnung_detail.php?id=<?= $r['wohnung_id'] ?>" style="font-weight:700; color:#2563eb; text-decoration:none;">
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
            <td style="text-align:right; font-weight:700; color:#334155;">
              <?= number_format($r['soll'], 2, '.', "'") ?>
            </td>
            <td style="text-align:right; font-weight:700; color:<?= $r['ist'] >= $r['soll'] ? '#16a34a' : ($r['ist'] > 0 ? '#d97706' : '#dc2626') ?>;">
              <?= number_format($r['ist'], 2, '.', "'") ?>
            </td>
            <td style="text-align:right; font-weight:700; color:<?= $r['diff'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
              <?= ($r['diff'] > 0 ? '+' : '') . number_format($r['diff'], 2, '.', "'") ?>
            </td>
            <td>
              <?php if (!empty($r['payments'])): ?>
                <?php foreach ($r['payments'] as $p): ?>
                  <div style="font-size:12px; background:#f8fafc; border-radius:6px; padding:4px 8px; margin-bottom:4px; border:1px solid #e2e8f0;">
                    <span style="font-weight:700; color:#16a34a;">+<?= number_format((float)$p['betrag'], 2) ?> CHF</span>
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

  <?php else: ?>
    <!-- Jahres-Matrix (12 Monate nebeneinander) -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-title">Soll-Miete Gesamt (Jahr <?= $selYear ?>)</div>
        <div class="kpi-val" style="color:#0f172a;">CHF <?= number_format($yearTotSoll, 2, '.', "'") ?></div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">12 Monate &bull; <?= count($wohnungen) ?> Einheiten</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">Ist-Eingang Bank (Jahr <?= $selYear ?>)</div>
        <div class="kpi-val" style="color:#16a34a;">CHF <?= number_format($yearTotIst, 2, '.', "'") ?></div>
        <div style="font-size:12px; color:#16a34a; margin-top:4px;">Summe aller erfassten Mieteingänge</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">Differenz / Saldo (Jahr <?= $selYear ?>)</div>
        <div class="kpi-val" style="color:<?= ($yearTotIst - $yearTotSoll) >= 0 ? '#16a34a' : '#dc2626' ?>;">
          CHF <?= number_format($yearTotIst - $yearTotSoll, 2, '.', "'") ?>
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">Ganzjahres-Ergebnis</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-title">Jahres-Zahlungsquote</div>
        <?php $yQuote = $yearTotSoll > 0 ? round(($yearTotIst / $yearTotSoll) * 100, 1) : 100; ?>
        <div class="kpi-val" style="color:<?= $yQuote >= 95 ? '#16a34a' : ($yQuote >= 70 ? '#d97706' : '#dc2626') ?>;">
          <?= $yQuote ?> %
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:4px;">Erfüllungsgrad über alle 12 Monate</div>
      </div>
    </div>

    <!-- 12-Monats Matrix Tabelle -->
    <div style="overflow-x:auto; background:#fff; border-radius:12px; border:1px solid #e2e8f0; box-shadow:0 2px 8px rgba(0,0,0,0.04); margin-bottom:30px;">
      <table style="width:100%; border-collapse:collapse; font-size:12px; white-space:nowrap;">
        <thead>
          <tr style="background:#0f172a; color:#fff; text-align:left;">
            <th style="padding:10px 12px; position:sticky; left:0; background:#0f172a; z-index:2;">Einheit / Mieter</th>
            <th style="padding:10px 10px; text-align:right;">Soll/Mt</th>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <th style="padding:10px 8px; text-align:center;">
                <a href="?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>&monat=<?= sprintf('%04d-%02d', $selYear, $m) ?>&view=month" style="color:#fff; text-decoration:none;" title="Zum Monat <?= $monthNamesShort[$m] ?>">
                  <?= $monthNamesShort[$m] ?> ↗
                </a>
              </th>
            <?php endfor; ?>
            <th style="padding:10px 12px; text-align:right;">Total Ist</th>
            <th style="padding:10px 12px; text-align:right;">Saldo Jahr</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($yearMatrix)): ?>
            <tr><td colspan="16" style="padding:30px; text-align:center; color:#94a3b8;">Keine Daten gefunden.</td></tr>
          <?php else: foreach ($yearMatrix as $row): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
              <td style="padding:10px 12px; position:sticky; left:0; background:#fff; z-index:1; border-right:1px solid #e2e8f0;">
                <a href="../pages/wohnung_detail.php?id=<?= $row['wohnung_id'] ?>" style="font-weight:700; color:#2563eb; text-decoration:none;">
                  <?= htmlspecialchars($row['wohnung_name']) ?>
                </a>
                <div style="font-size:11px; color:#475569;"><?= htmlspecialchars($row['mieter_name']) ?></div>
              </td>
              <td style="padding:10px; text-align:right; font-weight:700; color:#334155; border-right:1px solid #e2e8f0;">
                <?= number_format($row['monthly_soll'], 0, '.', "'") ?>
              </td>
              <?php for ($m = 1; $m <= 12; $m++): 
                $mc = $row['months'][$m];
                $bg = '#fff';
                $col = '#334155';
                if ($mc['status'] === 'paid' || $mc['status'] === 'overpaid') {
                    $bg = '#ecfdf5'; $col = '#166534';
                } elseif ($mc['status'] === 'partial') {
                    $bg = '#fef9c3'; $col = '#854d0e';
                } elseif ($mc['status'] === 'open') {
                    $bg = '#fef2f2'; $col = '#991b1b';
                }
              ?>
                <td style="padding:8px 6px; text-align:center; background:<?= $bg ?>; color:<?= $col ?>; border-right:1px solid #f1f5f9;" title="Soll: <?= number_format($mc['soll'],2) ?> | Ist: <?= number_format($mc['ist'],2) ?>">
                  <div style="font-weight:700;"><?= $mc['ist'] > 0 ? number_format($mc['ist'], 0, '.', "'") : '—' ?></div>
                </td>
              <?php endfor; ?>
              <td style="padding:10px 12px; text-align:right; font-weight:700; color:#16a34a; border-left:1px solid #e2e8f0;">
                <?= number_format($row['tot_ist'], 2, '.', "'") ?>
              </td>
              <td style="padding:10px 12px; text-align:right; font-weight:700; color:<?= $row['tot_diff'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
                <?= ($row['tot_diff'] > 0 ? '+' : '') . number_format($row['tot_diff'], 2, '.', "'") ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot style="background:#f8fafc; font-weight:700; border-top:2px solid #cbd5e1;">
          <tr>
            <td style="padding:12px; position:sticky; left:0; background:#f8fafc; z-index:1; border-right:1px solid #e2e8f0;">
              GESAMT (<?= count($wohnungen) ?> Einheiten)
            </td>
            <td style="padding:12px 10px; text-align:right; border-right:1px solid #e2e8f0;">
              <?= number_format($yearTotSoll / 12, 0, '.', "'") ?>
            </td>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <td style="padding:12px 6px; text-align:center; border-right:1px solid #e2e8f0; font-size:11px;">
                <div style="color:#16a34a;"><?= number_format($monthColTotals[$m]['ist'], 0, '.', "'") ?></div>
                <div style="color:#64748b; font-size:10px;">/ <?= number_format($monthColTotals[$m]['soll'], 0, '.', "'") ?></div>
              </td>
            <?php endfor; ?>
            <td style="padding:12px; text-align:right; color:#16a34a; border-left:1px solid #e2e8f0;">
              CHF <?= number_format($yearTotIst, 2, '.', "'") ?>
            </td>
            <td style="padding:12px; text-align:right; color:<?= ($yearTotIst - $yearTotSoll) >= 0 ? '#16a34a' : '#dc2626' ?>;">
              CHF <?= number_format($yearTotIst - $yearTotSoll, 2, '.', "'") ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
