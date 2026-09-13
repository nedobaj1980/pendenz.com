<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// --- Selbstheilung für neue Unternehmer-Spalten ---
try {
    $mysqli->query("SELECT unt_new_input FROM pendenzen LIMIT 1");
} catch (Throwable $e) {
    // Manuelle Prüfung ohne 'IF NOT EXISTS' für ältere MySQL Versionen
    $res = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE 'unt_bemerkung'");
    if ($res->num_rows === 0) {
        $mysqli->query("ALTER TABLE pendenzen ADD unt_bemerkung TEXT DEFAULT NULL");
    }
    $res = $mysqli->query("SHOW COLUMNS FROM pendenzen LIKE 'unt_new_input'");
    if ($res && $res->num_rows === 0) {
        $mysqli->query("ALTER TABLE pendenzen ADD unt_new_input TINYINT(1) DEFAULT 0");
    }
    // NEU: Status-Spalte erweitern für "Unt. Erledigt."
    $mysqli->query("ALTER TABLE pendenzen MODIFY COLUMN status VARCHAR(50) DEFAULT 'offen'");
}

// --- NEU: AJAX Handler für Glocke zurücksetzen ---
if (isset($_GET['action']) && $_GET['action'] === 'reset_unt_bell') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0 && isset($_SESSION['user_id'])) {
        $mysqli->query("UPDATE pendenzen SET unt_new_input = 0 WHERE id = $id");
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
}

$id = (int) ($_GET['id'] ?? 0);

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


function pendenzAttachmentColumnExists(mysqli $mysqli, string $column): bool
{
    $res = $mysqli->query("SHOW COLUMNS FROM `pendenz_anhaenge` LIKE '" . $mysqli->real_escape_string($column) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function pendenzTableExists(mysqli $mysqli, string $table): bool
{
    $res = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($table) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

// --- AUTO-FIX: Missing Columns in Various Tables ---
$tables_to_ensure_sort = [
    'pendenzen_arten',
    'pendenz_subkategorien',
    'pendenz_kategorien_mieter',
    'pendenz_subkategorien_mieter',
    'pendenz_kategorien_vermieter',
    'pendenz_subkategorien_vermieter'
];

foreach ($tables_to_ensure_sort as $tbl) {
    if (pendenzTableExists($mysqli, $tbl)) {
        $check = $mysqli->query("SHOW COLUMNS FROM `$tbl` LIKE 'sort_order'");
        if ($check && $check->num_rows === 0) {
            $mysqli->query("ALTER TABLE `$tbl` ADD COLUMN `sort_order` INT(11) DEFAULT 0");
        }
    }
}

// Spezielle Spalten für pendenzen_arten
if (pendenzTableExists($mysqli, 'pendenzen_arten')) {
    $extra_cols = [
        'bkp_erforderlich' => "TINYINT(1) DEFAULT 0",
        'nur_firmen_bkp' => "TINYINT(1) DEFAULT 0",
        'firma_bkp_filter' => "VARCHAR(255) DEFAULT NULL",
        'is_active' => "TINYINT(1) DEFAULT 1",
        'sort_order' => "INT DEFAULT 100",
        'default_projekt_id' => "INT DEFAULT NULL",
        'default_objekt_id' => "INT DEFAULT NULL",
        'default_wohnung_id' => "INT DEFAULT NULL",
        'default_benutzer_id' => "INT DEFAULT NULL"
    ];
    foreach ($extra_cols as $col => $def) {
        $check = $mysqli->query("SHOW COLUMNS FROM `pendenzen_arten` LIKE '$col'");
        if ($check && $check->num_rows === 0) {
            $mysqli->query("ALTER TABLE `pendenzen_arten` ADD COLUMN `$col` $def");
        }
    }
}


function pendenzColumnExists(mysqli $mysqli, string $table, string $column): bool
{
    if (!pendenzTableExists($mysqli, $table)) {
        return false;
    }
    $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $mysqli->real_escape_string($column) . "'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function ensurePendenzAttachmentsTable(mysqli $mysqli): void
{
    if (!pendenzTableExists($mysqli, 'pendenz_dateien')) {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS `pendenz_dateien` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `pendenz_id` INT NOT NULL,
                `typ` ENUM('image','file','audio','video') NOT NULL DEFAULT 'file',
                `pfad` VARCHAR(1024) NOT NULL,
                `mimetype` VARCHAR(190) DEFAULT NULL,
                `groesse` INT UNSIGNED DEFAULT NULL,
                `titel` VARCHAR(255) DEFAULT NULL,
                `is_cover` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_index` INT DEFAULT NULL,
                `hochgeladen_von` INT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_pendenz_id` (`pendenz_id`),
                KEY `idx_typ` (`typ`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        if (!pendenzColumnExists($mysqli, 'pendenz_dateien', 'is_cover')) {
            $mysqli->query("ALTER TABLE `pendenz_dateien` ADD COLUMN `is_cover` TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!pendenzColumnExists($mysqli, 'pendenz_dateien', 'sort_index')) {
            $mysqli->query("ALTER TABLE `pendenz_dateien` ADD COLUMN `sort_index` INT DEFAULT NULL");
        }
        if (!pendenzColumnExists($mysqli, 'pendenz_dateien', 'titel')) {
            $mysqli->query("ALTER TABLE `pendenz_dateien` ADD COLUMN `titel` VARCHAR(255) DEFAULT NULL");
        }
    }

    if (!pendenzTableExists($mysqli, 'pendenz_anhaenge')) {
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS `pendenz_anhaenge` (
                `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `pendenz_id` INT NOT NULL,
                `pfad` VARCHAR(500) NOT NULL,
                `erstellt_am` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `quelle` VARCHAR(50) DEFAULT NULL,
                KEY idx_pendenz_id (pendenz_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

function ensureDir(string $path): bool
{
    if (is_dir($path)) {
        return true;
    }
    return @mkdir($path, 0775, true);
}

function normalizeUploadFiles(array $files): array
{
    $normalized = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $normalized;
    }
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $normalized[] = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $normalized;
}

function savePendenzUploadsByType(mysqli $mysqli, int $pendenzId, ?int $userId, array $files, string $typ, array &$messages = []): void
{
    ensurePendenzAttachmentsTable($mysqli);

    $uploadBaseDir = realpath(__DIR__ . '/..');
    if ($uploadBaseDir === false) {
        $messages[] = 'Upload-Pfad konnte nicht aufgelöst werden.';
        return;
    }

    $targetDir = $uploadBaseDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pendenzen' . DIRECTORY_SEPARATOR . $pendenzId . DIRECTORY_SEPARATOR . ($typ === 'bild' ? 'bilder' : 'dokumente');
    if (!ensureDir($targetDir)) {
        $messages[] = 'Upload-Ordner konnte nicht erstellt werden.';
        return;
    }

    $allowedExt = $typ === 'bild'
        ? ['jpg', 'jpeg', 'png', 'gif', 'webp']
        : ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

    $dbType = $typ === 'bild' ? 'image' : 'file';
    $maxSize = 15 * 1024 * 1024;
    $items = normalizeUploadFiles($files);
    if (!$items) {
        return;
    }

    $stmt = $mysqli->prepare("INSERT INTO `pendenz_dateien` (`pendenz_id`,`typ`,`pfad`,`mimetype`,`groesse`,`titel`,`hochgeladen_von`,`is_cover`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        $messages[] = 'Anhänge konnten nicht vorbereitet werden: ' . $mysqli->error;
        return;
    }

    foreach ($items as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (string) ($file['name'] ?? '') === '') {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $messages[] = 'Datei "' . (string) $file['name'] . '" konnte nicht hochgeladen werden.';
            continue;
        }

        $originalName = (string) $file['name'];
        $size = (int) ($file['size'] ?? 0);
        if ($size > $maxSize) {
            $messages[] = 'Datei "' . $originalName . '" ist zu gross (max. 15 MB).';
            continue;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $messages[] = 'Dateityp nicht erlaubt: ' . $originalName;
            continue;
        }

        $safeOriginal = preg_replace('/[^A-Za-z0-9_\.-]+/u', '_', $originalName) ?: 'datei';
        $storedName = 'up-' . strtolower(bin2hex(random_bytes(7))) . '-' . $safeOriginal;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $storedName;

        if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $targetPath)) {
            $messages[] = 'Datei "' . $originalName . '" konnte nicht gespeichert werden.';
            continue;
        }

        // Automatische Verkleinerung für Bilder auf max 0.5 MB
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            smart_resize_image($targetPath);
        }

        $relativePath = 'uploads/pendenzen/' . $pendenzId . '/' . ($typ === 'bild' ? 'bilder' : 'dokumente') . '/' . $storedName;
        $mimeType = (string) ($file['type'] ?? '');
        $uploadUser = $userId ?: null;
        $title = $originalName;
        $isCover = 0;

        if ($dbType === 'image') {
            $coverRes = $mysqli->query("SELECT id FROM `pendenz_dateien` WHERE `pendenz_id` = " . (int) $pendenzId . " AND `typ` = 'image' AND `is_cover` = 1 LIMIT 1");
            $hasCover = $coverRes instanceof mysqli_result && $coverRes->num_rows > 0;
            if ($coverRes instanceof mysqli_result) {
                $coverRes->close();
            }
            if (!$hasCover) {
                $isCover = 1;
            }
        }

        $stmt->bind_param('isssisii', $pendenzId, $dbType, $relativePath, $mimeType, $size, $title, $uploadUser, $isCover);
        if (!$stmt->execute()) {
            @unlink($targetPath);
            $messages[] = 'Datei "' . $originalName . '" konnte nicht in der Datenbank gespeichert werden.';
        }
    }

    $stmt->close();
}


function setPendenzCover(mysqli $mysqli, int $pendenzId, int $attachmentId, string $source = 'pendenz_dateien'): bool
{
    ensurePendenzAttachmentsTable($mysqli);

    if ($source === 'pendenz_dateien' && pendenzTableExists($mysqli, 'pendenz_dateien')) {
        $mysqli->query("UPDATE `pendenz_dateien` SET `is_cover` = 0 WHERE `pendenz_id` = " . (int) $pendenzId . " AND `typ` = 'image'");
        $stmt = $mysqli->prepare("UPDATE `pendenz_dateien` SET `is_cover` = 1 WHERE `id` = ? AND `pendenz_id` = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ii', $attachmentId, $pendenzId);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }
    }

    return false;
}

function getPendenzAttachments(mysqli $mysqli, int $pendenzId, ?string $typ = null): array
{
    ensurePendenzAttachmentsTable($mysqli);
    $rows = [];

    if (pendenzTableExists($mysqli, 'pendenz_dateien')) {
        $sql = "SELECT id, titel, pfad, mimetype, groesse, typ, created_at, COALESCE(is_cover,0) AS is_cover FROM `pendenz_dateien` WHERE pendenz_id = ?";
        if ($typ !== null) {
            $sql .= $typ === 'bild' ? " AND (LOWER(COALESCE(typ,'')) IN ('image','bild','foto') OR LOWER(COALESCE(mimetype,'')) LIKE 'image/%' OR LOWER(COALESCE(pfad,'')) REGEXP '\\.(jpg|jpeg|png|gif|webp)$')" : " AND NOT (LOWER(COALESCE(typ,'')) IN ('image','bild','foto') OR LOWER(COALESCE(mimetype,'')) LIKE 'image/%' OR LOWER(COALESCE(pfad,'')) REGEXP '\\.(jpg|jpeg|png|gif|webp)$')";
        }
        $sql .= " ORDER BY COALESCE(sort_index, 999999) ASC, id DESC";
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $pendenzId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $rows[] = [
                    'id' => (int) $row['id'],
                    'source' => 'pendenz_dateien',
                    'original_name' => (string) ($row['titel'] ?? 'Datei'),
                    'stored_name' => basename((string) ($row['pfad'] ?? '')),
                    'file_path' => (string) ($row['pfad'] ?? ''),
                    'file_ext' => strtolower(pathinfo((string) ($row['pfad'] ?? ''), PATHINFO_EXTENSION)),
                    'mime_type' => (string) ($row['mimetype'] ?? ''),
                    'file_size' => (int) ($row['groesse'] ?? 0),
                    'typ' => ((in_array(strtolower((string) ($row['typ'] ?? '')), ['image', 'bild', 'foto'], true) || str_starts_with(strtolower((string) ($row['mimetype'] ?? '')), 'image/') || preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', (string) ($row['pfad'] ?? ''))) ? 'bild' : 'dokument'),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'is_cover' => (int) ($row['is_cover'] ?? 0),
                ];
            }
            if ($res instanceof mysqli_result) {
                $res->close();
            }
            $stmt->close();
        }
    }

    if (!$rows && pendenzTableExists($mysqli, 'pendenz_anhaenge')) {
        $stmt = $mysqli->prepare("SELECT id, pfad, quelle, erstellt_am FROM `pendenz_anhaenge` WHERE pendenz_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $pendenzId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $path = (string) ($row['pfad'] ?? '');
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $legacyType = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'bild' : 'dokument';
                if ($typ !== null && $legacyType !== $typ) {
                    continue;
                }
                $rows[] = [
                    'id' => (int) $row['id'],
                    'source' => 'pendenz_anhaenge',
                    'original_name' => basename($path),
                    'stored_name' => basename($path),
                    'file_path' => $path,
                    'file_ext' => $ext,
                    'mime_type' => '',
                    'file_size' => 0,
                    'typ' => $legacyType,
                    'created_at' => (string) ($row['erstellt_am'] ?? ''),
                    'is_cover' => 0,
                ];
            }
            if ($res instanceof mysqli_result) {
                $res->close();
            }
            $stmt->close();
        }
    }

    return $rows;
}

function deletePendenzAttachment(mysqli $mysqli, int $attachmentId, ?string $source = null): bool
{
    ensurePendenzAttachmentsTable($mysqli);

    $sources = $source ? [$source] : ['pendenz_dateien', 'pendenz_anhaenge'];

    foreach ($sources as $src) {
        if ($src === 'pendenz_dateien' && pendenzTableExists($mysqli, 'pendenz_dateien')) {
            $stmt = $mysqli->prepare("SELECT pfad FROM `pendenz_dateien` WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $attachmentId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($res instanceof mysqli_result)
                    $res->close();
                $stmt->close();
                if ($row) {
                    $filePath = __DIR__ . '/../' . ltrim((string) $row['pfad'], '/');
                    if (is_file($filePath))
                        @unlink($filePath);
                    $del = $mysqli->prepare("DELETE FROM `pendenz_dateien` WHERE id = ? LIMIT 1");
                    if ($del) {
                        $del->bind_param('i', $attachmentId);
                        $ok = $del->execute();
                        $del->close();
                        return $ok;
                    }
                }
            }
        }
        if ($src === 'pendenz_anhaenge' && pendenzTableExists($mysqli, 'pendenz_anhaenge')) {
            $stmt = $mysqli->prepare("SELECT pfad FROM `pendenz_anhaenge` WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $attachmentId);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($res instanceof mysqli_result)
                    $res->close();
                $stmt->close();
                if ($row) {
                    $filePath = __DIR__ . '/../' . ltrim((string) $row['pfad'], '/');
                    if (is_file($filePath))
                        @unlink($filePath);
                    $del = $mysqli->prepare("DELETE FROM `pendenz_anhaenge` WHERE id = ? LIMIT 1");
                    if ($del) {
                        $del->bind_param('i', $attachmentId);
                        $ok = $del->execute();
                        $del->close();
                        return $ok;
                    }
                }
            }
        }
    }

    return false;
}

function attachmentIsImage(array $att): bool
{
    return ($att['typ'] ?? '') === 'bild';
}

ensurePendenzAttachmentsTable($mysqli);

$prefillProjektId = isset($_GET['projekt_id']) && ctype_digit((string) $_GET['projekt_id']) ? (int) $_GET['projekt_id'] : null;
$prefillObjektId = isset($_GET['objekt_id']) && ctype_digit((string) $_GET['objekt_id']) ? (int) $_GET['objekt_id'] : null;
$prefillWohnungId = isset($_GET['wohnung_id']) && ctype_digit((string) $_GET['wohnung_id']) ? (int) $_GET['wohnung_id'] : null;
$prefillKategorieId = isset($_GET['kategorie_id']) && ctype_digit((string) $_GET['kategorie_id']) ? (int) $_GET['kategorie_id'] : null;
$prefillUnternehmerId = isset($_GET['unternehmer_id']) && ctype_digit((string) $_GET['unternehmer_id']) ? (int) $_GET['unternehmer_id'] : null;
$prefillVorgangsartId = isset($_GET['vorgangsart_id']) && ctype_digit((string) $_GET['vorgangsart_id']) ? (int) $_GET['vorgangsart_id'] : null;
$requestedListId = isset($_GET['list_id']) && ctype_digit((string) $_GET['list_id']) ? (int) $_GET['list_id'] : null;

$editPendenzId = isset($_GET['edit_id']) && ctype_digit((string) $_GET['edit_id']) ? (int) $_GET['edit_id'] : null;
$isEditMode = false;
$editDetailUrl = '';
$editAttachments = [];
$editImages = [];
$editDocuments = [];

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


if ($editPendenzId !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmtEditLoad = $mysqli->prepare("
        SELECT
            id,
            projekt_id,
            vorgangsart_id,
            objekt_id,
            wohnung_id,
            titel,
            kurzbeschreibung,
            langbeschreibung,
            beschreibung,
            notiz,
            status,
            wichtigkeit,
            startdatum,
            enddatum,
            uhrzeit,
            dauer,
            zustaendig_id,
            confirmation_required,
            external_can_view,
            external_can_upload,
            public_enabled,
            extra_json
        FROM pendenzen
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmtEditLoad) {
        $stmtEditLoad->bind_param('i', $editPendenzId);
        $stmtEditLoad->execute();
        $resEditLoad = $stmtEditLoad->get_result();
        $editRow = $resEditLoad ? $resEditLoad->fetch_assoc() : null;
        $stmtEditLoad->close();

        if ($editRow) {
            $extraData = [];
            if (!empty($editRow['extra_json'])) {
                $decoded = json_decode((string) $editRow['extra_json'], true);
                if (is_array($decoded)) {
                    $extraData = $decoded;
                }
            }

            $input = [
                'projekt_id' => isset($editRow['projekt_id']) ? (int) $editRow['projekt_id'] : null,
                'vorgangsart_id' => isset($editRow['vorgangsart_id']) ? (int) $editRow['vorgangsart_id'] : null,
                'objekt_id' => isset($editRow['objekt_id']) ? (int) $editRow['objekt_id'] : null,
                'wohnung_id' => isset($editRow['wohnung_id']) ? (int) $editRow['wohnung_id'] : null,
                'titel' => (string) ($editRow['titel'] ?? ''),
                'kurzbeschreibung' => (string) ($editRow['kurzbeschreibung'] ?? ''),
                'langbeschreibung' => (string) ($editRow['langbeschreibung'] ?? ''),
                'beschreibung' => (string) ($editRow['beschreibung'] ?? ''),
                'notiz' => (string) ($editRow['notiz'] ?? ''),
                'status' => (string) ($editRow['status'] ?? 'offen'),
                'wichtigkeit' => ($editRow['wichtigkeit'] ?? '') === null ? '' : (string) $editRow['wichtigkeit'],
                'startdatum' => (string) ($editRow['startdatum'] ?? ''),
                'enddatum' => (string) ($editRow['enddatum'] ?? ''),
                'uhrzeit' => (string) ($editRow['uhrzeit'] ?? ''),
                'dauer' => (string) ($editRow['dauer'] ?? ''),
                'zustaendig_id' => isset($editRow['zustaendig_id']) ? (int) $editRow['zustaendig_id'] : null,
                'kategorie_id' => isset($extraData['kategorie_id']) && is_numeric((string) $extraData['kategorie_id']) ? (int) $extraData['kategorie_id'] : null,
                'subkategorie_id' => isset($extraData['subkategorie_id']) && is_numeric((string) $extraData['subkategorie_id']) ? (int) $extraData['subkategorie_id'] : null,
                'send_now' => 0,
                'confirmation_required' => !empty($editRow['confirmation_required']) ? 1 : 0,
                'external_can_view' => array_key_exists('external_can_view', $editRow) ? (int) $editRow['external_can_view'] : (!empty($extraData['external_can_view']) ? 1 : 0),
                'external_can_upload' => array_key_exists('external_can_upload', $editRow) ? (int) $editRow['external_can_upload'] : (!empty($extraData['external_can_upload']) ? 1 : 0),
                'public_enabled' => array_key_exists('public_enabled', $editRow) ? (int) $editRow['public_enabled'] : (!empty($extraData['public_enabled']) ? 1 : 0),
            ];

            $isEditMode = true;
            $editDetailUrl = 'pendenz_show.php?id=' . $editPendenzId;
            $editAttachments = getPendenzAttachments($mysqli, (int) $editPendenzId);
            $editImages = array_values(array_filter($editAttachments, static fn(array $att): bool => ($att['typ'] ?? '') === 'bild'));
            $editDocuments = array_values(array_filter($editAttachments, static fn(array $att): bool => ($att['typ'] ?? '') !== 'bild'));
        } else {
            $error = 'Pendenz zum Bearbeiten wurde nicht gefunden.';
        }
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && postStr('form_action') === 'set_cover') {
    $postedPendenzId = isset($_POST['pendenz_id']) && ctype_digit((string) $_POST['pendenz_id']) ? (int) $_POST['pendenz_id'] : null;
    $attachmentId = isset($_POST['attachment_id']) && ctype_digit((string) $_POST['attachment_id']) ? (int) $_POST['attachment_id'] : null;
    $attachmentSource = postStr('attachment_source', 'pendenz_dateien');

    if ($postedPendenzId && $attachmentId) {
        if (setPendenzCover($mysqli, $postedPendenzId, $attachmentId, $attachmentSource)) {
            $success = 'Cover wurde geändert.';
            $editPendenzId = $postedPendenzId;
            $isEditMode = true;
            $editDetailUrl = 'pendenz_show.php?id=' . $editPendenzId;
            $editAttachments = getPendenzAttachments($mysqli, (int) $editPendenzId);
            $editImages = array_values(array_filter($editAttachments, static fn(array $att): bool => ($att['typ'] ?? '') === 'bild'));
            $editDocuments = array_values(array_filter($editAttachments, static fn(array $att): bool => ($att['typ'] ?? '') !== 'bild'));
        } else {
            $error = 'Cover konnte nicht geändert werden.';
        }
    } else {
        $error = 'Ungültige Cover-Auswahl.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && postStr('form_action') === 'delete_attachment') {
    $postedPendenzId = isset($_POST['pendenz_id']) && ctype_digit((string) $_POST['pendenz_id']) ? (int) $_POST['pendenz_id'] : null;
    $attachmentId = isset($_POST['attachment_id']) && ctype_digit((string) $_POST['attachment_id']) ? (int) $_POST['attachment_id'] : null;
    $attachmentSource = postStr('attachment_source', '');

    if ($postedPendenzId && $attachmentId) {
        if (deletePendenzAttachment($mysqli, $attachmentId, $attachmentSource !== '' ? $attachmentSource : null)) {
            $success = 'Anhang wurde gelöscht.';
            $editPendenzId = $postedPendenzId;
            $isEditMode = true;
            $editDetailUrl = 'pendenz_show.php?id=' . $editPendenzId;
            $editAttachments = getPendenzAttachments($mysqli, (int) $editPendenzId);
            $editImages = array_values(array_filter($editAttachments, static fn(array $att): bool => ($att['typ'] ?? '') === 'bild'));
            $editDocuments = array_values(array_filter($editAttachments, static fn(array $att): bool => ($att['typ'] ?? '') !== 'bild'));
        } else {
            $error = 'Anhang konnte nicht gelöscht werden.';
        }
    } else {
        $error = 'Ungültiger Anhang.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && postStr('form_action') === 'inline_update') {

    $inlineId = isset($_POST['inline_pendenz_id']) && ctype_digit((string) $_POST['inline_pendenz_id']) ? (int) $_POST['inline_pendenz_id'] : null;
    $inlineTitel = postStr('inline_titel');
    $inlineProjektId = postIntOrNull('inline_projekt_id');
    $inlineObjektId = postIntOrNull('inline_objekt_id');
    $inlineWohnungId = postIntOrNull('inline_wohnung_id');
    $inlineRaumId = postIntOrNull('inline_raum_id');
    $inlineVorgangsartId = postIntOrNull('inline_vorgangsart_id');
    $inlineKurzbeschreibung = postStr('inline_kurzbeschreibung');
    $inlineStatus = postStr('inline_status', 'offen');
    $inlineWichtigkeitRaw = postStr('inline_wichtigkeit');
    $inlineZustaendigId = postIntOrNull('inline_zustaendig_id');
    $inlineStartdatum = postStr('inline_startdatum');
    $inlineEnddatum = postStr('inline_enddatum');
    $inlineUhrzeit = postStr('inline_uhrzeit');
    $inlineDauer = postStr('inline_dauer');
    $inlineUntBemerkung = postStr('inline_unt_bemerkung');

    if ($inlineId === null) {
        $error = 'Inline-Bearbeitung fehlgeschlagen: ungültige ID.';
    } elseif ($inlineTitel === '') {
        $error = 'Bitte Titel eingeben.';
    } elseif (!in_array($inlineStatus, ['offen', 'in Bearbeitung', 'Unt. Erledigt.', 'erledigt', 'archiviert'], true)) {
        $error = 'Ungültiger Status.';
    } else {
        $inlineWichtigkeit = null;
        if ($inlineWichtigkeitRaw !== '' && is_numeric($inlineWichtigkeitRaw)) {
            $inlineWichtigkeit = max(1, min(5, (int) $inlineWichtigkeitRaw));
        }

        $inlineStartdatumDb = $inlineStartdatum !== '' ? $inlineStartdatum : null;
        $inlineEnddatumDb = $inlineEnddatum !== '' ? $inlineEnddatum : null;
        $inlineUhrzeitDb = $inlineUhrzeit !== '' ? $inlineUhrzeit : null;
        $inlineDauerDb = $inlineDauer !== '' ? $inlineDauer : null;

        $stmtInline = $mysqli->prepare("
            UPDATE pendenzen
            SET
                projekt_id = ?,
                objekt_id = ?,
                wohnung_id = ?,
                raum_id = ?,
                vorgangsart_id = ?,
                titel = ?,
                kurzbeschreibung = ?,
                status = ?,
                wichtigkeit = ?,
                zustaendig_id = ?,
                startdatum = ?,
                enddatum = ?,
                uhrzeit = ?,
                dauer = ?,
                unt_bemerkung = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmtInline) {
            $error = 'Inline-Update Prepare fehlgeschlagen: ' . $mysqli->error;
        } else {
            $stmtInline->bind_param(
                'iiiiisssissssssi',
                $inlineProjektId,
                $inlineObjektId,
                $inlineWohnungId,
                $inlineRaumId,
                $inlineVorgangsartId,
                $inlineTitel,
                $inlineKurzbeschreibung,
                $inlineStatus,
                $inlineWichtigkeit,
                $inlineZustaendigId,
                $inlineStartdatumDb,
                $inlineEnddatumDb,
                $inlineUhrzeitDb,
                $inlineDauerDb,
                $inlineUntBemerkung,
                $inlineId
            );

            if ($stmtInline->execute()) {
                $success = 'Pendenz #' . $inlineId . ' wurde gespeichert.';
            } else {
                $error = 'Inline-Speichern fehlgeschlagen: ' . $stmtInline->error;
            }

            $stmtInline->close();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedEditId = isset($_POST['pendenz_id']) && ctype_digit((string) $_POST['pendenz_id']) ? (int) $_POST['pendenz_id'] : null;
    $formAction = postStr('form_action', ($postedEditId !== null ? 'update' : 'create'));
    $isEditMode = ($formAction === 'update' && $postedEditId !== null);
    $editPendenzId = $isEditMode ? $postedEditId : $editPendenzId;
    $editDetailUrl = $editPendenzId ? ('pendenz_show.php?id=' . $editPendenzId) : '';
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
    $input['bkp_id'] = postIntOrNull('bkp_id');
    $input['bkp_kategorie_id'] = postIntOrNull('bkp_kategorie_id');
    $input['bkp_text_id'] = postIntOrNull('bkp_text_id');
    $input['mieter_kategorie_id'] = postIntOrNull('mieter_kategorie_id');
    $input['mieter_subkategorie_id'] = postIntOrNull('mieter_subkategorie_id');
    $input['vermieter_kategorie_id'] = postIntOrNull('vermieter_kategorie_id');
    $input['vermieter_subkategorie_id'] = postIntOrNull('vermieter_subkategorie_id');
    $input['send_now'] = isset($_POST['send_now']) ? 1 : 0;
    $input['confirmation_required'] = isset($_POST['confirmation_required']) ? 1 : 0;
    $input['external_can_view'] = isset($_POST['external_can_view']) ? 1 : 0;
    $input['external_can_upload'] = isset($_POST['external_can_upload']) ? 1 : 0;
    $input['public_enabled'] = isset($_POST['public_enabled']) ? 1 : 0;

    if ($input['titel'] === '') {
        $error = 'Bitte Titel eingeben.';
    } elseif (!in_array($input['status'], ['offen', 'in Bearbeitung', 'Unt. Erledigt.', 'erledigt', 'archiviert'], true)) {
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

        $art = $input['vorgangsart_id'] ? ($artMap[$input['vorgangsart_id']] ?? null) : null;
        $vorlagenWelt = $art ? (trim((string) ($art['vorlagen_welt'] ?? '')) ?: 'bkp') : 'bkp';
        $empfaengerTyp = $art ? (string) ($art['empfaenger_typ'] ?? '') : '';

        // Dynamic Title Resolution (Sync with pendenz_neu.php logic)
        $dynamicTitel = '';
        if ($vorlagenWelt === 'bkp' && $input['bkp_id'] && $input['bkp_kategorie_id']) {
            if (isset($bkpKategorieMap[$input['bkp_kategorie_id']]['name'])) {
                $dynamicTitel = trim((string) $bkpKategorieMap[$input['bkp_kategorie_id']]['name']);
            }
            if ($input['bkp_text_id'] && isset($bkpTextsByKategorie[$input['bkp_kategorie_id']])) {
                foreach ($bkpTextsByKategorie[$input['bkp_kategorie_id']] as $t) {
                    if ((int) $t['id'] === $input['bkp_text_id']) {
                        $dynamicTitel .= ($dynamicTitel !== '' ? ' / ' : '') . trim((string) $t['text']);
                        break;
                    }
                }
            }
        } elseif ($vorlagenWelt === 'mieter' && $input['mieter_kategorie_id']) {
            if (isset($mieterCatMap[$input['mieter_kategorie_id']]['name'])) {
                $dynamicTitel = trim((string) $mieterCatMap[$input['mieter_kategorie_id']]['name']);
            }
            if ($input['mieter_subkategorie_id'] && isset($mieterSubMap[$input['mieter_subkategorie_id']]['name'])) {
                $dynamicTitel .= ($dynamicTitel !== '' ? ' / ' : '') . trim((string) $mieterSubMap[$input['mieter_subkategorie_id']]['name']);
            }
        } elseif ($vorlagenWelt === 'vermieter' && $input['vermieter_kategorie_id']) {
            if (isset($vermieterCatMap[$input['vermieter_kategorie_id']]['name'])) {
                $dynamicTitel = trim((string) $vermieterCatMap[$input['vermieter_kategorie_id']]['name']);
            }
            if ($input['vermieter_subkategorie_id'] && isset($vermieterSubMap[$input['vermieter_subkategorie_id']]['name'])) {
                $dynamicTitel .= ($dynamicTitel !== '' ? ' / ' : '') . trim((string) $vermieterSubMap[$input['vermieter_subkategorie_id']]['name']);
            }
        }

        $finalTitel = $input['titel'];
        if ($dynamicTitel !== '') {
            $finalTitel = $input['titel'] !== '' ? $input['titel'] . ', ' . $dynamicTitel : $dynamicTitel;
        }

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
            'titel_manuell' => $input['titel'],
            'titel_vorlage' => $dynamicTitel,
            '_s_quick' => (isset($_POST['action']) && $_POST['action'] === 'save_pendenz' ? 1 : 0)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($isEditMode && $editPendenzId !== null) {
            $sqlUpdate = "
                UPDATE pendenzen
                SET
                    projekt_id = ?, vorgangsart_id = ?, objekt_id = ?, wohnung_id = ?,
                    titel = ?, kurzbeschreibung = ?, langbeschreibung = ?, notiz = ?, beschreibung = ?,
                    status = ?, wichtigkeit = ?, startdatum = ?, enddatum = ?, uhrzeit = ?, dauer = ?,
                    send_now = ?, zustaendig_id = ?, confirmation_required = ?,
                    external_can_view = ?, external_can_upload = ?, public_enabled = ?,
                    bkp_id = ?, extra_json = ?
                WHERE id = ? LIMIT 1
            ";
            $stmtUpdate = $mysqli->prepare($sqlUpdate);
            if ($stmtUpdate) {
                $stmtUpdate->bind_param(
                    'iiiissssssissssiiiiiiisi',
                    $input['projekt_id'],
                    $input['vorgangsart_id'],
                    $input['objekt_id'],
                    $input['wohnung_id'],
                    $finalTitel,
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
                    $input['zustaendig_id'],
                    $input['confirmation_required'],
                    $input['external_can_view'],
                    $input['external_can_upload'],
                    $input['public_enabled'],
                    $input['bkp_id'],
                    $extraJson,
                    $editPendenzId
                );
                $stmtUpdate->execute();
                $stmtUpdate->close();
            }
        } else {
            $sqlInsert = "
                INSERT INTO pendenzen (
                    mandant_id, projekt_id, vorgangsart_id, objekt_id, wohnung_id,
                    titel, kurzbeschreibung, langbeschreibung, notiz, beschreibung,
                    status, wichtigkeit, startdatum, enddatum, uhrzeit, dauer,
                    send_now, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id,
                    confirmation_required, confirmation_by, external_can_view, external_can_upload,
                    public_enabled, extra_json, zustaendig_typ, bkp_id
                ) VALUES (
                    0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'projekt', 1, ?, ?, 'assignee', ?, ?, ?, ?, 'user', ?
                )
            ";
            $stmtInsert = $mysqli->prepare($sqlInsert);
            if ($stmtInsert) {
                $stmtInsert->bind_param(
                    'iiiissssssissssiiiiiissi',
                    $input['projekt_id'],
                    $input['vorgangsart_id'],
                    $input['objekt_id'],
                    $input['wohnung_id'],
                    $finalTitel,
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
                    $extraJson,
                    $input['bkp_id']
                );

                if ($stmtInsert->execute()) {
                    $newId = (int) $stmtInsert->insert_id;

                    // Handle Quick Uploads
                    if (isset($_FILES['bilder'])) {
                        savePendenzUploadsByType($mysqli, $newId, $currentUserId, normalizeUploadFiles($_FILES['bilder']), 'bild');
                    }
                    if (isset($_FILES['dokumente'])) {
                        savePendenzUploadsByType($mysqli, $newId, $currentUserId, normalizeUploadFiles($_FILES['dokumente']), 'datei');
                    }

                    $success = 'Pendenz erfolgreich gespeichert. ID: ' . $newId;
                    $input['titel'] = '';
                    $input['kurzbeschreibung'] = '';
                    $input['langbeschreibung'] = '';
                } else {
                    $error = 'Speichern fehlgeschlagen: ' . $stmtInsert->error;
                }
                $stmtInsert->close();
            }
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
$res = $mysqli->query("
    SELECT id, wohnung_id, name
    FROM raeume
    ORDER BY wohnung_id ASC, name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $raeume[] = $row;
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

// --- Additional Data for Dashboard & Context ---
$arten = fetchAllAssoc($mysqli, "
    SELECT id, name, slug, icon, vorlagen_welt, default_projekt_id, default_objekt_id, default_wohnung_id, default_benutzer_id,
           empfaenger_typ, empfaenger_person_type_id, empfaenger_person_status_id
    FROM pendenzen_arten 
    WHERE is_active = 1 
    ORDER BY sort_order ASC, name ASC
");

$hasArtDefaults = pendenzTableExists($mysqli, 'pendenzen_art_empfaenger_defaults');
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

$userMemberships = [];
$hasBpt = pendenzTableExists($mysqli, 'benutzer_personentypen');
if ($hasBpt) {
    $membershipRows = fetchAllAssoc($mysqli, "
        SELECT benutzer_id, person_type_id, person_status_id
        FROM benutzer_personentypen
    ");
    foreach ($membershipRows as $row) {
        $uid = (int) $row['benutzer_id'];
        $userMemberships[$uid] ??= [];
        $userMemberships[$uid][] = [
            'person_type_id' => (int) $row['person_type_id'],
            'person_status_id' => (int) $row['person_status_id']
        ];
    }
}

$dashUsersList = [];
foreach ($benutzer as $row) {
    $uid = (int) $row['id'];
    $dashUsersList[] = [
        'id' => $uid,
        'label' => $row['name'],
        'memberships' => $userMemberships[$uid] ?? []
    ];
}

if (isset($_GET['profile_id'])) {
    if ($_GET['profile_id'] === '' || $_GET['profile_id'] === '0') {
        unset($_SESSION['active_profile_id']);
        $requestedListId = null;
    } else {
        $requestedListId = (int) $_GET['profile_id'];
        $_SESSION['active_profile_id'] = $requestedListId;
    }
} else {
    $requestedListId = isset($_SESSION['active_profile_id']) ? (int) $_SESSION['active_profile_id'] : null;
}

$profiles = [];
$res = $mysqli->query("
    SELECT
        l.id,
        l.name,
        l.filters_json,
        COALESCE(l.is_default, 0) AS is_default,
        GROUP_CONCAT(ls.col_name ORDER BY ls.sort_order ASC) as cols
    FROM listen l
    LEFT JOIN listen_spalten ls ON l.id = ls.listen_id
    WHERE l.table_name = 'pendenzen'
    GROUP BY l.id, l.name, l.filters_json, l.is_default
    ORDER BY l.is_default DESC, l.name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $profiles[] = $row;
    }
    $res->close();
}

$columnDef = [
    'id' => ['label' => 'ID', 'sort' => 'number'],
    'erstes_bild' => ['label' => '🖼️', 'sort' => ''],
    'titel' => ['label' => 'Titel', 'sort' => 'text'],
    'projekt_name' => ['label' => 'Proj.', 'sort' => 'text'],
    'objekt_name' => ['label' => 'Objekt', 'sort' => 'text'],
    'wohnung_name' => ['label' => 'Woh.', 'sort' => 'text'],
    'raum_name' => ['label' => 'Raum', 'sort' => 'text'],
    'status' => ['label' => 'Status', 'sort' => 'text'],
    'wichtigkeit' => ['label' => 'Prio', 'sort' => 'number'],
    'zustaendig_name' => ['label' => 'Zust.', 'sort' => 'text'],
    'unt_bemerkung' => ['label' => 'Info Unt.', 'sort' => 'text'],
    'startdatum' => ['label' => 'Start', 'sort' => 'text'],
    'enddatum' => ['label' => 'Ende', 'sort' => 'text'],
    'uhrzeit' => ['label' => 'Zeit', 'sort' => 'text'],
    'dauer' => ['label' => 'Dauer', 'sort' => 'text'],
    'tageszeit' => ['label' => 'Tagest.', 'sort' => 'text'],
    'vorgaenger_id' => ['label' => 'Vorg.', 'sort' => 'number'],
    'vorgangsart_name' => ['label' => 'Art', 'sort' => 'text'],
    'count_images' => ['label' => '📸', 'sort' => 'number'],
    'count_files' => ['label' => '📂', 'sort' => 'number'],
    'pdf' => ['label' => '📕', 'sort' => 'number'],
    'kurzbeschreibung' => ['label' => 'Kurz.', 'sort' => 'text'],
    'sichtbarkeit' => ['label' => 'Sichtb.', 'sort' => 'text'],
    'erstellt_am' => ['label' => 'Erst.am', 'sort' => 'text'],
    'geaendert_am' => ['label' => 'Geänd.am', 'sort' => 'text'],
    'deleted_at' => ['label' => 'Gelö.', 'sort' => 'text'],
    'erstellt_von' => ['label' => 'Erst.v.', 'sort' => 'number'],
];

$activeProfile = null;
$activeProfileCols = '';
$defaultProfile = null;

foreach ($profiles as $profileRow) {
    if ((int) ($profileRow['is_default'] ?? 0) === 1) {
        $defaultProfile = $profileRow;
        break;
    }
}

if ($requestedListId !== null) {
    foreach ($profiles as $profileRow) {
        if ((int) ($profileRow['id'] ?? 0) === $requestedListId) {
            $activeProfile = $profileRow;
            $activeProfileCols = (string) ($profileRow['cols'] ?? '');
            break;
        }
    }
} elseif ($defaultProfile !== null) {
    $activeProfile = $defaultProfile;
    $activeProfileCols = (string) ($defaultProfile['cols'] ?? '');
    $requestedListId = (int) ($defaultProfile['id'] ?? 0);
}

$displayCols = [];
if (!empty($activeProfileCols)) {
    $displayCols = explode(',', $activeProfileCols);
} else {
    // Default fallback columns if no profile
    $displayCols = ['id', 'erstes_bild', 'titel', 'projekt_name', 'objekt_name', 'wohnung_name', 'raum_name', 'status', 'wichtigkeit', 'zustaendig_name', 'vorgangsart_name'];
}

$recent = [];
$attachmentQueryParts = [
    'first_image' => "NULL AS first_image",
    'count_images' => "0 AS count_images",
    'count_files' => "0 AS count_files",
];
if (pendenzTableExists($mysqli, 'pendenz_dateien')) {
    $attachmentQueryParts = [
        'first_image' => "(SELECT pfad FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image' ORDER BY COALESCE(is_cover,0) DESC, COALESCE(sort_index,999999) ASC, id ASC LIMIT 1) AS first_image",
        'all_images' => "(SELECT GROUP_CONCAT(pfad ORDER BY COALESCE(is_cover,0) DESC, COALESCE(sort_index,999999) ASC, id ASC) FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image') AS all_images",
        'count_images' => "(SELECT COUNT(*) FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image') AS count_images",
        'count_files' => "(SELECT COUNT(*) FROM pendenz_dateien WHERE pendenz_id = p.id AND typ <> 'image') AS count_files",
    ];

} elseif (pendenzTableExists($mysqli, 'pendenz_anhaenge')) {
    $attachmentQueryParts = [
        'first_image' => "(SELECT pfad FROM pendenz_anhaenge WHERE pendenz_id = p.id ORDER BY id ASC LIMIT 1) AS first_image",
        'count_images' => "(SELECT COUNT(*) FROM pendenz_anhaenge WHERE pendenz_id = p.id) AS count_images",
        'count_files' => "0 AS count_files",
    ];
}
$profileFilters = [];
if ($activeProfile && !empty($activeProfile['filters_json'])) {
    $profileFilters = json_decode($activeProfile['filters_json'], true) ?: [];
}

$whereParts = ["p.deleted_at IS NULL"];
if (!empty($profileFilters['status'])) {
    $whereParts[] = "p.status = '" . $mysqli->real_escape_string($profileFilters['status']) . "'";
}
if (!empty($profileFilters['projekt_id'])) {
    $whereParts[] = "p.projekt_id = " . (int) $profileFilters['projekt_id'];
}
if (!empty($profileFilters['kategorie_id'])) {
    $whereParts[] = "p.kategorie_id = " . (int) $profileFilters['kategorie_id'];
}
if (!empty($profileFilters['only_open'])) {
    $whereParts[] = "p.status = 'offen'";
}

$whereClause = implode(" AND ", $whereParts);

$recentSql = "
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
        p.projekt_id,
        p.objekt_id,
        p.wohnung_id,
        p.raum_id,
        p.vorgangsart_id,
        pr.name AS projekt_name,
        o.name AS objekt_name,
        p.zustaendig_id,
        b.name AS zustaendig_name,
        w.name AS wohnung_name,
        rm.name AS raum_name,
        pa.name AS vorgangsart_name,
        p.public_token,
        p.public_enabled,
        p.unt_new_input,
        p.unt_bemerkung,
        {$attachmentQueryParts['first_image']},
        {$attachmentQueryParts['all_images']},
        {$attachmentQueryParts['count_images']},
        {$attachmentQueryParts['count_files']}
    FROM pendenzen p

    LEFT JOIN projekte pr ON pr.id = p.projekt_id
    LEFT JOIN objekte o ON o.id = p.objekt_id
    LEFT JOIN benutzer b ON b.id = p.zustaendig_id
    LEFT JOIN wohnungen w ON w.id = p.wohnung_id
    LEFT JOIN raeume rm ON rm.id = p.raum_id
    LEFT JOIN pendenzen_arten pa ON pa.id = p.vorgangsart_id
    WHERE {$whereClause}
    ORDER BY p.id DESC
    LIMIT 100
";
$res = $mysqli->query($recentSql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $recent[] = $row;
    }
    $res->close();
}
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
        max-width: 100%;
        margin: 0 auto;
        overflow-x: hidden;
        /* Verhindert, dass die ganze Seite nach rechts schiebt */
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
        grid-template-columns: repeat(6, 1fr) 240px;
        gap: 16px;
        align-items: end;
        position: relative;
        z-index: 1;
    }

    .pendenzen-nav-group {
        display: flex;
        gap: 8px;
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
    .pendenzen-dashboard-field button,
    .pendenzen-quick-btn {
        width: 100%;
        min-height: 40px;
        border-radius: 8px;
        border: 0;
        padding: 0 10px;
        font-size: 13px;
        transition: all 0.2s;
    }

    .pendenzen-quick-btn {
        background: #fff;
        color: var(--primary);
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--primary);
    }

    .pendenzen-quick-btn:hover {
        background: var(--primary);
        color: #fff;
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
        padding: 15px;
        max-width: 100%;
        box-sizing: border-box;
        overflow: hidden;
    }

    /* Recent Bar Styles */
    .recent-bar-container {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 16px 20px;
        margin-bottom: 24px;
        box-shadow: var(--shadow-sm);
    }

    .recent-bar-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        font-size: 13px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-main);
    }

    .recent-bar-scroller {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 8px;
    }

    .recent-bar-scroller::-webkit-scrollbar {
        height: 4px;
    }

    .recent-bar-scroller::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .recent-item {
        flex: 0 0 220px;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px;
        border-radius: 10px;
        border: 1px solid #f1f5f9;
        text-decoration: none;
        transition: all 0.2s;
        background: #fafafa;
    }

    .recent-item:hover {
        background: #fff;
        border-color: var(--accent-color);
        box-shadow: var(--shadow-sm);
        transform: translateY(-2px);
    }

    .recent-img {
        width: 48px;
        height: 48px;
        border-radius: 6px;
        overflow: hidden;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .recent-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .recent-info {
        overflow: hidden;
    }

    .recent-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--text-main);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .recent-meta {
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 2px;
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

    .link-inline {
        color: #0f766e;
        font-weight: 700;
        text-decoration: none;
    }

    .link-inline:hover {
        text-decoration: underline;
    }

    .pendenzen-actions {
        display: flex;
        gap: 0;
        /* Symbole berühren sich jetzt fast */
        flex-wrap: nowrap;
        justify-content: flex-start;
        align-items: center;
    }

    .pendenzen-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        /* Polster komplett weg */
        margin: 0 -2px;
        /* Negative Margins für maximale Nähe */
        border-radius: 4px;
        border: none;
        /* Rahmen entfernt */
        background: transparent;
        /* Hintergrund entfernt */
        color: #0f172a;
        text-decoration: none;
        font-size: 16px;
        /* Icons etwas größer für bessere Bedienbarkeit ohne Rahmen */
        font-weight: normal;
        cursor: pointer;
        /* Zeiger-Hand beim Drüberfahren */
        line-height: 1;
        white-space: nowrap;
        transition: all 0.2s;
    }

    .pendenzen-action-btn:hover {
        background: rgba(15, 118, 110, 0.1) !important;
        transform: scale(1.1);
        color: #0f766e;
    }

    .pendenzen-inline-input,
    .pendenzen-inline-select {
        width: 100%;
        min-width: 0;
        /* Min-Width entfernt, damit Spalten schmaler werden können */
        padding: 6px 8px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
        color: #0f172a;
        font-size: 12px;
        line-height: 1.2;
    }

    .pendenzen-inline-input:focus,
    .pendenzen-inline-select:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }

    .pendenzen-inline-input[type="date"] {
        min-width: 0;
    }

    .pendenzen-inline-input[type="time"] {
        min-width: 0;
    }

    .pendenzen-inline-input.input-title {
        min-width: 0;
        font-weight: 700;
    }

    .pendenzen-inline-input.input-short {
        min-width: 0;
    }

    .pendenzen-action-btn.save-btn {
        background: #0f766e;
        border-color: #0f766e;
        color: #fff;
    }

    .pendenzen-action-btn.save-btn:hover {
        background: #0d5f59;
        border-color: #0d5f59;
        color: #fff;
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
        overflow: hidden;
        /* Scrollbalken komplett verboten */
        box-shadow: var(--shadow-sm);
        width: 100%;
        max-width: 100%;
        margin-top: 10px;
    }

    .pendenzen-table {
        width: 100%;
        max-width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        /* Zurück auf fixed für absolute Kontrolle und Performance */
    }


    .pendenzen-table th {
        background: #f8fafc;
        color: var(--text-muted);
        text-transform: uppercase;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.05em;
        padding: 8px 10px;
        text-align: left;
        border-bottom: 2px solid var(--border-color);
        cursor: pointer;
        user-select: none;
        transition: background 0.2s;
        position: sticky;
        top: 0;
        z-index: 10;
        white-space: nowrap;
        /* Verhindert Umbruch in Köpfen beim Verkleinern */
        position: relative;
        /* Wichtig für Resizer */
    }

    /* Standardbreiten für wichtige Spalten */
    th[data-col-key="id"] {
        width: 65px;
    }

    th.unt-bell-header {
        width: 45px;
    }

    th[data-col-key="dauer"] {
        width: 50px;
    }

    th[data-label="Aktion"] {
        width: 135px;
    }

    .resizer {
        position: absolute;
        right: 0;
        top: 0;
        height: 100%;
        width: 6px;
        background: transparent;
        cursor: col-resize;
        z-index: 20;
        transition: background 0.2s;
    }

    .resizer:hover {
        background: var(--accent-color);
        opacity: 0.5;
    }

    .pendenzen-table th:hover {
        background: #e2e8f0;
        color: var(--text-main);
    }

    .pendenzen-table td {
        padding: 4px 6px;
        /* Platzsparender */
        border-bottom: 1px solid var(--border-color);
        font-size: 11px;
        /* Minimal kleiner für mehr Spalten-Platz */
        color: var(--text-main);
        vertical-align: middle;
        white-space: nowrap;
        /* Kein Umbruch für kompakte Zeilen */
        overflow: hidden;
        text-overflow: ellipsis;
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

    .pendenzen-pill.unt_erledigt {
        background: #ecfeff;
        color: #0891b2;
        border: 1px solid #0891b2;
    }

    .pendenzen-small {
        font-size: 12px;
        color: var(--text-muted);
        margin-top: 4px;
        line-height: 1.4;
    }

    /* Settings Bar */
    .pendenzen-settings-bar {
        background: #f8fafc;
        padding: 15px;
        border-radius: 12px;
        border: 1px solid var(--border-color);
        margin-bottom: 20px;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .pendenzen-settings-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .settings-label {
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        color: var(--text-muted);
        letter-spacing: 0.05em;
    }

    .settings-controls {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        align-items: center;
    }

    .settings-controls label {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        font-weight: 700;
        color: var(--text-main);
    }

    .settings-controls input[type="range"] {
        accent-color: var(--primary);
        width: 120px;
    }

    .column-toggles-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 15px;
        padding-top: 10px;
        border-top: 1px dashed var(--border-color);
    }

    .column-toggles-grid label {
        font-size: 13px;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
    }

    /* Lightbox */
    .pendenzen-lightbox {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.9);
        z-index: 9999;
        cursor: zoom-out;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .pendenzen-lightbox.active {
        display: flex;
    }

    .pendenzen-lightbox img {
        max-width: 95%;
        max-height: 95%;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
    }

    .lb-nav {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
        border: 0;
        width: 50px;
        height: 50px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background 0.2s;
        z-index: 10000;
        backdrop-filter: blur(4px);
    }

    .lb-nav:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    .lb-prev {
        left: 20px;
    }

    .lb-next {
        right: 20px;
    }

    /* Pulse animation for duplication feedback */
    @keyframes pneu-pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(20, 184, 166, 0.4);
        }

        70% {
            box-shadow: 0 0 0 15px rgba(20, 184, 166, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(20, 184, 166, 0);
        }
    }

    .pneu-highlight {
        animation: pneu-pulse 1.5s ease-out;
        border-color: var(--accent-color) !important;
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
            display: none;
            flex-direction: column;
            gap: 2px;
            padding: 8px 0;
            margin: 0;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
        }

        .pendenzen-dashboard-actions.active {
            display: flex;
        }

        .pendenzen-chip-link {
            flex: 1 1 100%;
            padding: 12px 14px;
            font-size: 14px;
            justify-content: flex-start;
            background: transparent;
            border: 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0;
            backdrop-filter: none;
        }

        .pendenzen-chip-link:last-child {
            border-bottom: 0;
        }

        .pendenzen-chip-link::before {
            content: "✓";
            margin-right: 10px;
            font-size: 12px;
            opacity: 0.5;
        }

        .pendenzen-dashboard-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .pendenzen-dashboard-field.full-mobile {
            grid-column: span 2;
            margin-top: 8px;
        }

        .pendenzen-dashboard-field.full-mobile .pendenzen-nav-group {
            display: flex;
            gap: 10px;
        }

        .pendenzen-dashboard-field.full-mobile .pendenzen-quick-btn {
            flex: 1;
            padding: 14px;
            font-size: 14px;
            height: 48px;
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

        /* Mobile Table Transformation - 2-COLUMN FIELD GRID */
        .pendenzen-table-wrap {
            border: 0;
            background: transparent;
            box-shadow: none;
            overflow: visible;
        }

        .pendenzen-table,
        .pendenzen-table thead,
        .pendenzen-table tbody {
            display: block;
            width: 100%;
        }

        .pendenzen-table thead {
            display: none;
        }

        .pendenzen-table tbody {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .pendenzen-table tr {
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 8px;

            box-shadow: var(--shadow-sm);
            padding: 8px;
            display: grid;
            grid-template-columns: repeat(30, 1fr);
            gap: 6px 8px;
            height: fit-content;
        }

        .pendenzen-table td {
            display: flex;
            flex-direction: column;
            padding: 0 !important;
            border: none;
            min-height: 0;
            grid-column: span 15;
            /* Default: 2 columns per row */
        }

        .pendenzen-table td::before {
            content: attr(data-label);
            font-weight: 800;
            font-size: 7px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 2px;
            line-height: 1;
        }

        /* Triple grouping for header: ID (20%), Art, Proj (Right) */
        .pendenzen-table td[data-col-key="id"],
        .pendenzen-table td[data-col-key="vorgangsart_name"],
        .pendenzen-table td[data-col-key="projekt_name"] {
            order: -10;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 4px !important;
            margin-bottom: 4px;
        }

        .pendenzen-table td[data-col-key="id"] {
            grid-column: span 6;
            /* 20% of 30 columns */
            order: -11;
            flex-direction: row;
            align-items: center;
            gap: 4px;
            font-weight: 800;
            font-size: 13px;
            color: var(--primary);
            justify-content: flex-start;
        }

        .pendenzen-table td[data-col-key="id"]::before {
            content: "ID";
            width: auto;
            font-size: 11px;
            margin-right: 4px;
        }

        .pendenzen-table td[data-col-key="vorgangsart_name"] {
            grid-column: span 12;
            order: -10;
            font-weight: 700;
            align-items: flex-start;
            text-align: left;
        }

        .pendenzen-table td[data-col-key="projekt_name"] {
            grid-column: span 12;
            order: -9;
            font-weight: 700;
            align-items: flex-start;
            text-align: left;
        }

        .pendenzen-table td[data-col-key="projekt_name"]::before {
            align-self: flex-start;
        }


        .pendenzen-table td[data-col-key="erstes_bild"] {
            grid-column: span 20;
            grid-row: span 2;
            order: -8;
        }

        .pendenzen-table td[data-col-key="erstes_bild"]::before {
            display: none;
        }

        .pendenzen-table td[data-col-key="wichtigkeit"],
        .pendenzen-table td[data-col-key="status"] {
            grid-column: span 10;
            order: -8;
            border-bottom: 1px solid #f1f5f9;
            flex-direction: row;
            align-items: center;
            gap: 4px;
            padding: 2px 0 !important;
        }

        .pendenzen-table td[data-col-key="wichtigkeit"]::before,
        .pendenzen-table td[data-col-key="status"]::before {
            margin-bottom: 0;
            width: 35px;
            flex-shrink: 0;
            font-size: 8px;
        }


        .pendenzen-table td[data-col-key="titel"],
        .pendenzen-table td[data-col-key="kurzbeschreibung"] {
            grid-column: span 15;
            order: -7;
            font-weight: 700;
        }


        /* Triple grouping for dates/durations (Start, Dauer, Ende) */
        .pendenzen-table td[data-col-key="startdatum"],
        .pendenzen-table td[data-col-key="enddatum"] {
            grid-column: span 12;
            order: -5;
        }

        .pendenzen-table td[data-col-key="dauer"] {
            grid-column: span 6;
            order: -5;
        }



        .pendenzen-table td[data-label="Aktion"] {
            grid-column: span 30;
        }


        .pendenzen-inline-input,
        .pendenzen-inline-select {
            padding: 2px 4px !important;
            height: 24px !important;
            font-size: 11px !important;
            width: 100% !important;
        }

        .pendenzen-table-img {
            max-width: 100%;
            height: auto !important;
            margin: 4px 0;
            border-radius: 6px;
        }

        .pendenzen-actions {
            padding: 8px 0 0 0;
            margin-top: 4px;
            border-top: 1px dashed #f1f5f9;
            display: flex;
            justify-content: space-around;
            gap: 8px;
        }

        .pendenzen-action-btn {
            padding: 4px 8px !important;
            flex: 1;
        }

        .pendenzen-pill {
            padding: 0 4px;
            font-size: 10px;
        }

        .pendenzen-small {
            font-size: 10px;
            margin-top: 0;
        }
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
        width: 6px;
        background: transparent;
        cursor: col-resize;
        user-select: none;
        z-index: 100;
        transition: background 0.2s;
    }

    .resizer:hover {
        background: var(--primary);
        opacity: 0.5;
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

    .pendenzen-gallery-container {
        display: flex;
        flex-direction: row;
        gap: 6px;
        align-items: flex-start;
    }

    .pendenzen-gallery-thumbs {
        display: flex;
        flex-direction: column;
        gap: 3px;
        padding-top: 2px;
    }

    .pendenzen-gallery-thumbs .thumb-img-mini {
        width: 12px !important;
        height: 12px !important;
        min-height: 12px !important;
        object-fit: cover;
        margin: 0 !important;
        padding: 0 !important;
        border: 1px solid rgba(0, 0, 0, 0.1) !important;
        border-radius: 2px;
    }

    .pendenzen-gallery-thumbs .thumb-img-mini:hover {
        transform: scale(1.2);
        z-index: 5;
    }



    /* General Modal Styles (Premium) */
    .pendenzen-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        padding: 20px;
    }

    .pendenzen-modal-content {
        background: #fff;
        width: 100%;
        max-width: 1200px;
        max-height: 90vh;
        border-radius: 12px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .pendenzen-modal-header {
        padding: 16px 24px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .pendenzen-modal-header h3 {
        margin: 0;
        font-size: 18px;
        color: #0f172a;
    }

    .pendenzen-modal-close {
        font-size: 24px;
        color: #64748b;
        background: none;
        border: none;
        cursor: pointer;
        transition: color 0.2s;
    }

    .pendenzen-modal-close:hover {
        color: #0f172a;
    }

    .pendenzen-modal-body {
        flex: 1;
        overflow-y: auto;
        padding: 0;
        background: #f1f5f9;
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

    /* Sort Arrows */
    .pendenzen-table th[data-sort] {
        position: relative;
        padding-right: 20px;
    }

    .pendenzen-table th[data-sort]::after {
        content: '↕';
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 10px;
        opacity: 0.2;
    }

    .pendenzen-table th.sort-asc::after {
        content: '↑';
        opacity: 1;
        color: var(--primary);
    }

    .pendenzen-table th.sort-desc::after {
        content: '↓';
        opacity: 1;
        color: var(--primary);
    }
</style>

<div class="pendenzen-page">
    <div class="pendenzen-wrap">

        <?php if (!empty($recent)): ?>
            <div class="recent-bar-container">
                <div class="recent-bar-header">
                    <span>🕒 Zuletzt erfasst</span>
                    <span class="pendenzen-note">Letzte 8 Einträge</span>
                </div>
                <div class="recent-bar-scroller">
                    <?php foreach (array_slice($recent, 0, 8) as $r): ?>
                        <a href="pendenz_show.php?id=<?php echo $r['id']; ?>" class="recent-item">
                            <div class="recent-img">
                                <?php if (!empty($r['first_image'])): ?>
                                    <img src="../<?php echo h(ltrim($r['first_image'], '/')); ?>">
                                <?php else: ?>
                                    <span style="font-size:10px; opacity:0.3">No Img</span>
                                <?php endif; ?>
                            </div>
                            <div class="recent-info">
                                <div class="recent-title"><?php echo h($r['titel']); ?></div>
                                <div class="recent-meta">#<?php echo $r['id']; ?> · <?php echo h($r['status']); ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="pendenzen-dashboard">
            <div class="pendenzen-dashboard-top">
                <div>
                    <div class="pendenzen-dashboard-title">Pendenzen Dashboard</div>
                    <div class="pendenzen-dashboard-sub">Neue Reihenfolge: Vorgang, Objekt, Wohnung, Raum, Unternehmer
                    </div>
                </div>

                <div class="pendenzen-dashboard-actions-container">
                    <label class="pendenzen-options-toggle hidden-desktop">
                        <input type="checkbox" id="toggleDashActions"> ⚙️ Optionen
                    </label>

                    <div class="pendenzen-dashboard-actions" id="dashActionsList">
                        <a class="pendenzen-chip-link" href="listen_settings.php">⚙️ Tabellen-Architekt</a>
                        <a class="pendenzen-chip-link" href="vorgangsart_settings.php">⚙️ Vorgangs-Architekt</a>
                        <a class="pendenzen-chip-link" href="pendenz_kategorien.php">🗂️ Vorlagen-Übersicht</a>
                        <a class="pendenzen-chip-link" href="pendenz_kategorien_mieter.php">🏠 Mieterkategorien</a>
                        <a class="pendenzen-chip-link" href="pendenz_kategorien_vermieter.php">🏢
                            Vermieterkategorien</a>
                        <a class="pendenzen-chip-link" href="bkp_codes.php">🧱 BKP</a>
                        <a class="pendenzen-chip-link" href="#" id="btnOpenProtocolModal">📄 Protokoll / PDF Export</a>
                        <a class="pendenzen-chip-link" href="#" id="btnOpenManagerModal"
                            style="background: #6366f1; color: #fff;">📄 protokoll_manager / PDF Export</a>
                    </div>
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
                    <label for="quick_projekt_id">🏗️ Projekt</label>
                    <select id="quick_projekt_id">
                        <option value="">— Projekt wählen —</option>
                        <?php foreach ($projekte as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>" <?php echo ((int) $input['projekt_id'] === (int) $row['id']) ? 'selected' : ''; ?>>
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
                    <label for="quick_raum_id">📍 Raum</label>
                    <select id="quick_raum_id">
                        <option value="">— Raum wählen —</option>
                        <?php foreach ($raeume as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>"
                                data-wohnung-id="<?php echo (int) $row['wohnung_id']; ?>" <?php echo ((int) ($input['raum_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>>
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

                <div class="pendenzen-dashboard-field full-mobile">
                    <label>&nbsp;</label>
                    <div class="pendenzen-nav-group">
                        <button type="button" id="btnPrevWohnung" class="pendenzen-quick-btn" style="flex:1;">←
                            Vorherige</button>
                        <button type="button" id="btnNextWohnung" class="pendenzen-quick-btn" style="flex:1;">Nächste
                            →</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="formTopMarker" style="position:relative;top:-20px;"></div>
        <div class="pendenzen-card" id="pendenzNeuContainer" style="margin-bottom:18px;">

            <button type="button" id="toggleForm" class="pendenzen-collapse-toggle"
                style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;background:#fff;border:0;cursor:pointer;">
                <span style="display:flex;align-items:center;gap:10px;">
                    <span class="toggle-icon"
                        style="display:inline-flex;transition:transform .2s ease;transform:rotate(-90deg);">▾</span>
                    <span style="font-weight:800;color:var(--text-main);">Neue Pendenz</span>
                    <span id="formStatusPill"
                        style="display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:700;">Eingeklappt</span>
                </span>
                <span style="font-size:12px;color:var(--text-muted);font-weight:700;">Separat eingebunden</span>
            </button>
            <div id="formContent"
                style="display:none;padding:0 18px 18px 18px;border-top:1px solid var(--border-color);">
                <iframe id="pendenzNeuFrame"
                    src="pendenz_neu.php?embed=1<?php echo ($editPendenzId ? '&edit_id=' . (int) $editPendenzId : ''); ?>"
                    title="Neue Pendenz"
                    style="width:100%;min-height:1650px;border:0;border-radius:14px;background:transparent;"
                    loading="lazy"></iframe>
            </div>
        </div>

        <div class="pendenzen-card">
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
                                    <option value="<?php echo (int) $prof['id']; ?>"
                                        data-cols="<?php echo h((string) ($prof['cols'] ?? '')); ?>" <?php echo ($activeProfile && (int) $activeProfile['id'] === (int) $prof['id']) ? 'selected' : ''; ?>>
                                        <?php echo h($prof['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="tableSettingsPanel" class="pendenzen-settings-bar" style="display: none;">
                        <div class="pendenzen-settings-group">
                            <div class="settings-label">📱 Bildgrößen-Steuerung</div>
                            <div class="settings-controls">
                                <label>
                                    🖥️ Desktop:
                                    <input type="range" id="imgSizeSliderDesktop" min="40" max="300" value="100">
                                    <span id="imgSizeValDesktop" style="font-family: monospace;">100px</span>
                                </label>
                                <label>
                                    📱 Mobile:
                                    <input type="range" id="imgSizeSliderMobile" min="40" max="400" value="100">
                                    <span id="imgSizeValMobile" style="font-family: monospace;">100px</span>
                                </label>
                            </div>
                        </div>

                        <div class="pendenzen-settings-group">
                            <div class="settings-label">🔍 Spalten einblenden / ausblenden</div>
                            <div class="column-toggles-grid" id="columnToggles">
                                <?php foreach ($columnDef as $key => $def): ?>
                                    <label>
                                        <input type="checkbox" data-col-key="<?php echo h($key); ?>" <?php echo in_array($key, $displayCols) ? 'checked' : ''; ?>>
                                        <?php echo h($def['label']); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
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
                                    <th class="unt-bell-header" data-col-key="bell"
                                        style="text-align: center; border-right: 1px solid var(--border-color);">🔔</th>
                                    <?php foreach ($displayCols as $colKey):
                                        $colKey = trim($colKey);
                                        if (!isset($columnDef[$colKey]))
                                            continue;
                                        $def = $columnDef[$colKey];
                                        ?>
                                        <th data-sort="<?php echo h($def['sort']); ?>"
                                            data-label="<?php echo h($def['label']); ?>"
                                            data-col-key="<?php echo h($colKey); ?>">
                                            <?php echo h($def['label']); ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th data-label="Aktion">Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recent): ?>
                                    <tr>
                                        <td colspan="26" class="pendenzen-table-empty">Keine Pendenzen gefunden.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent as $row):
                                        $statusClass = '';
                                        $statusLow = mb_strtolower((string) ($row['status'] ?? ''));
                                        if (str_contains($statusLow, 'offen'))
                                            $statusClass = 'offen';
                                        elseif (str_contains($statusLow, 'bearbeitung'))
                                            $statusClass = 'bearbeitung';
                                        elseif (str_contains($statusLow, 'erledigt'))
                                            $statusClass = 'erledigt';
                                        elseif (str_contains($statusLow, 'archiv'))
                                            $statusClass = 'archiviert';

                                        $inlineFormId = 'inline-edit-' . (int) $row['id'];
                                        ?>
                                        <tr data-projekt="<?php echo h((string) ($row['projekt_name'] ?? '')); ?>"
                                            data-status="<?php echo h((string) ($row['status'] ?? '')); ?>"
                                            data-zustaendig="<?php echo h((string) ($row['zustaendig_name'] ?? '')); ?>"
                                            data-wichtigkeit="<?php echo h((string) ($row['wichtigkeit'] ?? '')); ?>"
                                            class="<?php echo $statusClass; ?>">

                                            <td
                                                style="text-align: center; vertical-align: middle; border-right: 1px solid var(--border-color);">
                                                <?php if (!empty($row['unt_new_input'])): ?>
                                                    <span class="unt-notif-bell" data-id="<?php echo (int) $row['id']; ?>"
                                                        title="Neue Unternehmer-Rückmeldung! (Klicken zum Als gelesen markieren)"
                                                        style="cursor:pointer; font-size:18px; filter: drop-shadow(0 0 2px #3b82f6); transition: all 0.3s ease; display: inline-block;"
                                                        onclick="resetUntBell(this, <?php echo (int) $row['id']; ?>)">🔔</span>
                                                <?php endif; ?>
                                            </td>

                                            <?php foreach ($displayCols as $colKey):
                                                $colKey = trim($colKey);
                                                if (!isset($columnDef[$colKey]))
                                                    continue;
                                                $def = $columnDef[$colKey];
                                                ?>
                                                <td data-label="<?php echo h($def['label']); ?>"
                                                    data-col-key="<?php echo h($colKey); ?>">
                                                    <?php
                                                    switch ($colKey):
                                                        case 'id':
                                                            echo h($row['id']);
                                                            break;
                                                        case 'erstes_bild':
                                                            $images = !empty($row['all_images']) ? explode(',', $row['all_images']) : [];
                                                            if (!empty($images)): ?>
                                                                <div class="pendenzen-gallery-container">
                                                                    <img src="../<?php echo h(ltrim($images[0], '/')); ?>"
                                                                        class="pendenzen-table-img main-img">
                                                                    <?php if (count($images) > 1): ?>
                                                                        <div class="pendenzen-gallery-thumbs hidden-desktop">
                                                                            <?php for ($i = 1; $i < count($images); $i++): ?>
                                                                                <img src="../<?php echo h(ltrim($images[$i], '/')); ?>"
                                                                                    class="pendenzen-table-img thumb-img-mini">
                                                                            <?php endfor; ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="pendenzen-table-img placeholder"
                                                                    style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:10px;">
                                                                    -</div>
                                                            <?php endif;
                                                            break;

                                                        case 'titel': ?>
                                                            <div style="display:flex; align-items:center; gap:6px; flex:1;">
                                                                <input class="pendenzen-inline-input input-title" type="text"
                                                                    name="inline_titel" style="flex:1;"
                                                                    form="<?php echo h($inlineFormId); ?>"
                                                                    value="<?php echo h((string) ($row['titel'] ?? '')); ?>">
                                                            </div>
                                                            <?php break;
                                                        case 'projekt_name': ?>
                                                            <select class="pendenzen-inline-select inline-projekt-select"
                                                                name="inline_projekt_id" data-row-id="<?php echo (int) $row['id']; ?>"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php foreach ($projekte as $p): ?>
                                                                    <option value="<?php echo (int) $p['id']; ?>" <?php echo ((int) ($row['projekt_id'] ?? 0) === (int) $p['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h((string) $p['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'objekt_name': ?>
                                                            <select class="pendenzen-inline-select inline-objekt-select"
                                                                name="inline_objekt_id" data-row-id="<?php echo (int) $row['id']; ?>"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php foreach ($objekte as $o): ?>
                                                                    <option value="<?php echo (int) $o['id']; ?>"
                                                                        data-projekt-id="<?php echo (int) ($o['projekt_id'] ?? 0); ?>" <?php echo ((int) ($row['objekt_id'] ?? 0) === (int) $o['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h((string) $o['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'wohnung_name': ?>
                                                            <select class="pendenzen-inline-select inline-wohnung-select"
                                                                name="inline_wohnung_id" data-row-id="<?php echo (int) $row['id']; ?>"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php foreach ($wohnungen as $w): ?>
                                                                    <option value="<?php echo (int) $w['id']; ?>"
                                                                        data-objekt-id="<?php echo (int) ($w['objekt_id'] ?? 0); ?>" <?php echo ((int) ($row['wohnung_id'] ?? 0) === (int) $w['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h((string) $w['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'raum_name': ?>
                                                            <select class="pendenzen-inline-select inline-raum-select" name="inline_raum_id"
                                                                data-row-id="<?php echo (int) $row['id']; ?>"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php foreach ($raeume as $r): ?>
                                                                    <option value="<?php echo (int) $r['id']; ?>"
                                                                        data-wohnung-id="<?php echo (int) ($r['wohnung_id'] ?? 0); ?>" <?php echo ((int) ($row['raum_id'] ?? 0) === (int) $r['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h((string) $r['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'status': ?>
                                                            <select class="pendenzen-inline-select" name="inline_status"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <?php foreach (['offen', 'in Bearbeitung', 'Unt. Erledigt.', 'erledigt', 'archiviert'] as $opt): ?>
                                                                    <option value="<?php echo h($opt); ?>" <?php echo ((string) ($row['status'] ?? '') === $opt) ? 'selected' : ''; ?>><?php echo h($opt); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'wichtigkeit': ?>
                                                            <select class="pendenzen-inline-select" name="inline_wichtigkeit"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <option value="<?php echo $i; ?>" <?php echo ((string) ($row['wichtigkeit'] ?? '') === (string) $i) ? 'selected' : ''; ?>>P<?php echo $i; ?></option>
                                                                <?php endfor; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'zustaendig_name': ?>
                                                            <select class="pendenzen-inline-select" name="inline_zustaendig_id"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php foreach ($benutzer as $ben): ?>
                                                                    <option value="<?php echo (int) $ben['id']; ?>" <?php echo ((int) ($row['zustaendig_id'] ?? 0) === (int) $ben['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h((string) $ben['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'startdatum': ?>
                                                            <input class="pendenzen-inline-input" type="date" name="inline_startdatum"
                                                                form="<?php echo h($inlineFormId); ?>"
                                                                value="<?php echo h((string) ($row['startdatum'] ?? '')); ?>">
                                                            <?php break;
                                                        case 'enddatum': ?>
                                                            <input class="pendenzen-inline-input" type="date" name="inline_enddatum"
                                                                form="<?php echo h($inlineFormId); ?>"
                                                                value="<?php echo h((string) ($row['enddatum'] ?? '')); ?>">
                                                            <?php break;
                                                        case 'uhrzeit': ?>
                                                            <input class="pendenzen-inline-input" type="time" name="inline_uhrzeit"
                                                                form="<?php echo h($inlineFormId); ?>"
                                                                value="<?php echo h((string) ($row['uhrzeit'] ?? '')); ?>">
                                                            <?php break;
                                                        case 'dauer': ?>
                                                            <input class="pendenzen-inline-input" type="text" name="inline_dauer"
                                                                form="<?php echo h($inlineFormId); ?>"
                                                                value="<?php echo h((string) ($row['dauer'] ?? '')); ?>">
                                                            <?php break;
                                                        case 'vorgangsart_name': ?>
                                                            <select class="pendenzen-inline-select" name="inline_vorgangsart_id"
                                                                form="<?php echo h($inlineFormId); ?>">
                                                                <option value="">-</option>
                                                                <?php foreach ($arten as $art): ?>
                                                                    <option value="<?php echo (int) $art['id']; ?>" <?php echo ((int) ($row['vorgangsart_id'] ?? 0) === (int) $art['id']) ? 'selected' : ''; ?>>
                                                                        <?php echo h((string) $art['name']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <?php break;
                                                        case 'count_images':
                                                            echo (int) ($row['count_images'] ?? 0) ?: '-';
                                                            break;
                                                        case 'unt_bemerkung': ?>
                                                            <input class="pendenzen-inline-input" type="text" name="inline_unt_bemerkung"
                                                                form="<?php echo h($inlineFormId); ?>"
                                                                value="<?php echo h((string) ($row['unt_bemerkung'] ?? '')); ?>">
                                                            <?php break;
                                                        case 'count_files':
                                                            echo (int) ($row['count_files'] ?? 0) ?: '-';
                                                            break;
                                                        case 'pdf':
                                                            echo (str_contains(strtolower((string) ($row['titel'] ?? '')), 'pdf') || ($row['count_files'] ?? 0) > 0) ? '📕' : '-';
                                                            break;
                                                        case 'kurzbeschreibung': ?>
                                                            <input class="pendenzen-inline-input input-short" type="text"
                                                                name="inline_kurzbeschreibung" form="<?php echo h($inlineFormId); ?>"
                                                                value="<?php echo h((string) ($row['kurzbeschreibung'] ?? '')); ?>">
                                                            <?php break;
                                                        default:
                                                            echo h((string) ($row[$colKey] ?? '-'));
                                                            break;
                                                    endswitch;
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td data-label="Aktion">
                                                <form id="<?php echo h($inlineFormId); ?>" method="post">
                                                    <input type="hidden" name="form_action" value="inline_update">
                                                    <input type="hidden" name="inline_pendenz_id"
                                                        value="<?php echo (int) $row['id']; ?>">
                                                </form>
                                                <div class="pendenzen-actions">
                                                    <button type="submit" class="pendenzen-action-btn save-btn"
                                                        form="<?php echo h($inlineFormId); ?>" title="Speichern">💾</button>

                                                    <button type="button" class="pendenzen-action-btn btn-duplicate-task"
                                                        data-id="<?php echo (int) $row['id']; ?>" title="Duplizieren">👯</button>

                                                    <a class="pendenzen-action-btn btn-edit-professional"
                                                        href="pendenzen.php?edit_id=<?php echo (int) $row['id']; ?>"
                                                        data-id="<?php echo (int) $row['id']; ?>" title="Bearbeiten">✏️</a>
                                                    <a class="pendenzen-action-btn"
                                                        href="pendenz_show.php?id=<?php echo (int) $row['id']; ?>"
                                                        title="Details anzeigen">📋</a>
                                                    <a class="pendenzen-action-btn"
                                                        href="pendenz_pdf.php?id=<?php echo (int) $row['id']; ?><?php echo (!empty($row['public_token'])) ? '&t=' . h($row['public_token']) : ''; ?>"
                                                        title="PDF öffnen">📕</a>
                                                </div>
                                            </td>
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

<!-- Duplication Modal (Popup Fenster) -->
<div id="duplicationModal" class="pendenzen-modal" style="display: none;">
    <div class="pendenzen-modal-content">
        <div class="pendenzen-modal-header">
            <h3>👯 Pendenz duplizieren</h3>
            <button class="pendenzen-modal-close" id="btnCloseDuplication">&times;</button>
        </div>
        <div class="pendenzen-modal-body">
            <iframe id="duplicationFrame" src="about:blank" style="width: 100%; height: 80vh; border: none;"></iframe>
        </div>
    </div>
</div>

<!-- Protocol Designer Modal (Optimized Mask) -->
<div id="protocolModal" class="pendenzen-modal" style="display: none;">
    <div class="pendenzen-modal-content" style="max-width: 1000px; border-radius: 4px;">
        <div class="pendenzen-modal-header"
            style="background: #f8fafc; border-bottom: 2px solid #0f172a; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <h3 style="text-transform: uppercase; letter-spacing: 1px; margin: 0;">📝 Protokoll-Konfigurator</h3>
                <div style="display: flex; gap: 10px;">
                    <!-- LINKS: Laden (Muster & Formulare) -->
                    <div style="display: flex; gap: 10px; padding-right: 15px; border-right: 1px solid #e2e8f0;">
                        <!-- 1. Muster -->
                        <div
                            style="display: flex; gap: 5px; background: #fff; padding: 4px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <select id="templateLoader"
                                style="font-size: 11px; border: none; padding: 2px 5px; outline: none; min-width: 130px;">
                                <option value="">-- Muster laden --</option>
                            </select>
                        </div>
                        <!-- 2. Formulare -->
                        <div
                            style="display: flex; gap: 5px; background: #fff; padding: 4px; border: 1px solid #cbd5e1; border-radius: 6px;">
                            <select id="protocolLoader"
                                style="font-size: 11px; border: none; padding: 2px 5px; outline: none; min-width: 150px;">
                                <option value="">-- Dokument laden --</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; color: #64748b; font-size: 14px;">⮕</div>

                    <!-- RECHTS: Speichern unter (Kategorie) -->
                    <div
                        style="display: flex; gap: 5px; background: #fff; padding: 4px; border: 1px solid #00a896; border-radius: 6px;">
                        <select id="categoryPicker"
                            style="font-size: 11px; border: none; padding: 2px 5px; outline: none; min-width: 150px;">
                            <option value="">-- Ziel-Kategorie --</option>
                        </select>
                        <button type="button" id="btnSaveAsNew" title="Als neues Dokument speichern"
                            style="background: none; border: none; cursor: pointer; font-size: 14px;">➕</button>
                    </div>

                    <input type="hidden" id="activeProtocolId" value="">
                </div>
            </div>
            <button class="pendenzen-modal-close" id="btnCloseProtocol">&times;</button>
        </div>
        <div class="pendenzen-modal-body" style="background: #fff; padding: 40px;">
            <div id="modalHeaderManager"
                style="position: relative; margin-bottom: 30px; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px;">
                <div id="modalModeIndicator" class="mode-indicator mode-new"
                    style="position: absolute; top: -15px; right: 0; padding: 4px 15px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 10; background: #94a3b8; color: #fff;">
                    STARTSEITE</div>

                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 10px;">
                    <h2 id="modalFormTitle" style="display:none;">Neues Protokoll</h2> <!-- Hidden legacy title -->
                    <input type="text" name="p_title" id="p_title" value="Koordinationssitzung"
                        style="font-size: 24px; font-weight: 900; color: #0f172a; border: none; background: none; padding: 0; flex: 1; border-bottom: 2px solid transparent; transition: 0.2s;"
                        onfocus="this.style.borderBottomColor='var(--primary)'"
                        onblur="this.style.borderBottomColor='transparent'">
                </div>
                <div id="modalFormSubtitle" style="color: #64748b; margin: 0; font-size: 13px; margin-bottom: 20px;">
                    Bereiten Sie hier Ihre Protokoll-Maske vor.</div>

                <!-- Workflow Guide Banner -->
                <div id="modalWorkflowGuide"
                    style="padding: 12px 20px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 600; background: rgba(155, 89, 182, 0.1); color: #9b59b6;">
                    <span id="modalWorkflowIcon" style="font-size: 18px;">✨</span>
                    <span id="modalWorkflowText">ERSTELLUNGS-MODUS: Legen Sie eine neue Haupt-Struktur fest oder laden
                        Sie ein Muster.</span>
                </div>

                <!-- Status Bar -->
                <div
                    style="background: #f8fafc; padding: 20px 25px; border-radius: 12px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border: 2px solid #f1f5f9;">
                    <div style="font-size: 13px; display:flex; align-items:center; gap:25px; flex:1;">
                        <div style="display:flex; flex-direction:column; gap:5px; flex:1;">
                            <span
                                style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">Dokument-Name</span>
                            <div id="labelDocName" style="font-weight:700; color:#0f172a; font-size:15px;">Unbenannt
                            </div>
                        </div>
                        <div style="width:1px; height:35px; background:#e2e8f0;"></div>
                        <div style="display:flex; flex-direction:column; gap:5px; flex:1;">
                            <span
                                style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">Zugeordnete
                                Master-Vorlage</span>
                            <span id="labelMasterName" style="color:#0f172a; font-weight:600; font-size:13px;">Keine
                                Vorlage gewählt</span>
                        </div>
                        <div style="width:1px; height:35px; background:#e2e8f0;"></div>
                        <div style="display:flex; flex-direction:column; gap:5px; flex:1;">
                            <span
                                style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">Kategorie</span>
                            <span id="labelTypeName" style="color:#64748b; font-size:13px;">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <form id="protocolForm">
                <!-- Top Fields -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 30px;">
                    <div class="field-group" style="display:flex; flex-direction:column; gap:8px;">
                        <label
                            style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">BETREFF
                            / THEMA</label>
                        <input type="text" name="p_subject" id="p_subject" value="KW <?= date('W') ?> Protokoll"
                            style="border: 2px solid #f1f5f9; padding: 12px 15px; border-radius: 10px; font-size: 14px; background: #f8fafc; font-weight: 500; width: 100%;">
                    </div>
                    <div style="text-align: right; display:flex; flex-direction:column; align-items:flex-end;">
                        <label
                            style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom:5px;">LOGO
                            (VORSCHAU)</label>
                        <div
                            style="height: 45px; width: 200px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #94a3b8; border: 1px dashed #cbd5e1; border-radius: 8px;">
                            Logo aus Einstellungen</div>
                    </div>
                </div>

                <!-- BASIS DATEN SECTION -->
                <div
                    style="background: #fff; border: 2px solid #f1f5f9; border-radius: 16px; padding: 30px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div
                        style="font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        📄 BASIS-INFORMATIONEN
                    </div>

                    <div style="display: grid; grid-template-columns: 180px 1fr; gap: 20px; align-items: center;">
                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">
                            PROJEKT-TITEL (PDF Header):</div>
                        <input type="text" name="p_pdf_title" id="p_pdf_title" value="Protokoll"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 700; width: 100%; color: var(--primary); background: #f8fafc;">

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">TITEL
                            DER LISTE:</div>
                        <input type="text" name="p_list_title" id="p_list_title" value="Pendenzenliste"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 700; width: 100%; background: #f8fafc;">

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">🏗️
                            PROJEKT:</div>
                        <select name="p_project" id="p_project" onchange="filterProtocolObjekte()"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 700; width: 100%; background: #f8fafc; cursor: pointer;">
                            <option value="">-- Projekt wählen --</option>
                            <?php foreach ($projekte as $row): ?>
                                <option value="<?= (int) $row['id'] ?>"><?= h($row['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">🏢
                            OBJEKT:</div>
                        <select name="p_object" id="p_object" onchange="filterProtocolWohnungen()"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 700; width: 100%; background: #f8fafc; cursor: pointer;">
                            <option value="">-- Objekt wählen --</option>
                            <?php foreach ($objekte as $row): ?>
                                <option value="<?= (int) $row['id'] ?>" data-projekt-id="<?= (int) $row['projekt_id'] ?>">
                                    <?= h($row['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">🏠
                            WOHNUNG:</div>
                        <select name="p_apartment" id="p_apartment"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 700; width: 100%; background: #f8fafc; cursor: pointer;">
                            <option value="">-- Wohnung wählen --</option>
                            <?php foreach ($wohnungen as $row): ?>
                                <option value="<?= (int) $row['id'] ?>" data-objekt-id="<?= (int) $row['objekt_id'] ?>">
                                    <?= h($row['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">📅
                            DATUM / ZEIT:</div>
                        <input type="text" name="p_datetime" id="p_datetime"
                            value="<?= date('d.m.Y') ?>, <?= date('H:i') ?> Uhr"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 600; width: 100%; background: #f8fafc;">

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">📍
                            ORT:</div>
                        <input type="text" name="p_location" id="p_location" value="Baubüro"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 600; width: 100%; background: #f8fafc;">

                        <div style="font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase;">👤
                            ERSTELLT DURCH:</div>
                        <select name="p_leader" id="p_leader"
                            style="border: 2px solid #f1f5f9; padding: 10px 15px; border-radius: 8px; font-weight: 700; width: 100%; color: var(--primary); background: #f8fafc; cursor: pointer;">
                            <?php foreach ($benutzer as $u): ?>
                                <option value="<?= h($u['name']) ?>" <?= ($_SESSION['user_id'] == $u['id']) ? 'selected' : '' ?>>
                                    <?= h($u['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- 2. ERWEITERTE WERKDETAILS -->
                <div id="sectionWerkdetails"
                    style="background: #f8fafc; border: 2px solid #f1f5f9; border-radius: 16px; padding: 30px; margin-bottom: 30px; display: none;">
                    <div
                        style="font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        🏗️ Erweiterte Werkdetails (Optional)
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                        <div class="field-group" style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Objekt-Nr.</label>
                            <input type="text" id="p_obj_nr"
                                style="border: 2px solid #e2e8f0; padding: 10px; border-radius: 8px; font-size: 13px; background: #fff;">
                        </div>
                        <div class="field-group" style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Werkvertrag
                                vom</label>
                            <input type="text" id="p_wv_date"
                                style="border: 2px solid #e2e8f0; padding: 10px; border-radius: 8px; font-size: 13px; background: #fff;">
                        </div>
                        <div class="field-group" style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">WV-Nr.</label>
                            <input type="text" id="p_wv_nr"
                                style="border: 2px solid #e2e8f0; padding: 10px; border-radius: 8px; font-size: 13px; background: #fff;">
                        </div>
                        <div class="field-group" style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Betr.
                                BKP</label>
                            <input type="text" id="p_bkp"
                                style="border: 2px solid #e2e8f0; padding: 10px; border-radius: 8px; font-size: 13px; background: #fff;">
                        </div>
                        <div class="field-group" style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;">Grundstück</label>
                            <input type="text" id="p_land"
                                style="border: 2px solid #e2e8f0; padding: 10px; border-radius: 8px; font-size: 13px; background: #fff;">
                        </div>
                        <div class="field-group" style="display:flex; flex-direction:column; gap:5px;">
                            <label
                                style="font-size: 10px; font-weight: 700; color: #ef4444; text-transform: uppercase;">Behebung
                                bis</label>
                            <input type="text" id="p_fix_date"
                                style="border: 2px solid #fee2e2; padding: 10px; border-radius: 8px; font-size: 13px; background: #fff; color: #ef4444; font-weight: 800;">
                        </div>
                    </div>
                </div>

                <!-- 3. ZUSÄTZLICHE INHALTSBLÖCKE -->
                <div
                    style="background: #fff; border: 2px solid #f1f5f9; border-radius: 16px; padding: 30px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">
                        <div
                            style="font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px;">
                            🔧 Zusätzliche Inhaltsblöcke</div>
                        <button type="button" onclick="addCustomSection()"
                            style="padding: 8px 16px; font-size: 12px; font-weight: 700; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer; transition: 0.2s;"
                            onmouseover="this.style.background='#e2e8f0'"
                            onmouseout="this.style.background='#f1f5f9'">Block hinzufügen +</button>
                    </div>
                    <div id="customSectionsContainer">
                        <!-- JS fills this -->
                    </div>
                </div>

                <!-- 4. TEILNEHMER -->
                <div
                    style="background: #fff; border: 2px solid #f1f5f9; border-radius: 16px; padding: 30px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div
                            style="font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px;">
                            👥 Teilnehmer Sitzung</div>
                        <div style="display: flex; gap: 10px;">
                            <select id="userPicker"
                                style="font-size: 13px; padding: 10px; border-radius: 8px; border: 2px solid #f1f5f9; width: 250px; background: #f8fafc; font-weight: 600;">
                                <option value="">-- Teilnehmer auswählen --</option>
                                <?php foreach ($benutzer as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>" data-name="<?= h($u['name']) ?>"
                                        data-company="<?= h($u['firma'] ?? '-') ?>" data-email="<?= h($u['email'] ?? '') ?>"
                                        data-tel="<?= h($u['telefon'] ?? $u['mobile'] ?? '') ?>"
                                        data-position="<?= h($u['position'] ?? '-') ?>">
                                        <?= h($u['name']) ?> (<?= h($u['firma'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="btnAddParticipant"
                                style="padding: 10px 20px; background: #0f172a; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 800;">Hinzufügen</button>
                        </div>
                    </div>

                    <div style="border: 2px solid #f1f5f9; border-radius: 12px; overflow: hidden;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;" id="participantTable">
                            <thead>
                                <tr style="background: #f8fafc; border-bottom: 2px solid #f1f5f9;">
                                    <th
                                        style="padding: 12px; text-align: left; width: 100px; color: #64748b; font-size: 10px; font-weight: 800; text-transform: uppercase;">
                                        ENTSCH.</th>
                                    <th
                                        style="padding: 12px; text-align: left; color: #64748b; font-size: 10px; font-weight: 800; text-transform: uppercase;">
                                        NAME</th>
                                    <th
                                        style="padding: 12px; text-align: left; color: #64748b; font-size: 10px; font-weight: 800; text-transform: uppercase;">
                                        POSITION</th>
                                    <th
                                        style="padding: 12px; text-align: left; color: #64748b; font-size: 10px; font-weight: 800; text-transform: uppercase;">
                                        EMAIL / TEL</th>
                                    <th
                                        style="padding: 12px; text-align: left; color: #64748b; font-size: 10px; font-weight: 800; text-transform: uppercase;">
                                        FIRMA</th>
                                    <th style="padding: 12px; width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- JS -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 5. TEXTE & EINLEITUNG -->
                <div
                    style="background: #fff; border: 2px solid #f1f5f9; border-radius: 16px; padding: 30px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div
                        style="font-size: 14px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px;">
                        📝 Texte & Einleitung
                    </div>
                    <div style="margin-bottom: 25px;">
                        <label
                            style="display:block; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Zusammenfassung
                            / Einleitung</label>
                        <textarea name="p_intro" id="p_intro" rows="4"
                            style="width: 100%; border: 2px solid #f1f5f9; border-radius: 10px; padding: 15px; font-size: 14px; background: #f8fafc; font-weight: 500;"
                            placeholder="Fortschritt der Arbeiten..."></textarea>
                    </div>
                    <div>
                        <label
                            style="display:block; font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Schlusswort
                            / Regelung</label>
                        <textarea name="p_outro" id="p_outro" rows="3"
                            style="width: 100%; border: 2px solid #f1f5f9; border-radius: 10px; padding: 15px; font-size: 13px; background: #f8fafc; font-style: italic;"
                            placeholder="Besonderheiten dokumentiert."></textarea>
                    </div>
                </div>

                <!-- 6. CHECKBOXEN (NUR BEI ABNAHME) -->
                <div id="sectionCheckboxes"
                    style="margin-bottom: 30px; border: 2px solid #0f172a; padding: 25px; border-radius: 16px; display: none; background: #fcfcfc;">
                    <div
                        style="font-weight: 800; margin-bottom: 15px; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">
                        Prüfung der Vornahme UN / Abnahme:</div>
                    <div style="display: flex; gap: 30px; font-size: 13px; font-weight: 600;">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="p_art159" id="p_art159" style="width:18px; height:18px;"> Art.
                            159 - keine Mängel
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="p_art160" id="p_art160" style="width:18px; height:18px;"> Art.
                            160 - unwesentliche Mängel
                        </label>
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="p_art161" id="p_art161" style="width:18px; height:18px;"> Art.
                            161 - wesentliche Mängel
                        </label>
                    </div>
                </div>

                <!-- Unterschriften Felder -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <div
                            style="font-weight: bold; color: #64748b; font-size: 10px; margin-bottom: 5px; text-transform: uppercase;">
                            Unterschrift Besteller / Kunde</div>
                        <div
                            style="border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; position: relative;">
                            <canvas id="sigBesteller" width="400" height="150"
                                style="width: 100%; height: 150px; cursor: crosshair;"></canvas>
                            <button type="button" class="btnClearSig" data-target="sigBesteller"
                                style="position: absolute; top: 5px; right: 5px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 3px; font-size: 10px; cursor: pointer;">Löschen</button>
                        </div>
                    </div>
                    <div>
                        <div
                            style="font-weight: bold; color: #64748b; font-size: 10px; margin-bottom: 5px; text-transform: uppercase;">
                            Unterschrift Unternehmer</div>
                        <div
                            style="border: 1px solid #cbd5e1; border-radius: 4px; background: #fff; position: relative;">
                            <canvas id="sigUnternehmer" width="400" height="150"
                                style="width: 100%; height: 150px; cursor: crosshair;"></canvas>
                            <button type="button" class="btnClearSig" data-target="sigUnternehmer"
                                style="position: absolute; top: 5px; right: 5px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 3px; font-size: 10px; cursor: pointer;">Löschen</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="pendenzen-modal-footer"
            style="background: #f8fafc; padding: 15px 40px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <span
                    style="font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase;">Sortierung:</span>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer;">
                    <input type="radio" name="pdf_sort_mode" id="sort_by_nr" value="nummer" checked>
                    📋 Nach Nummer sortieren
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer;">
                    <input type="radio" name="pdf_sort_mode" id="sort_by_raum" value="raum">
                    🏠 Sortieren nach Raum
                </label>
            </div>
            <div class="pendenzen-modal-footer"
                style="background: #f8fafc; padding: 20px 40px; border-top: 2px solid #e2e8f0; display: flex; justify-content: center; align-items: center; gap: 15px; flex-wrap: wrap;">

                <div style="display: flex; gap: 12px; align-items: center;">
                    <button type="button"
                        style="padding: 12px 25px; border-radius: 10px; border: 2px solid #e2e8f0; background: #fff; cursor: pointer; font-weight: 700; font-size: 14px; transition: 0.2s;"
                        onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'"
                        onclick="document.getElementById('protocolModal').style.display='none'">Abbrechen</button>

                    <button type="button" id="btnSaveProtocolOnly"
                        style="padding: 12px 25px; border-radius: 10px; border: none; background: #6366f1; color: #fff; cursor: pointer; font-weight: 800; font-size: 14px; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.3); transition: 0.2s;"
                        onmouseover="this.style.transform='translateY(-1px)'"
                        onmouseout="this.style.transform='translateY(0)'">
                        🚀 PROTOKOLL JETZT SPEICHERN</button>

                    <button type="button" id="btnExportWithProtocol"
                        style="padding: 14px 40px; border-radius: 10px; border: none; background: #10b981; color: #fff; cursor: pointer; font-weight: 900; font-size: 15px; display: flex; align-items: center; gap: 10px; box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3); transition: 0.2s;"
                        onmouseover="this.style.background='#059669'; this.style.transform='scale(1.02)'"
                        onmouseout="this.style.background='#10b981'; this.style.transform='scale(1)'">
                        📄 PDF GENERIEREN</button>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Manager Iframe Modal -->
<div id="managerModal" class="pendenzen-modal" style="display: none; padding: 20px; z-index: 9999;">
    <div class="pendenzen-modal-content"
        style="width: 95%; max-width: 1600px; height: 90vh; display: flex; flex-direction: column; padding: 0; overflow: hidden; border-radius: 16px; border: none; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
        <div class="pendenzen-modal-header"
            style="background: #0f172a; color: #fff; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 15px;">
                <span style="font-size: 20px;">📄</span>
                <div>
                    <h3
                        style="margin: 0; font-size: 16px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #fff;">
                        Protokoll Manager</h3>
                    <p style="margin: 0; font-size: 11px; opacity: 0.7;">Live-Integration der Master-Verwaltung</p>
                </div>
            </div>
            <button class="pendenzen-modal-close" id="btnCloseManager"
                style="color: #fff; opacity: 0.8; font-size: 30px; border: none; background: transparent; cursor: pointer;">&times;</button>
        </div>
        <div style="flex: 1; position: relative; background: #fff;">
            <iframe id="managerIframe" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
        </div>
    </div>
</div>





<script>
    const dashArtMap = <?php echo json_encode(array_column($arten, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const dashDefaultsByArt = <?php echo json_encode($defaultsByArt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const dashUsers = <?php echo json_encode($dashUsersList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    (function () {
        // --- 1. Core Elements ---
        const pendenzNeuFrame = document.getElementById('pendenzNeuFrame');
        const toggleForm = document.getElementById('toggleForm');
        const formContent = document.getElementById('formContent');
        const toggleIcon = toggleForm?.querySelector('.toggle-icon');
        const formStatusPill = document.getElementById('formStatusPill');
        const dashVorgang = document.getElementById('quick_vorgangsart_id');
        const dashProjekt = document.getElementById('quick_projekt_id');
        const dashObjekt = document.getElementById('quick_objekt_id');
        const dashWohnung = document.getElementById('quick_wohnung_id');
        const dashRaum = document.getElementById('quick_raum_id');
        const dashUnternehmer = document.getElementById('quick_unternehmer_id');
        const btnPrevWohnung = document.getElementById('btnPrevWohnung');
        const btnNextWohnung = document.getElementById('btnNextWohnung');
        const table = document.getElementById('pendenzenTable');

        // --- 2. Dashboard -> Iframe Sync ---
        function syncIframeContext() {
            if (!pendenzNeuFrame) return;
            const artId = dashVorgang?.value || '';
            const pid = dashProjekt?.value || '';
            const oid = dashObjekt?.value || '';
            const wid = dashWohnung?.value || '';
            const rid = dashRaum?.value || '';
            const uid = dashUnternehmer?.value || '';

            let newSrc = 'pendenz_neu.php?embed=1';
            if (artId) newSrc += '&vorgangsart_id=' + artId;
            if (pid) newSrc += '&projekt_id=' + pid;
            if (oid) newSrc += '&objekt_id=' + oid;
            if (wid) newSrc += '&wohnung_id=' + wid;
            if (rid) newSrc += '&raum_id=' + rid;
            if (uid) newSrc += '&unternehmer_id=' + uid;

            const cur = new URL(pendenzNeuFrame.src, window.location.origin);
            const tar = new URL(newSrc, window.location.origin);
            if (cur.search !== tar.search) pendenzNeuFrame.src = newSrc;
        }

        // --- 2b. Dashboard Cascading Filters ---
        function filterDashObjekte() {
            if (!dashObjekt) return;
            const pid = dashProjekt?.value;
            const opt = dashVorgang?.selectedOptions[0];
            const defPid = opt?.dataset.defaultProjekt;
            const filterPid = pid || (defPid && defPid !== "0" ? defPid : null);
            const currentVal = dashObjekt.value;

            if (!dashObjekt.masterOptions) {
                dashObjekt.masterOptions = Array.from(dashObjekt.options);
            }

            dashObjekt.innerHTML = '';
            dashObjekt.masterOptions.forEach(o => {
                const isMatch = !o.value || !filterPid || o.dataset.projektId === filterPid;
                if (isMatch) dashObjekt.appendChild(o.cloneNode(true));
            });
            dashObjekt.value = currentVal;
            filterDashWohnungen();
        }

        function filterDashWohnungen() {
            if (!dashWohnung) return;
            const oid = dashObjekt.value;
            const currentVal = dashWohnung.value;

            if (!dashWohnung.masterOptions) {
                dashWohnung.masterOptions = Array.from(dashWohnung.options);
            }

            dashWohnung.innerHTML = '';
            dashWohnung.masterOptions.forEach(o => {
                const isMatch = !o.value || !oid || o.dataset.objektId === oid;
                if (isMatch) dashWohnung.appendChild(o.cloneNode(true));
            });
            dashWohnung.value = currentVal;
            filterDashRaeume();
        }

        function filterDashRaeume() {
            if (!dashRaum) return;
            const wid = dashWohnung.value;
            const currentVal = dashRaum.value;

            if (!dashRaum.masterOptions) {
                dashRaum.masterOptions = Array.from(dashRaum.options);
            }

            dashRaum.innerHTML = '';
            dashRaum.masterOptions.forEach(opt => {
                const match = !opt.value || !wid || opt.dataset.wohnungId === wid;
                if (match) dashRaum.appendChild(opt.cloneNode(true));
            });
            dashRaum.value = currentVal;
        }

        function userMatchesArtDash(user, art) {
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

        function filterDashUnternehmer() {
            if (!dashUnternehmer) return;
            const artId = dashVorgang?.value || '';
            const art = dashArtMap[artId] || null;
            const current = dashUnternehmer.value;
            const defs = dashDefaultsByArt[artId] || [];
            const firstDef = defs.length ? defs[0] : null;

            const allowed = dashUsers.filter(u => userMatchesArtDash(u, art));

            dashUnternehmer.innerHTML = '<option value="">— Unternehmer wählen —</option>';
            allowed.forEach(u => {
                const o = document.createElement('option');
                o.value = u.id;
                o.textContent = u.label;
                if (String(u.id) === String(current)) o.selected = true;
                dashUnternehmer.appendChild(o);
            });

            if (firstDef && firstDef.benutzer_id && !dashUnternehmer.value) {
                dashUnternehmer.value = firstDef.benutzer_id;
            } else if (art && art.default_benutzer_id && !dashUnternehmer.value) {
                dashUnternehmer.value = art.default_benutzer_id;
            }
        }

        dashVorgang?.addEventListener('change', () => {
            const artId = dashVorgang.value;
            const art = dashArtMap[artId] || null;
            const defs = dashDefaultsByArt[artId] || [];
            const firstDef = defs.length ? defs[0] : null;

            const pid = firstDef?.projekt_id || art?.default_projekt_id;
            if (pid && pid !== '0') {
                if (dashProjekt) dashProjekt.value = pid;
            }
            const oid = firstDef?.objekt_id || art?.default_objekt_id;
            if (oid && oid !== '0') {
                dashObjekt.value = oid;
            }
            const wid = firstDef?.wohnung_id || art?.default_wohnung_id;
            if (wid && wid !== '0') {
                dashWohnung.value = wid;
            }
            const rid = firstDef?.raum_id || art?.default_raum_id;
            if (rid && rid !== '0') {
                dashRaum.value = rid;
            }

            filterDashObjekte();
            filterDashUnternehmer();
            syncIframeContext();
        });

        dashProjekt?.addEventListener('change', () => {
            filterDashObjekte();
            syncIframeContext();
        });
        dashObjekt?.addEventListener('change', () => {
            filterDashWohnungen();
            syncIframeContext();
        });
        dashWohnung?.addEventListener('change', () => {
            filterDashRaeume();
            syncIframeContext();
        });
        dashRaum?.addEventListener('change', syncIframeContext);
        dashUnternehmer?.addEventListener('change', syncIframeContext);

        // --- 3. Iframe Lifecycle ---
        if (pendenzNeuFrame) {
            const resize = () => {
                try {
                    const doc = pendenzNeuFrame.contentWindow?.document;
                    if (!doc) return;
                    const h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight, 800);
                    pendenzNeuFrame.style.height = (h + 30) + 'px';
                } catch (e) { }
            };
            pendenzNeuFrame.addEventListener('load', () => setTimeout(resize, 300));
            window.addEventListener('resize', resize);
            window.addEventListener('message', e => {
                if (e.data === 'pendenz_saved' || e.data?.type === 'pendenz_saved') {
                    sessionStorage.setItem('pendenz_save_scroll', window.scrollY);
                    const url = new URL(window.location.href);
                    url.searchParams.delete('edit_id');
                    window.location.href = url.toString();
                }
            });

        }

        // --- 4. Form Toggle ---
        if (toggleForm && formContent) {
            const isE = <?php echo ($isEditMode ?? false) ? 'true' : 'false'; ?>;
            if (isE) {
                formContent.style.display = 'block';
                if (toggleIcon) toggleIcon.style.transform = 'rotate(0deg)';
                if (formStatusPill) {
                    formStatusPill.textContent = 'Geöffnet';
                    formStatusPill.style.background = '#dcfce7';
                    formStatusPill.style.color = '#166534';
                }
                setTimeout(() => toggleForm.scrollIntoView({ behavior: 'smooth' }), 400);
            }
            toggleForm.addEventListener('click', () => {
                const isC = formContent.style.display === 'none';
                formContent.style.display = isC ? 'block' : 'none';
                if (toggleIcon) toggleIcon.style.transform = isC ? 'rotate(0deg)' : 'rotate(-90deg)';
                if (formStatusPill) {
                    formStatusPill.textContent = isC ? 'Geöffnet' : 'Eingeklappt';
                    formStatusPill.style.background = isC ? '#dcfce7' : '#f1f5f9';
                    formStatusPill.style.color = isC ? '#475569' : '#475569';
                }
            });
        }


        // --- 6. Table Management ---
        if (table) {
            const tb = table.querySelector('tbody');
            const allRows = Array.from(tb.querySelectorAll('tr')).filter(r => !r.querySelector('.pendenzen-table-empty'));

            // FIltering
            const fG = document.getElementById('filterGlobal'), fP = document.getElementById('filterProjekt'), fS = document.getElementById('filterStatus'), fZ = document.getElementById('filterZustaendig'), fW = document.getElementById('filterWichtigkeit');

            // --- Populate Filter Options from Table Data ---
            const populateSelect = (select, dataAttr) => {
                if (!select) return;
                const vals = new Set();
                allRows.forEach(r => { if (r.dataset[dataAttr]) vals.add(r.dataset[dataAttr]); });
                const sorted = Array.from(vals).sort();
                sorted.forEach(v => {
                    const o = document.createElement('option');
                    o.value = v;
                    o.textContent = v;
                    select.appendChild(o);
                });
            };
            populateSelect(fP, 'projekt');
            populateSelect(fS, 'status');
            populateSelect(fZ, 'zustaendig');

            function doFilter() {
                const q = fG.value.toLowerCase().trim();
                allRows.forEach(r => {
                    const match = (!q || r.innerText.toLowerCase().includes(q)) &&
                        (!fP.value || r.dataset.projekt === fP.value) &&
                        (!fS.value || r.dataset.status === fS.value) &&
                        (!fZ.value || r.dataset.zustaendig === fZ.value) &&
                        (!fW.value || r.dataset.wichtigkeit === fW.value);
                    r.style.display = match ? '' : 'none';
                });
            }
            [fG, fP, fS, fZ, fW].forEach(el => el?.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', doFilter));

            // Sorting
            const getSortVal = (row, idx) => {
                const cell = row.children[idx];
                if (!cell) return '';
                const inp = cell.querySelector('input, select');
                if (inp) {
                    if (inp.tagName === 'SELECT') return inp.options[inp.selectedIndex]?.text || '';
                    return inp.value || '';
                }
                return cell.innerText.trim();
            };

            table.querySelectorAll('thead th[data-sort]').forEach((th, idx) => {
                const sortType = th.dataset.sort;
                if (!sortType) return;
                th.style.cursor = 'pointer';
                th.addEventListener('click', () => {
                    const isAsc = th.classList.contains('sort-asc');
                    const rows = Array.from(tb.querySelectorAll('tr')).filter(r => !r.querySelector('.pendenzen-table-empty'));
                    rows.sort((a, b) => {
                        let vA = getSortVal(a, idx), vB = getSortVal(b, idx);
                        if (sortType === 'number') {
                            let nA = parseFloat(vA.replace(/[^\d.-]/g, '')) || 0;
                            let nB = parseFloat(vB.replace(/[^\d.-]/g, '')) || 0;
                            return isAsc ? nB - nA : nA - nB;
                        }
                        return isAsc ? vB.localeCompare(vA) : vA.localeCompare(vB);
                    });
                    table.querySelectorAll('th').forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
                    th.classList.add(isAsc ? 'sort-desc' : 'sort-asc');
                    rows.forEach(r => tb.appendChild(r));
                });
            });

            // Mobile Sorting Select
            const mSort = document.getElementById('mobileSort');
            mSort?.addEventListener('change', () => {
                const idx = parseInt(mSort.value);
                if (!isNaN(idx)) {
                    const ths = table.querySelectorAll('thead th');
                    if (ths[idx]) ths[idx].click();
                }
            });


            // Column Visibility
            const colToggles = document.getElementById('columnToggles')?.querySelectorAll('input');
            colToggles?.forEach(chk => {
                const key = chk.dataset.colKey;
                if (!key) return;
                const update = (v) => {
                    table.querySelectorAll(`[data-col-key="${key}"]`).forEach(el => {
                        el.style.display = v ? '' : 'none';
                    });
                    localStorage.setItem('p_col_key_' + key, v);
                };
                chk.addEventListener('change', () => update(chk.checked));
                const saved = localStorage.getItem('p_col_key_' + key);
                if (saved !== null) {
                    chk.checked = saved === 'true';
                    update(chk.checked);
                }
            });

            // Resizable columns
            table.querySelectorAll('thead th').forEach((th, i) => {
                const colKeyAttr = th.dataset.colKey || ('col_' + i);
                if (colKeyAttr === 'Aktion') return;

                const storageKey = 'p_col_w_' + colKeyAttr;
                const savedW = localStorage.getItem(storageKey);
                if (savedW) {
                    th.style.width = savedW;
                } else {
                    // Fallback default widths if nothing saved
                    if (colKeyAttr === 'id') th.style.width = '65px';
                    if (colKeyAttr === 'bell') th.style.width = '45px';
                    if (colKeyAttr === 'titel') th.style.width = '250px';
                    if (colKeyAttr === 'dauer') th.style.width = '50px';
                    if (colKeyAttr === 'startdatum') th.style.width = '120px';
                    if (colKeyAttr === 'enddatum') th.style.width = '120px';
                }

                const r = document.createElement('div');
                r.className = 'resizer';
                th.appendChild(r);

                let x = 0, w = 0;
                const md = (e) => {
                    x = e.clientX;
                    w = th.offsetWidth;
                    document.addEventListener('mousemove', mm);
                    document.addEventListener('mouseup', mu);
                    e.preventDefault();
                    e.stopPropagation();
                };
                const mm = (e) => {
                    requestAnimationFrame(() => {
                        const nw = w + (e.clientX - x);
                        th.style.width = Math.max(10, nw) + 'px';
                    });
                };
                const mu = () => {
                    document.removeEventListener('mousemove', mm);
                    document.removeEventListener('mouseup', mu);
                    th.classList.remove('resizing');
                    localStorage.setItem(storageKey, th.style.width);
                };
                r.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    th.classList.add('resizing');
                    md(e);
                });
                r.addEventListener('click', e => e.stopPropagation());
            });


        }

        // --- 7. UX Extras ---
        // Image Size Slider
        const setImgSize = (t, v) => {
            document.documentElement.style.setProperty('--thumb-width-' + t, v + 'px');
            localStorage.setItem('p_img_' + t, v);
            const valEl = document.getElementById('imgSizeVal' + t.charAt(0).toUpperCase() + t.slice(1));
            if (valEl) valEl.textContent = v + 'px';
        };
        ['desktop', 'mobile'].forEach(t => {
            const sl = document.getElementById('imgSizeSlider' + t.charAt(0).toUpperCase() + t.slice(1));
            if (sl) {
                const s = localStorage.getItem('p_img_' + t) || (t === 'desktop' ? '120' : '140');
                sl.value = s; setImgSize(t, s);
                sl.addEventListener('input', e => setImgSize(t, e.target.value));
            }
        });

        // Date Auto-Calc
        const parseD = v => (v ? new Date(v) : null), fmtD = d => d.toISOString().split('T')[0];
        const addD = (d, n) => { let r = new Date(d); r.setDate(r.getDate() + n); return r; };
        function bindDate(s, e, du) {
            if (!s || !e || !du) return;
            const recalc = (f) => {
                let st = parseD(s.value), en = parseD(e.value), d = parseInt(du.value) || 0;
                if (f === 's' && d) e.value = fmtD(addD(st, d));
                else if (f === 'e' && st && en) du.value = Math.max(1, Math.ceil((en - st) / 86400000)) + ' Tage';
                else if (f === 'du' && st) e.value = fmtD(addD(st, d));
            };
            s.addEventListener('change', () => recalc('s'));
            e.addEventListener('change', () => recalc('e'));
            du.addEventListener('change', () => recalc('du'));
        }
        document.querySelectorAll('tbody tr').forEach(r => {
            bindDate(r.querySelector('[name="inline_startdatum"]'), r.querySelector('[name="inline_enddatum"]'), r.querySelector('[name="inline_dauer"]'));
        });

        // Inline Select Hierarchy Filtering
        document.querySelectorAll('tbody tr').forEach(r => {
            const sProj = r.querySelector('.inline-projekt-select');
            const sObj = r.querySelector('.inline-objekt-select');
            const sWoh = r.querySelector('.inline-wohnung-select');
            const sRaum = r.querySelector('.inline-raum-select');

            const filterOptions = (parentSelect, childSelect, dataAttr) => {
                if (!parentSelect || !childSelect) return;

                if (!childSelect.masterOptions) {
                    childSelect.masterOptions = Array.from(childSelect.options);
                }

                const parentVal = parentSelect.value;
                const currentVal = childSelect.value;

                childSelect.innerHTML = '';
                childSelect.masterOptions.forEach(opt => {
                    const match = !opt.value || !parentVal || opt.dataset[dataAttr] === parentVal;
                    if (match) childSelect.appendChild(opt.cloneNode(true));
                });

                childSelect.value = currentVal;
            };

            if (sProj && sObj) {
                sProj.addEventListener('change', () => { filterOptions(sProj, sObj, 'projektId'); if (sObj) sObj.dispatchEvent(new Event('change')); });
            }
            if (sObj && sWoh) {
                sObj.addEventListener('change', () => { filterOptions(sObj, sWoh, 'objektId'); if (sWoh) sWoh.dispatchEvent(new Event('change')); });
            }
            if (sWoh && sRaum) {
                sWoh.addEventListener('change', () => filterOptions(sWoh, sRaum, 'wohnungId'));
            }

            // Initial filtering
            if (sProj && sObj) filterOptions(sProj, sObj, 'projektId');
            if (sObj && sWoh) filterOptions(sObj, sWoh, 'objektId');
            if (sWoh && sRaum) filterOptions(sWoh, sRaum, 'wohnungId');
        });

        // Dashboard Navigation
        btnNextWohnung?.addEventListener('click', () => {
            if (!dashWohnung) return;
            const opts = Array.from(dashWohnung.options).filter(o => !o.hidden && o.value !== '');
            if (!opts.length) return;
            let idx = opts.findIndex(o => o.value === dashWohnung.value);
            dashWohnung.value = opts[(idx + 1) % opts.length].value;
            dashWohnung.dispatchEvent(new Event('change'));
            syncIframeContext();
        });

        btnPrevWohnung?.addEventListener('click', () => {
            if (!dashWohnung) return;
            const opts = Array.from(dashWohnung.options).filter(o => !o.hidden && o.value !== '');
            if (!opts.length) return;
            let idx = opts.findIndex(o => o.value === dashWohnung.value);
            if (idx === -1) idx = 0; // Default to last if none selected
            dashWohnung.value = opts[(idx - 1 + opts.length) % opts.length].value;
            dashWohnung.dispatchEvent(new Event('change'));
            syncIframeContext();
        });

        // Lightbox Slider
        let lbImages = [];
        let lbIndex = 0;
        const lb = document.getElementById('pendenzenLightbox');
        const lbImg = lb?.querySelector('img');
        const lbCounter = lb?.querySelector('.lb-counter');

        const updateLb = () => {
            if (!lbImg || !lbImages[lbIndex]) return;
            lbImg.src = lbImages[lbIndex];
            if (lbCounter) lbCounter.textContent = (lbIndex + 1) + ' / ' + lbImages.length;
        };

        if (lb && lbImg) {
            // Re-bind to catch dynamically filtered images
            const bindLb = () => {
                document.querySelectorAll('.pendenzen-table-img').forEach((img, i) => {
                    img.style.cursor = 'zoom-in';
                    img.onclick = (e) => {
                        e.stopPropagation();
                        // Collect all currently visible ACTUAL images (skip placeholders)
                        const visibleImgs = Array.from(document.querySelectorAll('tr:not([style*="display: none"]) img.pendenzen-table-img'));
                        lbImages = visibleImgs.map(el => el.src);
                        lbIndex = lbImages.indexOf(img.src);

                        if (lbIndex === -1) lbIndex = 0;
                        updateLb();
                        lb.classList.add('active');
                    };
                });
            };
            bindLb();

            lb.querySelector('.lb-prev')?.addEventListener('click', (e) => {
                e.stopPropagation();
                lbIndex = (lbIndex - 1 + lbImages.length) % lbImages.length;
                updateLb();
            });
            lb.querySelector('.lb-next')?.addEventListener('click', (e) => {
                e.stopPropagation();
                lbIndex = (lbIndex + 1) % lbImages.length;
                updateLb();
            });
            lb.addEventListener('click', () => lb.classList.remove('active'));

            // Keyboard support
            document.addEventListener('keydown', (e) => {
                if (!lb.classList.contains('active')) return;
                if (e.key === 'ArrowLeft') lb.querySelector('.lb-prev')?.click();
                if (e.key === 'ArrowRight') lb.querySelector('.lb-next')?.click();
                if (e.key === 'Escape') lb.classList.remove('active');
            });

            // Touch Swipe Support
            let touchStartX = 0;
            lb.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].screenX; }, { passive: true });
            lb.addEventListener('touchend', e => {
                const diff = touchStartX - e.changedTouches[0].screenX;
                if (Math.abs(diff) > 50) {
                    if (diff > 0) lb.querySelector('.lb-next')?.click();
                    else lb.querySelector('.lb-prev')?.click();
                }
            }, { passive: true });
        }



        // Duplicate Task Logic (Popup Modal)
        const dupModal = document.getElementById('duplicationModal');
        const dupFrame = document.getElementById('duplicationFrame');
        const btnCloseDup = document.getElementById('btnCloseDuplication');

        document.querySelectorAll('.btn-duplicate-task').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = btn.dataset.id;
                if (!id || !dupModal || !dupFrame) return;

                // Show modal
                dupModal.style.display = 'flex';

                // Load the duplicate data into the iframe
                dupFrame.src = 'pendenz_neu.php?embed=1&duplicate_id=' + id;
            });
        });

        btnCloseDup?.addEventListener('click', () => {
            dupModal.style.display = 'none';
            dupFrame.src = 'about:blank';
        });

        // Close on backdrop click
        dupModal?.addEventListener('click', (e) => {
            if (e.target === dupModal) btnCloseDup?.click();
        });


        // Edit Professional Link (Dynamic Loading)
        document.querySelectorAll('.btn-edit-professional').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const id = btn.dataset.id;
                if (!id || !pendenzNeuFrame) return;
                if (!e.ctrlKey && !e.shiftKey && !e.metaKey) {
                    e.preventDefault();
                    // Load the edit form into the iframe
                    pendenzNeuFrame.src = 'pendenz_neu.php?embed=1&edit_id=' + id;

                    // Update the toggle button text to indicate editing
                    const titleSpan = toggleForm?.querySelector('span[style*="font-weight:800"]');
                    if (titleSpan) titleSpan.textContent = 'Pendenz bearbeiten (ID: ' + id + ')';

                    // Open the form if it's closed
                    if (formContent && formContent.style.display === 'none') {
                        toggleForm?.click();
                    }

                    // Scroll to the form
                    setTimeout(() => {
                        const marker = document.getElementById('formTopMarker');
                        if (marker) {
                            marker.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        } else {
                            toggleForm?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }, 100);
                }
            });
        });


        // Restore scroll position after save
        const savedScroll = sessionStorage.getItem('pendenz_save_scroll');
        if (savedScroll) {
            window.scrollTo(0, parseInt(savedScroll));
            sessionStorage.removeItem('pendenz_save_scroll');
        }

        // Initial setup (e.g. on page reload with edit_id)
        const isE = <?php echo ($isEditMode ?? false) ? 'true' : 'false'; ?>;
        if (isE && toggleForm) {
            if (formContent && formContent.style.display === 'none') toggleForm.click();
            // Nur scrollen wenn NICHT gerade gespeichert wurde
            if (!savedScroll) {
                setTimeout(() => toggleForm.scrollIntoView({ behavior: 'smooth' }), 400);
            }
        }

        // --- 8. Table Settings Toggle ---
        const toggleTableSettings = document.getElementById('toggleTableSettings');
        const tableSettingsPanel = document.getElementById('tableSettingsPanel');
        if (toggleTableSettings && tableSettingsPanel) {
            toggleTableSettings.addEventListener('change', () => {
                tableSettingsPanel.style.display = toggleTableSettings.checked ? 'block' : 'none';
            });
        }
        // --- 9. Profile Selection ---
        const profileSelect = document.getElementById('profileSelect');
        if (profileSelect) {
            profileSelect.addEventListener('change', () => {
                const pid = profileSelect.value;
                const url = new URL(window.location.href);
                if (pid) url.searchParams.set('profile_id', pid);
                else url.searchParams.delete('profile_id');
                window.location.href = url.toString();
            });
        }

        // Dashboard Actions Toggle
        const toggleDashActions = document.getElementById('toggleDashActions');
        const dashActionsList = document.getElementById('dashActionsList');
        if (toggleDashActions && dashActionsList) {
            toggleDashActions.addEventListener('change', () => {
                dashActionsList.classList.toggle('active', toggleDashActions.checked);
            });
        }
        // --- Protocol Modal Logic ---
        const protocolModal = document.getElementById('protocolModal');
        const btnOpenProtocol = document.getElementById('btnOpenProtocolModal');
        const btnCloseProtocol = document.getElementById('btnCloseProtocol');
        const btnAddParticipant = document.getElementById('btnAddParticipant');
        const userPicker = document.getElementById('userPicker');
        const participantTable = document.getElementById('participantTable')?.querySelector('tbody');
        const btnExportWithProtocol = document.getElementById('btnExportWithProtocol');

        // --- Signature Pad Logic ---
        const initSignature = (canvasId) => {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let drawing = false;

            const getPos = (e) => {
                const rect = canvas.getBoundingClientRect();
                return {
                    x: (e.clientX - rect.left) * (canvas.width / rect.width),
                    y: (e.clientY - rect.top) * (canvas.height / rect.height)
                };
            };

            const startDrawing = (e) => {
                drawing = true;
                ctx.beginPath();
                const pos = getPos(e);
                ctx.moveTo(pos.x, pos.y);
                canvas.setPointerCapture(e.pointerId);
            };

            const draw = (e) => {
                if (!drawing) return;
                const pos = getPos(e);
                ctx.lineTo(pos.x, pos.y);
                ctx.lineWidth = 2;
                ctx.lineCap = 'round';
                ctx.strokeStyle = '#000';
                ctx.stroke();
            };

            const stopDrawing = (e) => {
                drawing = false;
                canvas.releasePointerCapture(e.pointerId);
            };

            canvas.addEventListener('pointerdown', startDrawing);
            canvas.addEventListener('pointermove', draw);
            canvas.addEventListener('pointerup', stopDrawing);
            canvas.addEventListener('pointercancel', stopDrawing);

            canvas.style.touchAction = 'none';
        };

        initSignature('sigBesteller');
        initSignature('sigUnternehmer');

        document.querySelectorAll('.btnClearSig').forEach(btn => {
            btn.addEventListener('click', () => {
                const canvas = document.getElementById(btn.dataset.target);
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            });
        });

        // --- Template & Form Management (Unified) ---
        const templateLoader = document.getElementById('templateLoader');
        const protocolLoader = document.getElementById('protocolLoader');
        const categoryPicker = document.getElementById('categoryPicker');

        let currentTemplateId = null;
        let currentFormId = null;
        let customSections = [];

        const loadSavedItems = () => {
            fetch(`../api/manage_protocol_templates.php?action=list`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;

                    // 1. Kategorien (rechts)
                    if (categoryPicker) {
                        const currentCat = categoryPicker.value;
                        categoryPicker.innerHTML = `<option value="">-- Ziel-Kategorie --</option>`;
                        const types = {};
                        data.templates.forEach(t => {
                            if (!types[t.typ_id]) types[t.typ_id] = { name: t.typ_name, icon: t.typ_icon };
                        });
                        Object.keys(types).forEach(tid => {
                            const opt = document.createElement('option');
                            opt.value = tid;
                            opt.textContent = (types[tid].icon || '📄') + ' ' + types[tid].name;
                            categoryPicker.appendChild(opt);
                        });
                        categoryPicker.value = currentCat;
                    }

                    // 2. Muster laden (links 1)
                    if (templateLoader) {
                        templateLoader.innerHTML = `<option value="">-- Muster laden --</option>`;
                        const groups = {};
                        const typeOrder = ['Abnahmeprotokoll', 'Baustellenprotokoll', 'Sitzungsprotokoll', 'Pendenzen & Erinnerungen'];
                        data.templates.forEach(t => {
                            const groupName = (t.typ_icon || '📄') + ' ' + t.typ_name;
                            if (!groups[groupName]) groups[groupName] = { name: t.typ_name, items: [] };
                            groups[groupName].items.push(t);
                        });
                        Object.keys(groups).sort((a, b) => {
                            let idxA = typeOrder.indexOf(groups[a].name);
                            let idxB = typeOrder.indexOf(groups[b].name);
                            return (idxA === -1 ? 999 : idxA) - (idxB === -1 ? 999 : idxB);
                        }).forEach(gKey => {
                            const group = document.createElement('optgroup');
                            group.label = gKey;
                            groups[gKey].items.forEach(t => {
                                const opt = document.createElement('option');
                                opt.value = t.id;
                                opt.textContent = '📐 ' + t.name;
                                opt.dataset.json = t.json_data;
                                opt.dataset.typId = t.typ_id;
                                group.appendChild(opt);
                            });
                            templateLoader.appendChild(group);
                        });
                    }

                    // 3. Formulare laden
                    if (protocolLoader) {
                        const currentVal = protocolLoader.value;
                        protocolLoader.innerHTML = `<option value="">-- Dokument laden --</option>`;
                        data.forms.forEach(f => {
                            const opt = document.createElement('option');
                            opt.value = f.id;
                            opt.textContent = '📄 ' + f.name;
                            opt.dataset.json = f.json_data;
                            opt.dataset.vorlageId = f.vorlage_id;
                            protocolLoader.appendChild(opt);
                        });
                        protocolLoader.value = currentVal;
                    }
                    filterProtocolLoader();
                });
        };

        const filterProtocolLoader = () => {
            if (!protocolLoader) return;
            const options = protocolLoader.querySelectorAll('option');
            options.forEach(opt => {
                if (!opt.value) return; // "-- Dokument laden --" immer zeigen
                if (!currentTemplateId) {
                    opt.style.display = ''; // Alles zeigen wenn kein Muster gewählt
                } else {
                    const match = opt.dataset.vorlageId == currentTemplateId;
                    opt.style.display = match ? '' : 'none';
                }
            });
            // Wenn aktuell gewähltes Item nun versteckt ist, Auswahl zurücksetzen
            if (protocolLoader.value && protocolLoader.options[protocolLoader.selectedIndex].style.display === 'none') {
                protocolLoader.value = '';
            }
        };

        const collectProtocolData = () => {
            try {
                const pr = document.getElementById('p_project');
                const ob = document.getElementById('p_object');
                const wo = document.getElementById('p_apartment');

                const data = {
                    title: document.getElementById('p_title')?.value || '',
                    pdf_title: document.getElementById('p_pdf_title')?.value || '',
                    subject: document.getElementById('p_subject')?.value || '',
                    list_title: document.getElementById('p_list_title')?.value || '',
                    project_id: pr?.value || '',
                    project: (pr && pr.selectedIndex >= 0) ? pr.options[pr.selectedIndex].text : '',
                    object_id: ob?.value || '',
                    object: (ob && ob.selectedIndex >= 0) ? ob.options[ob.selectedIndex].text : '',
                    apartment_id: wo?.value || '',
                    apartment: (wo && wo.selectedIndex >= 0) ? wo.options[wo.selectedIndex].text : '',
                    datetime: document.getElementById('p_datetime')?.value || '',
                    location: document.getElementById('p_location')?.value || '',
                    leader: document.getElementById('p_leader')?.value || '',
                    obj_nr: document.getElementById('p_obj_nr')?.value || '',
                    wv_date: document.getElementById('p_wv_date')?.value || '',
                    wv_nr: document.getElementById('p_wv_nr')?.value || '',
                    bkp: document.getElementById('p_bkp')?.value || '',
                    land: document.getElementById('p_land')?.value || '',
                    fix_date: document.getElementById('p_fix_date')?.value || '',
                    participants: [],
                    intro: document.getElementById('p_intro')?.value || '',
                    outro: document.getElementById('p_outro')?.value || '',
                    art159: document.getElementById('p_art159')?.checked || false,
                    art160: document.getElementById('p_art160')?.checked || false,
                    art161: document.getElementById('p_art161')?.checked || false,
                    sig1: document.getElementById('sigBesteller')?.toDataURL() || null,
                    sig2: document.getElementById('sigUnternehmer')?.toDataURL() || null,
                    customSections: customSections
                };

                document.querySelectorAll('.participant-row').forEach(tr => {
                    data.participants.push({
                        status: tr.querySelector('.p_status')?.value || 'ja',
                        name: tr.querySelector('.p_name')?.value || '',
                        position: tr.querySelector('.p_position')?.value || '',
                        email: tr.querySelector('.p_email')?.value || '',
                        tel: tr.querySelector('.p_tel')?.value || '',
                        company: tr.querySelector('.p_company')?.value || ''
                    });
                });
                return data;
            } catch (e) {
                console.error("Data Collection Error:", e);
                return null;
            }
        };

        const applyProtocolConfig = (d) => {
            const ind = document.getElementById('modalModeIndicator');
            const guide = document.getElementById('modalWorkflowGuide');
            const gIcon = document.getElementById('modalWorkflowIcon');
            const gText = document.getElementById('modalWorkflowText');
            const statusLabel = document.getElementById('labelDocName');
            const masterLabel = document.getElementById('labelMasterName');
            const typeLabel = document.getElementById('labelTypeName');

            if (currentFormId) {
                ind.textContent = 'FERTIGES DOKUMENT';
                ind.style.background = '#6366f1';
                guide.style.background = 'rgba(99, 102, 241, 0.1)';
                guide.style.color = '#6366f1';
                gIcon.textContent = '📄';
                gText.textContent = 'DOKUMENT-MODUS: Sie bearbeiten einen gespeicherten Bericht.';
                statusLabel.textContent = d.name || 'Dokument';
            } else if (currentTemplateId) {
                ind.textContent = 'MUSTER-VORLAGE';
                ind.style.background = '#1abc9c';
                guide.style.background = 'rgba(26, 188, 156, 0.1)';
                guide.style.color = '#1abc9c';
                gIcon.textContent = '📐';
                gText.textContent = 'MUSTER-MODUS: Sie verwenden ein vorbereitetes Gerüst.';
                statusLabel.textContent = 'Neues Dokument...';
            }

            // Master & Kategorie Info
            if (currentTemplateId) {
                const opt = Array.from(templateLoader.querySelectorAll('option')).find(o => o.value == currentTemplateId);
                if (opt) {
                    masterLabel.textContent = opt.textContent.replace('📐 ', '');
                    // Kategorie-Name finden
                    const catOpt = Array.from(categoryPicker.querySelectorAll('option')).find(o => o.value == opt.dataset.typId);
                    if (catOpt) typeLabel.textContent = catOpt.textContent;
                }
            }

            if (d.sig2 !== undefined) console.log("Signatur geladen");

            // Dynamische Abschnitte
            customSections = d.customSections || [];
            renderCustomSections();

            // Automatische Sektions-Steuerung
            const lowerTitle = (d.title || d.pdf_title || '').toLowerCase();
            const isAbnahme = lowerTitle.includes('abnahme');
            const isBaustelle = lowerTitle.includes('baustelle') || lowerTitle.includes('journal');

            document.getElementById('sectionWerkdetails').style.display = (isAbnahme || isBaustelle) ? 'block' : 'none';
            document.getElementById('sectionCheckboxes').style.display = isAbnahme ? 'block' : 'none';

            document.getElementById('p_title').value = d.title || '';
            document.getElementById('p_pdf_title').value = d.pdf_title || '';
            document.getElementById('p_subject').value = d.subject || '';
            document.getElementById('p_list_title').value = d.list_title || '';
            document.getElementById('p_obj_nr').value = d.obj_nr || '';
            document.getElementById('p_wv_date').value = d.wv_date || '';
            document.getElementById('p_wv_nr').value = d.wv_nr || '';
            document.getElementById('p_bkp').value = d.bkp || '';
            document.getElementById('p_land').value = d.land || '';
            document.getElementById('p_fix_date').value = d.fix_date || '';
            document.getElementById('p_location').value = d.location || '';
            document.getElementById('p_intro').value = d.intro || '';
            document.getElementById('p_outro').value = d.outro || '';
            document.getElementById('p_art159').checked = !!d.art159;
            document.getElementById('p_art160').checked = !!d.art160;
            document.getElementById('p_art161').checked = !!d.art161;
            document.getElementById('p_leader').value = d.leader || '<?php echo h($_SESSION['user_name'] ?? ''); ?>';

            if (d.project_id) {
                document.getElementById('p_project').value = d.project_id;
                filterProtocolObjekte();
                if (d.object_id) {
                    document.getElementById('p_object').value = d.object_id;
                    filterProtocolWohnungen();
                    if (d.apartment_id) {
                        document.getElementById('p_apartment').value = d.apartment_id;
                    }
                }
            }

            const pTableBody = document.getElementById('participantTable')?.querySelector('tbody');
            if (pTableBody && d.participants) {
                pTableBody.innerHTML = '';
                d.participants.forEach(p => {
                    const tr = document.createElement('tr');
                    tr.className = 'participant-row';
                    tr.innerHTML = `
                        <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;">
                            <select class="p_status" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px;">
                                <option value="ja" ${p.status === 'ja' ? 'selected' : ''}>Anwesend</option>
                                <option value="nein" ${p.status === 'nein' ? 'selected' : ''}>Entschuldigt</option>
                                <option value="abwesend" ${p.status === 'abwesend' ? 'selected' : ''}>Abwesend</option>
                            </select>
                        </td>
                        <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_name" value="${p.name || ''}" style="width: 100%; border: none; font-size: 11px;"></td>
                        <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_position" value="${p.position || ''}" style="width: 100%; border: none; font-size: 11px;"></td>
                        <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_email" value="${p.email || ''}" style="width: 100%; border: none; font-size: 11px;"><input type="text" class="p_tel" value="${p.tel || ''}" style="width: 100%; border: none; font-size: 10px; color: #64748b;"></td>
                        <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_company" value="${p.company || ''}" style="width: 100%; border: none; font-size: 11px;"></td>
                        <td style="padding: 8px; border-bottom: 1px solid #f1f5f9; text-align:center;"><button type="button" class="btnRemoveParticipant" style="border:none; background:none; cursor:pointer; color:#ef4444; font-size:18px;">&times;</button></td>
                    `;
                    pTableBody.appendChild(tr);
                    tr.querySelector('.btnRemoveParticipant').addEventListener('click', () => tr.remove());
                });
            }

            // Signatures wiederherstellen
            ['sigBesteller', 'sigUnternehmer'].forEach((id, i) => {
                const canvas = document.getElementById(id);
                if (canvas && d['sig' + (i + 1)]) {
                    const ctx = canvas.getContext('2d');
                    const img = new Image();
                    img.onload = () => {
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        ctx.drawImage(img, 0, 0);
                    };
                    img.src = d['sig' + (i + 1)];
                }
            });
        };

        templateLoader?.addEventListener('change', () => {
            const opt = templateLoader.selectedOptions[0];
            if (!opt || !opt.value) return;
            currentTemplateId = opt.value;
            currentFormId = null;
            categoryPicker.value = opt.dataset.typId;

            const docData = JSON.parse(opt.dataset.json);
            applyProtocolConfig(docData);

            if (protocolLoader) protocolLoader.value = '';
            filterProtocolLoader();
        });

        protocolLoader?.addEventListener('change', () => {
            const opt = protocolLoader.selectedOptions[0];
            if (!opt || !opt.value) return;
            currentFormId = opt.value;
            currentTemplateId = opt.dataset.vorlageId;
            document.getElementById('modalFormTitle').textContent = opt.textContent;
            // Kategorie finden
            fetch('../api/manage_protocol_templates.php?action=load&id=' + currentTemplateId)
                .then(r => r.json()).then(res => { if (res.success) categoryPicker.value = res.template.typ_id; });
            applyProtocolConfig(JSON.parse(opt.dataset.json));
        });

        document.getElementById('btnSaveAsNew')?.addEventListener('click', () => {
            console.log("Speichern-Prozess gestartet...");
            const typId = categoryPicker.value;
            if (!typId) {
                alert("🔴 FEHLER: Bitte wählen Sie rechts zuerst die Ziel-Kategorie (z.B. Baustellenprotokoll) aus.");
                return;
            }

            let targetTemplateId = currentTemplateId;
            if (!targetTemplateId) {
                const options = Array.from(templateLoader.querySelectorAll('option'));
                const matchedOpt = options.find(o => o.dataset.typId == typId);
                if (matchedOpt) targetTemplateId = matchedOpt.value;
            }

            if (!targetTemplateId) {
                alert("🔴 FEHLER: Kein Muster-Basis gefunden. Bitte wählen Sie links ein Muster aus.");
                return;
            }

            const data = collectProtocolData();
            if (!data) {
                alert("🔴 FEHLER: Die Formulardaten konnten nicht verarbeitet werden.");
                return;
            }

            if (!data.project_id) {
                alert("🔴 FEHLER: Bitte wählen Sie im Formular ein PROJEKT aus (unter dem Titel). Ohne Projekt kann nicht gespeichert werden.");
                return;
            }

            const docTitle = document.getElementById('p_title')?.value || 'Dokument';
            const name = docTitle + ' (' + new Date().toLocaleDateString() + ')';
            console.log("Sende Daten für:", name, data);

            fetch('../api/manage_protocol_templates.php?action=save_form', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    vorlage_id: targetTemplateId,
                    name: name,
                    json_data: JSON.stringify(data)
                })
            })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        alert("✅ ERFOLGREICH: Das Protokoll '" + name + "' wurde gespeichert.");
                        currentFormId = res.id;
                        loadSavedItems();
                        // Kurz warten bis die Liste geladen ist, dann selektieren
                        setTimeout(() => {
                            if (protocolLoader) protocolLoader.value = res.id;
                        }, 500);
                    } else {
                        alert("⚠️ DATENBANK-FEHLER: " + (res.error || 'Unbekannter Fehler'));
                    }
                })
                .catch(err => {
                    alert("❌ NETZWERK-FEHLER: " + err.message);
                    console.error(err);
                });
        });


        loadSavedItems();


        // Save Only Button (Footer)
        document.getElementById('btnSaveProtocolOnly')?.addEventListener('click', () => {
            document.getElementById('btnSaveAsNew')?.click();
        });

        const filterProtocolObjekte = () => {
            const pId = document.getElementById('p_project').value;
            const objSelect = document.getElementById('p_object');
            if (!objSelect) return;
            const options = objSelect.querySelectorAll('option');
            let firstVisible = '';

            options.forEach(opt => {
                if (!opt.value) return;
                const match = !pId || opt.dataset.projektId === pId;
                opt.style.display = match ? '' : 'none';
                if (match && !firstVisible) firstVisible = opt.value;
            });
            if (objSelect.value && objSelect.options[objSelect.selectedIndex].style.display === 'none') {
                objSelect.value = '';
            }
            filterProtocolWohnungen();
        };

        const filterProtocolWohnungen = () => {
            const oId = document.getElementById('p_object').value;
            const wohnSelect = document.getElementById('p_apartment');
            if (!wohnSelect) return;
            const options = wohnSelect.querySelectorAll('option');

            options.forEach(opt => {
                if (!opt.value) return;
                const match = !oId || opt.dataset.objektId === oId;
                opt.style.display = match ? '' : 'none';
            });
            if (wohnSelect.value && wohnSelect.options[wohnSelect.selectedIndex].style.display === 'none') {
                wohnSelect.value = '';
            }
        };

        // --- Manager Modal Logic ---
        const managerModal = document.getElementById('managerModal');
        const btnOpenManager = document.getElementById('btnOpenManagerModal');
        const btnCloseManager = document.getElementById('btnCloseManager');
        const managerIframe = document.getElementById('managerIframe');

        btnOpenManager?.addEventListener('click', () => {
            managerModal.style.display = 'flex';
            managerIframe.src = 'protokoll_manager.php';
        });

        btnCloseManager?.addEventListener('click', () => {
            managerModal.style.display = 'none';
            managerIframe.src = 'about:blank';
        });

        window.addEventListener('click', (e) => {
            if (e.target === managerModal) btnCloseManager.click();
        });

        const openProtocolModal = () => {
            protocolModal.style.display = 'flex';
            loadSavedItems();
            currentFormId = null;
            currentTemplateId = null;

            // Reset Header to clean state
            document.getElementById('modalModeIndicator').textContent = 'STARTSEITE';
            document.getElementById('modalModeIndicator').style.background = '#94a3b8';
            document.getElementById('modalFormSubtitle').textContent = 'Bereiten Sie hier Ihre Protokoll-Maske vor.';
            document.getElementById('labelDocName').textContent = 'Unbenannt';
            document.getElementById('labelMasterName').textContent = 'Keine Vorlage gewählt';
            document.getElementById('labelTypeName').textContent = '-';
            document.getElementById('modalWorkflowText').textContent = 'ERSTELLUNGS-MODUS: Legen Sie eine neue Haupt-Struktur fest oder laden Sie ein Muster.';
            document.getElementById('modalWorkflowIcon').textContent = '✨';

            templateLoader.value = '';
            protocolLoader.value = '';

            // Clear Form radikal
            applyProtocolConfig({});

            // Sync values from Quick Capture
            const qP = document.getElementById('quick_projekt_id')?.value || '';
            const qO = document.getElementById('quick_objekt_id')?.value || '';
            const qW = document.getElementById('quick_wohnung_id')?.value || '';

            if (qP) document.getElementById('p_project').value = qP;
            filterProtocolObjekte();
            if (qO) document.getElementById('p_object').value = qO;
            filterProtocolWohnungen();
            if (qW) document.getElementById('p_apartment').value = qW;
        };

        document.getElementById('p_project')?.addEventListener('change', filterProtocolObjekte);
        document.getElementById('p_object')?.addEventListener('change', filterProtocolWohnungen);

        btnOpenProtocol?.addEventListener('click', (e) => { e.preventDefault(); openProtocolModal(); });
        btnCloseProtocol?.addEventListener('click', () => { protocolModal.style.display = 'none'; });


        btnAddParticipant?.addEventListener('click', () => {
            const opt = userPicker.selectedOptions[0];
            if (!opt || !opt.value) return;

            const tr = document.createElement('tr');
            tr.className = 'participant-row';
            tr.dataset.id = opt.value;
            tr.innerHTML = `
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;">
                    <select class="p_status" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px;">
                        <option value="ja">Anwesend</option>
                        <option value="nein">Entschuldigt</option>
                        <option value="abwesend">Abwesend</option>
                    </select>
                </td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_name" value="${opt.dataset.name}" style="width:100%; border:none; background:transparent; font-weight:bold;"></td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_position" value="${opt.dataset.position}" style="width:100%; border:none; background:transparent;"></td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_email" value="${opt.dataset.email}" style="width:100%; border:none; background:transparent; font-size:11px; color:#1e40af;"></td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_tel" value="${opt.dataset.tel}" style="width:100%; border:none; background:transparent; font-size:11px;"></td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;"><input type="text" class="p_company" value="${opt.dataset.company}" style="width:100%; border:none; background:transparent;"></td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9; text-align:center;"><button type="button" class="btnRemoveParticipant" style="border:none; background:none; cursor:pointer; color:#ef4444; font-size:18px;">&times;</button></td>
            `;
            participantTable.appendChild(tr);
            tr.querySelector('.btnRemoveParticipant').addEventListener('click', () => tr.remove());
        });

        btnExportWithProtocol?.addEventListener('click', () => {
            try {
                const fG = document.getElementById('filterGlobal')?.value || '';
                const fP = document.getElementById('filterProjekt')?.value || '';
                const fS = document.getElementById('filterStatus')?.value || '';
                const fZ = document.getElementById('filterZustaendig')?.value || '';
                const fW = document.getElementById('filterWichtigkeit')?.value || '';

                // Protocol Data
                const pData = {
                    title: document.getElementById('p_title')?.value || '',
                    pdf_title: document.getElementById('p_pdf_title')?.value || '',
                    list_title: document.getElementById('p_list_title')?.value || '',
                    subject: document.getElementById('p_subject')?.value || '',
                    obj_nr: document.getElementById('p_obj_nr')?.value || '',
                    vertreten_durch: document.getElementById('p_vertreten_durch')?.value || '',
                    wv_date: document.getElementById('p_wv_date')?.value || '',
                    wv_nr: document.getElementById('p_wv_nr')?.value || '',
                    bkp: document.getElementById('p_bkp')?.value || '',
                    land: document.getElementById('p_land')?.value || '',
                    fix_date: document.getElementById('p_fix_date')?.value || '',
                    datetime: document.getElementById('p_datetime')?.value || '',
                    location: document.getElementById('p_location')?.value || '',
                    leader: document.getElementById('p_leader')?.value || '',
                    project: document.getElementById('p_project')?.options[document.getElementById('p_project')?.selectedIndex]?.text || '',
                    object: document.getElementById('p_object')?.options[document.getElementById('p_object')?.selectedIndex]?.text || '',
                    apartment: document.getElementById('p_apartment')?.options[document.getElementById('p_apartment')?.selectedIndex]?.text || '',
                    intro: document.getElementById('p_intro')?.value || '',
                    outro: document.getElementById('p_outro')?.value || '',
                    art159: document.getElementById('p_art159')?.checked || false,
                    art160: document.getElementById('p_art160')?.checked || false,
                    art161: document.getElementById('p_art161')?.checked || false,
                    sigBesteller: (() => { const c = document.getElementById('sigBesteller'); return c ? c.toDataURL() : ''; })(),
                    sigUnternehmer: (() => { const c = document.getElementById('sigUnternehmer'); return c ? c.toDataURL() : ''; })(),
                    participants: []
                };

                document.querySelectorAll('.participant-row').forEach(tr => {
                    pData.participants.push({
                        status: tr.querySelector('.p_status')?.value || '',
                        name: tr.querySelector('.p_name')?.value || '',
                        position: tr.querySelector('.p_position')?.value || '',
                        email: tr.querySelector('.p_email')?.value || '',
                        tel: tr.querySelector('.p_tel')?.value || '',
                        company: tr.querySelector('.p_company')?.value || ''
                    });
                });

                const sortMode = document.querySelector('input[name="pdf_sort_mode"]:checked')?.value || 'nummer';

                const url = new URL('pendenzen_list_pdf.php', window.location.origin + window.location.pathname.replace('pendenzen.php', ''));
                if (fG) url.searchParams.set('q', fG);
                if (fP) url.searchParams.set('projekt_name', fP);
                if (fS) url.searchParams.set('status', fS);
                if (fZ) url.searchParams.set('zustaendig_name', fZ);
                if (fW) url.searchParams.set('wichtigkeit', fW);
                url.searchParams.set('sort_mode', sortMode);


                // Use hidden form to POST large data and open in new tab
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = url.toString();
                form.target = '_blank';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'protocol_data';
                input.value = JSON.stringify(pData);
                form.appendChild(input);

                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            } catch (err) {
                alert('Fehler beim PDF-Export: ' + err.message);
                console.error(err);
            }
        });


        // Final init
        filterDashObjekte();
        filterDashUnternehmer();
    })();

    // Global reset function for the entrepreneur notification bell
    function resetUntBell(el, id) {
        // Element sofort ausblenden (optisches Feedback)
        el.style.opacity = '0.3';
        el.style.pointerEvents = 'none';

        fetch('pendenzen.php?action=reset_unt_bell&id=' + id)
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    el.style.transform = 'scale(0)';
                    setTimeout(() => el.remove(), 300);
                } else {
                    el.style.opacity = '1';
                    el.style.pointerEvents = 'auto';
                    alert('Fehler: ' + (res.error || 'Unbekannt'));
                }
            })
            .catch(err => {
                el.style.opacity = '1';
                el.style.pointerEvents = 'auto';
                console.error(err);
            });
    }
</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>