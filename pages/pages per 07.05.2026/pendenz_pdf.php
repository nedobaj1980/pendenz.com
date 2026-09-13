<?php
// pages/pendenz_pdf.php
if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/media.php';
require_once __DIR__ . '/../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
$token = $_GET['t'] ?? '';
$isPublicAccess = false;

if ($id > 0 && !empty($token)) {
    // Sicherer Prepared-Statement Check
    $stPublic = $mysqli->prepare("SELECT id FROM pendenzen WHERE id=? AND public_enabled=1 AND public_token=? LIMIT 1");
    if ($stPublic) {
        $stPublic->bind_param("is", $id, $token);
        $stPublic->execute();
        $resPublic = $stPublic->get_result();
        if ($resPublic && $resPublic->num_rows > 0) {
            $isPublicAccess = true;
        }
    }
}

// Logging für Debugging
@file_put_contents(__DIR__ . '/../logs/pdf_access.log', date('Y-m-d H:i:s') . " | ID: $id | Token: $token | Access: " . ($isPublicAccess ? 'GRANTED' : 'DENIED') . "\n", FILE_APPEND);

if (!$isPublicAccess) {
    require_login();
}

use Dompdf\Dompdf;
use Dompdf\Options;

// redundant get_user_with_company removed (defined in functions.php)

if ($id<=0) { http_response_code(400); exit('id missing'); }

$r = $mysqli->query("
    SELECT p.*, pr.name AS projekt_name, w.name AS wohnung_name, o.name AS objekt_name,
           va.name AS vorgangsart_name, ra.name AS raum_name
    FROM pendenzen p 
    LEFT JOIN projekte pr ON pr.id=p.projekt_id 
    LEFT JOIN wohnungen w ON w.id=p.wohnung_id
    LEFT JOIN objekte o ON o.id=w.objekt_id
    LEFT JOIN pendenzen_arten va ON va.id=p.vorgangsart_id
    LEFT JOIN raeume ra ON ra.id=p.raum_id
    WHERE p.id={$id} LIMIT 1
");
$p = $r ? $r->fetch_assoc() : null;
if(!$p) { http_response_code(404); exit('not found'); }
if(!$isPublicAccess && !can_view_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) { http_response_code(403); exit('forbidden'); }

$cfg = json_decode($p['extra_json'] ?? 'null', true) ?: [];
$view = $cfg['_view'] ?? [];
$view += [
  'layout'=>'classic','brand_source'=>'firma_logo','brand_from'=>'ersteller','hero'=>'cover',
  'sections'=>['meta','kurzbeschreibung','langbeschreibung','notiz','bilder','anhaenge'],
  'meta_fields'=>['status','wichtigkeit','startdatum','enddatum','erstellt_am','geaendert_am','projekt_name','zustaendig_name','erstellt_von_name']
];

$assigneeUser = get_user_with_company($mysqli, $p['zustaendig_id']);
$createdUser = get_user_with_company($mysqli, $p['erstellt_von']);
$currentUser = get_user_with_company($mysqli, $_SESSION['user_id'] ?? 0);

// --- NEU: Maske aus Settings laden ---
$resMask = $mysqli->query("SELECT v FROM settings WHERE k = 'pdf_mask_default'");
$maskCfg = $resMask && $resMask->num_rows ? json_decode($resMask->fetch_assoc()['v'], true) : null;

// Branding-Reihenfolge: 
// 1. Wenn explizit 'zustaendiger' gewählt wurde -> Zuständiger
// 2. Sonst der aktuell eingeloggte Admin (User-Wunsch)
// 3. Fallback auf Ersteller
// 4. Fallback auf Zuständiger
$brandUser = ($view['brand_from'] === 'zustaendiger' ? $assigneeUser : ($currentUser ?: $createdUser)) ?: $createdUser ?: $assigneeUser;
$brandPath = null;
if ($view['brand_source']==='firma_logo' && !empty($brandUser['firmenlogo']))     $brandPath = $brandUser['firmenlogo'];
if ($view['brand_source']==='user_titelbild' && !empty($brandUser['titelbild']))   $brandPath = $brandUser['titelbild'];
if ($view['brand_source']==='user_profilbild' && !empty($brandUser['profilbild'])) $brandPath = $brandUser['profilbild'];

$imgs=[]; $files=[];
$res = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$id} ORDER BY is_cover DESC, sort_index IS NULL, sort_index, id");
while($row=$res->fetch_assoc()){ if($row['typ']==='image') $imgs[]=$row; else $files[]=$row; }
$hero = null;
if ($view['hero']==='first') { $hero = $imgs[0] ?? null; }
else { foreach($imgs as $im){ if((int)$im['is_cover']===1){ $hero=$im; break; } } if(!$hero) $hero=$imgs[0]??null; }

$publicUrl = null; $qrUrl=null;
if ((int)($p['public_enabled']??0)===1 && !empty($p['public_token'])) {
  $publicUrl = base_url("pages/pendenz_public.php?t=" . rawurlencode($p['public_token']));
}
// Immer einen QR-Code erzeugen (wenn kein public link, dann permalink)
$qrData = $publicUrl ?: base_url('pages/pendenz_show.php?id=' . $id);
$qrUrl = "https://quickchart.io/qr?text=".rawurlencode($qrData)."&size=120";

$fmtDate=function($d){ if(!$d) return ''; $ts=strtotime($d); return $ts?date('d.m.Y',$ts):$d; };
$status = $p['status']==='in Bearbeitung' ? 'in&nbsp;Bearbeitung' : htmlspecialchars($p['status']);

// Meta-Felder zusammenbauen
$smartVals = [
  'projekt_name'=>$p['projekt_name']??'',
  'zustaendig_name'=>($assigneeUser['name']??''),
  'erstellt_von_name'=>($createdUser['name']??'')
];
$labels=[];
$colsRes = $mysqli->query("SHOW COLUMNS FROM pendenzen"); while($c=$colsRes->fetch_assoc()){ $labels[$c['Field']] = ucwords(str_replace(['_','-'],' ',$c['Field'])); }
$labels += ['projekt_name'=>'Projekt','zustaendig_name'=>'Zuständig','erstellt_von_name'=>'Erstellt von','wohnung_name'=>'Wohnung','objekt_name'=>'Objekt'];
$smartVals += [
  'wohnung_name'=>$p['wohnung_name']??'',
  'objekt_name'=>$p['objekt_name']??''
];
$metaItems=[];
foreach($view['meta_fields'] as $k){
  $lbl = $labels[$k] ?? ucwords(str_replace('_',' ',$k));
  $val = $smartVals[$k] ?? ($p[$k] ?? '');
  if (in_array($k,['startdatum','enddatum','erstellt_am','geaendert_am','created_at','updated_at','deleted_at','confirmation_at','submitted_at','reviewed_at'],true) && $val) {
    $val = $fmtDate($val);
  }
  $metaItems[]=['label'=>$lbl, 'value'=>$val];
}

// PDF HTML
ob_start(); ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    <?php 
      $pW_px = $maskCfg['pageW'] ?? 794;
      $pH_px = $maskCfg['pageH'] ?? 1123;
      // px to mm: px / 3.78
      $pW_mm = round($pW_px / 3.78, 1);
      $pH_mm = round($pH_px / 3.78, 1);
    ?>
    @page { size: <?= $pW_mm ?>mm <?= $pH_mm ?>mm; margin: 0; }
    body { font-family: DejaVu Sans, Arial, Helvetica, sans-serif; font-size: 12px; color:#222; margin: 0; padding: 0; width: <?= $pW_mm ?>mm; height: <?= $pH_mm ?>mm; }
    h1 { font-size: 20px; margin: 0 0 6px 0; }
    h2 { font-size: 14px; margin: 12px 0 6px 0; }
    .row { width:100%; }
    .left { float:left; width:70%; }
    .right { float:right; width:28%; text-align:right; }
    .header-top { width: 100%; margin-bottom: 20px; border-bottom: 2px solid #1abc9c; padding-bottom: 10px; }
    .header-left { float: left; width: 60%; }
    .header-right { float: right; width: 38%; text-align: right; }
    .brand { max-width: 160px; max-height: 80px; object-fit: contain; }
    .brand-info { font-size: 11px; color: #444; line-height: 1.4; }
    .hero { width:100%; height:220px; object-fit:cover; border:1px solid #ddd; border-radius:6px; }
    .meta { display: table; width: 100%; table-layout: fixed; margin-top:6px; }
    .item { display: table-row; }
    .item .k, .item .v { display: table-cell; padding:4px 6px; border-bottom:1px solid #eee; vertical-align:top; }
    .item .k { width:30%; color:#555; }
    .box { border:1px solid #ccc; padding:8px; border-radius:6px; margin-top:6px; }
    .imggrid { display: table; width: 100%; table-layout: fixed; border-spacing: 6px; }
    .imggrid .cell { display: table-cell; vertical-align: middle; text-align:center; border:1px solid #eee; border-radius:4px; height: 110px; }
    .muted { color:#666; }
    ul { margin: 6px 0 0 16px; }
    .clearfix::after{content:"";display:block;clear:both}

    /* Masken-Modus Styles */
    .absolute-container { position: relative; width: 100%; height: 260mm; }
    .absolute-block { position: absolute; overflow: hidden; border: 1px solid transparent; line-height: 1.2; }
    /* Vererbung erzwingen, ohne die Tabellen-Struktur zu zerstören */
    .absolute-block h1, .absolute-block h2, .absolute-block h3, .absolute-block p, .absolute-block div, .absolute-block span { 
        font-family: inherit !important;
        font-size: inherit !important; 
        font-weight: inherit !important; 
        font-style: inherit !important; 
        text-decoration: inherit !important;
        text-align: inherit !important;
        margin: 0; padding: 0;
    }
    .absolute-block h1, .absolute-block h2, .absolute-block h3 { display: block; }
    .absolute-block table { font-size: inherit !important; font-weight: inherit !important; }
    .abs-header { border-bottom: 2px solid <?= $maskCfg['color'] ?? '#1abc9c' ?>; }
  </style>
</head>
<body>
<?php if ($maskCfg && isset($maskCfg['blocks'])): ?>
  <div class="absolute-container" style="padding: <?= $maskCfg['margin'] ?? 0 ?>px;">
    <?php foreach ($maskCfg['blocks'] as $block): 
        $x = $block['x'] ?? 0; $y = $block['y'] ?? 0; $w = $block['w'] ?? 200; $h = $block['h'] ?? 50;
        $color = $maskCfg['color'] ?? '#1abc9c';
        
        $text = $block['text'] ?? '';
        $text = str_replace('{COLOR}', $color, $text);
        
        // --- SUPER-TAGS ---
        
        // {HEADER}
        if (strpos($text, '{HEADER}') !== false) {
           $hHtml = '<div style="border-bottom:2px solid '.$color.'; padding-bottom:5px; margin-bottom:10px;">';
           $hHtml .= '<table style="width:100%"><tr>';
           $hHtml .= '<td style="font-size:9px; color:#666; vertical-align:top;">';
           if(!empty($brandUser['firma_name'])) $hHtml .= '<strong>'.htmlspecialchars($brandUser['firma_name']).'</strong><br>';
           if(!empty($brandUser['firma_strasse'])) {
               $hHtml .= htmlspecialchars($brandUser['firma_strasse']).' '.htmlspecialchars($brandUser['firma_hausnummer']??'').'<br>';
           }
           if(!empty($brandUser['firma_plz']) || !empty($brandUser['firma_ort'])) {
               $hHtml .= htmlspecialchars(($brandUser['firma_plz']??'').' '.($brandUser['firma_ort']??'')).'<br>';
           } elseif (!empty($brandUser['firma_adresse_full'])) {
               $hHtml .= htmlspecialchars($brandUser['firma_adresse_full']).'<br>';
           }
           if(!empty($brandUser['firma_telefon'])) $hHtml .= 'Tel: '.htmlspecialchars($brandUser['firma_telefon']).'<br>';
           if(!empty($brandUser['firma_email'])) $hHtml .= htmlspecialchars($brandUser['firma_email']).'<br>';
           $hHtml .= '</td>';
           $hHtml .= '<td style="text-align:right; vertical-align:top;">';
           
           $logoPath = $brandUser['firmenlogo'] ?? '';
           if ($logoPath) {
               $bp = (defined('BASE_PATH') && BASE_PATH) ? BASE_PATH : '';
               if ($bp && strpos($logoPath, $bp) === 0) $logoPath = substr($logoPath, strlen($bp));
               $abs = realpath(__DIR__.'/../'.ltrim($logoPath,'/'));
               if ($abs && is_file($abs)) {
                   $data = @file_get_contents($abs);
                   if ($data) {
                       $finfo = new finfo(FILEINFO_MIME_TYPE);
                       $mime = $finfo->buffer($data);
                       $b64 = 'data:'.$mime.';base64,'.base64_encode($data);
                       $hHtml .= '<img src="'.$b64.'" style="height:45px; max-width:250px; object-fit:contain;">';
                   }
               }
           }
           $hHtml .= '</td></tr></table></div>';
           $text = str_replace('{HEADER}', $hHtml, $text);
        }

        // {META}
        if (strpos($text, '{META}') !== false) {
           $mHtml = '<table style="width:100%; border-collapse:collapse; font-size:10px;">';
           foreach($metaItems as $it) {
               if (empty($it['value']) || $it['value'] === '-') continue;
               $mHtml .= '<tr>';
               $mHtml .= '<td style="color:#666; padding:2px 0; border-bottom:1px solid #eee; width:40%;">'.htmlspecialchars($it['label']??'').':</td>';
               $mHtml .= '<td style="padding:2px 0; border-bottom:1px solid #eee;"><strong>'.htmlspecialchars($it['value']??'').'</strong></td>';
               $mHtml .= '</tr>';
           }
           $mHtml .= '</table>';
           $text = str_replace('{META}', $mHtml, $text);
        }

        // {FOOTER}
        if (strpos($text, '{FOOTER}') !== false) {
           $fHtml = '<div style="border-top:1px solid #eee; padding-top:5px; font-size:8px; color:#999; display:flex; justify-content:space-between;">';
           $fHtml .= '<div>Generiert am '.date('d.m.Y').'</div>';
           if($qrUrl) $fHtml .= ' <img src="'.htmlspecialchars($qrUrl).'" style="width:30px; height:30px; float:right;">';
           $fHtml .= '</div>';
           $text = str_replace('{FOOTER}', $fHtml, $text);
        }

        // --- GRANULARE PERSONEN-TAGS (Zuständiger) ---
        $z = $assigneeUser ?: [];
        $zName = trim(($z['vorname']??'').' '.($z['nachname']??''));
        if (!$zName) $zName = $z['name'] ?? '';
        
        $zReps = [
            '{ZUSTAENDIG_NAME}' => htmlspecialchars($zName),
            '{ZUSTAENDIG_FIRMA}' => htmlspecialchars($z['firma_name']??''),
            '{ZUSTAENDIG_EMAIL}' => htmlspecialchars($z['firma_email']??$z['email']??''),
            '{ZUSTAENDIG_TELEFON}' => htmlspecialchars($z['firma_telefon']??$z['telefonnummer']??''),
            '{ZUSTAENDIG_HOMEPAGE}' => htmlspecialchars($z['firma_website']??''),
            '{ZUSTAENDIG_STRASSE}' => htmlspecialchars($z['firma_strasse']??$z['strasse']??''),
            '{ZUSTAENDIG_PLZ}' => htmlspecialchars($z['firma_plz']??$z['plz']??''),
            '{ZUSTAENDIG_ORT}' => htmlspecialchars($z['firma_ort']??$z['ort']??''),
            '{ZUSTAENDIG_ADRESSE}' => htmlspecialchars(trim(($z['firma_strasse']??$z['strasse']??'').' '.($z['firma_hausnummer']??$z['hausnummer']??'').', '.($z['firma_plz']??$z['plz']??'').' '.($z['firma_ort']??$z['ort']??''), ', ')),
        ];
        foreach($zReps as $tk => $tv) $text = str_replace($tk, $tv, $text);

        // Bild-Tags für Personen & Logo
        $imgTags = [
            '{ZUSTAENDIG_LOGO}' => $z['firmenlogo'] ?? '',
            '{ZUSTAENDIG_PROFILBILD}' => $z['profilbild'] ?? $z['bild'] ?? '',
            '{ZUSTAENDIG_TITELBILD}' => $z['titelbild'] ?? '',
            '{LOGO}' => $brandUser['firmenlogo'] ?? '',
        ];

        foreach($imgTags as $tag => $relPath) {
            if (strpos($text, $tag) !== false) {
                $html = '';
                if ($relPath) {
                    $data = null;
                    if (strpos($relPath, 'http') === 0) {
                        $data = @file_get_contents($relPath);
                    } else {
                        // More robust path stripping
                        $cleanPath = $relPath;
                        $prefixes = ['/pendenz.com', 'pendenz.com'];
                        if (defined('BASE_PATH') && BASE_PATH) $prefixes[] = BASE_PATH;
                        foreach($prefixes as $pfx) {
                            if (strpos($cleanPath, $pfx) === 0) {
                                $cleanPath = substr($cleanPath, strlen($pfx));
                                break;
                            }
                        }
                        $abs = realpath(__DIR__.'/../'.ltrim($cleanPath,'/'));
                        if ($abs && is_file($abs)) $data = @file_get_contents($abs);
                    }
                    
                    if ($data) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = explode(';', $finfo->buffer($data))[0];
                        $b64 = 'data:'.$mime.';base64,'.base64_encode($data);
                        $style = $tag === '{ZUSTAENDIG_PROFILBILD}' ? 'border-radius:50%;' : '';
                        $html = '<img src="'.$b64.'" style="max-width:100%; max-height:100%; object-fit:contain; '.$style.'">';
                    }
                }
                $text = str_replace($tag, $html, $text);
            }
        }

        // Meta-Felder Formular (Alle ausgefüllten Daten als Tabelle)
        $formHtml = '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
        foreach ($metaItems as $it) {
            if (empty($it['value']) || $it['value'] === '-') continue;
            $formHtml .= '<tr>';
            $formHtml .= '<td style="padding:4px; border-bottom:1px solid #eee; width:150px; color:#666;">'.htmlspecialchars($it['label']).'</td>';
            $formHtml .= '<td style="padding:4px; border-bottom:1px solid #eee;"><strong>'.htmlspecialchars($it['value']).'</strong></td>';
            $formHtml .= '</tr>';
        }
        $formHtml .= '</table>';

        // Bilder für Cover und Erstes Bild vorbereiten
        $makeImgTag = function($relPath, $style='max-width:100%; max-height:100%; object-fit:cover;') {
            if (!$relPath) return '';
            if (strpos($relPath, 'http') === 0) { $data = @file_get_contents($relPath); }
            else { $bp = defined('BASE_PATH') ? BASE_PATH : ''; if ($bp && strpos($relPath, $bp) === 0) $relPath = substr($relPath, strlen($bp)); $abs = realpath(__DIR__.'/../'.ltrim($relPath,'/')); $data = ($abs && is_file($abs)) ? @file_get_contents($abs) : null; }
            if (!$data) return '';
            return '<img src="data:image/jpeg;base64,'.base64_encode($data).'" style="'.$style.'">';
        };
        $cHeight = (int)($maskCfg['cover_height'] ?? 220);
        $gHeight = (int)($maskCfg['gallery_height'] ?? 110);

        $coverImg = $hero ? $makeImgTag($hero['pfad'], 'width:100%; max-height:'.$cHeight.'px; object-fit:cover;') : '';
        $erstesImg = !empty($imgs[0]) ? $makeImgTag($imgs[0]['pfad'], 'max-width:100%; max-height:'.$cHeight.'px;') : '';

        // Dokumente-Liste
        $docsHtml = '<ul style="font-size:10px; margin:0; padding-left:16px;">';
        $pdfDocsHtml = '<ul style="font-size:10px; margin:0; padding-left:16px;">';
        foreach ($files as $fd) {
            $fname = htmlspecialchars(basename($fd['pfad']));
            $fsize = number_format((int)($fd['groesse']??0)/1024, 0, "'", '.') . ' KB';
            $docsHtml .= "<li>📄 {$fname} ({$fsize})</li>";
            if (stripos($fd['mimetype']??'', 'pdf') !== false) $pdfDocsHtml .= "<li>📕 {$fname} ({$fsize})</li>";
        }
        $docsHtml .= '</ul>';
        $pdfDocsHtml .= '</ul>';

        // Link-Logik
        $host = (is_https_request() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'pendenz.com');
        $baseUrl = $host . rtrim(_site_prefix(), '/') . '/pages/';
        $publicToken = $p['public_token'] ?? '';
        $isPublic = ((int)($p['public_enabled']??0) === 1 && !empty($publicToken));
        $taskLink = $isPublic ? ($baseUrl . 'pendenz_public.php?t=' . $publicToken) : ($baseUrl . 'pendenz_show.php?id=' . $p['id']);
        $linkHtml = '<a href="'.htmlspecialchars($taskLink).'" style="color:#2563eb; text-decoration:none; font-weight:500;">'.htmlspecialchars($taskLink).'</a>';

        $reps = [
          '{TITEL}' => trim(htmlspecialchars($p['titel'])),
          '{TITLE}' => trim(htmlspecialchars($p['titel'])),
          '{PROJEKT}' => trim(htmlspecialchars($p['projekt_name']??'')),
          '{PROJECT}' => trim(htmlspecialchars($p['projekt_name']??'')),
          '{OBJEKT}' => trim(htmlspecialchars($p['objekt_name']??'')),
          '{WOHNUNG}' => trim(htmlspecialchars($p['wohnung_name']??'')),
          '{RAUM}' => trim(htmlspecialchars($p['raum_name']??'')),
          '{DAUER}' => trim(htmlspecialchars($p['dauer']??'')),
          '{STATUS}' => trim($status),
          '{SICHTBARKEIT}' => trim(htmlspecialchars($p['sichtbarkeit']??'')),
          '{VORGANGSART}' => trim(htmlspecialchars($p['vorgangsart_name']??'')),
          '{UHRZEIT}' => !empty($p['uhrzeit']) ? trim(htmlspecialchars(substr($p['uhrzeit'],0,5))) : '',
          '{TAGESZEIT}' => trim(htmlspecialchars($p['tageszeit']??'')),
          '{VORGAENGER_ID}' => trim(htmlspecialchars($p['vorgaenger_id']??'')),
          '{KURZBESCHREIBUNG}' => trim(htmlspecialchars($p['kurzbeschreibung'] ?? '')),
          '{DESCRIPTION}' => trim(htmlspecialchars($p['langbeschreibung'] ?? '')),
          '{LANGBESCHREIBUNG}' => trim(htmlspecialchars($p['langbeschreibung'] ?? '')),
          '{NOTIZ}' => trim(htmlspecialchars($p['notiz'] ?? '')),
          '{NOTIZEN}' => trim(htmlspecialchars($p['notiz'] ?? '')),
          '{UNT_BEMERKUNG}' => trim(htmlspecialchars($p['unt_bemerkung'] ?? '')),
          '{DATUM}' => date('d.m.Y'),
          '{ERSTELLT_AM}' => !empty($p['erstellt_am']) ? date('d.m.Y H:i', strtotime($p['erstellt_am'])) : '',
          '{GEAENDERT_AM}' => !empty($p['geaendert_am']) ? date('d.m.Y H:i', strtotime($p['geaendert_am'])) : '',
          '{BEARBEITET_AM}' => !empty($p['geaendert_am']) ? date('d.m.Y H:i', strtotime($p['geaendert_am'])) : '',
          '{DELETED_AT}' => !empty($p['deleted_at']) ? date('d.m.Y H:i', strtotime($p['deleted_at'])) : '-',
          '{ARCHIVIERT_AM}' => !empty($p['archived_at']) ? date('d.m.Y', strtotime($p['archived_at'])) : '-',
          '{STARTDATUM}' => !empty($p['startdatum']) ? date('d.m.Y', strtotime($p['startdatum'])) : '-',
          '{BEGINN}' => !empty($p['startdatum']) ? date('d.m.Y', strtotime($p['startdatum'])) : '-',
          '{ENDDATUM}' => !empty($p['enddatum']) ? date('d.m.Y', strtotime($p['enddatum'])) : '-',
          '{ENDE}' => !empty($p['enddatum']) ? date('d.m.Y', strtotime($p['enddatum'])) : '-',
          '{WICHTIGKEIT}' => str_repeat('★', (int)($p['wichtigkeit']??0)) . str_repeat('☆', 5 - (int)($p['wichtigkeit']??0)),
          '{PRIORITAET}' => str_repeat('★', (int)($p['wichtigkeit']??0)) . str_repeat('☆', 5 - (int)($p['wichtigkeit']??0)),
          '{ID}' => trim('#'.$p['id']),
          '{ZUSTAENDIG}' => trim($zName),
          '{ZUSTAENDIG_NAME}' => trim($zName),
          '{VERANTWORTLICH}' => trim($zName),
          '{VERANTWORTLICHER}' => trim($zName),
          '{ERSTELLT_VON}' => trim(htmlspecialchars($createdUser['name']??'')),
          '{COVER}' => $coverImg,
          '{ERSTES_BILD}' => $erstesImg,
          '{DOKUMENTE}' => $docsHtml,
          '{PDF_DOCS}' => $pdfDocsHtml,
          '{ALL_DATA}' => $formHtml,
          '{FORMULAR}' => $formHtml,
          '{LINK}' => $linkHtml,
          '{URL}' => $linkHtml,
          
          '{COMPANY_NAME}' => htmlspecialchars($brandUser['firma_name']??''),
          '{COMPANY_EMAIL}' => htmlspecialchars($brandUser['firma_email']??''),
          '{COMPANY_PHONE}' => htmlspecialchars($brandUser['firma_telefon']??''),
          '{COMPANY_ADDRESS}' => !empty($brandUser['firma_adresse_full']) ? htmlspecialchars($brandUser['firma_adresse_full']) : htmlspecialchars(trim(($brandUser['firma_strasse']??'').' '.($brandUser['firma_hausnummer']??'').', '.($brandUser['firma_plz']??'').' '.($brandUser['firma_ort']??''), ', ')),
          '{COMPANY_PLZ}' => htmlspecialchars($brandUser['firma_plz']??''),
          '{COMPANY_ORT}' => htmlspecialchars($brandUser['firma_ort']??''),
          '{COMPANY_LOGO}' => '{LOGO}',

          '{BRAND_NAME}' => htmlspecialchars($brandUser['firma_name']??''),
          '{BRAND_FIRMA}' => htmlspecialchars($brandUser['firma_name']??''),
          '{BRAND_EMAIL}' => htmlspecialchars($brandUser['firma_email']??''),
          '{BRAND_TELEFON}' => htmlspecialchars($brandUser['firma_telefon']??''),
          '{BRAND_STRASSE}' => htmlspecialchars($brandUser['firma_strasse']??''),
          '{COMPANY_STRASSE}' => htmlspecialchars($brandUser['firma_strasse']??''),
          
          '{ERSTELLER_NAME}' => htmlspecialchars($createdUser['name']??''),
          '{ERSTELLER_FIRMA}' => htmlspecialchars($createdUser['firma_name']??''),
          '{ERSTELLER_EMAIL}' => htmlspecialchars($createdUser['firma_email']??''),

          '{ZUSTÄNDIG_NAME}' => $zName,
          '{ZUSTÄNDIG_FIRMA}' => htmlspecialchars($z['firma_name']??''),
          '{ZUSTAENDIG_NAME}' => $zName,
          '{ZUSTAENDIG_FIRMA}' => htmlspecialchars($z['firma_name']??''),
          '{ZUSTAENDIG_EMAIL}' => htmlspecialchars($z['firma_email']??''),
          '{ZUSTÄNDIG_EMAIL}' => htmlspecialchars($z['firma_email']??''),
          '{ZUSTAENDIG_TELEFON}' => htmlspecialchars($z['firma_telefon']??''),
          '{ZUSTÄNDIG_TELEFON}' => htmlspecialchars($z['firma_telefon']??''),
          '{ZUSTAENDIG_STRASSE}' => htmlspecialchars($z['firma_strasse']??''),
          '{ZUSTÄNDIG_STRASSE}' => htmlspecialchars($z['firma_strasse']??''),
          '{ZUSTAENDIG_FIRMA_STRASSE}' => htmlspecialchars($z['firma_strasse']??''),
          '{ZUSTÄNDIG_FIRMA_STRASSE}' => htmlspecialchars($z['firma_strasse']??''),
          '{ZUSTAENDIG_ADRESSE}' => !empty($z['firma_adresse_full']) ? htmlspecialchars($z['firma_adresse_full']) : htmlspecialchars(trim(($z['firma_strasse']??'').' '.($z['firma_hausnummer']??'').', '.($z['firma_plz']??'').' '.($z['firma_ort']??''), ', ')),
          '{ZUSTÄNDIG_ADRESSE}' => !empty($z['firma_adresse_full']) ? htmlspecialchars($z['firma_adresse_full']) : htmlspecialchars(trim(($z['firma_strasse']??'').' '.($z['firma_hausnummer']??'').', '.($z['firma_plz']??'').' '.($z['firma_ort']??''), ', ')),
          '{ZUSTAENDIG_PLZ}' => trim(htmlspecialchars($z['firma_plz']??'')),
          '{ZUSTAENDIG_ORT}' => trim(htmlspecialchars($z['firma_ort']??'')),
          '{ZUSTÄNDIG_PLZ}' => trim(htmlspecialchars($z['firma_plz']??'')),
          '{ZUSTÄNDIG_ORT}' => trim(htmlspecialchars($z['firma_ort']??'')),
          '{ZUSTAENDIG_FIRMA_ORT}' => trim(htmlspecialchars($z['firma_ort']??'')),
          '{ZUSTÄNDIG_FIRMA_ORT}' => trim(htmlspecialchars($z['firma_ort']??'')),
        ];
        
        foreach($reps as $tk => $tv) $text = str_replace($tk, $tv, $text);

        // {FOOTER} fix (smaller QR)
        if (strpos($text, '{FOOTER}') !== false) {
           $fHtml = '<div style="border-top:1px solid #eee; padding-top:5px; font-size:8px; color:#999;">';
           $fHtml .= '<div style="float:left;">Generiert am '.date('d.m.Y').'</div>';
           if($qrUrl) $fHtml .= '<img src="'.htmlspecialchars($qrUrl).'" style="width:35px; height:35px; float:right;">';
           $fHtml .= '<div style="clear:both;"></div></div>';
           $text = str_replace('{FOOTER}', $fHtml, $text);
        }
        
        // Bilder-Tags
        if (strpos($text, '{LOGO}') !== false) {
           $imgTag = '';
           $logoPath = $brandUser['firmenlogo'] ?? '';
           if ($logoPath) {
               $bp = defined('BASE_PATH') ? BASE_PATH : '';
               if ($bp && strpos($logoPath, $bp) === 0) $logoPath = substr($logoPath, strlen($bp));
               $abs = realpath(__DIR__.'/../'.ltrim($logoPath,'/'));
               if ($abs && is_file($abs)) {
                   $data = @file_get_contents($abs);
                   if ($data) {
                       $b64 = 'data:image/png;base64,'.base64_encode($data);
                       $imgTag = '<img src="'.$b64.'" style="max-width:100%; max-height:100%; object-fit:contain;">';
                   }
               }
           }
           $text = str_replace('{LOGO}', $imgTag, $text);
        }

        if (strpos($text, '{QR}') !== false) {
           $text = str_replace('{QR}', '<img src="'.htmlspecialchars($qrUrl).'" style="width:100%; height:100%;">', $text);
        }

        if (strpos($text, '{BILDER}') !== false) {
            $grid = '<div class="imggrid">';
            $count = 0;
            foreach ($imgs as $i) {
                $relPath = $i['pfad'];
                $data = null;
                if (strpos($relPath, 'http') === 0) {
                    $data = @file_get_contents($relPath);
                } else {
                    $bp = defined('BASE_PATH') ? BASE_PATH : '';
                    if ($bp && strpos($relPath, $bp) === 0) $relPath = substr($relPath, strlen($bp));
                    $abs = realpath(__DIR__ . '/../' . ltrim($relPath, '/'));
                    if ($abs && is_file($abs)) $data = @file_get_contents($abs);
                }

                if ($data) {
                    $b64 = 'data:' . ($i['mimetype'] ?: 'image/jpeg') . ';base64,' . base64_encode($data);
                    $grid .= '<div class="cell"><img src="' . $b64 . '" style="max-width:100%; max-height:' . $gHeight . 'px; object-fit:cover;"></div>';
                    $count++;
                    if ($count % 3 === 0) $grid .= '</div><div class="imggrid">'; // Neue Zeile alle 3 Bilder
                }
            }
            $grid .= '</div>';
            $text = str_replace('{BILDER}', $grid, $text);
        }

        // --- NEU: Zeilenumbrüche und einfache Formatierung unterstützen ---
        // 1. Markdown-ähnliche Formatierung: **bold** -> <strong>, __italic__ -> <em>
        $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.*?)__/', '<em>$1</em>', $text);
        
        // 2. Absätze erhalten, aber extreme Leerzeilen (mehr als 2) reduzieren
        $text = trim($text);
        $text = preg_replace("/(\r\n|\n|\r){3,}/", "\n\n", $text); 
        $text = nl2br($text);
    ?>
      <?php 
        $bStyle = "left:{$x}px; top:{$y}px; width:{$w}px; height:{$h}px;";
        $bStyle .= " font-size:".($block['fontSize'] ?? 12)."px;";
        if (!empty($block['bold']))      $bStyle .= " font-weight:bold;";
        if (!empty($block['italic']))    $bStyle .= " font-style:italic;";
        if (!empty($block['underline'])) $bStyle .= " text-decoration:underline;";
        if (!empty($block['align']))     $bStyle .= " text-align:{$block['align']};";
        if (!empty($block['color']))     $bStyle .= " color:{$block['color']};";
        if (!empty($block['bgColor']))   $bStyle .= " background-color:{$block['bgColor']}; padding:5px;";

        // Vertikale Ausrichtung via Flexbox
        $bStyle .= " display: flex; flex-direction: column;";
        if (($block['vAlign'] ?? 'top') === 'middle') $bStyle .= " justify-content: center;";
        elseif (($block['vAlign'] ?? 'top') === 'bottom') $bStyle .= " justify-content: flex-end;";
        else $bStyle .= " justify-content: flex-start;";
      ?>
      <div class="absolute-block" style="<?= $bStyle ?>">
        <div style="text-align:inherit;"><?= $text ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="header-top clearfix">
    <div class="header-left">
      <div class="brand-info">
        <?php if(!empty($brandUser['firma_name'])): ?><strong style="font-size:14px; color:#1abc9c;"><?= htmlspecialchars($brandUser['firma_name']) ?></strong><br><?php endif; ?>
        <?php if(!empty($brandUser['firma_strasse'])): ?><?= htmlspecialchars($brandUser['firma_strasse']) ?> <?= htmlspecialchars($brandUser['firma_hausnummer']??'') ?><br><?php endif; ?>
        <?php if(!empty($brandUser['firma_plz']) || !empty($brandUser['firma_ort'])): ?><?= htmlspecialchars(($brandUser['firma_plz']??'').' '.($brandUser['firma_ort']??'')) ?><br><?php endif; ?>
        <?php if(!empty($brandUser['firma_telefon'])): ?>Tel: <?= htmlspecialchars($brandUser['firma_telefon']) ?><br><?php endif; ?>
        <?php if(!empty($brandUser['firma_email'])): ?><?= htmlspecialchars($brandUser['firma_email']) ?><?php endif; ?>
      </div>
    </div>
    <div class="header-right">
      <?php if($brandPath && is_file(__DIR__.'/../'.ltrim($brandPath,'/'))):
        $abs=realpath(__DIR__.'/../'.ltrim($brandPath,'/')); $data=@file_get_contents($abs);
        if($data): 
          $finfo = new finfo(FILEINFO_MIME_TYPE);
          $mime = $finfo->file($abs);
          $b64='data:'.$mime.';base64,'.base64_encode($data); ?>
          <img class="brand" src="<?= $b64 ?>">
        <?php endif; endif; ?>
    </div>
  </div>

  <div class="row clearfix">
    <div class="left">
      <?php 
        $titlePrefix = "";
        if((int)($p['is_protocol']??0)===1 && ($p['protocol_type']??'')!=='none') {
            $titlePrefix = strtoupper($p['protocol_type']) . "-PROTOKOLL: ";
        }
      ?>
      <h1><?= $titlePrefix ?><?= htmlspecialchars($p['titel']) ?></h1>
      <div class="muted">
        Projekt: <strong><?= htmlspecialchars($p['projekt_name'] ?? '') ?></strong> 
        <?php if(!empty($p['wohnung_name'])): ?> • Wohnung: <strong><?= htmlspecialchars($p['wohnung_name']) ?></strong><?php endif; ?>
    <style>
      @page { margin: 0; }
      body { 
        font-family: 'Helvetica', 'Arial', sans-serif; 
        margin: 0; 
        padding: 0; 
        background: #f8fafc; 
        color: #1e293b;
        line-height: 1.5;
      }
      .page-container { padding: 40px; }
      
      /* Hero Header matching pendenz_show.php */
      .hero-header {
        background: #10b981;
        color: white;
        padding: 30px 40px;
        margin-bottom: 30px;
      }
      .hero-header h1 { 
        margin: 0 0 10px 0; 
        font-size: 28px; 
        color: white; 
      }
      .hero-header .meta { 
        font-size: 14px; 
        opacity: 0.9; 
      }
      .hero-header .meta strong { color: white; }

      .branding-header {
        display: table;
        width: 100%;
        padding: 20px 40px;
        background: white;
        border-bottom: 2px solid #10b981;
      }
      .brand-info { display: table-cell; vertical-align: bottom; font-size: 12px; color: #64748b; }
      .brand-logo { display: table-cell; vertical-align: bottom; text-align: right; }
      .brand-logo img { max-width: 200px; max-height: 80px; }

      .card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
      }
      .card h2 { 
        margin: 0 0 15px 0; 
        font-size: 18px; 
        color: #0f172a; 
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 10px;
      }
      .box { 
        background: #f8fafc; 
        border: 1px solid #f1f5f9; 
        border-radius: 6px; 
        padding: 15px; 
        font-size: 14px; 
      }
      
      .meta-grid { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
      .meta-item { padding: 10px; border: 1px solid #f1f5f9; background: white; border-radius: 6px; }
      .meta-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px; }
      .meta-value { font-size: 14px; font-weight: bold; color: #1e293b; }

      .gallery { margin-top: 20px; }
      .gallery img { width: 100%; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
      
      footer { 
        position: fixed; bottom: 20px; left: 40px; right: 40px; 
        font-size: 10px; color: #94a3b8; text-align: center;
      }
    </style>

  <!-- BRANDING HEADER -->
  <div class="branding-header">
    <div class="brand-info">
      <?php if($brandUser): ?>
        <strong style="font-size:16px; color:#1e293b;"><?= htmlspecialchars($brandUser['firma_name']??'') ?></strong><br>
        <?= htmlspecialchars($brandUser['firma_strasse']??'') ?> <?= htmlspecialchars($brandUser['firma_hausnummer']??'') ?><br>
        <?= htmlspecialchars($brandUser['firma_plz']??'') ?> <?= htmlspecialchars($brandUser['firma_ort']??'') ?><br>
        <span style="font-size:10px;">
          <?= !empty($brandUser['firma_telefon']) ? 'Tel: '.htmlspecialchars($brandUser['firma_telefon']) : '' ?> 
          <?= !empty($brandUser['firma_email']) ? ' | '.htmlspecialchars($brandUser['firma_email']) : '' ?>
        </span>
      <?php endif; ?>
    </div>
    <div class="brand-logo">
      <?php if($brandPath && is_file(__DIR__.'/../'.ltrim($brandPath,'/'))): ?>
        <img src="data:image/png;base64,<?= base64_encode(file_get_contents(__DIR__.'/../'.ltrim($brandPath,'/'))) ?>" alt="Logo">
      <?php endif; ?>
    </div>
  </div>

  <!-- HERO HEADER -->
  <div class="hero-header">
    <h1><?= htmlspecialchars($p['titel']) ?></h1>
    <div class="meta">
      Projekt: <strong><?= htmlspecialchars($p['projekt_name']) ?></strong> 
      &bull; Status: <strong><?= htmlspecialchars($p['status']) ?></strong>
      &bull; Fällig: <strong><?= !empty($p['enddatum']) ? date('d.m.Y', strtotime($p['enddatum'])) : '-' ?></strong>
    </div>
  </div>

  <div class="page-container">
    <?php if (in_array('meta',$view['sections'],true)): ?>
      <div class="card">
        <h2>Details</h2>
        <table class="meta-grid">
          <?php 
            $metaChunks = array_chunk($view['meta_fields'], 2); 
            foreach($metaChunks as $chunk):
          ?>
            <tr>
              <?php foreach($chunk as $f): 
                $lbl = $labels[$f] ?? ucwords(str_replace('_',' ',$f));
                $val = $formatValue($f, $values[$f] ?? ($p[$f] ?? ''));
              ?>
                <td style="width:50%; padding:5px;">
                   <div class="meta-item">
                      <div class="meta-label"><?= htmlspecialchars($lbl) ?></div>
                      <div class="meta-value"><?= htmlspecialchars($val) ?></div>
                   </div>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>

    <?php if (in_array('kurzbeschreibung',$view['sections'],true) && !empty($p['kurzbeschreibung'])): ?>
      <div class="card">
        <h2>Kurzbeschreibung</h2>
        <div class="box"><?= nl2br(htmlspecialchars($p['kurzbeschreibung'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (in_array('langbeschreibung',$view['sections'],true) && !empty($p['langbeschreibung'])): ?>
      <div class="card">
        <h2>Beschreibung</h2>
        <div class="box"><?= nl2br(htmlspecialchars($p['langbeschreibung'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (in_array('notiz',$view['sections'],true) && !empty($p['notiz'])): ?>
      <div class="card">
        <h2>Notiz</h2>
        <div class="box"><?= nl2br(htmlspecialchars($p['notiz'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($p['unt_bemerkung'])): ?>
      <div class="card" style="border-left:5px solid #10b981;">
        <h2 style="color:#059669;">Unternehmer-Rückmeldung</h2>
        <div class="box" style="background:#f0fdf4;"><?= nl2br(htmlspecialchars($p['unt_bemerkung'])) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <?php if (in_array('bilder',$view['sections'],true) && $imgs): ?>
    <h2>Bilder</h2>
    <div class="imggrid">
      <?php foreach($imgs as $i):
        $abs=realpath(__DIR__.'/../'.ltrim($i['pfad'],'/')); if(!$abs||!is_file($abs)) continue; $data=@file_get_contents($abs); if(!$data) continue;
        $b64='data:'.($i['mimetype']?:'image/jpeg').';base64,'.base64_encode($data); ?>
        <div class="cell"><img src="<?= $b64 ?>" style="max-width:100%; max-height:100px;"></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (in_array('anhaenge',$view['sections'],true) && $files): ?>
    <h2>Anhänge</h2>
    <div class="box">
      <ul>
        <?php foreach($files as $f): ?>
          <li><span class="muted"><?= htmlspecialchars($f['mimetype'] ?: 'Datei') ?></span> — <?= htmlspecialchars(basename($f['pfad'])) ?> (<?= number_format((int)($f['groesse']??0)/1024,0,'\'','.') ?> KB)</li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; // End Mask Mode ?>
</body>
</html>
<?php
$html = ob_get_clean();

[$rel,$abs] = pendenz_fs_base($mysqli, (int)$p['projekt_id'], (int)($p['ordner_id'] ?? 0) ?: null, (int)$p['id'], (string)$p['titel']);
if (!is_dir($abs.'/pdf')) @mkdir($abs.'/pdf',0775,true);
$pdfAbs = $abs . '/pdf/pendenz_'.$id.'.pdf';
$pdfRel = $rel . '/pdf/pendenz_'.$id.'.pdf';

require_once __DIR__ . '/../vendor/autoload.php';
$opt = new Options();
$opt->set('isRemoteEnabled', true);
$dom = new Dompdf($opt);
$dom->loadHtml($html, 'UTF-8');
if (!empty($maskCfg['pageW']) && !empty($maskCfg['pageH'])) {
    // Dompdf uses points (pt). 1px = 0.75pt (at 96dpi)
    $dom->setPaper([0, 0, $maskCfg['pageW'] * 0.75, $maskCfg['pageH'] * 0.75]);
} else {
    $dom->setPaper('A4', 'portrait');
}
$dom->render();
file_put_contents($pdfAbs, $dom->output());

// Stream direkt an den Browser (sicherer für Gäste auf Servern mit Dateischutz)
$filename = 'pendenz_' . $id . '.pdf';
$dom->stream($filename, ["Attachment" => 0]);
exit;
