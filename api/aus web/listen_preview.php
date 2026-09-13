<?php
// api/listen_preview.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) $data = $_POST;

$cols = $data['columns'] ?? [];
if(empty($cols)) {
    echo "<div class='muted'>Bitte wähle Spalten aus, um eine Vorschau zu sehen.</div>";
    exit;
}

$colNames = array_map(fn($c) => $c['col_name'] ?? $c, $cols);

// Holen der Datensätze
$sql = "SELECT p.*, 
               pr.name AS projekt_name, 
               pa.name AS vorgangsart_name,
               ob.name AS objekt_name,
               wo.name AS wohnung_name,
               bz.name AS zustaendig_name,
               bv.name AS verantwortlicher
        FROM pendenzen p 
        LEFT JOIN projekte pr ON p.projekt_id = pr.id 
        LEFT JOIN pendenzen_arten pa ON p.vorgangsart_id = pa.id
        LEFT JOIN objekte ob ON p.objekt_id = ob.id
        LEFT JOIN wohnungen wo ON p.wohnung_id = wo.id
        LEFT JOIN benutzer bz ON p.zustaendig_id = bz.id
        LEFT JOIN benutzer bv ON p.zustaendig_id = bv.id
        ORDER BY p.id DESC LIMIT 5";
$res = $mysqli->query($sql);
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Wir bauen 1:1 die Struktur aus pendenzen.php nach, um das CSS fehlerfrei zu übernehmen
echo "<div style='margin-bottom:12px; display:flex; gap:8px; align-items:center;'>";
echo "<a href='#' class='btn btn-primary' style='background:#10b981; border:none; padding:4px 12px; color:white; font-size:14px; text-decoration:none;'>« Zurück</a>";
echo "<span style='color:#334155; font-size:14px;'>Offset: 0 / Schritt: 25</span>";
echo "<a href='#' class='btn btn-primary' style='background:#10b981; border:none; padding:4px 12px; color:white; font-size:14px; text-decoration:none;'>Weiter »</a>";
echo "</div>";

echo "<div style='overflow-x:auto;'>";
echo "<table class='table' id='pendenzenTable'>";

// Zeile 1: Column Headers (sort links)
$labels = [
  'id'=>'ID','erstes_bild'=>'Bild','cover'=>'Cover','titel'=>'Titel','projekt_name'=>'Projekt','status'=>'Status',
  'vorgangsart_name'=>'Vorgangsart',
  'wichtigkeit'=>'Prio', 'startdatum'=>'Start', 'enddatum'=>'Fällig', 'uhrzeit'=>'Zeit', 'tageszeit'=>'Abschnitt',
  'dauer'=>'Dauer', 'objekt_name'=>'Objekt', 'wohnung_name'=>'Einheit', 'verantwortlicher'=>'Verantwortlicher',
  'kategorie_name'=>'Kategorie', 'unterkategorie_name'=>'Sub',
  'erstellt_am'=>'Erstellt', 'geaendert_am'=>'Update', 'bilder'=>'📸', 'anhaenge'=>'📎', 'balance'=>'Saldo', 'fs_rel_path'=>'Ordner'
];

echo "<thead><tr>";
foreach($colNames as $c) {
    if(strpos($c, 'json:') === 0) {
        $cLabel = htmlspecialchars(ucfirst(str_replace('json:', '', $c)));
        echo "<th class='sort'><a href='#'>{$cLabel}</a></th>";
    } else {
        $label = htmlspecialchars($labels[$c] ?? ucfirst(str_replace('_', ' ', $c)));
        echo "<th class='sort'><a href='#'>{$label}</a></th>";
    }
}
echo "<th class='actions-th'><div>Aktionen</div></th></tr>";

// Zeile 2: Filter-Zeile
echo "<tr class='filter-row'>";
foreach($colNames as $c) {
    echo "<th><input type='text' placeholder='Filtern…'></th>";
}
echo "<th></th>";
echo "</tr>";
echo "</thead>";

// Body
echo "<tbody>";
foreach($rows as $r) {
    $extra = [];
    if (!empty($r['extra_json'])) $extra = json_decode($r['extra_json'], true) ?: [];
    
    echo "<tr>";
    foreach($colNames as $c) {
        $val = '-';
        if(strpos($c, 'json:') === 0) {
            $key = str_replace('json:', '', $c);
            $val = $extra[$key] ?? '-';
        } else {
            $val = $r[$c] ?? '-';
            if (strpos($c, 'datum') !== false && $val !== '-') {
                $d = date("d.m.Y", strtotime($val));
                $time = isset($r['uhrzeit']) ? trim((string)$r['uhrzeit']) : '';
                $tz = isset($r['tageszeit']) ? trim((string)$r['tageszeit']) : '';
                
                $val = "<div>$d";
                if ($time || $tz) {
                    $val .= "<div style='font-size:10px; opacity:0.6;'>";
                    if ($time) $val .= substr($time, 0, 5) . " ";
                    if ($tz) $val .= "$tz";
                    $val .= "</div>";
                }
                $val .= "</div>";
                echo "<td>".$val."</td>";
            } else {
                echo "<td>".htmlspecialchars((string)$val)."</td>";
            }
        }
    }
    // Action Buttons Platzhalter
    echo "<td><div style='display:inline-flex; width:28px; height:24px; background:#10b981; border-radius:4px; align-items:center; justify-content:center; color:#fff;'><svg width='14' height='14' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-width='2' d='M15 12a3 3 0 11-6 0 3 3 0 016 0z'></path><path stroke-width='2' d='M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z'></path></svg></div></td>";
    echo "</tr>";
}
echo "</tbody></table></div>";
