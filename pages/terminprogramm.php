<?php
// pages/terminprogramm.php
if (session_status() === PHP_SESSION_NONE) session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$PAGE_TITLE = 'Terminprogramm (Gantt)';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

$pid = (int) ($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));
$projekte = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

$tasks = [];
$raw_tasks = [];
if ($pid > 0) {
    $res = $mysqli->query("
        SELECT p.*, pr.name AS projekt_name, b.name AS zustaendig_name, o.name AS objekt_name, w.name AS wohnung_name, pk.name AS kategorie_name
        FROM pendenzen p 
        LEFT JOIN projekte pr ON p.projekt_id = pr.id 
        LEFT JOIN benutzer b ON p.zustaendig_id = b.id
        LEFT JOIN objekte o ON p.objekt_id = o.id
        LEFT JOIN wohnungen w ON p.wohnung_id = w.id
        LEFT JOIN pendenz_kategorien pk ON p.kategorie_id = pk.id
        WHERE p.projekt_id = $pid 
        ORDER BY p.startdatum ASC, p.id ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $raw_tasks[] = $row;
            $progress = ($row['status'] === 'erledigt') ? 100 : (in_array(strtolower($row['status']), ['in bearbeitung', 'in_bearbeitung']) ? 50 : 0);
            $start = $row['startdatum'] ?: date('Y-m-d');
            $end = $row['enddatum'];
            if (!$end || $end == '0000-00-00') { $end = date('Y-m-d', strtotime($start . " + 2 days")); }
            $tasks[] = [
                'id' => (string)$row['id'],
                'name' => (string)$row['titel'],
                'start' => date('Y-m-d', strtotime($start)),
                'end' => date('Y-m-d', strtotime($end)),
                'progress' => $progress,
                'dependencies' => !empty($row['vorgaenger_id']) ? (string)$row['vorgaenger_id'] : '',
                'custom_class' => 'gantt-task-' . strtolower(str_replace(' ', '-', (string)$row['status']))
            ];
        }
    }
}
$ids = array_column($tasks, 'id');
foreach ($tasks as &$t) {
    if (!empty($t['dependencies'])) {
        $matches = []; preg_match_all('/\d+/', $t['dependencies'], $matches);
        $valid = array_intersect($matches[0], $ids);
        $t['dependencies'] = implode(',', $valid);
    }
}
unset($t);
 
// --- COLUMN CONFIGURATION ---
$ALL_LABELS = [
    'id' => 'ID (Nr.)',
    'titel' => 'Titel',
    'projekt_name' => 'Projekt',
    'status' => 'Status',
    'wichtigkeit' => 'Prio',
    'startdatum' => 'Start',
    'enddatum' => 'Fällig',
    'uhrzeit' => 'Uhrzeit',
    'tageszeit' => 'Tageszeit',
    'dauer' => 'Dauer',
    'created_at' => 'Erstellt am',
    'updated_at' => 'Aktualisiert am',
    'geaendert_am' => 'Geändert am',
    'deleted_at' => 'Gelöscht am',
    'erstellt_von' => 'Erstellt von (ID)',
    'beschreibung' => 'Beschreibung',
    'kurzbeschreibung' => 'Kurz-Info',
    'sichtbarkeit' => 'Sichtbarkeit',
    'balance' => 'Saldo',
    'mandant_id' => 'Mandant Id',
    'projekt_id' => 'Projekt Id',
    'objekt_id' => 'Objekt Id',
    'wohnung_id' => 'Wohnung Id',
    'ordner_id' => 'Ordner Id',
    'fs_rel_path' => 'Fs Rel Path',
    'sort_index' => 'Sort Index',
    'langbeschreibung' => 'Langbeschreibung',
    'notiz' => 'Notiz',
    'send_now' => 'Send Now',
    'vorgaenger_id' => 'Vorgaenger Id',
    'assignee_can_edit' => 'Assignee Can Edit',
    'zustaendig_id' => 'Zustaendig Id',
    'confirmation_required' => 'Confirmation Required',
    'confirmation_by' => 'Confirmation By',
    'confirmation_at' => 'Confirmation At',
    'external_can_view' => 'External Can View',
    'external_can_upload' => 'External Can Upload',
    'public_enabled' => 'Public Enabled',
    'public_token' => 'Public Token',
    'submitted_by' => 'Submitted By',
    'submitted_at' => 'Submitted At',
    'reviewed_by' => 'Reviewed By',
    'reviewed_at' => 'Reviewed At',
    'extra_json' => 'Extra Json',
    'zustaendig_typ' => 'Zustaendig Typ',
    'sicht_ref_id' => 'Sicht Ref Id',
    'is_protocol' => 'Is Protocol',
    'protocol_type' => 'Protocol Type',
    'kategorie_id' => 'Kategorie Id',
    'subkategorie_id' => 'Subkategorie Id',
    'bilder' => 'Bild',
    'dokumente' => 'Dokument'
];

$selectedCols = $_SESSION['tp_cols'] ?? ['id', 'titel', 'startdatum', 'enddatum', 'status', 'dauer', 'vorgaenger_id'];
if (isset($_GET['cols_order_payload']) && !empty($_GET['cols_order_payload'])) {
    $selectedCols = explode(',', $_GET['cols_order_payload']);
    $_SESSION['tp_cols'] = $selectedCols;
}

$cur_oid = (int)(($_GET['objekt_id'] ?? 0) ?: ($_SESSION['current_objekt_id'] ?? 0));
$cur_wid = (int)(($_GET['wohnung_id'] ?? 0) ?: ($_SESSION['current_wohnung_id'] ?? 0));
?>

<style>
    :root { 
        --gantt-header-bg: #f1f5f9; 
        --gantt-border: #cbd5e1; 
        --tp-table-h: 60px; 
        --tp-gantt-h: 78px;
        --tp-head-tab-h: 70px;
        --tp-head-ga-h: 70px;
    }
    .tp-wrapper { padding: 20px; min-height: calc(100vh - 80px); }
    .tp-container { display: flex; flex-direction: column; height: calc(100vh - 120px); background: #fff; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid var(--gantt-border); }
    .tp-toolbar { padding: 12px 20px; background: #fff; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: nowrap; overflow-x: auto; }
    .tp-split-view { display: grid; grid-template-columns: 450px 8px 1fr; flex: 1; overflow: hidden; position: relative; }
    .tp-table-pane { overflow-y: hidden; overflow-x: auto; background: #fff; border-right: 1px solid var(--gantt-border); }
    .tp-table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
    .tp-table th { 
        position: sticky; top: 0; background: var(--gantt-header-bg); padding: 0 10px !important; 
        height: var(--tp-head-tab-h) !important; line-height: var(--tp-head-tab-h) !important; 
        text-align: left; font-weight: 800; border-bottom: 2px solid var(--gantt-border); z-index: 20; color: #475569; box-sizing: border-box; 
    }
    .tp-table td { 
        padding: 0 8px !important; height: var(--tp-table-h) !important; 
        line-height: var(--tp-table-h) !important; border-bottom: 1px solid #e2e8f0; 
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
        box-sizing: border-box; vertical-align: middle; 
        max-width: 0; /* Ensures ellipsis works in fixed layout */
    }
    .tp-gantt-pane { flex: 1; overflow: auto; background: #fff; }
    .tp-resizer { width: 8px; cursor: col-resize; background: #f8fafc; border-left: 1px solid #cbd5e1; border-right: 1px solid #cbd5e1; z-index: 100; display: flex; align-items: center; justify-content: center; }
    .tp-resizer:hover { background: #0ea5e9; }
    .tp-resizer::after { content: "⋮"; color: #94a3b8; }
    .view-btn-group { display: flex; background: #f1f5f9; padding: 2px; border-radius: 8px; }
    .btn-view { padding: 4px 10px; border: none; background: transparent; font-size: 11px; font-weight: 700; color: #64748b; cursor: pointer; border-radius: 6px; }
    .btn-view.active { background: #fff; color: #0ea5e9; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .gantt .upper-text { font-weight: 800; fill: #1e293b; font-size: 13px; dominant-baseline: central; }
    .gantt .lower-text { font-weight: 600; fill: #64748b; font-size: 11px; dominant-baseline: central; }
    
    /* Inline Editor Styles */
    .inline-editor { 
        width: 100%; border: 2px solid #0ea5e9; border-radius: 4px; padding: 4px; 
        font-size: 13px; outline: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); 
        background: #fff; color: #1e293b;
    }
    td.inline-editable:hover { background: #f1f5f9; cursor: cell; }
    td.inline-editable { transition: 0.2s; position: relative; }
    
    #marker-line-group line { pointer-events: none; }
    #marker-line-label { font-size: 10px; font-weight: 800; fill: #ef4444; pointer-events: none; }

    /* Highlight Active Rows */
    tr.active-at-marker td { background-color: #fef9c3 !important; border-bottom: 2px solid #facc15 !important; }
    tr.active-at-marker td:first-child { border-left: 4px solid #facc15; }

    /* Column Selector Styles */
    #colSelector { display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:800px; max-width:95vw; background:#fff; border-radius:12px; box-shadow:0 20px 50px rgba(0,0,0,0.3); z-index:2000; padding:25px; border:1px solid #e2e8f0; }
    #colSelector .col-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; max-height:400px; overflow-y:auto; padding:15px; border:1px solid #f1f5f9; border-radius:8px; background:#f8fafc; }
    #colSelector label { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; color:#475569; cursor:pointer; }
    #colOrder { list-style:none; padding:10px; margin:0; border:1px solid #f1f5f9; border-radius:8px; background:#fff; max-height:400px; overflow-y:auto; }
    .col-item { padding:8px 12px; border-bottom:1px solid #f1f5f9; cursor:move; font-size:12px; font-weight:700; color:#1e293b; background:#fff; display:flex; align-items:center; gap:8px; }
    .col-item:hover { background: #f0f9ff; }
    .col-item::before { content: "⠿"; color:#94a3b8; }
    .overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); z-index:1999; backdrop-filter:blur(2px); }

    /* Column Resizing */
    .tp-table th { position: relative; }
    .col-resizer { 
        position: absolute; top: 0; right: 0; width: 6px; cursor: col-resize; 
        height: 100%; user-select: none; z-index: 10; transition: background 0.2s;
    }
    .col-resizer:hover, .col-resizer.resizing { background: #0ea5e9; }

    /* Status Badges */
    .status-badge { display: inline-block; padding: 0; font-size: 10px; font-weight: 900; text-transform: uppercase; white-space: nowrap; background: transparent !important; }
    .status-offen { color: #dc2626 !important; }
    .status-in-bearbeitung { color: #d97706 !important; }
    .status-erledigt { color: #166534 !important; }
    .status-wartend { color: #475569 !important; }

    /* Lightbox */
    #lightbox { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:5000; cursor:pointer; align-items:center; justify-content:center; backdrop-filter: blur(10px); }
    #lightbox img { max-width:90%; max-height:90%; border-radius:12px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5); transition: transform 0.3s ease; z-index: 5001; }
    #lightbox:hover img { transform: scale(1.02); }
    .lb-arrow { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.1); color: white; border: none; font-size: 3rem; padding: 20px; cursor: pointer; border-radius: 50%; transition: all 0.2s; z-index: 5002; display: flex; align-items: center; justify-content: center; width: 80px; height: 80px; }
    .lb-arrow:hover { background: rgba(255,255,255,0.2); transform: translateY(-50%) scale(1.1); }
    .lb-prev { left: 40px; }
    .lb-next { right: 40px; }
</style>

<div class="tp-wrapper">
    <div class="tp-container">
        <div class="tp-toolbar">
            <div style="display:flex; align-items:center; gap:8px;">
                <div style="background:#0ea5e9; color:#fff; width:30px; height:30px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:12px;">TP</div>
                <h2 style="margin:0; font-size:14px; color:#1f2937; white-space:nowrap;">Zeitplan</h2>
            </div>
            
            <div style="display:flex; gap:8px; align-items:center; flex-shrink:0;">
                <select onchange="location.href='?projekt_id='+this.value" class="input" style="height:32px; border-radius:6px; min-width:140px; font-size:12px;">
                    <option value="">-- Projekt --</option>
                    <?php foreach ($projekte as $p): ?><option value="<?= $p['id'] ?>" <?= $pid == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
                
                <div style="display:flex; align-items:center; gap:6px; background:#f8fafc; padding:2px 8px; border-radius:8px; border:1px solid #cbd5e1;">
                    <span style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Head Tab:</span>
                    <input type="number" id="headTabInput" value="70" onchange="applyHeadTab(this.value)" style="width:35px; border:none; outline:none; font-weight:800; background:transparent; text-align:center; color:#6366f1; font-size:11px;">
                    <span style="color:#cbd5e1;">|</span>
                    <span style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Head Ga:</span>
                    <input type="number" id="headGanttInput" value="70" onchange="applyHeadGantt(this.value)" style="width:35px; border:none; outline:none; font-weight:800; background:transparent; text-align:center; color:#ec4899; font-size:11px;">
                    <span style="color:#cbd5e1;">|</span>
                    <span style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Tab Row:</span>
                    <input type="number" id="tableHeightInput" value="60" min="20" onchange="applyTableHeight(this.value)" style="width:35px; border:none; outline:none; font-weight:800; background:transparent; text-align:center; color:#0ea5e9; font-size:11px;">
                    <span style="color:#cbd5e1;">|</span>
                    <span style="font-size:9px; font-weight:800; color:#64748b; text-transform:uppercase;">Ga Row:</span>
                    <input type="number" id="ganttHeightInput" value="78" onchange="applyGanttHeight(this.value)" style="width:35px; border:none; outline:none; font-weight:800; background:transparent; text-align:center; color:#f59e0b; font-size:11px;">
                    <span style="color:#cbd5e1;">|</span>
                    <span style="font-size:9px; font-weight:800; color:#ef4444; text-transform:uppercase;">Fokus-Tag:</span>
                    <input type="date" id="markerDateInput" onchange="applyMarkerDate(this.value)" style="border:none; outline:none; font-weight:800; background:transparent; text-align:center; color:#ef4444; font-size:12px; cursor:pointer; padding:2px;">
                </div>

                <div class="view-btn-group">
                    <?php foreach(['Half Day'=>'12h','Day'=>'Tag','Week'=>'Woche','Month'=>'Monat'] as $m=>$l): ?>
                    <button class="btn-view" data-mode="<?= $m ?>" onclick="change_view('<?= $m ?>', this)"><?= $l ?></button>
                    <?php endforeach; ?>
                </div>

                <button onclick="toggleColsMenu()" class="btn" style="background:#fff; border:1px solid #cbd5e1; height:32px; padding:0 8px; border-radius:6px; font-size:12px;">Spalten ⚙️</button>
            </div>
        </div>

        <div class="tp-split-view">
            <div class="tp-table-pane" id="syncTable">
                <table class="tp-table">
                    <thead>
                        <tr>
                            <?php foreach ($selectedCols as $c): ?>
                            <th data-col="<?= h($c) ?>" style="width:<?= in_array($c, ['titel','beschreibung','langbeschreibung']) ? '220px' : (in_array($c, ['startdatum','enddatum','created_at','updated_at']) ? '100px' : '80px') ?>;">
                                <?= e($ALL_LABELS[$c] ?? $c) ?>
                                <div class="col-resizer"></div>
                            </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($raw_tasks as $r): ?>
                        <tr data-id="<?= $r['id'] ?>">
                            <?php foreach ($selectedCols as $c): 
                                $v = $r[$c] ?? '';
                                $type = 'text'; $disp = e($v); $val = $v;
                                if (in_array($c, ['startdatum','enddatum'])) { $type='date'; $disp = $v ? date('d.m.y', strtotime($v)) : '—'; }
                                if ($c === 'id') { $disp = '<span style="color:#64748b; font-family:monospace;">#'.$v.'</span>'; }
                                if ($c === 'titel') { $disp = '<strong>'.e($v).'</strong>'; }
                                if ($c === 'wichtigkeit') { $type='number'; $disp = str_repeat('★', (int)$v); }
                                if ($c === 'status') { 
                                    $cls = 'status-'.strtolower(str_replace(' ', '-', (string)$v));
                                    $disp = '<span class="status-badge '.$cls.'">'.e($v).'</span>';
                                }
                                if (in_array($c, ['bilder', 'dokumente']) && $v === '') {
                                    $pid_row = (int)$r['id'];
                                    $typeFilt = ($c === 'bilder') ? "typ='image'" : "typ!='image'";
                                    $resM = $mysqli->query("SELECT pfad, mimetype FROM pendenz_dateien WHERE pendenz_id=$pid_row AND $typeFilt ORDER BY COALESCE(is_cover,0) DESC, CASE WHEN titel='Plan-Ausschnitt' THEN 1 ELSE 0 END ASC, id ASC LIMIT 1");
                                    $m = $resM ? $resM->fetch_assoc() : null;
                                    if ($m) {
                                        if ($c === 'bilder') {
                                            $disp = '<img src="../'.ltrim($m['pfad'],'/').'" onclick="openLightbox(this.src)" style="width:40px; height:30px; object-fit:cover; border-radius:4px; border:1px solid #ddd; cursor:zoom-in;">';
                                        } else {
                                            $icon = '📄';
                                            if (stripos($m['mimetype'], 'pdf') !== false) $icon = '📕';
                                            $disp = '<span style="font-size:16px;" title="'.e($m['mimetype']).'">'.$icon.'</span>';
                                        }
                                    } else {
                                        $disp = '—';
                                    }
                                }
                            ?>
                            <td class="inline-editable" data-field="<?= $c ?>" data-type="<?= $type ?>" data-id="<?= $r['id'] ?>" data-value="<?= e($val) ?>"><?= $disp ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>

                        <?php if ($pid > 0): ?>
                        <tr id="create-row" style="background: #f0f9ff;">
                            <?php foreach ($selectedCols as $c): ?>
                            <td style="height:45px; border-top:2px solid #0ea5e9; border-bottom:1px solid #0ea5e9; padding:0 8px !important; transition: all 0.3s;">
                                <?php if ($c === 'titel'): ?>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="color:#0ea5e9; font-size:16px; font-weight:900;">⊕</span>
                                    <input type="text" id="new-task-titel" placeholder="Neue Aufgabe hier schnell erfassen..." 
                                           style="width:100%; border:none; background:transparent; font-weight:800; outline:none; font-size:13px; color:#0369a1; padding:0;">
                                </div>
                                <?php else: ?>
                                <span style="color:#bae6fd;">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="tp-resizer" id="tpSplitter"></div>
            <div class="tp-gantt-pane" id="syncGantt"><svg id="gantt-svg"></svg></div>
        </div>
    </div>
</div>

<div class="overlay" id="colOverlay" onclick="toggleColsMenu()"></div>
<div id="colSelector">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0; font-size:16px;">Spalten wählen & anordnen</h3>
        <button onclick="toggleColsMenu()" style="border:none; background:none; font-size:24px; cursor:pointer; color:#94a3b8;">&times;</button>
    </div>
    <form id="colForm" method="GET">
        <input type="hidden" name="projekt_id" value="<?= $pid ?>">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:25px;">
            <div>
                <div style="margin-bottom:10px; font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase;">Verfügbare Spalten</div>
                <div class="col-grid">
                    <?php foreach ($ALL_LABELS as $key => $label): ?>
                    <label>
                        <input type="checkbox" name="cols[]" value="<?= h($key) ?>" <?= in_array($key, $selectedCols, true)?'checked':'' ?>>
                        <span><?= h($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div style="margin-bottom:10px; font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase;">Reihenfolge (Drag & Drop)</div>
                <ul id="colOrder">
                    <?php foreach ($selectedCols as $c): if (!isset($ALL_LABELS[$c])) continue; ?>
                    <li draggable="true" data-col="<?= h($c) ?>" class="col-item"><?= h($ALL_LABELS[$c]) ?></li>
                    <?php endforeach; ?>
                </ul>
                <input type="hidden" name="cols_order_payload" id="cols_order_payload" value="">
            </div>
        </div>
        <div style="margin-top:25px; display:flex; justify-content:flex-end; gap:10px;">
            <button type="button" class="btn" onclick="toggleColsMenu()" style="background:#f1f5f9; color:#475569;">Abbrechen</button>
            <button type="submit" class="btn" style="background:#0ea5e9; color:#white; font-weight:800; padding:0 25px;">Speichern & Anwenden</button>
        </div>
        <p style="margin-top:10px; font-size:11px; color:#94a3b8;">Die Reihenfolge wird beim Absenden übernommen.</p>
    </form>
</div>


<link rel="stylesheet" href="<?= e(asset_url('css/frappe-gantt.css')) ?>" />
<script src="<?= e(asset_url('js/frappe-gantt.min.js')) ?>"></script>

<script>
    var tasks = <?= json_encode($tasks) ?>;
    var gantt;

    function applyHeadTab(h) {
        h = parseInt(h) || 70; localStorage.setItem('tpHeadTab', h);
        document.documentElement.style.setProperty('--tp-head-tab-h', h + 'px');
    }
    function applyHeadGantt(h) {
        h = parseInt(h) || 70; localStorage.setItem('tpHeadGantt', h);
        document.documentElement.style.setProperty('--tp-head-ga-h', h + 'px');
        initGantt();
    }
    function applyTableHeight(h) {
        h = parseInt(h) || 60; localStorage.setItem('tpHeightTable', h);
        document.documentElement.style.setProperty('--tp-table-h', h + 'px');
    }
    function applyGanttHeight(h) {
        h = parseInt(h) || 78; localStorage.setItem('tpHeightGantt', h);
        initGantt();
    }
    
    function applyMarkerDate(d) {
        localStorage.setItem('tpMarkerDate', d);
        drawMarkerLine();
    }

    function initGantt() {
        if (!tasks.length) return;
        const gH = parseInt(localStorage.getItem('tpHeightGantt')) || 78;
        const gaHeadH = parseInt(localStorage.getItem('tpHeadGantt')) || 70;
        let p = Math.floor(gH / 4), bh = gH - (2 * p);
        const container = document.getElementById('syncGantt'); container.innerHTML = '<svg id="gantt-svg"></svg>';
        gantt = new Gantt("#gantt-svg", tasks, {
            header_height: gaHeadH, column_width: 30, step: 24, view_mode: 'Day', language: 'de',
            bar_height: bh, padding: p, on_date_change: (t, s, e) => saveGanttChange(t.id, s, e)
        });
        setTimeout(drawMarkerLine, 100);
    }

    function drawMarkerLine() {
        const dateStr = localStorage.getItem('tpMarkerDate');
        if (!dateStr || !gantt) return;
        const svg = document.querySelector('#gantt-svg');
        if (!svg) return;

        const date = new Date(dateStr);
        // Calculate X position
        const start = gantt.gantt_start;
        const diffHours = (date - start) / (1000 * 60 * 60);
        const x = (diffHours / gantt.options.step) * gantt.options.column_width;

        // Remove old
        svg.querySelectorAll('#marker-line-group').forEach(el => el.remove());

        const h = svg.getBoundingClientRect().height || 1000;
        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        group.setAttribute('id', 'marker-line-group');

        const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
        line.setAttribute('x1', x); line.setAttribute('y1', 0);
        line.setAttribute('x2', x); line.setAttribute('y2', h);
        line.setAttribute('stroke', '#ef4444');
        line.setAttribute('stroke-width', '2');
        line.setAttribute('stroke-dasharray', '5,5');

        const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        label.setAttribute('id', 'marker-line-label');
        label.setAttribute('x', x + 5);
        label.setAttribute('y', 15);
        label.textContent = new Date(dateStr).toLocaleDateString('de-DE');

        group.appendChild(line);
        group.appendChild(label);
        svg.appendChild(group);

        // --- HIGHLIGHT ROWS IN TABLE ---
        const selDate = new Date(dateStr); selDate.setHours(0,0,0,0);
        document.querySelectorAll('.tp-table tbody tr').forEach(row => {
            const startStr = row.querySelector('[data-field="startdatum"]')?.dataset.value;
            const endStr = row.querySelector('[data-field="enddatum"]')?.dataset.value;
            row.classList.remove('active-at-marker');
            if (startStr && endStr && startStr !== '0000-00-00' && endStr !== '0000-00-00') {
                const s = new Date(startStr); s.setHours(0,0,0,0);
                const e = new Date(endStr); e.setHours(0,0,0,0);
                if (selDate >= s && selDate <= e) {
                    row.classList.add('active-at-marker');
                }
            }
        });
    }

    async function saveGanttChange(id, s, e) {
        const payload = { id, updates: { startdatum: s.toISOString().split('T')[0], enddatum: e.toISOString().split('T')[0] } };
        await fetch('../api/pendenzen_inline_save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        location.reload();
    }
    function change_view(mode, btn) { 
        gantt.change_view_mode(mode); 
        localStorage.setItem('tpViewMode', mode);
        document.querySelectorAll('.btn-view').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        setTimeout(drawMarkerLine, 100); 
    }
    
    // --- SPREADSHEET INLINE EDITOR ---
    (function() {
        let currentInput = null;
        const statusOptions = ["offen", "in Bearbeitung", "erledigt", "archiviert", "wartend"];

        document.addEventListener('click', e => {
            const td = e.target.closest('.inline-editable');
            if (td && !td.querySelector('.inline-editor')) startEditing(td);
        });

        window.startEditing = async (td) => {
            const field = td.dataset.field;
            const id = td.dataset.id;
            const type = td.dataset.type || 'text';
            const oldVal = td.dataset.value || td.textContent.trim();
            
            let input;
            if (field === 'status') {
                input = document.createElement('select');
                statusOptions.forEach(opt => {
                    const o = new Option(opt, opt);
                    if (opt === oldVal) o.selected = true;
                    input.add(o);
                });
            } else {
                input = document.createElement('input');
                input.type = type;
                input.value = oldVal;
            }
            
            input.className = 'inline-editor';
            const originalContent = td.innerHTML;

            const finish = async () => {
                if (currentInput !== input) return;
                const newVal = input.value;
                currentInput = null;
                if (newVal === oldVal) { td.innerHTML = originalContent; return; }

                td.innerHTML = '<span style="color:#0ea5e9; font-size:10px; font-weight:800;">SYNC...</span>';
                let bodyData = { id, updates: {} };
                bodyData.updates[field] = newVal;

                try {
                    const res = await fetch('../api/pendenzen_inline_save.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(bodyData)
                    });
                    if ((await res.json()).ok) location.reload();
                    else td.innerHTML = originalContent;
                } catch (e) { td.innerHTML = originalContent; }
            };

            td.innerHTML = ''; td.appendChild(input); input.focus(); currentInput = input;
            input.onblur = finish;
            input.onkeydown = (ev) => {
                if (ev.key === 'Enter') input.blur();
                if (ev.key === 'Escape') { currentInput = null; td.innerHTML = originalContent; }
            };
        };
    })();
    function toggleColsMenu() { 
        const s = document.getElementById('colSelector').style;
        const o = document.getElementById('colOverlay').style;
        const sh = s.display === 'block';
        s.display = o.display = sh ? 'none' : 'block';
    }
    function toggleCol(idx, show) { document.querySelectorAll('.col-'+idx).forEach(td => td.style.display = show?'':'none'); }

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
            if (prev) prev.style.display = 'none';
            if (next) next.style.display = 'none';
        } else {
            if (prev) prev.style.display = 'flex';
            if (next) next.style.display = 'flex';
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
        if (lb && lb.style.display === 'flex') {
            if (e.key === 'ArrowRight') nextImage();
            if (e.key === 'ArrowLeft') prevImage();
            if (e.key === 'Escape') closeLightbox();
        }
    });
</script>
<script src="<?= e(asset_url('js/pendenzen_cols_order.js')) ?>"></script>
<script>

    document.addEventListener('DOMContentLoaded', () => {
        const ht = parseInt(localStorage.getItem('tpHeadTab')) || 70;
        const hg = parseInt(localStorage.getItem('tpHeadGantt')) || 70;
        const rt = parseInt(localStorage.getItem('tpHeightTable')) || 60;
        const rg = parseInt(localStorage.getItem('tpHeightGantt')) || 78;
        const md = localStorage.getItem('tpMarkerDate') || new Date().toISOString().split('T')[0];
        const vm = localStorage.getItem('tpViewMode') || 'Day';
        
        document.getElementById('headTabInput').value = ht;
        document.getElementById('headGanttInput').value = hg;
        document.getElementById('tableHeightInput').value = rt;
        document.getElementById('ganttHeightInput').value = rg;
        document.getElementById('markerDateInput').value = md;
        
        const activeBtn = document.querySelector(`.btn-view[data-mode="${vm}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        if (!localStorage.getItem('tpMarkerDate')) localStorage.setItem('tpMarkerDate', md);
        if (!localStorage.getItem('tpViewMode')) localStorage.setItem('tpViewMode', vm);

        applyHeadTab(ht); applyHeadGantt(hg);
        applyTableHeight(rt); applyGanttHeight(rg);
        if (vm !== 'Day') gantt.change_view_mode(vm);

        const syncT = document.getElementById('syncTable'), syncG = document.getElementById('syncGantt');
        syncG.onscroll = () => syncT.scrollTop = syncG.scrollTop; syncT.onscroll = () => syncG.scrollTop = syncT.scrollTop;

        // Splitter
        const sl = document.getElementById('tpSplitter'), sv = document.querySelector('.tp-split-view');
        sl.onmousedown = (e) => {
            const mv = (ev) => {
                const w = ev.clientX - sv.getBoundingClientRect().left;
                if (w > 50 && w < window.innerWidth - 100) { sv.style.gridTemplateColumns = `${w}px 8px 1fr`; localStorage.setItem('tp_w', w); }
            };
            const up = () => { document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); };
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
        };
        if (localStorage.getItem('tp_w')) sv.style.gridTemplateColumns = `${localStorage.getItem('tp_w')}px 8px 1fr`;
        
        // --- COLUMN RESIZING LOGIC ---
        let startX, startWidth, activeTh;
        const syncTable = document.getElementById('syncTable');

        document.querySelectorAll('.tp-table th').forEach(th => {
            const savedW = localStorage.getItem('tp_col_w_' + th.dataset.col);
            if (savedW) th.style.width = savedW + 'px';
        });

        syncTable.addEventListener('mousedown', e => {
            if (!e.target.classList.contains('col-resizer')) return;
            activeTh = e.target.parentElement;
            startX = e.pageX;
            startWidth = activeTh.offsetWidth;
            e.target.classList.add('resizing');
            
            const mv = (ev) => {
                const w = startWidth + (ev.pageX - startX);
                if (w > 40) {
                    activeTh.style.width = w + 'px';
                    localStorage.setItem('tp_col_w_' + activeTh.dataset.col, w);
                }
            };
            const up = () => {
                e.target.classList.remove('resizing');
                document.removeEventListener('mousemove', mv);
                document.removeEventListener('mouseup', up);
            };
            document.addEventListener('mousemove', mv);
            document.addEventListener('mouseup', up);
        });

        // --- INLINE CREATE LOGIC ---
        const nt = document.getElementById('new-task-titel');
        if (nt) {
            nt.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter') {
                    const t = nt.value.trim();
                    if (!t) return;
                    nt.disabled = true; nt.placeholder = "WIRD ERSTELLT...";
                    try {
                        const r = await fetch('../api/gantt_task_create.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                projekt_id: <?= (int)$pid ?>, 
                                objekt_id: <?= (int)$cur_oid ?>,
                                wohnung_id: <?= (int)$cur_wid ?>,
                                titel: t, 
                                status: 'offen' 
                            })
                        });
                        const j = await r.json();
                        if (j.ok) location.reload();
                        else { alert(j.error); nt.disabled = false; nt.placeholder = "+ Neue Aufgabe..."; }
                    } catch (err) { alert("Fehler beim Erstellen"); nt.disabled = false; }
                }
            });
        }
    });
</script>
<div id="lightbox" onclick="closeLightbox()">
    <button class="lb-arrow lb-prev" onclick="prevImage(event)">‹</button>
    <img src="" alt="Vorschau" onclick="event.stopPropagation()">
    <button class="lb-arrow lb-next" onclick="nextImage(event)">›</button>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>