<?php
// pages/pendenzen_list_pdf.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE)
    session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// --- 1. Filter & Protokoll-Daten aus GET/POST übernehmen ---
$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$projekt_name = isset($_GET['projekt_name']) ? trim((string) $_GET['projekt_name']) : '';
$zustaendig_name = isset($_GET['zustaendig_name']) ? trim((string) $_GET['zustaendig_name']) : '';
$wichtigkeit = isset($_GET['wichtigkeit']) ? trim((string) $_GET['wichtigkeit']) : '';
$date_from = isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : '';
$sortMode = isset($_GET['sort_mode']) && $_GET['sort_mode'] === 'raum' ? 'raum' : 'nummer';

// Protokoll-Daten (aus dem Modal oder Preview-URL)
$protocol = null;
$rawProtocol = $_POST['protocol_data'] ?? $_GET['protocol_data'] ?? '';
if (!empty($rawProtocol)) {
    $protocol = json_decode($rawProtocol, true);
    // Falls Daten verschachtelt in 'layout' kommen (Designer-Preview), flach klopfen
    if (isset($protocol['layout']) && is_array($protocol['layout'])) {
        foreach ($protocol['layout'] as $k => $v) {
            if (!isset($protocol[$k]))
                $protocol[$k] = $v;
        }
    }
}

// Hilfsfunktion für sicheres Escapen (verhindert Fehler bei null in PHP 8.1+)
if (!function_exists('he')) {
    function he($val)
    {
        return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// --- 2. Query zusammenbauen ---
$where = ["p.deleted_at IS NULL"];
$params = [];
$types = "";

if ($q !== '') {
    $where[] = "(p.titel LIKE ? OR p.kurzbeschreibung LIKE ? OR p.id LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $types .= "sss";
}
if ($status !== '') {
    $where[] = "p.status = ?";
    $params[] = $status;
    $types .= "s";
}
if ($projekt_name !== '') {
    $where[] = "pr.name = ?";
    $params[] = $projekt_name;
    $types .= "s";
}
if ($zustaendig_name !== '') {
    $where[] = "b.name = ?";
    $params[] = $zustaendig_name;
    $types .= "s";
}
if ($wichtigkeit !== '') {
    $where[] = "p.wichtigkeit = ?";
    $params[] = $wichtigkeit;
    $types .= "s";
}
if ($date_from !== '') {
    $where[] = "p.created_at >= ?";
    $params[] = $date_from . " 00:00:00";
    $types .= "s";
}
if ($date_to !== '') {
    $where[] = "p.created_at <= ?";
    $params[] = $date_to . " 23:59:59";
    $types .= "s";
}

$whereSql = implode(" AND ", $where);

$orderSql = $sortMode === 'raum'
    ? "ORDER BY w.name ASC, rm.name ASC, p.id ASC"
    : "ORDER BY p.id DESC";

$sql = "SELECT p.*, 
               pr.name AS projekt_name, 
               o.name AS objekt_name, 
               w.name AS wohnung_name, 
               pa.name AS vorgangsart_name,
               b.name AS zustaendig_name,
               f.name AS zustaendig_firma,
               rm.name AS raum_name,
        (SELECT pfad FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image' ORDER BY is_cover DESC, id ASC LIMIT 1) AS first_image
        FROM pendenzen p
        LEFT JOIN projekte pr ON pr.id = p.projekt_id
        LEFT JOIN objekte o ON o.id = p.objekt_id
        LEFT JOIN wohnungen w ON w.id = p.wohnung_id
        LEFT JOIN pendenzen_arten pa ON pa.id = p.vorgangsart_id
        LEFT JOIN benutzer b ON b.id = p.zustaendig_id
        LEFT JOIN firmen f ON f.id = b.firma_id
        LEFT JOIN raeume rm ON rm.id = p.raum_id
        WHERE $whereSql
        $orderSql";

$stmt = $mysqli->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$pendenzen = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// --- 3. Branding & Settings ---
$settingsRes = $mysqli->query("SELECT k, v FROM settings WHERE k IN ('pdf_mask_default', 'logo', 'company_name', 'company_address', 'company_zip', 'company_city', 'company_phone', 'company_email', 'company_web')");
$set = [];
while ($r = $settingsRes->fetch_assoc())
    $set[$r['k']] = $r['v'];

$themeColor = '#00a896'; // Standard
if (!empty($set['pdf_mask_default'])) {
    $mask = json_decode($set['pdf_mask_default'], true);
    if (!empty($mask['color']))
        $themeColor = $mask['color'];
}

// Aktueller Benutzer mit Firmen-Join für das Branding
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$currentUser = null;
if ($user_id > 0) {
    $cSql = "SELECT b.*, f.name as f_name, f.adresse as f_adresse, f.ort as f_ort, f.telefon as f_tel, f.email as f_email, f.website as f_web, f.logo as f_logo
             FROM benutzer b
             LEFT JOIN firmen f ON b.firma_id = f.id
             WHERE b.id = $user_id";
    $currentUser = $mysqli->query($cSql)->fetch_assoc();
}

$brand = [
    'name' => $currentUser['f_name'] ?? $currentUser['firma_name'] ?? $currentUser['firma'] ?? ($set['company_name'] ?? 'Pendenz.com'),
    'adresse' => $currentUser['f_adresse'] ?? $currentUser['firma_adresse'] ?? $currentUser['adresse'] ?? ($set['company_address'] ?? ''),
    'ort' => $currentUser['f_ort'] ?? $currentUser['firma_ort'] ?? $currentUser['ort'] ?? ($set['company_city'] ?? ''),
    'tel' => $currentUser['f_tel'] ?? $currentUser['firma_telefon'] ?? $currentUser['telefonnummer'] ?? $currentUser['telefon'] ?? ($set['company_phone'] ?? ''),
    'email' => $currentUser['f_email'] ?? $currentUser['firma_email'] ?? $currentUser['email'] ?? ($set['company_email'] ?? ''),
    'web' => $currentUser['f_web'] ?? $currentUser['firma_website'] ?? $currentUser['website'] ?? ($set['company_web'] ?? ''),
    'logo' => $currentUser['f_logo'] ?? $currentUser['firmenlogo'] ?? $currentUser['logo'] ?? ($set['logo'] ?? '')
];

$brandLogo = null;
if (!empty($brand['logo'])) {
    $logoPath = realpath(__DIR__ . '/../' . ltrim($brand['logo'], '/'));
    if ($logoPath && is_file($logoPath)) {
        $logoData = @file_get_contents($logoPath);
        if ($logoData)
            $brandLogo = 'data:image/png;base64,' . base64_encode($logoData);
    }
}
// --- SVG Icon Mapping für PDF (Cross-Platform Compatibility) ---
$icons = [
    'PROJEKT'   => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCIgZmlsbD0iIzAwYTg5NiI+PHBhdGggZD0iTTEyIDdWM0gydjE4aDIwVjdIMTJ6TTYgMTlINH0tMmgidjJ6bTAtNEg0di0yaDIndjJ6bTAtNEg0VjloMnYyem0wLTRILDRWNWgydjJ6bTQgMTJIOHYtMmgidjJ6bTAtNEg4di0yaDIndjJ6bTAtNEg4VjloMnYyem0wLTRIOFY1aDJ2MnptMTAgMTJoLTh2LTJoMnYtMmgtMnYtMmgydi0yaC0yVjloOHYxMHptLTItOGgtMnYyaDJ2LTJ6bTAgNGgtMnYyaDJ2LTJ6Ii8+PC9zdmc+',
    'OBJEKT'    => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCIgZmlsbD0iIzAwYTg5NiI+PHBhdGggZD0iTTEyIDJjLTMuODcgMC03IDMuMTMtNyA3czcgMTMgNyAxMyA3LTcuNzNSA3LTEzLTMuMTMtNy03LTd6bTAgOS41Yy0xLjM4IDAtMi41LTEuMTItMi41LTIuNXMxLjEyLTIuNSAyLjUtMi41IDIuNSAxLjEyIDIuNSAyLjUtMS4xMiAyLjUtMi41IDIuNXoiLz48L3N2Zz4+',
    'WOHNUNG'   => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCIgZmlsbD0iIzAwYTg5NiI+PHBhdGggZD0iTTEwIDIwdi02aDR2Nmg1di04aDNMMTIgMyAyIDEyaDN2OHoiLz48L3N2Zz4+',
    'RAUM'      => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCIgZmlsbD0iIzAwYTg5NiI+PHBhdGggZD0iTTUgIDNWMTloMTRWM0g1em0xMiAxMmgtMnYtMmgydjJ6Ii8+PC9zdmc+',
    'TERMIN'    => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCIgZmlsbD0iIzAwYTg5NiI+PHBhdGggZD0iTTE5IDNoLTF2LTloMnYyaC04di0yaC0ydjJoLTFjLTEuMTA0IDAtMiAuODk2LTIgMnYxNmMwIDEuMTA0Ljg5NiAyIDIgMmgxNmMxLjEwNCAwIDItLjg5NiAyLTJ2LTE2YzAtMS4xMDQtLjg5Ni0yLTItMnptMCAxOHgtMTZ2LTEzaDE2djEzem0tOC01aC00di00aDR2NHoiLz48L3N2Zz4+',
    'LOCATION'  => 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCIgZmlsbD0iIzAwYTg5NiI+PHBhdGggZD0iTTEyIDJjLTQuNDE4IDAtOCAzLjU4Mi04IDhwMCA1LjUwNSA4IDguNDk1IDggOC40OTV2LTguNDk1YzAtNC40MTgtMy41ODItOC04LTh6bTAgMTFjLTEuNjU3IDAtMy0xLjM0My0zLTMyczEuMzQzLTMgMy0zIDMgMS4zNDMgMyAzLTEuMzQzIDMtMyAzIi8+PC9zdmc+'
];

function getIcon($key, $icons) {
    if (!isset($icons[$key])) return '';
    return '<img src="data:image/svg+xml;base64,' . $icons[$key] . '" width="14" height="14" style="border:none; vertical-align: middle;">';
}

// --- 4. HTML für Dompdf vorbereiten ---
ob_start();
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 10mm;
        }

        @font-face {
            font-family: 'LinearB';
            src: url('https://raw.githubusercontent.com/googlefonts/noto-fonts/master/hinted/ttf/NotoSansLinearB/NotoSansLinearB-Regular.ttf') format('truetype');
        }

        body {
            font-family: 'DejaVu Sans', 'LinearB', sans-serif;
            font-size: 11px;
            color: #334155;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        .symbol-font {
            font-family: 'DejaVu Sans', 'sans-serif' !important;
        }

        /* Protokoll Styles */
        .protocol-page {
            page-break-after: always;
        }

        .proto-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .proto-title {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 5px;
        }

        .proto-grid {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .proto-grid td {
            padding: 4px 0;
            vertical-align: top;
        }

        .proto-label {
            font-weight: bold;
            width: 120px;
            color: #64748b;
            font-size: 10px;
            text-transform: uppercase;
        }

        .part-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .part-table th {
            background: #f8fafc;
            text-align: left;
            padding: 8px;
            font-size: 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #64748b;
        }

        .part-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 10px;
        }

        .part-status-ja {
            color: #15803d;
            font-weight: bold;
        }

        .part-status-nein {
            color: #b91c1c;
        }

        /* List Styles */
        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid
                <?= $themeColor ?>
            ;
            padding-bottom: 10px;
        }

        .header h1 {
            margin: 0;
            color:
                <?= $themeColor ?>
            ;
            font-size: 18px;
        }

        .header-meta {
            font-size: 10px;
            color: #64748b;
            margin-top: 5px;
        }

        .list-container {
            width: 100%;
        }

        .item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 20px;
            padding: 12px;
            background: #ffffff;
            page-break-inside: avoid;
        }
        .item-table {
            width: 100%;
            border-collapse: collapse;
        }
        .item-table td {
            padding: 3px 0;
            vertical-align: top;
            font-size: 10px;
        }
        .label-bold {
            font-weight: bold;
            color: #1e293b;
        }
        .item-main-area {
            display: table;
            width: 100%;
        }
        .area-left {
            display: table-cell;
            width: 80px;
            vertical-align: top;
            padding-right: 12px;
        }
        .area-content {
            display: table-cell;
            vertical-align: top;
        }
        .area-right {
            display: table-cell;
            width: 70px;
            vertical-align: top;
            padding-left: 12px;
            text-align: right;
            border-left: 1px solid #f1f5f9;
        }
        .qr-code {
            width: 60px;
            height: 60px;
        }
        .pos-link {
            font-size: 7px;
            color: #3b82f6;
            text-decoration: none;
            display: block;
            margin-top: 2px;
        }
        .thumb {
            width: 80px;
            height: 80px;
            border-radius: 6px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
        }
        .status-pill {
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 9px;
            text-transform: uppercase;
        }
        .status-offen {
            background: #fee2e2;
            color: #b91c1c;
        }
        .status-in-bearbeitung {
            background: #fef9c3;
            color: #a16207;
        }
        .status-erledigt {
            background: #dcfce7;
            color: #15803d;
        }

        .footer {
            position: fixed;
            bottom: -5mm;
            left: 0;
            right: 0;
            font-size: 8px;
            text-align: center;
            color: #94a3b8;
        }
        .item-right {
            display: table-cell;
            width: 65px;
            vertical-align: top;
            text-align: right;
            padding-left: 10px;
            border-left: 1px solid #f1f5f9;
        }
        .qr-code {
            width: 55px;
            height: 55px;
            margin-bottom: 3px;
        }
        .pos-link {
            font-size: 7px;
            color: #3b82f6;
            text-decoration: none;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>

<body>

    <?php if ($protocol): ?>
        <div class="protocol-page">
            <?php if (!empty($protocol['layout']['blocks'])): ?>
                <!-- CUSTOM DESIGNER COVER -->
                <div style="position: relative; width: 100%; height: 842px; margin: -10mm;">
                    <?php
                    $sc = 0.75; // PX to PT conversion factor
                    foreach ($protocol['layout']['blocks'] as $b):
                        $val = $b['text'];
                        $val = str_replace('{TITEL}', he($protocol['TITEL'] ?? $protocol['title'] ?? 'Protokoll'), $val);
                        $val = str_replace('{BETREFF}', he($protocol['BETREFF'] ?? $protocol['subject'] ?? '-'), $val);
                        $val = str_replace('{PROJEKT}', he($protocol['PROJEKT'] ?? $protocol['project'] ?? '-'), $val);
                        $val = str_replace('{OBJEKT}', he($protocol['OBJEKT'] ?? $protocol['object'] ?? '-'), $val);
                        $val = str_replace('{WOHNUNG}', he($protocol['WOHNUNG'] ?? $protocol['apartment'] ?? '-'), $val);
                        $val = str_replace('{RAUM}', he($protocol['RAUM'] ?? $protocol['room'] ?? '-'), $val);
                        $val = str_replace('{DATUM}', he($protocol['DATUM'] ?? $protocol['datetime'] ?? date('d.m.Y')), $val);
                        $val = str_replace('{LEITUNG}', he($protocol['LEITUNG'] ?? $protocol['leader'] ?? '-'), $val);
                        $val = str_replace('{ORT}', he($protocol['ORT'] ?? $protocol['location'] ?? '-'), $val);

                        // Bau-Spezifisch
                        $val = str_replace('{OBJ_NR}', he($protocol['OBJ_NR'] ?? $protocol['obj_nr'] ?? '-'), $val);
                        $val = str_replace('{WV_DATE}', he($protocol['WV_DATE'] ?? $protocol['wv_date'] ?? '-'), $val);
                        $val = str_replace('{WV_NR}', he($protocol['WV_NR'] ?? $protocol['wv_nr'] ?? '-'), $val);
                        $val = str_replace('{BKP}', he($protocol['BKP'] ?? $protocol['bkp'] ?? '-'), $val);
                        $val = str_replace('{WEATHER}', he($protocol['WEATHER'] ?? $protocol['weather'] ?? '-'), $val);
                        $val = str_replace('{Temperatur}', he($protocol['Temperatur'] ?? $protocol['TEMP_RANGE'] ?? '-'), $val);
                        $val = str_replace('{TEMP_RANGE}', he($protocol['TEMP_RANGE'] ?? '-'), $val);
                        $val = str_replace('{DEADLINE}', he($protocol['DEADLINE'] ?? $protocol['deadline'] ?? '-'), $val);
                        $val = str_replace('{INTRO}', nl2br(he($protocol['INTRO'] ?? $protocol['intro'] ?? '')), $val);
                        $val = str_replace('{OUTRO}', nl2br(he($protocol['OUTRO'] ?? $protocol['outro'] ?? '')), $val);

                        // Komplexe Felder (HTML erlaubt falls Testmode)
                        if (str_contains($val, '{TEILNEHMER}')) {
                            $tVal = $protocol['TEILNEHMER'] ?? $protocol['participants_summary'] ?? '-';
                            if (str_contains($tVal, '<table')) { // Ist HTML
                                $val = str_replace('{TEILNEHMER}', $tVal, $val);
                            } else {
                                $val = str_replace('{TEILNEHMER}', he($tVal), $val);
                            }
                        }

                        if (str_contains($val, '{BLOCKS}')) {
                            $val = str_replace('{BLOCKS}', $protocol['BLOCKS'] ?? '', $val);
                        }

                        if (str_contains($val, '{SIG_UNTERNEHMER}')) {
                            $sigImg = !empty($protocol['sigUnternehmer']) ? '<img src="' . $protocol['sigUnternehmer'] . '" style="max-height:60pt; max-width:200pt; display:block; margin-bottom:5pt;">' : '';
                            $sig = '<div style="margin-top:10px;">' . $sigImg . '<div style="border-bottom:1px solid #1e293b; width:200pt;"></div>'
                                . '<div style="font-size:8pt; color:#64748b; margin-top:5px; text-transform:uppercase; font-weight:bold;">Unterschrift Unternehmer</div></div>';
                            $val = str_replace('{SIG_UNTERNEHMER}', $sig, $val);
                        }
                        if (str_contains($val, '{SIG_BESTELLER}')) {
                            $sigImg = !empty($protocol['sigBesteller']) ? '<img src="' . $protocol['sigBesteller'] . '" style="max-height:60pt; max-width:200pt; display:block; margin-bottom:5pt;">' : '';
                            $sig = '<div style="margin-top:10px;">' . $sigImg . '<div style="border-bottom:1px solid #1e293b; width:200pt;"></div>'
                                . '<div style="font-size:8pt; color:#64748b; margin-top:5px; text-transform:uppercase; font-weight:bold;">Unterschrift Besteller / Kunde</div></div>';
                            $val = str_replace('{SIG_BESTELLER}', $sig, $val);
                        }

                        if (str_contains($val, '{UNTERSCHRIFTEN}')) {
                            $sig = '<div style="border-top:1px solid #1e293b; margin-top:30pt; padding-top:5px; font-size:8pt; color:#64748b; width:150pt;">Unterschrift Bauleitung</div>';
                            $val = str_replace('{UNTERSCHRIFTEN}', $sig, $val);
                        }

                        if (str_contains($val, '{LOGO}')) {
                            $val = $brandLogo ? '<img src="' . $brandLogo . '" style="max-width:100%; max-height:100%; object-fit:contain;">' : '';
                        }
                        if (str_contains($val, '{HEADER}')) {
                            $headerHtml = '<strong>' . he($brand['name']) . '</strong><br>'
                                . he($brand['adresse']) . '<br>'
                                . he($brand['ort']) . '<br>'
                                . (!empty($brand['tel']) ? 'T: ' . he($brand['tel']) . '<br>' : '')
                                . (!empty($brand['email']) ? 'M: ' . he($brand['email']) : '');
                            $val = str_replace('{HEADER}', $headerHtml, $val);
                        }

                        $val = strtr($val, [
                            '𐃄' => getIcon('PROJEKT', $icons),
                            '𐃍' => getIcon('OBJEKT', $icons),
                            '𐃝' => getIcon('WOHNUNG', $icons),
                            '𐃅' => getIcon('TERMIN', $icons),
                            '𐊪' => getIcon('RAUM', $icons)
                        ]);
                        ?>
                        <div style="position: absolute; 
                                    left: <?= $b['x'] * $sc ?>pt; 
                                    top: <?= $b['y'] * $sc ?>pt; 
                                    width: <?= ($b['w'] ?? 100) * $sc ?>pt; 
                                    height: <?= ($b['h'] ?? 50) * $sc ?>pt; 
                                    font-size: <?= ($b['fontSize'] ?? 11) * $sc ?>pt; 
                                    font-weight: <?= !empty($b['bold']) ? 'bold' : 'normal' ?>; 
                                    font-style: <?= !empty($b['italic']) ? 'italic' : 'normal' ?>; 
                                    text-align: <?= $b['align'] ?? 'left' ?>; 
                                    color: <?= $b['color'] ?? '#000000' ?>;
                                    background-color: <?= $b['bgColor'] ?? 'transparent' ?>;
                                    border: <?= ($b['borderWidth'] ?? 0) * $sc ?>pt <?= $b['borderStyle'] ?? 'solid' ?> <?= $b['borderColor'] ?? 'transparent' ?>;
                                    border-radius: <?= ($b['borderRadius'] ?? 0) * $sc ?>pt;
                                    padding: <?= ($b['padding'] ?? 0) * $sc ?>pt;
                                    overflow: hidden;">
                            <?= (str_contains($val, '<img') || str_contains($val, '<table') || str_contains($val, '<div')) ? $val : nl2br($val) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- DEFAULT COVER -->
                <div class="proto-header">
                    <table style="width: 100%;">
                        <tr>
                            <td style="vertical-align: top;">
                                <div style="font-size: 11px; margin-bottom: 15px; color: #1e293b; line-height: 1.4;">
                                    <strong>
                                        <?= he($brand['name']) ?>
                                    </strong><br>
                                    <?= he($brand['adresse']) ?><br>
                                    <?= he($brand['ort']) ?><br>
                                    <?php if (!empty($brand['tel'])): ?>T
                                        <?= he($brand['tel']) ?><br>
                                    <?php endif; ?>
                                    <?php if (!empty($brand['web'])): ?><span style="color: <?= $themeColor ?>;">
                                            <?= he($brand['web']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: right; vertical-align: top;">
                                <?php if ($brandLogo): ?>
                                    <img src="<?= $brandLogo ?>" style="max-height: 45px;">
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <div class="proto-title">
                        <?= he($protocol['title'] ?: 'Protokoll') ?>
                    </div>
                    <div style="font-size: 12px; font-weight: bold;">
                        <?= he($protocol['subject']) ?>
                    </div>
                   <table class="proto-grid">
                    <?php if (!empty($protocol['project'])): ?>
                        <tr>
                            <td style="width: 25px; vertical-align: middle;"><?= getIcon('PROJEKT', $icons) ?></td>
                            <td class="proto-label">Projekt:</td>
                            <td><strong><?= he($protocol['project']) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($protocol['object'])): ?>
                        <tr>
                            <td style="width: 25px; vertical-align: middle;"><?= getIcon('OBJEKT', $icons) ?></td>
                            <td class="proto-label">Objekt:</td>
                            <td><strong><?= he($protocol['object']) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($protocol['apartment']) && $protocol['apartment'] !== '-- Wohnung wählen --'): ?>
                        <tr>
                            <td style="width: 25px; vertical-align: middle;"><?= getIcon('WOHNUNG', $icons) ?></td>
                            <td class="proto-label">Wohnung:</td>
                            <td><strong><?= he($protocol['apartment']) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($protocol['room']) && $protocol['room'] !== '-- Raum wählen --'): ?>
                        <tr>
                            <td style="width: 25px; vertical-align: middle;"><?= getIcon('RAUM', $icons) ?></td>
                            <td class="proto-label">Raum:</td>
                            <td><strong><?= he($protocol['room']) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td style="width: 25px; vertical-align: middle;"><?= getIcon('TERMIN', $icons) ?></td>
                        <td class="proto-label">Datum / Zeit:</td>
                        <td><?= he($protocol['datetime']) ?></td>
                    </tr>
                    <tr>
                        <td style="width: 25px; vertical-align: middle;"><?= getIcon('LOCATION', $icons) ?></td>
                        <td class="proto-label">Ort:</td>
                        <td><?= he($protocol['location']) ?></td>
                    </tr>
                    <?php if (!empty($protocol['leader'])): ?>
                        <tr>
                            <td style="width: 25px;"></td>
                            <td class="proto-label">Erstellt durch:</td>
                            <td><strong><?= he($protocol['leader']) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($protocol['vertreten_durch'])): ?>
                        <tr>
                            <td style="width: 25px;"></td>
                            <td class="proto-label">Vertreten durch:</td>
                            <td><strong><?= he($protocol['vertreten_durch']) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                </table>
            <?php endif; ?>

            <!-- Werkdetails Tabelle -->
            <?php if (!empty($protocol['obj_nr']) || !empty($protocol['wv_date']) || !empty($protocol['bkp']) || !empty($protocol['fix_date'])): ?>
                <div style="margin-top: 15px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                        <tr>
                            <td style="width: 120px; color: #64748b; font-weight: bold;">Objekt-Nr.:</td>
                            <td style="width: 150px;">
                                <?= he($protocol['obj_nr']) ?>
                            </td>
                            <td style="width: 120px; color: #64748b; font-weight: bold;">Betr. BKP:</td>
                            <td>
                                <?= he($protocol['bkp'] ?? '') ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color: #64748b; font-weight: bold;">Werkvertrag vom:</td>
                            <td>
                                <?= he($protocol['wv_date'] ?? '') ?>
                            </td>
                            <td style="color: #64748b; font-weight: bold;">Grundstück:</td>
                            <td>
                                <?= he($protocol['land'] ?? '') ?>
                            </td>
                        </tr>
                        <tr>
                            <td style="color: #64748b; font-weight: bold;">WV-Nr.:</td>
                            <td>
                                <?= he($protocol['wv_nr'] ?? '') ?>
                            </td>
                            <td style="color: #64748b; font-weight: bold; color: #ef4444;">Frist Behebung:</td>
                            <td style="color: #ef4444; font-weight: bold;">
                                <?= he($protocol['fix_date'] ?? '') ?>
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($protocol['participants'])): ?>
                <div style="font-weight: bold; margin-bottom: 10px; font-size: 12px;">Teilnehmer Sitzung</div>
                <table class="part-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Entschuldigt</th>
                            <th>Vorname / Name</th>
                            <th>Position</th>
                            <th>Email / Telefon</th>
                            <th>Firma</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($protocol['participants'] as $part): ?>
                            <tr>
                                <td class="part-status-<?= he($part['status'] ?? 'ja') ?>">
                                    <?= (($part['status'] ?? 'ja') === 'nein') ? 'ja' : ((($part['status'] ?? 'ja') === 'ja') ? 'nein' : 'abwesend') ?>
                                </td>
                                <td><strong>
                                        <?= he($part['name'] ?? '-') ?>
                                    </strong></td>
                                <td>
                                    <?= he($part['position'] ?? '-') ?>
                                </td>
                                <td style="font-size: 9px;">
                                    <span style="color: #1e40af;">
                                        <?= he($part['email'] ?? '') ?>
                                    </span><br>
                                    <?= he($part['tel'] ?? '') ?>
                                </td>
                                <td>
                                    <?= he($part['company'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- Inhaltsblöcke -->
            <?php if (!empty($protocol['custom_sections'])): ?>
                <?php foreach ($protocol['custom_sections'] as $sec): ?>
                    <div
                        style="margin-top: 15px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px;">
                        <div
                            style="font-weight: bold; font-size: 11px; margin-bottom: 5px; color: #1e293b; text-transform: uppercase;">
                            <?= he($sec['title']) ?>
                        </div>
                        <div style="font-size: 10px; line-height: 1.5; color: #334155;">
                            <?= nl2br(he($sec['content'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($protocol['intro'])): ?>
                <div style="margin-top: 20px; border-left: 4px solid #e2e8f0; padding-left: 15px;">
                    <div
                        style="font-weight: bold; font-size: 11px; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">
                        Einleitung / Zusammenfassung</div>
                    <div style="font-size: 10px; line-height: 1.4; color: #334155;">
                        <?= nl2br(he($protocol['intro'])) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Abnahme Details (Art 159-161) -->
            <?php if (!empty($protocol['art159']) || !empty($protocol['art160']) || !empty($protocol['art161'])): ?>
                <div style="margin-top: 25px; background: #fff; border: 2px solid #f1f5f9; padding: 15px; border-radius: 8px;">
                    <div style="font-weight: bold; font-size: 11px; margin-bottom: 10px; color: #1e293b;">Regelungen & Abnahme
                        (SIA 118)</div>
                    <table style="width: 100%; font-size: 10px; line-height: 1.6;">
                        <?php if (!empty($protocol['art159']) && $protocol['art159'] !== 'false'): ?>
                            <tr>
                                <td style="width: 25px; vertical-align: top;">✅</td>
                                <td><strong>Art. 159 Abs. 1:</strong> Die Abnahme erfolgt nach Vollendung des Werkes. Der Bauleiter
                                    teilt dem Unternehmer den Termin der Abnahme rechtzeitig mit.</td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($protocol['art160']) && $protocol['art160'] !== 'false'): ?>
                            <tr>
                                <td style="width: 25px; vertical-align: top;">✅</td>
                                <td><strong>Art. 160 Abs. 1:</strong> Über die Abnahme wird ein Protokoll erstellt, das von der
                                    Bauleitung und dem Unternehmer unterzeichnet wird.</td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($protocol['art161']) && $protocol['art161'] !== 'false'): ?>
                            <tr>
                                <td style="width: 25px; vertical-align: top;">✅</td>
                                <td><strong>Art. 161 Abs. 1:</strong> Mit der Unterzeichnung des Abnahmeprotokolls gilt das Werk als
                                    abgenommen.</td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($protocol['outro'])): ?>
                <div style="margin-top: 20px;">
                    <div
                        style="font-weight: bold; font-size: 11px; color: #64748b; margin-bottom: 5px; text-transform: uppercase;">
                        Schlusswort / Regelung</div>
                    <div style="font-size: 10px; line-height: 1.4; color: #334155; font-style: italic;">
                        <?= nl2br(he($protocol['outro'])) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Unterschriften Bereich -->
            <?php if (!empty($protocol['sigBesteller']) || !empty($protocol['sigUnternehmer'])): ?>
                <div style="margin-top: 40px; page-break-inside: avoid;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="width: 48%; vertical-align: top;">
                                <div style="font-size: 9px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px;">
                                    Unterschrift Besteller / Kunde
                                </div>
                                <?php if (!empty($protocol['sigBesteller']) && $protocol['sigBesteller'] !== 'data:,'): ?>
                                    <div style="height: 80px; text-align: center; background: #fff;">
                                        <img src="<?= $protocol['sigBesteller'] ?>" style="max-height: 75px; max-width: 100%;">
                                    </div>
                                <?php else: ?>
                                    <div style="height: 80px; border-bottom: 1px dashed #cbd5e1;"></div>
                                <?php endif; ?>
                            </td>
                            <td style="width: 4%;"></td>
                            <td style="width: 48%; vertical-align: top;">
                                <div style="font-size: 9px; color: #64748b; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px;">
                                    Unterschrift Unternehmer
                                </div>
                                <?php if (!empty($protocol['sigUnternehmer']) && $protocol['sigUnternehmer'] !== 'data:,'): ?>
                                    <div style="height: 80px; text-align: center; background: #fff;">
                                        <img src="<?= $protocol['sigUnternehmer'] ?>" style="max-height: 75px; max-width: 100%;">
                                    </div>
                                <?php else: ?>
                                    <div style="height: 80px; border-bottom: 1px dashed #cbd5e1;"></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

        <?php endif;

    // If only_cover is requested (from designer preview), stop here
    if (!empty($_GET['only_cover'])) {
        echo '</body></html>';
        exit;
    }
    ?>

        <div class="header">
            <table style="width:100%">
                <tr>
                    <td>
                        <h1>
                            <?= he($protocol['list_title'] ?? 'Pendenzenliste') ?>
                        </h1>
                    </td>
                </tr>
            </table>
        </div>

        <div class="list-container">
            <?php if ($sortMode === 'raum'):
                // --- RAUM-SORTIERUNG: Gruppenweise nach Wohnung + Raum ---
                $groups = [];
                foreach ($pendenzen as $p) {
                    $wName = $p['wohnung_name'] ?: 'Ohne Wohnung';
                    $rName = $p['raum_name'] ?: 'Ohne Raum';
                    $groupKey = $wName . '|||' . $rName;
                    $groups[$groupKey][] = $p;
                }
                ksort($groups);

                foreach ($groups as $groupKey => $items):
                    [$wName, $rName] = explode('|||', $groupKey, 2);
                    ?>
                    <div
                        style="background: #e2e8f0; border-radius: 6px; padding: 8px 12px; margin-bottom: 6px; margin-top: 14px; page-break-inside: avoid; display: flex; align-items: center; gap: 10px;">
                        <span style="font-weight: bold; font-size: 12px; color: #1e293b;">
                            <?= he($wName) ?>
                        </span>
                        <span style="color: #64748b; font-size: 11px;">•</span>
                        <span style="font-size: 11px; color: #475569; font-weight: 600;">
                            <?= he($rName) ?>
                        </span>
                        <span style="margin-left: auto; font-size: 9px; color: #94a3b8;">
                            <?= count($items) ?> Aufgabe
                            <?= count($items) !== 1 ? 'n' : '' ?>
                        </span>
                    </div>

                    <?php foreach ($items as $p):
                        $imgSrc = null;
                        if ($p['first_image']) {
                            $abs = realpath(__DIR__ . '/../' . ltrim($p['first_image'], '/'));
                            if ($abs && is_file($abs)) {
                                $data = @file_get_contents($abs);
                                if ($data)
                                    $imgSrc = 'data:image/jpeg;base64,' . base64_encode($data);
                            }
                        }
                        ?>
                        <div class="item">
                            <div class="item-main-area">
                                <div class="area-left">
                                    <?php if ($imgSrc): ?>
                                        <img src="<?= $imgSrc ?>" class="thumb">
                                    <?php else: ?>
                                        <div class="thumb" style="display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:8px; text-align:center; background:#f8fafc;">Kein Bild</div>
                                    <?php endif; ?>
                                </div>
                                <div class="area-content">
                                    <table class="item-table">
                                        <tr>
                                            <td style="width:50%"><span class="label-bold">Pendenz Nr.:</span> #<?= $p['id'] ?></td>
                                            <td><span class="label-bold">Pendenz Status:</span> <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $p['status'])) ?>"><?= he($p['status']) ?></span></td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="border-top:1px solid #f1f5f9; padding-top:5px;">
                                                <span class="label-bold">Projekt:</span> <?= he($p['projekt_name']) ?> &nbsp;&nbsp;
                                                <span class="label-bold">Objekt:</span> <?= he($p['objekt_name']) ?> &nbsp;&nbsp;
                                                <span class="label-bold">Wohnung:</span> <?= he($p['wohnung_name']) ?> &nbsp;&nbsp;
                                                <span class="label-bold">Raum:</span> <?= he($p['raum_name']) ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2">
                                                <span class="label-bold">Titel:</span> <?= he($p['titel']) ?><br>
                                                <span class="label-bold">Beschreibung:</span> <?= nl2br(he($p['kurzbeschreibung'])) ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="border-top:1px solid #f1f5f9; padding-top:5px;">
                                                <span class="label-bold">Beginn:</span> <?= (!empty($p['startdatum']) && $p['startdatum'] !== '0000-00-00') ? date('d.m.Y', strtotime($p['startdatum'])) : '-' ?> &nbsp;&nbsp;
                                                <span class="label-bold">Dauer:</span> <?= he($p['dauer'] ?: '-') ?> &nbsp;&nbsp;
                                                <span class="label-bold">Zu erledigen bis:</span> <?= (!empty($p['enddatum']) && $p['enddatum'] !== '0000-00-00') ? date('d.m.Y', strtotime($p['enddatum'])) : '-' ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="border-top:1px solid #f1f5f9; padding-top:5px;">
                                                <span class="label-bold">Zuständig:</span> <?= he($p['zustaendig_name']) ?><?= !empty($p['zustaendig_firma']) ? ' (' . he($p['zustaendig_firma']) . ')' : '' ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="area-right">
                                    <?php 
                                        $itemUrl = base_url("pages/pendenz_show.php?id=" . $p['id']);
                                        if (!empty($p['public_token'])) {
                                            $itemUrl = base_url("pages/pendenz_public.php?t=" . $p['public_token']);
                                        }
                                        $qrUrl = "https://quickchart.io/qr?text=" . urlencode($itemUrl) . "&size=150";
                                    ?>
                                    <img src="<?= $qrUrl ?>" class="qr-code">
                                    <a href="<?= $itemUrl ?>" class="pos-link">Details</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            <?php else: // --- NUMMER-SORTIERUNG (Standard) --- ?>
                <?php foreach ($pendenzen as $p):
                    $imgSrc = null;
                    if ($p['first_image']) {
                        $abs = realpath(__DIR__ . '/../' . ltrim($p['first_image'], '/'));
                        if ($abs && is_file($abs)) {
                            $data = @file_get_contents($abs);
                            if ($data)
                                $imgSrc = 'data:image/jpeg;base64,' . base64_encode($data);
                        }
                    }
                    ?>
                    <div class="item">
                        <div class="item-main-area">
                            <div class="area-left">
                                <?php if ($imgSrc): ?>
                                    <img src="<?= $imgSrc ?>" class="thumb">
                                <?php else: ?>
                                    <div class="thumb" style="display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:8px; text-align:center; background:#f8fafc;">Kein Bild</div>
                                <?php endif; ?>
                            </div>
                            <div class="area-content">
                                <table class="item-table">
                                    <tr>
                                        <td style="width:50%"><span class="label-bold">Pendenz Nr.:</span> #<?= $p['id'] ?></td>
                                        <td><span class="label-bold">Pendenz Status:</span> <span class="status-pill status-<?= strtolower(str_replace(' ', '-', $p['status'])) ?>"><?= he($p['status']) ?></span></td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="border-top:1px solid #f1f5f9; padding-top:5px;">
                                            <span class="label-bold">Projekt:</span> <?= he($p['projekt_name']) ?> &nbsp;&nbsp;
                                            <span class="label-bold">Objekt:</span> <?= he($p['objekt_name']) ?> &nbsp;&nbsp;
                                            <span class="label-bold">Wohnung:</span> <?= he($p['wohnung_name']) ?> &nbsp;&nbsp;
                                            <span class="label-bold">Raum:</span> <?= he($p['raum_name']) ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2">
                                            <span class="label-bold">Titel:</span> <?= he($p['titel']) ?><br>
                                            <span class="label-bold">Beschreibung:</span> <?= nl2br(he($p['kurzbeschreibung'])) ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="border-top:1px solid #f1f5f9; padding-top:5px;">
                                            <span class="label-bold">Beginn:</span> <?= (!empty($p['startdatum']) && $p['startdatum'] !== '0000-00-00') ? date('d.m.Y', strtotime($p['startdatum'])) : '-' ?> &nbsp;&nbsp;
                                            <span class="label-bold">Dauer:</span> <?= he($p['dauer'] ?: '-') ?> &nbsp;&nbsp;
                                            <span class="label-bold">Zu erledigen bis:</span> <?= (!empty($p['enddatum']) && $p['enddatum'] !== '0000-00-00') ? date('d.m.Y', strtotime($p['enddatum'])) : '-' ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="border-top:1px solid #f1f5f9; padding-top:5px;">
                                            <span class="label-bold">Zuständig:</span> <?= he($p['zustaendig_name']) ?><?= !empty($p['zustaendig_firma']) ? ' (' . he($p['zustaendig_firma']) . ')' : '' ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="area-right">
                                <?php 
                                    $itemUrl = base_url("pages/pendenz_show.php?id=" . $p['id']);
                                    if (!empty($p['public_token'])) {
                                        $itemUrl = base_url("pages/pendenz_public.php?t=" . $p['public_token']);
                                    }
                                    $qrUrl = "https://quickchart.io/qr?text=" . urlencode($itemUrl) . "&size=150";
                                ?>
                                <img src="<?= $qrUrl ?>" class="qr-code">
                                <a href="<?= $itemUrl ?>" class="pos-link">Details</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            Seite
            <script type="text/php">echo $PAGE_NUM . " von " . $PAGE_COUNT;</script> | pendenz.com - Professionelles
            Aufgabenmanagement
        </div>
</body>

</html>
<?php
$html = ob_get_clean();

$opt = new Options();
$opt->set('isRemoteEnabled', true);
$dom = new Dompdf($opt);
$dom->loadHtml($html);
$dom->setPaper('A4', 'portrait');
$dom->render();
$dom->stream("pendenzenliste_" . date('Ymd_His') . ".pdf", ["Attachment" => false]);
