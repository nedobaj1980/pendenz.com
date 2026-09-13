<?php
// pages/pendenzen_liste.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();
require_once __DIR__ . '/../includes/functions.php'; 

$PREFIX = site_prefix();
$ASSET  = rtrim($PREFIX,'/').'/';
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

$NAV_ACTIVE = 'pendenzen_liste';
$PAGE_TITLE = 'Pendenzen-Export';

/* === Eingaben === */
$GET = filter_input_array(INPUT_GET, [
  'list_id'     => FILTER_SANITIZE_NUMBER_INT,
  'q'           => FILTER_SANITIZE_SPECIAL_CHARS,
  'status'      => FILTER_SANITIZE_SPECIAL_CHARS,
  'projekt_id'  => FILTER_SANITIZE_NUMBER_INT,
  'wohnung_id'  => FILTER_SANITIZE_NUMBER_INT,
  'kategorie_id'=> FILTER_SANITIZE_NUMBER_INT,
  'von'         => FILTER_SANITIZE_SPECIAL_CHARS,
  'bis'         => FILTER_SANITIZE_SPECIAL_CHARS,
  'sort'        => FILTER_SANITIZE_SPECIAL_CHARS,
  'dir'         => FILTER_SANITIZE_SPECIAL_CHARS,
  'page'        => FILTER_SANITIZE_NUMBER_INT,
  'per_page'    => FILTER_SANITIZE_NUMBER_INT,
  'export'      => FILTER_SANITIZE_SPECIAL_CHARS,
  'pdf_size'    => FILTER_SANITIZE_SPECIAL_CHARS,
  'pdf_orient'  => FILTER_SANITIZE_SPECIAL_CHARS,
  'pdf_fs'      => FILTER_SANITIZE_NUMBER_INT,
  'cols_order_payload' => FILTER_UNSAFE_RAW,
  'cols'        => ['filter'=>FILTER_UNSAFE_RAW, 'flags'=>FILTER_REQUIRE_ARRAY]
]) ?? [];

$listId     = (int)($GET['list_id'] ?? 0);
if ($listId <= 0 && !empty($_GET['listen_id'])) $listId = (int)$_GET['listen_id']; // Fallback

// NEU: Falls keine Liste gewählt wurde, prüfen ob es eine Standard-Ansicht gibt
if ($listId <= 0 && !isset($_GET['cols'])) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $resDef = @$mysqli->query("SELECT id FROM listen WHERE table_name='pendenzen' AND owner_id=$uid AND is_default=1 LIMIT 1");
    if ($resDef && $rowDef = $resDef->fetch_assoc()) {
        $listId = (int)$rowDef['id'];
    }
}

$q          = trim($GET['q'] ?? '');
$status     = trim($GET['status'] ?? '');
$projekt_id = (isset($GET['projekt_id']) && $GET['projekt_id'] !== '') ? (int)$GET['projekt_id'] : null;
$wohnung_id = (isset($GET['wohnung_id']) && $GET['wohnung_id'] !== '') ? (int)$GET['wohnung_id'] : null;
$kategorie_id = (isset($GET['kategorie_id']) && $GET['kategorie_id'] !== '') ? (int)$GET['kategorie_id'] : null;
$von        = trim($GET['von'] ?? '');
$bis        = trim($GET['bis'] ?? '');

$sort       = $GET['sort'] ?? 'id';
$dirParam   = strtolower($GET['dir'] ?? 'asc');
$dirSql     = ($dirParam === 'desc') ? 'DESC' : 'ASC';
$page       = max(1, (int)($GET['page'] ?? 1));
$per_page   = (int)($GET['per_page'] ?? ($_SESSION['pendenzen_per_page'] ?? 25));
$per_page   = max(5, min(200, $per_page));

$export     = $GET['export'] ?? null;
$pdf_size   = strtolower($GET['pdf_size'] ?? 'a4');
$pdf_orient = strtolower($GET['pdf_orient'] ?? 'portrait');
$pdf_fs     = max(8, min(16, (int)($GET['pdf_fs'] ?? 11)));

// --- LAYER LADEN (falls list_id vorhanden) ---
$layerCols = null;
if ($listId > 0) {
    $resL = $mysqli->query("SELECT * FROM listen WHERE id=$listId LIMIT 1");
    if ($l = $resL->fetch_assoc()) {
        $fJson = json_decode($l['filters_json'] ?? '{}', true);
        if ($q === '' && !empty($fJson['q'])) $q = $fJson['q'];
        if ($status === '' && !empty($fJson['status'])) $status = $fJson['status'];
        if ($projekt_id === null && !empty($fJson['projekt_id'])) $projekt_id = (int)$fJson['projekt_id'];
        if ($wohnung_id === null && !empty($fJson['wohnung_id'])) $wohnung_id = (int)$fJson['wohnung_id'];
        if ($kategorie_id === null && !empty($fJson['kategorie_id'])) $kategorie_id = (int)$fJson['kategorie_id'];
        
        $resC = $mysqli->query("SELECT col_name FROM listen_spalten WHERE listen_id=$listId ORDER BY sort_order ASC");
        if ($resC) {
            $layerCols = [];
            while($cRow = $resC->fetch_assoc()) $layerCols[] = $cRow['col_name'];
        }
    }
}

/* === Spalten-Auswahl === */
$payload = trim($GET['cols_order_payload'] ?? '');
if (!empty($payload)) {
    $selectedCols = explode(',', $payload);
} else {
    $selectedCols = $GET['cols'] ?? $layerCols ?? ($_SESSION['pendenzen_cols'] ?? null);
}
if (!$selectedCols) $selectedCols = ['titel', 'status', 'enddatum'];
$_SESSION['pendenzen_cols'] = $selectedCols;

/* === Introspektion === */
function column_exists($db, $table, $column) {
  $res = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$table' AND column_name='$column'");
  return ($res && $res->num_rows > 0);
}
$HAS_BESCHREIBUNG = column_exists($mysqli, 'pendenzen', 'beschreibung');
$HAS_ENDDATUM     = column_exists($mysqli, 'pendenzen', 'enddatum');
$HAS_CREATED_AT   = column_exists($mysqli, 'pendenzen', 'created_at');

$PEND_COLS = [];
$resX = $mysqli->query("SHOW COLUMNS FROM pendenzen");
while($x = $resX->fetch_assoc()) $PEND_COLS[] = $x['Field'];
$ALL_SORTABLE = array_flip(array_merge($PEND_COLS, ['projekt_name', 'objekt_name', 'wohnung_name', 'raum_name', 'verantwortlicher']));


/* === Basis-Query === */
$baseSql = "FROM pendenzen p
LEFT JOIN projekte pr ON p.projekt_id = pr.id
LEFT JOIN pendenzen_arten pa ON p.vorgangsart_id = pa.id
LEFT JOIN objekte ob ON p.objekt_id = ob.id
LEFT JOIN wohnungen wo ON p.wohnung_id = wo.id
LEFT JOIN raeume rm ON p.raum_id = rm.id
LEFT JOIN benutzer bz ON p.zustaendig_id = bz.id
WHERE 1=1";


$where = [];
$params = [];
$types  = "";

/* Filter */
if ($q !== '') {
  $qLike = "%$q%";
  if ($HAS_BESCHREIBUNG) {
    $where[] = "(p.titel LIKE ? OR p.beschreibung LIKE ?)";
    $params[] = $qLike; $params[] = $qLike; $types .= "ss";
  } else {
    $where[] = "p.titel LIKE ?";
    $params[] = $qLike; $types .= "s";
  }
}
if ($status !== '') { $where[] = "p.status = ?"; $params[] = $status; $types .= "s"; }
if (!is_null($projekt_id)) { $where[] = "p.projekt_id = ?"; $params[] = $projekt_id; $types .= "i"; }
if (!is_null($wohnung_id)) { $where[] = "p.wohnung_id = ?"; $params[] = $wohnung_id; $types .= "i"; }
if (!is_null($kategorie_id)) { $where[] = "p.kategorie_id = ?"; $params[] = $kategorie_id; $types .= "i"; }

if ($HAS_ENDDATUM && $von !== '') { $where[] = "(p.enddatum IS NOT NULL AND p.enddatum >= ?)"; $params[] = $von; $types .= "s"; }
if ($HAS_ENDDATUM && $bis !== '') { $where[] = "(p.enddatum IS NOT NULL AND p.enddatum <= ?)"; $params[] = $bis; $types .= "s"; }

$whereSql = $where ? (" AND " . implode(" AND ", $where)) : "";

/* Sortierung */
$sortUsed = isset($ALL_SORTABLE[$sort]) ? $sort : 'id';
if ($sortUsed === 'enddatum' && $HAS_ENDDATUM) {
  $orderSql = " ORDER BY p.enddatum IS NULL, p.enddatum ASC, p.id DESC";
} else {
  $orderBy = ($sortUsed === 'projekt_name') ? "pr.name" : 
             (($sortUsed === 'objekt_name') ? "ob.name" : 
             (($sortUsed === 'wohnung_name') ? "wo.name" : 
             (($sortUsed === 'raum_name') ? "rm.name" : 
             (($sortUsed === 'verantwortlicher') ? "bz.name" : "p.`$sortUsed`"))));
  $orderSql = " ORDER BY $orderBy $dirSql";
}


/* Count */
$countSql = "SELECT COUNT(*) " . $baseSql . $whereSql;
$stmt = $mysqli->prepare($countSql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();

$totalRows  = (int)$totalRows;
$totalPages = max(1, (int)ceil($totalRows / $per_page));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $per_page;

/* Daten */
$selectSql = "SELECT p.*, pr.name AS projekt_name, pa.name AS vorgangsart_name, ob.name AS objekt_name, wo.name AS wohnung_name, rm.name AS raum_name, bz.name AS zustaendig_name, bz.name AS verantwortlicher, (SELECT SUM(fk.soll - fk.haben) FROM finanzen_konto fk WHERE fk.wohnung_id = p.wohnung_id) AS balance " . $baseSql . $whereSql . $orderSql . " LIMIT ? OFFSET ?";

$stmt = $mysqli->prepare($selectSql);
$bindTypes = $types . "ii";
$bindParams = $params; $bindParams[] = $per_page; $bindParams[] = $offset;
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$fieldMeta = $res->fetch_fields();
$stmt->close();

/* Feldnamen */
$resultFieldNames = [];
if ($fieldMeta) foreach ($fieldMeta as $f) $resultFieldNames[] = $f->name;
if (!$resultFieldNames && !empty($rows[0])) $resultFieldNames = array_keys($rows[0]);

/* Labels */
$labelsBase = [
  'vorgangsart_name' => 'Vorgangsart',
  'id'=>'ID (Nr.)','titel'=>'Titel','projekt_name'=>'Projekt','status'=>'Status',
  'prioritaet'=>'Priorität','wichtigkeit'=>'Prio','startdatum'=>'Start','enddatum'=>'Fällig',
  'uhrzeit'=>'Uhrzeit','tageszeit'=>'Tageszeit','dauer'=>'Dauer',
  'created_at'=>'Erstellt am','updated_at'=>'Aktualisiert am',
  'erstellt_am'=>'Erstellt am','aktualisiert_am'=>'Aktualisiert am',
  'geaendert_am'=>'Geändert am','deleted_at'=>'Gelöscht am',
  'erstellt_von'=>'Erstellt von (ID)',
  'zugewiesen_an'=>'Zugewiesen an','beschreibung'=>'Beschreibung','kurzbeschreibung'=>'Kurz-Info','sichtbarkeit'=>'Sichtbarkeit','balance'=>'Saldo',
  'dokumente'=>'📂 Dokumente','pdf'=>'📕 PDF-Dateien','bilder'=>'🖼️ Medien/Bilder','erstes_bild'=>'🖼️ Vorschaubild',
  'mandant_id' => 'Mandant (ID)',
  'projekt_id' => 'Projekt (ID)',
  'objekt_id' => 'Liegenschaft/Objekt (ID)',
  'wohnung_id' => 'Einheit (ID)',
  'ordner_id' => 'Ordner (ID)',
  'fs_rel_path' => 'Dateipfad (relativ)',
  'sort_index' => 'Sortier-Index',
  'langbeschreibung' => 'Ausführliche Beschreibung',
  'notiz' => 'Interne Notiz',
  'send_now' => 'Sofort versenden',
  'vorgaenger_id' => 'Vorgänger-Aufgabe (ID)',
  'assignee_can_edit' => 'Bearbeiter darf editieren',
  'zustaendig_id' => 'Zuständige Person (ID)',
  'confirmation_required' => 'Bestätigung erforderlich',
  'confirmation_by' => 'Bestätigt von (ID)',
  'confirmation_at' => 'Bestätigt am',
  'external_can_view' => 'Externer darf sehen',
  'external_can_upload' => 'Externer darf hochladen',
  'public_enabled' => 'Öffentlich freigegeben',
  'public_token' => 'Öffentlicher Zugriffs-Token',
  'submitted_by' => 'Eingereicht von',
  'submitted_at' => 'Eingereicht am',
  'reviewed_by' => 'Geprüft von',
  'reviewed_at' => 'Geprüft am',
  'extra_json' => 'Zusatzdaten (JSON)',
  'zustaendig_typ' => 'Zuständigkeits-Typ',
  'sicht_ref_id' => 'Referenz-ID',
  'is_protocol' => 'Als Protokoll markiert',
  'protocol_type' => 'Protokoll-Typ',
  'kategorie_id' => 'Kategorie (ID)',
  'subkategorie_id' => 'Unterkategorie (ID)',
  'objekt_name' => '🏢 Objekt Name',
  'wohnung_name' => '🚪 Wohnung / Einheit',
  'raum_name' => '🚪 Raum',
  'verantwortlicher' => '👤 Verantwortlicher'
];

$labels=[];
$virtualFields = ['dokumente','pdf', 'bilder', 'erstes_bild', 'cover'];
foreach ($labelsBase as $k => $v) {
  if (in_array($k, $resultFieldNames, true) || in_array($k, $virtualFields, true)) $labels[$k] = $v;
}
foreach ($resultFieldNames as $k) if (!isset($labels[$k])) $labels[$k] = ucwords(str_replace(['_','-'],' ',$k));

/* Spaltenauswahl säubern (Erlaubt DB-Felder UND virtuelle json:-Felder) */
$cols = array_values(array_filter($selectedCols, fn($c) => in_array($c, $resultFieldNames, true) || str_starts_with($c, 'json:') || in_array($c, $virtualFields, true)));
if (count($cols) === 0) $cols = array_values(array_intersect($defaultCols, $resultFieldNames));


/* === HEADER / NAV IF NOT EXPORTING === */
if (!$export) {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/nav_dispatch.php';
}

/* === Render-HTML (für Export) === */
$renderTableHtml = function(array $rows, array $cols, array $labels, int $fs = 11) use ($mysqli, $ASSET): string {
  ob_start(); ?>
  <style>
    table.export { width:100%; border-collapse:collapse; font-size: <?= (int)$fs ?>pt; font-family: sans-serif; }
    table.export th, table.export td { border: 1px solid #94a3b8; padding: 8px 10px; text-align: left; vertical-align: top; }
    table.export thead th { background: #f1f5f9 !important; font-weight: bold; color: #1e293b; -webkit-print-color-adjust: exact; }
    .st-chip { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 8pt; font-weight: bold; text-transform: uppercase; white-space: nowrap; }
    .st-offen { background: #fee2e2 !important; color: #991b1b !important; -webkit-print-color-adjust: exact; }
    .st-bearbeitung { background: #fef3c7 !important; color: #92400e !important; -webkit-print-color-adjust: exact; }
    .st-erledigt { background: #dcfce7 !important; color: #166534 !important; -webkit-print-color-adjust: exact; }
    .thumbnail { width: 60px; height: 45px; border-radius: 4px; object-fit: cover; border: 1px solid #ddd; margin-right: 4px; }
    .desc-cell { font-size: 0.9em; color: #475569; }
  </style>
  <table class="export">
    <thead>
      <tr>
        <?php foreach ($cols as $c): ?>
          <th><?= h($labels[$c] ?? $c) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($cols as $c):
            $v = $r[$c] ?? '';
            if ($c === 'status') {
               $cls = 'st-offen';
               if(stripos($v,'bearbeitung')!==false) $cls='st-bearbeitung';
               if(stripos($v,'erledigt')!==false) $cls='st-erledigt';
               $v = '<span class="st-chip '.$cls.'">'.h($v).'</span>';
            } elseif (in_array($c, ['erstes_bild', 'bilder', 'cover', 'dokumente', 'pdf']) && !empty($r['id'])) {
               // Suche Medien (Bilder oder Dokumente)
               $typeFilter = "1=1";
               if ($c === 'dokumente' || $c === 'pdf') $typeFilter = "typ != 'image'";
               elseif ($c === 'erstes_bild' || $c === 'cover') $typeFilter = "typ = 'image'";

               $limit = ($c === 'bilder' || $c === 'dokumente') ? 10 : 1;
               $imgRes = $mysqli->query("SELECT pfad, typ, mimetype FROM pendenz_dateien WHERE pendenz_id=".(int)$r['id']." AND ($typeFilter) ORDER BY is_cover DESC, id ASC LIMIT $limit");
               
               $v = '';
               while ($img = $imgRes->fetch_assoc()) {
                   if ($img['typ'] === 'image') {
                       $abs = realpath(__DIR__.'/../'.ltrim($img['pfad'],'/'));
                       if ($abs && is_file($abs)) {
                           $data = @file_get_contents($abs);
                           $b64 = 'data:image/jpeg;base64,'.base64_encode($data);
                           $v .= '<img src="'.$b64.'" class="thumbnail" style="margin-right:2px;">';
                       } else { $v .= '🖼️ '; }
                   } else {
                       // Icon für Dokumente
                       $icon = '📄';
                       if (stripos($img['mimetype'], 'pdf') !== false) $icon = '📕';
                       elseif (stripos($img['mimetype'], 'excel') !== false || stripos($img['mimetype'], 'spreadsheet') !== false) $icon = '📊';
                       elseif (stripos($img['mimetype'], 'word') !== false) $icon = '📝';
                       
                       $v .= '<span title="'.h($img['mimetype']).'" style="font-size:16pt; cursor:help; margin-right:4px;">'.$icon.'</span>';
                   }
               }
            } elseif ($c === 'wichtigkeit') {
               $stars = (int)$v;
               $v = str_repeat('★', $stars) . str_repeat('☆', 5 - $stars);
            } elseif (str_starts_with($c, 'json:')) {
               $fld = str_replace('json:', '', $c);
               $jx = json_decode((string)($r['extra_json'] ?? '[]'), true) ?: [];
               $v = $jx[$fld] ?? '—';
            } elseif ($c === 'balance') {
               $bal = (float)$v;
               if ($bal > 0) $v = '<span style="color:#ef4444; font-weight:bold;">' . number_format($bal, 2, '.', '\'') . '</span>';
               elseif ($bal < 0) $v = '<span style="color:#22c55e; font-weight:bold;">' . number_format(abs($bal), 2, '.', '\'') . '</span>';
               else $v = '0.00';
            } elseif (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v)) {
               $ts = strtotime($v);
               if ($ts) $v = date('d.m.Y', $ts);
            }
          ?>
            <td>
              <?php if ($c === 'beschreibung' || $c === 'titel'): ?>
                <div class="desc-cell"><?= (strpos((string)$v, '<')!==false) ? $v : h((string)$v) ?></div>
              <?php elseif ($c === 'dauer' && is_numeric(trim(str_replace('Tage','',(string)$v)))): ?>
                <?= h($v) ?> <?= (stripos((string)$v,'Tage')===false ? 'Tage' : '') ?>
              <?php else: ?>
                <?= (strpos((string)$v, '<')!==false) ? $v : h((string)$v) ?>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  return (string)ob_get_clean();
};

/* === EXPORTS === */
if (in_array($export, ['csv','xls','pdf','print'], true)) {
  if ($export === 'csv') {
    $filename='pendenzen_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out=fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
    $head=[]; foreach ($cols as $c) $head[]=$labels[$c]??$c; fputcsv($out,$head,';');
    foreach ($rows as $r){
      $line=[];
      foreach ($cols as $c){
        $v=$r[$c]??'';
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/',$v)){ $ts=strtotime($v); if($ts)$v=date('d.m.Y',$ts); }
        $line[]=$v;
      }
      fputcsv($out,$line,';');
    }
    fclose($out); exit;
  }

  if ($export === 'xls') {
    $filename='pendenzen_'.date('Ymd_His').'.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo '<html><head><meta charset="utf-8"></head><body>';
    echo '<h3>Pendenzen</h3>';
    echo $renderTableHtml($rows,$cols,$labels,$pdf_fs);
    echo '</body></html>'; exit;
  }

  if ($export === 'pdf') {
    $html = '<h2 style="margin:0 0 10px 0;font-family:sans-serif">Pendenzen</h2>'.$renderTableHtml($rows,$cols,$labels,$pdf_fs);
    $paper = in_array($pdf_size,['a4','a3','letter'],true) ? strtoupper($pdf_size) : 'A4';
    $orient= $pdf_orient==='landscape' ? 'landscape' : 'portrait';
    if (file_exists($autoload)) {
      require_once $autoload;
      if (class_exists('\\Dompdf\\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
        $dompdf->loadHtml('<meta charset="utf-8">'.$html);
        $dompdf->setPaper($paper,$orient);
        $dompdf->render();
        $dompdf->stream('pendenzen_'.date('Ymd_His').'.pdf', ['Attachment'=>true]); exit;
      }
    }
    // Fallback
    $export = 'print';
  }

  if ($export === 'print') {
    $paper = in_array($pdf_size,['a4','a3','letter'],true) ? strtoupper($pdf_size) : 'A4';
    $orient= $pdf_orient==='landscape' ? 'landscape' : 'portrait';
    $html = '<h2 style="margin:0 0 10px 0;font-family:sans-serif">Pendenzen</h2>'.$renderTableHtml($rows,$cols,$labels,$pdf_fs);
    ?>
    <!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Pendenzen-Protokoll</title>
    <style>
      @page { size: <?= $paper ?> <?= $orient ?>; margin: 15mm; } 
      body { font-family: "Inter", system-ui, -apple-system, sans-serif; color: #1e293b; background: white; margin: 0; padding: 0; line-height: 1.5; }
      .print-container { padding: 0; max-width: 100%; }
      .print-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #1e293b; margin-bottom: 20px; padding-bottom: 15px; }
      .print-header h1 { margin: 0; font-size: 22pt; color: #0f172a; text-transform: uppercase; letter-spacing: 1px; }
      .print-meta { text-align: right; font-size: 10pt; color: #64748b; }
      .print-footer { margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 10px; font-size: 9pt; color: #94a3b8; display: flex; justify-content: space-between; }
      @media print { 
        .no-print { display: none !important; }
        body { padding: 0; }
        .print-container { width: 100%; }
      }
      .btn-print-trigger { background: #0ea5e9; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; }
    </style></head>
    <body onload="window.print()">
      <div class="no-print" style="background:#f8fafc; padding: 15px; border-bottom: 1px solid #e2e8f0; display:flex; gap:12px; align-items:center">
        <button class="btn-print-trigger" onclick="window.print()">Jetzt Drucken</button>
        <a href="javascript:history.back()" style="color:#64748b; text-decoration:none; font-size: 14px;">← Zurück</a>
      </div>
      
      <div class="print-container">
        <header class="print-header">
          <div>
            <h1>Pendenzen-Protokoll</h1>
            <?php if($projekt_id): 
               $pName = $mysqli->query("SELECT name FROM projekte WHERE id=$projekt_id")->fetch_row()[0] ?? '';
               if($pName) echo '<div style="font-size:14pt; font-weight:700; color:#0ea5e9; margin-top:5px;">'.h($pName).'</div>';
            endif; ?>
          </div>
          <div class="print-meta">
            <div>Datum: <?= date('d.m.Y') ?></div>
            <div>Seite: 1 / 1</div>
          </div>
        </header>
        
        <?= $html ?>
        
        <footer class="print-footer">
          <div>Baupartnerschaft Management System</div>
          <div>Erstellt von: <?= h($_SESSION['user_name'] ?? 'System') ?></div>
          <div>Dokument-ID: <?= strtoupper(uniqid('PP-')) ?></div>
        </footer>
      </div>
    </body></html>
    <?php exit;
  }
}

/* === Projekte für Filter === */
$projRes = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
$projekte = $projRes ? $projRes->fetch_all(MYSQLI_ASSOC) : [];

/* === QS-Builder === */
$qs = function(array $overrides = []) use ($q,$status,$projekt_id,$von,$bis,$sort,$dirParam,$page,$per_page,$selectedCols,$pdf_size,$pdf_orient,$pdf_fs) {
  $base = [
    'q'=>$q,'status'=>$status,'projekt_id'=>$projekt_id,'von'=>$von,'bis'=>$bis,
    'sort'=>$sort,'dir'=>$dirParam,'page'=>$page,'per_page'=>$per_page,
    'pdf_size'=>$pdf_size,'pdf_orient'=>$pdf_orient,'pdf_fs'=>$pdf_fs,
  ];
  foreach ($selectedCols as $c) $base['cols'][] = $c;
  return http_build_query(array_merge($base,$overrides));
};
?>

<?php if (!$export): ?>
<div class="container-fluid">
  <header class="hero hero-teal" style="display:flex;justify-content:space-between;align-items:center; border-radius:12px; margin-bottom:24px;">
    <h1 style="margin:0;">Pendenzen-Liste</h1>
<?php else: ?>
  <div class="print-content">
<?php endif; ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn" href="<?= h($PREFIX) ?>pages/pendenzen.php">⬅︎ Zur Erfassung</a>
    </div>
  </header>

  <!-- Inline-Erfassung Quick -->
  <section id="inline-create" class="card" style="padding:12px;margin-bottom:12px;">
    <form id="form-create" autocomplete="off">
      <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;align-items:end;">
        <div>
          <label for="fc_proj">Projekt</label>
          <select id="fc_proj" name="projekt_id" class="input">
            <option value="">—</option>
            <?php foreach ($projekte as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="fc_titel">Titel</label>
          <input id="fc_titel" name="titel" type="text" required class="input">
        </div>
        <div style="grid-column: span 2;">
          <label for="fc_beschr">Beschreibung</label>
          <input id="fc_beschr" name="beschreibung" type="text" class="input">
        </div>
        <div>
          <label for="fc_status">Status</label>
          <select id="fc_status" name="status" class="input">
            <option value="offen">offen</option>
            <option value="in_bearbeitung">in_bearbeitung</option>
            <option value="erledigt">erledigt</option>
            <option value="wartend">wartend</option>
          </select>
        </div>
        <div>
          <label for="fc_prio">Priorität</label>
          <input id="fc_prio" name="prioritaet" type="number" min="0" step="1" value="1" class="input">
        </div>
        <div>
          <label for="fc_zust">Zuständig</label>
          <select id="fc_zust" name="zugewiesen_an" class="input">
            <option value="">—</option>
          </select>
        </div>
        <div style="grid-column: span 6; display:flex;align-items:center;gap:12px;">
          <label style="display:flex;align-items:center;gap:6px;">
            <input type="checkbox" id="fc_send" name="send_now" value="1"> sofort senden?
          </label>
          <button class="btn primary" id="fc_save" type="submit">Speichern</button>
          <span id="fc_msg" class="muted"></span>
        </div>
      </div>
    </form>
  </section>

  <!-- Filter + Export -->
  <div class="card" style="padding:1rem">
    <form method="get" style="display:grid; gap:12px">
      <div style="display:grid; gap:8px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end">
        <div>
          <label for="q">Suche</label>
          <input type="text" id="q" name="q"
                 value="<?= h($q) ?>"
                 class="input"
                 placeholder="<?= $HAS_BESCHREIBUNG ? 'Titel/Beschreibung enthält ...' : 'Titel enthält ...' ?>">
        </div>
        <div>
          <label for="status">Status</label>
          <select id="status" name="status" class="input">
            <option value="">– alle –</option>
            <?php foreach (['offen','in_bearbeitung','erledigt','wartend'] as $opt): ?>
              <option value="<?= h($opt) ?>" <?= $status===$opt?'selected':''?>><?= h(ucfirst(str_replace('_',' ',$opt))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="projekt_id">Projekt</label>
          <select id="projekt_id" name="projekt_id" class="input">
            <option value="">– alle –</option>
            <?php foreach ($projekte as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (string)$projekt_id===(string)$p['id']?'selected':'' ?>>
                <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="von">Fällig ab</label>
          <input type="date" id="von" name="von" value="<?= h($von) ?>" class="input" <?= $HAS_ENDDATUM?'':'disabled' ?>>
        </div>
        <div>
          <label for="bis">Fällig bis</label>
          <input type="date" id="bis" name="bis" value="<?= h($bis) ?>" class="input" <?= $HAS_ENDDATUM?'':'disabled' ?>>
        </div>
        <div>
          <label for="per_page">Einträge/Seite</label>
          <input type="number" id="per_page" name="per_page" min="5" max="200" value="<?= (int)$per_page ?>" class="input">
        </div>
        <div>
          <label for="pdf_size">PDF-Größe</label>
          <select id="pdf_size" name="pdf_size" class="input">
            <?php foreach (['a4'=>'A4','a3'=>'A3','letter'=>'Letter'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $pdf_size===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="pdf_orient">PDF-Ausrichtung</label>
          <select id="pdf_orient" name="pdf_orient" class="input">
            <option value="portrait"  <?= $pdf_orient==='portrait'?'selected':'' ?>>Hochformat</option>
            <option value="landscape" <?= $pdf_orient==='landscape'?'selected':'' ?>>Querformat</option>
          </select>
        </div>
        <div>
          <label for="pdf_fs">PDF-Schriftgröße</label>
          <input type="number" id="pdf_fs" name="pdf_fs" min="8" max="16" value="<?= (int)$pdf_fs ?>" class="input">
        </div>
      </div>

      <details>
        <summary><strong>Spalten wählen & anordnen</strong></summary>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:10px">
          <div>
            <div style="margin-bottom:6px; font-weight:600">Verfügbare Spalten</div>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px,1fr)); gap:6px; max-height:220px; overflow:auto; border:1px solid #ddd; padding:8px; border-radius:8px">
              <?php foreach ($labels as $key => $label): ?>
                <label style="display:flex; gap:6px; align-items:center">
                  <input type="checkbox" name="cols[]" value="<?= h($key) ?>" <?= in_array($key, $selectedCols, true)?'checked':'' ?>>
                  <span><?= h($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <div style="margin-bottom:6px; font-weight:600">Reihenfolge (Drag & Drop)</div>
            <ul id="colOrder" style="list-style:none; padding:0; margin:0; border:1px solid #ddd; min-height:42px; border-radius:8px">
              <?php foreach ($selectedCols as $c): if (!isset($labels[$c])) continue; ?>
                <li draggable="true" data-col="<?= h($c) ?>" class="col-item">
                  <?= h($labels[$c]) ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <input type="hidden" name="cols_order_payload" id="cols_order_payload" value="">
            <div style="margin-top:12px; padding:10px; background:#f0f9ff; border-radius:8px; border:1px solid #bae6fd; font-size:12px; color:#0369a1; line-height:1.4;">
                <strong>💡 Spalten-Architekt Hinweis:</strong><br>
                Wählen Sie links die gewünschten Felder aus. Auf der rechten Seite können Sie diese per <strong>Drag & Drop</strong> verschieben, um die Reihenfolge in der Tabelle festzulegen. Ihre Auswahl wird in dieser Browser-Sitzung gespeichert und beim CSV/Excel-Export sowie beim Drucken berücksichtigt.
            </div>
          </div>
        </div>
      </details>

      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
        <button type="submit" class="btn btn-primary">Anwenden</button>
        <a class="btn" href="?">Zurücksetzen</a>

        <button type="submit" name="export" value="csv" class="btn">Export CSV</button>
        <button type="submit" name="export" value="xls" class="btn">Export Excel</button>
        <button type="submit" name="export" value="pdf" class="btn btn-primary">Export PDF (direkt)</button>
        <button type="submit" name="export" value="print" class="btn">Drucken</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div style="overflow:auto">
      <table class="table">
        <thead>
          <tr>
            <?php
            $sortUsed = $sort;
            foreach ($cols as $c):
              $isSortable = isset($ALL_SORTABLE[$c]) || $c === 'projekt_name';
              $nextDir = ($sortUsed===$c && $dirParam==='asc') ? 'desc' : 'asc';
              $link = $isSortable ? ('?'.$qs(['sort'=>$c,'dir'=>$nextDir,'page'=>1])) : '#';
            ?>
              <th>
                <?php if ($isSortable): ?>
                  <a href="<?= h($link) ?>" title="Sortieren">
                    <?= h($labels[$c] ?? $c) ?>
                    <?php if ($sortUsed===$c): ?>
                      <?= ($dirParam==='asc') ? '▲' : '▼' ?>
                    <?php endif; ?>
                  </a>
                <?php else: ?>
                  <?= h($labels[$c] ?? $c) ?>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= count($cols) ?>">Keine Einträge gefunden.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr style="height:40px;">
              <?php foreach ($cols as $c):
                $v = $r[$c] ?? '';
                $disp = h((string)$v);
                
                if (str_starts_with($c, 'json:')) {
                  $fld = str_replace('json:', '', $c);
                  $jx = json_decode((string)($r['extra_json'] ?? '[]'), true) ?: [];
                  $disp = h((string)($jx[$fld] ?? '—'));
                } elseif ($c === 'status') {
                    $cls = '';
                    if(stripos($v,'offen')!==false) $cls='color:#ef4444;font-weight:700;';
                    if(stripos($v,'bearbeitung')!==false) $cls='color:#d97706;font-weight:700;';
                    if(stripos($v,'erledigt')!==false) $cls='color:#16a34a;font-weight:700;';
                    $disp = '<span style="'.$cls.'">'.h($v).'</span>';
                } elseif ($c === 'wichtigkeit') {
                    $disp = str_repeat('★', (int)$v);
                } elseif (in_array($c, ['bilder', 'erstes_bild', 'cover', 'dokumente', 'pdf'])) {
                    $resM = $mysqli->query("SELECT pfad, mimetype FROM pendenz_dateien WHERE pendenz_id=".(int)$r['id']." ".($c==='pdf'?" AND mimetype LIKE '%pdf%'":"")." ORDER BY is_cover DESC, id ASC LIMIT 1");
                    $m = $resM ? $resM->fetch_assoc() : null;
                    if ($m) {
                        if (stripos($m['mimetype'], 'image') !== false) {
                            $disp = '<img src="../'.ltrim($m['pfad'],'/').'" style="width:40px;height:30px;object-fit:cover;border-radius:4px;border:1px solid #ddd;cursor:pointer;" onclick="if(window.openLightbox) openLightbox(this.src)">';
                        } else {
                            $icon = '📄';
                            if (stripos($m['mimetype'], 'pdf') !== false) $icon = '📕';
                            $disp = '<a href="../'.ltrim($m['pfad'],'/').'" target="_blank" style="text-decoration:none;font-size:18px;">'.$icon.'</a>';
                        }
                    } else {
                        $disp = '<span style="color:#cbd5e1">—</span>';
                    }
                }
              ?>
                <td style="vertical-align:middle;" 
                    class="<?= in_array($c, ['titel','beschreibung','kurzbeschreibung','status','startdatum','enddatum','prioritaet','uhrzeit','tageszeit','dauer', 'wichtigkeit']) ? 'inline-editable' : '' ?>"
                    data-id="<?= (int)$r['id'] ?>"
                    data-field="<?= h($c) ?>"
                    data-value="<?= h((string)($r[$c] ?? '')) ?>"
                ><?= $disp ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div style="padding:10px; display:flex; gap:6px; flex-wrap:wrap; align-items:center; justify-content:flex-end">
        <span>Seite <?= (int)$page ?> / <?= (int)$totalPages ?></span>
        <?php
          $nav = function($p, $label) use ($qs) {
            return '<a class="btn" href="?'.h($qs(['page'=>$p])).'">'.h($label).'</a>';
          };
          if ($page > 1) echo $nav(1, '« Erste');
          if ($page > 1) echo $nav($page-1, '‹ Zurück');
          if ($page < $totalPages) echo $nav($page+1, 'Weiter ›');
          if ($page < $totalPages) echo $nav($totalPages, 'Letzte »');
        ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- JS: Spalten-Reihenfolge + Inline-Erfassung -->
<script src="<?= h(asset_url('js/pendenzen_cols_order.js')) ?>" defer></script>
<script src="<?= h(asset_url('js/pendenzen_inline_create.js')) ?>" defer></script>
<script>
    let currentImages = [];
    let currentIndex = 0;

    window.openLightbox = (src) => {
        const lb = document.getElementById('lightbox');
        const allImgs = Array.from(document.querySelectorAll('img[onclick*="openLightbox"]'));
        currentImages = allImgs.map(img => img.src);
        currentIndex = currentImages.indexOf(src);
        
        updateLightbox();
        lb.style.display = 'flex';
    };

    function updateLightbox() {
        const lb = document.getElementById('lightbox');
        lb.querySelector('img').src = currentImages[currentIndex];
        const prev = lb.querySelector('.lb-prev');
        const next = lb.querySelector('.lb-next');
        if (currentImages.length <= 1) {
            prev.style.display = next.style.display = 'none';
        } else {
            prev.style.display = next.style.display = 'flex';
        }
    }

    window.nextImage = (e) => {
        if (e) e.stopPropagation();
        currentIndex = (currentIndex + 1) % currentImages.length;
        updateLightbox();
    };

    window.prevImage = (e) => {
        if (e) e.stopPropagation();
        currentIndex = (currentIndex - 1 + currentImages.length) % currentImages.length;
        updateLightbox();
    };

    window.closeLightbox = () => {
        document.getElementById('lightbox').style.display = 'none';
    };

    document.addEventListener('keydown', (e) => {
        const lb = document.getElementById('lightbox');
        if (lb.style.display === 'flex') {
            if (e.key === 'ArrowRight') nextImage();
            if (e.key === 'ArrowLeft') prevImage();
            if (e.key === 'Escape') closeLightbox();
        }
    });

    // --- INLINE EDIT LOGIC ---
    (function(){
        let currentInput = null;
        document.querySelector('.table')?.addEventListener('click', (e) => {
            const td = e.target.closest('.inline-editable');
            if (!td || currentInput) return;

            const id = td.dataset.id, field = td.dataset.field, oldVal = td.dataset.value;
            let input;

            if (field === 'status') {
                input = document.createElement('select');
                ['offen','in_bearbeitung','erledigt','wartend'].forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt; o.textContent = opt.replace('_',' ');
                    if (opt === oldVal) o.selected = true;
                    input.appendChild(o);
                });
            } else if (['startdatum','enddatum'].includes(field)) {
                input = document.createElement('input'); input.type = 'date'; input.value = oldVal;
            } else if (field === 'prioritaet' || field === 'wichtigkeit') {
                input = document.createElement('input'); input.type = 'number'; input.value = oldVal;
                if(field === 'wichtigkeit') { input.min = 0; input.max = 5; }
            } else {
                input = document.createElement('input'); input.type = 'text'; input.value = oldVal;
            }

            input.className = 'inline-editor';
            const originalHTML = td.innerHTML;

            const save = async () => {
                const newVal = input.value;
                if (newVal === oldVal) { td.innerHTML = originalHTML; currentInput = null; return; }
                
                td.innerHTML = '<span style="color:#0ea5e9; font-size:10px; font-weight:800;">SYNC...</span>';
                try {
                    const res = await fetch('../api/pendenzen_inline_save.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id, updates: { [field]: newVal } })
                    });
                    const resText = await res.text();
                    let j;
                    try { j = JSON.parse(resText); } catch(ex) { console.error("Invalid JSON:", resText); throw new Error("Server-Fehler"); }

                    if (j.ok) {
                        td.dataset.value = newVal;
                        location.reload(); 
                    } else { alert(j.error); td.innerHTML = originalHTML; }
                } catch(err) { alert(err.message); td.innerHTML = originalHTML; }
                currentInput = null;
            };

            td.innerHTML = ''; td.appendChild(input); input.focus();
            currentInput = input;
            input.onblur = save;
            input.onkeydown = (ev) => { if (ev.key === 'Enter') input.blur(); if (ev.key === 'Escape') { td.innerHTML = originalHTML; currentInput = null; } };
        });
    })();
</script>
<style>
  #lightbox { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:5000; cursor:pointer; align-items:center; justify-content:center; backdrop-filter: blur(10px); }
  #lightbox img { max-width:90%; max-height:90%; border-radius:12px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); transition: transform 0.3s ease; z-index: 5001; }
  #lightbox:hover img { transform: scale(1.02); }
  .lb-arrow { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); color: white; border: none; font-size: 3rem; padding: 20px; cursor: pointer; border-radius: 50%; transition: all 0.2s; z-index: 5002; display: flex; align-items: center; justify-content: center; width: 80px; height: 80px; }
  .lb-arrow:hover { background: rgba(255,255,255,0.2); transform: translateY(-50%) scale(1.1); }
  .lb-prev { left: 40px; }
  .lb-next { right: 40px; }

  /* Inline Edit */
  .inline-editable { cursor: cell; transition: background 0.2s; }
  .inline-editable:hover { background-color: #f1f5f9; }
  .inline-editor { width: 100%; border: 2px solid #0ea5e9; border-radius: 4px; padding: 4px; font-size: inherit; outline: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); background: #fff; }
</style>

<?php if (!$export): ?>
</div>
<div id="lightbox" onclick="closeLightbox()">
    <button class="lb-arrow lb-prev" onclick="prevImage(event)">‹</button>
    <img src="" alt="Vorschau" onclick="event.stopPropagation()">
    <button class="lb-arrow lb-next" onclick="nextImage(event)">›</button>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php else: ?>
  </div>
<?php endif; ?>
