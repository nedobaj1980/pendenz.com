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
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pendenzTableExists')) {
    function pendenzTableExists(mysqli $mysqli, string $table): bool
    {
        $table = $mysqli->real_escape_string($table);
        $res = $mysqli->query("SHOW TABLES LIKE '{$table}'");
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->close();
        return $exists;
    }
}

if (!function_exists('pendenzColumnExists')) {
    function pendenzColumnExists(mysqli $mysqli, string $table, string $column): bool
    {
        $table = $mysqli->real_escape_string($table);
        $column = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if (!$res) {
            return false;
        }
        $exists = $res->num_rows > 0;
        $res->close();
        return $exists;
    }
}

function postStr(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function postIntOrNull(string $key): ?int
{
    if (!isset($_POST[$key])) {
        return null;
    }

    $value = trim((string) $_POST[$key]);
    if ($value === '') {
        return null;
    }

    return ctype_digit($value) ? (int) $value : null;
}

function currentUserId(): ?int
{
    $keys = ['user_id', 'benutzer_id', 'admin_id', 'id'];

    foreach ($keys as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            return (int) $_SESSION[$key];
        }
    }

    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach ($keys as $key) {
            if (isset($_SESSION['user'][$key]) && is_numeric($_SESSION['user'][$key])) {
                return (int) $_SESSION['user'][$key];
            }
        }
    }

    return null;
}

$prefillProjektId = isset($_GET['projekt_id']) && ctype_digit((string) $_GET['projekt_id']) ? (int) $_GET['projekt_id'] : null;
$prefillObjektId = isset($_GET['objekt_id']) && ctype_digit((string) $_GET['objekt_id']) ? (int) $_GET['objekt_id'] : null;
$prefillWohnungId = isset($_GET['wohnung_id']) && ctype_digit((string) $_GET['wohnung_id']) ? (int) $_GET['wohnung_id'] : null;
$prefillRaumId = isset($_GET['raum_id']) && ctype_digit((string) $_GET['raum_id']) ? (int) $_GET['raum_id'] : null;
$prefillKategorieId = isset($_GET['kategorie_id']) && ctype_digit((string) $_GET['kategorie_id']) ? (int) $_GET['kategorie_id'] : null;
$prefillUnternehmerId = isset($_GET['unternehmer_id']) && ctype_digit((string) $_GET['unternehmer_id']) ? (int) $_GET['unternehmer_id'] : null;
$prefillVorgangsartId = isset($_GET['vorgangsart_id']) && ctype_digit((string) $_GET['vorgangsart_id']) ? (int) $_GET['vorgangsart_id'] : null;

$input = [
    'projekt_id' => $prefillProjektId,
    'vorgangsart_id' => $prefillVorgangsartId,
    'objekt_id' => $prefillObjektId,
    'wohnung_id' => $prefillWohnungId,
    'raum_id' => $prefillRaumId,
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
    $input['raum_id'] = postIntOrNull('raum_id');
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

    $selectedWorld = 'bkp';
    if ($input['vorgangsart_id'] && pendenzTableExists($mysqli, 'pendenzen_arten')) {
        $worldSql = pendenzColumnExists($mysqli, 'pendenzen_arten', 'vorlagen_welt')
            ? "SELECT COALESCE(vorlagen_welt, 'bkp') AS vorlagen_welt FROM pendenzen_arten WHERE id = ? LIMIT 1"
            : "SELECT 'bkp' AS vorlagen_welt FROM pendenzen_arten WHERE id = ? LIMIT 1";
        $worldStmt = $mysqli->prepare($worldSql);
        if ($worldStmt) {
            $worldStmt->bind_param('i', $input['vorgangsart_id']);
            $worldStmt->execute();
            $worldRes = $worldStmt->get_result();
            if ($worldRow = $worldRes->fetch_assoc()) {
                $selectedWorld = (string)($worldRow['vorlagen_welt'] ?? 'bkp');
            }
            $worldStmt->close();
        }
    }

    $selectedKategorieName = '';
    $selectedSubName = '';
    $selectedSubKurz = '';

    if ($selectedWorld === 'bkp') {
        if ($input['kategorie_id'] && pendenzTableExists($mysqli, 'bkp_kategorien')) {
            $stmtMeta = $mysqli->prepare("SELECT name FROM bkp_kategorien WHERE id = ? LIMIT 1");
            if ($stmtMeta) {
                $stmtMeta->bind_param('i', $input['kategorie_id']);
                $stmtMeta->execute();
                $resMeta = $stmtMeta->get_result();
                if ($rowMeta = $resMeta->fetch_assoc()) {
                    $selectedKategorieName = trim((string)($rowMeta['name'] ?? ''));
                }
                $stmtMeta->close();
            }
        }
        if ($input['subkategorie_id'] && pendenzTableExists($mysqli, 'bkp_vorlagen_texte')) {
            $stmtMeta = $mysqli->prepare("SELECT text AS name, text AS beschreibung FROM bkp_vorlagen_texte WHERE id = ? LIMIT 1");
            if ($stmtMeta) {
                $stmtMeta->bind_param('i', $input['subkategorie_id']);
                $stmtMeta->execute();
                $resMeta = $stmtMeta->get_result();
                if ($rowMeta = $resMeta->fetch_assoc()) {
                    $selectedSubName = trim((string)($rowMeta['name'] ?? ''));
                    $selectedSubKurz = trim((string)($rowMeta['beschreibung'] ?? ''));
                }
                $stmtMeta->close();
            }
        }
    } else {
        $catTable = 'pendenz_kategorien_' . $selectedWorld;
        $subTable = 'pendenz_subkategorien_' . $selectedWorld;
        if ($input['kategorie_id'] && pendenzTableExists($mysqli, $catTable)) {
            $stmtMeta = $mysqli->prepare("SELECT name FROM `{$catTable}` WHERE id = ? LIMIT 1");
            if ($stmtMeta) {
                $stmtMeta->bind_param('i', $input['kategorie_id']);
                $stmtMeta->execute();
                $resMeta = $stmtMeta->get_result();
                if ($rowMeta = $resMeta->fetch_assoc()) {
                    $selectedKategorieName = trim((string)($rowMeta['name'] ?? ''));
                }
                $stmtMeta->close();
            }
        }
        if ($input['subkategorie_id'] && pendenzTableExists($mysqli, $subTable)) {
            $descField = pendenzColumnExists($mysqli, $subTable, 'beschreibung') ? 'COALESCE(beschreibung, name)' : 'name';
            $stmtMeta = $mysqli->prepare("SELECT name, {$descField} AS beschreibung FROM `{$subTable}` WHERE id = ? LIMIT 1");
            if ($stmtMeta) {
                $stmtMeta->bind_param('i', $input['subkategorie_id']);
                $stmtMeta->execute();
                $resMeta = $stmtMeta->get_result();
                if ($rowMeta = $resMeta->fetch_assoc()) {
                    $selectedSubName = trim((string)($rowMeta['name'] ?? ''));
                    $selectedSubKurz = trim((string)($rowMeta['beschreibung'] ?? ''));
                }
                $stmtMeta->close();
            }
        }
    }

    $autoTitel = trim(implode(', ', array_values(array_filter([$selectedKategorieName, $selectedSubName]))));
    $autoKurz = $selectedSubKurz !== '' ? $selectedSubKurz : $selectedSubName;

    if ($input['titel'] === '' && $autoTitel !== '') {
        $input['titel'] = $autoTitel;
    } elseif ($input['titel'] !== '' && $autoTitel !== '' && stripos($input['titel'], $autoTitel) === false) {
        $input['titel'] .= ', ' . $autoTitel;
    }

    if ($input['kurzbeschreibung'] === '' && $autoKurz !== '') {
        $input['kurzbeschreibung'] = $autoKurz;
    } elseif ($input['kurzbeschreibung'] !== '' && $autoKurz !== '' && stripos($input['kurzbeschreibung'], $autoKurz) === false) {
        $input['kurzbeschreibung'] .= ', ' . $autoKurz;
    }

    if ($input['titel'] === '') {
        $error = 'Bitte Titel eingeben oder eine Kategorie wählen.';
    } elseif (!in_array($input['status'], ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'], true)) {
        $error = 'Ungültiger Status.';
    } else {
        $currentUserId = currentUserId();

        $wichtigkeit = null;
        if ($input['wichtigkeit'] !== '' && is_numeric($input['wichtigkeit'])) {
            $wichtigkeit = (int) $input['wichtigkeit'];
        }

        $startdatum = $input['startdatum'] !== '' ? $input['startdatum'] : null;
        $enddatum = $input['enddatum'] !== '' ? $input['enddatum'] : null;
        $uhrzeit = $input['uhrzeit'] !== '' ? $input['uhrzeit'] : null;
        $dauer = $input['dauer'] !== '' ? $input['dauer'] : null;

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
            'raum_id' => $input['raum_id'],
            'vorlagen_welt' => $selectedWorld,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sqlInsert = "
            INSERT INTO pendenzen (
                mandant_id,
                projekt_id,
                vorgangsart_id,
                objekt_id,
                wohnung_id,
                raum_id,
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
                ?, ?, ?, ?, ?,
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
                'iiiiissssssissssiiiiiiss',
                $input['projekt_id'],
                $input['vorgangsart_id'],
                $input['objekt_id'],
                $input['wohnung_id'],
                $input['raum_id'],
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
                $newId = (int) $stmtInsert->insert_id;
                $success = 'Pendenz erfolgreich gespeichert. ID: ' . $newId;

                $input = [
                    'projekt_id' => $input['projekt_id'],
                    'vorgangsart_id' => $input['vorgangsart_id'],
                    'objekt_id' => $input['objekt_id'],
                    'wohnung_id' => $input['wohnung_id'],
                    'raum_id' => $input['raum_id'],
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

$raeume = [];
if (pendenzTableExists($mysqli, 'raeume')) {
    $res = $mysqli->query("
        SELECT id, wohnung_id, name
        FROM raeume
        ORDER BY wohnung_id ASC, sort_order ASC, name ASC
    " );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $raeume[] = $row;
        }
        $res->close();
    }
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

$arten = [];
$artenSelectFields = "id, name, default_projekt_id, default_objekt_id, default_wohnung_id, default_benutzer_id";
if (pendenzColumnExists($mysqli, 'pendenzen_arten', 'vorlagen_welt')) {
    $artenSelectFields .= ", COALESCE(vorlagen_welt, 'bkp') AS vorlagen_welt";
} else {
    $artenSelectFields .= ", 'bkp' AS vorlagen_welt";
}
if (pendenzColumnExists($mysqli, 'pendenzen_arten', 'empfaenger_typ')) {
    $artenSelectFields .= ", empfaenger_typ";
} else {
    $artenSelectFields .= ", NULL AS empfaenger_typ";
}

if ($input['projekt_id']) {
    $stmt = $mysqli->prepare("
        SELECT DISTINCT
            {$artenSelectFields}
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
            {$artenSelectFields}
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

$selectedVorlagenWelt = 'bkp';
foreach ($arten as $artRow) {
    if ((int)($artRow['id'] ?? 0) === (int)($input['vorgangsart_id'] ?? 0)) {
        $selectedVorlagenWelt = (string)($artRow['vorlagen_welt'] ?? 'bkp');
        break;
    }
}

$vorlagenKategorien = ['bkp' => [], 'mieter' => [], 'vermieter' => []];
$vorlagenSubkategorien = ['bkp' => [], 'mieter' => [], 'vermieter' => []];

if (pendenzTableExists($mysqli, 'bkp_kategorien')) {
    $res = $mysqli->query("SELECT id, name, NULL AS projekt_id, NULL AS objekt_id FROM bkp_kategorien ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $vorlagenKategorien['bkp'][] = $row;
        }
        $res->close();
    }
}
if (pendenzTableExists($mysqli, 'bkp_vorlagen_texte')) {
    $res = $mysqli->query("SELECT id, kategorie_id, text AS name, text AS beschreibung, NULL AS projekt_id, NULL AS objekt_id FROM bkp_vorlagen_texte ORDER BY text ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $vorlagenSubkategorien['bkp'][] = $row;
        }
        $res->close();
    }
}

foreach (['mieter','vermieter'] as $welt) {
    $catTable = 'pendenz_kategorien_' . $welt;
    $subTable = 'pendenz_subkategorien_' . $welt;
    if (pendenzTableExists($mysqli, $catTable)) {
        $catSort = pendenzColumnExists($mysqli, $catTable, 'sortierung') ? 'sortierung ASC, name ASC' : 'name ASC';
        $res = $mysqli->query("SELECT id, name, " . (pendenzColumnExists($mysqli, $catTable, 'projekt_id') ? 'projekt_id' : 'NULL AS projekt_id') . ", " . (pendenzColumnExists($mysqli, $catTable, 'objekt_id') ? 'objekt_id' : 'NULL AS objekt_id') . " FROM `{$catTable}` ORDER BY {$catSort}");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $vorlagenKategorien[$welt][] = $row;
            }
            $res->close();
        }
    }
    if (pendenzTableExists($mysqli, $subTable)) {
        $subSort = pendenzColumnExists($mysqli, $subTable, 'sortierung') ? 'sortierung ASC, name ASC' : 'name ASC';
        $res = $mysqli->query("SELECT id, kategorie_id, name, " . (pendenzColumnExists($mysqli, $subTable, 'beschreibung') ? 'beschreibung' : 'name AS beschreibung') . ", " . (pendenzColumnExists($mysqli, $subTable, 'projekt_id') ? 'projekt_id' : 'NULL AS projekt_id') . ", " . (pendenzColumnExists($mysqli, $subTable, 'objekt_id') ? 'objekt_id' : 'NULL AS objekt_id') . " FROM `{$subTable}` ORDER BY {$subSort}");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $vorlagenSubkategorien[$welt][] = $row;
            }
            $res->close();
        }
    }
}

$kategorien = $vorlagenKategorien[$selectedVorlagenWelt] ?? [];
$subkategorien = $vorlagenSubkategorien[$selectedVorlagenWelt] ?? [];

$profiles = [];
$res = $mysqli->query("
    SELECT l.id, l.name, GROUP_CONCAT(ls.col_name ORDER BY ls.sort_order ASC) as cols
    FROM listen l
    LEFT JOIN listen_spalten ls ON l.id = ls.listen_id
    WHERE l.table_name = 'pendenzen'
    GROUP BY l.id
    ORDER BY l.name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $profiles[] = $row;
    }
    $res->close();
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
        p.tageszeit,
        p.vorgaenger_id,
        p.erstellt_am,
        p.geaendert_am,
        p.deleted_at,
        p.erstellt_von,
        p.sichtbarkeit,
        pr.name AS projekt_name,
        b.name AS zustaendig_name,
        w.name AS wohnung_name,
        pa.name AS vorgangsart_name,
        (SELECT pfad FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image' ORDER BY is_cover DESC, id ASC LIMIT 1) as first_image,
        (SELECT COUNT(*) FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image') as count_images,
        (SELECT COUNT(*) FROM pendenz_dateien WHERE pendenz_id = p.id AND typ <> 'image') as count_files
    FROM pendenzen p
    LEFT JOIN projekte pr ON pr.id = p.projekt_id
    LEFT JOIN benutzer b ON b.id = p.zustaendig_id
    LEFT JOIN wohnungen w ON w.id = p.wohnung_id
    LEFT JOIN pendenzen_arten pa ON pa.id = p.vorgangsart_id
    WHERE p.deleted_at IS NULL
    ORDER BY p.id DESC
    LIMIT 100
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $recent[] = $row;
    }
    $res->close();
}

$vorlagenWorldLabels = [
    'bkp' => 'BKP',
    'mieter' => 'Mieterkategorie',
    'vermieter' => 'Vermieterkategorie',
];
$selectedWorldLabel = $vorlagenWorldLabels[$selectedVorlagenWelt] ?? 'Kategorie';
?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
        --accent-color: #0d9488;
        --bg-color: #f8fafc;
        --card-bg: #ffffff;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        --input-focus: #14b8a6;
        --radius: 12px;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
        --shadow-md: 0 4px 12px -2px rgba(0, 0, 0, 0.08);
        --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
    }

    .pendenzen-page {
        padding: 24px 2%;
        background-color: var(--bg-color);
        min-height: calc(100vh - 60px);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    .pendenzen-wrap {
        max-width: 96%;
        margin: 0 auto;
    }

    /* Dashboard & Glassmorphism */
    .pendenzen-dashboard {
        background: var(--primary-gradient);
        color: #ffffff;
        border-radius: var(--radius);
        padding: 32px 28px;
        margin-bottom: 24px;
        box-shadow: var(--shadow-lg);
        position: relative;
        overflow: hidden;
    }

    .pendenzen-dashboard::before {
        content: "";
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
        pointer-events: none;
    }

    .pendenzen-dashboard-top {
        display: flex;
        justify-content: space-between;
        gap: 24px;
        align-items: flex-start;
        margin-bottom: 24px;
        position: relative;
        z-index: 1;
    }

    .pendenzen-dashboard-title {
        font-size: 24px;
        font-weight: 800;
        letter-spacing: -0.02em;
        margin: 0;
    }

    .pendenzen-dashboard-sub {
        margin-top: 4px;
        font-size: 14px;
        opacity: 0.85;
    }

    .pendenzen-dashboard-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .pendenzen-chip-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 10px;
        color: #fff;
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s ease;
    }

    .pendenzen-chip-link:hover {
        background: rgba(255, 255, 255, 0.25);
        transform: translateY(-1px);
    }

    .pendenzen-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(100px, 1fr));
        gap: 12px;
        align-items: end;
        position: relative;
        z-index: 1;
    }

    .pendenzen-dashboard-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .pendenzen-dashboard-field label {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        opacity: 0.9;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pendenzen-dashboard-field select,
    .pendenzen-dashboard-field button {
        width: 100%;
        min-height: 40px;
        border-radius: 8px;
        border: 0;
        padding: 0 10px;
        font-size: 13px;
        transition: all 0.2s;
    }

    .pendenzen-dashboard-field select {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(4px);
        outline: none;
    }

    .pendenzen-dashboard-field select option {
        color: var(--text-main);
        background: #fff;
    }

    .pendenzen-quick-btn {
        background: #fff;
        color: #0f766e;
        font-weight: 700;
        cursor: pointer;
        box-shadow: var(--shadow-sm);
    }

    .pendenzen-quick-btn:hover {
        background: #f1f5f9;
        transform: translateY(-1px);
    }

    /* Main Card Styling */
    .pendenzen-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow-md);
        border: 1px solid var(--border-color);
        overflow: hidden;
    }

    .pendenzen-head {
        padding: 24px 28px;
        background: #fff;
        border-bottom: 1px solid var(--border-color);
    }

    .pendenzen-head h1 {
        margin: 0;
        font-size: 22px;
        font-weight: 800;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .pendenzen-sub {
        margin-top: 4px;
        color: var(--text-muted);
        font-size: 14px;
    }

    .pendenzen-content {
        padding: 28px;
    }

    .pendenzen-msg {
        padding: 16px;
        border-radius: 10px;
        margin-bottom: 24px;
        font-size: 15px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .pendenzen-msg.ok {
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .pendenzen-msg.err {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    /* Form Styles */
    .pendenzen-form {
        display: flex;
        flex-direction: column;
        gap: 28px;
    }

    .pendenzen-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
    }

    .pendenzen-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .pendenzen-field {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .pendenzen-field label {
        font-size: 13px;
        font-weight: 700;
        color: var(--text-main);
        margin-left: 2px;
    }

    .pendenzen-field input,
    .pendenzen-field select,
    .pendenzen-field textarea {
        width: 100%;
        box-sizing: border-box;
        padding: 12px 14px;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 15px;
        background: #fafafa;
        transition: all 0.2s ease;
        color: var(--text-main);
    }

    .pendenzen-field input:focus,
    .pendenzen-field select:focus,
    .pendenzen-field textarea:focus {
        border-color: var(--input-focus);
        background: #fff;
        outline: none;
        box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.1);
    }

    .pendenzen-field textarea {
        min-height: 120px;
        resize: vertical;
    }

    /* Checkboxes */
    .pendenzen-checks {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        background: #f8fafc;
        padding: 16px;
        border-radius: var(--radius);
        border: 1px solid var(--border-color);
    }

    .pendenzen-checks label {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 14px;
        font-weight: 600;
        color: var(--text-main);
        cursor: pointer;
        user-select: none;
    }

    .pendenzen-checks input[type="checkbox"] {
        width: 18px;
        height: 18px;
        accent-color: var(--accent-color);
        cursor: pointer;
    }

    .pendenzen-actions {
        display: flex;
        gap: 16px;
        padding-top: 12px;
        border-top: 1px solid var(--border-color);
    }

    .pendenzen-btn {
        background: var(--primary-gradient);
        color: #fff;
        border: 0;
        border-radius: var(--radius);
        padding: 14px 28px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: var(--shadow-sm);
    }

    .pendenzen-btn:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
        filter: brightness(1.1);
    }

    /* List & Table Styling */
    .pendenzen-list {
        margin-top: 40px;
    }

    .pendenzen-table-tools {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 20px;
    }

    .pendenzen-table-tools>* {
        flex: 1;
        min-width: 150px;
    }

    .pendenzen-table-tools input,
    .pendenzen-table-tools select {
        padding: 10px 14px;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        font-size: 14px;
        background: #fff;
    }

    .pendenzen-table-wrap {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        overflow-x: auto;
        box-shadow: var(--shadow-sm);
    }

    .pendenzen-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1000px;
    }

    .pendenzen-table th {
        background: #f1f5f9;
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.05em;
        padding: 14px 16px;
        text-align: left;
        border-bottom: 2px solid var(--border-color);
        cursor: pointer;
        user-select: none;
        transition: background 0.2s;
    }

    .pendenzen-table th:hover {
        background: #e2e8f0;
        color: var(--text-main);
    }

    .pendenzen-table td {
        padding: 16px;
        border-bottom: 1px solid var(--border-color);
        font-size: 14px;
        color: var(--text-main);
        vertical-align: middle;
    }

    .pendenzen-table tr:last-child td {
        border-bottom: 0;
    }

    .pendenzen-table tr:hover td {
        background: #f8fafc;
    }

    .pendenzen-pill {
        display: inline-flex;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 800;
        background: #e2e8f0;
        color: #475569;
        white-space: nowrap;
    }

    .pendenzen-pill.offen {
        background: #dcfce7;
        color: #166534;
    }

    .pendenzen-pill.bearbeitung {
        background: #fef9c3;
        color: #854d0e;
    }

    .pendenzen-pill.erledigt {
        background: #dbeafe;
        color: #1e40af;
    }

    .pendenzen-pill.archiviert {
        background: #f1f5f9;
        color: #475569;
    }

    .pendenzen-small {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 4px;
        line-height: 1.4;
    }

    /* Mobile Optimization */
    @media (max-width: 1024px) {
        .pendenzen-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 900px) {
        .pendenzen-page {
            padding: 12px;
        }

        .pendenzen-dashboard {
            padding: 20px 16px;
        }

        .pendenzen-dashboard-top {
            flex-direction: column;
            align-items: stretch;
        }

        .pendenzen-dashboard-actions {
            justify-content: flex-start;
        }

        .pendenzen-chip-link {
            flex: 1;
            justify-content: center;
            min-width: 140px;
        }

        .pendenzen-dashboard-grid {
            grid-template-columns: 1fr;
        }

        .pendenzen-grid,
        .pendenzen-grid-2 {
            grid-template-columns: 1fr;
        }

        .pendenzen-content {
            padding: 20px 16px;
        }

        .pendenzen-head {
            padding: 20px 16px;
        }

        .pendenzen-table-tools>* {
            flex: none;
            width: 100%;
        }

        .pendenzen-btn {
            width: 100%;
            padding: 16px;
        }

        /* Form accessibility on mobile */
        .pendenzen-field select,
        .pendenzen-field input,
        .pendenzen-field textarea {
            font-size: 16px;
            /* Prevents iOS auto-zoom */
            padding: 14px;
        }

        /* Mobile Table Transformation - COMPACT */
        .pendenzen-table-wrap {
            border: 0;
            background: transparent;
            box-shadow: none;
            overflow: visible;
        }

        .pendenzen-table,
        .pendenzen-table thead,
        .pendenzen-table tbody,
        .pendenzen-table th,
        .pendenzen-table td,
        .pendenzen-table tr {
            display: block;
            width: 100%;
            min-width: 0 !important;
        }

        .pendenzen-table thead tr {
            position: absolute;
            top: -9999px;
            left: -9999px;
        }

        .pendenzen-table tr {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
            padding: 8px 0;
            position: relative;
        }

        .pendenzen-table td {
            border: none;
            border-bottom: 1px solid #f1f5f9;
            position: relative;
            padding: 6px 12px 6px 105px !important;
            text-align: left;
            min-height: 28px;
            display: block;
            word-break: break-all;
            font-size: 13px;
        }

        .pendenzen-table td:last-child {
            border-bottom: 0;
        }

        .pendenzen-table td::before {
            content: attr(data-label);
            position: absolute;
            left: 12px;
            width: 85px;
            text-align: left;
            font-weight: 800;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--text-muted);
            white-space: nowrap;
            top: 10px;
        }

        .pendenzen-table td strong {
            font-size: 14px;
            color: var(--text-main);
        }

        .pendenzen-pill {
            padding: 2px 8px;
            font-size: 11px;
        }

        .pendenzen-small {
            margin-top: 2px;
            font-size: 12px;
        }
    }

    .form-section {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: var(--shadow-sm);
    }

    .form-section-head {
        font-size: 15px;
        font-weight: 800;
        color: var(--text-main);
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 1px dashed var(--border-color);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Floating Action Button & Drawer */
    .pendenzen-fab {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 60px;
        height: 60px;
        background: var(--primary);
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        box-shadow: 0 10px 25px rgba(var(--primary-rgb), 0.4);
        cursor: pointer;
        z-index: 1000;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .pendenzen-fab:hover {
        transform: scale(1.1) rotate(90deg);
        background: var(--primary-dark);
    }

    .pendenzen-quick-drawer {
        position: fixed;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%) translateY(110%);
        width: 95%;
        max-width: 600px;
        background: #fff;
        border-radius: 20px 20px 0 0;
        box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.15);
        z-index: 1001;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        padding: 25px;
        visibility: hidden;
        opacity: 0;
    }

    .pendenzen-quick-drawer.open {
        transform: translateX(-50%) translateY(0);
        visibility: visible;
        opacity: 1;
    }

    .pendenzen-drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        opacity: 0;
        visibility: hidden;
        z-index: 1000;
        transition: all 0.3s;
    }

    .pendenzen-drawer-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .drawer-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .drawer-header h2 {
        margin: 0;
        font-size: 18px;
    }

    .drawer-close {
        cursor: pointer;
        font-size: 24px;
        color: var(--text-muted);
    }

    .toggle-icon {
        transition: transform 0.3s ease;
        display: inline-block;
        margin-right: 8px;
        font-size: 14px;
    }

    .collapsed .toggle-icon {
        transform: rotate(-90deg);
    }

    .collapsed #formContent {
        display: none;
    }

    .hidden {
        display: none;
    }

    #toggleForm:hover {
        background: #f8fafc;
    }

    .hidden-desktop {
        display: none;
    }

    @media (max-width: 900px) {
        .hidden-desktop {
            display: block;
        }
    }

    .resizer {
        position: absolute;
        right: 0;
        top: 0;
        height: 100%;
        width: 5px;
        background: rgba(0, 0, 0, 0.05);
        cursor: col-resize;
        user-select: none;
    }

    .resizer:hover {
        background: var(--primary);
    }

    th {
        position: relative;
    }

    .pendenzen-table-img {
        width: var(--thumb-width-desktop, 100%);
        max-width: 100%;
        height: auto;
        min-height: 30px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        background: #f1f5f9;
        display: block;
        cursor: zoom-in;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    @media (max-width: 900px) {
        .pendenzen-table-img {
            width: var(--thumb-width-mobile, 100%);
        }
    }

    .pendenzen-table-img:hover {
        transform: scale(1.05);
        box-shadow: var(--shadow-md);
        z-index: 2;
        position: relative;
    }

    /* Lightbox Modal */
    .pendenzen-lightbox {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.9);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        cursor: zoom-out;
        padding: 20px;
    }

    .pendenzen-lightbox img {
        max-width: 95%;
        max-height: 95%;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
        transition: transform 0.3s ease;
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
                    <a class="pendenzen-chip-link" id="btnQuickAdd" href="javascript:void(0)">⚡ Schnell-Erfassung</a>
                    <a class="pendenzen-chip-link" id="btnAddNew" href="javascript:void(0)">➕ Neue Pendenz</a>
                    <a class="pendenzen-chip-link" href="listen_settings.php">⚙️ Tabellen-Architekt</a>
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
                            <option value="<?php echo (int) $row['id']; ?>"
                                data-default-projekt="<?php echo (int) ($row['default_projekt_id'] ?? 0); ?>"
                                data-default-objekt="<?php echo (int) ($row['default_objekt_id'] ?? 0); ?>"
                                data-default-wohnung="<?php echo (int) ($row['default_wohnung_id'] ?? 0); ?>"
                                data-default-benutzer="<?php echo (int) ($row['default_benutzer_id'] ?? 0); ?>" <?php echo ((int) $input['vorgangsart_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
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
                            <option value="<?php echo (int) $row['id']; ?>"
                                data-projekt-id="<?php echo (int) $row['projekt_id']; ?>" <?php echo ((int) $input['objekt_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
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
                            <option value="<?php echo (int) $row['id']; ?>"
                                data-objekt-id="<?php echo (int) $row['objekt_id']; ?>" <?php echo ((int) $input['wohnung_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
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
                            <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) $input['zustaendig_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
                                <?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pendenzen-dashboard-field">
                    <label>&nbsp;</label>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" id="btnPrevWohnung" class="pendenzen-quick-btn">← Vorherige Wohnung</button>
                        <button type="button" id="btnNextWohnung" class="pendenzen-quick-btn">Nächste Wohnung →</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="pendenzen-card">
            <div class="pendenzen-head"
                style="cursor:pointer; display:flex; justify-content:space-between; align-items:center;"
                id="toggleForm">
                <div>
                    <h1><span class="toggle-icon">▼</span> Neue Pendenz</h1>
                    <div class="pendenzen-sub">Logik neu aufgebaut: Vorgang → Objekt → Wohnung → Unternehmer</div>
                </div>
                <div class="pendenzen-actions">
                    <span class="pendenzen-pill" id="formStatusPill">Geöffnet</span>
                </div>
            </div>

            <div class="pendenzen-content" id="formContent" style="display: none;">
                <?php if ($success !== ''): ?>
                    <div class="pendenzen-msg ok">✅ <?php echo h($success); ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="pendenzen-msg err">⚠️ <?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="post" action="" class="pendenzen-form" id="pendenzenForm">
                    <!-- Sektion 1: Basis-Daten -->
                    <div class="form-section">
                        <div class="form-section-head">📍 Basis-Informationen & Zuweisung</div>
                        <div class="pendenzen-grid">
                            <div class="pendenzen-field">
                                <label for="vorgangsart_id">Vorgang</label>
                                <select name="vorgangsart_id" id="vorgangsart_id">
                                    <option value="">— auswählen —</option>
                                    <?php foreach ($arten as $row): ?>
                                        <option value="<?php echo (int) $row['id']; ?>"
                                            data-default-projekt="<?php echo (int) ($row['default_projekt_id'] ?? 0); ?>"
                                            data-default-objekt="<?php echo (int) ($row['default_objekt_id'] ?? 0); ?>"
                                            data-default-wohnung="<?php echo (int) ($row['default_wohnung_id'] ?? 0); ?>"
                                            data-default-benutzer="<?php echo (int) ($row['default_benutzer_id'] ?? 0); ?>"
                                            data-world="<?php echo h((string) ($row['vorlagen_welt'] ?? 'bkp')); ?>"
                                            <?php echo ((int) $input['vorgangsart_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
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
                                        <option value="<?php echo (int) $row['id']; ?>" data-projekt-id="<?php echo (int) $row['projekt_id']; ?>" <?php echo ((int) $input['objekt_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
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
                                        <option value="<?php echo (int) $row['id']; ?>" data-objekt-id="<?php echo (int) $row['objekt_id']; ?>" <?php echo ((int) $input['wohnung_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pendenzen-field">
                                <label for="raum_id">Raum</label>
                                <select name="raum_id" id="raum_id">
                                    <option value="">— auswählen —</option>
                                    <?php foreach ($raeume as $row): ?>
                                        <option value="<?php echo (int) $row['id']; ?>" data-wohnung-id="<?php echo (int) $row['wohnung_id']; ?>" <?php echo ((int) ($input['raum_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="pendenzen-grid" style="margin-top:20px;">
                            <div class="pendenzen-field">
                                <label for="zustaendig_id">Unternehmer / Zuständig</label>
                                <select name="zustaendig_id" id="zustaendig_id">
                                    <option value="">— auswählen —</option>
                                    <?php foreach ($benutzer as $row): ?>
                                        <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) $input['zustaendig_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pendenzen-field">
                                <label for="projekt_id">Projekt</label>
                                <select name="projekt_id" id="projekt_id">
                                    <option value="">— auswählen —</option>
                                    <?php foreach ($projekte as $row): ?>
                                        <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) $input['projekt_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pendenzen-field">
                                <label for="status">Status</label>
                                <select name="status" id="status">
                                    <?php $statusOptions = ['offen', 'in Bearbeitung', 'erledigt', 'archiviert']; foreach ($statusOptions as $status): ?>
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
                                        <option value="<?php echo $i; ?>" <?php echo ((string) $input['wichtigkeit'] === (string) $i) ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Sektion 2: Kategorie & Texte -->
                    <div class="form-section">
                        <div class="form-section-head">🗂️ Kategorie & Texte</div>
                        <div class="pendenzen-grid">
                            <div class="pendenzen-field">
                                <label for="kategorie_id"><span id="labelKategorieMain"><?php echo h($selectedWorldLabel); ?></span></label>
                                <select name="kategorie_id" id="kategorie_id">
                                    <option value="">— auswählen —</option>
                                    <?php foreach ($kategorien as $row): ?>
                                        <option value="<?php echo (int) $row['id']; ?>"
                                            data-projekt-id="<?php echo h((string) ($row['projekt_id'] ?? '')); ?>"
                                            data-objekt-id="<?php echo h((string) ($row['objekt_id'] ?? '')); ?>"
                                            data-title-text="<?php echo h((string) ($row['name'] ?? '')); ?>"
                                            <?php echo ((int) $input['kategorie_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pendenzen-field">
                                <label for="subkategorie_id"><span id="labelKategorieSub"><?php echo $selectedVorlagenWelt === 'bkp' ? 'BKP Kurzbeschreibung' : 'Unterkategorie'; ?></span></label>
                                <select name="subkategorie_id" id="subkategorie_id">
                                    <option value="">— auswählen —</option>
                                    <?php foreach ($subkategorien as $row): ?>
                                        <option value="<?php echo (int) $row['id']; ?>"
                                            data-kategorie-id="<?php echo (int) $row['kategorie_id']; ?>"
                                            data-projekt-id="<?php echo h((string) ($row['projekt_id'] ?? '')); ?>"
                                            data-objekt-id="<?php echo h((string) ($row['objekt_id'] ?? '')); ?>"
                                            data-title-text="<?php echo h((string) ($row['name'] ?? '')); ?>"
                                            data-kurz-text="<?php echo h((string) ($row['beschreibung'] ?? $row['name'] ?? '')); ?>"
                                            <?php echo ((int) $input['subkategorie_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
                                            <?php echo h($row['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="pendenzen-field">
                                <label for="titel">Titel</label>
                                <input type="text" name="titel" id="titel" value="<?php echo h($input['titel']); ?>">
                            </div>

                            <div class="pendenzen-field">
                                <label for="kurzbeschreibung">Kurzbeschreibung</label>
                                <input type="text" name="kurzbeschreibung" id="kurzbeschreibung" value="<?php echo h($input['kurzbeschreibung']); ?>">
                            </div>
                        </div>

                        <div class="pendenzen-grid" style="margin-top:20px;">
                            <div class="pendenzen-field" style="grid-column: span 2;">
                                <label for="beschreibung">Beschreibung</label>
                                <input type="text" name="beschreibung" id="beschreibung" value="<?php echo h($input['beschreibung']); ?>">
                            </div>

                            <div class="pendenzen-field" style="grid-column: span 2;">
                                <label for="notiz">Notiz</label>
                                <textarea name="notiz" id="notiz"><?php echo h($input['notiz']); ?></textarea>
                            </div>
                        </div>

                        <div class="pendenzen-grid" style="margin-top:20px;">
                            <div class="pendenzen-field" style="grid-column: 1 / -1;">
                                <label for="langbeschreibung">Langbeschreibung</label>
                                <textarea name="langbeschreibung" id="langbeschreibung"><?php echo h($input['langbeschreibung']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Sektion 3: Zeitplan -->
                    <div class="form-section">
                        <div class="form-section-head">⏱️ Zeitplan</div>
                        <div class="pendenzen-grid">
                            <div class="pendenzen-field">
                                <label for="startdatum">Startdatum</label>
                                <input type="date" name="startdatum" id="startdatum" value="<?php echo h($input['startdatum']); ?>">
                            </div>

                            <div class="pendenzen-field">
                                <label for="dauer">Dauer Tage</label>
                                <input type="text" name="dauer" id="dauer" value="<?php echo h($input['dauer']); ?>" placeholder="z. B. 3 Tage">
                            </div>

                            <div class="pendenzen-field">
                                <label for="uhrzeit">Uhrzeit</label>
                                <input type="time" name="uhrzeit" id="uhrzeit" value="<?php echo h($input['uhrzeit']); ?>">
                            </div>

                            <div class="pendenzen-field">
                                <label for="enddatum">Enddatum</label>
                                <input type="date" name="enddatum" id="enddatum" value="<?php echo h($input['enddatum']); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Sektion 4: Optionen -->
                    <div class="form-section">
                        <div class="form-section-head">⚙️ Einstellungen & Sichtbarkeit</div>
                        <div class="pendenzen-checks">
                            <label><input type="checkbox" name="send_now" value="1" <?php echo $input['send_now'] ? 'checked' : ''; ?>> sofort senden</label>
                            <label><input type="checkbox" name="confirmation_required" value="1" <?php echo $input['confirmation_required'] ? 'checked' : ''; ?>> Bestätigung
                                erforderlich</label>
                            <label><input type="checkbox" name="external_can_view" value="1" <?php echo $input['external_can_view'] ? 'checked' : ''; ?>> extern sichtbar</label>
                            <label><input type="checkbox" name="external_can_upload" value="1" <?php echo $input['external_can_upload'] ? 'checked' : ''; ?>> externer Upload erlaubt</label>
                            <label><input type="checkbox" name="public_enabled" value="1" <?php echo $input['public_enabled'] ? 'checked' : ''; ?>> öffentlich aktivieren</label>
                        </div>
                    </div>

                    <div class="pendenzen-actions">
                        <button type="submit" class="pendenzen-btn">Pendenz speichern</button>
                    </div>
                </form>
            </div>

            <div class="pendenzen-content" style="padding-top:0;">
                <div class="pendenzen-list">
                    <div class="pendenzen-view-toggle"
                        style="margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                        <label
                            style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 700; color: var(--text-main); font-size: 14px;">
                            <input type="checkbox" id="toggleTableSettings" style="accent-color: var(--primary);"> ⚙️
                            Spalten einblenden / ausblenden
                        </label>

                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 13px; font-weight: 700; color: var(--text-muted);">Vorlage:</span>
                            <select id="profileSelect"
                                style="width: auto; padding: 5px 10px; font-size: 13px; border-radius: 8px; border: 1px solid var(--border-color);">
                                <option value="">— Standard-Ansicht —</option>
                                <?php foreach ($profiles as $prof): ?>
                                    <option value='<?php echo h($prof['cols']); ?>'><?php echo h($prof['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="tableSettingsPanel" class="form-section"
                        style="display: none; padding: 15px; margin-bottom: 15px; background: #f8fafc;">
                        <div class="form-section-head" style="margin-bottom: 10px; font-size: 13px;">👁️ Sichtbare
                            Spalten wählen</div>
                        <div
                            style="display: flex; flex-wrap: wrap; gap: 15px 30px; margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--border-color); align-items: center;">
                            <label
                                style="font-size: 13px; font-weight: 700; color: var(--text-muted); display:flex; align-items:center; gap:10px;">
                                🖥️ Bild (Desktop):
                                <input type="range" id="imgSizeSliderDesktop" min="40" max="300" value="100"
                                    style="width: 120px; accent-color: var(--primary);">
                                <span id="imgSizeValDesktop"
                                    style="font-family: monospace; min-width: 45px;">100px</span>
                            </label>
                            <label
                                style="font-size: 13px; font-weight: 700; color: var(--text-muted); display:flex; align-items:center; gap:10px;">
                                📱 Bild (Mobile):
                                <input type="range" id="imgSizeSliderMobile" min="40" max="400" value="100"
                                    style="width: 120px; accent-color: var(--primary);">
                                <span id="imgSizeValMobile"
                                    style="font-family: monospace; min-width: 45px;">100px</span>
                            </label>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px 15px; margin-top: 10px;"
                            id="columnToggles">
                            <label><input type="checkbox" data-col="0" checked> ID</label>
                            <label><input type="checkbox" data-col="1" checked> 🖼️</label>
                            <label><input type="checkbox" data-col="2" checked> Titel</label>
                            <label><input type="checkbox" data-col="3" checked> Proj.</label>
                            <label><input type="checkbox" data-col="4" checked> Woh.</label>
                            <label><input type="checkbox" data-col="5" checked> Status</label>
                            <label><input type="checkbox" data-col="6" checked> Prio</label>
                            <label><input type="checkbox" data-col="7" checked> Zust.</label>
                            <label><input type="checkbox" data-col="8" checked> Start</label>
                            <label><input type="checkbox" data-col="9" checked> Ende</label>
                            <label><input type="checkbox" data-col="10" checked> Zeit</label>
                            <label><input type="checkbox" data-col="11" checked> Dauer</label>
                            <label><input type="checkbox" data-col="12"> Tagest.</label>
                            <label><input type="checkbox" data-col="13"> Vorg.</label>
                            <label><input type="checkbox" data-col="14"> Art</label>
                            <label><input type="checkbox" data-col="15"> 📸</label>
                            <label><input type="checkbox" data-col="16"> 📂</label>
                            <label><input type="checkbox" data-col="17"> 📕</label>
                            <label><input type="checkbox" data-col="18"> Kurz.</label>
                            <label><input type="checkbox" data-col="19"> Sichtb.</label>
                            <label><input type="checkbox" data-col="20"> Erst.am</label>
                            <label><input type="checkbox" data-col="21"> Geänd.am</label>
                            <label><input type="checkbox" data-col="22"> Gelö.</label>
                            <label><input type="checkbox" data-col="23"> Erst.v.</label>
                        </div>
                    </div>

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
                        <select id="mobileSort" class="hidden-desktop">
                            <option value="">Sortieren nach...</option>
                            <option value="0">ID</option>
                            <option value="1">Titel</option>
                            <option value="2">Projekt</option>
                            <option value="3">Wohnung</option>
                            <option value="4">Status</option>
                            <option value="5">Wichtigkeit</option>
                        </select>
                    </div>

                    <div class="pendenzen-table-wrap">
                        <table id="pendenzenTable" class="pendenzen-table">
                            <thead>
                                <tr>
                                    <th data-sort="number" data-label="ID">ID</th>
                                    <th data-label="🖼️">🖼️</th>
                                    <th data-sort="text" data-label="Titel">Titel</th>
                                    <th data-sort="text" data-label="Proj.">Proj.</th>
                                    <th data-sort="text" data-label="Woh.">Woh.</th>
                                    <th data-sort="text" data-label="Status">Status</th>
                                    <th data-sort="number" data-label="Prio">Prio</th>
                                    <th data-sort="text" data-label="Zust.">Zust.</th>
                                    <th data-sort="text" data-label="Start">Start</th>
                                    <th data-sort="text" data-label="Ende">Ende</th>
                                    <th data-sort="text" data-label="Zeit">Zeit</th>
                                    <th data-sort="text" data-label="Dauer">Dauer</th>
                                    <th data-sort="text" data-label="Tagest.">Tagest.</th>
                                    <th data-sort="number" data-label="Vorg.">Vorg.</th>
                                    <th data-sort="text" data-label="Art">Art</th>
                                    <th data-sort="number" data-label="📸">📸</th>
                                    <th data-sort="number" data-label="📂">📂</th>
                                    <th data-sort="number" data-label="📕">📕</th>
                                    <th data-sort="text" data-label="Kurz.">Kurz.</th>
                                    <th data-sort="text" data-label="Sichtb.">Sichtb.</th>
                                    <th data-sort="text" data-label="Erst.am">Erst.am</th>
                                    <th data-sort="text" data-label="Geänd.am">Geänd.am</th>
                                    <th data-sort="text" data-label="Gelö.">Gelö.</th>
                                    <th data-sort="number" data-label="Erst.v.">Erst.v.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recent): ?>
                                    <tr>
                                        <td colspan="24" class="pendenzen-table-empty">Keine Pendenzen gefunden.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent as $row):
                                        $statusClass = '';
                                        $statusLow = mb_strtolower((string) $row['status']);
                                        if (str_contains($statusLow, 'offen'))
                                            $statusClass = 'offen';
                                        elseif (str_contains($statusLow, 'bearbeitung'))
                                            $statusClass = 'bearbeitung';
                                        elseif (str_contains($statusLow, 'erledigt'))
                                            $statusClass = 'erledigt';
                                        elseif (str_contains($statusLow, 'archiv'))
                                            $statusClass = 'archiviert';
                                        ?>
                                        <tr data-projekt="<?php echo h((string) $row['projekt_name']); ?>"
                                            data-status="<?php echo h((string) $row['status']); ?>"
                                            data-zustaendig="<?php echo h((string) $row['zustaendig_name']); ?>"
                                            data-wichtigkeit="<?php echo h((string) $row['wichtigkeit']); ?>">
                                            <td data-label="ID"><?php echo h($row['id']); ?></td>
                                            <td data-label="🖼️">
                                                <?php if (!empty($row['first_image'])): ?>
                                                    <img src="../<?php echo h(ltrim($row['first_image'], '/')); ?>"
                                                        class="pendenzen-table-img">
                                                <?php else: ?>
                                                    <div class="pendenzen-table-img"
                                                        style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:10px;">
                                                        -</div>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Titel">
                                                <strong><?php echo h($row['titel']); ?></strong>
                                            </td>
                                            <td data-label="Proj."><?php echo h($row['projekt_name']); ?></td>
                                            <td data-label="Woh."><?php echo h($row['wohnung_name']); ?></td>
                                            <td data-label="Status"><span
                                                    class="pendenzen-pill <?php echo $statusClass; ?>"><?php echo h($row['status']); ?></span>
                                            </td>
                                            <td data-label="Prio">
                                                <span class="pendenzen-pill"
                                                    style="background:<?php echo ((int) $row['wichtigkeit'] >= 4) ? '#fee2e2' : '#f1f5f9'; ?>; color:<?php echo ((int) $row['wichtigkeit'] >= 4) ? '#991b1b' : '#475569'; ?>;">
                                                    P<?php echo h($row['wichtigkeit']); ?>
                                                </span>
                                            </td>
                                            <td data-label="Zust."><?php echo h($row['zustaendig_name']); ?></td>
                                            <td data-label="Start">
                                                <div class="pendenzen-small"><?php echo h($row['startdatum']); ?></div>
                                            </td>
                                            <td data-label="Ende">
                                                <div class="pendenzen-small"><?php echo h($row['enddatum'] ?: '—'); ?></div>
                                            </td>
                                            <td data-label="Zeit"><?php echo h($row['uhrzeit']); ?></td>
                                            <td data-label="Dauer"><?php echo h($row['dauer']); ?></td>
                                            <td data-label="Tagest."><?php echo h($row['tageszeit']); ?></td>
                                            <td data-label="Vorg."><?php echo h($row['vorgaenger_id'] ?: '-'); ?></td>
                                            <td data-label="Art"><?php echo h($row['vorgangsart_name']); ?></td>
                                            <td data-label="📸"><?php echo (int) $row['count_images'] ?: '-'; ?></td>
                                            <td data-label="📂"><?php echo (int) $row['count_files'] ?: '-'; ?></td>
                                            <td data-label="📕">
                                                <?php echo (str_contains(strtolower($row['titel']), 'pdf') || $row['count_files'] > 0) ? '📕' : '-'; ?>
                                            </td>
                                            <td data-label="Kurz."><?php echo h($row['kurzbeschreibung']); ?></td>
                                            <td data-label="Sichtb."><?php echo h($row['sichtbarkeit']); ?></td>
                                            <td data-label="Erst.am"><?php echo h($row['erstellt_am']); ?></td>
                                            <td data-label="Geänd.am"><?php echo h($row['geaendert_am']); ?></td>
                                            <td data-label="Gelö."><?php echo h($row['deleted_at']); ?></td>
                                            <td data-label="Erst.v."><?php echo h($row['erstellt_von']); ?></td>
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

<div id="pendenzenLightbox" class="pendenzen-lightbox">
    <img src="" alt="Vorschau">
</div>

<div class="pendenzen-fab" id="fabAdd">➕</div>
<div class="pendenzen-drawer-overlay" id="drawerOverlay"></div>
<div class="pendenzen-quick-drawer" id="quickDrawer">
    <div class="drawer-header">
        <h2>⚡ Schnell-Eingabe</h2>
        <div class="drawer-close" id="drawerClose">×</div>
    </div>
    <div style="display: flex; flex-direction: column; gap: 12px;">
        <div class="pendenzen-field">
            <label>Was ist zu tun?</label>
            <input type="text" id="quickTitel" placeholder="Titel eingeben...">
            <div id="quickTags" style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px;">
                <button type="button" class="pendenzen-pill"
                    style="cursor:pointer; border:1px solid #ddd;">Abnahme</button>
                <button type="button" class="pendenzen-pill"
                    style="cursor:pointer; border:1px solid #ddd;">Abbruch</button>
                <button type="button" class="pendenzen-pill"
                    style="cursor:pointer; border:1px solid #ddd;">Mangel</button>
                <button type="button" class="pendenzen-pill"
                    style="cursor:pointer; border:1px solid #ddd;">Reinigung</button>
                <button type="button" class="pendenzen-pill"
                    style="cursor:pointer; border:1px solid #ddd;">Dringend</button>
                <button type="button" class="pendenzen-pill"
                    style="cursor:pointer; border:1px solid #ddd;">Termin</button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div class="pendenzen-field">
                <label>Vorgangsart (Art)</label>
                <select id="quickVorgang">
                    <option value="">— Art —</option>
                    <?php foreach ($arten as $row): ?>
                        <option value="<?php echo (int) $row['id']; ?>"><?php echo h($row['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pendenzen-field">
                <label>Projekt</label>
                <select id="quickProjSelector">
                    <option value="">— Projekt —</option>
                    <!-- Wird via JS befüllt oder bleibt bei globalem Filter -->
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div class="pendenzen-field">
                <label>Datum</label>
                <input type="date" id="quickDate" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="pendenzen-field">
                <label>Dauer (Min/Std)</label>
                <input type="text" id="quickDauer" placeholder="z.B. 30min">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div class="pendenzen-field">
                <label>Status</label>
                <select id="quickStatus">
                    <option value="offen">Offen</option>
                    <option value="in Bearbeitung">In Bearbeitung</option>
                </select>
            </div>
            <div class="pendenzen-field">
                <label>Priorität</label>
                <select id="quickPrio">
                    <option value="3">Normal (P3)</option>
                    <option value="1">Niedrig (P1)</option>
                    <option value="5">HOCH (P5)</option>
                </select>
            </div>
        </div>
        <button type="button" class="pendenzen-btn" id="btnQuickSave" style="width: 100%; margin-top: 10px;">
            <span>➕</span> Pendenz jetzt erstellen
        </button>
    </div>
</div>

<script>
    (function () {
        // Toggle Form
        const toggleForm = document.getElementById('toggleForm');
        const formContent = document.getElementById('formContent');
        const toggleIcon = toggleForm.querySelector('.toggle-icon');
        const formStatusPill = document.getElementById('formStatusPill');

        // Initialize collapsed state
        toggleIcon.style.transform = 'rotate(-90deg)';
        formStatusPill.textContent = 'Eingeklappt';
        formStatusPill.style.background = '#f1f5f9';
        formStatusPill.style.color = '#475569';

        toggleForm.addEventListener('click', function () {
            const isCollapsed = formContent.style.display === 'none';
            if (isCollapsed) {
                formContent.style.display = 'block';
                toggleIcon.style.transform = 'rotate(0deg)';
                formStatusPill.textContent = 'Geöffnet';
                formStatusPill.style.background = '#dcfce7';
                formStatusPill.style.color = '#166534';
            } else {
                formContent.style.display = 'none';
                toggleIcon.style.transform = 'rotate(-90deg)';
                formStatusPill.textContent = 'Eingeklappt';
                formStatusPill.style.background = '#f1f5f9';
                formStatusPill.style.color = '#475569';
            }
        });

        const btnAddNew = document.getElementById('btnAddNew');
        if (btnAddNew) {
            btnAddNew.addEventListener('click', function (e) {
                e.preventDefault();
                if (formContent.style.display === 'none') {
                    toggleForm.click();
                }
                toggleForm.scrollIntoView({ behavior: 'smooth' });
            });
        }

        const projekt = document.getElementById('projekt_id');
        const vorgangsart = document.getElementById('vorgangsart_id');
        const objekt = document.getElementById('objekt_id');
        const wohnung = document.getElementById('wohnung_id');
        const zustaendig = document.getElementById('zustaendig_id');
        const kategorie = document.getElementById('kategorie_id');
        const subkategorie = document.getElementById('subkategorie_id');
        const raum = document.getElementById('raum_id');
        const titelInput = document.getElementById('titel');
        const kurzbeschreibungInput = document.getElementById('kurzbeschreibung');
        const labelKategorieMain = document.getElementById('labelKategorieMain');
        const labelKategorieSub = document.getElementById('labelKategorieSub');
        const vorlagenData = <?php echo json_encode(['kategorien' => $vorlagenKategorien, 'subkategorien' => $vorlagenSubkategorien], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const worldLabels = { bkp: 'BKP', mieter: 'Mieterkategorie', vermieter: 'Vermieterkategorie' };

        const quickVorgangsart = document.getElementById('quick_vorgangsart_id');
        const quickWohnung = document.getElementById('quick_wohnung_id');
        const quickUnternehmer = document.getElementById('quick_unternehmer_id');
        const quickObjekt = document.getElementById('quick_objekt_id');
        const btnPrevWohnung = document.getElementById('btnPrevWohnung');
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

        function currentWorld() {
            const opt = vorgangsart.options[vorgangsart.selectedIndex];
            return (opt && opt.getAttribute('data-world')) || 'bkp';
        }

        function fillSelectFromData(selectEl, items, placeholder, selectedValue, mapFn) {
            const currentValue = selectedValue || selectEl.value || '';
            selectEl.innerHTML = '';
            const first = document.createElement('option');
            first.value = '';
            first.textContent = placeholder;
            selectEl.appendChild(first);
            items.forEach((item) => {
                const opt = document.createElement('option');
                opt.value = String(item.id);
                mapFn(opt, item);
                if (String(item.id) === String(currentValue)) {
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
            if (currentValue && selectEl.value !== String(currentValue)) {
                selectEl.value = '';
            }
        }

        function categoryVisible(item) {
            const p = String(item.projekt_id || '');
            const o = String(item.objekt_id || '');
            if (currentWorld() === 'bkp') return true;
            if (!projekt.value) return p === '' && o === '';
            if (objekt.value) {
                return (p === '' && o === '') || (p === projekt.value && o === '') || (o === objekt.value);
            }
            return (p === '' && o === '') || (p === projekt.value && o === '');
        }

        function syncKategorieData() {
            const world = currentWorld();
            if (labelKategorieMain) labelKategorieMain.textContent = worldLabels[world] || 'Kategorie';
            if (labelKategorieSub) labelKategorieSub.textContent = world === 'bkp' ? 'BKP Kurzbeschreibung' : 'Unterkategorie';

            const cats = (vorlagenData.kategorien[world] || []).filter(categoryVisible);
            fillSelectFromData(kategorie, cats, '— auswählen —', kategorie.value, (opt, item) => {
                opt.textContent = item.name || '';
                opt.setAttribute('data-title-text', item.name || '');
                opt.setAttribute('data-projekt-id', item.projekt_id || '');
                opt.setAttribute('data-objekt-id', item.objekt_id || '');
            });

            const subs = (vorlagenData.subkategorien[world] || []).filter((item) => {
                if (String(item.kategorie_id || '') !== String(kategorie.value || '')) return false;
                return categoryVisible(item);
            });
            fillSelectFromData(subkategorie, subs, '— auswählen —', subkategorie.value, (opt, item) => {
                opt.textContent = item.name || '';
                opt.setAttribute('data-kategorie-id', item.kategorie_id || '');
                opt.setAttribute('data-title-text', item.name || '');
                opt.setAttribute('data-kurz-text', item.beschreibung || item.name || '');
            });
        }

        function syncObjectAndApartmentFilters() {
            filterOptions(objekt, 'data-projekt-id', projekt.value);
            if (quickObjekt) filterOptions(quickObjekt, 'data-projekt-id', projekt.value);

            const activeObjektValue = objekt.value || (quickObjekt ? quickObjekt.value : '') || '';
            filterOptions(wohnung, 'data-objekt-id', activeObjektValue);
            if (quickWohnung) filterOptions(quickWohnung, 'data-objekt-id', activeObjektValue);
            if (raum) filterOptions(raum, 'data-wohnung-id', wohnung.value);

            if (quickObjekt && objekt.value !== quickObjekt.value && (objekt.value || quickObjekt.value)) {
                const val = objekt.value || quickObjekt.value;
                objekt.value = val;
                quickObjekt.value = val;
            }

            if (quickWohnung && wohnung.value !== quickWohnung.value && (wohnung.value || quickWohnung.value)) {
                const val = wohnung.value || quickWohnung.value;
                wohnung.value = val;
                quickWohnung.value = val;
            }

            syncKategorieData();
        }

        function applyKategorieFilter() {
            syncKategorieData();
        }

        function buildAutoCategoryTexts() {
            const catOpt = kategorie.options[kategorie.selectedIndex];
            const subOpt = subkategorie.options[subkategorie.selectedIndex];
            const catTitle = catOpt ? (catOpt.getAttribute('data-title-text') || '') : '';
            const subTitle = subOpt ? (subOpt.getAttribute('data-title-text') || '') : '';
            const subKurz = subOpt ? (subOpt.getAttribute('data-kurz-text') || '') : '';
            return {
                title: [catTitle, subTitle].filter(Boolean).join(', '),
                kurz: subKurz || subTitle
            };
        }

        function updateTemplateDrivenFields() {
            const auto = buildAutoCategoryTexts();
            if (titelInput && titelInput.dataset.manual !== '1') {
                titelInput.value = auto.title;
                titelInput.dataset.autoValue = auto.title;
            }
            if (kurzbeschreibungInput && kurzbeschreibungInput.dataset.manual !== '1') {
                kurzbeschreibungInput.value = auto.kurz;
                kurzbeschreibungInput.dataset.autoValue = auto.kurz;
            }
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
            if (quickVorgangsart) quickVorgangsart.value = vorgangsart.value;
            if (quickObjekt) quickObjekt.value = objekt.value;
            if (quickWohnung) quickWohnung.value = wohnung.value;
            if (quickUnternehmer) quickUnternehmer.value = zustaendig.value;
        }

        projekt.addEventListener('change', function () {
            syncObjectAndApartmentFilters();
        });

        objekt.addEventListener('change', function () {
            if (quickObjekt) quickObjekt.value = objekt.value;
            syncObjectAndApartmentFilters();
        });

        if (quickObjekt) quickObjekt.addEventListener('change', function () {
            objekt.value = quickObjekt.value;
            syncObjectAndApartmentFilters();
        });

        wohnung.addEventListener('change', function () {
            if (quickWohnung) quickWohnung.value = wohnung.value;
            syncObjectAndApartmentFilters();
        });

        if (quickWohnung) quickWohnung.addEventListener('change', function () {
            wohnung.value = quickWohnung.value;
            syncObjectAndApartmentFilters();
        });

        zustaendig.addEventListener('change', function () {
            if (quickUnternehmer) quickUnternehmer.value = zustaendig.value;
        });

        if (quickUnternehmer) quickUnternehmer.addEventListener('change', function () {
            zustaendig.value = quickUnternehmer.value;
        });

        vorgangsart.addEventListener('change', function () {
            if (quickVorgangsart) quickVorgangsart.value = vorgangsart.value;
            applyVorgangsartDefaults(vorgangsart);
            syncKategorieData();
            updateTemplateDrivenFields();
        });

        if (quickVorgangsart) quickVorgangsart.addEventListener('change', function () {
            vorgangsart.value = quickVorgangsart.value;
            applyVorgangsartDefaults(quickVorgangsart);
            syncKategorieData();
            updateTemplateDrivenFields();
        });

        kategorie.addEventListener('change', function () { applyKategorieFilter(); updateTemplateDrivenFields(); });
        subkategorie.addEventListener('change', updateTemplateDrivenFields);

        if (btnPrevWohnung) btnPrevWohnung.addEventListener('click', function () {
            const options = visibleOptions(quickWohnung);
            if (!options.length) {
                return;
            }

            let currentIndex = options.findIndex(opt => opt.value === quickWohnung.value);
            currentIndex = currentIndex <= 0 ? options.length - 1 : currentIndex - 1;

            quickWohnung.value = options[currentIndex].value;
            wohnung.value = options[currentIndex].value;
            quickWohnung.dispatchEvent(new Event('change'));
        });

        if (btnNextWohnung) btnNextWohnung.addEventListener('click', function () {
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
            quickWohnung.dispatchEvent(new Event('change'));
        });

        if (titelInput) {
            titelInput.dataset.autoValue = titelInput.value || '';
            titelInput.addEventListener('input', function () {
                this.dataset.manual = (this.value !== (this.dataset.autoValue || '')) ? '1' : '';
            });
        }
        if (kurzbeschreibungInput) {
            kurzbeschreibungInput.dataset.autoValue = kurzbeschreibungInput.value || '';
            kurzbeschreibungInput.addEventListener('input', function () {
                this.dataset.manual = (this.value !== (this.dataset.autoValue || '')) ? '1' : '';
            });
        }

        const pendenzenForm = document.getElementById('pendenzenForm');
        if (pendenzenForm) {
            pendenzenForm.addEventListener('submit', function () {
                const auto = buildAutoCategoryTexts();
                if (titelInput) {
                    const current = (titelInput.value || '').trim();
                    if (!current && auto.title) {
                        titelInput.value = auto.title;
                    } else if (current && auto.title && titelInput.dataset.manual === '1' && current.toLowerCase().indexOf(auto.title.toLowerCase()) === -1) {
                        titelInput.value = current + ', ' + auto.title;
                    }
                }
                if (kurzbeschreibungInput) {
                    const current = (kurzbeschreibungInput.value || '').trim();
                    if (!current && auto.kurz) {
                        kurzbeschreibungInput.value = auto.kurz;
                    } else if (current && auto.kurz && kurzbeschreibungInput.dataset.manual === '1' && current.toLowerCase().indexOf(auto.kurz.toLowerCase()) === -1) {
                        kurzbeschreibungInput.value = current + ', ' + auto.kurz;
                    }
                }
            });
        }

        syncObjectAndApartmentFilters();
        applyKategorieFilter();
        updateTemplateDrivenFields();
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
        const mobileSort = document.getElementById('mobileSort');
        if (mobileSort) {
            mobileSort.addEventListener('change', function () {
                const index = parseInt(this.value);
                if (!isNaN(index) && headers[index]) {
                    headers[index].click();
                }
            });
        }
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
        // Column Visibility Logic
        const toggleTableSettings = document.getElementById('toggleTableSettings');
        const tableSettingsPanel = document.getElementById('tableSettingsPanel');
        const columnToggles = document.getElementById('columnToggles').querySelectorAll('input[type="checkbox"]');

        toggleTableSettings.addEventListener('change', function () {
            tableSettingsPanel.style.display = this.checked ? 'block' : 'none';
            localStorage.setItem('pendenzen_settings_visible', this.checked);
        });

        if (localStorage.getItem('pendenzen_settings_visible') === 'true') {
            toggleTableSettings.checked = true;
            tableSettingsPanel.style.display = 'block';
        }

        function updateColumnVisibility(colIndex, visible) {
            // Headers
            const th = table.querySelectorAll('thead th')[colIndex];
            if (th) th.style.display = visible ? '' : 'none';

            // Body Cells
            table.querySelectorAll('tbody tr').forEach(row => {
                const td = row.querySelectorAll('td')[colIndex];
                if (td) td.style.display = visible ? '' : 'none';
            });

            // Save preference
            const prefs = JSON.parse(localStorage.getItem('pendenzen_column_prefs') || '{}');
            prefs[colIndex] = visible;
            localStorage.setItem('pendenzen_column_prefs', JSON.stringify(prefs));
        }

        columnToggles.forEach(chk => {
            chk.addEventListener('change', function () {
                updateColumnVisibility(parseInt(this.dataset.col), this.checked);
            });
        });

        // Load initial column prefs
        const initialPrefs = JSON.parse(localStorage.getItem('pendenzen_column_prefs') || '{}');
        Object.keys(initialPrefs).forEach(idx => {
            const visible = initialPrefs[idx];
            const chk = document.querySelector(`#columnToggles input[data-col="${idx}"]`);
            if (chk) {
                chk.checked = visible;
                updateColumnVisibility(parseInt(idx), visible);
            }
        });

        // Profile Selector Logic (Listen-Architekt)
        const profileSelect = document.getElementById('profileSelect');
        if (profileSelect) {
            const columnMap = {
                'id': 0,
                'erstes_bild': 1, 'bild': 1, 'cover': 1,
                'titel': 2,
                'projekt_name': 3,
                'wohnung_name': 4,
                'status': 5,
                'wichtigkeit': 6,
                'zustaendig_name': 7,
                'startdatum': 8,
                'enddatum': 9,
                'uhrzeit': 10,
                'dauer': 11,
                'tageszeit': 12,
                'vorgaenger_id': 13,
                'vorgangsart_name': 14,
                'bilder': 15,
                'dokumente': 16,
                'pdf': 17,
                'kurzbeschreibung': 18,
                'sichtbarkeit': 19,
                'erstellt_am': 20,
                'geaendert_am': 21,
                'deleted_at': 22,
                'erstellt_von': 23
            };

            profileSelect.addEventListener('change', function () {
                if (this.value === "") {
                    columnToggles.forEach(chk => {
                        chk.checked = true;
                        updateColumnVisibility(parseInt(chk.dataset.col), true);
                    });
                    return;
                }

                try {
                    const cols = this.value.split(','); // Comma-separated from GROUP_CONCAT
                    columnToggles.forEach(chk => {
                        chk.checked = false;
                        updateColumnVisibility(parseInt(chk.dataset.col), false);
                    });

                    cols.forEach(colName => {
                        const index = columnMap[colName.trim()];
                        if (index !== undefined) {
                            const chk = document.querySelector(`#columnToggles input[data-col="${index}"]`);
                            if (chk) {
                                chk.checked = true;
                                updateColumnVisibility(index, true);
                            }
                        }
                    });
                } catch (e) { console.error("Profile Error", e); }
            });
        }

        // Resizable Columns with Persistence
        const createResizableTable = function (table) {
            const cols = table.querySelectorAll('th');
            const savedWidths = JSON.parse(localStorage.getItem('pendenzen_column_widths') || '{}');

            [].forEach.call(cols, function (col, index) {
                // Restore saved width
                if (savedWidths[index]) {
                    col.style.width = savedWidths[index];
                }

                // Add resizer element
                const resizer = document.createElement('div');
                resizer.classList.add('resizer');
                col.appendChild(resizer);

                let x = 0;
                let w = 0;

                const mouseDownHandler = function (e) {
                    x = e.clientX;
                    const styles = window.getComputedStyle(col);
                    w = parseInt(styles.width, 10);

                    document.addEventListener('mousemove', mouseMoveHandler);
                    document.addEventListener('mouseup', mouseUpHandler);
                };

                const mouseMoveHandler = function (e) {
                    const dx = e.clientX - x;
                    col.style.width = `${w + dx}px`;
                };

                const mouseUpHandler = function () {
                    document.removeEventListener('mousemove', mouseMoveHandler);
                    document.removeEventListener('mouseup', mouseUpHandler);

                    // Save new width
                    const currentWidths = JSON.parse(localStorage.getItem('pendenzen_column_widths') || '{}');
                    currentWidths[index] = col.style.width;
                    localStorage.setItem('pendenzen_column_widths', JSON.stringify(currentWidths));
                };

                resizer.addEventListener('mousedown', mouseDownHandler);
            });
        };
        createResizableTable(document.getElementById('pendenzenTable'));

        // Image Size Persistence (Desktop & Mobile)
        const slDesktop = document.getElementById('imgSizeSliderDesktop');
        const valDesktop = document.getElementById('imgSizeValDesktop');
        const slMobile = document.getElementById('imgSizeSliderMobile');
        const valMobile = document.getElementById('imgSizeValMobile');

        function setSize(type, val) {
            document.documentElement.style.setProperty(`--thumb-width-${type}`, val + 'px');
            if (type === 'desktop') valDesktop.textContent = val + 'px';
            else valMobile.textContent = val + 'px';
            localStorage.setItem(`pendenzen_img_size_${type}`, val);
        }

        if (slDesktop) {
            const sD = localStorage.getItem('pendenzen_img_size_desktop') || '100';
            slDesktop.value = sD;
            setSize('desktop', sD);
            slDesktop.addEventListener('input', (e) => setSize('desktop', e.target.value));
        }
        if (slMobile) {
            const sM = localStorage.getItem('pendenzen_img_size_mobile') || '120';
            slMobile.value = sM;
            setSize('mobile', sM);
            slMobile.addEventListener('input', (e) => setSize('mobile', e.target.value));
        }

        // Lightbox Logic
        const lightbox = document.getElementById('pendenzenLightbox');
        const lightboxImg = lightbox.querySelector('img');

        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('pendenzen-table-img')) {
                lightboxImg.src = e.target.src;
                lightbox.style.display = 'flex';
            } else if (lightbox.style.display === 'flex') {
                lightbox.style.display = 'none';
            }
        });

        // FAB & Drawer Logic
        const fabAdd = document.getElementById('fabAdd');
        const quickDrawer = document.getElementById('quickDrawer');
        const drawerOverlay = document.getElementById('drawerOverlay');
        const drawerClose = document.getElementById('drawerClose');
        const btnQuickSave = document.getElementById('btnQuickSave');
        const quickTitel = document.getElementById('quickTitel');
        const quickProjSelector = document.getElementById('quickProjSelector');

        function openDrawer() {
            // Sync projects into drawer selector
            if (quickProjSelector) {
                quickProjSelector.innerHTML = '<option value="">— Projekt wählen —</option>';
                const globalProj = document.getElementById('filter_projekt');
                const mainProj = document.getElementById('projekt_id');
                const source = globalProj || mainProj;
                if (source) {
                    Array.from(source.options).forEach(opt => {
                        if (opt.value) {
                            const n = document.createElement('option');
                            n.value = opt.value;
                            n.textContent = opt.textContent;
                            if (opt.value === source.value) n.selected = true;
                            quickProjSelector.appendChild(n);
                        }
                    });
                    if (!quickProjSelector.value && source.value) quickProjSelector.value = source.value;
                }
            }

            quickDrawer.classList.add('open');
            drawerOverlay.classList.add('active');
            quickTitel.focus();
        }
        function closeDrawer() {
            quickDrawer.classList.remove('open');
            drawerOverlay.classList.remove('active');
        }

        const btnQuickAdd = document.getElementById('btnQuickAdd');
        if (fabAdd) fabAdd.addEventListener('click', openDrawer);
        if (btnQuickAdd) btnQuickAdd.addEventListener('click', openDrawer);
        if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
        if (drawerOverlay) drawerOverlay.addEventListener('click', closeDrawer);

        // Quick Tags Logic
        document.getElementById('quickTags')?.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                const space = quickTitel.value ? ' ' : '';
                quickTitel.value += space + btn.textContent;
                quickTitel.focus();
            });
        });

        function submitQuick() {
            const title = quickTitel.value.trim();
            if (!title) return;

            // Context
            const projId = quickProjSelector.value || document.getElementById('filter_projekt')?.value || "";
            const objId = document.getElementById('filter_objekt')?.value || "";
            const whgId = document.getElementById('filter_wohnung')?.value || "";

            const formData = new FormData();
            formData.append('action', 'save_pendenz');
            formData.append('titel', title);
            formData.append('vorgangsart_id', document.getElementById('quickVorgang').value);
            formData.append('projekt_id', projId);
            formData.append('objekt_id', objId);
            formData.append('wohnung_id', whgId);
            formData.append('startdatum', document.getElementById('quickDate').value);
            formData.append('dauer', document.getElementById('quickDauer').value);
            formData.append('status', document.getElementById('quickStatus').value);
            formData.append('wichtigkeit', document.getElementById('quickPrio').value);

            btnQuickSave.disabled = true;
            btnQuickSave.textContent = 'Wird gespeichert...';

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(res => {
                if (res.ok) window.location.reload();
                else {
                    alert("Fehler!");
                    btnQuickSave.disabled = false;
                    btnQuickSave.textContent = 'Pendenz jetzt erstellen';
                }
            });
        }

        if (btnQuickSave) {
            btnQuickSave.addEventListener('click', submitQuick);
            quickTitel.addEventListener('keypress', (e) => { if (e.key === 'Enter') submitQuick(); });
        }

    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>