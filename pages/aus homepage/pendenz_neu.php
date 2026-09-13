<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isEmbedded = isset($_GET['embed']) && $_GET['embed'] === '1';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$PAGE_TITLE = 'Neue Pendenz';


if (!$isEmbedded) {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/nav_dispatch.php';
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die('Keine gültige Datenbankverbindung vorhanden.');
}

if ($isEmbedded) {
    echo '<style>body{background:transparent!important;} .app-shell,.page-shell,.content-shell,main{background:transparent!important;} .page-header,.site-header,.site-footer{display:none!important;} .pendenz-neu-page{padding-top:0!important;} </style>';
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function tableExists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function columnExists(mysqli $db, string $table, string $column): bool
{
    if (!tableExists($db, $table)) {
        return false;
    }
    $safeTable = $db->real_escape_string($table);
    $safeColumn = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

// Safe-Check Spalten für pendenzen
try {
    $colsToAdd = [
        'public_enabled' => "TINYINT(1) DEFAULT 0",
        'public_token' => "VARCHAR(255) DEFAULT NULL",
        'external_can_view' => "TINYINT(1) DEFAULT 1",
        'external_can_upload' => "TINYINT(1) DEFAULT 0",
        'confirmation_required' => "TINYINT(1) DEFAULT 0",
    ];
    foreach ($colsToAdd as $col => $def) {
        if (!columnExists($mysqli, 'pendenzen', $col)) {
            $mysqli->query("ALTER TABLE pendenzen ADD COLUMN $col $def");
        }
    }
} catch (Throwable $e) {}

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

function existingBenutzerId(mysqli $db, ?int $id): ?int
{
    if (!$id || $id <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM benutzer WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? (int) $row['id'] : null;
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
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    return (int) $value;
}



function normalizeDateFields(string $startdatum, string $enddatum, string $dauer): array
{
    $startdatum = trim($startdatum);
    $enddatum = trim($enddatum);
    $dauer = trim($dauer);

    $today = date('Y-m-d');

    $normalize = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : '';
    };

    $startdatum = $normalize($startdatum);
    $enddatum = $normalize($enddatum);

    if ($startdatum === '') {
        $startdatum = $today;
    }

    if ($dauer !== '' && ctype_digit($dauer)) {
        $tage = (int) $dauer;
        if ($tage > 0) {
            $base = strtotime($startdatum ?: $today);
            $enddatum = date('Y-m-d', strtotime('+' . $tage . ' day', $base));
        }
    }

    return [$startdatum, $enddatum, $dauer];
}

function deriveVorlagenWelt(array $art): string
{
    $welt = trim((string) ($art['vorlagen_welt'] ?? ''));
    $empfaengerTyp = mb_strtolower(trim((string) ($art['empfaenger_typ'] ?? '')));

    if ($welt === 'mieter' || $welt === 'vermieter') {
        return $welt;
    }

    if (in_array($empfaengerTyp, ['mieter', 'mietinteressent', 'vormieter'], true)) {
        return 'mieter';
    }

    if (in_array($empfaengerTyp, ['vermieter', 'eigentuemer', 'eigentümer', 'verwaltung'], true)) {
        return 'vermieter';
    }

    return $welt !== '' ? $welt : 'bkp';
}

function joinUnique(array $values, string $separator = ', '): string
{
    $seen = [];
    $out = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $key = mb_strtolower($value);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $value;
    }
    return implode($separator, $out);
}

function joinTextBlocks(array $values): string
{
    $blocks = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $blocks[] = $value;
        }
    }
    return implode("\n\n", $blocks);
}

function fetchAllAssoc(mysqli $db, string $sql): array
{
    $rows = [];
    $res = $db->query($sql);
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->close();
    }
    return $rows;
}


function ensureDir(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0777, true) || is_dir($path);
}

function slugifyFileName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name) ?? 'datei';
    $name = trim((string) $name, '._-');
    return $name !== '' ? $name : 'datei';
}

function handlePendenzUploads(mysqli $db, int $pendenzId, ?int $userId): array
{
    $messages = [];
    if (!tableExists($db, 'pendenz_dateien')) {
        return $messages;
    }

    $basePublic = 'uploads/pendenzen/' . $pendenzId;
    $baseFs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pendenzen' . DIRECTORY_SEPARATOR . $pendenzId;

    $configs = [
        'bilder' => [
            'typ' => 'image',
            'dir' => 'bilder',
            'max' => 15 * 1024 * 1024,
            'ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'mime_prefix' => 'image/',
        ],
        'dokumente' => [
            'typ' => 'file',
            'dir' => 'dokumente',
            'max' => 15 * 1024 * 1024,
            'ext' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'],
            'mime_prefix' => '',
        ],
    ];

    foreach ($configs as $field => $cfg) {
        if (!isset($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            continue;
        }
        $names = $_FILES[$field]['name'];
        $tmpNames = $_FILES[$field]['tmp_name'];
        $errors = $_FILES[$field]['error'];
        $sizes = $_FILES[$field]['size'];
        $types = $_FILES[$field]['type'];
        if (!is_array($names)) {
            $names = [$names];
            $tmpNames = [$_FILES[$field]['tmp_name'] ?? ''];
            $errors = [$_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE];
            $sizes = [$_FILES[$field]['size'] ?? 0];
            $types = [$_FILES[$field]['type'] ?? ''];
        }

        $targetDirFs = $baseFs . DIRECTORY_SEPARATOR . $cfg['dir'];
        $targetDirPublic = $basePublic . '/' . $cfg['dir'];
        ensureDir($targetDirFs);

        foreach ($names as $i => $origName) {
            $origName = trim((string) $origName);
            if ($origName === '') {
                continue;
            }
            $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                $messages[] = 'Datei konnte nicht hochgeladen werden: ' . $origName;
                continue;
            }
            $size = (int) ($sizes[$i] ?? 0);
            if ($size <= 0 || $size > $cfg['max']) {
                $messages[] = 'Datei zu gross oder leer: ' . $origName;
                continue;
            }
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $cfg['ext'], true)) {
                $messages[] = 'Dateityp nicht erlaubt: ' . $origName;
                continue;
            }
            $tmp = (string) ($tmpNames[$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }
            $mime = (string) ($types[$i] ?? '');
            if ($cfg['mime_prefix'] !== '' && strncmp($mime, $cfg['mime_prefix'], strlen($cfg['mime_prefix'])) !== 0) {
                $messages[] = 'Ungültiger Bildtyp: ' . $origName;
                continue;
            }
            $storedName = 'up-' . substr(bin2hex(random_bytes(8)), 0, 14) . '-' . slugifyFileName(pathinfo($origName, PATHINFO_FILENAME)) . '.' . $ext;
            $targetFs = $targetDirFs . DIRECTORY_SEPARATOR . $storedName;
            $targetPublic = $targetDirPublic . '/' . $storedName;
            if (!@move_uploaded_file($tmp, $targetFs)) {
                $messages[] = 'Speichern fehlgeschlagen: ' . $origName;
                continue;
            }

            // Automatische Verkleinerung auf max 0.5 MB
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                smart_resize_image($targetFs);
            }
            $isCover = 0;
            if ($cfg['typ'] === 'image') {
                $coverRes = $db->query("SELECT id FROM pendenz_dateien WHERE pendenz_id = $pendenzId AND is_cover = 1 LIMIT 1");
                if ($coverRes && $coverRes->num_rows === 0) {
                    $isCover = 1;
                }
            }

            $validUploaderId = existingBenutzerId($db, $userId);
            if ($validUploaderId !== null) {
                $stmt = $db->prepare('INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, is_cover, sort_index, hochgeladen_von) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)');
                if ($stmt) {
                    $typ = $cfg['typ'];
                    $stmt->bind_param('isssisii', $pendenzId, $typ, $targetPublic, $mime, $size, $origName, $isCover, $validUploaderId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $db->prepare('INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, is_cover, sort_index, hochgeladen_von) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)');
                if ($stmt) {
                    $typ = $cfg['typ'];
                    $stmt->bind_param('isssisi', $pendenzId, $typ, $targetPublic, $mime, $size, $origName, $isCover);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    return $messages;
}

$hasBpt = tableExists($mysqli, 'benutzer_personentypen');
$hasFirmaUser = tableExists($mysqli, 'firma_user');
$hasFirmenVorlagenMap = tableExists($mysqli, 'firmen_vorlagen_map');
$hasArtDefaults = tableExists($mysqli, 'pendenzen_art_empfaenger_defaults');
$hasBkpTextTable = tableExists($mysqli, 'bkp_vorlagen_texte');
$hasPersonTypeColsOnBenutzer = columnExists($mysqli, 'benutzer', 'person_type_id') && columnExists($mysqli, 'benutzer', 'person_status_id');

$success = '';
$error = '';
$currentUserId = existingBenutzerId($mysqli, currentUserId());


$input = [

    'vorgangsart_id' => isset($_GET['vorgangsart_id']) && ctype_digit((string) $_GET['vorgangsart_id']) ? (int) $_GET['vorgangsart_id'] : null,
    'projekt_id' => isset($_GET['projekt_id']) && ctype_digit((string) $_GET['projekt_id']) ? (int) $_GET['projekt_id'] : null,
    'objekt_id' => isset($_GET['objekt_id']) && ctype_digit((string) $_GET['objekt_id']) ? (int) $_GET['objekt_id'] : null,
    'wohnung_id' => isset($_GET['wohnung_id']) && ctype_digit((string) $_GET['wohnung_id']) ? (int) $_GET['wohnung_id'] : null,
    'raum_id' => isset($_GET['raum_id']) && ctype_digit((string) $_GET['raum_id']) ? (int) $_GET['raum_id'] : null,
    'zustaendig_id' => isset($_GET['unternehmer_id']) && ctype_digit((string) $_GET['unternehmer_id']) ? (int) $_GET['unternehmer_id'] : null,
    'bkp_id' => null,
    'bkp_kategorie_id' => null,
    'bkp_text_id' => null,
    'mieter_kategorie_id' => null,
    'mieter_subkategorie_id' => null,
    'vermieter_kategorie_id' => null,
    'vermieter_subkategorie_id' => null,
    'titel_manuell' => '',
    'kurzbeschreibung_manuell' => '',
    'beschreibung_manuell' => '',
    'notiz' => '',
    'status' => 'offen',
    'send_now' => 0,
    'confirmation_required' => 0,
    'external_can_view' => 1,
    'external_can_upload' => 0,
    'public_enabled' => 0,
    'startdatum' => '',
    'enddatum' => '',
    'uhrzeit' => '',
    'dauer' => '',
];

$editId = (isset($_GET['edit_id']) && ctype_digit((string) $_GET['edit_id'])) ? (int) $_GET['edit_id'] : (isset($_POST['edit_id']) && ctype_digit((string) $_POST['edit_id']) ? (int) $_POST['edit_id'] : null);
$duplicateId = (isset($_GET['duplicate_id']) && ctype_digit((string) $_GET['duplicate_id'])) ? (int) $_GET['duplicate_id'] : null;

// --- Handle File Actions (Delete / Cover) ---
if ($editId && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete_file_id']) || isset($_GET['set_cover_id']))) {
    $fid = null;
    $action = null;
    if (isset($_POST['delete_file_id'])) { $fid = (int)$_POST['delete_file_id']; $action = 'delete'; }
    elseif (isset($_GET['delete_file_id'])) { $fid = (int)$_GET['delete_file_id']; $action = 'delete'; }
    elseif (isset($_POST['set_cover_id'])) { $fid = (int)$_POST['set_cover_id']; $action = 'cover'; }
    elseif (isset($_GET['set_cover_id'])) { $fid = (int)$_GET['set_cover_id']; $action = 'cover'; }

    if ($fid && $action === 'delete') {
        // ACL Check: Only Admin or Creator can delete files. Assignees (Entrepreneurs) cannot.
        $canDelete = is_admin();
        $pRes = $mysqli->query("SELECT erstellt_von, zustaendig_id FROM pendenzen WHERE id = $editId LIMIT 1");
        if ($pRes && $pRow = $pRes->fetch_assoc()) {
            if ((int)$pRow['erstellt_von'] === (int)($_SESSION['user_id']??0)) $canDelete = true;
        }
        
        if (!$canDelete) {
            die("Fehler: Sie haben keine Berechtigung, Dateien zu löschen.");
        }

        $fres = $mysqli->query("SELECT pfad FROM pendenz_dateien WHERE id = $fid AND pendenz_id = $editId LIMIT 1");
        if ($fres && $frow = $fres->fetch_assoc()) {
            $fpathFs = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $frow['pfad']);
            if (file_exists($fpathFs)) @unlink($fpathFs);
            $mysqli->query("DELETE FROM pendenz_dateien WHERE id = $fid");
        }
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?edit_id=' . $editId . ($isEmbedded ? '&embed=1' : ''));
        exit;
    }
    if ($fid && $action === 'cover') {
        $mysqli->query("UPDATE pendenz_dateien SET is_cover = 0 WHERE pendenz_id = $editId");
        $mysqli->query("UPDATE pendenz_dateien SET is_cover = 1 WHERE id = $fid AND pendenz_id = $editId");
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . '?edit_id=' . $editId . ($isEmbedded ? '&embed=1' : ''));
        exit;
    }
}


// --- Load Data for Edit OR Duplicate ---
$sourceId = $editId ?: $duplicateId;
if ($sourceId && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $mysqli->prepare("SELECT * FROM pendenzen WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $input['vorgangsart_id'] = (int) ($row['vorgangsart_id'] ?? 0) ?: null;
            $input['projekt_id'] = (int) ($row['projekt_id'] ?? 0) ?: null;
            $input['objekt_id'] = (int) ($row['objekt_id'] ?? 0) ?: null;
            $input['wohnung_id'] = (int) ($row['wohnung_id'] ?? 0) ?: null;
            $input['raum_id'] = (int) ($row['raum_id'] ?? 0) ?: null;
            $input['zustaendig_id'] = (int) ($row['zustaendig_id'] ?? 0) ?: null;
            $input['status'] = (string) ($row['status'] ?? 'offen');
            $input['titel_manuell'] = (string) ($row['titel'] ?? '');
            $input['kurzbeschreibung_manuell'] = (string) ($row['kurzbeschreibung'] ?? '');
            $input['beschreibung_manuell'] = (string) ($row['beschreibung'] ?? '');
            $input['notiz'] = (string) ($row['notiz'] ?? '');
            $input['startdatum'] = (string) ($row['startdatum'] ?? '');
            $input['enddatum'] = (string) ($row['enddatum'] ?? '');
            $input['uhrzeit'] = (string) ($row['uhrzeit'] ?? '');
            $input['dauer'] = (string) ($row['dauer'] ?? '');
            $input['bkp_id'] = (int) ($row['bkp_id'] ?? 0) ?: null;

            // Extra logic for extra_json if it exists
            if (isset($row['extra_json']) && $row['extra_json'] !== '') {
                $extra = json_decode($row['extra_json'], true);
                if (is_array($extra)) {
                    $input['bkp_kategorie_id'] = (int) ($extra['bkp_kategorie_id'] ?? 0) ?: null;
                    $input['bkp_text_id'] = (int) ($extra['bkp_text_id'] ?? 0) ?: null;
                    $input['mieter_kategorie_id'] = (int) ($extra['mieter_kategorie_id'] ?? 0) ?: null;
                    $input['mieter_subkategorie_id'] = (int) ($extra['mieter_subkategorie_id'] ?? 0) ?: null;
                    $input['vermieter_kategorie_id'] = (int) ($extra['vermieter_kategorie_id'] ?? 0) ?: null;
                    $input['vermieter_subkategorie_id'] = (int) ($extra['vermieter_subkategorie_id'] ?? 0) ?: null;
                    $input['titel_manuell'] = (string) ($extra['titel_manuell'] ?? $input['titel_manuell']);
                    $input['kurzbeschreibung_manuell'] = (string) ($extra['kurzbeschreibung_manuell'] ?? $input['kurzbeschreibung_manuell']);
                    $input['beschreibung_manuell'] = (string) ($extra['beschreibung_manuell'] ?? $input['beschreibung_manuell']);
                }
            }

            // Beim Duplizieren setzen wir einige Felder zurück
            if ($duplicateId) {
                $input['status'] = 'offen';
                $input['send_now'] = 0;
            } else {
                $input['send_now'] = (int) ($row['send_now'] ?? 0);
            }

            $input['confirmation_required'] = (int) ($row['confirmation_required'] ?? 0);
            $input['external_can_view'] = (int) ($row['external_can_view'] ?? 1);
            $input['external_can_upload'] = (int) ($row['external_can_upload'] ?? 0);
            $input['public_enabled'] = (int) ($row['public_enabled'] ?? 0);
        }
        $stmt->close();
    }
}


$projekte = fetchAllAssoc($mysqli, "SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name ASC");
$objekte = fetchAllAssoc($mysqli, "SELECT id, projekt_id, name FROM objekte ORDER BY projekt_id ASC, name ASC");
$wohnungen = fetchAllAssoc($mysqli, "SELECT id, objekt_id, name FROM wohnungen ORDER BY objekt_id ASC, name ASC");
$raeume = fetchAllAssoc($mysqli, "SELECT id, wohnung_id, name FROM raeume ORDER BY wohnung_id ASC, name ASC");
$arten = fetchAllAssoc($mysqli, "
    SELECT
        id,
        name,
        slug,
        icon,
        color,
        farbe,
        default_projekt_id,
        default_objekt_id,
        default_wohnung_id,
        default_benutzer_id,
        allow_override_projekt,
        allow_override_objekt,
        allow_override_wohnung,
        allow_override_benutzer,
        vorlagen_welt,
        empfaenger_typ,
        empfaenger_person_type_id,
        empfaenger_person_status_id,
        bkp_erforderlich,
        nur_firmen_bkp,
        firma_bkp_filter
    FROM pendenzen_arten
    WHERE is_active = 1
    ORDER BY sort_order ASC, name ASC
");

$defaultsByArt = [];
if ($hasArtDefaults) {
    $sqlDefaults = "
        SELECT d.*
        FROM pendenzen_art_empfaenger_defaults d
        WHERE d.is_active = 1
        ORDER BY d.pendenz_art_id ASC, d.sort_order ASC, d.id ASC
    ";
    foreach (fetchAllAssoc($mysqli, $sqlDefaults) as $row) {
        $defaultsByArt[(int) $row['pendenz_art_id']][] = $row;
    }
}

$personTypes = fetchAllAssoc($mysqli, "SELECT id, name, slug FROM person_types WHERE active = 1 ORDER BY sort ASC, name ASC");
$personStatuses = fetchAllAssoc($mysqli, "SELECT id, type_id, name, slug FROM person_statuses WHERE active = 1 ORDER BY type_id ASC, sort ASC, name ASC");

$users = [];
$userMemberships = [];
if ($hasBpt) {
    $membershipRows = fetchAllAssoc($mysqli, "
        SELECT
            bpt.benutzer_id,
            bpt.person_type_id,
            bpt.person_status_id,
            bpt.is_primary,
            pt.name AS person_type_name,
            ps.name AS person_status_name
        FROM benutzer_personentypen bpt
        LEFT JOIN person_types pt ON pt.id = bpt.person_type_id
        LEFT JOIN person_statuses ps ON ps.id = bpt.person_status_id
        ORDER BY bpt.benutzer_id ASC, bpt.is_primary DESC, bpt.sort_order ASC, bpt.id ASC
    ");
    foreach ($membershipRows as $row) {
        $uid = (int) ($row['benutzer_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }
        $userMemberships[$uid] ??= [];
        $userMemberships[$uid][] = [
            'person_type_id' => (int) ($row['person_type_id'] ?? 0),
            'person_status_id' => (int) ($row['person_status_id'] ?? 0),
            'person_type_name' => (string) ($row['person_type_name'] ?? ''),
            'person_status_name' => (string) ($row['person_status_name'] ?? ''),
            'is_primary' => (int) ($row['is_primary'] ?? 0),
        ];
    }
}

$userSql = "
    SELECT
        b.id,
        b.name,
        b.email,
        b.rolle,
        COALESCE(f.name, b.firma_name, '') AS firma_name
    FROM benutzer b
";
if ($hasFirmaUser) {
    $userSql .= " LEFT JOIN firma_user fu ON fu.user_id = b.id AND fu.is_primary = 1 LEFT JOIN firmen f ON f.id = fu.firma_id ";
} else {
    $userSql .= " LEFT JOIN firmen f ON 1 = 0 ";
}
$userSql .= " WHERE b.deleted_at IS NULL ORDER BY b.name ASC";
foreach (fetchAllAssoc($mysqli, $userSql) as $row) {
    $uid = (int) ($row['id'] ?? 0);
    $memberships = $userMemberships[$uid] ?? [];
    if (!$memberships && $hasPersonTypeColsOnBenutzer) {
        $fallbackSql = "
            SELECT
                b.person_type_id,
                b.person_status_id,
                pt.name AS person_type_name,
                ps.name AS person_status_name
            FROM benutzer b
            LEFT JOIN person_types pt ON pt.id = b.person_type_id
            LEFT JOIN person_statuses ps ON ps.id = b.person_status_id
            WHERE b.id = {$uid}
            LIMIT 1
        ";
        $fallbackRows = fetchAllAssoc($mysqli, $fallbackSql);
        if (!empty($fallbackRows[0]) && (int) ($fallbackRows[0]['person_type_id'] ?? 0) > 0) {
            $memberships[] = [
                'person_type_id' => (int) $fallbackRows[0]['person_type_id'],
                'person_status_id' => (int) ($fallbackRows[0]['person_status_id'] ?? 0),
                'person_type_name' => (string) ($fallbackRows[0]['person_type_name'] ?? ''),
                'person_status_name' => (string) ($fallbackRows[0]['person_status_name'] ?? ''),
                'is_primary' => 1,
            ];
        }
    }

    $mainMembership = $memberships[0] ?? ['person_type_name' => '', 'person_status_name' => '', 'person_type_id' => 0, 'person_status_id' => 0];
    $label = trim((string) $row['name']);
    if ((string) ($row['firma_name'] ?? '') !== '') {
        $label .= ' · ' . (string) $row['firma_name'];
    }
    if ((string) ($mainMembership['person_type_name'] ?? '') !== '') {
        $label .= ' — ' . (string) $mainMembership['person_type_name'];
    }
    if ((string) ($mainMembership['person_status_name'] ?? '') !== '') {
        $label .= ' / ' . (string) $mainMembership['person_status_name'];
    }

    $users[] = [
        'id' => $uid,
        'name' => (string) ($row['name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'rolle' => (string) ($row['rolle'] ?? ''),
        'firma_name' => (string) ($row['firma_name'] ?? ''),
        'label' => $label,
        'memberships' => $memberships,
    ];
}

$userFirmaBkpMap = [];
if ($hasFirmaUser && $hasFirmenVorlagenMap) {
    foreach (fetchAllAssoc($mysqli, "
        SELECT fu.user_id, fvm.ref_id AS bkp_id
        FROM firma_user fu
        INNER JOIN firmen_vorlagen_map fvm ON fvm.firma_id = fu.firma_id AND fvm.welt = 'bkp'
        WHERE fu.user_id IS NOT NULL
        ORDER BY fu.user_id ASC, fvm.ref_id ASC
    ") as $row) {
        $uid = (int) ($row['user_id'] ?? 0);
        $bid = (int) ($row['bkp_id'] ?? 0);
        if ($uid > 0 && $bid > 0) {
            $userFirmaBkpMap[$uid] ??= [];
            $userFirmaBkpMap[$uid][$bid] = $bid;
        }
    }
}
foreach ($userFirmaBkpMap as $uid => $ids) {
    $userFirmaBkpMap[$uid] = array_values($ids);
}

$bkpCodes = fetchAllAssoc($mysqli, "SELECT id, code, bezeichnung, parent_id FROM bkp_codes ORDER BY code ASC, bezeichnung ASC");
$bkpKategorien = tableExists($mysqli, 'bkp_kategorien')
    ? fetchAllAssoc($mysqli, "SELECT id, bkp_id, name FROM bkp_kategorien ORDER BY bkp_id ASC, name ASC, id ASC")
    : [];
$bkpKategorienByBkp = [];
$bkpKategorieMap = [];
foreach ($bkpKategorien as $row) {
    $kid = (int) ($row['id'] ?? 0);
    $bid = (int) ($row['bkp_id'] ?? 0);
    if ($kid > 0) {
        $bkpKategorieMap[$kid] = $row;
    }
    if ($bid > 0) {
        $bkpKategorienByBkp[$bid] ??= [];
        $bkpKategorienByBkp[$bid][] = $row;
    }
}
$bkpTextsByKategorie = [];
if ($hasBkpTextTable) {
    foreach (fetchAllAssoc($mysqli, "SELECT id, kategorie_id, text FROM bkp_vorlagen_texte ORDER BY id ASC") as $row) {
        $kid = (int) ($row['kategorie_id'] ?? 0);
        if ($kid > 0) {
            $bkpTextsByKategorie[$kid] ??= [];
            $bkpTextsByKategorie[$kid][] = $row;
        }
    }
}

$mieterKategorien = fetchAllAssoc($mysqli, "SELECT id, name, projekt_id, objekt_id FROM pendenz_kategorien_mieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");
$mieterSubkategorien = fetchAllAssoc($mysqli, "SELECT id, kategorie_id, name, beschreibung, projekt_id, objekt_id FROM pendenz_subkategorien_mieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");
$vermieterKategorien = fetchAllAssoc($mysqli, "SELECT id, name, projekt_id, objekt_id FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");
$vermieterSubkategorien = fetchAllAssoc($mysqli, "SELECT id, kategorie_id, name, beschreibung, projekt_id, objekt_id FROM pendenz_subkategorien_vermieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");

$bkpMap = [];
foreach ($bkpCodes as $row) {
    $bkpMap[(int) $row['id']] = $row;
}
$mieterCatMap = [];
foreach ($mieterKategorien as $row) {
    $mieterCatMap[(int) $row['id']] = $row;
}
$mieterSubMap = [];
foreach ($mieterSubkategorien as $row) {
    $mieterSubMap[(int) $row['id']] = $row;
}
$vermieterCatMap = [];
foreach ($vermieterKategorien as $row) {
    $vermieterCatMap[(int) $row['id']] = $row;
}
$vermieterSubMap = [];
foreach ($vermieterSubkategorien as $row) {
    $vermieterSubMap[(int) $row['id']] = $row;
}
$artMap = [];
foreach ($arten as $row) {
    $artMap[(int) $row['id']] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input['vorgangsart_id'] = postIntOrNull('vorgangsart_id');
    $input['projekt_id'] = postIntOrNull('projekt_id');
    $input['objekt_id'] = postIntOrNull('objekt_id');
    $input['wohnung_id'] = postIntOrNull('wohnung_id');
    $input['zustaendig_id'] = postIntOrNull('zustaendig_id');
    $input['bkp_id'] = postIntOrNull('bkp_id');
    $input['bkp_kategorie_id'] = postIntOrNull('bkp_kategorie_id');
    $input['bkp_text_id'] = postIntOrNull('bkp_text_id');
    $input['mieter_kategorie_id'] = postIntOrNull('mieter_kategorie_id');
    $input['mieter_subkategorie_id'] = postIntOrNull('mieter_subkategorie_id');
    $input['vermieter_kategorie_id'] = postIntOrNull('vermieter_kategorie_id');
    $input['vermieter_subkategorie_id'] = postIntOrNull('vermieter_subkategorie_id');
    $input['titel_manuell'] = postStr('titel_manuell');
    $input['kurzbeschreibung_manuell'] = postStr('kurzbeschreibung_manuell');
    $input['beschreibung_manuell'] = postStr('beschreibung_manuell');
    $input['notiz'] = postStr('notiz');
    $input['status'] = postStr('status', 'offen');
    $input['send_now'] = isset($_POST['send_now']) ? 1 : 0;
    $input['confirmation_required'] = isset($_POST['confirmation_required']) ? 1 : 0;
    $input['external_can_view'] = isset($_POST['external_can_view']) ? 1 : 0;
    $input['external_can_upload'] = isset($_POST['external_can_upload']) ? 1 : 0;
    $input['public_enabled'] = isset($_POST['public_enabled']) ? 1 : 0;
    $input['startdatum'] = postStr('startdatum');
    $input['enddatum'] = postStr('enddatum');
    $input['uhrzeit'] = postStr('uhrzeit');
    $input['dauer'] = postStr('dauer');
    [$input['startdatum'], $input['enddatum'], $input['dauer']] = normalizeDateFields($input['startdatum'], $input['enddatum'], $input['dauer']);
    // MySQL strict mode: convert empty date strings to NULL
    if ($input['startdatum'] === '')
        $input['startdatum'] = null;
    if ($input['enddatum'] === '')
        $input['enddatum'] = null;
    if ($input['uhrzeit'] === '')
        $input['uhrzeit'] = null;

    $art = $input['vorgangsart_id'] ? ($artMap[$input['vorgangsart_id']] ?? null) : null;
    $vorlagenWelt = $art ? deriveVorlagenWelt($art) : '';
    $empfaengerTyp = (string) ($art['empfaenger_typ'] ?? '');

    $dynamicTitel = '';
    $dynamicKurzbeschreibung = '';
    $dynamicBeschreibung = '';

    if ($vorlagenWelt === 'bkp' && $input['bkp_id']) {
        if ($input['bkp_kategorie_id'] && isset($bkpKategorieMap[$input['bkp_kategorie_id']])) {
            $dynamicTitel = trim((string) ($bkpKategorieMap[$input['bkp_kategorie_id']]['name'] ?? ''));
        }
        if ($input['bkp_text_id'] && $input['bkp_kategorie_id'] && isset($bkpTextsByKategorie[$input['bkp_kategorie_id']])) {
            foreach ($bkpTextsByKategorie[$input['bkp_kategorie_id']] as $txt) {
                if ((int) ($txt['id'] ?? 0) === (int) $input['bkp_text_id']) {
                    $dynamicKurzbeschreibung = trim((string) ($txt['text'] ?? ''));
                    break;
                }
            }
        }
    } elseif ($vorlagenWelt === 'mieter') {
        if ($input['mieter_kategorie_id'] && isset($mieterCatMap[$input['mieter_kategorie_id']])) {
            $dynamicTitel = trim((string) ($mieterCatMap[$input['mieter_kategorie_id']]['name'] ?? ''));
        }
        if ($input['mieter_subkategorie_id'] && isset($mieterSubMap[$input['mieter_subkategorie_id']])) {
            $sub = $mieterSubMap[$input['mieter_subkategorie_id']];
            $dynamicKurzbeschreibung = trim((string) ($sub['name'] ?? ''));
            $dynamicBeschreibung = trim((string) ($sub['beschreibung'] ?? ''));
        }
    } elseif ($vorlagenWelt === 'vermieter') {
        if ($input['vermieter_kategorie_id'] && isset($vermieterCatMap[$input['vermieter_kategorie_id']])) {
            $dynamicTitel = trim((string) ($vermieterCatMap[$input['vermieter_kategorie_id']]['name'] ?? ''));
        }
        if ($input['vermieter_subkategorie_id'] && isset($vermieterSubMap[$input['vermieter_subkategorie_id']])) {
            $sub = $vermieterSubMap[$input['vermieter_subkategorie_id']];
            $dynamicKurzbeschreibung = trim((string) ($sub['name'] ?? ''));
            $dynamicBeschreibung = trim((string) ($sub['beschreibung'] ?? ''));
        }
    }

    $finalTitel = joinUnique([$input['titel_manuell'], $dynamicTitel], ', ');
    $finalKurzbeschreibung = joinUnique([$input['kurzbeschreibung_manuell'], $dynamicKurzbeschreibung], ', ');
    $finalBeschreibung = joinTextBlocks([$input['beschreibung_manuell'], $dynamicBeschreibung]);

    if ($finalTitel === '') {
        $error = 'Bitte mindestens einen Titel angeben oder eine Vorlage auswählen.';
    } elseif (!$input['vorgangsart_id']) {
        $error = 'Bitte eine Vorgangsart wählen.';
    } elseif (!$input['zustaendig_id']) {
        $error = 'Bitte einen Empfänger wählen.';
    } elseif (!empty($art['bkp_erforderlich']) && !$input['bkp_id']) {
        $error = 'Für diese Vorgangsart ist eine BKP-Auswahl erforderlich.';
    } else {
        $extraJson = json_encode([
            'vorgangsart_id' => $input['vorgangsart_id'],
            'vorlagen_welt' => $vorlagenWelt,
            'empfaenger_typ' => $empfaengerTyp,
            'bkp_id' => $input['bkp_id'],
            'bkp_kategorie_id' => $input['bkp_kategorie_id'],
            'bkp_text_id' => $input['bkp_text_id'],
            'mieter_kategorie_id' => $input['mieter_kategorie_id'],
            'mieter_subkategorie_id' => $input['mieter_subkategorie_id'],
            'vermieter_kategorie_id' => $input['vermieter_kategorie_id'],
            'vermieter_subkategorie_id' => $input['vermieter_subkategorie_id'],
            'titel_manuell' => $input['titel_manuell'],
            'kurzbeschreibung_manuell' => $input['kurzbeschreibung_manuell'],
            'beschreibung_manuell' => $input['beschreibung_manuell'],
            'titel_vorlage' => $dynamicTitel,
            'kurzbeschreibung_vorlage' => $dynamicKurzbeschreibung,
            'beschreibung_vorlage' => $dynamicBeschreibung,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!function_exists('pneu_colExists')) {
            function pneu_colExists(mysqli $db, string $table, string $col): bool
            {
                $t = $db->real_escape_string($table);
                $c = $db->real_escape_string($col);
                $r = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
                return $r && $r->num_rows > 0;
            }
        }
        if (!function_exists('pneu_toId')) {
            function pneu_toId($val)
            {
                $v = (int) $val;
                return $v > 0 ? $v : null;
            }
        }
        if (!function_exists('pneu_toEmptyNull')) {
            function pneu_toEmptyNull($val)
            {
                $v = trim((string) $val);
                return $v === '' ? null : $v;
            }
        }




        // Build dynamic INSERT – always include core columns
        $insertCols = [
            'projekt_id',
            'vorgangsart_id',
            'objekt_id',
            'wohnung_id',
            'raum_id',
            'titel',
            'kurzbeschreibung',
            'notiz',
            'status',
            'bkp_id',
            'startdatum',
            'enddatum',
            'send_now',
            'erstellt_von',
            'zustaendig_id',
            'zustaendig_typ',
        ];
        $insertVals = [
            pneu_toId($input['projekt_id']),
            pneu_toId($input['vorgangsart_id']),
            pneu_toId($input['objekt_id']),
            pneu_toId($input['wohnung_id']),
            pneu_toId($input['raum_id']),
            (string) $finalTitel,
            (string) $finalKurzbeschreibung,
            (string) $input['notiz'],
            (string) $input['status'],
            pneu_toId($input['bkp_id']),
            pneu_toEmptyNull($input['startdatum']),
            pneu_toEmptyNull($input['enddatum']),

            (int) $input['send_now'],
            pneu_toId($currentUserId),
            pneu_toId($input['zustaendig_id']),
            'user',
        ];



        // Optional columns – only if they exist in DB
        $optionalCols = [
            'empfaenger_typ' => (string) $empfaengerTyp,
            'vorlagen_welt' => (string) $vorlagenWelt,
            'langbeschreibung' => '',
            'beschreibung' => (string) $finalBeschreibung,
            'uhrzeit' => pneu_toEmptyNull($input['uhrzeit']),
            'dauer' => (string) $input['dauer'],
            'empfaenger_benutzer_id' => pneu_toId($input['zustaendig_id']),
            'ersteller_benutzer_id' => pneu_toId($currentUserId),
            'confirmation_required' => (int) $input['confirmation_required'],
            'external_can_view' => (int) $input['external_can_view'],
            'external_can_upload' => (int) $input['external_can_upload'],
            'public_enabled' => (int) $input['public_enabled'],
            'extra_json' => (string) $extraJson,
        ];

        if ($input['public_enabled']) {
            // Token generieren falls noch nicht da
            $existingToken = null;
            if ($editId) {
                $tRes = $mysqli->query("SELECT public_token FROM pendenzen WHERE id = $editId LIMIT 1");
                $existingToken = $tRes ? $tRes->fetch_assoc()['public_token'] : null;
            }
            if (!$existingToken) {
                $optionalCols['public_token'] = bin2hex(random_bytes(16));
            }
        }

        foreach ($optionalCols as $optCol => $optVal) {
            if (pneu_colExists($mysqli, 'pendenzen', $optCol)) {
                $insertCols[] = $optCol;
                $insertVals[] = $optVal;
            }
        }


        if ($editId) {
            $updateParts = [];
            $updateVals = [];
            for ($i = 0; $i < count($insertCols); $i++) {
                $updateParts[] = $insertCols[$i] . " = ?";
                $updateVals[] = $insertVals[$i];
            }
            $sql = "UPDATE pendenzen SET " . implode(', ', $updateParts) . " WHERE id = ?";
            $updateVals[] = $editId;
            $stmt = $mysqli->prepare($sql);
        } else {
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $colList = implode(', ', $insertCols);
            $sql = "INSERT INTO pendenzen ($colList) VALUES ($placeholders)";
            $stmt = $mysqli->prepare($sql);
        }

        if (!$stmt) {
            $error = 'Prepare fehlgeschlagen: ' . $mysqli->error;
        } else {
            $bindTypes = '';
            foreach ($updateVals ?? $insertVals as $v) {
                if (is_int($v) || is_bool($v)) {
                    $bindTypes .= 'i';
                } elseif (is_float($v)) {
                    $bindTypes .= 'd';
                } else {
                    $bindTypes .= 's';
                }
            }

            if ($editId) {
                $stmt->bind_param($bindTypes, ...$updateVals);
            } else {
                $stmt->bind_param($bindTypes, ...$insertVals);
            }

            if ($stmt->execute()) {
                $savedId = $editId ?: (int) $stmt->insert_id;
                $uploadMessages = handlePendenzUploads($mysqli, $savedId, $currentUserId);
                $success = ($editId ? 'Pendenz aktualisiert. ' : 'Pendenz gespeichert. ') . 'ID: ' . $savedId;
                if ($uploadMessages !== []) {
                    $success .= ' Hinweise: ' . implode(' | ', $uploadMessages);
                }

                // For edit mode, we stay on the record. For new mode, we might reset (as before)
                if (!$editId) {
                    $prefillArt = $input['vorgangsart_id'];
                    $input = [
                        'vorgangsart_id' => $prefillArt,
                        'projekt_id' => null,
                        'objekt_id' => null,
                        'wohnung_id' => null,
                        'raum_id' => null,
                        'zustaendig_id' => null,
                        'bkp_id' => null,
                        'bkp_kategorie_id' => null,
                        'bkp_text_id' => null,
                        'mieter_kategorie_id' => null,
                        'mieter_subkategorie_id' => null,
                        'vermieter_kategorie_id' => null,
                        'vermieter_subkategorie_id' => null,
                        'titel_manuell' => '',
                        'kurzbeschreibung_manuell' => '',
                        'beschreibung_manuell' => '',
                        'notiz' => '',
                        'status' => 'offen',
                        'send_now' => 0,
                        'confirmation_required' => 0,
                        'external_can_view' => 1,
                        'external_can_upload' => 0,
                        'public_enabled' => 0,
                        'startdatum' => '',
                        'enddatum' => '',
                        'uhrzeit' => '',
                        'dauer' => '',
                    ];
                }
            } else {
                $error = 'Speichern fehlgeschlagen: ' . $stmt->error;
            }
            $stmt->close();
        }

    }
}


$quickRows = [];
$quickSql = "SELECT id, titel, kurzbeschreibung, status FROM pendenzen ORDER BY id DESC LIMIT 15";
$quickRes = $mysqli->query($quickSql);
if ($quickRes instanceof mysqli_result) {
    while ($row = $quickRes->fetch_assoc()) {
        $quickRows[] = $row;
    }
    $quickRes->close();
}

$prefix = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';
?>
<style>
    .pneu-wrap {
        max-width: 1480px;
        width: 100%;
        margin: 18px auto;
        padding: 0 14px
    }

    .pneu-wrap.is-embedded {
        max-width: none;
        margin: 0;
        padding: 0 8px 12px
    }

    .pneu-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 14px
    }

    .pneu-head h1 {
        margin: 0;
        font-size: 28px
    }

    .pneu-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border: 1px solid #d7deea;
        border-radius: 12px;
        background: #fff;
        color: #1f2937;
        text-decoration: none
    }

    .pneu-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.35fr) minmax(340px, .95fr);
        gap: 16px
    }

    .pneu-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 18px;
        padding: 18px;
        box-shadow: 0 8px 26px rgba(15, 23, 42, .05)
    }

    .pneu-card h2 {
        margin: 0 0 14px;
        font-size: 20px
    }

    .pneu-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px
    }

    .pneu-field {
        display: flex;
        flex-direction: column;
        gap: 6px
    }

    .pneu-field.full {
        grid-column: 1/-1
    }

    .pneu-field label {
        font-size: 13px;
        font-weight: 700;
        color: #334155
    }

    .pneu-field input,
    .pneu-field select,
    .pneu-field textarea {
        width: 100%;
        border: 1px solid #cfd8e3;
        border-radius: 12px;
        padding: 11px 12px;
        font: inherit;
        background: #fff
    }

    .pneu-field textarea {
        min-height: 96px;
        resize: vertical
    }

    .pneu-switches {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px
    }

    .pneu-check {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 12px;
        border: 1px solid #d7deea;
        border-radius: 12px;
        background: #f8fafc
    }

    .pneu-check input {
        width: auto
    }

    .pneu-msg {
        padding: 12px 14px;
        border-radius: 12px;
        margin-bottom: 14px
    }

    .pneu-msg.ok {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #166534
    }

    .pneu-msg.err {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b
    }

    .pneu-note {
        font-size: 12px;
        color: #64748b
    }

    .pneu-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 16px
    }

    .pneu-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 10px;
        border: 1px solid #2159a8;
        background: #2563eb;
        color: #fff;
        text-decoration: none;
        font-weight: 700;
        cursor: pointer;
        font-size: 13px
    }

    .pneu-btn.alt {
        background: #fff;
        color: #1f2937;
        border-color: #d7deea
    }

    .pneu-box {
        padding: 10px;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        background: #fafcff
    }

    .pneu-preview {
        font-size: 14px;
        line-height: 1.45
    }

    .pneu-preview strong {
        display: block;
        margin-bottom: 6px
    }

    .pneu-chip {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 700;
        margin-right: 6px;
        margin-bottom: 6px
    }

    .pneu-simple-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px
    }

    .pneu-simple-table th,
    .pneu-simple-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
        vertical-align: top
    }

    .pneu-simple-table th {
        font-size: 12px;
        text-transform: uppercase;
        color: #64748b;
        background: #f8fafc
    }

    .pneu-existing-files img {
        transition: transform 0.2s
    }

    .pneu-existing-files img:hover {
        transform: scale(1.05);
        z-index: 10
    }

    .pneu-btn-mini{padding:6px;border:1px solid #ddd;background:white;border-radius:4px;cursor:pointer;font-size:16px;line-height:1;display:inline-flex;align-items:center;justify-content:center;min-width:32px;min-height:32px;position:relative;z-index:20}

    .pneu-btn-mini:hover {
        background: #f3f4f6
    }

    .pneu-btn-mini.err {
        color: #dc2626
    }

    .pneu-btn-mini.err:hover {
        background: #fef2f2
    }

    @media (max-width: 1200px) {
        .pneu-fields {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }

        .pneu-switches {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }
    }

    @media (max-width: 980px) {
        .pneu-grid {
            grid-template-columns: 1fr
        }

        .pneu-fields {
            grid-template-columns: 1fr
        }

        .pneu-switches {
            grid-template-columns: 1fr
        }

        .pneu-wrap.is-embedded {
            padding: 0 4px 4px
        }
    }
</style>

<div class="pneu-wrap<?php echo $isEmbedded ? ' is-embedded' : ''; ?>">
    <div class="pneu-head">
        <div>
            <h1><?php echo $editId ? 'Pendenz bearbeiten (ID: ' . $editId . ')' : 'Neue Pendenz'; ?></h1>
            <div class="pneu-note">
                <?php echo $isEmbedded ? 'Direkt eingebunden in die Pendenzenliste.' : 'Separates Formular. Tabelle und Dashboard bleiben unberührt.'; ?>
            </div>
        </div>
        <a class="pneu-back" href="<?php echo h($prefix . 'pages/pendenzen.php'); ?>">← Zur Pendenzenliste</a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="pneu-msg ok"><?php echo h($success); ?></div>
        <script>
            window.parent.postMessage('pendenz_saved', '*');
        </script>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="pneu-msg err"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form
        action="<?php echo h($_SERVER['PHP_SELF'] . ($isEmbedded ? '?embed=1' : '') . ($editId ? ($isEmbedded ? '&' : '?') . 'edit_id=' . $editId : '')); ?>"
        method="POST" enctype="multipart/form-data">
        <?php if ($editId): ?>
            <input type="hidden" name="edit_id" value="<?php echo (int) $editId; ?>">
        <?php endif; ?>

        <div class="pneu-grid">
            <div class="pneu-card">
                <h2>1. Grunddaten</h2>
                <div class="pneu-fields">
                    <div class="pneu-field full">
                        <label for="vorgangsart_id">Vorgangsart</label>
                        <select name="vorgangsart_id" id="vorgangsart_id" required>
                            <option value="">— auswählen —</option>
                            <?php foreach ($arten as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['vorgangsart_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo h(trim((string) ($row['icon'] ?? '') . ' ' . (string) ($row['name'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="projekt_id">Projekt</label>
                        <select name="projekt_id" id="projekt_id">
                            <option value="">— kein Projekt —</option>
                            <?php foreach ($projekte as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['projekt_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="objekt_id">Objekt</label>
                        <select name="objekt_id" id="objekt_id">
                            <option value="">— kein Objekt —</option>
                            <?php foreach ($objekte as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>"
                                    data-projekt-id="<?php echo (int) ($row['projekt_id'] ?? 0); ?>" <?php echo ((int) ($input['objekt_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo h((string) $row['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="wohnung_id">Wohnung</label>
                        <select name="wohnung_id" id="wohnung_id">
                            <option value="">— keine Wohnung —</option>
                            <?php foreach ($wohnungen as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>"
                                    data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['wohnung_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo h((string) $row['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="raum_id">Raum</label>
                        <select name="raum_id" id="raum_id">
                            <option value="">— kein Raum —</option>
                            <?php foreach ($raeume as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>"
                                    data-wohnung-id="<?php echo (int) ($row['wohnung_id'] ?? 0); ?>" <?php echo ((int) ($input['raum_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo h((string) $row['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field full">
                        <label for="zustaendig_id">Empfänger</label>
                        <select name="zustaendig_id" id="zustaendig_id" required>
                            <option value="">— auswählen —</option>
                            <?php foreach ($users as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['zustaendig_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="pneu-note" id="empfaenger_hinweis"></div>
                    </div>
                </div>
            </div>

            <div class="pneu-card">
                <h2>2. Logik & Vorschau</h2>
                <div id="welt_info" class="pneu-box pneu-preview">Noch keine Vorgangsart gewählt.</div>

                <div class="pneu-box pneu-preview">
                    <strong>Finaler Text</strong>
                    <div><span class="pneu-note">Titel</span>
                        <div id="preview_titel">—</div>
                    </div>
                    <div style="margin-top:10px"><span class="pneu-note">Kurzbeschreibung</span>
                        <div id="preview_kurz">—</div>
                    </div>
                    <div style="margin-top:10px"><span class="pneu-note">Beschreibung</span>
                        <div id="preview_beschreibung">—</div>
                    </div>
                </div>
            </div>
        </div>

        <div style="height:10px"></div>

        <div class="pneu-grid">
            <div class="pneu-card">
                <h2>3. Freie Eingabe</h2>
                <div class="pneu-fields">
                    <div class="pneu-field full">
                        <label for="titel_manuell">Titel manuell</label>
                        <input type="text" name="titel_manuell" id="titel_manuell"
                            value="<?php echo h($input['titel_manuell']); ?>"
                            placeholder="z. B. Badzimmer, Eingang, Terrasse ...">
                    </div>
                    <div class="pneu-field full">
                        <label for="kurzbeschreibung_manuell">Kurzbeschreibung manuell</label>
                        <input type="text" name="kurzbeschreibung_manuell" id="kurzbeschreibung_manuell"
                            value="<?php echo h($input['kurzbeschreibung_manuell']); ?>"
                            placeholder="z. B. bitte prüfen, Mangel sichtbar, Termin nötig ...">
                    </div>
                    <div class="pneu-field full">
                        <label for="beschreibung_manuell">Beschreibung / Notiz manuell</label>
                        <textarea name="beschreibung_manuell" id="beschreibung_manuell"
                            placeholder="Freier Text für Details."><?php echo h($input['beschreibung_manuell']); ?></textarea>
                    </div>
                    <div class="pneu-field full">
                        <label for="notiz">Interne Notiz</label>
                        <textarea name="notiz" id="notiz"
                            placeholder="Optional."><?php echo h($input['notiz']); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="pneu-card">
                <h2>4. Vorlagen</h2>

                <div id="block_bkp" style="display:none">
                    <div class="pneu-fields">
                        <div class="pneu-field full">
                            <label for="bkp_id">BKP</label>
                            <select name="bkp_id" id="bkp_id">
                                <option value="">— keine BKP —</option>
                                <?php foreach ($bkpCodes as $row): ?>
                                    <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['bkp_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                        <?php echo h(trim((string) ($row['code'] ?? '') . ' ' . (string) ($row['bezeichnung'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="pneu-note" id="bkp_hinweis"></div>
                        </div>
                        <div class="pneu-field full">
                            <label for="bkp_kategorie_id">BKP Titel</label>
                            <select name="bkp_kategorie_id" id="bkp_kategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
                            <input type="hidden" id="php_selected_bkp_kat"
                                value="<?php echo (int) ($input['bkp_kategorie_id'] ?? 0); ?>">
                        </div>
                        <div class="pneu-field full">
                            <label for="bkp_text_id">BKP Beschreibung</label>
                            <select name="bkp_text_id" id="bkp_text_id">
                                <option value="">— auswählen —</option>
                            </select>
                            <input type="hidden" id="php_selected_bkp_text"
                                value="<?php echo (int) ($input['bkp_text_id'] ?? 0); ?>">
                        </div>

                    </div>
                </div>

                <div id="block_mieter" style="display:none">
                    <div class="pneu-fields">
                        <div class="pneu-field full">
                            <label for="mieter_kategorie_id">Mieterkategorie</label>
                            <select name="mieter_kategorie_id" id="mieter_kategorie_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($mieterKategorien as $row): ?>
                                    <option value="<?php echo (int) $row['id']; ?>"
                                        data-projekt-id="<?php echo (int) ($row['projekt_id'] ?? 0); ?>"
                                        data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['mieter_kategorie_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                        <?php echo h((string) $row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pneu-field full">
                            <label for="mieter_subkategorie_id">Mieter-Kurzbeschreibung</label>
                            <select name="mieter_subkategorie_id" id="mieter_subkategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
                            <input type="hidden" id="php_selected_mieter_sub"
                                value="<?php echo (int) ($input['mieter_subkategorie_id'] ?? 0); ?>">
                        </div>
                    </div>

                </div>

                <div id="block_vermieter" style="display:none">
                    <div class="pneu-fields">
                        <div class="pneu-field full">
                            <label for="vermieter_kategorie_id">Vermieterkategorie</label>
                            <select name="vermieter_kategorie_id" id="vermieter_kategorie_id">
                                <option value="">— auswählen —</option>
                                <?php foreach ($vermieterKategorien as $row): ?>
                                    <option value="<?php echo (int) $row['id']; ?>"
                                        data-projekt-id="<?php echo (int) ($row['projekt_id'] ?? 0); ?>"
                                        data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['vermieter_kategorie_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
                                        <?php echo h((string) $row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pneu-field full">
                            <label for="vermieter_subkategorie_id">Vermieter-Kurzbeschreibung</label>
                            <select name="vermieter_subkategorie_id" id="vermieter_subkategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
                            <input type="hidden" id="php_selected_vermieter_sub"
                                value="<?php echo (int) ($input['vermieter_subkategorie_id'] ?? 0); ?>">
                        </div>
                    </div>

                </div>

                <div id="block_keine_welt" class="pneu-note">Diese Vorgangsart hat aktuell keine zusätzliche
                    Vorlagen-Auswahl.</div>
            </div>
        </div>

        <div style="height:10px"></div>

        <div class="pneu-grid">
            <div class="pneu-card">
                <h2>5. Zeit</h2>
                <div class="pneu-fields">
                    <div class="pneu-field">
                        <label for="startdatum">Startdatum</label>
                        <input type="date" name="startdatum" id="startdatum"
                            value="<?php echo h($input['startdatum']); ?>">
                    </div>
                    <div class="pneu-field">
                        <label for="enddatum">Enddatum</label>
                        <input type="date" name="enddatum" id="enddatum" value="<?php echo h($input['enddatum']); ?>">
                    </div>
                    <div class="pneu-field">
                        <label for="uhrzeit">Uhrzeit</label>
                        <input type="time" name="uhrzeit" id="uhrzeit" value="<?php echo h($input['uhrzeit']); ?>">
                    </div>
                    <div class="pneu-field">
                        <label for="dauer">Dauer</label>
                        <input type="text" name="dauer" id="dauer" value="<?php echo h($input['dauer']); ?>"
                            placeholder="z. B. 2 = 2 Tage ab Startdatum">
                    </div>
                </div>
            </div>

            <div class="pneu-card">
                <h2>6. Optionen</h2>
                <div class="pneu-fields">
                    <div class="pneu-field">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <?php foreach (['offen', 'in Bearbeitung', 'erledigt', 'archiviert'] as $status): ?>
                                <option value="<?php echo h($status); ?>" <?php echo $input['status'] === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pneu-field full">
                        <div class="pneu-switches">
                            <label class="pneu-check"><input type="checkbox" name="send_now" <?php echo $input['send_now'] ? 'checked' : ''; ?>> sofort senden</label>
                            <label class="pneu-check"><input type="checkbox" name="confirmation_required" <?php echo $input['confirmation_required'] ? 'checked' : ''; ?>> Bestätigung nötig</label>
                            <label class="pneu-check"><input type="checkbox" name="external_can_view" <?php echo $input['external_can_view'] ? 'checked' : ''; ?>> extern sichtbar</label>
                            <label class="pneu-check"><input type="checkbox" name="external_can_upload" <?php echo $input['external_can_upload'] ? 'checked' : ''; ?>> externe Uploads</label>
                            <label class="pneu-check"><input type="checkbox" name="public_enabled" <?php echo $input['public_enabled'] ? 'checked' : ''; ?>> öffentlich aktiv</label>
                        </div>
                    </div>
                </div>
                <div class="pneu-actions">
                    <button type="submit" class="pneu-btn">💾 Pendenz speichern</button>
                    <a href="<?php echo h(base_url('pages/pendenz_neu.php')); ?>" class="pneu-btn alt">Neu leeren</a>
                    <a href="<?php echo h(base_url('pages/pendenzen.php')); ?>" class="pneu-btn alt">Zur Liste</a>

                </div>
            </div>
        </div>

        <section class="pneu-card pneu-span-3">
            <h3>5. Bilder</h3>
            <div class="pneu-field">
                <label for="bilder">Bilder hinzufügen</label>
                <input type="file" id="bilder" name="bilder[]" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" multiple>
                <small>Erlaubt: JPG, JPEG, PNG, GIF, WEBP · max. 15 MB pro Datei</small>
                <?php if ($editId): ?>
                    <div class="pneu-existing-files">
                        <?php
                        $files = fetchAllAssoc($mysqli, "SELECT * FROM pendenz_dateien WHERE pendenz_id = $editId AND typ = 'image' ORDER BY sort_index ASC, id ASC");
                        if ($files): ?>
                            <div
                                style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:15px;margin-top:10px">
                                <?php foreach ($files as $f): ?>
                                    <div
                                        style="position:relative;aspect-ratio:1;border:1px solid #ddd;border-radius:8px;overflow:hidden;background:#f8fafc;display:flex;flex-direction:column">
                                        <div style="flex:1;overflow:hidden;position:relative">
                                            <img src="<?php echo h(base_url($f['pfad'])); ?>"
                                                style="width:100%;height:100%;object-fit:cover">
                                            <?php if ($f['is_cover']): ?>
                                                <span
                                                    style="position:absolute;top:5px;left:5px;background:#0f766e;color:white;font-size:10px;padding:2px 5px;border-radius:3px;font-weight:700">COVER</span>
                                            <?php endif; ?>
                                        </div>
                                        <div
                                            style="padding:5px;display:flex;gap:5px;background:rgba(255,255,255,0.9);border-top:1px solid #eee">
                                            <a href="?edit_id=<?php echo $editId; ?>&set_cover_id=<?php echo (int) $f['id']; ?><?php echo $isEmbedded ? '&embed=1' : ''; ?>"
                                                class="pneu-btn-mini" title="Als Cover festlegen"
                                                style="flex:1;text-decoration:none"><?php echo $f['is_cover'] ? '★' : '☆'; ?></a>
                                            <a href="?edit_id=<?php echo $editId; ?>&delete_file_id=<?php echo (int) $f['id']; ?><?php echo $isEmbedded ? '&embed=1' : ''; ?>"
                                                class="pneu-btn-mini err" title="Löschen"
                                                style="text-decoration:none">🗑</a>

                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>
        </section>

        <section class="pneu-card pneu-span-3">
            <h3>6. Dokumente</h3>
            <div class="pneu-field">
                <label for="dokumente">Dokumente hinzufügen</label>
                <input type="file" id="dokumente" name="dokumente[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip"
                    multiple>
                <small>Erlaubt: PDF, DOC, DOCX, XLS, XLSX, TXT, ZIP · max. 15 MB pro Datei</small>
            </div>
            <?php if ($editId): ?>
                <div class="pneu-existing-files">
                    <?php
                    $docs = fetchAllAssoc($mysqli, "SELECT * FROM pendenz_dateien WHERE pendenz_id = $editId AND typ = 'file' ORDER BY id ASC");
                    if ($docs): ?>
                        <ul style="margin-top:10px;font-size:12px;list-style:none;padding:0">
                            <?php foreach ($docs as $d): ?>
                                <li style="padding:4px 0;border-bottom:1px solid #eee"><a
                                        href="<?php echo h(base_url($d['pfad'])); ?>" target="_blank">📄
                                        <?php echo h($d['titel'] ?: basename($d['pfad'])); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>



    </form>
</div>

<script>
    const artMap = <?php echo json_encode($artMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const defaultsByArt = <?php echo json_encode($defaultsByArt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const users = <?php echo json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const userFirmaBkpMap = <?php echo json_encode($userFirmaBkpMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const bkpKategorienByBkp = <?php echo json_encode($bkpKategorienByBkp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const bkpTextsByKategorie = <?php echo json_encode($bkpTextsByKategorie, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const mieterSubkategorien = <?php echo json_encode($mieterSubkategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const vermieterSubkategorien = <?php echo json_encode($vermieterSubkategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const mieterKategorien = <?php echo json_encode($mieterKategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const vermieterKategorien = <?php echo json_encode($vermieterKategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const bkpCodes = <?php echo json_encode($bkpCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    const elArt = document.getElementById('vorgangsart_id');
    const elProjekt = document.getElementById('projekt_id');
    const elObjekt = document.getElementById('objekt_id');
    const elWohnung = document.getElementById('wohnung_id');
    const elRaum = document.getElementById('raum_id');
    const elEmpf = document.getElementById('zustaendig_id');
    const elBkp = document.getElementById('bkp_id');
    const elBkpKategorie = document.getElementById('bkp_kategorie_id');
    const elBkpText = document.getElementById('bkp_text_id');
    const elMieterKat = document.getElementById('mieter_kategorie_id');
    const elMieterSub = document.getElementById('mieter_subkategorie_id');
    const elVermieterKat = document.getElementById('vermieter_kategorie_id');
    const elVermieterSub = document.getElementById('vermieter_subkategorie_id');
    const elTitelMan = document.getElementById('titel_manuell');
    const elKurzMan = document.getElementById('kurzbeschreibung_manuell');
    const elBeschrMan = document.getElementById('beschreibung_manuell');
    const elStartdatum = document.getElementById('startdatum');
    const elEnddatum = document.getElementById('enddatum');
    const elDauer = document.getElementById('dauer');
    const elWeltInfo = document.getElementById('welt_info');
    const elEmpfHinweis = document.getElementById('empfaenger_hinweis');
    const elBkpHinweis = document.getElementById('bkp_hinweis');
    const blockBkp = document.getElementById('block_bkp');
    const blockMieter = document.getElementById('block_mieter');
    const blockVermieter = document.getElementById('block_vermieter');
    const blockKeineWelt = document.getElementById('block_keine_welt');
    const previewTitel = document.getElementById('preview_titel');
    const previewKurz = document.getElementById('preview_kurz');
    const previewBeschreibung = document.getElementById('preview_beschreibung');


    function deriveVorlagenWeltJs(art) {
        const welt = String(art?.vorlagen_welt || '').trim();
        const empfaengerTyp = String(art?.empfaenger_typ || '').trim().toLowerCase();

        if (welt === 'mieter' || welt === 'vermieter') return welt;
        if (['mieter', 'mietinteressent', 'vormieter'].includes(empfaengerTyp)) return 'mieter';
        if (['vermieter', 'eigentuemer', 'eigentümer', 'verwaltung'].includes(empfaengerTyp)) return 'vermieter';
        return welt || 'bkp';
    }

    function selectedArt() {
        return artMap[String(elArt.value)] || null;
    }

    function firstDefaultForArt(artId) {
        const list = defaultsByArt[String(artId)] || [];
        return list.length ? list[0] : null;
    }

    function refillSelect(select, options, placeholder, selectedValue) {
        const current = selectedValue ?? select.value;
        select.innerHTML = '';
        const first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value = String(opt.value);
            o.textContent = opt.label;
            if (String(opt.value) === String(current || '')) {
                o.selected = true;
            }
            select.appendChild(o);
        });
    }

    function filterObjekte() {
        const projektId = elProjekt.value;
        const currentVal = elObjekt.value;
        if (!elObjekt.masterOptions) {
            elObjekt.masterOptions = Array.from(elObjekt.options);
        }
        elObjekt.innerHTML = '';
        elObjekt.masterOptions.forEach(opt => {
            const show = !opt.value || !projektId || String(opt.dataset.projektId || '') === String(projektId);
            if (show) elObjekt.appendChild(opt.cloneNode(true));
        });
        elObjekt.value = currentVal;
    }

    function filterWohnungen() {
        const objektId = elObjekt.value;
        const currentVal = elWohnung.value;
        if (!elWohnung.masterOptions) {
            elWohnung.masterOptions = Array.from(elWohnung.options);
        }
        elWohnung.innerHTML = '';
        elWohnung.masterOptions.forEach(opt => {
            const show = !opt.value || !objektId || String(opt.dataset.objektId || '') === String(objektId);
            if (show) elWohnung.appendChild(opt.cloneNode(true));
        });
        elWohnung.value = currentVal;
        filterRaeume();
    }

    function filterRaeume() {
        const wohnungId = elWohnung.value;
        const currentVal = elRaum.value;
        if (!elRaum.masterOptions) {
            elRaum.masterOptions = Array.from(elRaum.options);
        }
        elRaum.innerHTML = '';
        elRaum.masterOptions.forEach(opt => {
            const show = !opt.value || !wohnungId || String(opt.dataset.wohnungId || '') === String(wohnungId);
            if (show) elRaum.appendChild(opt.cloneNode(true));
        });
        elRaum.value = currentVal;
    }

    function userMatchesArt(user, art) {
        if (!art) return true;
        const typeId = String(art.empfaenger_person_type_id || '');
        const statusId = String(art.empfaenger_person_status_id || '');
        if (!typeId) return true;
        const memberships = Array.isArray(user.memberships) ? user.memberships : [];
        return memberships.some(m => {
            if (String(m.person_type_id || '') !== typeId) return false;
            if (statusId && String(m.person_status_id || '') !== statusId) return false;
            return true;
        });
    }

    function filterEmpfaenger(prefill = true) {
        const art = selectedArt();
        const current = elEmpf.value;
        const defaults = firstDefaultForArt(elArt.value);
        const opts = users
            .filter(user => {
                if (current && String(user.id) === String(current)) return true;
                return userMatchesArt(user, art);
            })
            .map(user => ({ value: user.id, label: user.label }));
        refillSelect(elEmpf, opts, '— auswählen —', current);


        let hint = 'Alle passenden Benutzer zum gewählten Vorgang.';
        if (art && art.empfaenger_person_type_id) {
            hint = 'Gefiltert nach Personen-Typ';
            if (art.empfaenger_person_status_id) {
                hint += ' und Status';
            }
            hint += '.';
        }
        if (defaults && prefill && !current && defaults.benutzer_id) {
            elEmpf.value = String(defaults.benutzer_id);
            hint += ' Standard-Empfänger vorausgefüllt.';
        }
        elEmpfHinweis.textContent = hint;
    }

    function filterBkpOptions(prefill = true) {
        const art = selectedArt();
        const userId = elEmpf.value;
        let allowed = bkpCodes.slice();
        const useFirma = art && (String(art.nur_firmen_bkp || '0') === '1' || String(art.firma_bkp_filter || '0') === '1');
        if (useFirma && userId && Array.isArray(userFirmaBkpMap[String(userId)])) {
            const ids = userFirmaBkpMap[String(userId)].map(String);
            allowed = bkpCodes.filter(row => ids.includes(String(row.id)));
            elBkpHinweis.textContent = allowed.length ? 'Nur aktive Firmen-BKP dieses Unternehmers.' : 'Bei dieser Firma sind keine BKP hinterlegt.';
        } else if (art && String(art.vorlagen_welt || '') === 'bkp') {
            elBkpHinweis.textContent = 'Alle BKP verfügbar.';
        } else {
            elBkpHinweis.textContent = '';
        }

        const current = elBkp.value;
        refillSelect(elBkp, allowed.map(row => ({ value: row.id, label: `${row.code} ${row.bezeichnung}`.trim() })), '— keine BKP —', current);

        const def = firstDefaultForArt(elArt.value);
        if (prefill && def && def.bkp_id && !current && String(def.bkp_mode || '') === 'single') {
            elBkp.value = String(def.bkp_id);
        }
        refillBkpKategorien(prefill);
    }

    function refillBkpKategorien(prefill = true) {
        const phpBkpKat = document.getElementById('php_selected_bkp_kat')?.value;
        const list = bkpKategorienByBkp[String(elBkp.value)] || [];
        const current = elBkpKategorie.value;
        refillSelect(elBkpKategorie, list.map(row => ({ value: row.id, label: row.name })), '— auswählen —', current || phpBkpKat);
        refillBkpTexts();
    }


    function refillBkpTexts() {
        const phpBkpText = document.getElementById('php_selected_bkp_text')?.value;
        const list = bkpTextsByKategorie[String(elBkpKategorie.value)] || [];
        refillSelect(elBkpText, list.map(row => ({ value: row.id, label: row.text })), '— auswählen —', elBkpText.value || phpBkpText);
    }


    function refillSubKategorien(prefill = false) {
        const def = prefill ? firstDefaultForArt(elArt.value) : null;

        const projektId = elProjekt.value;
        const objektId = elObjekt.value;

        [...elMieterKat.options].forEach((opt, idx) => {
            if (idx === 0) return;
            const p = String(opt.dataset.projektId || '');
            const o = String(opt.dataset.objektId || '');
            let show = true;
            if (projektId && p && p !== String(projektId)) show = false;
            if (objektId && o && o !== String(objektId)) show = false;
            opt.hidden = !show;
        });
        if (elMieterKat.selectedOptions[0] && elMieterKat.selectedOptions[0].hidden) elMieterKat.value = '';

        [...elVermieterKat.options].forEach((opt, idx) => {
            if (idx === 0) return;
            const p = String(opt.dataset.projektId || '');
            const o = String(opt.dataset.objektId || '');
            let show = true;
            if (projektId && p && p !== String(projektId)) show = false;
            if (objektId && o && o !== String(objektId)) show = false;
            opt.hidden = !show;
        });
        if (elVermieterKat.selectedOptions[0] && elVermieterKat.selectedOptions[0].hidden) elVermieterKat.value = '';

        const phpMieterSub = document.getElementById('php_selected_mieter_sub')?.value;
        const defMieterSub = def?.mieter_subkategorie_id;
        const mSubs = mieterSubkategorien
            .filter(row => String(row.kategorie_id) === String(elMieterKat.value))
            .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
        refillSelect(elMieterSub, mSubs, '— auswählen —', elMieterSub.value || defMieterSub || phpMieterSub);

        const phpVermieterSub = document.getElementById('php_selected_vermieter_sub')?.value;
        const defVermieterSub = def?.vermieter_subkategorie_id;
        const vSubs = vermieterSubkategorien
            .filter(row => String(row.kategorie_id) === String(elVermieterKat.value))
            .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
        refillSelect(elVermieterSub, vSubs, '— auswählen —', elVermieterSub.value || defVermieterSub || phpVermieterSub);


    }

    function applyArtState() {
        const art = selectedArt();
        const welt = deriveVorlagenWeltJs(art);
        blockBkp.style.display = welt === 'bkp' ? '' : 'none';
        blockMieter.style.display = welt === 'mieter' ? '' : 'none';
        blockVermieter.style.display = welt === 'vermieter' ? '' : 'none';
        blockKeineWelt.style.display = (welt === 'bkp' || welt === 'mieter' || welt === 'vermieter') ? 'none' : '';

        if (!art) {
            elWeltInfo.innerHTML = 'Noch keine Vorgangsart gewählt.';
            return;
        }

        const text = [];
        text.push(`<span class="pneu-chip">Vorlagen: ${welt || 'keine'}</span>`);
        if (art.empfaenger_typ) text.push(`<span class="pneu-chip">Empfänger: ${art.empfaenger_typ}</span>`);
        if (String(art.bkp_erforderlich || '0') === '1') text.push('<span class="pneu-chip">BKP Pflicht</span>');
        if (String(art.nur_firmen_bkp || '0') === '1' || String(art.firma_bkp_filter || '0') === '1') text.push('<span class="pneu-chip">nur Firmen-BKP</span>');
        const def = firstDefaultForArt(elArt.value);
        if (def) text.push('<div class="pneu-note" style="margin-top:8px">Standardwerte aus Vorgangsart werden vorausgefüllt.</div>');
        elWeltInfo.innerHTML = text.join(' ');
    }

    function applyDefaultsFromArt(preserveExisting = false) {
        const art = selectedArt();
        const def = firstDefaultForArt(elArt.value);
        if (!art) return;

        const pid = def?.projekt_id || art.default_projekt_id;
        if (pid && (!preserveExisting || !elProjekt.value)) elProjekt.value = String(pid);
        filterObjekte();

        const oid = def?.objekt_id || art.default_objekt_id;
        if (oid && (!preserveExisting || !elObjekt.value)) elObjekt.value = String(oid);
        filterWohnungen();

        const wid = def?.wohnung_id || art.default_wohnung_id;
        if (wid && (!preserveExisting || !elWohnung.value)) elWohnung.value = String(wid);
        filterRaeume();

        filterEmpfaenger(true);
        filterBkpOptions(true);

        if (def) {
            if (def.mieter_kategorie_id && (!preserveExisting || !elMieterKat.value)) elMieterKat.value = String(def.mieter_kategorie_id);
            if (def.mieter_subkategorie_id && (!preserveExisting || !elMieterSub.value)) {
                // we will pass this to refillSubKategorien
            }
            if (def.vermieter_kategorie_id && (!preserveExisting || !elVermieterKat.value)) elVermieterKat.value = String(def.vermieter_kategorie_id);
        }

        refillSubKategorien(true);
        updatePreview();

    }

    function currentDynamic() {
        const art = selectedArt();
        const welt = deriveVorlagenWeltJs(art);
        let titel = '';
        let kurz = '';
        let beschreibung = '';

        if (welt === 'bkp') {
            const katList = bkpKategorienByBkp[String(elBkp.value)] || [];
            const kat = katList.find(row => String(row.id) === String(elBkpKategorie.value));
            if (kat) titel = kat.name || '';
            const list = bkpTextsByKategorie[String(elBkpKategorie.value)] || [];
            const txt = list.find(row => String(row.id) === String(elBkpText.value));
            if (txt) kurz = txt.text || '';
        } else if (welt === 'mieter') {
            const sub = mieterSubkategorien.find(row => String(row.id) === String(elMieterSub.value));
            const cat = mieterKategorien.find(row => String(row.id) === String(elMieterKat.value));
            if (cat) {
                titel = cat.name || '';
            }
            if (sub) {
                kurz = sub.name || '';
                beschreibung = sub.beschreibung || '';
            }
        } else if (welt === 'vermieter') {
            const sub = vermieterSubkategorien.find(row => String(row.id) === String(elVermieterSub.value));
            const cat = vermieterKategorien.find(row => String(row.id) === String(elVermieterKat.value));
            if (cat) {
                titel = cat.name || '';
            }
            if (sub) {
                kurz = sub.name || '';
                beschreibung = sub.beschreibung || '';
            }
        }

        return { titel, kurz, beschreibung };
    }

    function joinUniqueJs(values) {
        const seen = new Set();
        return values
            .map(v => String(v || '').trim())
            .filter(v => {
                if (!v) return false;
                const key = v.toLowerCase();
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            })
            .join(', ');
    }

    function updatePreview() {
        const dyn = currentDynamic();
        previewTitel.textContent = joinUniqueJs([elTitelMan.value, dyn.titel]) || '—';
        previewKurz.textContent = joinUniqueJs([elKurzMan.value, dyn.kurz]) || '—';
        previewBeschreibung.textContent = [elBeschrMan.value, dyn.beschreibung].map(v => String(v || '').trim()).filter(Boolean).join(' | ') || '—';
    }

    function calcEnddatumFromDauer() {
        const dauer = String(elDauer.value || '').trim();
        if (!/^\d+$/.test(dauer)) {
            return;
        }
        let start = String(elStartdatum.value || '').trim();
        if (!start) {
            const today = new Date();
            start = today.toISOString().slice(0, 10);
            elStartdatum.value = start;
        }
        const startDate = new Date(start + 'T00:00:00');
        if (Number.isNaN(startDate.getTime())) {
            return;
        }
        const endDate = new Date(startDate);
        endDate.setDate(endDate.getDate() + parseInt(dauer, 10));
        elEnddatum.value = endDate.toISOString().slice(0, 10);
    }

    elArt.addEventListener('change', () => {
        applyArtState();
        applyDefaultsFromArt();
    });
    elProjekt.addEventListener('change', () => { filterObjekte(); filterWohnungen(); refillSubKategorien(); });
    elObjekt.addEventListener('change', () => { filterWohnungen(); refillSubKategorien(); });
    elWohnung.addEventListener('change', () => { filterRaeume(); updatePreview(); });
    elEmpf.addEventListener('change', () => { filterBkpOptions(false); updatePreview(); });
    elBkp.addEventListener('change', () => { refillBkpKategorien(); updatePreview(); });
    elBkpKategorie.addEventListener('change', () => { refillBkpTexts(); updatePreview(); });
    elBkpText.addEventListener('change', updatePreview);
    elMieterKat.addEventListener('change', () => { refillSubKategorien(); updatePreview(); });
    elMieterSub.addEventListener('change', updatePreview);
    elVermieterKat.addEventListener('change', () => { refillSubKategorien(); updatePreview(); });
    elVermieterSub.addEventListener('change', updatePreview);
    [elTitelMan, elKurzMan, elBeschrMan].forEach(el => el.addEventListener('input', updatePreview));
    elDauer.addEventListener('input', calcEnddatumFromDauer);
    elStartdatum.addEventListener('change', calcEnddatumFromDauer);

    filterObjekte();
    filterWohnungen();
    filterRaeume();
    applyArtState();
    filterEmpfaenger(false);
    filterBkpOptions(false);
    refillSubKategorien();
    updatePreview();
    if (elArt.value) {
        applyDefaultsFromArt(true);
    }
</script>

<?php if (!$isEmbedded) {
    require_once __DIR__ . '/../includes/footer.php';
} ?>