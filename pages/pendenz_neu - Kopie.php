<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$PAGE_TITLE = 'Neue Pendenz';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die('Keine gültige Datenbankverbindung vorhanden.');
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
    'projekt_id' => null,
    'objekt_id' => null,
    'wohnung_id' => null,
    'zustaendig_id' => null,
    'bkp_id' => null,
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

$projekte = fetchAllAssoc($mysqli, "SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name ASC");
$objekte = fetchAllAssoc($mysqli, "SELECT id, projekt_id, name FROM objekte ORDER BY projekt_id ASC, name ASC");
$wohnungen = fetchAllAssoc($mysqli, "SELECT id, objekt_id, name FROM wohnungen ORDER BY objekt_id ASC, name ASC");
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
$bkpTextsByBkp = [];
if ($hasBkpTextTable) {
    foreach (fetchAllAssoc($mysqli, "SELECT id, kategorie_id, text FROM bkp_vorlagen_texte ORDER BY id ASC") as $row) {
        $bid = (int) ($row['kategorie_id'] ?? 0);
        if ($bid > 0) {
            $bkpTextsByBkp[$bid] ??= [];
            $bkpTextsByBkp[$bid][] = $row;
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

    $art = $input['vorgangsart_id'] ? ($artMap[$input['vorgangsart_id']] ?? null) : null;
    $vorlagenWelt = (string) ($art['vorlagen_welt'] ?? '');
    $empfaengerTyp = (string) ($art['empfaenger_typ'] ?? '');

    $dynamicTitel = '';
    $dynamicKurzbeschreibung = '';
    $dynamicBeschreibung = '';

    if ($vorlagenWelt === 'bkp' && $input['bkp_id']) {
        $bkp = $bkpMap[$input['bkp_id']] ?? null;
        if ($bkp) {
            $dynamicTitel = trim((string) ($bkp['code'] ?? '') . ' ' . (string) ($bkp['bezeichnung'] ?? ''));
        }
        if ($input['bkp_text_id'] && isset($bkpTextsByBkp[$input['bkp_id']])) {
            foreach ($bkpTextsByBkp[$input['bkp_id']] as $txt) {
                if ((int) ($txt['id'] ?? 0) === (int) $input['bkp_text_id']) {
                    $dynamicKurzbeschreibung = trim((string) ($txt['text'] ?? ''));
                    break;
                }
            }
        }
    } elseif ($vorlagenWelt === 'mieter') {
        if ($input['mieter_subkategorie_id'] && isset($mieterSubMap[$input['mieter_subkategorie_id']])) {
            $sub = $mieterSubMap[$input['mieter_subkategorie_id']];
            $dynamicTitel = trim((string) ($sub['name'] ?? ''));
            $dynamicKurzbeschreibung = trim((string) ($sub['beschreibung'] ?? ''));
        } elseif ($input['mieter_kategorie_id'] && isset($mieterCatMap[$input['mieter_kategorie_id']])) {
            $dynamicTitel = trim((string) ($mieterCatMap[$input['mieter_kategorie_id']]['name'] ?? ''));
        }
    } elseif ($vorlagenWelt === 'vermieter') {
        if ($input['vermieter_subkategorie_id'] && isset($vermieterSubMap[$input['vermieter_subkategorie_id']])) {
            $sub = $vermieterSubMap[$input['vermieter_subkategorie_id']];
            $dynamicTitel = trim((string) ($sub['name'] ?? ''));
            $dynamicKurzbeschreibung = trim((string) ($sub['beschreibung'] ?? ''));
        } elseif ($input['vermieter_kategorie_id'] && isset($vermieterCatMap[$input['vermieter_kategorie_id']])) {
            $dynamicTitel = trim((string) ($vermieterCatMap[$input['vermieter_kategorie_id']]['name'] ?? ''));
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
                zustaendig_typ
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'user'
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
            ];
            $bindTypes = '';
            foreach ($bindValues as $bindValue) {
                $bindTypes .= is_int($bindValue) || is_bool($bindValue) ? 'i' : 's';
            }
            $stmt->bind_param($bindTypes, ...$bindValues);

            if ($stmt->execute()) {
                $newId = (int) $stmt->insert_id;
                $success = 'Pendenz gespeichert. ID: ' . $newId;
                $prefillArt = $input['vorgangsart_id'];
                $input = [
                    'vorgangsart_id' => $prefillArt,
                    'projekt_id' => null,
                    'objekt_id' => null,
                    'wohnung_id' => null,
                    'zustaendig_id' => null,
                    'bkp_id' => null,
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
.pneu-wrap{max-width:1380px;margin:22px auto;padding:0 16px}
.pneu-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;margin-bottom:18px}
.pneu-head h1{margin:0;font-size:30px;line-height:1.1;color:#0f172a}
.pneu-back{display:inline-flex;align-items:center;gap:8px;padding:11px 15px;border:1px solid #d9e2ef;border-radius:14px;background:#fff;color:#1f2937;text-decoration:none;box-shadow:0 6px 18px rgba(15,23,42,.04)}
.pneu-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 12px}
.pneu-section-title h3{margin:0;font-size:14px;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.pneu-section-line{height:1px;flex:1;background:linear-gradient(90deg,#dbe4f0,transparent)}
.pneu-grid{display:grid;grid-template-columns:1.18fr .92fr;gap:16px}
.pneu-grid.tight{grid-template-columns:1fr 1fr}
.pneu-card{background:linear-gradient(180deg,#ffffff 0%,#fbfdff 100%);border:1px solid #e2e8f0;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(15,23,42,.06)}
.pneu-card h2{margin:0 0 14px;font-size:19px;color:#0f172a}
.pneu-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.pneu-field{display:flex;flex-direction:column;gap:6px}
.pneu-field.full{grid-column:1/-1}
.pneu-field label{font-size:13px;font-weight:700;color:#334155}
.pneu-field input,.pneu-field select,.pneu-field textarea{width:100%;border:1px solid #cfd8e3;border-radius:14px;padding:12px 13px;font:inherit;background:#fff;transition:border-color .18s ease, box-shadow .18s ease}
.pneu-field input:focus,.pneu-field select:focus,.pneu-field textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.10)}
.pneu-field textarea{min-height:110px;resize:vertical}
.pneu-switches{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.pneu-check{display:flex;align-items:center;gap:8px;padding:11px 12px;border:1px solid #dbe4f0;border-radius:14px;background:#f8fbff}
.pneu-check input{width:auto}
.pneu-msg{padding:13px 15px;border-radius:14px;margin-bottom:14px}
.pneu-msg.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#166534}
.pneu-msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.pneu-note{font-size:12px;color:#64748b}
.pneu-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.pneu-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 16px;border-radius:14px;border:1px solid #2159a8;background:#2563eb;color:#fff;text-decoration:none;font-weight:700;cursor:pointer;box-shadow:0 10px 22px rgba(37,99,235,.18)}
.pneu-btn.alt{background:#fff;color:#1f2937;border-color:#d7deea;box-shadow:none}
.pneu-box{padding:14px;border:1px dashed #cbd5e1;border-radius:16px;background:#fafcff}
.pneu-preview{font-size:14px;line-height:1.5}
.pneu-preview strong{display:block;margin-bottom:6px;color:#0f172a}
.pneu-chip{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;margin-right:6px;margin-bottom:6px}
.pneu-simple-table-wrap{overflow:auto;border-radius:18px;border:1px solid #e5e7eb}
.pneu-simple-table{width:100%;border-collapse:collapse;font-size:14px;background:#fff}
.pneu-simple-table th,.pneu-simple-table td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
.pneu-simple-table th{font-size:12px;text-transform:uppercase;color:#64748b;background:#f8fafc}
.pneu-hero{display:grid;grid-template-columns:1.2fr .8fr;gap:14px;margin-bottom:16px}
.pneu-stat{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:16px 18px;box-shadow:0 10px 24px rgba(15,23,42,.05)}
.pneu-stat-label{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}
.pneu-stat-value{font-size:22px;font-weight:800;color:#0f172a;margin-top:4px}
@media (max-width: 980px){.pneu-grid,.pneu-grid.tight,.pneu-hero{grid-template-columns:1fr}.pneu-fields{grid-template-columns:1fr}.pneu-switches{grid-template-columns:1fr}.pneu-head{align-items:flex-start;flex-direction:column}}
</style>

<div class="pneu-wrap">
    <div class="pneu-head">
        <div>
            <h1>Neue Pendenz</h1>
            <div class="pneu-note">Separates Formular. Tabelle und Dashboard bleiben unberührt.</div>
        </div>
        <a class="pneu-back" href="<?php echo h($prefix . 'pages/pendenzen.php'); ?>">← Zur Pendenzenliste</a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="pneu-msg ok"><?php echo h($success); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="pneu-msg err"><?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="pneu-hero">
        <div class="pneu-stat">
            <div class="pneu-stat-label">Schnellerfassung</div>
            <div class="pneu-stat-value">Neue Pendenz</div>
            <div class="pneu-note" style="margin-top:6px;">Oben Grunddaten und Text. Unten Vorlagen und Zeit kompakt gruppiert.</div>
        </div>
        <div class="pneu-stat">
            <div class="pneu-stat-label">Hinweis</div>
            <div class="pneu-stat-value" style="font-size:18px;">Manuell + Vorlage</div>
            <div class="pneu-note" style="margin-top:6px;">Titel und Kurzbeschreibung werden sauber zusammengeführt.</div>
        </div>
    </div>

    <div class="pneu-card" style="margin-bottom:16px;">
        <h2>Einfache Übersicht</h2>
        <div class="pneu-note" style="margin-bottom:10px;">Zeigt einfach die letzten Punkte an, egal wie.</div>
        <div class="pneu-simple-table-wrap">
        <table class="pneu-simple-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Titel</th>
                    <th>Kurzbeschreibung</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($quickRows === []): ?>
                    <tr><td colspan="4">Noch keine Pendenzen vorhanden.</td></tr>
                <?php else: ?>
                    <?php foreach ($quickRows as $row): ?>
                        <tr>
                            <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                            <td><?php echo h($row['titel'] ?? ''); ?></td>
                            <td><?php echo h($row['kurzbeschreibung'] ?? ''); ?></td>
                            <td><?php echo h($row['status'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <form method="post" action="">
        <div class="pneu-section-title"><h3>Erstellen</h3><div class="pneu-section-line"></div></div>
        <div class="pneu-grid">
            <div class="pneu-card">
                <h2>1. Grunddaten</h2>
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
                        <select name="wohnung_id" id="wohnung_id">
                            <option value="">— keine Wohnung —</option>
                            <?php foreach ($wohnungen as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" data-objekt-id="<?php echo (int) ($row['objekt_id'] ?? 0); ?>" <?php echo ((int) ($input['wohnung_id'] ?? 0) === (int) $row['id']) ? 'selected' : ''; ?>><?php echo h((string) $row['name']); ?></option>
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
                <div style="height:12px"></div>
                <div class="pneu-box pneu-preview">
                    <strong>Finaler Text</strong>
                    <div><span class="pneu-note">Titel</span><div id="preview_titel">—</div></div>
                    <div style="margin-top:10px"><span class="pneu-note">Kurzbeschreibung</span><div id="preview_kurz">—</div></div>
                    <div style="margin-top:10px"><span class="pneu-note">Beschreibung</span><div id="preview_beschreibung">—</div></div>
                </div>
            </div>
        </div>

        <div class="pneu-section-title"><h3>Text & Vorlagen</h3><div class="pneu-section-line"></div></div>

        <div class="pneu-grid">
            <div class="pneu-card">
                <h2>3. Freie Eingabe</h2>
                <div class="pneu-fields">
                    <div class="pneu-field full">
                        <label for="titel_manuell">Titel manuell</label>
                        <input type="text" name="titel_manuell" id="titel_manuell" value="<?php echo h($input['titel_manuell']); ?>" placeholder="z. B. Badzimmer, Eingang, Terrasse ...">
                    </div>
                    <div class="pneu-field full">
                        <label for="kurzbeschreibung_manuell">Kurzbeschreibung manuell</label>
                        <input type="text" name="kurzbeschreibung_manuell" id="kurzbeschreibung_manuell" value="<?php echo h($input['kurzbeschreibung_manuell']); ?>" placeholder="z. B. bitte prüfen, Mangel sichtbar, Termin nötig ...">
                    </div>
                    <div class="pneu-field full">
                        <label for="beschreibung_manuell">Beschreibung / Notiz manuell</label>
                        <textarea name="beschreibung_manuell" id="beschreibung_manuell" placeholder="Freier Text für Details."><?php echo h($input['beschreibung_manuell']); ?></textarea>
                    </div>
                    <div class="pneu-field full">
                        <label for="notiz">Interne Notiz</label>
                        <textarea name="notiz" id="notiz" placeholder="Optional."><?php echo h($input['notiz']); ?></textarea>
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
                            <label for="bkp_text_id">BKP Kurzbeschreibung</label>
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

        <div class="pneu-section-title"><h3>Zeit & Optionen</h3><div class="pneu-section-line"></div></div>

        <div class="pneu-grid tight">
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
                        <input type="text" name="dauer" id="dauer" value="<?php echo h($input['dauer']); ?>" placeholder="z. B. 30 Min / 2 Std.">
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
    </form>
</div>

<script>
const artMap = <?php echo json_encode($artMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const defaultsByArt = <?php echo json_encode($defaultsByArt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const users = <?php echo json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const userFirmaBkpMap = <?php echo json_encode($userFirmaBkpMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const bkpTextsByBkp = <?php echo json_encode($bkpTextsByBkp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const mieterSubkategorien = <?php echo json_encode($mieterSubkategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const vermieterSubkategorien = <?php echo json_encode($vermieterSubkategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const mieterKategorien = <?php echo json_encode($mieterKategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const vermieterKategorien = <?php echo json_encode($vermieterKategorien, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const bkpCodes = <?php echo json_encode($bkpCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const elArt = document.getElementById('vorgangsart_id');
const elProjekt = document.getElementById('projekt_id');
const elObjekt = document.getElementById('objekt_id');
const elWohnung = document.getElementById('wohnung_id');
const elEmpf = document.getElementById('zustaendig_id');
const elBkp = document.getElementById('bkp_id');
const elBkpText = document.getElementById('bkp_text_id');
const elMieterKat = document.getElementById('mieter_kategorie_id');
const elMieterSub = document.getElementById('mieter_subkategorie_id');
const elVermieterKat = document.getElementById('vermieter_kategorie_id');
const elVermieterSub = document.getElementById('vermieter_subkategorie_id');
const elTitelMan = document.getElementById('titel_manuell');
const elKurzMan = document.getElementById('kurzbeschreibung_manuell');
const elBeschrMan = document.getElementById('beschreibung_manuell');
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
    [...elObjekt.options].forEach((opt, index) => {
        if (index === 0) return;
        const show = !projektId || String(opt.dataset.projektId || '') === String(projektId);
        opt.hidden = !show;
    });
    if (elObjekt.selectedOptions[0] && elObjekt.selectedOptions[0].hidden) {
        elObjekt.value = '';
    }
}

function filterWohnungen() {
    const objektId = elObjekt.value;
    [...elWohnung.options].forEach((opt, index) => {
        if (index === 0) return;
        const show = !objektId || String(opt.dataset.objektId || '') === String(objektId);
        opt.hidden = !show;
    });
    if (elWohnung.selectedOptions[0] && elWohnung.selectedOptions[0].hidden) {
        elWohnung.value = '';
    }
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
    refillBkpTexts();
}

function refillBkpTexts() {
    const list = bkpTextsByBkp[String(elBkp.value)] || [];
    refillSelect(elBkpText, list.map(row => ({ value: row.id, label: row.text })), '— auswählen —', elBkpText.value);
}

function refillSubKategorien() {
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

    const mSubs = mieterSubkategorien
        .filter(row => String(row.kategorie_id) === String(elMieterKat.value))
        .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
    refillSelect(elMieterSub, mSubs, '— auswählen —', elMieterSub.value);

    const vSubs = vermieterSubkategorien
        .filter(row => String(row.kategorie_id) === String(elVermieterKat.value))
        .map(row => ({ value: row.id, label: row.beschreibung ? `${row.name} — ${row.beschreibung}` : row.name }));
    refillSelect(elVermieterSub, vSubs, '— auswählen —', elVermieterSub.value);
}

function applyArtState() {
    const art = selectedArt();
    const welt = String(art?.vorlagen_welt || '');
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

    filterEmpfaenger(true);
    filterBkpOptions(true);
    refillSubKategorien();
    updatePreview();
}

function currentDynamic() {
    const art = selectedArt();
    const welt = String(art?.vorlagen_welt || '');
    let titel = '';
    let kurz = '';
    let beschreibung = '';

    if (welt === 'bkp') {
        const selected = bkpCodes.find(row => String(row.id) === String(elBkp.value));
        if (selected) titel = `${selected.code} ${selected.bezeichnung}`.trim();
        const list = bkpTextsByBkp[String(elBkp.value)] || [];
        const txt = list.find(row => String(row.id) === String(elBkpText.value));
        if (txt) kurz = txt.text || '';
    } else if (welt === 'mieter') {
        const sub = mieterSubkategorien.find(row => String(row.id) === String(elMieterSub.value));
        const cat = mieterKategorien.find(row => String(row.id) === String(elMieterKat.value));
        if (sub) {
            titel = sub.name || '';
            kurz = sub.beschreibung || '';
        } else if (cat) {
            titel = cat.name || '';
        }
    } else if (welt === 'vermieter') {
        const sub = vermieterSubkategorien.find(row => String(row.id) === String(elVermieterSub.value));
        const cat = vermieterKategorien.find(row => String(row.id) === String(elVermieterKat.value));
        if (sub) {
            titel = sub.name || '';
            kurz = sub.beschreibung || '';
        } else if (cat) {
            titel = cat.name || '';
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
    const dyn = currentDynamic();
    previewTitel.textContent = joinUniqueJs([elTitelMan.value, dyn.titel]) || '—';
    previewKurz.textContent = joinUniqueJs([elKurzMan.value, dyn.kurz]) || '—';
    previewBeschreibung.textContent = [elBeschrMan.value, dyn.beschreibung].map(v => String(v || '').trim()).filter(Boolean).join(' | ') || '—';
}

elArt.addEventListener('change', () => {
    applyArtState();
    applyDefaultsFromArt();
});
elProjekt.addEventListener('change', () => { filterObjekte(); filterWohnungen(); refillSubKategorien(); });
elObjekt.addEventListener('change', () => { filterWohnungen(); refillSubKategorien(); });
elEmpf.addEventListener('change', () => { filterBkpOptions(false); updatePreview(); });
elBkp.addEventListener('change', () => { refillBkpTexts(); updatePreview(); });
elBkpText.addEventListener('change', updatePreview);
elMieterKat.addEventListener('change', () => { refillSubKategorien(); updatePreview(); });
elMieterSub.addEventListener('change', updatePreview);
elVermieterKat.addEventListener('change', () => { refillSubKategorien(); updatePreview(); });
elVermieterSub.addEventListener('change', updatePreview);
[elTitelMan, elKurzMan, elBeschrMan].forEach(el => el.addEventListener('input', updatePreview));

filterObjekte();
filterWohnungen();
applyArtState();
filterEmpfaenger(false);
filterBkpOptions(false);
refillSubKategorien();
updatePreview();
if (elArt.value) {
    applyDefaultsFromArt();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
