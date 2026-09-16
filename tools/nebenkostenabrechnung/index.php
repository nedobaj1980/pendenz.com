<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE){session_name('PENDENZ_SESSID');session_start();}
require_once __DIR__.'/../../config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/authz.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/property_scope.php';
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib.php';

require_login();
$role = $_SESSION['rolle'] ?? 'gast';
if(!in_array($role, ['admin','superadmin'], true)){
    http_response_code(403);
    exit('Zugriff verweigert');
}

nk_bootstrap($mysqli);
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$year = (int)($_GET['jahr'] ?? date('Y'));
$pid  = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));
$projects = $mysqli->query('SELECT id, nummer, name FROM projekte ORDER BY name')->fetch_all(MYSQLI_ASSOC);
if(!$pid && $projects) {
    $pid = (int)$projects[0]['id'];
}

$projectName = 'Unbekannt';
foreach ($projects as $p) {
    if ((int)$p['id'] === $pid) {
        $projectName = ($p['nummer'] ? $p['nummer'] . ' – ' : '') . $p['name'];
        break;
    }
}

$bookings = $pid ? nk_load_bookings($mysqli, $pid, $year) : [];
$units = [];
if ($pid) {
    $periodFrom = $year . '-01-01';
    $periodTo = $year . '-12-31';
    $st = $mysqli->prepare("
        SELECT w.id AS wohnung_id, w.name AS wohnung_name, COALESCE(w.flaeche, 0) AS area,
               wm.mieter_name, COALESCE(wm.nk_akonto, 0) AS nk_akonto, wm.startdatum, wm.enddatum, wm.status AS mieter_status
        FROM wohnungen w
        JOIN objekte o ON o.id = w.objekt_id
        LEFT JOIN wohnung_mieter wm ON wm.wohnung_id = w.id 
             AND (wm.enddatum IS NULL OR wm.enddatum = '' OR wm.enddatum >= ?)
             AND (wm.startdatum IS NULL OR wm.startdatum <= ?)
        WHERE o.projekt_id = ?
        ORDER BY w.name ASC
    ");
    if ($st) {
        $st->bind_param('ssi', $periodFrom, $periodTo, $pid);
        $st->execute();
        $units = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
    }
}

$units = array_values(array_filter($units, fn($u) => property_scope_is_tenant_unit($u['wohnung_name'] ?? '')));
foreach ($units as &$u) {
    $u['units'] = 1;
    $u['persons'] = 1;
    $u['period_from'] = $year . '-01-01';
    $u['period_to'] = $year . '-12-31';
}
unset($u);

$daysInPeriod = ($year % 4 === 0 ? 366 : 365);
$result = nk_calculate_statement($bookings, $units, $daysInPeriod);
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nebenkostenabrechnung – <?=SITE_NAME?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260915">
    <script src="../../assets/js/finance-tables.js?v=20260915.2" defer></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .wrap { max-width: 1320px; margin: 24px auto; padding: 0 20px; }
        .nk-header-card { background: #fff; border-radius: 14px; padding: 24px; margin-bottom: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .nk-title { font-size: 24px; font-weight: 800; color: #1e293b; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px; }
        .nk-desc { color: #64748b; font-size: 14px; margin: 0 0 18px 0; }
        .nk-form { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
        select, input[type="number"] { padding: 9px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; color: #0f172a; min-width: 180px; }
        .btn-calc { background: linear-gradient(135deg, #0f766e, #0d9488); color: #fff; font-weight: 700; padding: 10px 22px; border: none; border-radius: 8px; cursor: pointer; transition: opacity 0.2s; }
        .btn-calc:hover { opacity: 0.92; }
        .btn-link { display: inline-flex; align-items: center; gap: 6px; color: #4f46e5; text-decoration: none; font-size: 13px; font-weight: 600; padding: 8px 14px; background: #eef2ff; border-radius: 8px; }
        .btn-link:hover { background: #e0e7ff; }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .kpi-label { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .kpi-val { font-size: 24px; font-weight: 800; color: #1e293b; }
        .kpi-val.highlight { color: #0f766e; }
        .kpi-val.positive { color: #059669; }
        .kpi-val.negative { color: #dc2626; }
        .kpi-val.info { color: #3b82f6; }
        .card { background: #fff; border-radius: 14px; padding: 22px; margin-bottom: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .card h2 { font-size: 18px; font-weight: 700; margin: 0; color: #1e293b; }
        .warn-card { background: #fffbeb; border: 1px solid #fef3c7; color: #92400e; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; }
        .warn-card strong { display: block; margin-bottom: 6px; }
        .warn-card ul { margin: 0; padding-left: 20px; }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; min-width: 680px; }
        th { background: #f8fafc; padding: 12px 14px; border-bottom: 2px solid #e2e8f0; font-weight: 700; color: #475569; text-align: left; }
        td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
        tr:hover td { background: #f8fafc; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-leerstand { background: #f1f5f9; color: #64748b; }
        .badge-guthaben { background: #dcfce7; color: #15803d; }
        .badge-nachzahlung { background: #fee2e2; color: #b91c1c; }
        .actions-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f1f5f9; }
        .btn-act { text-decoration: none; font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-csv { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-csv:hover { background: #e2e8f0; }
        .btn-pdf { background: #4f46e5; color: #fff; }
        .btn-pdf:hover { background: #4338ca; }
        @media (max-width: 640px) {
            .wrap { padding: 0 12px; margin: 12px auto; }
            .nk-header-card { padding: 16px; }
            .nk-form select, .nk-form input { width: 100%; min-width: 100%; }
            .btn-calc { width: 100%; text-align: center; }
            .kpi-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php require __DIR__.'/../../includes/nav_dispatch.php'; ?>
<main class="wrap">
    <div class="nk-header-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="nk-title">📑 Schweizer Nebenkostenabrechnung</h1>
                <p class="nk-desc">Periodengerechte Heiz- und Nebenkostenverteilung nach Schweizer Mietrecht (Art. 257a OR) mit Akonto-Abgleich und Steueroptimierung.</p>
            </div>
            <a href="../finanzgruppen/index.php?projekt_id=<?=$pid?>" class="btn-link">🗂️ Finanzgruppen &amp; Schlüssel verwalten</a>
        </div>
        <form method="get" class="nk-form">
            <div class="form-group">
                <label>Liegenschaft / Projekt</label>
                <select name="projekt_id">
                    <?php foreach($projects as $p): ?>
                        <option value="<?=$p['id']?>" <?=$pid===(int)$p['id']?'selected':''?>><?=$h(($p['nummer']? $p['nummer'].' – ':'').$p['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Abrechnungsjahr</label>
                <input type="number" name="jahr" value="<?=$year?>" min="2000" max="2100">
            </div>
            <button type="submit" class="btn-calc">🔄 Neu berechnen</button>
        </form>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Umlagefähige Kosten (Mieter)</div>
            <div class="kpi-val highlight">CHF <?=number_format($result['tenant_total'],2,'.',"'")?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Geleistete Akontozahlungen</div>
            <div class="kpi-val info">CHF <?=number_format($result['tenant_akonto_total'],2,'.',"'")?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Abrechnungssaldo Mieter</div>
            <?php 
                $saldoTot = $result['tenant_saldo_total'];
                $saldoClass = $saldoTot >= 0 ? 'positive' : 'negative';
                $saldoLabel = $saldoTot >= 0 ? 'Guthaben Mieter' : 'Nachzahlung Mieter';
            ?>
            <div class="kpi-val <?=$saldoClass?>">CHF <?=number_format(abs($saldoTot),2,'.',"'")?> <span style="font-size:12px; font-weight:600; display:block;"><?=$saldoLabel?></span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Empfohlener Steuerabzug (Eigentümer)</div>
            <div class="kpi-val">CHF <?=number_format($result['owner_recommended'],2,'.',"'")?></div>
        </div>
    </div>

    <?php if($result['warnings']): ?>
        <div class="warn-card">
            <strong>⚠️ Prüfhinweise für diese Periode:</strong>
            <ul>
                <?php foreach($result['warnings'] as $w): ?>
                    <li><?=$h($w)?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head">
            <h2>👥 Mieterabrechnung je Mieteinheit</h2>
            <div style="font-size:13px; color:#64748b;">
                Periode: <strong>01.01.<?=$year?> – 31.12.<?=$year?></strong> (<?=$daysInPeriod?> Tage)
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Mietobjekt / Einheit</th>
                        <th>Aktiver Mieter</th>
                        <th class="num">Fläche</th>
                        <th class="num">Umlageanteil</th>
                        <th class="num">Geleistetes Akonto</th>
                        <th class="num">Abrechnungssaldo</th>
                        <th style="text-align:center;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $uDetails = $result['unit_details'] ?? [];
                    foreach($uDetails as $uid => $d): 
                        $saldo = $d['saldo'];
                        $isLeer = $d['is_leerstand'];
                    ?>
                    <tr>
                        <td><strong><?=$h($d['wohnung_name'])?></strong></td>
                        <td>
                            <?php if(!$isLeer): ?>
                                <span><?=$h($d['mieter_name'])?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8; font-style:italic;">Kein Mieter hinterlegt</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?=$d['area'] > 0 ? number_format($d['area'],1).' m²' : '—'?></td>
                        <td class="num">CHF <?=number_format($d['cost'],2,'.',"'")?></td>
                        <td class="num">
                            <?php if($d['akonto_paid'] > 0): ?>
                                CHF <?=number_format($d['akonto_paid'],2,'.',"'")?>
                                <small style="display:block; color:#64748b; font-size:11px;">(CHF <?=number_format($d['monthly_akonto'],0)?>/Mt)</small>
                            <?php else: ?>
                                <span style="color:#94a3b8;">CHF 0.00</span>
                            <?php endif; ?>
                        </td>
                        <td class="num" style="font-weight:700;">
                            <?php if($isLeer): ?>
                                <span style="color:#64748b;">CHF <?=number_format($d['cost'],2,'.',"'")?></span>
                            <?php elseif($saldo >= 0): ?>
                                <span style="color:#15803d;">+ CHF <?=number_format($saldo,2,'.',"'")?></span>
                            <?php else: ?>
                                <span style="color:#b91c1c;">- CHF <?=number_format(abs($saldo),2,'.',"'")?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if($isLeer): ?>
                                <span class="badge badge-leerstand">Leerstand (Eigentümer)</span>
                            <?php elseif($saldo >= 0): ?>
                                <span class="badge badge-guthaben">Guthaben</span>
                            <?php else: ?>
                                <span class="badge badge-nachzahlung">Nachzahlung</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($uDetails)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; color:#64748b; padding:28px;">
                            Keine abrechenbaren Wohneinheiten für dieses Projekt gefunden.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="actions-bar">
            <a href="export.php?format=csv&projekt_id=<?=$pid?>&jahr=<?=$year?>" class="btn-act btn-csv">📥 CSV-Export</a>
            <a href="export.php?format=html&projekt_id=<?=$pid?>&jahr=<?=$year?>" target="_blank" class="btn-act btn-pdf">🖨️ Drucken / PDF</a>
        </div>
    </div>

    <div class="card">
        <h2>🏛️ Eigentümer- &amp; Steuerübersicht (Liegenschaftskosten)</h2>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Steuerklasse / Kostenart</th>
                        <th class="num">Effektiver Aufwand</th>
                        <th>Steuerliche Behandlung (Schweiz)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $taxHints = [
                        'unterhalt' => 'Werterhaltend – 100% steuerlich abzugsfähig',
                        'verwaltung' => 'Betriebsaufwand – 100% steuerlich abzugsfähig',
                        'investition' => 'Wertvermehrend – nicht bei Einkommenssteuer abzugsfähig (Anlagekosten)',
                        'finanzierung' => 'Schuldzinsen – als Schuldzinsen abzugsfähig',
                        'privat' => 'Nicht geschäftsmässig begründet',
                        'unbekannt' => 'Muss noch klassifiziert werden'
                    ];
                    foreach($result['owner'] as $k=>$v): 
                    ?>
                    <tr>
                        <td><strong><?=$h(ucfirst($k))?></strong></td>
                        <td class="num">CHF <?=number_format($v,2,'.',"'")?></td>
                        <td style="color:#64748b; font-size:13px;"><?=$taxHints[$k] ?? '—'?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px; padding:14px; background:#f1f5f9; border-radius:8px; font-size:13px; color:#475569;">
            💡 <strong>Steuer-Tipp:</strong> Effektiver Abzug beträgt <strong>CHF <?=number_format($result['owner_effective'],2,'.',"'")?></strong> vs. Pauschalabzug von <strong>CHF <?=number_format($result['owner_flat'],2,'.',"'")?></strong>. Das System empfiehlt für dieses Steuerjahr die Geltendmachung von <strong>CHF <?=number_format($result['owner_recommended'],2,'.',"'")?></strong>.
        </div>
    </div>
</main>
<?php require __DIR__.'/../../includes/footer.php'; ?>
</body>
</html>
