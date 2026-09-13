<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_logged_in()) {
    require_login();
}

$PAGE_TITLE = $PAGE_TITLE ?? 'Neue Pendenz';

if (!defined('IS_EMBEDDED_FORM')) {
    define('IS_EMBEDDED_FORM', isset($isEmbedded) ? (bool)$isEmbedded : (isset($_GET['embed']) && $_GET['embed'] === '1'));
}

if (!IS_EMBEDDED_FORM) {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/nav_dispatch.php';
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die('Keine gültige Datenbankverbindung vorhanden.');
}

if (IS_EMBEDDED_FORM) {
    echo '<style>body{background:transparent!important;} .app-shell,.page-shell,.content-shell,main{background:transparent!important;} .page-header,.site-header,.site-footer{display:none!important;} .pendenz-neu-page{padding-top:0!important;} </style>';
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tableExists')) {
function tableExists(mysqli $db, string $table): bool
{
    $safe = $db->real_escape_string($table);
    $res = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

}

if (!function_exists('columnExists')) {
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

}

if (!function_exists('currentUserId')) {
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

}

if (!function_exists('existingBenutzerId')) {
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

}

if (!function_exists('postStr')) {
function postStr(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

}

if (!function_exists('postIntOrNull')) {
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



}

if (!function_exists('normalizeDateFields')) {
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

}

if (!function_exists('deriveVorlagenWelt')) {
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

}

if (!function_exists('joinUnique')) {
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

}

if (!function_exists('joinTextBlocks')) {
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

}

if (!function_exists('fetchAllAssoc')) {
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


}

if (!function_exists('ensureDir')) {
function ensureDir(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0777, true) || is_dir($path);
}

}

if (!function_exists('slugifyFileName')) {
function slugifyFileName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name) ?? 'datei';
    $name = trim((string) $name, '._-');
    return $name !== '' ? $name : 'datei';
}

}

ensure_projekt_plaene_tables($mysqli);

if (!function_exists('handlePendenzUploads')) {
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
            $validUploaderId = existingBenutzerId($db, $userId);
            
            // Check if a cover already exists for this pendenz
            $coverRes = $db->query("SELECT id FROM pendenz_dateien WHERE pendenz_id = $pendenzId AND typ = 'image' AND is_cover = 1 LIMIT 1");
            $hasCover = ($coverRes && $coverRes->num_rows > 0);
            $newIsCover = ($cfg['typ'] === 'image' && !$hasCover) ? 1 : 0;

            if ($validUploaderId !== null) {
                $stmt = $db->prepare('INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, is_cover, sort_index, hochgeladen_von) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)');
                if ($stmt) {
                    $typ = $cfg['typ'];
                    $stmt->bind_param('isssisii', $pendenzId, $typ, $targetPublic, $mime, $size, $origName, $newIsCover, $validUploaderId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $db->prepare('INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, is_cover, sort_index, hochgeladen_von) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL)');
                if ($stmt) {
                    $typ = $cfg['typ'];
                    $stmt->bind_param('isssisi', $pendenzId, $typ, $targetPublic, $mime, $size, $origName, $newIsCover);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }

    return $messages;
}

}

$hasBpt = tableExists($mysqli, 'benutzer_personentypen');
$hasFirmaUser = tableExists($mysqli, 'firma_user');
$hasFirmenVorlagenMap = tableExists($mysqli, 'firmen_vorlagen_map');
$hasArtDefaults = tableExists($mysqli, 'pendenzen_art_empfaenger_defaults');
$hasBkpTextTable = tableExists($mysqli, 'bkp_vorlagen_texte');
$hasPersonTypeColsOnBenutzer = columnExists($mysqli, 'benutzer', 'person_type_id') && columnExists($mysqli, 'benutzer', 'person_status_id');


if (!function_exists('selectExistingColumn')) {
function selectExistingColumn(mysqli $db, string $table, string $column, string $defaultSql = 'NULL', ?string $alias = null): string
{
    $alias = $alias ?: $column;
    if (columnExists($db, $table, $column)) {
        return '`' . $column . '`';
    }
    return $defaultSql . ' AS `' . $alias . '`';
}

}

$success = '';
$error = '';
$currentUserId = existingBenutzerId($mysqli, currentUserId());

$input = $input ?? [
    'vorgangsart_id' => isset($_GET['vorgangsart_id']) && ctype_digit((string) $_GET['vorgangsart_id']) ? (int) $_GET['vorgangsart_id'] : null,
    'projekt_id' => null,
    'objekt_id' => null,
    'wohnung_id' => null,
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
    'plan_id' => null,
    'pin_x' => null,
    'pin_y' => null,
    'plan_pins' => '[]',
];

// [Antigravity] Duplication Logic: Load data if duplicate_id is provided
$duplicateId = isset($_GET['duplicate_id']) && ctype_digit((string)$_GET['duplicate_id']) ? (int)$_GET['duplicate_id'] : null;
if ($duplicateId) {
    $resDup = $mysqli->query("SELECT * FROM pendenzen WHERE id = " . $duplicateId);
    if ($resDup && $rowDup = $resDup->fetch_assoc()) {
        $ext = json_decode((string)($rowDup['extra_json'] ?? ''), true);
        
        $input['vorgangsart_id'] = (int)($rowDup['vorgangsart_id'] ?? 0) ?: null;
        $input['projekt_id'] = (int)($rowDup['projekt_id'] ?? 0) ?: null;
        $input['objekt_id'] = (int)($rowDup['objekt_id'] ?? 0) ?: null;
        $input['wohnung_id'] = (int)($rowDup['wohnung_id'] ?? 0) ?: null;
        $input['raum_id'] = (int)($rowDup['raum_id'] ?? 0) ?: null;
        $input['zustaendig_id'] = (int)($rowDup['zustaendig_id'] ?? 0) ?: null;
        $input['bkp_id'] = (int)($rowDup['bkp_id'] ?? 0) ?: null;
        
        // Use extra_json for precise manual/template split
        if ($ext) {
            $input['bkp_kategorie_id'] = (int)($ext['bkp_kategorie_id'] ?? 0) ?: null;
            $input['bkp_text_id'] = (int)($ext['bkp_text_id'] ?? 0) ?: null;
            $input['mieter_kategorie_id'] = (int)($ext['mieter_kategorie_id'] ?? 0) ?: null;
            $input['mieter_subkategorie_id'] = (int)($ext['mieter_subkategorie_id'] ?? 0) ?: null;
            $input['vermieter_kategorie_id'] = (int)($ext['vermieter_kategorie_id'] ?? 0) ?: null;
            $input['vermieter_subkategorie_id'] = (int)($ext['vermieter_subkategorie_id'] ?? 0) ?: null;
            $input['titel_manuell'] = (string)($ext['titel_manuell'] ?? '');
            $input['kurzbeschreibung_manuell'] = (string)($ext['kurzbeschreibung_manuell'] ?? '');
            $input['beschreibung_manuell'] = (string)($ext['beschreibung_manuell'] ?? '');
        } else {
            // Fallback if extra_json is missing
            $input['titel_manuell'] = (string)($rowDup['titel'] ?? '');
            $input['kurzbeschreibung_manuell'] = (string)($rowDup['kurzbeschreibung'] ?? '');
            $input['beschreibung_manuell'] = (string)($rowDup['beschreibung'] ?? '');
        }
        
        $input['notiz'] = (string)($rowDup['notiz'] ?? '');
        $input['status'] = (string)($rowDup['status'] ?? 'offen');
        $input['startdatum'] = (string)($rowDup['startdatum'] ?? '');
        $input['enddatum'] = (string)($rowDup['enddatum'] ?? '');
        $input['uhrzeit'] = (string)($rowDup['uhrzeit'] ?? '');
        $input['dauer'] = (string)($rowDup['dauer'] ?? '');
    }
}

// [Antigravity] Edit Mode Logic: Load data if edit_id is provided
$editPendenzId = isset($_GET['edit_id']) && ctype_digit((string)$_GET['edit_id']) ? (int)$_GET['edit_id'] : null;
if ($editPendenzId) {
    $resEdit = $mysqli->query("SELECT * FROM pendenzen WHERE id = " . $editPendenzId);
    if ($resEdit && $rowEdit = $resEdit->fetch_assoc()) {
        $ext = json_decode((string)($rowEdit['extra_json'] ?? ''), true);
        
        $input['vorgangsart_id'] = (int)($rowEdit['vorgangsart_id'] ?? 0) ?: null;
        $input['projekt_id'] = (int)($rowEdit['projekt_id'] ?? 0) ?: null;
        $input['objekt_id'] = (int)($rowEdit['objekt_id'] ?? 0) ?: null;
        $input['wohnung_id'] = (int)($rowEdit['wohnung_id'] ?? 0) ?: null;
        $input['raum_id'] = (int)($rowEdit['raum_id'] ?? 0) ?: null;
        $input['zustaendig_id'] = (int)($rowEdit['zustaendig_id'] ?? 0) ?: null;
        $input['bkp_id'] = (int)($rowEdit['bkp_id'] ?? 0) ?: null;
        
        if ($ext) {
            $input['bkp_kategorie_id'] = (int)($ext['bkp_kategorie_id'] ?? 0) ?: null;
            $input['bkp_text_id'] = (int)($ext['bkp_text_id'] ?? 0) ?: null;
            $input['mieter_kategorie_id'] = (int)($ext['mieter_kategorie_id'] ?? 0) ?: null;
            $input['mieter_subkategorie_id'] = (int)($ext['mieter_subkategorie_id'] ?? 0) ?: null;
            $input['vermieter_kategorie_id'] = (int)($ext['vermieter_kategorie_id'] ?? 0) ?: null;
            $input['vermieter_subkategorie_id'] = (int)($ext['vermieter_subkategorie_id'] ?? 0) ?: null;
            $input['titel_manuell'] = (string)($ext['titel_manuell'] ?? '');
            $input['kurzbeschreibung_manuell'] = (string)($ext['kurzbeschreibung_manuell'] ?? '');
            $input['beschreibung_manuell'] = (string)($ext['beschreibung_manuell'] ?? '');
        } else {
            $input['titel_manuell'] = (string)($rowEdit['titel'] ?? '');
            $input['kurzbeschreibung_manuell'] = (string)($rowEdit['kurzbeschreibung'] ?? '');
            $input['beschreibung_manuell'] = (string)($rowEdit['beschreibung'] ?? '');
        }
        
        $input['notiz'] = (string)($rowEdit['notiz'] ?? '');
        $input['status'] = (string)($rowEdit['status'] ?? 'offen');
        $input['startdatum'] = (string)($rowEdit['startdatum'] ?? '');
        $input['enddatum'] = (string)($rowEdit['enddatum'] ?? '');
        $input['uhrzeit'] = (string)($rowEdit['uhrzeit'] ?? '');
        $input['dauer'] = (string)($rowEdit['dauer'] ?? '');

        $switches = ['confirmation_required', 'external_can_view', 'external_can_upload', 'public_enabled'];
        foreach ($switches as $s) {
            if (isset($rowEdit[$s])) {
                $input[$s] = (int)$rowEdit[$s];
            } elseif (isset($ext[$s])) {
                $input[$s] = (int)$ext[$s];
            }
        }
    }
}

// [Antigravity] Attachment AJAX Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postStr('form_action');
    if ($action === 'set_cover') {
        $pid = postIntOrNull('pendenz_id');
        $aid = postIntOrNull('attachment_id');
        if ($pid && $aid) {
            $mysqli->query("UPDATE pendenz_dateien SET is_cover = 0 WHERE pendenz_id = $pid AND typ = 'image'");
            $mysqli->query("UPDATE pendenz_dateien SET is_cover = 1 WHERE id = $aid AND pendenz_id = $pid");
            echo "ok"; exit;
        }
    } elseif ($action === 'delete_attachment') {
        $pid = postIntOrNull('pendenz_id');
        $aid = postIntOrNull('attachment_id');
        if ($pid && $aid) {
            $res = $mysqli->query("SELECT pfad, is_cover FROM pendenz_dateien WHERE id = $aid AND pendenz_id = $pid");
            if ($res && $row = $res->fetch_assoc()) {
                $wasCover = (int)$row['is_cover'];
                $fsPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['pfad']);
                if (is_file($fsPath)) @unlink($fsPath);
                $mysqli->query("DELETE FROM pendenz_dateien WHERE id = $aid");
                
                // Fallback if we deleted the cover
                if ($wasCover) {
                    $mysqli->query("UPDATE pendenz_dateien SET is_cover = 1 WHERE pendenz_id = $pid AND typ = 'image' ORDER BY CASE WHEN titel='Plan-Ausschnitt' THEN 1 ELSE 0 END ASC, id ASC LIMIT 1");
                }
                echo "ok"; exit;
            }
        }
    }
}

// [Antigravity] Fetch existing attachments for preview
$existingAttachments = [];
if ($editPendenzId && tableExists($mysqli, 'pendenz_dateien')) {
    $resAtt = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id = $editPendenzId ORDER BY COALESCE(is_cover,0) DESC, CASE WHEN titel='Plan-Ausschnitt' THEN 1 ELSE 0 END ASC, id ASC");
    if ($resAtt) while ($row = $resAtt->fetch_assoc()) $existingAttachments[] = $row;
}

$projekte = fetchAllAssoc($mysqli, "SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name ASC");
$objekte = fetchAllAssoc($mysqli, "SELECT id, projekt_id, name FROM objekte ORDER BY projekt_id ASC, name ASC");
$wohnungen = fetchAllAssoc($mysqli, "SELECT id, objekt_id, name FROM wohnungen ORDER BY objekt_id ASC, name ASC");
$raeume = fetchAllAssoc($mysqli, "SELECT id, wohnung_id, name FROM raeume ORDER BY wohnung_id ASC, name ASC");
$artenColumns = [
    'id',
    'name',
    selectExistingColumn($mysqli, 'pendenzen_arten', 'slug', "''"),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'icon', "''"),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'color', "''"),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'farbe', "''"),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'default_projekt_id'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'default_objekt_id'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'default_wohnung_id'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'default_benutzer_id'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'allow_override_projekt', '1'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'allow_override_objekt', '1'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'allow_override_wohnung', '1'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'allow_override_benutzer', '1'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'vorlagen_welt', "'bkp'"),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'empfaenger_typ', "''"),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'empfaenger_person_type_id'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'empfaenger_person_status_id'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'bkp_erforderlich', '0'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'nur_firmen_bkp', '0'),
    selectExistingColumn($mysqli, 'pendenzen_arten', 'firma_bkp_filter', "''"),
];
$artenWhere = columnExists($mysqli, 'pendenzen_arten', 'is_active') ? 'WHERE is_active = 1' : '';
$artenOrder = columnExists($mysqli, 'pendenzen_arten', 'sort_order') ? 'sort_order ASC, name ASC' : 'name ASC';
$arten = fetchAllAssoc($mysqli, "SELECT " . implode(', ', $artenColumns) . " FROM pendenzen_arten {$artenWhere} ORDER BY {$artenOrder}");

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

$mieterKatOrder   = (columnExists($mysqli, 'pendenz_kategorien_mieter',     'sortierung') ? 'sortierung ASC, ' : '') . (columnExists($mysqli, 'pendenz_kategorien_mieter',     'sort_order') ? 'sort_order ASC, ' : '') . 'name ASC';
$mieterSubOrder   = (columnExists($mysqli, 'pendenz_subkategorien_mieter',  'sortierung') ? 'sortierung ASC, ' : '') . (columnExists($mysqli, 'pendenz_subkategorien_mieter',  'sort_order') ? 'sort_order ASC, ' : '') . 'name ASC';
$vermieterKatOrder= (columnExists($mysqli, 'pendenz_kategorien_vermieter',  'sortierung') ? 'sortierung ASC, ' : '') . (columnExists($mysqli, 'pendenz_kategorien_vermieter',  'sort_order') ? 'sort_order ASC, ' : '') . 'name ASC';
$vermieterSubOrder= (columnExists($mysqli, 'pendenz_subkategorien_vermieter','sortierung') ? 'sortierung ASC, ' : '') . (columnExists($mysqli, 'pendenz_subkategorien_vermieter','sort_order') ? 'sort_order ASC, ' : '') . 'name ASC';

// Load categories using try/catch – robust like ajax_quick_pendenz.php
// Try with projekt_id/objekt_id columns first; fall back to simple query if columns don't exist
$mieterKategorien = [];
if (tableExists($mysqli, 'pendenz_kategorien_mieter')) {
    try {
        $res = $mysqli->query("SELECT id, name, projekt_id, objekt_id FROM pendenz_kategorien_mieter WHERE aktiv = 1 ORDER BY {$mieterKatOrder}");
        if ($res) while ($row = $res->fetch_assoc()) $mieterKategorien[] = $row;
    } catch (Throwable $e) {
        try {
            $res = $mysqli->query("SELECT id, name, NULL AS projekt_id, NULL AS objekt_id FROM pendenz_kategorien_mieter WHERE aktiv = 1 ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $mieterKategorien[] = $row;
        } catch (Throwable $e2) { /* ignore */ }
    }
}

$mieterSubkategorien = [];
if (tableExists($mysqli, 'pendenz_subkategorien_mieter')) {
    try {
        $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung, projekt_id, objekt_id FROM pendenz_subkategorien_mieter WHERE aktiv = 1 ORDER BY {$mieterSubOrder}");
        if ($res) while ($row = $res->fetch_assoc()) $mieterSubkategorien[] = $row;
    } catch (Throwable $e) {
        try {
            $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung, NULL AS projekt_id, NULL AS objekt_id FROM pendenz_subkategorien_mieter WHERE aktiv = 1 ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $mieterSubkategorien[] = $row;
        } catch (Throwable $e2) { /* ignore */ }
    }
}

$vermieterKategorien = [];
if (tableExists($mysqli, 'pendenz_kategorien_vermieter')) {
    try {
        $res = $mysqli->query("SELECT id, name, projekt_id, objekt_id FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY {$vermieterKatOrder}");
        if ($res) while ($row = $res->fetch_assoc()) $vermieterKategorien[] = $row;
    } catch (Throwable $e) {
        try {
            $res = $mysqli->query("SELECT id, name, NULL AS projekt_id, NULL AS objekt_id FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $vermieterKategorien[] = $row;
        } catch (Throwable $e2) { /* ignore */ }
    }
}

$vermieterSubkategorien = [];
if (tableExists($mysqli, 'pendenz_subkategorien_vermieter')) {
    try {
        $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung, projekt_id, objekt_id FROM pendenz_subkategorien_vermieter WHERE aktiv = 1 ORDER BY {$vermieterSubOrder}");
        if ($res) while ($row = $res->fetch_assoc()) $vermieterSubkategorien[] = $row;
    } catch (Throwable $e) {
        try {
            $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung, NULL AS projekt_id, NULL AS objekt_id FROM pendenz_subkategorien_vermieter WHERE aktiv = 1 ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $vermieterSubkategorien[] = $row;
        } catch (Throwable $e2) { /* ignore */ }
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !IS_EMBEDDED_FORM) {
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
    $input['plan_id'] = postIntOrNull('plan_id');
    $input['pin_x'] = isset($_POST['pin_x']) && $_POST['pin_x'] !== '' ? (float)str_replace(',', '.', $_POST['pin_x']) : null;
    $input['pin_y'] = isset($_POST['pin_y']) && $_POST['pin_y'] !== '' ? (float)str_replace(',', '.', $_POST['pin_y']) : null;
    $input['plan_pins'] = $_POST['plan_pins'] ?? '[]';
    [$input['startdatum'], $input['enddatum'], $input['dauer']] = normalizeDateFields($input['startdatum'], $input['enddatum'], $input['dauer']);

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

    $planSnapshotPath = null;
    $snapData = $_POST['plan_snapshot_data'] ?? '';
    $pendingSnapshotInsert = null;
    
    error_log("[SNAP DEBUG] snapData length: " . strlen($snapData));
    error_log("[SNAP DEBUG] plan_pins: " . ($_POST['plan_pins'] ?? 'NONE'));
    
    if ($snapData === 'DELETE') {
        $planSnapshotPath = ''; // flag to delete
    } elseif (!empty($snapData)) {
        $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $snapData);
        $imgData = base64_decode($base64);
        if ($imgData) {
            $uploadDir = dirname(__DIR__) . '/uploads/pendenzen';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
            $snapName = 'plan_snap_' . uniqid() . '.jpg';
            $snapPath = $uploadDir . '/' . $snapName;
            if (file_put_contents($snapPath, $imgData)) {
                $planSnapshotPath = 'uploads/pendenzen/' . $snapName;
                $pendingSnapshotInsert = $planSnapshotPath;
                error_log("[SNAP DEBUG] Saved snapshot to: $snapPath");
            } else {
                error_log("[SNAP DEBUG] FAILED to write snapshot to: $snapPath");
            }
        } else {
            error_log("[SNAP DEBUG] base64_decode returned empty");
        }
    } else {
        error_log("[SNAP DEBUG] snapData is EMPTY - no snapshot to save");
    }

    if ($finalTitel === '') {
        $error = 'Bitte mindestens einen Titel angeben oder eine Vorlage auswählen.';
    } elseif (!$input['vorgangsart_id']) {
        $error = 'Bitte eine Vorgangsart wählen.';
    } elseif (!$input['zustaendig_id']) {
        $error = 'Bitte einen Empfänger wählen.';
    } elseif (!empty($art['bkp_erforderlich']) && !$input['bkp_id']) {
        $error = 'Für diese Vorgangsart ist eine BKP-Auswahl erforderlich.';
    } elseif (($_POST['form_action'] ?? '') === 'update' && !empty($_POST['pendenz_id'])) {
        // ── UPDATE existing pendenz ──────────────────────────────────────────
        $updateId = (int)$_POST['pendenz_id'];
        
        $oldP = $mysqli->query("SELECT extra_json FROM pendenzen WHERE id = $updateId")->fetch_assoc();
        $oldCfg = json_decode($oldP['extra_json'] ?? 'null', true) ?: [];
        $finalSnapPath = $planSnapshotPath !== null ? $planSnapshotPath : ($oldCfg['plan_snapshot_path'] ?? '');
        if ($finalSnapPath === '') unset($finalSnapPath);

        $extraArray = [
            'plan_pins'                 => json_decode($input['plan_pins'] ?? '[]', true),
            'vorgangsart_id'            => $input['vorgangsart_id'],
            'vorlagen_welt'             => $vorlagenWelt,
            'empfaenger_typ'            => $empfaengerTyp,
            'bkp_id'                    => $input['bkp_id'],
            'bkp_kategorie_id'          => $input['bkp_kategorie_id'],
            'bkp_text_id'               => $input['bkp_text_id'],
            'mieter_kategorie_id'       => $input['mieter_kategorie_id'],
            'mieter_subkategorie_id'    => $input['mieter_subkategorie_id'],
            'vermieter_kategorie_id'    => $input['vermieter_kategorie_id'],
            'vermieter_subkategorie_id' => $input['vermieter_subkategorie_id'],
            'titel_manuell'             => $input['titel_manuell'],
            'kurzbeschreibung_manuell'  => $input['kurzbeschreibung_manuell'],
            'beschreibung_manuell'      => $input['beschreibung_manuell'],
            'titel_vorlage'             => $dynamicTitel,
            'kurzbeschreibung_vorlage'  => $dynamicKurzbeschreibung,
            'beschreibung_vorlage'      => $dynamicBeschreibung,
        ];
        if (isset($finalSnapPath)) $extraArray['plan_snapshot_path'] = $finalSnapPath;
        $extraJson = json_encode($extraArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Build UPDATE dynamically – only include columns that exist in this DB schema
        // Core columns always present:
        $updateCols = [
            'projekt_id'     => $input['projekt_id'],
            'vorgangsart_id' => $input['vorgangsart_id'],
            'objekt_id'      => $input['objekt_id'],
            'wohnung_id'     => $input['wohnung_id'],
            'titel'          => $finalTitel,
            'kurzbeschreibung' => $finalKurzbeschreibung,
            'notiz'          => $input['notiz'],
            'beschreibung'   => $finalBeschreibung,
            'status'         => $input['status'],
            'bkp_id'         => $input['bkp_id'],
            'startdatum'     => $input['startdatum'],
            'enddatum'       => $input['enddatum'],
            'uhrzeit'        => $input['uhrzeit'],
            'dauer'          => $input['dauer'],
            'zustaendig_id'  => $input['zustaendig_id'],
            'extra_json'     => $extraJson,
            'plan_id'        => $input['plan_id'],
            'pin_x'          => $input['pin_x'],
            'pin_y'          => $input['pin_y'],
        ];

        // Optional columns – only add if they exist in this database
        $optionalCols = [
            'vorlagen_welt'          => $vorlagenWelt,
            'empfaenger_typ'         => $empfaengerTyp,
            'langbeschreibung'       => $finalBeschreibung,
            'empfaenger_benutzer_id' => $input['zustaendig_id'],
            'confirmation_required'  => $input['confirmation_required'],
            'external_can_view'      => $input['external_can_view'],
            'external_can_upload'    => $input['external_can_upload'],
            'public_enabled'         => $input['public_enabled'],
        ];
        foreach ($optionalCols as $col => $val) {
            if (columnExists($mysqli, 'pendenzen', $col)) {
                $updateCols[$col] = $val;
            }
        }

        $setParts = [];
        $bindValues = [];
        foreach ($updateCols as $col => $val) {
            $setParts[] = "`{$col}` = ?";
            $bindValues[] = $val;
        }
        $bindValues[] = $updateId; // WHERE id = ?

        $sql = 'UPDATE pendenzen SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $error = 'Update-Prepare fehlgeschlagen: ' . $mysqli->error;
        } else {
            $bindTypes = '';
            foreach ($bindValues as $v) {
                $bindTypes .= (is_int($v) || is_bool($v)) ? 'i' : 's';
            }
            $stmt->bind_param($bindTypes, ...$bindValues);
            if ($stmt->execute()) {
                if ($pendingSnapshotInsert) {
                    $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id = $updateId AND typ = 'image' AND titel = 'Plan-Ausschnitt'");
                    $snapEsc = $mysqli->real_escape_string($pendingSnapshotInsert);
                    $r = $mysqli->query("INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, is_cover, sort_index, hochgeladen_von) VALUES ($updateId, 'image', '$snapEsc', 'image/jpeg', 0, 'Plan-Ausschnitt', 0, 9999, NULL)");
                    error_log('[SNAP DEBUG] DB insert result: ' . ($r ? 'OK' : $mysqli->error));
                }
                $uploadMessages = handlePendenzUploads($mysqli, $updateId, $currentUserId);
                $prefix = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';
                $redirectUrl = $prefix . 'pages/pendenzen.php?saved=' . $updateId;
                if ($uploadMessages !== []) {
                    $redirectUrl .= '&msg=' . urlencode(implode(' | ', $uploadMessages));
                }
                // Safe redirect: use HTTP header if possible, JS fallback if headers already sent
                $safeUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
                if (!headers_sent()) {
                    header('Location: ' . $redirectUrl);
                    exit;
                } else {
                    echo '<script>window.location.href=' . json_encode($redirectUrl) . ';</script>';
                    echo '<p>Gespeichert. <a href="' . $safeUrl . '">Hier klicken</a> falls nicht weitergeleitet.</p>';
                    exit;
                }
            } else {
                $error = 'Update fehlgeschlagen: ' . $stmt->error;
            }
            $stmt->close();
        }
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

        $sql = "
            INSERT INTO pendenzen (
                projekt_id,
                vorgangsart_id,
                vorlagen_welt,
                empfaenger_typ,
                objekt_id,
                wohnung_id,
                titel,
                kurzbeschreibung,
                langbeschreibung,
                notiz,
                beschreibung,
                status,
                bkp_id,
                startdatum,
                enddatum,
                uhrzeit,
                dauer,
                send_now,
                erstellt_von,
                zustaendig_id,
                empfaenger_benutzer_id,
                ersteller_benutzer_id,
                confirmation_required,
                external_can_view,
                external_can_upload,
                public_enabled,
                extra_json,
                zustaendig_typ,
                plan_id,
                pin_x,
                pin_y
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user',
                ?, ?, ?
            )
        ";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            $error = 'Prepare fehlgeschlagen: ' . $mysqli->error;
        } else {
            $bindValues = [
                $input['projekt_id'],
                $input['vorgangsart_id'],
                $vorlagenWelt,
                $empfaengerTyp,
                $input['objekt_id'],
                $input['wohnung_id'],
                $finalTitel,
                $finalKurzbeschreibung,
                $input['notiz'],
                $finalBeschreibung,
                $input['status'],
                $input['bkp_id'],
                $input['startdatum'],
                $input['enddatum'],
                $input['uhrzeit'],
                $input['dauer'],
                $input['send_now'],
                $currentUserId,
                $input['zustaendig_id'],
                $input['zustaendig_id'],
                $currentUserId,
                $input['confirmation_required'],
                $input['external_can_view'],
                $input['external_can_upload'],
                $input['public_enabled'],
                $extraJson,
                $input['plan_id'],
                $input['pin_x'],
                $input['pin_y'],
            ];
            $bindTypes = '';
            foreach ($bindValues as $bindValue) {
                $bindTypes .= is_int($bindValue) || is_bool($bindValue) ? 'i' : 's';
            }
            $stmt->bind_param($bindTypes, ...$bindValues);

            if ($stmt->execute()) {
                $newId = (int) $stmt->insert_id;
                if ($pendingSnapshotInsert) {
                    $snapEsc = $mysqli->real_escape_string($pendingSnapshotInsert);
                    $r = $mysqli->query("INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, is_cover, sort_index, hochgeladen_von) VALUES ($newId, 'image', '$snapEsc', 'image/jpeg', 0, 'Plan-Ausschnitt', 0, 9999, NULL)");
                    error_log('[SNAP DEBUG] DB insert (new) result: ' . ($r ? 'OK' : $mysqli->error));
                }
                $uploadMessages = handlePendenzUploads($mysqli, $newId, $currentUserId);

                // Final fallback: ensure at least one image is cover
                $check = $mysqli->query("SELECT id FROM pendenz_dateien WHERE pendenz_id = $newId AND typ = 'image' AND is_cover = 1 LIMIT 1");
                if ($check && $check->num_rows === 0) {
                    $mysqli->query("UPDATE pendenz_dateien SET is_cover = 1 WHERE pendenz_id = $newId AND typ = 'image' ORDER BY CASE WHEN titel='Plan-Ausschnitt' THEN 1 ELSE 0 END ASC, id ASC LIMIT 1");
                }

                $success = 'Pendenz gespeichert. ID: ' . $newId;
                if ($uploadMessages !== []) {
                    $success .= ' Hinweise: ' . implode(' | ', $uploadMessages);
                }
                $prefillArt = $input['vorgangsart_id'];
                $input = [
                    'vorgangsart_id' => $prefillArt,
                    'projekt_id' => null,
                    'objekt_id' => null,
                    'wohnung_id' => null,
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
                    'plan_id' => null,
                    'pin_x' => null,
                    'pin_y' => null,
                ];
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
.pneu-wrap{max-width:1480px;width:100%;margin:18px auto;padding:0 14px}.pneu-wrap.is-embedded{max-width:none;margin:0;padding:0 8px 12px}
.pneu-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.pneu-head h1{margin:0;font-size:28px}
.pneu-back{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #d7deea;border-radius:12px;background:#fff;color:#1f2937;text-decoration:none}
.pneu-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(340px,.95fr);gap:16px}
.pneu-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:18px;box-shadow:0 8px 26px rgba(15,23,42,.05)}
.pneu-card h2{margin:0 0 14px;font-size:20px}
.pneu-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.pneu-field{display:flex;flex-direction:column;gap:6px}
.pneu-field.full{grid-column:1/-1}
.pneu-field label{font-size:13px;font-weight:700;color:#334155}
.pneu-field input,.pneu-field select,.pneu-field textarea{width:100%;border:1px solid #cfd8e3;border-radius:12px;padding:11px 12px;font:inherit;background:#fff}
.pneu-field textarea{min-height:96px;resize:vertical}
.pneu-switches{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.pneu-check{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #d7deea;border-radius:12px;background:#f8fafc}
.pneu-check input{width:auto}
.pneu-msg{padding:12px 14px;border-radius:12px;margin-bottom:14px}
.pneu-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#166534}
.pneu-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.pneu-note{font-size:12px;color:#64748b}
.pneu-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pneu-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:8px 12px;border-radius:10px;border:1px solid #2159a8;background:#2563eb;color:#fff;text-decoration:none;font-weight:700;cursor:pointer;font-size:13px}
.pneu-btn.alt{background:#fff;color:#1f2937;border-color:#d7deea}
.pneu-btn.danger{background:#ef4444;border-color:#dc2626}
.pneu-box{padding:10px;border:1px dashed #cbd5e1;border-radius:10px;background:#fafcff}
.pneu-preview{font-size:14px;line-height:1.45}
.pneu-preview strong{display:block;margin-bottom:6px}
.pneu-chip{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;margin-right:6px;margin-bottom:6px}
.pneu-simple-table{width:100%;border-collapse:collapse;font-size:14px}
.pneu-simple-table th,.pneu-simple-table td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
.pneu-simple-table th{font-size:12px;text-transform:uppercase;color:#64748b;background:#f8fafc}
.pneu-img-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-top:12px}
.pneu-img-item{position:relative;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;background:#f8fafc;aspect-ratio:1/1}
.pneu-img-item img{width:100%;height:100%;object-fit:cover;cursor:pointer}
.pneu-img-actions{position:absolute;bottom:0;left:0;right:0;background:rgba(255,255,255,0.9);padding:6px;display:flex;justify-content:space-between;align-items:center;opacity:0;transition:opacity .2s}
.pneu-img-item:hover .pneu-img-actions{opacity:1}
.pneu-cover-label{font-size:10px;font-weight:700;color:#1e40af;display:flex;align-items:center;gap:4px;cursor:pointer}
.pneu-cover-label input{margin:0}
.pneu-img-del{background:#fee2e2;color:#991b1b;border:0;width:24px;height:24px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px}
.pneu-img-del:hover{background:#fecaca}
.pneu-is-cover-badge{position:absolute;top:6px;left:6px;background:#2563eb;color:#fff;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:800;text-transform:uppercase;box-shadow:0 2px 4px rgba(0,0,0,0.1)}
@media (max-width: 1200px){.pneu-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.pneu-switches{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width: 980px){.pneu-grid{grid-template-columns:1fr}.pneu-fields{grid-template-columns:1fr}.pneu-switches{grid-template-columns:1fr}.pneu-wrap.is-embedded{padding:0 4px 4px}}
</style>

<div class="pneu-wrap<?php echo IS_EMBEDDED_FORM ? ' is-embedded' : ''; ?>">
    <?php if (!IS_EMBEDDED_FORM): ?>
    <div class="pneu-head">
        <div>
            <h1>Neue Pendenz</h1>
            <div class="pneu-note">Separates Formular. Tabelle und Dashboard bleiben unberührt.</div>
        </div>
        <a class="pneu-back" href="<?php echo h($prefix . 'pages/pendenzen.php'); ?>">← Zur Pendenzenliste</a>
    </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="pneu-msg ok"><?php echo h($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="pneu-msg err"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form id="pendenzForm" method="post" action="<?php echo h($prefix . 'pages/pendenz_neu.php'); ?>" enctype="multipart/form-data" data-offline-sync>
        <?php if (isset($editPendenzId) && $editPendenzId): ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="pendenz_id" value="<?php echo (int)$editPendenzId; ?>">
        <?php elseif (isset($_GET['edit_id'])): ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="pendenz_id" value="<?php echo (int)$_GET['edit_id']; ?>">
        <?php else: ?>
            <input type="hidden" name="form_action" value="create">
        <?php endif; ?>
        <div class="pneu-grid">
            <div class="pneu-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
                    <h2 style="margin:0">1. Grunddaten</h2>
                    <button type="submit" class="pneu-btn" style="background:#059669;border-color:#047857">💾 Speichern</button>
                </div>
                <div class="pneu-fields">
                    <div class="pneu-field full">
                        <label for="vorgangsart_id">Vorgangsart</label>
                        <select name="vorgangsart_id" id="vorgangsart_id" required>
                            <option value="">— auswählen —</option>
                            <?php foreach ($arten as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['vorgangsart_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h(trim((string) ($row['icon'] ?? '') . ' ' . (string) ($row['name'] ?? ''))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="projekt_id">Projekt</label>
                        <select name="projekt_id" id="projekt_id">
                            <option value="">— kein Projekt —</option>
                            <?php foreach ($projekte as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['projekt_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="objekt_id">Objekt</label>
                        <select name="objekt_id" id="objekt_id">
                            <option value="">— kein Objekt —</option>
                            <?php foreach ($objekte as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" data-projekt-id="<?php echo (int) ($row['projekt_id'] ?? 0); ?>" <?php echo ((int) ($input['objekt_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field">
                        <label for="wohnung_id">Wohnung</label>
                        <div style="display:flex; gap:8px;">
                            <select name="wohnung_id" id="wohnung_id" style="flex:1;">
                                <option value="">— keine Wohnung —</option>
                                <?php foreach ($wohnungen as $row): ?>
                                    <option value="<?php echo (int) $row['id']; ?>" data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['wohnung_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="openPlanPicker()" style="padding: 0 12px; font-size: 13px; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer;">📍 Auf Plan markieren</button>
                        </div>
                        <input type="hidden" name="plan_id" id="plan_id" value="<?php echo (int)($input['plan_id'] ?? 0) ?: ''; ?>">
                        <input type="hidden" name="pin_x" id="pin_x" value="<?php echo h((string)($input['pin_x'] ?? '')); ?>">
                        <input type="hidden" name="pin_y" id="pin_y" value="<?php echo h((string)($input['pin_y'] ?? '')); ?>">
                        <input type="hidden" name="plan_pins" id="plan_pins" value="<?php echo h((string)($input['plan_pins'] ?? '[]')); ?>">
                        <input type="hidden" name="plan_snapshot_data" id="plan_snapshot_data" value="">
                    </div>

                    <div class="pneu-field">
                        <label for="raum_id">📍 Raum</label>
                        <select name="raum_id" id="raum_id">
                            <option value="">— kein Raum —</option>
                            <?php foreach ($raeume as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" data-wohnung-id="<?php echo (int) ($row['wohnung_id'] ?? 0); ?>" <?php echo ((int) ($input['raum_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="pneu-field full">
                        <label for="zustaendig_id">Empfänger</label>
                        <select name="zustaendig_id" id="zustaendig_id" required>
                            <option value="">— auswählen —</option>
                            <?php foreach ($users as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['zustaendig_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['label']); ?></option>
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
                    <div style="margin-top:10px">
                        <span class="pneu-note" id="label_preview_titel">Titel</span>
                        <div id="preview_titel">—</div>
                    </div>
                    <div style="margin-top:10px">
                        <span class="pneu-note" id="label_preview_kurz">Kurzbeschreibung</span>
                        <div id="preview_kurz">—</div>
                    </div>
                    <div style="margin-top:10px">
                        <span class="pneu-note" id="label_preview_beschreibung">Beschreibung</span>
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
                        <input type="text" name="titel_manuell" id="titel_manuell" value="<?php echo h($input['titel_manuell'] ?? ''); ?>" placeholder="z. B. Badzimmer, Eingang, Terrasse ...">
                    </div>
                    <div class="pneu-field full">
                        <label for="kurzbeschreibung_manuell">Kurzbeschreibung manuell</label>
                        <input type="text" name="kurzbeschreibung_manuell" id="kurzbeschreibung_manuell" value="<?php echo h($input['kurzbeschreibung_manuell'] ?? ''); ?>" placeholder="z. B. bitte prüfen, Mangel sichtbar, Termin nötig ...">
                    </div>
                    <div class="pneu-field full">
                        <label for="beschreibung_manuell">Beschreibung / Notiz manuell</label>
                        <textarea name="beschreibung_manuell" id="beschreibung_manuell" placeholder="Freier Text für Details."><?php echo h($input['beschreibung_manuell'] ?? ''); ?></textarea>
                    </div>
                    <div class="pneu-field full">
                        <label for="notiz">Interne Notiz</label>
                        <textarea name="notiz" id="notiz" placeholder="Optional."><?php echo h($input['notiz'] ?? ''); ?></textarea>
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
                                    <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) ($input['bkp_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h(trim((string) ($row['code'] ?? '') . ' ' . (string) ($row['bezeichnung'] ?? ''))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="pneu-note" id="bkp_hinweis"></div>
                        </div>
                        <div class="pneu-field full">
                            <label for="bkp_kategorie_id">BKP Titel</label>
                            <select name="bkp_kategorie_id" id="bkp_kategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
                        </div>
                        <div class="pneu-field full">
                            <label for="bkp_text_id">BKP Beschreibung</label>
                            <select name="bkp_text_id" id="bkp_text_id">
                                <option value="">— auswählen —</option>
                            </select>
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
                                    <option value="<?php echo (int) $row['id']; ?>" data-projekt-id="<?php echo (int) ($row['projekt_id'] ?? 0); ?>" data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['mieter_kategorie_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pneu-field full">
                            <label for="mieter_subkategorie_id">Mieter-Kurzbeschreibung</label>
                            <select name="mieter_subkategorie_id" id="mieter_subkategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
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
                                    <option value="<?php echo (int) $row['id']; ?>" data-projekt-id="<?php echo (int) ($row['projekt_id'] ?? 0); ?>" data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['vermieter_kategorie_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pneu-field full">
                            <label for="vermieter_subkategorie_id">Vermieter-Kurzbeschreibung</label>
                            <select name="vermieter_subkategorie_id" id="vermieter_subkategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="block_keine_welt" class="pneu-note">Diese Vorgangsart hat aktuell keine zusätzliche Vorlagen-Auswahl.</div>
            </div>
        </div>

        <div style="height:10px"></div>

        <div class="pneu-grid">
            <div class="pneu-card">
                <h2>5. Zeit</h2>
                <div class="pneu-fields">
                    <div class="pneu-field">
                        <label for="startdatum">Startdatum</label>
                        <input type="date" name="startdatum" id="startdatum" value="<?php echo h($input['startdatum']); ?>">
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
                        <input type="text" name="dauer" id="dauer" value="<?php echo h($input['dauer']); ?>" placeholder="z. B. 2 = 2 Tage ab Startdatum">
                    </div>
                </div>
            </div>

            <div class="pneu-card">
                <h2>6. Optionen</h2>
                <div class="pneu-fields">
                    <div class="pneu-field">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <?php foreach (['offen','in Bearbeitung','erledigt','archiviert'] as $status): ?>
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
                    <a href="<?php echo h($prefix . 'pages/pendenz_neu.php'); ?>" class="pneu-btn alt">Neu leeren</a>
                    <a href="<?php echo h($prefix . 'pages/pendenzen.php'); ?>" class="pneu-btn alt">Zur Liste</a>
                </div>
            </div>
        </div>

        <section class="pneu-card pneu-span-3">
            <h3>5. Bilder</h3>
            
            <?php 
            $images = array_filter($existingAttachments, fn($a) => $a['typ'] === 'image');
            if (!empty($images)): ?>
            <div class="pneu-img-grid">
                <?php foreach ($images as $img): ?>
                <div class="pneu-img-item" id="att-item-<?php echo $img['id']; ?>">
                    <?php if (!empty($img['is_cover'])): ?>
                        <div class="pneu-is-cover-badge">Cover</div>
                    <?php endif; ?>
                    <img src="<?php echo h($prefix . $img['pfad']); ?>" onclick="window.open(this.src,'_blank')" title="<?php echo h($img['titel']); ?>">
                    <div class="pneu-img-actions">
                        <label class="pneu-cover-label">
                            <input type="radio" name="set_cover_id" value="<?php echo $img['id']; ?>" <?php echo !empty($img['is_cover']) ? 'checked' : ''; ?> onchange="updateCover(<?php echo $img['id']; ?>)">
                            Cover
                        </label>
                        <button type="button" class="pneu-img-del" onclick="deleteAttachment(<?php echo $img['id']; ?>)" title="Löschen">🗑️</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:20px"></div>
            <?php endif; ?>

            <div class="pneu-field">
                <label for="bilder">Bilder hinzufügen</label>
                <input type="file" id="bilder" name="bilder[]" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" multiple>
                <small>Erlaubt: JPG, JPEG, PNG, GIF, WEBP · max. 15 MB pro Datei</small>
            </div>
        </section>

        <section class="pneu-card pneu-span-3">
            <h3>6. Dokumente</h3>

            <?php 
            $docs = array_filter($existingAttachments, fn($a) => $a['typ'] !== 'image');
            if (!empty($docs)): ?>
            <div class="pneu-box" style="margin-bottom:12px">
                <table class="pneu-simple-table">
                    <?php foreach ($docs as $doc): ?>
                    <tr id="att-item-<?php echo $doc['id']; ?>">
                        <td><a href="<?php echo h($prefix . $doc['pfad']); ?>" target="_blank"><?php echo h($doc['titel']); ?></a></td>
                        <td style="text-align:right">
                            <button type="button" class="pneu-img-del" onclick="deleteAttachment(<?php echo $doc['id']; ?>)">🗑️</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <div class="pneu-field">
                <label for="dokumente">Dokumente hinzufügen</label>
                <input type="file" id="dokumente" name="dokumente[]" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" multiple>
                <small>Erlaubt: PDF, DOC, DOCX, XLS, XLSX, TXT, ZIP · max. 15 MB pro Datei</small>
            </div>
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
const allObjekte = <?php echo json_encode($objekte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const allWohnungen = <?php echo json_encode($wohnungen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const allRaeume = <?php echo json_encode($raeume, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const initialValues = <?php echo json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const duplicateId = <?php echo json_encode($duplicateId); ?>;
const editPendenzId = <?php echo json_encode($editPendenzId); ?>;

function deleteAttachment(id) {
    if (!confirm('Diesen Anhang wirklich löschen?')) return;
    
    const formData = new FormData();
    formData.append('form_action', 'delete_attachment');
    formData.append('attachment_id', id);
    formData.append('pendenz_id', editPendenzId);

    fetch('pendenz_neu.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(() => {
        const el = document.getElementById('att-item-' + id);
        if (el) el.remove();
    })
    .catch(e => {
        console.error(e);
        alert('Fehler beim Löschen.');
    });
}

function updateCover(id) {
    const formData = new FormData();
    formData.append('form_action', 'set_cover');
    formData.append('attachment_id', id);
    formData.append('pendenz_id', editPendenzId);

    fetch('pendenz_neu.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(() => {
        // Optional: UI Feedback
        window.location.reload(); // Reload to update badges
    })
    .catch(e => {
        console.error(e);
        alert('Fehler beim Setzen des Covers.');
    });
}

const editId = <?php echo json_encode($editPendenzId ?? (isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null)); ?>;

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

function filterObjekte(initVal = null) {
    const projektId = elProjekt.value;
    const current = initVal?.objekt_id ?? elObjekt.value;
    const opts = allObjekte
        .filter(o => !projektId || String(o.projekt_id) === String(projektId))
        .map(o => ({ value: o.id, label: o.name }));
    refillSelect(elObjekt, opts, '— kein Objekt —', current);
    filterWohnungen(initVal);
}

function filterWohnungen(initVal = null) {
    const objektId = elObjekt.value;
    const current = initVal?.wohnung_id ?? elWohnung.value;
    const opts = allWohnungen
        .filter(w => !objektId || String(w.objekt_id) === String(objektId))
        .map(w => ({ value: w.id, label: w.name }));
    refillSelect(elWohnung, opts, '— keine Wohnung —', current);
    filterRaeume(initVal);
}

function filterRaeume(initVal = null) {
    if (!elRaum) return;
    const wohnungId = elWohnung.value;
    const current = initVal?.raum_id ?? elRaum.value;
    const opts = allRaeume
        .filter(r => !wohnungId || String(r.wohnung_id) === String(wohnungId))
        .map(r => ({ value: r.id, label: r.name }));
    refillSelect(elRaum, opts, '— kein Raum —', current);
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

function filterEmpfaenger(prefill = true, initVal = null) {
    const art = selectedArt();
    const current = initVal?.zustaendig_id ?? elEmpf.value;
    const defaults = firstDefaultForArt(elArt.value);
    const opts = users
        .filter(user => userMatchesArt(user, art))
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

function filterBkpOptions(prefill = true, initVal = null) {
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

    const current = initVal?.bkp_id ?? elBkp.value;
    refillSelect(elBkp, allowed.map(row => ({ value: row.id, label: `${row.code} ${row.bezeichnung}`.trim() })), '— keine BKP —', current);

    const def = firstDefaultForArt(elArt.value);
    if (prefill && def && def.bkp_id && !current && String(def.bkp_mode || '') === 'single') {
        elBkp.value = String(def.bkp_id);
    }
    refillBkpKategorien(prefill, initVal);
}

function refillBkpKategorien(prefill = true, initVal = null) {
    const list = bkpKategorienByBkp[String(elBkp.value)] || [];
    const current = initVal?.bkp_kategorie_id ?? elBkpKategorie.value;
    refillSelect(elBkpKategorie, list.map(row => ({ value: row.id, label: row.name })), '— auswählen —', current);
    refillBkpTexts(initVal);
}

function refillBkpTexts(initVal = null) {
    const list = bkpTextsByKategorie[String(elBkpKategorie.value)] || [];
    const current = initVal?.bkp_text_id ?? elBkpText.value;
    refillSelect(elBkpText, list.map(row => ({ value: row.id, label: row.text })), '— auswählen —', current);
}

function refillSubKategorien(initVal = null) {
    const projektId = elProjekt.value;
    const objektId = elObjekt.value;

    // Filter Mieter Categories
    const mKats = mieterKategorien
        .filter(row => {
            const p = String(row.projekt_id || '0').toLowerCase();
            const o = String(row.objekt_id || '0').toLowerCase();
            if (projektId && p !== '0' && p !== 'null' && p !== '' && p !== String(projektId)) return false;
            if (objektId && o !== '0' && o !== 'null' && o !== '' && o !== String(objektId)) return false;
            return true;
        })
        .map(row => ({ value: row.id, label: row.name }));
    refillSelect(elMieterKat, mKats, '— keine Vorlage —', initVal?.mieter_kategorie_id ?? elMieterKat.value);

    // Filter Vermieter Categories
    const vKats = vermieterKategorien
        .filter(row => {
            const p = String(row.projekt_id || '0').toLowerCase();
            const o = String(row.objekt_id || '0').toLowerCase();
            if (projektId && p !== '0' && p !== 'null' && p !== '' && p !== String(projektId)) return false;
            if (objektId && o !== '0' && o !== 'null' && o !== '' && o !== String(objektId)) return false;
            return true;
        })
        .map(row => ({ value: row.id, label: row.name }));
    refillSelect(elVermieterKat, vKats, '— auswählen —', initVal?.vermieter_kategorie_id ?? elVermieterKat.value);

    const mSubs = mieterSubkategorien
        .filter(row => String(row.kategorie_id) === String(elMieterKat.value))
        .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
    refillSelect(elMieterSub, mSubs, '— auswählen —', initVal?.mieter_subkategorie_id ?? elMieterSub.value);

    const vSubs = vermieterSubkategorien
        .filter(row => String(row.kategorie_id) === String(elVermieterKat.value))
        .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
    refillSelect(elVermieterSub, vSubs, '— auswählen —', initVal?.vermieter_subkategorie_id ?? elVermieterSub.value);
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

function applyDefaultsFromArt() {
    const art = selectedArt();
    const def = firstDefaultForArt(elArt.value);
    if (!art) return;

    if (art.default_projekt_id) elProjekt.value = String(art.default_projekt_id);
    if (def?.projekt_id) elProjekt.value = String(def.projekt_id);
    filterObjekte();

    if (art.default_objekt_id) elObjekt.value = String(art.default_objekt_id);
    if (def?.objekt_id) elObjekt.value = String(def.objekt_id);
    filterWohnungen();

    if (art.default_wohnung_id) elWohnung.value = String(art.default_wohnung_id);
    if (def?.wohnung_id) elWohnung.value = String(def.wohnung_id);
    filterRaeume();

    if (art.default_raum_id) elRaum.value = String(art.default_raum_id);
    if (def?.raum_id) elRaum.value = String(def.raum_id);

    filterEmpfaenger(true);
    filterBkpOptions(true);
    refillSubKategorien();
    updatePreview();
}

function currentDynamic() {
    const art = selectedArt();
    const welt = deriveVorlagenWeltJs(art);
    let titel = '';
    let kurz = '';
    let beschreibung = '';

    if (welt === 'bkp') {
        const bkpRow = bkpCodes.find(row => String(row.id) === String(elBkp.value));
        if (bkpRow) titel = (bkpRow.code + ' ' + bkpRow.bezeichnung).trim();

        const katList = bkpKategorienByBkp[String(elBkp.value)] || [];
        const kat = katList.find(row => String(row.id) === String(elBkpKategorie.value));
        if (kat) titel = kat.name || titel;

        const list = bkpTextsByKategorie[String(elBkpKategorie.value)] || [];
        const txt = list.find(row => String(row.id) === String(elBkpText.value));
        if (txt) kurz = txt.text || '';
    } else if (welt === 'mieter') {
        const cat = mieterKategorien.find(row => String(row.id) === String(elMieterKat.value));
        if (cat) titel = cat.name || '';

        const sub = mieterSubkategorien.find(row => String(row.id) === String(elMieterSub.value));
        if (sub) {
            kurz = sub.name || '';
            beschreibung = sub.beschreibung || '';
        }
    } else if (welt === 'vermieter') {
        const cat = vermieterKategorien.find(row => String(row.id) === String(elVermieterKat.value));
        if (cat) titel = cat.name || '';

        const sub = vermieterSubkategorien.find(row => String(row.id) === String(elVermieterSub.value));
        if (sub) {
            kurz = sub.name || '';
            beschreibung = sub.beschreibung || '';
        }
    }

    return {titel, kurz, beschreibung};
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
    const art = selectedArt();
    const welt = deriveVorlagenWeltJs(art);
    const dyn = currentDynamic();

    // Update Labels
    const lTitel = document.getElementById('label_preview_titel');
    const lKurz = document.getElementById('label_preview_kurz');
    
    if (welt === 'mieter') {
        if (lTitel) lTitel.textContent = 'Mieter-Kategorie';
        if (lKurz) lKurz.textContent = 'Mieter-Kurzbeschreibung';
    } else if (welt === 'vermieter') {
        if (lTitel) lTitel.textContent = 'Vermieter-Kategorie';
        if (lKurz) lKurz.textContent = 'Vermieter-Kurzbeschreibung';
    } else if (welt === 'bkp') {
        if (lTitel) lTitel.textContent = 'BKP-Kategorie';
        if (lKurz) lKurz.textContent = 'BKP-Kurztext';
    } else {
        if (lTitel) lTitel.textContent = 'Titel';
        if (lKurz) lKurz.textContent = 'Kurzbeschreibung';
    }
    
    // Title logic: Template + Manual (if present)
    let t = dyn.titel || '';
    if (elTitelMan.value.trim()) {
        t = t ? t + ' | ' + elTitelMan.value.trim() : elTitelMan.value.trim();
    }
    previewTitel.textContent = t || '—';

    // Kurz logic
    let k = dyn.kurz || '';
    if (elKurzMan.value.trim()) {
        k = k ? k + ' — ' + elKurzMan.value.trim() : elKurzMan.value.trim();
    }
    previewKurz.textContent = k || '—';

    // Beschreibung logic
    let b = dyn.beschreibung || '';
    if (elBeschrMan.value.trim()) {
        b = b ? b + "\n\nManuell:\n" + elBeschrMan.value.trim() : elBeschrMan.value.trim();
    }
    previewBeschreibung.innerHTML = b ? b.replace(/\n/g, '<br>') : '—';
}

function toggleCard(btn) {
    const content = btn.nextElementSibling;
    const icon = btn.querySelector('.pneu-toggle-icon');
    const isHidden = content.style.display === 'none';
    content.style.display = isHidden ? 'block' : 'none';
    if (icon) icon.style.transform = isHidden ? 'rotate(0deg)' : 'rotate(-90deg)';
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
elProjekt.addEventListener('change', () => { filterObjekte(); refillSubKategorien(); });
elObjekt.addEventListener('change', () => { filterWohnungen(); refillSubKategorien(); });
elWohnung.addEventListener('change', () => { filterRaeume(); });
elEmpf.addEventListener('change', () => { filterBkpOptions(false); refillSubKategorien(); updatePreview(); });
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

filterObjekte(initialValues);
applyArtState();
filterEmpfaenger(false, initialValues);
filterBkpOptions(false, initialValues);
// First pass: fill categories and select the saved value
refillSubKategorien(initialValues);
// Second pass: after the category select has its value, fill the subcategories
// with the correct saved selection (needed because block was hidden on first pass)
if (editId || duplicateId) {
    // Ensure the selected category value is properly reflected in subcategory dropdowns
    const mSubsInit = mieterSubkategorien
        .filter(row => String(row.kategorie_id) === String(elMieterKat.value))
        .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
    refillSelect(elMieterSub, mSubsInit, '— auswählen —', initialValues?.mieter_subkategorie_id);

    const vSubsInit = vermieterSubkategorien
        .filter(row => String(row.kategorie_id) === String(elVermieterKat.value))
        .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
    refillSelect(elVermieterSub, vSubsInit, '— auswählen —', initialValues?.vermieter_subkategorie_id);
}
updatePreview();
if (!duplicateId && !editId) {
    applyDefaultsFromArt();
}
</script>

<!-- Plan Picker Modal -->
<div id="planPickerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.8); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; width:95%; max-width:1200px; height:90%; border-radius:16px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="padding:16px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
            <h3 style="margin:0; font-size:20px; color:#0f172a;">📍 Ort auf Plan markieren</h3>
            <div style="display:flex; gap:12px; align-items:center;">
                <select id="planSelect" style="padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; min-width:200px;" onchange="loadSelectedPlan()">
                    <option value="">-- Plan laden --</option>
                </select>
                <button type="button" onclick="closePlanPicker()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
            </div>
        </div>
        
        <div style="flex:1; overflow:auto; display:flex; background:#e2e8f0; position:relative;" id="planAreaWrap">
            <!-- Toolbar -->
            <div style="position:fixed; bottom:80px; left:50%; transform:translateX(-50%); background:white; padding:8px; border-radius:30px; box-shadow:0 4px 15px rgba(0,0,0,0.3); z-index:100; display:flex; gap:10px; border:1px solid #e2e8f0;">
                <button type="button" class="draw-tool active" data-tool="pin" onclick="setDrawTool('pin')" style="background:#ef4444; color:white; border:none; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">📍 Pin</button>
                <button type="button" class="draw-tool" data-tool="line" onclick="setDrawTool('line')" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">✏️ Linie</button>
                <button type="button" class="draw-tool" data-tool="rect" onclick="setDrawTool('rect')" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">🔲 Fläche</button>
                <button type="button" onclick="undoLastDraw()" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">↩️ Zurück</button>
            </div>
            
            <div id="planContainer" style="position:relative; margin:auto; cursor:crosshair; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); background:#fff; display:none; flex-shrink:0;">
                <img id="planImage" style="display:block; max-width:100%; max-height:100%; object-fit:contain; pointer-events:none; user-select:none;" crossOrigin="anonymous">
                <svg id="drawOverlay" viewBox="0 0 100 100" preserveAspectRatio="none" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:40; overflow:visible;"></svg>
            </div>
            <div id="planLoading" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); display:none; color:#475569; font-weight:600;">Pläne werden geladen...</div>
            <div id="planEmpty" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); display:none; color:#64748b; text-align:center;">Keine Pläne für dieses Projekt hinterlegt.<br><br><button type="button" onclick="closePlanPicker()" class="pneu-btn alt">Schliessen</button></div>
        </div>
        
        <div style="padding:16px 24px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
            <div style="color:#64748b; font-size:14px;" id="planStatusText">Bitte klicke auf den Plan, um einen Pin zu setzen.</div>
            <div style="display:flex; gap:12px;">
                <button type="button" class="pneu-btn alt" onclick="clearPlanPin()">Pin entfernen</button>
                <button type="button" class="pneu-btn" onclick="applyPlanPin()" id="applyPinBtn" disabled>Pin übernehmen</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentPlans = [];
let currentZones = [];
let activePlanId = null;
let currentPins = []; // Array of { plan_id, x, y, wohnung_id, type, w, h, points }
let currentDrawTool = 'pin';
let isDrawing = false;
let currentDrawObj = null;

function setDrawTool(tool) {
    currentDrawTool = tool;
    document.querySelectorAll('.draw-tool').forEach(btn => {
        btn.style.background = '#f8fafc';
        btn.style.color = '#334155';
        btn.style.border = '1px solid #cbd5e1';
    });
    const activeBtn = document.querySelector(`.draw-tool[data-tool="${tool}"]`);
    if (activeBtn) {
        activeBtn.style.background = '#ef4444';
        activeBtn.style.color = 'white';
        activeBtn.style.border = 'none';
    }
}

function undoLastDraw() {
    if (currentPins.length > 0) {
        currentPins.pop();
        loadSelectedPlan();
    }
}

function openPlanPicker() {
    const projSelect = document.getElementById('projekt_id');
    const projId = projSelect ? projSelect.value : null;
    
    if (!projId) {
        alert("Bitte wähle zuerst ein Projekt aus.");
        return;
    }
    
    document.getElementById('planPickerModal').style.display = 'flex';
    document.getElementById('planLoading').style.display = 'block';
    document.getElementById('planContainer').style.display = 'none';
    document.getElementById('planEmpty').style.display = 'none';
    
    // Load pre-existing pins
    try {
        currentPins = JSON.parse(document.getElementById('plan_pins').value || '[]');
    } catch(e) {
        currentPins = [];
    }
    
    // Fallback: If legacy single pin exists but no pins array, migrate it
    if (currentPins.length === 0 && document.getElementById('pin_x').value && document.getElementById('plan_id').value) {
        currentPins.push({
            plan_id: document.getElementById('plan_id').value,
            x: parseFloat(document.getElementById('pin_x').value),
            y: parseFloat(document.getElementById('pin_y').value),
            wohnung_id: null,
            type: 'pin'
        });
    }
    // Normalize old pins to have type
    currentPins.forEach(p => { if (!p.type) p.type = 'pin'; });
    
    activePlanId = currentPins.length > 0 ? currentPins[currentPins.length-1].plan_id : null;
    
    const ajaxUrl = <?= json_encode($prefix . 'pages/ajax_get_plans.php') ?>;
    fetch(ajaxUrl + '?projekt_id=' + projId)
        .then(res => res.json())
        .then(data => {
            document.getElementById('planLoading').style.display = 'none';
            if (data.error || !data.plaene || data.plaene.length === 0) {
                document.getElementById('planEmpty').innerHTML = "Keine Pläne für dieses Projekt hinterlegt.<br><br><button type='button' onclick='closePlanPicker()' class='pneu-btn alt'>Schliessen</button>";
                document.getElementById('planEmpty').style.display = 'block';
                return;
            }
            
            currentPlans = data.plaene;
            currentZones = data.zonen;
            
            let allowedPlans = currentPlans;
            const wDropdown = document.getElementById('wohnung_id').value;
            
            if (wDropdown) {
                const wZones = currentZones.filter(z => z.wohnung_id == wDropdown);
                if (wZones.length > 0) {
                    const validPlanIds = wZones.map(z => z.plan_id);
                    allowedPlans = currentPlans.filter(p => validPlanIds.includes(p.id));
                    if (!activePlanId || !validPlanIds.includes(activePlanId)) {
                        activePlanId = validPlanIds[0];
                    }
                } else {
                    allowedPlans = [];
                }
            }
            
            if (allowedPlans.length === 0) {
                if (wDropdown) {
                    document.getElementById('planEmpty').innerHTML = "Für die ausgewählte Wohnung wurde noch keine Zone auf einem Plan hinterlegt.<br><br><button type='button' onclick='closePlanPicker()' class='pneu-btn alt'>Schliessen</button>";
                }
                document.getElementById('planEmpty').style.display = 'block';
                return;
            }
            
            const sel = document.getElementById('planSelect');
            sel.innerHTML = '';
            allowedPlans.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name;
                if (p.id == activePlanId) opt.selected = true;
                sel.appendChild(opt);
            });
            
            if (!activePlanId || !allowedPlans.find(p => p.id == activePlanId)) {
                activePlanId = allowedPlans[0].id;
            }
            
            loadSelectedPlan();
        })
        .catch(err => {
            console.error(err);
            document.getElementById('planLoading').style.display = 'none';
            document.getElementById('planEmpty').innerHTML = "Fehler beim Laden.";
            document.getElementById('planEmpty').style.display = 'block';
        });
}

function closePlanPicker() {
    document.getElementById('planPickerModal').style.display = 'none';
}

function loadSelectedPlan() {
    const sel = document.getElementById('planSelect');
    activePlanId = sel.value;
    const plan = currentPlans.find(p => p.id == activePlanId);
    
    if (!plan) return;
    
    const img = document.getElementById('planImage');
    const cont = document.getElementById('planContainer');
    const wrap = document.getElementById('planAreaWrap');
    
    wrap.style.overflow = 'auto';
    
    img.onload = function() {
        cont.style.display = 'inline-block';
        cont.style.width = '100%';
        cont.style.minWidth = '100%';
        cont.style.maxWidth = 'none';
        img.style.maxHeight = 'none';
        img.style.width = '100%';
        img.style.height = 'auto';
        
        // Remove old zones and pins
        cont.querySelectorAll('.picker-zone').forEach(e => e.remove());
        cont.querySelectorAll('.picker-multi-pin').forEach(e => e.remove());
        
        // Draw zones
        const pZones = currentZones.filter(z => z.plan_id == activePlanId);
        pZones.forEach(z => {
            const div = document.createElement('div');
            div.className = 'picker-zone';
            div.id = 'picker-zone-' + z.wohnung_id;
            div.style.position = 'absolute';
            div.style.left = z.x + '%';
            div.style.top = z.y + '%';
            div.style.width = z.w + '%';
            div.style.height = z.h + '%';
            div.style.border = '2px dashed rgba(59, 130, 246, 0.4)';
            div.style.background = 'rgba(59, 130, 246, 0.05)';
            div.style.pointerEvents = 'none';
            div.style.transition = 'all 0.2s';
            cont.appendChild(div);
        });
        
        // Render all pins and drawings
        const svg = document.getElementById('drawOverlay');
        svg.innerHTML = '';
        currentPins.forEach((pin, index) => {
            if (pin.plan_id == activePlanId) {
                if (pin.type === 'pin' || !pin.type) {
                    renderPin(pin.x, pin.y, index);
                } else if (pin.type === 'line') {
                    renderLine(pin, index, svg);
                } else if (pin.type === 'rect') {
                    renderRect(pin, index, svg);
                }
            }
        });
        
        if (currentPins.length > 0) {
            document.getElementById('applyPinBtn').disabled = false;
            document.getElementById('planStatusText').innerHTML = `<b>${currentPins.length} Markierung(en)</b> gesetzt. Klicke auf eine bestehende, um sie zu entfernen.`;
        } else {
            document.getElementById('applyPinBtn').disabled = true;
            document.getElementById('planStatusText').textContent = "Bitte klicke auf den Plan, um Markierungen zu setzen.";
            highlightZone(null);
        }
        
        // AUTO-ZOOM logic via container width and scroll
        const selectedWohnungDropdown = document.getElementById('wohnung_id').value;
        if (selectedWohnungDropdown) {
            const tz = pZones.find(z => z.wohnung_id == selectedWohnungDropdown);
            if (tz) {
                const maxScaleW = 100 / tz.w * 0.9;
                const maxScaleH = 100 / tz.h * 0.9;
                const scale = Math.max(1, Math.min(maxScaleW, maxScaleH));
                
                cont.style.width = (scale * 100) + '%';
                
                setTimeout(() => {
                    const cx = (tz.x + tz.w / 2) / 100 * cont.offsetWidth;
                    const cy = (tz.y + tz.h / 2) / 100 * cont.offsetHeight;
                    wrap.scrollLeft = cx - (wrap.clientWidth / 2);
                    wrap.scrollTop = cy - (wrap.clientHeight / 2);
                }, 50);
                
                highlightZone(tz.wohnung_id);
                
                cont.ondblclick = function() {
                    cont.style.width = '100%';
                    wrap.scrollLeft = 0;
                    wrap.scrollTop = 0;
                };
            } else {
                cont.style.width = '100%';
                cont.ondblclick = null;
            }
        } else {
            cont.style.width = '100%';
            cont.ondblclick = null;
        }
    };
    img.src = plan.url;
}

function renderPin(x, y, index) {
    const cont = document.getElementById('planContainer');
    const div = document.createElement('div');
    div.className = 'picker-multi-pin';
    div.style.position = 'absolute';
    div.style.left = x + '%';
    div.style.top = y + '%';
    div.style.transform = 'translate(-50%, -100%)';
    div.style.zIndex = '50';
    div.style.cursor = 'pointer';
    div.innerHTML = `
        <svg viewBox="0 0 24 24" width="28" height="28" style="filter: drop-shadow(0 4px 4px rgba(0,0,0,0.4));">
            <path fill="#ef4444" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" stroke="#ffffff" stroke-width="1.5"/>
            <circle cx="12" cy="9" r="3.5" fill="#ffffff"/>
        </svg>
    `;
    
    // Click on pin to remove it
    div.addEventListener('click', function(e) {
        e.stopPropagation(); // prevent adding a new pin
        currentPins.splice(index, 1);
        loadSelectedPlan(); // Re-render everything to update indices cleanly
    });
    
    cont.appendChild(div);
}

function renderLine(line, index, svg) {
    if (!line.points || line.points.length === 0) return;
    const pts = line.points.map(p => `${p.x},${p.y}`).join(' ');
    const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    poly.setAttribute('points', pts);
    poly.setAttribute('fill', 'none');
    poly.setAttribute('stroke', '#ef4444');
    poly.setAttribute('stroke-width', '4');
    poly.setAttribute('stroke-linecap', 'round');
    poly.setAttribute('stroke-linejoin', 'round');
    poly.setAttribute('vector-effect', 'non-scaling-stroke'); // Keeps it constant thickness!
    poly.style.filter = 'drop-shadow(0px 3px 4px rgba(0,0,0,0.5))';
    poly.style.pointerEvents = 'auto';
    poly.style.cursor = 'pointer';
    poly.addEventListener('click', (e) => { e.stopPropagation(); currentPins.splice(index, 1); loadSelectedPlan(); });
    svg.appendChild(poly);
}

function renderRect(rectObj, index, svg) {
    if (!rectObj.w || !rectObj.h) return;
    const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    rect.setAttribute('x', rectObj.x);
    rect.setAttribute('y', rectObj.y);
    rect.setAttribute('width', rectObj.w);
    rect.setAttribute('height', rectObj.h);
    rect.setAttribute('fill', 'rgba(239, 68, 68, 0.3)');
    rect.setAttribute('stroke', '#ef4444');
    rect.setAttribute('stroke-width', '0.5');
    rect.setAttribute('vector-effect', 'non-scaling-stroke');
    rect.style.pointerEvents = 'auto';
    rect.style.cursor = 'pointer';
    rect.addEventListener('click', (e) => { e.stopPropagation(); currentPins.splice(index, 1); loadSelectedPlan(); });
    svg.appendChild(rect);
}

const cont = document.getElementById('planContainer');
cont.addEventListener('mousedown', function(e) {
    if (e.target.closest('.picker-multi-pin') || e.target.tagName === 'polyline' || e.target.tagName === 'rect') return;
    const rect = this.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * 100;
    const y = ((e.clientY - rect.top) / rect.height) * 100;
    
    if (currentDrawTool === 'pin') {
        // Pin logic stays on click/mousedown
        return; 
    }
    
    isDrawing = true;
    if (currentDrawTool === 'line') {
        currentDrawObj = { type: 'line', plan_id: activePlanId, points: [{x, y}], wohnung_id: null };
    } else if (currentDrawTool === 'rect') {
        currentDrawObj = { type: 'rect', plan_id: activePlanId, x: x, y: y, w: 0, h: 0, startX: x, startY: y, wohnung_id: null };
    }
    currentPins.push(currentDrawObj);
    loadSelectedPlan();
});

cont.addEventListener('mousemove', function(e) {
    if (!isDrawing || !currentDrawObj) return;
    const rect = this.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * 100;
    const y = ((e.clientY - rect.top) / rect.height) * 100;
    
    if (currentDrawTool === 'line') {
        currentDrawObj.points.push({x, y});
    } else if (currentDrawTool === 'rect') {
        currentDrawObj.x = Math.min(x, currentDrawObj.startX);
        currentDrawObj.y = Math.min(y, currentDrawObj.startY);
        currentDrawObj.w = Math.abs(x - currentDrawObj.startX);
        currentDrawObj.h = Math.abs(y - currentDrawObj.startY);
    }
    
    // Quick render instead of full load to avoid lag
    const svg = document.getElementById('drawOverlay');
    svg.innerHTML = '';
    currentPins.forEach((pin, index) => {
        if (pin.plan_id == activePlanId) {
            if (pin.type === 'line') renderLine(pin, index, svg);
            else if (pin.type === 'rect') renderRect(pin, index, svg);
        }
    });
});

window.addEventListener('mouseup', function() {
    if (isDrawing) {
        isDrawing = false;
        currentDrawObj = null;
        loadSelectedPlan();
    }
});

cont.addEventListener('click', function(e) {
    if (e.target.closest('.picker-multi-pin') || e.target.tagName === 'polyline' || e.target.tagName === 'rect') return;
    if (currentDrawTool !== 'pin') return;

    const rect = this.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * 100;
    const y = ((e.clientY - rect.top) / rect.height) * 100;
    
    let matchedWohnung = null;
    let matchedObjekt = null;
    const pZones = currentZones.filter(z => z.plan_id == activePlanId);
    for (let z of pZones) {
        if (x >= z.x && x <= (z.x + z.w) && y >= z.y && y <= (z.y + z.h)) {
            matchedWohnung = z.wohnung_id;
            matchedObjekt = z.objekt_id;
            break;
        }
    }
    
    currentPins.push({
        plan_id: activePlanId,
        x: parseFloat(x).toFixed(4),
        y: parseFloat(y).toFixed(4),
        wohnung_id: matchedWohnung,
        type: 'pin'
    });
    
    highlightZone(matchedWohnung);
    
    if (matchedWohnung) {
        // Auto-select dropdown immediately
        const wSelect = document.getElementById('wohnung_id');
        if (wSelect) wSelect.value = matchedWohnung;
        const oSelect = document.getElementById('objekt_id');
        if (oSelect && matchedObjekt) oSelect.value = matchedObjekt;
        document.getElementById('planStatusText').innerHTML = "<span style='color:#10b981; font-weight:600;'>✓ Treffer!</span> Die Wohnung wurde anhand des Pins gesetzt.";
    } else {
        document.getElementById('planStatusText').innerHTML = `<b>${currentPins.length} Markierung(en)</b> gesetzt.`;
    }
    
    loadSelectedPlan(); // Re-render to show the new pin
});

function highlightZone(wohnungId) {
    document.querySelectorAll('.picker-zone').forEach(e => {
        e.style.background = 'rgba(59, 130, 246, 0.05)';
        e.style.borderColor = 'rgba(59, 130, 246, 0.4)';
    });
    if (wohnungId) {
        const el = document.getElementById('picker-zone-' + wohnungId);
        if (el) {
            el.style.background = 'rgba(16, 185, 129, 0.3)';
            el.style.borderColor = '#10b981';
        }
    }
}

function clearPlanPin() {
    currentPins = [];
    document.getElementById('plan_id').value = '';
    document.getElementById('pin_x').value = '';
    document.getElementById('pin_y').value = '';
    document.getElementById('plan_pins').value = '[]';
    loadSelectedPlan();
    document.getElementById('planStatusText').textContent = "Alle Pins wurden entfernt.";
    highlightZone(null);
}

function applyPlanPin() {
    document.getElementById('plan_pins').value = JSON.stringify(currentPins);
    
    // For legacy compat, set the first pin to the legacy columns
    if (currentPins.length > 0) {
        document.getElementById('plan_id').value = currentPins[0].plan_id;
        document.getElementById('pin_x').value = currentPins[0].x;
        document.getElementById('pin_y').value = currentPins[0].y;
        
        // GENERATE SNAPSHOT
        const img = document.getElementById('planImage');
        if (img && img.naturalWidth) {
            const natW = img.naturalWidth;
            const natH = img.naturalHeight;
            
            let zx = 0, zy = 0, zw = 100, zh = 100;
            
            const wDropdown = document.getElementById('wohnung_id').value;
            if (wDropdown) {
                const tz = currentZones.find(z => z.wohnung_id == wDropdown && z.plan_id == activePlanId);
                if (tz) {
                    // We want the snapshot to show the apartment, with a small 5% margin
                    zx = Math.max(0, tz.x - 5);
                    zy = Math.max(0, tz.y - 5);
                    zw = Math.min(100 - zx, tz.w + 10);
                    zh = Math.min(100 - zy, tz.h + 10);
                }
            }
            
            const cropX = (zx / 100) * natW;
            const cropY = (zy / 100) * natH;
            const cropW = (zw / 100) * natW;
            const cropH = (zh / 100) * natH;
            
            const canvas = document.createElement('canvas');
            const maxDim = 1200; // max resolution for snapshot
            let scale = 1;
            if (cropW > maxDim || cropH > maxDim) {
                scale = maxDim / Math.max(cropW, cropH);
            }
            
            canvas.width = cropW * scale;
            canvas.height = cropH * scale;
            
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, cropX, cropY, cropW, cropH, 0, 0, canvas.width, canvas.height);
            
            console.log("[SNAP] Drawing " + currentPins.length + " pins on canvas. activePlanId:", activePlanId);
            currentPins.forEach((pin, idx) => {
                if (String(pin.plan_id) === String(activePlanId)) {
                    if (pin.type === 'line' && pin.points && pin.points.length > 1) {
                        console.log("[SNAP] Drawing line #" + idx);
                        ctx.strokeStyle = '#ef4444';
                        ctx.lineWidth = Math.max(3, 5 * scale);
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';
                        ctx.shadowColor = 'rgba(0,0,0,0.5)';
                        ctx.shadowBlur = 6 * scale;
                        ctx.shadowOffsetY = 3 * scale;
                        ctx.beginPath();
                        pin.points.forEach((pt, i) => {
                            const rx = ((pt.x / 100) * natW - cropX) * scale;
                            const ry = ((pt.y / 100) * natH - cropY) * scale;
                            if (i === 0) ctx.moveTo(rx, ry);
                            else ctx.lineTo(rx, ry);
                        });
                        ctx.stroke();
                        ctx.shadowColor = 'transparent';
                        ctx.shadowBlur = 0;
                        ctx.shadowOffsetY = 0;
                    } else if (pin.type === 'rect') {
                        console.log("[SNAP] Drawing rect #" + idx);
                        const rx = ((pin.x / 100) * natW - cropX) * scale;
                        const ry = ((pin.y / 100) * natH - cropY) * scale;
                        const rw = (pin.w / 100) * natW * scale;
                        const rh = (pin.h / 100) * natH * scale;
                        
                        ctx.fillStyle = 'rgba(239, 68, 68, 0.3)';
                        ctx.fillRect(rx, ry, rw, rh);
                        ctx.strokeStyle = '#ef4444';
                        ctx.lineWidth = Math.max(1, 3 * scale);
                        ctx.strokeRect(rx, ry, rw, rh);
                    } else {
                        // Default: Pin
                        console.log("[SNAP] Drawing pin #" + idx);
                        const rx = ((pin.x / 100) * natW - cropX) * scale;
                        const ry = ((pin.y / 100) * natH - cropY) * scale;
                        
                        ctx.fillStyle = '#ef4444';
                        ctx.beginPath();
                        ctx.arc(rx, ry - 24, 12, 0, Math.PI * 2);
                        ctx.fill();
                        ctx.beginPath();
                        ctx.moveTo(rx - 11, ry - 20);
                        ctx.lineTo(rx + 11, ry - 20);
                        ctx.lineTo(rx, ry);
                        ctx.fill();
                        
                        ctx.fillStyle = '#ffffff';
                        ctx.beginPath();
                        ctx.arc(rx, ry - 24, 4, 0, Math.PI * 2);
                        ctx.fill();
                    }
                }
            });
            
            try {
                const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                document.getElementById('plan_snapshot_data').value = dataUrl;
                console.log('[SNAP] Snapshot generated, length:', dataUrl.length);
            } catch(canvasErr) {
                console.error('[SNAP] Canvas toDataURL failed (likely CORS):', canvasErr);
                alert('Hinweis: Der Plan-Screenshot konnte nicht erstellt werden (CORS). Die Pins werden trotzdem gespeichert.');
            }
        }
    } else {
        document.getElementById('plan_id').value = '';
        document.getElementById('pin_x').value = '';
        document.getElementById('pin_y').value = '';
        document.getElementById('plan_snapshot_data').value = 'DELETE'; // Flag to delete
    }
    
    closePlanPicker();
}
</script>

<!-- Offline Support handled via footer.php -->

<?php if (!IS_EMBEDDED_FORM) { require_once __DIR__ . '/../includes/footer.php'; } else { echo '</body></html>'; } ?>













