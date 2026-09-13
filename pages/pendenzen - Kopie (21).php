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
    $inlineVorgangsartId = postIntOrNull('inline_vorgangsart_id');
    $inlineKurzbeschreibung = postStr('inline_kurzbeschreibung');
    $inlineStatus = postStr('inline_status', 'offen');
    $inlineWichtigkeitRaw = postStr('inline_wichtigkeit');
    $inlineZustaendigId = postIntOrNull('inline_zustaendig_id');
    $inlineStartdatum = postStr('inline_startdatum');
    $inlineEnddatum = postStr('inline_enddatum');
    $inlineUhrzeit = postStr('inline_uhrzeit');
    $inlineDauer = postStr('inline_dauer');

    if ($inlineId === null) {
        $error = 'Inline-Bearbeitung fehlgeschlagen: ungültige ID.';
    } elseif ($inlineTitel === '') {
        $error = 'Bitte Titel eingeben.';
    } elseif (!in_array($inlineStatus, ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'], true)) {
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
                vorgangsart_id = ?,
                titel = ?,
                kurzbeschreibung = ?,
                status = ?,
                wichtigkeit = ?,
                zustaendig_id = ?,
                startdatum = ?,
                enddatum = ?,
                uhrzeit = ?,
                dauer = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmtInline) {
            $error = 'Inline-Update Prepare fehlgeschlagen: ' . $mysqli->error;
        } else {
            $stmtInline->bind_param(
                'iiiisssisssssi',
                $inlineProjektId,
                $inlineObjektId,
                $inlineWohnungId,
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

// --- Additional Data for Quick Capture (Strict Sync with pendenz_neu.php logic) ---
$arten = fetchAllAssoc($mysqli, "
    SELECT
        id, name, slug, icon, color, farbe,
        default_projekt_id, default_objekt_id, default_wohnung_id, default_benutzer_id,
        allow_override_projekt, allow_override_objekt, allow_override_wohnung, allow_override_benutzer,
        vorlagen_welt, empfaenger_typ, empfaenger_person_type_id, empfaenger_person_status_id,
        bkp_erforderlich, nur_firmen_bkp, firma_bkp_filter
    FROM pendenzen_arten
    WHERE is_active = 1
    ORDER BY sort_order ASC, name ASC
");
$userSql = "
    SELECT b.id, b.name, b.email, b.rolle, b.firma_name
    FROM benutzer b
    WHERE b.deleted_at IS NULL ORDER BY b.name ASC";
$users = [];
foreach (fetchAllAssoc($mysqli, $userSql) as $row) {
    $label = trim((string) $row['name']);
    if ((string) ($row['firma_name'] ?? '') !== '') {
        $label .= ' · ' . $row['firma_name'];
    }
    if ((string) ($row['rolle'] ?? '') !== '') {
        $label .= ' (' . $row['rolle'] . ')';
    }
    $users[] = ['id' => $row['id'], 'name' => $row['name'], 'label' => $label];
}
$artMap = [];
foreach ($arten as $row) {
    $artMap[(int) $row['id']] = $row;
}

$bkpCodes = fetchAllAssoc($mysqli, "SELECT id, code, bezeichnung, parent_id FROM bkp_codes ORDER BY code ASC, bezeichnung ASC");
$bkpKategorien = $mysqli->query("SHOW TABLES LIKE 'bkp_kategorien'")->num_rows > 0
    ? fetchAllAssoc($mysqli, "SELECT id, bkp_id, name FROM bkp_kategorien ORDER BY bkp_id ASC, name ASC, id ASC") : [];
$bkpKategorienByBkp = [];
$bkpKategorieMap = [];
foreach ($bkpKategorien as $row) {
    if ((int) $row['bkp_id'] > 0) {
        $bkpKategorienByBkp[(int) $row['bkp_id']][] = $row;
    }
    if ((int) $row['id'] > 0) {
        $bkpKategorieMap[(int) $row['id']] = $row;
    }
}

$hasBkpTextTable = $mysqli->query("SHOW TABLES LIKE 'bkp_vorlagen_texte'")->num_rows > 0;
$bkpTextsByKategorie = [];
if ($hasBkpTextTable) {
    foreach (fetchAllAssoc($mysqli, "SELECT id, kategorie_id, text FROM bkp_vorlagen_texte ORDER BY id ASC") as $row) {
        $bkpTextsByKategorie[(int) $row['kategorie_id']][] = $row;
    }
}

$mieterKategorien = fetchAllAssoc($mysqli, "SELECT id, name, projekt_id, objekt_id FROM pendenz_kategorien_mieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");
$mieterSubkategorien = fetchAllAssoc($mysqli, "SELECT id, kategorie_id, name, beschreibung, projekt_id, objekt_id FROM pendenz_subkategorien_mieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");
$vermieterKategorien = fetchAllAssoc($mysqli, "SELECT id, name, projekt_id, objekt_id FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");
$vermieterSubkategorien = fetchAllAssoc($mysqli, "SELECT id, kategorie_id, name, beschreibung, projekt_id, objekt_id FROM pendenz_subkategorien_vermieter WHERE aktiv = 1 ORDER BY sortierung ASC, sort_order ASC, name ASC");

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

$mieterSubByCat = [];
foreach ($mieterSubkategorien as $row) {
    $mieterSubByCat[(int) $row['kategorie_id']][] = $row;
}
$vermieterSubByCat = [];
foreach ($vermieterSubkategorien as $row) {
    $vermieterSubByCat[(int) $row['kategorie_id']][] = $row;
}

$arten = fetchAllAssoc($mysqli, "
    SELECT id, name, slug, icon, vorlagen_welt, default_projekt_id, default_objekt_id, default_wohnung_id, default_benutzer_id 
    FROM pendenzen_arten 
    WHERE is_active = 1 
    ORDER BY sort_order ASC, name ASC
");

$artMap = [];
foreach ($arten as $a) {
    $artMap[(int) $a['id']] = $a;
}

$profiles = [];
$res = $mysqli->query("
    SELECT
        l.id,
        l.name,
        COALESCE(l.is_default, 0) AS is_default,
        GROUP_CONCAT(ls.col_name ORDER BY ls.sort_order ASC) as cols
    FROM listen l
    LEFT JOIN listen_spalten ls ON l.id = ls.listen_id
    WHERE l.table_name = 'pendenzen'
    GROUP BY l.id, l.name, l.is_default
    ORDER BY l.is_default DESC, l.name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $profiles[] = $row;
    }
    $res->close();
}

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

$recent = [];
$attachmentQueryParts = [
    'first_image' => "NULL AS first_image",
    'count_images' => "0 AS count_images",
    'count_files' => "0 AS count_files",
];
if (pendenzTableExists($mysqli, 'pendenz_dateien')) {
    $attachmentQueryParts = [
        'first_image' => "(SELECT pfad FROM pendenz_dateien WHERE pendenz_id = p.id AND typ = 'image' ORDER BY COALESCE(is_cover,0) DESC, COALESCE(sort_index,999999) ASC, id ASC LIMIT 1) AS first_image",
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
        p.vorgangsart_id,
        pr.name AS projekt_name,
        o.name AS objekt_name,
        p.zustaendig_id,
        b.name AS zustaendig_name,
        w.name AS wohnung_name,
        pa.name AS vorgangsart_name,
        {$attachmentQueryParts['first_image']},
        {$attachmentQueryParts['count_images']},
        {$attachmentQueryParts['count_files']}
    FROM pendenzen p
    LEFT JOIN projekte pr ON pr.id = p.projekt_id
    LEFT JOIN objekte o ON o.id = p.objekt_id
    LEFT JOIN benutzer b ON b.id = p.zustaendig_id
    LEFT JOIN wohnungen w ON w.id = p.wohnung_id
    LEFT JOIN pendenzen_arten pa ON pa.id = p.vorgangsart_id
    WHERE p.deleted_at IS NULL
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
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .pendenzen-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 10px;
        border-radius: 10px;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #0f172a;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
    }

    .pendenzen-action-btn:hover {
        border-color: #0f766e;
        color: #0f766e;
        background: #f0fdfa;
    }

    .pendenzen-inline-input,
    .pendenzen-inline-select {
        width: 100%;
        min-width: 70px;
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
        min-width: 128px;
    }

    .pendenzen-inline-input[type="time"] {
        min-width: 92px;
    }

    .pendenzen-inline-input.input-title {
        min-width: 180px;
        font-weight: 700;
    }

    .pendenzen-inline-input.input-short {
        min-width: 150px;
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

    /* Floating Action Button & Drawer Modernized */
    .pendenzen-fab {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 64px;
        height: 64px;
        background: var(--primary-gradient);
        color: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        cursor: pointer;
        box-shadow: 0 8px 32px rgba(15, 118, 110, 0.4);
        z-index: 2000;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .pendenzen-fab:hover {
        transform: scale(1.1) rotate(0deg);
        /* Neutral rotation on hover */
        box-shadow: 0 12px 40px rgba(15, 118, 110, 0.5);
    }

    .pendenzen-drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.5);
        backdrop-filter: blur(4px);
        z-index: 1900;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .pendenzen-drawer-overlay.active {
        display: block;
        opacity: 1;
    }

    /* Quick Modal (Screenshot Style) */
    .pendenzen-quick-modal {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.9);
        width: 90%;
        max-width: 580px;
        background: #fff;
        z-index: 2500;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        padding: 0;
        overflow: hidden;
    }

    .pendenzen-quick-modal.open {
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .modal-header {
        padding: 16px 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #f1f5f9;
        background: #fff;
    }

    .modal-body {
        padding: 24px;
    }

    .quick-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-top: 12px;
    }

    .quick-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 10px;
    }

    .modal-body label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #334155;
        margin-bottom: 6px;
    }

    .tag-pill {
        padding: 6px 14px;
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .tag-pill:hover {
        background: #cbd5e1;
        color: #0f172a;
    }

    .btn-quick-create {
        width: 100%;
        height: 52px;
        background: #14b8a6;
        color: #fff;
        border: 0;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.3s;
    }

    .btn-quick-create:hover {
        background: #0d9488;
        transform: translateY(-1px);
        box-shadow: 0 4px 15px rgba(20, 184, 166, 0.4);
    }

    .btn-quick-create .btn-icon-plus {
        color: #a855f7;
        /* Purple sign as in screenshot */
        font-size: 22px;
        font-weight: 400;
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
                    <div class="pendenzen-dashboard-sub">Neue Reihenfolge: Vorgang, Objekt, Wohnung, Unternehmer</div>
                </div>

                <div class="pendenzen-dashboard-actions">
                    <a class="pendenzen-chip-link" id="btnQuickAdd" href="javascript:void(0)"
                        onclick="openQuickModal()">⚡ Schnell-Erfassung</a>
                    <a class="pendenzen-chip-link" id="btnAddNew" href="javascript:void(0)">➕ Neue Pendenz</a>
                    <a class="pendenzen-chip-link" href="listen_settings.php">⚙️ Tabellen-Architekt</a>
                    <a class="pendenzen-chip-link" href="pendenz_kategorien.php">🗂️ Vorlagen-Übersicht</a>
                    <a class="pendenzen-chip-link" href="pendenz_kategorien_mieter.php">🏠 Mieterkategorien</a>
                    <a class="pendenzen-chip-link" href="pendenz_kategorien_vermieter.php">🏢 Vermieterkategorien</a>
                    <a class="pendenzen-chip-link" href="bkp_codes.php">🧱 BKP</a>
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
                    <button type="button" id="btnNextWohnung" class="pendenzen-quick-btn">Nächste Wohnung →</button>
                </div>
            </div>
        </div>

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
                style="padding:0 18px 18px 18px;border-top:1px solid var(--border-color);display:none;">
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
                                        data-cols="<?php echo h((string) ($prof['cols'] ?? '')); ?>" <?php echo ($activeProfile && (int) $activeProfile['id'] === (int) $prof['id']) ? 'selected' : ''; ?>><?php echo h($prof['name']); ?></option>
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
                            <label><input type="checkbox" data-col="4" checked> Objekt</label>
                            <label><input type="checkbox" data-col="5" checked> Woh.</label>
                            <label><input type="checkbox" data-col="6" checked> Status</label>
                            <label><input type="checkbox" data-col="7" checked> Prio</label>
                            <label><input type="checkbox" data-col="8" checked> Zust.</label>
                            <label><input type="checkbox" data-col="9" checked> Start</label>
                            <label><input type="checkbox" data-col="10" checked> Ende</label>
                            <label><input type="checkbox" data-col="11" checked> Zeit</label>
                            <label><input type="checkbox" data-col="12" checked> Dauer</label>
                            <label><input type="checkbox" data-col="13"> Tagest.</label>
                            <label><input type="checkbox" data-col="14"> Vorg.</label>
                            <label><input type="checkbox" data-col="15" checked> Art</label>
                            <label><input type="checkbox" data-col="16"> 📸</label>
                            <label><input type="checkbox" data-col="17"> 📂</label>
                            <label><input type="checkbox" data-col="18"> 📕</label>
                            <label><input type="checkbox" data-col="19"> Kurz.</label>
                            <label><input type="checkbox" data-col="20"> Sichtb.</label>
                            <label><input type="checkbox" data-col="21"> Erst.am</label>
                            <label><input type="checkbox" data-col="22"> Geänd.am</label>
                            <label><input type="checkbox" data-col="23"> Gelö.</label>
                            <label><input type="checkbox" data-col="24"> Erst.v.</label>
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
                                    <th data-sort="text" data-label="Objekt">Objekt</th>
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
                                            <?php $inlineFormId = 'inline-edit-' . (int) $row['id']; ?>
                                            <td data-label="Titel">
                                                <input class="pendenzen-inline-input input-title" type="text"
                                                    name="inline_titel" form="<?php echo h($inlineFormId); ?>"
                                                    value="<?php echo h((string) $row['titel']); ?>">
                                            </td>
                                            <td data-label="Proj.">
                                                <select class="pendenzen-inline-select inline-projekt-select"
                                                    name="inline_projekt_id" data-row-id="<?php echo (int) $row['id']; ?>"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <option value="">-</option>
                                                    <?php foreach ($projekte as $projekt): ?>
                                                        <option value="<?php echo (int) $projekt['id']; ?>" <?php echo ((int) ($row['projekt_id'] ?? 0) === (int) $projekt['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h((string) $projekt['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td data-label="Objekt">
                                                <select class="pendenzen-inline-select inline-objekt-select"
                                                    name="inline_objekt_id" data-row-id="<?php echo (int) $row['id']; ?>"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <option value="">-</option>
                                                    <?php foreach ($objekte as $objekt): ?>
                                                        <option value="<?php echo (int) $objekt['id']; ?>"
                                                            data-projekt-id="<?php echo (int) ($objekt['projekt_id'] ?? 0); ?>"
                                                            <?php echo ((int) ($row['objekt_id'] ?? 0) === (int) $objekt['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h((string) $objekt['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td data-label="Woh.">
                                                <select class="pendenzen-inline-select inline-wohnung-select"
                                                    name="inline_wohnung_id" data-row-id="<?php echo (int) $row['id']; ?>"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <option value="">-</option>
                                                    <?php foreach ($wohnungen as $wohnung): ?>
                                                        <option value="<?php echo (int) $wohnung['id']; ?>"
                                                            data-objekt-id="<?php echo (int) ($wohnung['objekt_id'] ?? 0); ?>" <?php echo ((int) ($row['wohnung_id'] ?? 0) === (int) $wohnung['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h((string) $wohnung['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td data-label="Status">
                                                <select class="pendenzen-inline-select" name="inline_status"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <?php foreach (['offen', 'in Bearbeitung', 'erledigt', 'archiviert'] as $inlineStatusOption): ?>
                                                        <option value="<?php echo h($inlineStatusOption); ?>" <?php echo ((string) $row['status'] === $inlineStatusOption) ? 'selected' : ''; ?>>
                                                            <?php echo h($inlineStatusOption); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td data-label="Prio">
                                                <select class="pendenzen-inline-select" name="inline_wichtigkeit"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <option value="">-</option>
                                                    <?php for ($prio = 1; $prio <= 5; $prio++): ?>
                                                        <option value="<?php echo $prio; ?>" <?php echo ((string) $row['wichtigkeit'] === (string) $prio) ? 'selected' : ''; ?>>
                                                            P<?php echo $prio; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </td>
                                            <td data-label="Zust.">
                                                <select class="pendenzen-inline-select" name="inline_zustaendig_id"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <option value="">-</option>
                                                    <?php foreach ($benutzer as $ben): ?>
                                                        <option value="<?php echo (int) $ben['id']; ?>" <?php echo ((int) ($row['zustaendig_id'] ?? 0) === (int) $ben['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h((string) $ben['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td data-label="Start">
                                                <input class="pendenzen-inline-input" type="date" name="inline_startdatum"
                                                    form="<?php echo h($inlineFormId); ?>"
                                                    value="<?php echo h((string) $row['startdatum']); ?>">
                                            </td>
                                            <td data-label="Ende">
                                                <input class="pendenzen-inline-input" type="date" name="inline_enddatum"
                                                    form="<?php echo h($inlineFormId); ?>"
                                                    value="<?php echo h((string) ($row['enddatum'] ?? '')); ?>">
                                            </td>
                                            <td data-label="Zeit">
                                                <input class="pendenzen-inline-input" type="time" name="inline_uhrzeit"
                                                    form="<?php echo h($inlineFormId); ?>"
                                                    value="<?php echo h((string) ($row['uhrzeit'] ?? '')); ?>">
                                            </td>
                                            <td data-label="Dauer">
                                                <input class="pendenzen-inline-input" type="text" name="inline_dauer"
                                                    form="<?php echo h($inlineFormId); ?>"
                                                    value="<?php echo h((string) ($row['dauer'] ?? '')); ?>">
                                            </td>
                                            <td data-label="Tagest."><?php echo h($row['tageszeit']); ?></td>
                                            <td data-label="Vorg."><?php echo h($row['vorgaenger_id'] ?: '-'); ?></td>
                                            <td data-label="Art">
                                                <select class="pendenzen-inline-select" name="inline_vorgangsart_id"
                                                    form="<?php echo h($inlineFormId); ?>">
                                                    <option value="">-</option>
                                                    <?php foreach ($arten as $art): ?>
                                                        <option value="<?php echo (int) $art['id']; ?>" <?php echo ((int) ($row['vorgangsart_id'] ?? 0) === (int) $art['id']) ? 'selected' : ''; ?>>
                                                            <?php echo h((string) $art['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td data-label="📸"><?php echo (int) $row['count_images'] ?: '-'; ?></td>
                                            <td data-label="📂"><?php echo (int) $row['count_files'] ?: '-'; ?></td>
                                            <td data-label="📕">
                                                <?php echo (str_contains(strtolower($row['titel']), 'pdf') || $row['count_files'] > 0) ? '📕' : '-'; ?>
                                            </td>
                                            <td data-label="Kurz.">
                                                <input class="pendenzen-inline-input input-short" type="text"
                                                    name="inline_kurzbeschreibung" form="<?php echo h($inlineFormId); ?>"
                                                    value="<?php echo h((string) ($row['kurzbeschreibung'] ?? '')); ?>">
                                            </td>
                                            <td data-label="Sichtb."><?php echo h($row['sichtbarkeit']); ?></td>
                                            <td data-label="Erst.am"><?php echo h($row['erstellt_am']); ?></td>
                                            <td data-label="Geänd.am"><?php echo h($row['geaendert_am']); ?></td>
                                            <td data-label="Gelö."><?php echo h($row['deleted_at']); ?></td>
                                            <td data-label="Erst.v."><?php echo h($row['erstellt_von']); ?></td>
                                            <td data-label="Aktion">
                                                <form id="<?php echo h($inlineFormId); ?>" method="post">
                                                    <input type="hidden" name="form_action" value="inline_update">
                                                    <input type="hidden" name="inline_pendenz_id"
                                                        value="<?php echo (int) $row['id']; ?>">
                                                </form>
                                                <div class="pendenzen-actions">
                                                    <button type="submit" class="pendenzen-action-btn save-btn"
                                                        form="<?php echo h($inlineFormId); ?>">💾</button>
                                                    <a class="pendenzen-action-btn"
                                                        href="pendenzen.php?edit_id=<?php echo (int) $row['id']; ?>">Bearbeiten</a>
                                                    <a class="pendenzen-action-btn"
                                                        href="pendenz_show.php?id=<?php echo (int) $row['id']; ?>"
                                                        title="Details anzeigen">📋</a>
                                                    <a class="pendenzen-action-btn"
                                                        href="pendenz_pdf.php?id=<?php echo (int) $row['id']; ?>"
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

<div id="pendenzenLightbox" class="pendenzen-lightbox">
    <img src="" alt="Vorschau">
</div>
<div class="pendenzen-fab" id="fabAdd" onclick="openQuickModal()">➕</div>
<div class="pendenzen-drawer-overlay" id="drawerOverlay"></div>

<div class="pendenzen-quick-modal" id="quickDrawer">
    <div class="modal-header">
        <div style="display:flex; align-items:center; gap:10px">
            <span style="color:#f97316; font-size:20px">⚡</span>
            <span style="font-weight:700; font-size:16px; color:#1e293b">Schnell-Eingabe</span>
        </div>
        <div class="drawer-close" id="drawerClose" style="color:#94a3b8">×</div>
    </div>

    <div class="modal-body">
        <form id="quickCaptureForm" enctype="multipart/form-data">
            <div class="pendenzen-field">
                <label style="font-size:11px; margin-bottom:4px">Was ist zu tun?</label>
                <input type="text" id="quickTitel" name="titel" placeholder="Titel eingeben..." style="height:44px">

                <div class="quick-tags" id="quickTags">
                    <button type="button" class="tag-pill">Abnahme</button>
                    <button type="button" class="tag-pill">Abbruch</button>
                    <button type="button" class="tag-pill">Mangel</button>
                    <button type="button" class="tag-pill">Reinigung</button>
                    <button type="button" class="tag-pill">Dringend</button>
                    <button type="button" class="tag-pill">Termin</button>
                </div>
            </div>

            <div class="quick-grid-2">
                <div class="pendenzen-field">
                    <label>Vorgangsart (Art)</label>
                    <select id="quickVorgang" name="vorgangsart_id" required>
                        <option value="">— Art —</option>
                        <?php foreach ($arten as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>"
                                data-welt="<?php echo h($row['vorlagen_welt'] ?: 'bkp'); ?>">
                                <?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pendenzen-field">
                    <label>Empfänger (Zuständig)</label>
                    <select id="quickAssign" name="zustaendig_id" required>
                        <option value="">— Empfänger —</option>
                        <?php foreach ($users ?? [] as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>"><?php echo h($row['label'] ?? $row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="quick-grid-2">
                <div class="pendenzen-field">
                    <label>Wohnung / Lage</label>
                    <select id="quickWohnung" name="wohnung_id">
                        <option value="">— keine Wohnung —</option>
                        <?php foreach ($wohnungen ?? [] as $row): ?>
                            <option value="<?php echo (int) $row['id']; ?>"
                                data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>"><?php echo h($row['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pendenzen-field">
                    <label>Datum / Prio</label>
                    <div style="display:flex; gap:5px">
                        <input type="date" id="quickDate" name="startdatum" value="<?php echo date('Y-m-d'); ?>"
                            style="flex:1">
                        <select id="quickPrio" name="wichtigkeit" style="width:70px">
                            <option value="3">P3</option>
                            <option value="5">P5</option>
                            <option value="1">P1</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Fields for internal logic persistence -->
            <input type="hidden" name="projekt_id" id="quickProj" value="<?php echo (int) ($filter_projekt ?: 0); ?>">
            <input type="hidden" name="objekt_id" id="quickObjekt" value="<?php echo (int) ($activeObjektId ?: 0); ?>">
            <input type="hidden" name="status" value="offen">

            <!-- Vorlagen (Optional, hidden by default) -->
            <div id="quickTemplateArea"
                style="display:none; margin-top:15px; padding-top:15px; border-top:1px dashed #eee">
                <div id="qBlockBkp" style="display:none">
                    <div class="pendenzen-field">
                        <label>BKP / Gewerk</label>
                        <select id="qBkpId" name="bkp_id">
                            <option value="">— keine BKP —</option>
                            <?php foreach ($bkpCodes as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>">
                                    <?php echo h($row['code'] . ' ' . $row['bezeichnung']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pendenzen-field" style="margin-top:10px">
                        <label>Inhalt / Kategorie</label>
                        <select id="qBkpKatId" name="bkp_kategorie_id">
                            <option value="">— wählen —</option>
                        </select>
                    </div>
                    <div class="pendenzen-field" style="margin-top:10px">
                        <label>Vorlage / Text</label>
                        <select id="qBkpTextId" name="bkp_text_id">
                            <option value="">— wählen —</option>
                        </select>
                    </div>
                </div>
                <div id="qBlockMieter" style="display:none">
                    <div class="quick-grid-2">
                        <div class="pendenzen-field">
                            <label>Mieter-Kategorie</label>
                            <select id="qMieterId" name="mieter_kategorie_id">
                                <option value="">— wählen —</option>
                                <?php foreach ($mieterKategorien as $row): ?>
                                    <option value="<?php echo (int) $row['id']; ?>"><?php echo h($row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pendenzen-field">
                            <label>Subkategorie</label>
                            <select id="qMieterSubId" name="mieter_subkategorie_id">
                                <option value="">— wählen —</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="qBlockVermieter" style="display:none">
                    <div class="quick-grid-2">
                        <div class="pendenzen-field">
                            <label>Vermieter-Kategorie</label>
                            <select id="qVermieterId" name="vermieter_kategorie_id">
                                <option value="">— wählen —</option>
                                <?php foreach ($vermieterKategorien as $row): ?>
                                    <option value="<?php echo (int) $row['id']; ?>"><?php echo h($row['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pendenzen-field">
                            <label>Subkategorie</label>
                            <select id="qVermieterSubId" name="vermieter_subkategorie_id">
                                <option value="">— wählen —</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top:15px">
                <label style="font-size:11px; margin-bottom:4px; display:block">Bilder / Dokumente hinzufügen</label>
                <div style="display:flex; gap:10px">
                    <label class="pendenzen-chip-link"
                        style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; cursor:pointer; flex:1; justify-content:center">
                        📸 Bilder wählen <input type="file" name="bilder[]" multiple accept="image/*"
                            style="display:none">
                    </label>
                    <label class="pendenzen-chip-link"
                        style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; cursor:pointer; flex:1; justify-content:center">
                        📄 Dateien wählen <input type="file" name="dokumente[]" multiple style="display:none">
                    </label>
                </div>
            </div>

            <div style="margin-top:20px">
                <button type="button" class="btn-quick-create" id="btnQuickSave">
                    <span class="btn-icon-plus">+</span> Pendenz jetzt erstellen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    window.qBkpKategorien = <?php echo json_encode($bkpKategorienByBkp); ?>;
    window.qBkpTexts = <?php echo json_encode($bkpTextsByKategorie); ?>;
    window.qMieterSubs = <?php echo json_encode($mieterSubByCat); ?>;
    window.qVermieterSubs = <?php echo json_encode($vermieterSubByCat); ?>;
</script>
<script>
    (function () {
        // --- 1. Dashboard Context Bar Logic (Legacy Vibe) ---
        const quickVorgangsart = document.getElementById('quick_vorgangsart_id');
        const quickObjekt = document.getElementById('quick_objekt_id');
        const quickWohnung = document.getElementById('quick_wohnung_id');
        const quickUnternehmer = document.getElementById('quick_unternehmer_id');
        const btnNextWohnung = document.getElementById('btnNextWohnung');

        const mainVorgang = document.getElementById('vorgangsart_id');
        const mainObjekt = document.getElementById('objekt_id');
        const mainWohnung = document.getElementById('wohnung_id');
        const mainAssign = document.getElementById('zustaendig_id');
        const mainProj = document.getElementById('projekt_id');

        // Modal fields
        const modalVorgang = document.getElementById('quickVorgang');
        const modalWohnung = document.getElementById('quickWohnung');
        const modalAssign = document.getElementById('quickAssign');
        const modalProj = document.getElementById('quickProj');
        const modalObjekt = document.getElementById('quickObjekt');

        function filterSelect(selectEl, attr, val) {
            if (!selectEl) return;
            const currentVal = selectEl.value;
            let stillValid = currentVal === '';
            Array.from(selectEl.options).forEach((opt, idx) => {
                if (idx === 0) return;
                const match = !val || opt.getAttribute(attr) === String(val);
                opt.hidden = !match;
                if (match && opt.value === currentVal) stillValid = true;
            });
            if (!stillValid) selectEl.value = '';
        }

        function syncContext() {
            const pid = mainProj?.value || '';
            filterSelect(quickObjekt, 'data-projekt-id', pid);
            filterSelect(mainObjekt, 'data-projekt-id', pid);

            const oid = quickObjekt?.value || mainObjekt?.value || '';
            filterSelect(quickWohnung, 'data-objekt-id', oid);
            filterSelect(mainWohnung, 'data-objekt-id', oid);
        }

        function applyDefaults(vId) {
            const opt = Array.from(quickVorgangsart?.options || []).find(o => o.value === String(vId));
            if (!opt) return;
            const dProj = opt.dataset.defaultProjekt;
            const dObj = opt.dataset.defaultObjekt;
            const dWhg = opt.dataset.defaultWohnung;
            const dUser = opt.dataset.defaultBenutzer;

            if (dProj && dProj !== '0' && mainProj) mainProj.value = dProj;
            syncContext();
            if (dObj && dObj !== '0') {
                if (quickObjekt) quickObjekt.value = dObj;
                if (mainObjekt) mainObjekt.value = dObj;
            }
            syncContext();
            if (dWhg && dWhg !== '0') {
                if (quickWohnung) quickWohnung.value = dWhg;
                if (mainWohnung) mainWohnung.value = dWhg;
            }
            if (dUser && dUser !== '0') {
                if (quickUnternehmer) quickUnternehmer.value = dUser;
                if (mainAssign) mainAssign.value = dUser;
            }
        }

        quickVorgangsart?.addEventListener('change', (e) => {
            if (mainVorgang) mainVorgang.value = e.target.value;
            applyDefaults(e.target.value);
        });
        quickObjekt?.addEventListener('change', (e) => {
            if (mainObjekt) mainObjekt.value = e.target.value;
            syncContext();
        });
        quickWohnung?.addEventListener('change', (e) => {
            if (mainWohnung) mainWohnung.value = e.target.value;
        });
        quickUnternehmer?.addEventListener('change', (e) => {
            if (mainAssign) mainAssign.value = e.target.value;
        });

        btnNextWohnung?.addEventListener('click', () => {
            if (!quickWohnung) return;
            const opts = Array.from(quickWohnung.options).filter((o, i) => i > 0 && !o.hidden);
            if (!opts.length) return;
            let idx = opts.findIndex(o => o.value === quickWohnung.value);
            idx = (idx + 1) % opts.length;
            quickWohnung.value = opts[idx].value;
            if (mainWohnung) mainWohnung.value = quickWohnung.value;
        });

        // --- 2. Global Modal Functions ---
        window.openQuickModal = function () {
            const modal = document.getElementById('pendenzen-quick-modal');
            const overlay = document.getElementById('pendenzen-quick-overlay');
            if (!modal) return;

            // Pre-fill from Dashboard
            if (modalVorgang && quickVorgangsart) modalVorgang.value = quickVorgangsart.value;
            if (modalWohnung && quickWohnung) modalWohnung.value = quickWohnung.value;
            if (modalAssign && quickUnternehmer) modalAssign.value = quickUnternehmer.value;
            if (modalProj && mainProj) modalProj.value = mainProj.value;
            if (modalObjekt && quickObjekt) modalObjekt.value = quickObjekt.value;

            modal.style.display = 'block';
            overlay.style.display = 'block';
            document.getElementById('quickTitel')?.focus();

            // Trigger template visibility
            modalVorgang?.dispatchEvent(new Event('change'));
        }

        window.closeQuickModal = function () {
            document.getElementById('pendenzen-quick-modal').style.display = 'none';
            document.getElementById('pendenzen-quick-overlay').style.display = 'none';
        }

        // --- 3. Modal Form Logic ---
        const qForm = document.getElementById('quickCaptureForm');
        const qTitle = document.getElementById('quickTitel');
        const qBkp = document.getElementById('qBkpId');
        const qBkpKat = document.getElementById('qBkpKatId');
        const qArea = document.getElementById('quickTemplateArea');
        const qBkpBlock = document.getElementById('qBlockBkp');
        const qMieterBlock = document.getElementById('qBlockMieter');
        const qVermieterBlock = document.getElementById('qBlockVermieter');
        const btnSave = document.getElementById('btnQuickSave');

        modalVorgang?.addEventListener('change', (e) => {
            const welt = e.target.options[e.target.selectedIndex]?.dataset.welt || 'bkp';
            qArea.style.display = 'block';
            qBkpBlock.style.display = welt === 'bkp' ? 'block' : 'none';
            qMieterBlock.style.display = welt === 'mieter' ? 'block' : 'none';
            qVermieterBlock.style.display = welt === 'vermieter' ? 'block' : 'none';
        });

        qBkp?.addEventListener('change', (e) => {
            const bId = e.target.value;
            qBkpKat.innerHTML = '<option value="">— wählen —</option>';
            qBkpText.innerHTML = '<option value="">— wählen —</option>';
            if (window.qBkpKategorien && window.qBkpKategorien[bId]) {
                window.qBkpKategorien[bId].forEach(k => {
                    const o = document.createElement('option');
                    o.value = k.id;
                    o.textContent = k.name;
                    qBkpKat.appendChild(o);
                });
            }
        });

        const qBkpText = document.getElementById('qBkpTextId');
        qBkpKat?.addEventListener('change', (e) => {
            const kId = e.target.value;
            qBkpText.innerHTML = '<option value="">— wählen —</option>';
            if (window.qBkpTexts && window.qBkpTexts[kId]) {
                window.qBkpTexts[kId].forEach(t => {
                    const o = document.createElement('option');
                    o.value = t.id;
                    o.textContent = (t.text || '').substring(0, 50) + (t.text && t.text.length > 50 ? '...' : '');
                    qBkpText.appendChild(o);
                });
            }
        });

        const qMieter = document.getElementById('qMieterId');
        const qMieterSub = document.getElementById('qMieterSubId');
        qMieter?.addEventListener('change', (e) => {
            const cId = e.target.value;
            qMieterSub.innerHTML = '<option value="">— wählen —</option>';
            if (window.qMieterSubs && window.qMieterSubs[cId]) {
                window.qMieterSubs[cId].forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = s.name;
                    qMieterSub.appendChild(o);
                });
            }
        });

        const qVermieter = document.getElementById('qVermieterId');
        const qVermieterSub = document.getElementById('qVermieterSubId');
        qVermieter?.addEventListener('change', (e) => {
            const cId = e.target.value;
            qVermieterSub.innerHTML = '<option value="">— wählen —</option>';
            if (window.qVermieterSubs && window.qVermieterSubs[cId]) {
                window.qVermieterSubs[cId].forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.id;
                    o.textContent = s.name;
                    qVermieterSub.appendChild(o);
                });
            }
        });

        document.querySelectorAll('#quickTags .tag-pill').forEach(tag => {
            tag.addEventListener('click', () => {
                qTitle.value += (qTitle.value ? ' ' : '') + tag.textContent;
                qTitle.focus();
            });
        });

        btnSave?.addEventListener('click', () => {
            if (!qTitle.value.trim()) { alert('Bitte Titel eingeben'); return; }
            const fd = new FormData(qForm);
            fd.append('action', 'save_pendenz');

            btnSave.disabled = true;
            btnSave.innerHTML = 'Speichert...';

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.ok ? window.location.reload() : alert('Fehler beim Speichern'))
                .catch(() => alert('Netzwerkfehler'));
        });

        syncContext();

        // --- 4. Main Iframe & Toggle Logic ---
        const pendenzNeuFrame = document.getElementById('pendenzNeuFrame');
        if (pendenzNeuFrame) {
            const resizePendenzFrame = () => {
                try {
                    const doc = pendenzNeuFrame.contentWindow?.document;
                    if (!doc) return;
                    const body = doc.body;
                    const html = doc.documentElement;
                    const height = Math.max(
                        body ? body.scrollHeight : 0,
                        html ? html.scrollHeight : 0,
                        body ? body.offsetHeight : 0,
                        html ? html.offsetHeight : 0
                    );
                    if (height > 0) pendenzNeuFrame.style.height = (height + 24) + 'px';
                } catch (e) { }
            };
            pendenzNeuFrame.addEventListener('load', () => {
                resizePendenzFrame();
                setTimeout(resizePendenzFrame, 400);
                setTimeout(resizePendenzFrame, 1200);
            });
            window.addEventListener('resize', resizePendenzFrame);
        }

        const toggleForm = document.getElementById('toggleForm');
        const formContent = document.getElementById('formContent');
        const toggleIcon = toggleForm?.querySelector('.toggle-icon');
        const formStatusPill = document.getElementById('formStatusPill');

        if (toggleForm && formContent) {
            toggleForm.addEventListener('click', function () {
                const isCollapsed = formContent.style.display === 'none';
                if (isCollapsed) {
                    formContent.style.display = 'block';
                    if (toggleIcon) toggleIcon.style.transform = 'rotate(0deg)';
                    if (formStatusPill) {
                        formStatusPill.textContent = 'Geöffnet';
                        formStatusPill.style.background = '#dcfce7';
                        formStatusPill.style.color = '#166534';
                    }
                } else {
                    formContent.style.display = 'none';
                    if (toggleIcon) toggleIcon.style.transform = 'rotate(-90deg)';
                    if (formStatusPill) {
                        formStatusPill.textContent = 'Eingeklappt';
                        formStatusPill.style.background = '#f1f5f9';
                        formStatusPill.style.color = '#475569';
                    }
                }
            });
        }

        const btnAddNew = document.getElementById('btnAddNew');
        if (btnAddNew && toggleForm) {
            btnAddNew.addEventListener('click', function (e) {
                e.preventDefault();
                if (formContent.style.display === 'none') toggleForm.click();
                toggleForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        const isEditModeStatic = <?php echo ($isEditMode ?? false) ? 'true' : 'false'; ?>;
        if (isEditModeStatic && toggleForm && formContent.style.display === 'none') {
            toggleForm.click();
            window.requestAnimationFrame(() => toggleForm.scrollIntoView({ behavior: 'smooth', block: 'start' }));
        }
    })();

    (function () {
        const table = document.getElementById('pendenzenTable');
        if (!table) {
            return;
        }

        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => !row.querySelector('.pendenzen-table-empty'));
        const requestedListId = <?php echo $requestedListId !== null ? (int) $requestedListId : 'null'; ?>;
        const defaultListId = <?php echo $defaultProfile !== null ? (int) ($defaultProfile['id'] ?? 0) : 'null'; ?>;
        const requestedListCols = <?php echo json_encode($activeProfileCols, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
                const visibleHeaderCount = Array.from(table.querySelectorAll('thead th')).filter(th => th.style.display !== 'none').length || 1;
                emptyRow.innerHTML = `<td colspan="${visibleHeaderCount}" class="pendenzen-table-empty">Keine Treffer gefunden.</td>`;
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

        const headerRow = table.querySelector('thead tr');
        const headers = Array.from(table.querySelectorAll('thead th'));
        const defaultColumnOrder = headers.map((_, index) => index);

        headers.forEach((header, index) => {
            header.dataset.colKey = String(index);
        });

        table.querySelectorAll('tbody tr').forEach(row => {
            Array.from(row.children).forEach((cell, index) => {
                cell.dataset.colKey = String(index);
            });
        });

        function rebuildMobileSortOptions() {
            const mobileSort = document.getElementById('mobileSort');
            if (!mobileSort) {
                return;
            }

            const firstOptionValue = mobileSort.value;
            mobileSort.innerHTML = '<option value="">Sortieren nach...</option>';

            Array.from(headerRow.children).forEach((th, visualIndex) => {
                const label = (th.getAttribute('data-label') || th.innerText || '').trim();
                if (!label) {
                    return;
                }
                const option = document.createElement('option');
                option.value = String(visualIndex);
                option.textContent = label;
                mobileSort.appendChild(option);
            });

            if (firstOptionValue && Array.from(mobileSort.options).some(opt => opt.value === firstOptionValue)) {
                mobileSort.value = firstOptionValue;
            }
        }

        function reorderTableColumnsByOrder(orderIndices) {
            const seen = new Set();
            const normalizedOrder = [];

            (Array.isArray(orderIndices) ? orderIndices : []).forEach(index => {
                const num = Number(index);
                if (!Number.isInteger(num) || num < 0 || num >= defaultColumnOrder.length || seen.has(num)) {
                    return;
                }
                seen.add(num);
                normalizedOrder.push(num);
            });

            defaultColumnOrder.forEach(index => {
                if (!seen.has(index)) {
                    normalizedOrder.push(index);
                }
            });

            const orderedHeaderCells = normalizedOrder
                .map(index => headerRow.querySelector(`th[data-col-key="${index}"]`))
                .filter(Boolean);
            orderedHeaderCells.forEach(cell => headerRow.appendChild(cell));

            table.querySelectorAll('tbody tr').forEach(row => {
                const orderedCells = normalizedOrder
                    .map(index => row.querySelector(`td[data-col-key="${index}"]`))
                    .filter(Boolean);
                orderedCells.forEach(cell => row.appendChild(cell));
            });

            rebuildMobileSortOptions();
        }

        rebuildMobileSortOptions();
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
        const columnMap = {
            'id': 0,
            'erstes_bild': 1, 'bild': 1, 'cover': 1, 'first_image': 1,
            'titel': 2,
            'projekt_name': 3,
            'objekt_name': 4,
            'wohnung_name': 5,
            'status': 6,
            'wichtigkeit': 7,
            'zustaendig_name': 8,
            'startdatum': 9,
            'enddatum': 10,
            'uhrzeit': 11,
            'dauer': 12,
            'tageszeit': 13,
            'vorgaenger_id': 14,
            'vorgangsart_name': 15,
            'art': 15,
            'bilder': 16, 'count_images': 16,
            'dokumente': 17, 'count_files': 17,
            'pdf': 18,
            'kurzbeschreibung': 19,
            'sichtbarkeit': 20,
            'erstellt_am': 21,
            'geaendert_am': 22,
            'deleted_at': 23,
            'erstellt_von': 24
        };

        function setAllColumnsVisible(visible) {
            columnToggles.forEach(chk => {
                chk.checked = visible;
                updateColumnVisibility(parseInt(chk.dataset.col, 10), visible);
            });
        }

        function applyProfileColumns(colsString) {
            if (!colsString || !colsString.trim()) {
                reorderTableColumnsByOrder(defaultColumnOrder.concat([25]));
                setAllColumnsVisible(true);
                updateColumnVisibility(25, true);
                return;
            }

            const cols = colsString.split(',').map(col => col.trim()).filter(Boolean);
            const orderedIndices = [];

            columnToggles.forEach(chk => {
                chk.checked = false;
                updateColumnVisibility(parseInt(chk.dataset.col, 10), false);
            });

            cols.forEach(colName => {
                const index = columnMap[colName];
                if (index !== undefined) {
                    orderedIndices.push(index);
                    const chk = document.querySelector(`#columnToggles input[data-col="${index}"]`);
                    if (chk) {
                        chk.checked = true;
                        updateColumnVisibility(index, true);
                    }
                }
            });

            if (!orderedIndices.includes(25)) {
                orderedIndices.push(25);
            }
            updateColumnVisibility(25, true);
            reorderTableColumnsByOrder(orderedIndices);
        }

        if (profileSelect) {
            profileSelect.addEventListener('change', function () {
                const selectedOption = this.options[this.selectedIndex];
                const selectedCols = selectedOption ? (selectedOption.dataset.cols || '') : '';

                if (!this.value) {
                    localStorage.removeItem('pendenzen_active_list_id');
                    setAllColumnsVisible(true);
                    return;
                }

                localStorage.setItem('pendenzen_active_list_id', this.value);
                applyProfileColumns(selectedCols);
            });

            const savedListId = localStorage.getItem('pendenzen_active_list_id');
            let initialListId = requestedListId !== null
                ? String(requestedListId)
                : (defaultListId !== null ? String(defaultListId) : (savedListId || ''));

            if (initialListId) {
                const targetOption = Array.from(profileSelect.options).find(opt => opt.value === initialListId);
                if (targetOption) {
                    profileSelect.value = initialListId;
                    localStorage.setItem('pendenzen_active_list_id', initialListId);
                    applyProfileColumns(targetOption.dataset.cols || requestedListCols || '');
                }
            } else if (requestedListCols) {
                applyProfileColumns(requestedListCols);
            }
        }


        function filterInlineDependentSelects(rowId) {
            const projektSelect = document.querySelector('.inline-projekt-select[data-row-id="' + rowId + '"]');
            const objektSelect = document.querySelector('.inline-objekt-select[data-row-id="' + rowId + '"]');
            const wohnungSelect = document.querySelector('.inline-wohnung-select[data-row-id="' + rowId + '"]');

            if (!projektSelect || !objektSelect || !wohnungSelect) {
                return;
            }

            const projektId = projektSelect.value;
            const currentObjektValue = objektSelect.value;
            let objektStillValid = currentObjektValue === '';

            Array.from(objektSelect.options).forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }
                const match = !projektId || option.dataset.projektId === projektId;
                option.hidden = !match;
                if (match && option.value === currentObjektValue) {
                    objektStillValid = true;
                }
            });

            if (!objektStillValid) {
                objektSelect.value = '';
            }

            const objektId = objektSelect.value;
            const currentWohnungValue = wohnungSelect.value;
            let wohnungStillValid = currentWohnungValue === '';

            Array.from(wohnungSelect.options).forEach((option, index) => {
                if (index === 0) {
                    option.hidden = false;
                    return;
                }
                const match = !objektId || option.dataset.objektId === objektId;
                option.hidden = !match;
                if (match && option.value === currentWohnungValue) {
                    wohnungStillValid = true;
                }
            });

            if (!wohnungStillValid) {
                wohnungSelect.value = '';
            }
        }

        document.querySelectorAll('.inline-projekt-select').forEach(select => {
            const rowId = select.dataset.rowId;
            filterInlineDependentSelects(rowId);

            select.addEventListener('change', function () {
                filterInlineDependentSelects(this.dataset.rowId);
            });
        });

        document.querySelectorAll('.inline-objekt-select').forEach(select => {
            select.addEventListener('change', function () {
                filterInlineDependentSelects(this.dataset.rowId);
            });
        });

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

        // Quick Modal Logic (Centered Card Style)
        const fabAdd = document.getElementById('fabAdd');
        const btnQuickAdd = document.getElementById('btnQuickAdd');
        const quickDrawer = document.getElementById('quickDrawer');
        const drawerOverlay = document.getElementById('drawerOverlay');
        const drawerClose = document.getElementById('drawerClose');
        const qVorgang = document.getElementById('quickVorgang');
        const qProj = document.getElementById('quickProj');
        const qBkp = document.getElementById('qBkpId');
        const qBkpKat = document.getElementById('qBkpKatId');
        const qTemplateArea = document.getElementById('quickTemplateArea');
        const btnQuickSave = document.getElementById('btnQuickSave');
        const quickCaptureForm = document.getElementById('quickCaptureForm');
        const qTitel = document.getElementById('quickTitel');

        window.openQuickModal = function () {
            if (!quickDrawer) return;
            const fProj = document.getElementById('filter_projekt')?.value;
            if (fProj && qProj) { qProj.value = fProj; }
            quickDrawer.classList.add('open');
            drawerOverlay?.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => qTitel?.focus(), 300);
        };

        window.closeQuickModal = function () {
            quickDrawer?.classList.remove('open');
            drawerOverlay?.classList.remove('active');
            document.body.style.overflow = '';
        };

        fabAdd?.addEventListener('click', openQuickModal);
        btnQuickAdd?.addEventListener('click', openQuickModal);
        drawerClose?.addEventListener('click', closeQuickModal);
        drawerOverlay?.addEventListener('click', closeQuickModal);

        // Open main form from "Neue Pendenz" button
        const btnAddNew = document.getElementById('btnAddNew');
        const toggleForm = document.getElementById('toggleForm');
        btnAddNew?.addEventListener('click', () => {
            if (toggleForm) toggleForm.click();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Tags logic
        document.querySelectorAll('.tag-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                const space = qTitel.value ? ' ' : '';
                qTitel.value += space + btn.textContent;
                qTitel.focus();
            });
        });

        // Vorgangsart logic (Templates)
        qVorgang?.addEventListener('change', () => {
            const sel = qVorgang.options[qVorgang.selectedIndex];
            const welt = sel?.dataset.welt || 'bkp';

            qTemplateArea.style.display = 'block';
            document.getElementById('qBlockBkp').style.display = (welt === 'bkp') ? 'block' : 'none';
            document.getElementById('qBlockMieter').style.display = (welt === 'mieter') ? 'block' : 'none';
            document.getElementById('qBlockVermieter').style.display = (welt === 'vermieter') ? 'block' : 'none';
        });

        // BKP logic
        qBkp?.addEventListener('change', () => {
            const bid = qBkp.value;
            qBkpKat.innerHTML = '<option value="">— wählen —</option>';
            const cats = window.qBkpKategorien?.[bid] || [];
            cats.forEach(c => {
                const o = document.createElement('option');
                o.value = c.id;
                o.textContent = c.name;
                qBkpKat.appendChild(o);
            });
        });

        btnQuickSave?.addEventListener('click', async () => {
            if (!qTitel.value.trim()) { alert("Bitte Titel eingeben!"); qTitel.focus(); return; }

            const formData = new FormData(quickCaptureForm);
            formData.append('action', 'save_pendenz');

            btnQuickSave.disabled = true;
            btnQuickSave.innerHTML = 'Speichern...';

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    throw new Error("Server error");
                }
            } catch (err) {
                alert("Fehler beim Speichern!");
                btnQuickSave.disabled = false;
                btnQuickSave.innerHTML = '<span style="font-size:18px">+</span> Pendenz jetzt erstellen';
            }
        });


        // Auto-Berechnung Hauptformular + Inline-Tabelle
        function parseIsoDate(value) {
            if (!value) return null;
            const parts = String(value).split('-');
            if (parts.length !== 3) return null;
            const y = parseInt(parts[0], 10);
            const m = parseInt(parts[1], 10);
            const d = parseInt(parts[2], 10);
            if (!y || !m || !d) return null;
            return new Date(y, m - 1, d);
        }

        function formatIsoDate(date) {
            if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        function addDays(baseDate, days) {
            const d = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
            d.setDate(d.getDate() + days);
            return d;
        }

        function normalizeDurationDays(raw) {
            const value = String(raw || '').trim().toLowerCase();
            if (!value) return null;

            const num = value.match(/^(\d+)$/);
            if (num) return Math.max(1, parseInt(num[1], 10));

            const dayMatch = value.match(/^(\d+)\s*(tag|tage|d)$/);
            if (dayMatch) return Math.max(1, parseInt(dayMatch[1], 10));

            const weekMatch = value.match(/^(\d+)\s*(w|woche|wochen)$/);
            if (weekMatch) return Math.max(1, parseInt(weekMatch[1], 10) * 7);

            return null;
        }

        function formatDurationDays(days) {
            const n = parseInt(days, 10);
            if (!n || n < 1) return '';
            return n === 1 ? '1 Tag' : `${n} Tage`;
        }

        function todayDateOnly() {
            const now = new Date();
            return new Date(now.getFullYear(), now.getMonth(), now.getDate());
        }

        function tomorrowDateOnly() {
            return addDays(todayDateOnly(), 1);
        }

        function recalcDateFields(startInput, endInput, dauerInput, changedField) {
            if (!startInput || !endInput || !dauerInput) return;

            let start = parseIsoDate(startInput.value);
            let end = parseIsoDate(endInput.value);
            let days = normalizeDurationDays(dauerInput.value);

            // Kein Datum + Dauer vorhanden:
            // Start = heute, Ende = ab morgen für X Tage inklusiv
            if (!start && !end && days) {
                start = todayDateOnly();
                startInput.value = formatIsoDate(start);
                endInput.value = formatIsoDate(addDays(start, days));
                dauerInput.value = formatDurationDays(days);
                return;
            }

            // Weder Datum noch Dauer:
            // Standard = heute Start, morgen Ende, 1 Tag
            if (!start && !end && !days) {
                start = todayDateOnly();
                startInput.value = formatIsoDate(start);
                endInput.value = formatIsoDate(tomorrowDateOnly());
                dauerInput.value = formatDurationDays(1);
                return;
            }

            if (changedField === 'dauer' || changedField === 'start') {
                if (start && days) {
                    endInput.value = formatIsoDate(addDays(start, days));
                    dauerInput.value = formatDurationDays(days);
                    return;
                }
            }

            if (changedField === 'dauer' || changedField === 'end') {
                if (!start && end && days) {
                    startInput.value = formatIsoDate(addDays(end, -days));
                    dauerInput.value = formatDurationDays(days);
                    return;
                }
            }

            if (changedField === 'end' || changedField === 'start') {
                if (start && end) {
                    const diffMs = end.getTime() - start.getTime();
                    const diffDays = Math.floor(diffMs / 86400000);
                    if (diffDays >= 1) {
                        dauerInput.value = formatDurationDays(diffDays);
                    } else if (diffDays === 0) {
                        dauerInput.value = formatDurationDays(1);
                    }
                }
            }
        }

        function bindDateAutoCalc(startInput, endInput, dauerInput) {
            if (!startInput || !endInput || !dauerInput) return;

            startInput.addEventListener('change', function () {
                recalcDateFields(startInput, endInput, dauerInput, 'start');
            });
            endInput.addEventListener('change', function () {
                recalcDateFields(startInput, endInput, dauerInput, 'end');
            });
            dauerInput.addEventListener('change', function () {
                recalcDateFields(startInput, endInput, dauerInput, 'dauer');
            });
            dauerInput.addEventListener('blur', function () {
                const days = normalizeDurationDays(dauerInput.value);
                if (days) {
                    dauerInput.value = formatDurationDays(days);
                }
                recalcDateFields(startInput, endInput, dauerInput, 'dauer');
            });
        }

        // Hauptformular
        const mainStart = document.getElementById('startdatum');
        const mainEnd = document.getElementById('enddatum');
        const mainDauer = document.getElementById('dauer');
        bindDateAutoCalc(mainStart, mainEnd, mainDauer);

        if (mainStart && mainEnd && mainDauer) {
            const triggerMainDefault = () => {
                if (!mainStart.value && !mainEnd.value && !String(mainDauer.value || '').trim()) {
                    recalcDateFields(mainStart, mainEnd, mainDauer, 'dauer');
                }
            };
            mainDauer.addEventListener('focus', triggerMainDefault);
            if (mainDauer.form) {
                mainDauer.form.addEventListener('submit', triggerMainDefault);
            }
        }

        // Bestehende Tabelle / Inline-Zeilen
        document.querySelectorAll('tbody tr').forEach(function (row) {
            const startInput = row.querySelector('input[name="inline_startdatum"]');
            const endInput = row.querySelector('input[name="inline_enddatum"]');
            const dauerInput = row.querySelector('input[name="inline_dauer"]');
            if (startInput && endInput && dauerInput) {
                bindDateAutoCalc(startInput, endInput, dauerInput);
            }
        });

    })();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>