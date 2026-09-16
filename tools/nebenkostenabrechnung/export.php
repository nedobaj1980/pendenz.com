<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE){session_name('PENDENZ_SESSID');session_start();}
require_once __DIR__.'/../../config.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/authz.php';
require_once __DIR__.'/../../includes/property_scope.php';
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib.php';

if(file_exists(__DIR__.'/../../vendor/autoload.php')) {
    require_once __DIR__.'/../../vendor/autoload.php';
}

require_login();
if(!in_array($_SESSION['rolle'] ?? '', ['admin','superadmin'], true)){
    http_response_code(403);
    exit('Zugriff verweigert');
}

nk_bootstrap($mysqli);

$pid = (int)($_GET['projekt_id'] ?? 0);
$year = (int)($_GET['jahr'] ?? date('Y'));
$format = $_GET['format'] ?? 'csv';

$projRow = $mysqli->query("SELECT id, nummer, name FROM projekte WHERE id = $pid LIMIT 1")->fetch_assoc();
$projectName = $projRow ? (($projRow['nummer'] ? $projRow['nummer'] . ' – ' : '') . $projRow['name']) : "Liegenschaft #$pid";

$rows = nk_load_bookings($mysqli, $pid, $year);
$units = [];
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

$units = array_values(array_filter($units, fn($u) => property_scope_is_tenant_unit($u['wohnung_name'] ?? '')));
foreach ($units as &$u) {
    $u['units'] = 1;
    $u['persons'] = 1;
    $u['period_from'] = $periodFrom;
    $u['period_to'] = $periodTo;
}
unset($u);

$daysInPeriod = ($year % 4 === 0 ? 366 : 365);
$r = nk_calculate_statement($rows, $units, $daysInPeriod);

if ($format === 'csv') {
    $filename = 'Nebenkostenabrechnung_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $projectName) . '_' . $year . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Swiss UTF-8 BOM for Microsoft Excel
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    
    fputcsv($o, ['Nebenkostenabrechnung ' . $year, $projectName, 'Periode: 01.01.' . $year . ' - 31.12.' . $year], ';');
    fputcsv($o, [], ';');
    fputcsv($o, ['Mietobjekt / Einheit', 'Mieter', 'Fläche m²', 'Umlageanteil CHF', 'Geleistetes Akonto CHF', 'Abrechnungssaldo CHF', 'Status'], ';');
    
    $uDetails = $r['unit_details'] ?? [];
    foreach ($uDetails as $d) {
        $status = $d['is_leerstand'] ? 'Leerstand' : ($d['saldo'] >= 0 ? 'Guthaben' : 'Nachzahlung');
        fputcsv($o, [
            $d['wohnung_name'],
            $d['mieter_name'] ?? 'Leerstand',
            number_format($d['area'], 1, '.', ''),
            number_format($d['cost'], 2, '.', ''),
            number_format($d['akonto_paid'], 2, '.', ''),
            number_format($d['saldo'], 2, '.', ''),
            $status
        ], ';');
    }
    
    fputcsv($o, [], ';');
    fputcsv($o, ['Zusammenfassung', 'Betrag CHF'], ';');
    fputcsv($o, ['Total Kosten Mieter', number_format($r['tenant_total'], 2, '.', '')], ';');
    fputcsv($o, ['Total Geleistete Akonto', number_format($r['tenant_akonto_total'], 2, '.', '')], ';');
    fputcsv($o, ['Gesamtsaldo Mieter', number_format($r['tenant_saldo_total'], 2, '.', '')], ';');
    fputcsv($o, ['Eigentümer steuerlich effektiv', number_format($r['owner_effective'], 2, '.', '')], ';');
    fputcsv($o, ['Eigentümer Pauschalabzug (20%)', number_format($r['owner_flat'], 2, '.', '')], ';');
    fputcsv($o, ['Empfohlener Steuerabzug', number_format($r['owner_recommended'], 2, '.', '')], ';');
    
    fclose($o);
    exit;
}

// HTML / PDF Export
$html = '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Nebenkostenabrechnung ' . $year . ' – ' . htmlspecialchars($projectName) . '</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 13px; color: #1e293b; padding: 30px; }
h1 { color: #0f766e; font-size: 22px; margin: 0 0 6px 0; }
.meta { color: #64748b; font-size: 13px; margin-bottom: 24px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
th { background: #f8fafc; border-bottom: 2px solid #cbd5e1; padding: 8px 10px; font-weight: 700; text-align: left; font-size: 12px; }
td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-bottom: 20px; }
.kpi { display: inline-block; margin-right: 30px; }
.kpi-lbl { font-size: 11px; color: #64748b; font-weight: bold; }
.kpi-val { font-size: 16px; font-weight: bold; color: #0f766e; }
@media print { body { padding: 0; } }
</style>
</head>
<body>
<h1>📑 Schweizer Nebenkostenabrechnung ' . $year . '</h1>
<div class="meta">
    <strong>Liegenschaft:</strong> ' . htmlspecialchars($projectName) . ' &nbsp;|&nbsp; 
    <strong>Periode:</strong> 01.01.' . $year . ' – 31.12.' . $year . ' (' . $daysInPeriod . ' Tage)
</div>

<div class="box">
    <div class="kpi"><div class="kpi-lbl">TOTAL KOSTEN MIETER</div><div class="kpi-val">CHF ' . number_format($r['tenant_total'], 2, '.', "'") . '</div></div>
    <div class="kpi"><div class="kpi-lbl">GELEISTETE AKONTO</div><div class="kpi-val" style="color:#2563eb;">CHF ' . number_format($r['tenant_akonto_total'], 2, '.', "'") . '</div></div>
    <div class="kpi"><div class="kpi-lbl">ABRECHNUNGSSALDO</div><div class="kpi-val" style="color:' . ($r['tenant_saldo_total'] >= 0 ? '#15803d' : '#b91c1c') . ';">CHF ' . number_format(abs($r['tenant_saldo_total']), 2, '.', "'") . ' (' . ($r['tenant_saldo_total'] >= 0 ? 'Guthaben' : 'Nachzahlung') . ')</div></div>
    <div class="kpi"><div class="kpi-lbl">EMPFOHLENER STEUERABZUG</div><div class="kpi-val" style="color:#475569;">CHF ' . number_format($r['owner_recommended'], 2, '.', "'") . '</div></div>
</div>

<h2>👥 Abrechnung nach Mieteinheiten</h2>
<table>
    <thead>
        <tr>
            <th>Mietobjekt / Einheit</th>
            <th>Mieter</th>
            <th class="num">Fläche</th>
            <th class="num">Umlageanteil</th>
            <th class="num">Geleistetes Akonto</th>
            <th class="num">Saldo</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>';

$uDetails = $r['unit_details'] ?? [];
foreach ($uDetails as $d) {
    $saldo = $d['saldo'];
    $isLeer = $d['is_leerstand'];
    $statusText = $isLeer ? 'Leerstand (Eigentümer)' : ($saldo >= 0 ? 'Guthaben' : 'Nachzahlung');
    $color = $isLeer ? '#64748b' : ($saldo >= 0 ? '#15803d' : '#b91c1c');

    $html .= '<tr>
        <td><strong>' . htmlspecialchars($d['wohnung_name']) . '</strong></td>
        <td>' . htmlspecialchars($d['mieter_name'] ?? 'Leerstand') . '</td>
        <td class="num">' . ($d['area'] > 0 ? number_format($d['area'], 1) . ' m²' : '—') . '</td>
        <td class="num">CHF ' . number_format($d['cost'], 2, '.', "'") . '</td>
        <td class="num">CHF ' . number_format($d['akonto_paid'], 2, '.', "'") . '</td>
        <td class="num" style="font-weight:bold; color:' . $color . ';">' . ($saldo >= 0 ? '+ ' : '- ') . 'CHF ' . number_format(abs($saldo), 2, '.', "'") . '</td>
        <td style="color:' . $color . '; font-weight:600;">' . $statusText . '</td>
    </tr>';
}

$html .= '</tbody></table>

<h2>🏛️ Steuerliche Zusammenfassung Eigentümer</h2>
<table>
    <thead>
        <tr>
            <th>Steuerklasse / Kostenart</th>
            <th class="num">Betrag CHF</th>
        </tr>
    </thead>
    <tbody>';
foreach ($r['owner'] as $k => $v) {
    $html .= '<tr><td>' . htmlspecialchars(ucfirst($k)) . '</td><td class="num">CHF ' . number_format($v, 2, '.', "'") . '</td></tr>';
}
$html .= '</tbody></table>

<div style="font-size:11px; color:#94a3b8; margin-top:40px; border-top:1px solid #cbd5e1; padding-top:8px;">
    Erstellt mit ' . SITE_NAME . ' am ' . date('d.m.Y H:i') . ' Uhr. Gemäss Schweizer Mietrecht (Art. 257a OR) und kantonalem Steuergesetz.
</div>
</body>
</html>';

if (class_exists('Dompdf\\Dompdf') && $format === 'pdf') {
    $opt = new Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $pdf = new Dompdf\Dompdf($opt);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4');
    $pdf->render();
    $pdf->stream('nebenkosten-' . $pid . '-' . $year . '.pdf', ['Attachment' => false]);
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}
