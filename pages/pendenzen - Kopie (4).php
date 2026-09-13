<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$PAGE_TITLE = 'Pendenzen';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die('Keine gültige Datenbankverbindung vorhanden.');
}

$success = '';
$error = '';

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function postStr(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function postIntOrNull(string $key): ?int
{
    if (!isset($_POST[$key])) {
        return null;
    }

    $value = trim((string)$_POST[$key]);
    if ($value === '') {
        return null;
    }

    return ctype_digit($value) ? (int)$value : null;
}

function currentUserId(): ?int
{
    $keys = ['user_id', 'benutzer_id', 'admin_id', 'id'];

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach ($keys as $key) {
            if (isset($_SESSION['user'][$key]) && is_numeric($_SESSION['user'][$key])) {
                return (int)$_SESSION['user'][$key];
            }
        }
    }

    return null;
}

$prefillProjektId      = isset($_GET['projekt_id']) && ctype_digit((string)$_GET['projekt_id']) ? (int)$_GET['projekt_id'] : null;
$prefillObjektId       = isset($_GET['objekt_id']) && ctype_digit((string)$_GET['objekt_id']) ? (int)$_GET['objekt_id'] : null;
$prefillWohnungId      = isset($_GET['wohnung_id']) && ctype_digit((string)$_GET['wohnung_id']) ? (int)$_GET['wohnung_id'] : null;
$prefillKategorieId    = isset($_GET['kategorie_id']) && ctype_digit((string)$_GET['kategorie_id']) ? (int)$_GET['kategorie_id'] : null;
$prefillUnternehmerId  = isset($_GET['unternehmer_id']) && ctype_digit((string)$_GET['unternehmer_id']) ? (int)$_GET['unternehmer_id'] : null;
$prefillVorgangsartId  = isset($_GET['vorgangsart_id']) && ctype_digit((string)$_GET['vorgangsart_id']) ? (int)$_GET['vorgangsart_id'] : null;

$input = [
    'projekt_id' => $prefillProjektId,
    'vorgangsart_id' => $prefillVorgangsartId,
    'objekt_id' => $prefillObjektId,
    'wohnung_id' => $prefillWohnungId,
    'titel' => '',
    'kurzbeschreibung' => '',
    'langbeschreibung' => '',
    'beschreibung' => '',
    'notiz' => '',
    'status' => 'offen',
    'wichtigkeit' => '',
    'startdatum' => '',
    'enddatum' => '',
    'uhrzeit' => '',
    'dauer' => '',
    'zustaendig_id' => $prefillUnternehmerId,
    'kategorie_id' => $prefillKategorieId,
    'subkategorie_id' => null,
    'send_now' => 0,
    'confirmation_required' => 0,
    'external_can_view' => 1,
    'external_can_upload' => 0,
    'public_enabled' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['projekt_id'] = postIntOrNull('projekt_id');
    $input['vorgangsart_id'] = postIntOrNull('vorgangsart_id');
    $input['objekt_id'] = postIntOrNull('objekt_id');
    $input['wohnung_id'] = postIntOrNull('wohnung_id');
    $input['titel'] = postStr('titel');
    $input['kurzbeschreibung'] = postStr('kurzbeschreibung');
    $input['langbeschreibung'] = postStr('langbeschreibung');
    $input['beschreibung'] = postStr('beschreibung');
    $input['notiz'] = postStr('notiz');
    $input['status'] = postStr('status', 'offen');
    $input['wichtigkeit'] = postStr('wichtigkeit');
    $input['startdatum'] = postStr('startdatum');
    $input['enddatum'] = postStr('enddatum');
    $input['uhrzeit'] = postStr('uhrzeit');
    $input['dauer'] = postStr('dauer');
    $input['zustaendig_id'] = postIntOrNull('zustaendig_id');
    $input['kategorie_id'] = postIntOrNull('kategorie_id');
    $input['subkategorie_id'] = postIntOrNull('subkategorie_id');
    $input['send_now'] = isset($_POST['send_now']) ? 1 : 0;
    $input['confirmation_required'] = isset($_POST['confirmation_required']) ? 1 : 0;
    $input['external_can_view'] = isset($_POST['external_can_view']) ? 1 : 0;
    $input['external_can_upload'] = isset($_POST['external_can_upload']) ? 1 : 0;
    $input['public_enabled'] = isset($_POST['public_enabled']) ? 1 : 0;

    if ($input['titel'] === '') {
        $error = 'Bitte Titel eingeben.';
    } elseif (!in_array($input['status'], ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'], true)) {
        $error = 'Ungültiger Status.';
    } else {
        $currentUserId = currentUserId();

        $wichtigkeit = null;
        if ($input['wichtigkeit'] !== '' && is_numeric($input['wichtigkeit'])) {
            $wichtigkeit = (int)$input['wichtigkeit'];
        }

        $startdatum = $input['startdatum'] !== '' ? $input['startdatum'] : null;
        $enddatum   = $input['enddatum'] !== '' ? $input['enddatum'] : null;
        $uhrzeit    = $input['uhrzeit'] !== '' ? $input['uhrzeit'] : null;
        $dauer      = $input['dauer'] !== '' ? $input['dauer'] : null;

        $extraJson = json_encode([
            '_form_source' => 'pages/pendenzen.php',
            '_assignee_type' => 'user',
            '_sicht_ui' => 'projekt_all',
            'confirmation_required' => $input['confirmation_required'],
            'public_enabled' => $input['public_enabled'],
            'external_can_view' => $input['external_can_view'],
            'external_can_upload' => $input['external_can_upload'],
            'kategorie_id' => $input['kategorie_id'],
            'subkategorie_id' => $input['subkategorie_id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sqlInsert = "
            INSERT INTO pendenzen (
                mandant_id,
                projekt_id,
                vorgangsart_id,
                objekt_id,
                wohnung_id,
                titel,
                kurzbeschreibung,
                langbeschreibung,
                notiz,
                beschreibung,
                status,
                wichtigkeit,
                startdatum,
                enddatum,
                uhrzeit,
                dauer,
                send_now,
                erstellt_von,
                sichtbarkeit,
                assignee_can_edit,
                zustaendig_id,
                confirmation_required,
                confirmation_by,
                external_can_view,
                external_can_upload,
                public_enabled,
                extra_json,
                zustaendig_typ
            ) VALUES (
                0,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?,
                ?,
                ?, ?, ?, ?,
                ?,
                ?,
                'projekt',
                1,
                ?,
                ?,
                'assignee',
                ?,
                ?,
                ?,
                ?,
                'user'
            )
        ";

        $stmtInsert = $mysqli->prepare($sqlInsert);

        if (!$stmtInsert) {
            $error = 'Prepare fehlgeschlagen: ' . $mysqli->error;
        } else {
            $stmtInsert->bind_param(
                'iiiissssssissssiiiiiiss',
                $input['projekt_id'],
                $input['vorgangsart_id'],
                $input['objekt_id'],
                $input['wohnung_id'],
                $input['titel'],
                $input['kurzbeschreibung'],
                $input['langbeschreibung'],
                $input['notiz'],
                $input['beschreibung'],
                $input['status'],
                $wichtigkeit,
                $startdatum,
                $enddatum,
                $uhrzeit,
                $dauer,
                $input['send_now'],
                $currentUserId,
                $input['zustaendig_id'],
                $input['confirmation_required'],
                $input['external_can_view'],
                $input['external_can_upload'],
                $input['public_enabled'],
                $extraJson
            );

            if ($stmtInsert->execute()) {
                $newId = (int)$stmtInsert->insert_id;
                $success = 'Pendenz erfolgreich gespeichert. ID: ' . $newId;

                $input = [
                    'projekt_id' => $input['projekt_id'],
                    'vorgangsart_id' => $input['vorgangsart_id'],
                    'objekt_id' => $input['objekt_id'],
                    'wohnung_id' => $input['wohnung_id'],
                    'titel' => '',
                    'kurzbeschreibung' => '',
                    'langbeschreibung' => '',
                    'beschreibung' => '',
                    'notiz' => '',
                    'status' => 'offen',
                    'wichtigkeit' => '',
                    'startdatum' => '',
                    'enddatum' => '',
                    'uhrzeit' => '',
                    'dauer' => '',
                    'zustaendig_id' => $input['zustaendig_id'],
                    'kategorie_id' => $input['kategorie_id'],
                    'subkategorie_id' => null,
                    'send_now' => 0,
                    'confirmation_required' => 0,
                    'external_can_view' => 1,
                    'external_can_upload' => 0,
                    'public_enabled' => 0,
                ];
            } else {
                $error = 'Speichern fehlgeschlagen: ' . $stmtInsert->error;
            }

            $stmtInsert->close();
        }
    }
}

$projekte = [];
$res = $mysqli->query("
    SELECT id, name
    FROM projekte
    WHERE deleted_at IS NULL
    ORDER BY name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $projekte[] = $row;
    }
    $res->close();
}

$objekte = [];
$res = $mysqli->query("
    SELECT id, projekt_id, name
    FROM objekte
    ORDER BY projekt_id ASC, name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $objekte[] = $row;
    }
    $res->close();
}

$wohnungen = [];
$res = $mysqli->query("
    SELECT id, objekt_id, name
    FROM wohnungen
    ORDER BY objekt_id ASC, name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $wohnungen[] = $row;
    }
    $res->close();
}

$benutzer = [];
$res = $mysqli->query("
    SELECT id, name
    FROM benutzer
    WHERE deleted_at IS NULL
    ORDER BY name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $benutzer[] = $row;
    }
    $res->close();
}

$kategorien = [];
if ($input['projekt_id']) {
    $stmt = $mysqli->prepare("
        SELECT id, name, projekt_id
        FROM pendenz_kategorien
        WHERE projekt_id IS NULL OR projekt_id = ?
        ORDER BY sort_order ASC, name ASC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $input['projekt_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $kategorien[] = $row;
        }
        $stmt->close();
    }
} else {
    $res = $mysqli->query("
        SELECT id, name, projekt_id
        FROM pendenz_kategorien
        WHERE projekt_id IS NULL
        ORDER BY sort_order ASC, name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $kategorien[] = $row;
        }
        $res->close();
    }
}

$subkategorien = [];
if ($input['projekt_id']) {
    $stmt = $mysqli->prepare("
        SELECT id, kategorie_id, name, projekt_id
        FROM pendenz_subkategorien
        WHERE projekt_id IS NULL OR projekt_id = ?
        ORDER BY sort_order ASC, name ASC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $input['projekt_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $subkategorien[] = $row;
        }
        $stmt->close();
    }
} else {
    $res = $mysqli->query("
        SELECT id, kategorie_id, name, projekt_id
        FROM pendenz_subkategorien
        WHERE projekt_id IS NULL
        ORDER BY sort_order ASC, name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $subkategorien[] = $row;
        }
        $res->close();
    }
}

$arten = [];
if ($input['projekt_id']) {
    $stmt = $mysqli->prepare("
        SELECT DISTINCT
            a.id,
            a.name,
            a.default_projekt_id,
            a.default_objekt_id,
            a.default_wohnung_id,
            a.default_benutzer_id
        FROM pendenzen_arten a
        LEFT JOIN pendenzen_arten_projekte ap
            ON ap.vorgangsart_id = a.id
        WHERE a.is_active = 1
          AND (
                ap.projekt_id = ?
                OR a.default_projekt_id = ?
                OR (ap.projekt_id IS NULL AND a.default_projekt_id IS NULL)
          )
        ORDER BY a.sort_order ASC, a.name ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ii', $input['projekt_id'], $input['projekt_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $arten[] = $row;
        }
        $stmt->close();
    }
} else {
    $res = $mysqli->query("
        SELECT
            id,
            name,
            default_projekt_id,
            default_objekt_id,
            default_wohnung_id,
            default_benutzer_id
        FROM pendenzen_arten
        WHERE is_active = 1
        ORDER BY sort_order ASC, name ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $arten[] = $row;
        }
        $res->close();
    }
}

$recent = [];
$res = $mysqli->query("
    SELECT
        p.id,
        p.titel,
        p.kurzbeschreibung,
        p.status,
        p.wichtigkeit,
        p.startdatum,
        p.enddatum,
        p.uhrzeit,
        p.dauer,
        pr.name AS projekt_name,
        b.name AS zustaendig_name,
        w.name AS wohnung_name
    FROM pendenzen p
    LEFT JOIN projekte pr ON pr.id = p.projekt_id
    LEFT JOIN benutzer b ON b.id = p.zustaendig_id
    LEFT JOIN wohnungen w ON w.id = p.wohnung_id
    WHERE p.deleted_at IS NULL
    ORDER BY p.id DESC
    LIMIT 50
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $recent[] = $row;
    }
    $res->close();
}
?>

<style>
    .pendenzen-page{padding:20px;}
    .pendenzen-wrap{max-width:1600px;margin:0 auto;}
    .pendenzen-dashboard{
        background:linear-gradient(135deg, #26c6a5 0%, #21b38f 100%);
        color:#ffffff;border-radius:14px;padding:22px 18px;margin-bottom:18px;
        box-shadow:0 8px 24px rgba(0,0,0,0.08);
    }
    .pendenzen-dashboard-top{
        display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:16px;
    }
    .pendenzen-dashboard-title{font-size:20px;font-weight:800;line-height:1.2;}
    .pendenzen-dashboard-sub{margin-top:6px;font-size:13px;opacity:0.9;}
    .pendenzen-dashboard-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:flex-end;}
    .pendenzen-chip-link{
        display:inline-flex;align-items:center;gap:6px;padding:8px 10px;
        background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.18);
        border-radius:10px;color:#fff;text-decoration:none;font-size:13px;font-weight:700;white-space:nowrap;
    }
    .pendenzen-chip-link:hover{background:rgba(255,255,255,0.18);}
    .pendenzen-dashboard-grid{
        display:grid;grid-template-columns:1.15fr 1.15fr 1.15fr 1.15fr auto;gap:12px;align-items:end;
    }
    .pendenzen-dashboard-field{display:flex;flex-direction:column;gap:6px;}
    .pendenzen-dashboard-field label{
        font-size:12px;font-weight:800;letter-spacing:0.02em;color:#ffffff;
    }
    .pendenzen-dashboard-field select,
    .pendenzen-dashboard-field button{
        width:100%;box-sizing:border-box;min-height:42px;border-radius:10px;border:0;padding:10px 12px;font-size:14px;
    }
    .pendenzen-dashboard-field select{
        background:#1680d5;color:#fff;outline:none;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.15);
    }
    .pendenzen-dashboard-field select option{color:#111827;background:#fff;}
    .pendenzen-quick-btn{background:#ffffff;color:#0f172a;font-weight:800;cursor:pointer;}
    .pendenzen-quick-btn:hover{background:#f8fafc;}

    .pendenzen-card{background:#ffffff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.06);overflow:hidden;}
    .pendenzen-head{padding:18px 20px;border-bottom:1px solid #e5e7eb;}
    .pendenzen-head h1{margin:0;font-size:24px;}
    .pendenzen-sub{margin-top:6px;color:#6b7280;font-size:13px;}
    .pendenzen-content{padding:20px;}
    .pendenzen-msg{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;}
    .pendenzen-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}
    .pendenzen-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .pendenzen-form{display:grid;gap:18px;}
    .pendenzen-grid{display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:14px;}
    .pendenzen-grid-2{display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:14px;}
    .pendenzen-field{display:flex;flex-direction:column;gap:6px;}
    .pendenzen-field label{font-size:13px;font-weight:700;color:#374151;}
    .pendenzen-field input[type="text"],
    .pendenzen-field input[type="date"],
    .pendenzen-field input[type="time"],
    .pendenzen-field select,
    .pendenzen-field textarea{
        width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;background:#fff;
    }
    .pendenzen-field textarea{min-height:110px;resize:vertical;}
    .pendenzen-checks{display:flex;flex-wrap:wrap;gap:18px;}
    .pendenzen-checks label{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:400;}
    .pendenzen-actions{display:flex;gap:10px;align-items:center;}
    .pendenzen-btn{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:11px 16px;font-size:14px;font-weight:700;cursor:pointer;}
    .pendenzen-btn:hover{background:#1d4ed8;}
    .pendenzen-list{margin-top:24px;}
    .pendenzen-table-tools{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:12px;margin-bottom:14px;}
    .pendenzen-table-tools input,
    .pendenzen-table-tools select{
        width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;font-size:14px;background:#fff;
    }
    .pendenzen-table-wrap{overflow:auto;border:1px solid #e5e7eb;border-radius:12px;}
    .pendenzen-table{width:100%;border-collapse:collapse;min-width:1200px;}
    .pendenzen-table th,
    .pendenzen-table td{
        text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top;
    }
    .pendenzen-table th{background:#f3f4f6;font-weight:700;white-space:nowrap;cursor:pointer;user-select:none;}
    .pendenzen-table tr:hover td{background:#f9fafb;}
    .pendenzen-pill{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef2ff;font-size:12px;font-weight:700;}
    .pendenzen-small{font-size:12px;color:#6b7280;margin-top:4px;}
    .pendenzen-table-empty{padding:14px;color:#6b7280;font-size:14px;}

    @media (max-width: 1400px){
        .pendenzen-dashboard-grid{grid-template-columns:1fr 1fr 1fr 1fr;}
    }
    @media (max-width: 1200px){
        .pendenzen-table-tools{grid-template-columns:1fr 1fr;}
        .pendenzen-dashboard-top{flex-direction:column;}
        .pendenzen-dashboard-actions{justify-content:flex-start;}
    }
    @media (max-width: 1100px){
        .pendenzen-grid,
        .pendenzen-grid-2,
        .pendenzen-dashboard-grid{grid-template-columns:repeat(2, minmax(0, 1fr));}
    }
    @media (max-width: 700px){
        .pendenzen-page{padding:14px;}
        .pendenzen-grid,
        .pendenzen-grid-2,
        .pendenzen-table-tools,
        .pendenzen-dashboard-grid{grid-template-columns:1fr;}
    }
</style>

<div class="pendenzen-page">
    <div class="pendenzen-wrap">

        <div class="pendenzen-dashboard">
            <div class="pendenzen-dashboard-top">
                <div>
                    <div class="pendenzen-dashboard-title">Pendenzen Dashboard</div>
                    <div class="pendenzen-dashboard-sub">Neue Reihenfolge: Vorgang, Objekt, Wohnung, Unternehmer</div>
                </div>

                <div class="pendenzen-dashboard-actions">
                    <a class="pendenzen-chip-link" href="#">⚡ Schnell-Erfassung</a>
                    <a class="pendenzen-chip-link" href="#">➕ Neue Pendenz</a>
                    <a class="pendenzen-chip-link" href="#">🔄 Drive-Sync</a>
                    <a class="pendenzen-chip-link" href="#">📝 Text-Vorlagen</a>
                    <a class="pendenzen-chip-link" href="#">📄 Exporte</a>
                </div>
            </div>

            <div class="pendenzen-dashboard-grid">
                <div class="pendenzen-dashboard-field">
                    <label for="quick_vorgangsart_id">⚙️ Vorgang</label>
                    <select id="quick_vorgangsart_id">
                        <option value="">— Vorgang wählen —</option>
                        <?php foreach ($arten as $row): ?>
                            <option
                                value="<?php echo (int)$row['id']; ?>"
                                data-default-projekt="<?php echo (int)($row['default_projekt_id'] ?? 0); ?>"
                                data-default-objekt="<?php echo (int)($row['default_objekt_id'] ?? 0); ?>"
                                data-default-wohnung="<?php echo (int)($row['default_wohnung_id'] ?? 0); ?>"
                                data-default-benutzer="<?php echo (int)($row['default_benutzer_id'] ?? 0); ?>"
                                <?php echo ((int)$input['vorgangsart_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                            >
                                <?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pendenzen-dashboard-field">
                    <label for="quick_objekt_id">🏢 Objekt</label>
                    <select id="quick_objekt_id">
                        <option value="">— Objekt wählen —</option>
                        <?php foreach ($objekte as $row): ?>
                            <option
                                value="<?php echo (int)$row['id']; ?>"
                                data-projekt-id="<?php echo (int)$row['projekt_id']; ?>"
                                <?php echo ((int)$input['objekt_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                            >
                                <?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pendenzen-dashboard-field">
                    <label for="quick_wohnung_id">🏠 Wohnung</label>
                    <select id="quick_wohnung_id">
                        <option value="">— Wohnung wählen —</option>
                        <?php foreach ($wohnungen as $row): ?>
                            <option
                                value="<?php echo (int)$row['id']; ?>"
                                data-objekt-id="<?php echo (int)$row['objekt_id']; ?>"
                                <?php echo ((int)$input['wohnung_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                            >
                                <?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pendenzen-dashboard-field">
                    <label for="quick_unternehmer_id">👷 Unternehmer</label>
                    <select id="quick_unternehmer_id">
                        <option value="">— Unternehmer wählen —</option>
                        <?php foreach ($benutzer as $row): ?>
                            <option value="<?php echo (int)$row['id']; ?>" <?php echo ((int)$input['zustaendig_id'] === (int)$row['id']) ? 'selected' : ''; ?>>
                                <?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pendenzen-dashboard-field">
                    <label>&nbsp;</label>
                    <button type="button" id="btnNextWohnung" class="pendenzen-quick-btn">Nächste Wohnung →</button>
                </div>
            </div>
        </div>

        <div class="pendenzen-card">
            <div class="pendenzen-head">
                <h1>Neue Pendenz</h1>
                <div class="pendenzen-sub">Logik neu aufgebaut: Vorgang → Objekt → Wohnung → Unternehmer</div>
            </div>

            <div class="pendenzen-content">
                <?php if ($success !== ''): ?>
                    <div class="pendenzen-msg ok"><?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="pendenzen-msg err"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" action="" class="pendenzen-form" id="pendenzenForm">
                    <div class="pendenzen-grid">
                        <div class="pendenzen-field">
                            <label for="vorgangsart_id">Vorgang</label>
                            <select name="vorgangsart_id" id="vorgangsart_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($arten as $row): ?>
                                    <option
                                        value="<?php echo (int)$row['id']; ?>"
                                        data-default-projekt="<?php echo (int)($row['default_projekt_id'] ?? 0); ?>"
                                        data-default-objekt="<?php echo (int)($row['default_objekt_id'] ?? 0); ?>"
                                        data-default-wohnung="<?php echo (int)($row['default_wohnung_id'] ?? 0); ?>"
                                        data-default-benutzer="<?php echo (int)($row['default_benutzer_id'] ?? 0); ?>"
                                        <?php echo ((int)$input['vorgangsart_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="objekt_id">Objekt</label>
                            <select name="objekt_id" id="objekt_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($objekte as $row): ?>
                                    <option
                                        value="<?php echo (int)$row['id']; ?>"
                                        data-projekt-id="<?php echo (int)$row['projekt_id']; ?>"
                                        <?php echo ((int)$input['objekt_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="wohnung_id">Wohnung / Einheit</label>
                            <select name="wohnung_id" id="wohnung_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($wohnungen as $row): ?>
                                    <option
                                        value="<?php echo (int)$row['id']; ?>"
                                        data-objekt-id="<?php echo (int)$row['objekt_id']; ?>"
                                        <?php echo ((int)$input['wohnung_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="zustaendig_id">Unternehmer / Zuständig</label>
                            <select name="zustaendig_id" id="zustaendig_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($benutzer as $row): ?>
                                    <option value="<?php echo (int)$row['id']; ?>" <?php echo ((int)$input['zustaendig_id'] === (int)$row['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="pendenzen-grid">
                        <div class="pendenzen-field">
                            <label for="projekt_id">Projekt</label>
                            <select name="projekt_id" id="projekt_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($projekte as $row): ?>
                                    <option value="<?php echo (int)$row['id']; ?>" <?php echo ((int)$input['projekt_id'] === (int)$row['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="titel">Titel</label>
                            <input type="text" name="titel" id="titel" value="<?php echo h($input['titel']); ?>" required>
                        </div>

                        <div class="pendenzen-field">
                            <label for="status">Status</label>
                            <select name="status" id="status">
                                <?php
                                $statusOptions = ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'];
                                foreach ($statusOptions as $status):
                                ?>
                                    <option value="<?php echo h($status); ?>" <?php echo ($input['status'] === $status) ? 'selected' : ''; ?>>
                                        <?php echo h($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="wichtigkeit">Wichtigkeit</label>
                            <select name="wichtigkeit" id="wichtigkeit">
                                <option value="">— auswählen —</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ((string)$input['wichtigkeit'] === (string)$i) ? 'selected' : ''; ?>>
                                        <?php echo $i; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="pendenzen-grid">
                        <div class="pendenzen-field">
                            <label for="kategorie_id">Kategorie</label>
                            <select name="kategorie_id" id="kategorie_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($kategorien as $row): ?>
                                    <option value="<?php echo (int)$row['id']; ?>" <?php echo ((int)$input['kategorie_id'] === (int)$row['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="subkategorie_id">Subkategorie</label>
                            <select name="subkategorie_id" id="subkategorie_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($subkategorien as $row): ?>
                                    <option
                                        value="<?php echo (int)$row['id']; ?>"
                                        data-kategorie-id="<?php echo (int)$row['kategorie_id']; ?>"
                                        <?php echo ((int)$input['subkategorie_id'] === (int)$row['id']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo h($row['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pendenzen-field">
                            <label for="startdatum">Startdatum</label>
                            <input type="date" name="startdatum" id="startdatum" value="<?php echo h($input['startdatum']); ?>">
                        </div>

                        <div class="pendenzen-field">
                            <label for="enddatum">Enddatum</label>
                            <input type="date" name="enddatum" id="enddatum" value="<?php echo h($input['enddatum']); ?>">
                        </div>
                    </div>

                    <div class="pendenzen-grid">
                        <div class="pendenzen-field">
                            <label for="uhrzeit">Uhrzeit</label>
                            <input type="time" name="uhrzeit" id="uhrzeit" value="<?php echo h($input['uhrzeit']); ?>">
                        </div>

                        <div class="pendenzen-field">
                            <label for="dauer">Dauer</label>
                            <input type="text" name="dauer" id="dauer" value="<?php echo h($input['dauer']); ?>" placeholder="z. B. 3 Tage">
                        </div>

                        <div class="pendenzen-field">
                            <label for="kurzbeschreibung">Kurzbeschreibung</label>
                            <input type="text" name="kurzbeschreibung" id="kurzbeschreibung" value="<?php echo h($input['kurzbeschreibung']); ?>">
                        </div>

                        <div class="pendenzen-field">
                            <label for="beschreibung">Beschreibung</label>
                            <input type="text" name="beschreibung" id="beschreibung" value="<?php echo h($input['beschreibung']); ?>">
                        </div>
                    </div>

                    <div class="pendenzen-grid-2">
                        <div class="pendenzen-field">
                            <label for="langbeschreibung">Langbeschreibung</label>
                            <textarea name="langbeschreibung" id="langbeschreibung"><?php echo h($input['langbeschreibung']); ?></textarea>
                        </div>

                        <div class="pendenzen-field">
                            <label for="notiz">Notiz</label>
                            <textarea name="notiz" id="notiz"><?php echo h($input['notiz']); ?></textarea>
                        </div>
                    </div>

                    <div class="pendenzen-checks">
                        <label><input type="checkbox" name="send_now" value="1" <?php echo $input['send_now'] ? 'checked' : ''; ?>> sofort senden</label>
                        <label><input type="checkbox" name="confirmation_required" value="1" <?php echo $input['confirmation_required'] ? 'checked' : ''; ?>> Bestätigung erforderlich</label>
                        <label><input type="checkbox" name="external_can_view" value="1" <?php echo $input['external_can_view'] ? 'checked' : ''; ?>> extern sichtbar</label>
                        <label><input type="checkbox" name="external_can_upload" value="1" <?php echo $input['external_can_upload'] ? 'checked' : ''; ?>> externer Upload erlaubt</label>
                        <label><input type="checkbox" name="public_enabled" value="1" <?php echo $input['public_enabled'] ? 'checked' : ''; ?>> öffentlich aktivieren</label>
                    </div>

                    <div class="pendenzen-actions">
                        <button type="submit" class="pendenzen-btn">Pendenz speichern</button>
                    </div>
                </form>

                <div class="pendenzen-list">
                    <div class="pendenzen-table-tools">
                        <input type="text" id="filterGlobal" placeholder="Suche in Tabelle...">
                        <select id="filterProjekt">
                            <option value="">Alle Projekte</option>
                        </select>
                        <select id="filterStatus">
                            <option value="">Alle Status</option>
                        </select>
                        <select id="filterZustaendig">
                            <option value="">Alle Zuständigen</option>
                        </select>
                        <select id="filterWichtigkeit">
                            <option value="">Alle Wichtigkeiten</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                        </select>
                    </div>

                    <div class="pendenzen-table-wrap">
                        <table id="pendenzenTable" class="pendenzen-table">
                            <thead>
                                <tr>
                                    <th data-sort="number">ID</th>
                                    <th data-sort="text">Titel</th>
                                    <th data-sort="text">Projekt</th>
                                    <th data-sort="text">Wohnung</th>
                                    <th data-sort="text">Status</th>
                                    <th data-sort="number">Wichtigkeit</th>
                                    <th data-sort="text">Zuständig</th>
                                    <th data-sort="text">Start</th>
                                    <th data-sort="text">Ende</th>
                                    <th data-sort="text">Zeit</th>
                                    <th data-sort="text">Dauer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recent): ?>
                                    <tr>
                                        <td colspan="11" class="pendenzen-table-empty">Keine Pendenzen gefunden.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent as $row): ?>
                                        <tr
                                            data-projekt="<?php echo h((string)$row['projekt_name']); ?>"
                                            data-status="<?php echo h((string)$row['status']); ?>"
                                            data-zustaendig="<?php echo h((string)$row['zustaendig_name']); ?>"
                                            data-wichtigkeit="<?php echo h((string)$row['wichtigkeit']); ?>"
                                        >
                                            <td><?php echo h($row['id']); ?></td>
                                            <td>
                                                <?php echo h($row['titel']); ?>
                                                <?php if (!empty($row['kurzbeschreibung'])): ?>
                                                    <div class="pendenzen-small"><?php echo h($row['kurzbeschreibung']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo h($row['projekt_name']); ?></td>
                                            <td><?php echo h($row['wohnung_name']); ?></td>
                                            <td><span class="pendenzen-pill"><?php echo h($row['status']); ?></span></td>
                                            <td><?php echo h($row['wichtigkeit']); ?></td>
                                            <td><?php echo h($row['zustaendig_name']); ?></td>
                                            <td><?php echo h($row['startdatum']); ?></td>
                                            <td><?php echo h($row['enddatum']); ?></td>
                                            <td><?php echo h($row['uhrzeit']); ?></td>
                                            <td><?php echo h($row['dauer']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const projekt = document.getElementById('projekt_id');
    const vorgangsart = document.getElementById('vorgangsart_id');
    const objekt = document.getElementById('objekt_id');
    const wohnung = document.getElementById('wohnung_id');
    const zustaendig = document.getElementById('zustaendig_id');
    const kategorie = document.getElementById('kategorie_id');
    const subkategorie = document.getElementById('subkategorie_id');

    const quickVorgangsart = document.getElementById('quick_vorgangsart_id');
    const quickWohnung = document.getElementById('quick_wohnung_id');
    const quickUnternehmer = document.getElementById('quick_unternehmer_id');
    const quickObjekt = document.getElementById('quick_objekt_id');
    const btnNextWohnung = document.getElementById('btnNextWohnung');

    function visibleOptions(selectEl) {
        return Array.from(selectEl.options).filter((opt, index) => index > 0 && !opt.hidden);
    }

    function filterOptions(selectEl, attrName, wantedValue) {
        const selectedOption = selectEl.options[selectEl.selectedIndex];

        Array.from(selectEl.options).forEach((opt, index) => {
            if (index === 0) {
                opt.hidden = false;
                return;
            }

            const optValue = opt.getAttribute(attrName);
            opt.hidden = !!(wantedValue && optValue && optValue !== wantedValue);
        });

        if (selectedOption && selectedOption.hidden) {
            selectEl.value = '';
        }
    }

    function syncObjectAndApartmentFilters() {
        filterOptions(objekt, 'data-projekt-id', projekt.value);
        filterOptions(quickObjekt, 'data-projekt-id', projekt.value);

        const activeObjektValue = objekt.value || quickObjekt.value || '';
        filterOptions(wohnung, 'data-objekt-id', activeObjektValue);
        filterOptions(quickWohnung, 'data-objekt-id', activeObjektValue);

        if (objekt.value !== quickObjekt.value && (objekt.value || quickObjekt.value)) {
            const val = objekt.value || quickObjekt.value;
            objekt.value = val;
            quickObjekt.value = val;
        }

        if (wohnung.value !== quickWohnung.value && (wohnung.value || quickWohnung.value)) {
            const val = wohnung.value || quickWohnung.value;
            wohnung.value = val;
            quickWohnung.value = val;
        }
    }

    function applyKategorieFilter() {
        filterOptions(subkategorie, 'data-kategorie-id', kategorie.value);
    }

    function applyVorgangsartDefaults(sourceSelect) {
        const opt = sourceSelect.options[sourceSelect.selectedIndex];
        if (!opt || !opt.value) {
            return;
        }

        const defaultProjekt = opt.getAttribute('data-default-projekt');
        const defaultObjekt = opt.getAttribute('data-default-objekt');
        const defaultWohnung = opt.getAttribute('data-default-wohnung');
        const defaultBenutzer = opt.getAttribute('data-default-benutzer');

        if (!projekt.value && defaultProjekt && defaultProjekt !== '0') {
            projekt.value = defaultProjekt;
        }

        if (defaultObjekt && defaultObjekt !== '0') {
            objekt.value = defaultObjekt;
            quickObjekt.value = defaultObjekt;
        }

        syncObjectAndApartmentFilters();

        if (defaultWohnung && defaultWohnung !== '0') {
            wohnung.value = defaultWohnung;
            quickWohnung.value = defaultWohnung;
        }

        if (defaultBenutzer && defaultBenutzer !== '0') {
            zustaendig.value = defaultBenutzer;
            quickUnternehmer.value = defaultBenutzer;
        }
    }

    function syncQuickFromForm() {
        quickVorgangsart.value = vorgangsart.value;
        quickObjekt.value = objekt.value;
        quickWohnung.value = wohnung.value;
        quickUnternehmer.value = zustaendig.value;
    }

    projekt.addEventListener('change', function () {
        syncObjectAndApartmentFilters();
    });

    objekt.addEventListener('change', function () {
        quickObjekt.value = objekt.value;
        syncObjectAndApartmentFilters();
    });

    quickObjekt.addEventListener('change', function () {
        objekt.value = quickObjekt.value;
        syncObjectAndApartmentFilters();
    });

    wohnung.addEventListener('change', function () {
        quickWohnung.value = wohnung.value;
    });

    quickWohnung.addEventListener('change', function () {
        wohnung.value = quickWohnung.value;
    });

    zustaendig.addEventListener('change', function () {
        quickUnternehmer.value = zustaendig.value;
    });

    quickUnternehmer.addEventListener('change', function () {
        zustaendig.value = quickUnternehmer.value;
    });

    vorgangsart.addEventListener('change', function () {
        quickVorgangsart.value = vorgangsart.value;
        applyVorgangsartDefaults(vorgangsart);
    });

    quickVorgangsart.addEventListener('change', function () {
        vorgangsart.value = quickVorgangsart.value;
        applyVorgangsartDefaults(quickVorgangsart);
    });

    kategorie.addEventListener('change', applyKategorieFilter);

    btnNextWohnung.addEventListener('click', function () {
        const options = visibleOptions(quickWohnung);
        if (!options.length) {
            return;
        }

        let currentIndex = options.findIndex(opt => opt.value === quickWohnung.value);
        currentIndex = currentIndex < 0 ? 0 : currentIndex + 1;

        if (currentIndex >= options.length) {
            currentIndex = 0;
        }

        quickWohnung.value = options[currentIndex].value;
        wohnung.value = options[currentIndex].value;
    });

    syncObjectAndApartmentFilters();
    applyKategorieFilter();
    syncQuickFromForm();
})();

(function () {
    const table = document.getElementById('pendenzenTable');
    if (!table) {
        return;
    }

    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => !row.querySelector('.pendenzen-table-empty'));
    const filterGlobal = document.getElementById('filterGlobal');
    const filterProjekt = document.getElementById('filterProjekt');
    const filterStatus = document.getElementById('filterStatus');
    const filterZustaendig = document.getElementById('filterZustaendig');
    const filterWichtigkeit = document.getElementById('filterWichtigkeit');

    function uniqueValues(attr) {
        const values = new Set();
        rows.forEach((row) => {
            const value = (row.getAttribute(attr) || '').trim();
            if (value !== '') {
                values.add(value);
            }
        });
        return Array.from(values).sort((a, b) => a.localeCompare(b, 'de'));
    }

    function fillSelect(select, values) {
        values.forEach((value) => {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value;
            select.appendChild(opt);
        });
    }

    fillSelect(filterProjekt, uniqueValues('data-projekt'));
    fillSelect(filterStatus, uniqueValues('data-status'));
    fillSelect(filterZustaendig, uniqueValues('data-zustaendig'));

    function applyTableFilter() {
        const q = (filterGlobal.value || '').toLowerCase().trim();
        const projektVal = filterProjekt.value.trim();
        const statusVal = filterStatus.value.trim();
        const zustaendigVal = filterZustaendig.value.trim();
        const wichtigkeitVal = filterWichtigkeit.value.trim();

        let visibleCount = 0;

        rows.forEach((row) => {
            const text = row.innerText.toLowerCase();
            const projekt = (row.getAttribute('data-projekt') || '').trim();
            const status = (row.getAttribute('data-status') || '').trim();
            const zustaendig = (row.getAttribute('data-zustaendig') || '').trim();
            const wichtigkeit = (row.getAttribute('data-wichtigkeit') || '').trim();

            const matchGlobal = !q || text.includes(q);
            const matchProjekt = !projektVal || projekt === projektVal;
            const matchStatus = !statusVal || status === statusVal;
            const matchZustaendig = !zustaendigVal || zustaendig === zustaendigVal;
            const matchWichtigkeit = !wichtigkeitVal || wichtigkeit === wichtigkeitVal;

            const visible = matchGlobal && matchProjekt && matchStatus && matchZustaendig && matchWichtigkeit;
            row.style.display = visible ? '' : 'none';

            if (visible) {
                visibleCount++;
            }
        });

        let emptyRow = tbody.querySelector('.js-no-results');
        if (!emptyRow && visibleCount === 0) {
            emptyRow = document.createElement('tr');
            emptyRow.className = 'js-no-results';
            emptyRow.innerHTML = '<td colspan="11" class="pendenzen-table-empty">Keine Treffer gefunden.</td>';
            tbody.appendChild(emptyRow);
        } else if (emptyRow && visibleCount > 0) {
            emptyRow.remove();
        }
    }

    filterGlobal.addEventListener('input', applyTableFilter);
    filterProjekt.addEventListener('change', applyTableFilter);
    filterStatus.addEventListener('change', applyTableFilter);
    filterZustaendig.addEventListener('change', applyTableFilter);
    filterWichtigkeit.addEventListener('change', applyTableFilter);

    const headers = Array.from(table.querySelectorAll('thead th'));
    let currentSortIndex = -1;
    let currentSortDir = 'asc';

    headers.forEach((header, index) => {
        header.addEventListener('click', function () {
            const type = header.getAttribute('data-sort') || 'text';

            if (currentSortIndex === index) {
                currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortIndex = index;
                currentSortDir = 'asc';
            }

            const sortableRows = rows.slice();

            sortableRows.sort((a, b) => {
                const aText = (a.children[index]?.innerText || '').trim();
                const bText = (b.children[index]?.innerText || '').trim();

                let result = 0;

                if (type === 'number') {
                    const aNum = parseFloat(aText.replace(',', '.')) || 0;
                    const bNum = parseFloat(bText.replace(',', '.')) || 0;
                    result = aNum - bNum;
                } else {
                    result = aText.localeCompare(bText, 'de', { numeric: true, sensitivity: 'base' });
                }

                return currentSortDir === 'asc' ? result : -result;
            });

            sortableRows.forEach((row) => tbody.appendChild(row));

            const noResultRow = tbody.querySelector('.js-no-results');
            if (noResultRow) {
                tbody.appendChild(noResultRow);
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>