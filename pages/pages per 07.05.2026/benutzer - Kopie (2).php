<?php
// pages/benutzer.php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vorgang_taxonomy.php';

/* ===== Tabellen-/Spalten-Checker ===== */
if (!function_exists('table_has_columns')) {
    function table_has_columns(mysqli $db, string $table, array $cols): array {
        $in = implode("','", array_map('strval', $cols));
        $sql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN ('$in')";
        $have = [];
        try {
            $st=$db->prepare($sql);
            $st->bind_param('s',$table);
            $st->execute();
            $rs=$st->get_result();
            while($r=$rs->fetch_assoc()){ $have[]=$r['COLUMN_NAME']; }
            $st->close();
        } catch(Throwable $e){}
        return $have;
    }
}

// Safe-Check Spalte vorgangsart_id in benutzer
try {
    if (!table_has_columns($mysqli, 'benutzer', ['vorgangsart_id'])) {
        $mysqli->query("ALTER TABLE benutzer ADD COLUMN vorgangsart_id INT NULL DEFAULT NULL AFTER wohnung_id");
    }
} catch (Throwable $e) {}

/* === Auswahlmodus (für Rückkehr in eine andere Seite) === */
$select_mode = (isset($_GET['select']) && $_GET['select']=='1' && !empty($_GET['return_to']));
$return_to   = $select_mode ? (string)$_GET['return_to'] : '';
$ve_id_pick  = $select_mode ? (int)($_GET['ve_id'] ?? 0) : 0;

/* --- AJAX Hierarchy Handler --- */
if (isset($_GET['ajax_hierarchy'])) {
    header('Content-Type: application/json');
    $pId = (int)($_GET['pId'] ?? 0);
    $oId = (int)($_GET['oId'] ?? 0);
    $res = ['objects' => [], 'units' => []];

    if ($pId > 0) {
        $st = $mysqli->prepare("
            SELECT id, name
            FROM objekte
            WHERE projekt_id = ?
            ORDER BY name ASC
        ");
        $st->bind_param('i', $pId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $res['objects'][] = $r;
        $st->close();
    }
    if ($oId > 0) {
        $st = $mysqli->prepare("
            SELECT id, name, etage, zimmer, flaeche
            FROM wohnungen
            WHERE objekt_id = ?
            ORDER BY name ASC
        ");
        $st->bind_param('i', $oId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) {
            $parts = [];
            if (!empty($r['etage']))  $parts[] = trim((string)$r['etage']);
            if ((string)$r['zimmer'] !== '' && $r['zimmer'] !== null) $parts[] = rtrim(rtrim((string)$r['zimmer'], '0'), '.') . ' Zi.';
            if ((string)$r['flaeche'] !== '' && $r['flaeche'] !== null) $parts[] = rtrim(rtrim((string)$r['flaeche'], '0'), '.') . ' m²';
            $r['display_name'] = trim($r['name'] . (!empty($parts) ? ' — ' . implode(', ', $parts) : ''));
            $res['units'][] = $r;
        }
        $st->close();
    }
    echo json_encode($res);
    exit;
}

require_login();

require_once __DIR__ . '/../includes/functions.php';     // handle_upload(), url(), evtl. h()
require_once __DIR__ . '/../includes/links.php';
// chips_style_once(); // Verschoben nach header.php inclusion unten

require_once __DIR__ . '/../includes/csrf_benutzer.php'; // benutzer_csrf_* nur für diese Seite
require_once __DIR__ . '/../includes/user_ui.php';
require_once __DIR__ . '/../includes/mailer.php';        // send_mail_html()
require_once __DIR__ . '/../includes/user_folder_automation.php';

// Fallback für h()
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* Taxonomie (optional) */
$types = [];
$statusesMap = [];
$__utPath = __DIR__ . '/../includes/user_taxonomy.php';
if (is_file($__utPath)) {
    require_once $__utPath;
    if (function_exists('user_types_all')) {
        try { $types = user_types_all($mysqli); } catch (Throwable $e) { $types = []; }
    }
    if (function_exists('user_statuses_all_grouped')) {
        try { $statusesMap = user_statuses_all_grouped($mysqli); } catch (Throwable $e) { $statusesMap = []; }
    }
} else {
    if (function_exists('app_log')) app_log('Hinweis: includes/user_taxonomy.php fehlt – Typ/Status-UI deaktiviert.');
}

$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

/* ===== Rollen & Rechte ===== */
$currentRole   = (string)current_role();
$canViewOthers = in_array($currentRole, ['admin','superadmin'], true);

/* ===== Welches Profil anzeigen/bearbeiten? ===== */
$sid    = (int)(current_user_id() ?? 0);
$viewId = (int)(filter_input(INPUT_GET, 'view', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);
$viewId = $viewId ? (int)$viewId : $sid;

if (!$canViewOthers && $viewId !== $sid) {
    $target = rtrim($PREFIX, '/') . '/pages/benutzer.php?view=' . $sid;
    header('Location: ' . $target, true, 302);
    exit;
}

/* ===== Header/Navi ===== */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
chips_style_once(); // Jetzt hier (nach doctype)

/* ===== User laden ===== */
$stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { http_response_code(404); exit('Benutzer nicht gefunden.'); }

/* ===== Tabellen-/Spalten-Checker: (Definition nach oben verschoben) ===== */
$benutzerCols = table_has_columns($mysqli,'benutzer',['person_type_id','person_status_id','projekt_id','objekt_id','wohnung_id','business_type','mieter_phase']);


function normalize_mieter_phase(?string $phase): string {
    $phase = trim((string)$phase);
    if ($phase === 'bewerber') return 'interessent';
    $allowed = ['interessent','mieter','vormieter','gekündigt'];
    return in_array($phase, $allowed, true) ? $phase : 'interessent';
}

function resolve_assignment_ids(mysqli $db, ?int $projektId, ?int $objektId, ?int $wohnungId): array {
    $projektId = (int)($projektId ?? 0);
    $objektId  = (int)($objektId ?? 0);
    $wohnungId = (int)($wohnungId ?? 0);

    if ($wohnungId > 0) {
        $st = $db->prepare("
            SELECT w.id AS wohnung_id, w.objekt_id, o.projekt_id
            FROM wohnungen w
            INNER JOIN objekte o ON o.id = w.objekt_id
            WHERE w.id = ?
            LIMIT 1
        ");
        $st->bind_param('i', $wohnungId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            return [
                'projekt_id' => (int)$row['projekt_id'],
                'objekt_id'  => (int)$row['objekt_id'],
                'wohnung_id' => (int)$row['wohnung_id'],
            ];
        }
    }

    if ($objektId > 0) {
        $st = $db->prepare("SELECT id, projekt_id FROM objekte WHERE id = ? LIMIT 1");
        $st->bind_param('i', $objektId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            return [
                'projekt_id' => (int)$row['projekt_id'],
                'objekt_id'  => (int)$row['id'],
                'wohnung_id' => 0,
            ];
        }
    }

    return [
        'projekt_id' => $projektId > 0 ? $projektId : 0,
        'objekt_id'  => 0,
        'wohnung_id' => 0,
    ];
}

function fetch_assignment_projects(mysqli $db): array {
    $sql = "
        SELECT id, name
        FROM projekte
        WHERE (deleted_at IS NULL OR deleted_at < '1000-01-01')
          AND name <> 'Abgearbeitet'
        ORDER BY name ASC
    ";
    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function fetch_assignment_objects(mysqli $db): array {
    $sql = "
        SELECT id, projekt_id, name
        FROM objekte
        ORDER BY name ASC
    ";
    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function fetch_assignment_units(mysqli $db): array {
    $sql = "
        SELECT id, objekt_id, name, etage, zimmer, flaeche
        FROM wohnungen
        ORDER BY name ASC
    ";
    $res = $db->query($sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as &$row) {
        $parts = [];
        if (!empty($row['etage'])) $parts[] = trim((string)$row['etage']);
        if ((string)$row['zimmer'] !== '' && $row['zimmer'] !== null) $parts[] = rtrim(rtrim((string)$row['zimmer'], '0'), '.') . ' Zi.';
        if ((string)$row['flaeche'] !== '' && $row['flaeche'] !== null) $parts[] = rtrim(rtrim((string)$row['flaeche'], '0'), '.') . ' m²';
        $row['display_name'] = trim($row['name'] . (!empty($parts) ? ' — ' . implode(', ', $parts) : ''));
    }
    unset($row);
    return $rows;
}

// Hierarchy Data for Dropdowns
$projects = fetch_assignment_projects($mysqli);
$objectsAll = fetch_assignment_objects($mysqli);
$unitsAll = fetch_assignment_units($mysqli);

// Vorgangsarten (Typen) laden
$vorgangsarten = [];
try {
    $vRes = $mysqli->query("SELECT id, name FROM pendenzen_arten ORDER BY sort_order ASC, name ASC");
    if ($vRes) {
        while ($vr = $vRes->fetch_assoc()) $vorgangsarten[] = $vr;
        $vRes->close();
    }
} catch (Throwable $ve) {}

$hierarchyJS = [
    'projects' => $projects,
    'objects' => [],
    'units' => []
];
foreach($objectsAll as $o) {
    if (!isset($hierarchyJS['objects'][$o['projekt_id']])) $hierarchyJS['objects'][$o['projekt_id']] = [];
    $hierarchyJS['objects'][$o['projekt_id']][] = $o;
}
foreach($unitsAll as $u) {
    if (!isset($hierarchyJS['units'][$u['objekt_id']])) $hierarchyJS['units'][$u['objekt_id']] = [];
    $hierarchyJS['units'][$u['objekt_id']][] = $u;
}

// Pre-select for Neuanlage if wohnung_id is in GET
$preW = (int)($_GET['wohnung_id'] ?? 0);
$preO = $preP = 0;
if ($preW > 0) {
    $rowH = $mysqli->query("SELECT w.objekt_id, o.projekt_id FROM wohnungen w JOIN objekte o ON o.id=w.objekt_id WHERE w.id=$preW")->fetch_assoc();
    if($rowH){ $preO = (int)$rowH['objekt_id']; $preP = (int)$rowH['projekt_id']; }
}

/* ===== Firmen-Helfer ===== */
function firmen_available_columns(mysqli $db): array {
    $cols = [];
    try {
        $res = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='firmen'");
        while ($r = $res->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
        $res->close();
    } catch (Throwable $e) {}
    return $cols;
}
function firmen_select_all(mysqli $db): array {
    $sqlFull = "SELECT id,name,adresse,telefon,email,website,logo FROM firmen ORDER BY name";
    try {
        $out=[]; if ($res=$db->query($sqlFull)) { while($r=$res->fetch_assoc()) $out[]=$r; $res->close(); return $out; }
    } catch(Throwable $e){}
    $out=[]; try {
        if ($res=$db->query("SELECT id,name FROM firmen ORDER BY name")) {
            while($r=$res->fetch_assoc()){
                $r += ['adresse'=>null,'telefon'=>null,'email'=>null,'website'=>null,'logo'=>null];
                $out[]=$r;
            }
            $res->close();
        }
    } catch(Throwable $e){}
    return $out;
}
function firmen_by_id(mysqli $db, int $id): ?array {
    if ($id<=0) return null;
    try {
        $res = $db->query("SELECT * FROM firmen WHERE id=$id");
        return $res->fetch_assoc();
    } catch(Throwable $e){ return null; }
}
$firmen = firmen_select_all($mysqli);
$firmen_by_id = []; foreach($firmen as $f) $firmen_by_id[$f['id']] = $f;

// BKP Codes fetching
$bkp_codes_all = [];
$res_bkp = $mysqli->query("SELECT id, code, bezeichnung FROM bkp_codes ORDER BY code ASC");
if ($res_bkp) while($rb = $res_bkp->fetch_assoc()) $bkp_codes_all[] = $rb;

function firmen_insert_dynamic(mysqli $db, array $data): ?int {
    $avail = firmen_available_columns($db);
    if (!in_array('name',$avail,true)) return null;
    $cols = ['name']; $types='s'; $params = [$data['name']];
    foreach (['adresse','telefon','email','website','logo'] as $c) {
        if (in_array($c,$avail,true) && isset($data[$c])) {
            $cols[] = $c; $types .= 's'; $params[] = $data[$c];
        }
    }
    $place = implode(',', array_fill(0,count($cols),'?'));
    $sql = "INSERT INTO firmen (".implode(',',$cols).") VALUES ($place)";
    $st = $db->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $id = $db->insert_id;
    $st->close();
    return (int)$id;
}

/* ===== Firmen laden ===== */
$firmen = firmen_select_all($mysqli);
$firmen_by_id = []; foreach ($firmen as $f) $firmen_by_id[(int)$f['id']] = $f;

/* ===== Felder & Sichtbarkeit ===== */
$FIELDS = [
  'name'            => 'Name',
  'email'           => 'E-Mail',
  'telefonnummer'   => 'Telefon',
  'adresse'         => 'Adresse',
  'firma_name'      => 'Firma – Name',
  'firma_adresse'   => 'Firma – Adresse',
  'firma_telefon'   => 'Firma – Telefon',
  'firma_email'     => 'Firma – E-Mail',
  'firma_website'   => 'Firma – Website',
  'geburtsdatum'    => 'Geburtstag',
  'heimatland'      => 'Heimatland',
  'position'        => 'Position',
  'beruf'           => 'Beruf',
  'kontaktweg'      => 'Bevorz. Kontaktweg',
];
$LEVELS = ['private','internal','project','public'];
$LEVEL_LABEL = ['private'=>'Nur ich','internal'=>'Intern','project'=>'Projekt','public'=>'Öffentlich'];
$LEVEL_ICON  = ['private'=>'🔒','internal'=>'🏢','project'=>'👥','public'=>'🌍'];

/* ===== Sichtbarkeit laden + Defaults ===== */
$profileVis = [];
if (!empty($user['profile_vis'])) { $tmp = json_decode($user['profile_vis'], true); if (is_array($tmp)) $profileVis = $tmp; }
foreach (array_keys($FIELDS) as $k) { if (empty($profileVis[$k])) $profileVis[$k] = 'private'; }
foreach (['profilbild','firmenlogo','titelbild'] as $imgK) {
  $key = 'vis_'.$imgK; if (empty($profileVis[$key])) $profileVis[$key] = 'private';
}
if (!isset($profileVis['titelbild_max_height'])) $profileVis['titelbild_max_height'] = 260;
if (!isset($profileVis['profilbild_max_size']))  $profileVis['profilbild_max_size']  = 160;
if (!isset($profileVis['firmenlogo_max_size']))  $profileVis['firmenlogo_max_size']  = 160;

/* ===== Bild-Helfer ===== */
function _bp_prefix_strip(string $path, string $PREFIX): string {
    if (strpos($path, $PREFIX) === 0) return substr($path, strlen($PREFIX));
    return ltrim($path, '/');
}
function _bp_rel_to_abs(string $rel): string {
    return realpath(__DIR__ . '/..') . '/' . ltrim($rel, '/');
}
function _bp_abs_to_rel(string $abs): string {
    $root = realpath(__DIR__ . '/..');
    $abs = str_replace('\\','/',$abs); $root = str_replace('\\','/',$root);
    if (strpos($abs, $root) === 0) {
        $rel = substr($abs, strlen($root));
        return '/' . ltrim($rel, '/');
    }
    return '';
}
function _bp_change_ext(string $rel, string $newExt): string {
    $pi = pathinfo($rel);
    return rtrim(($pi['dirname'] ?? '/'),'/') . '/' . ($pi['filename'] ?? 'image') . '.' . ltrim($newExt,'.');
}
function remove_local_upload(?string $pathOrUrl, string $PREFIX): void {
    if (!$pathOrUrl) return;
    $rel = _bp_prefix_strip($pathOrUrl, $PREFIX);
    $absUploads = realpath(__DIR__ . '/../uploads/');
    $absFile    = realpath(__DIR__ . '/../' . ltrim($rel, '/'));
    if ($absUploads && $absFile && strpos($absFile, $absUploads) === 0 && is_file($absFile)) {
        @unlink($absFile);
    }
}
function sanitize_image_url(?string $url, string $PREFIX): ?string {
    if (!$url) return null;
    $u = (string)$url; $low = strtolower($u);
    $block = ['assets/img/placeholder_','placehold.co','placeholder.com','via.placeholder.com','dummyimage.com','placehold.it','ui-avatars.com','picsum.photos'];
    foreach ($block as $needle) { if (strpos($low, $needle) !== false) return null; }
    if (strpos($low, 'data:image/svg+xml') === 0) return null;
    $rel = _bp_prefix_strip($u, $PREFIX);
    if (preg_match('~^/?uploads/~', $rel)) {
        $abs = _bp_rel_to_abs($rel);
        if (!is_file($abs)) return null;
        return rtrim($PREFIX, '/') . '/' . ltrim($rel, '/');
    }
    return $u;
}
function shrink_image_under_limit(string $pathOrUrl, int $maxW, int $maxH, int $maxBytes, string $PREFIX): ?string {
    if (!$pathOrUrl) return null;
    $rel = _bp_prefix_strip($pathOrUrl, $PREFIX);
    if (!preg_match('~^uploads/~', ltrim($rel,'/'))) return $pathOrUrl;
    $abs = _bp_rel_to_abs($rel);
    if (!file_exists($abs)) return $pathOrUrl;

    $info = @getimagesize($abs); if (!$info) return $pathOrUrl;
    $mime = $info['mime'] ?? 'image/jpeg'; $srcW=$info[0]; $srcH=$info[1];

    switch ($mime) {
        case 'image/jpeg': $im = @imagecreatefromjpeg($abs); break;
        case 'image/png':  $im = @imagecreatefrompng($abs);  break;
        case 'image/gif':  $im = @imagecreatefromgif($abs);  break;
        default: $im = @imagecreatefromstring(@file_get_contents($abs)); $mime='image/jpeg';
    }
    if (!$im) return $pathOrUrl;

    $scale = 1.0;
    if ($srcW > $maxW || $srcH > $maxH) $scale = min($maxW / max(1,$srcW), $maxH / max(1,$srcH));
    $tW = max(1,(int)floor($srcW*$scale)); $tH = max(1,(int)floor($srcH*$scale));

    $work = imagecreatetruecolor($tW,$tH);
    $white=imagecolorallocate($work,255,255,255);
    imagefilledrectangle($work,0,0,$tW,$tH,$white);
    imagecopyresampled($work,$im,0,0,0,0,$tW,$tH,$srcW,$srcH);
    imagedestroy($im);

    $tmp = $abs.'.tmp.jpg';
    $q=85; $downs=0;
    do{
        @imagejpeg($work,$tmp,$q);
        $size=@filesize($tmp);
        if ($size!==false && $size <= $maxBytes) break;
        $q-=7;
        if ($q<55){
            $downs++;
            $tW=(int)floor($tW*0.9); $tH=(int)floor($tH*0.9);
            if ($tW<80 || $tH<80) break;
            $tmp2=imagecreatetruecolor($tW,$tH);
            $white=imagecolorallocate($tmp2,255,255,255);
            imagefilledrectangle($tmp2,0,0,$tW,$tH,$white);
            imagecopyresampled($tmp2,$work,0,0,0,0,$tW,$tH,imagesx($work),imagesy($work));
            imagedestroy($work);
            $work=$tmp2; $q=80;
        }
    }while($downs<6);
    imagedestroy($work);

    $relJpg=_bp_change_ext(_bp_abs_to_rel($abs),'jpg');
    $absJpg=_bp_rel_to_abs($relJpg);
    @mkdir(dirname($absJpg),0777,true);
    @rename($tmp,$absJpg);
    if (stripos($mime,'jpeg')===false && stripos($mime,'jpg')===false){ @unlink($abs); }
    return $relJpg;
}
function upload_image_max1mb(string $field, ?string $oldPfad, int $userId, string $slug, int $maxW, int $maxH, string $PREFIX) : ?string {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $path = handle_upload($field, $oldPfad, $userId, ($slug ?: 'user'), ['image/jpeg','image/png','image/gif'], 20_000_000, $maxW, $maxH);
    if (!$path) return null;
    $newRel = shrink_image_under_limit($path, $maxW, $maxH, 1_000_000, $PREFIX);
    return (strpos($newRel, $PREFIX) === 0) ? $newRel : (rtrim($PREFIX,'/') . '/' . ltrim($newRel,'/'));
}

/* ===== Einladungs-Helfer ===== */
function app_base_url(string $PREFIX): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme.'://'.$host . rtrim($PREFIX, '/');
}
function invite_create(mysqli $db, int $userId, int $days = 7): array {
  $token   = bin2hex(random_bytes(32));
  $expires = (new DateTime("+{$days} days"))->format('Y-m-d H:i:s');
  $st = $db->prepare("INSERT INTO user_invites (user_id, token, expires_at) VALUES (?,?,?)");
  $st->bind_param("iss", $userId, $token, $expires);
  $st->execute(); $st->close();
  return ['token'=>$token, 'expires_at'=>$expires];
}
function invite_url(string $PREFIX, string $token): string {
  return app_base_url($PREFIX) . '/pages/accept_invite.php?token=' . urlencode($token);
}
function send_invite_email(array $user, string $inviteUrl): bool {
  $to   = $user['email'] ?? '';
  $name = trim($user['name'] ?? '') ?: 'Kollegin/Kollege';
  $html = "<p>Hallo ".h($name).",</p>
           <p>du wurdest zu pendenz.com eingeladen.</p>
           <p><a href=\"".h($inviteUrl)."\">Hier klicken, um Passwort zu setzen</a>.</p>
           <p>Falls der Link nicht funktioniert: ".h($inviteUrl)."</p>";

  return send_mail_html(
    $to,
    'Deine Einladung zu pendenz.com',
    $html,
    [
      'from_email' => (defined('SMTP_FROM') ? SMTP_FROM : 'no-reply@pendenz.com'),
      'from_name'  => (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'pendenz.com'),
      'x_category' => 'invite',
    ]
  );
}
function invite_send_for_user(mysqli $db, int $userId, string $PREFIX): array {
  $st=$db->prepare("SELECT id,name,email FROM benutzer WHERE id=?");
  $st->bind_param("i",$userId);
  $st->execute();
  $u=$st->get_result()->fetch_assoc();
  $st->close();
  if(!$u) throw new Exception("Benutzer nicht gefunden.");

  $inv = invite_create($db, $userId, 7);
  $url = invite_url($PREFIX, $inv['token']);

  // Timestamp setzen (Einladung erzeugt)
  $db->query("UPDATE benutzer SET eingeladen_am=NOW() WHERE id=".(int)$userId);

  // Versand versuchen
  $sent = send_invite_email($u, $url);

  return ['url' => $url, 'sent' => (bool)$sent];
}

/* ===== Anzeige-URLs (keine Platzhalter) ===== */
$profilURL = sanitize_image_url($user['profilbild'] ?? null, $PREFIX);
$logoURL   = sanitize_image_url($user['firmenlogo'] ?? null, $PREFIX);
$titelURL  = sanitize_image_url($user['titelbild']  ?? null, $PREFIX);

$tbMaxH    = (int)($profileVis['titelbild_max_height'] ?? 260);
$pbMaxW    = (int)($profileVis['profilbild_max_size'] ?? 160);
$lgMaxW    = (int)($profileVis['firmenlogo_max_size'] ?? 160);

/* Helper */
function audience_can_see($fieldLevel, $audience){
  switch ($audience) {
    case 'internal': return in_array($fieldLevel, ['internal','public'], true);
    case 'project':  return in_array($fieldLevel, ['project','public'], true);
    case 'public':   return $fieldLevel === 'public';
    default:         return true; // self
  }
}

/* === Vorschau-Renderer (Projekt / Öffentlich) === */
function render_profile_preview_block(array $user, array $profileVis, array $FIELDS, string $aud, ?string $profilURL, ?string $logoURL, ?string $titelURL, int $tbMaxH, int $pbMaxW, int $lgMaxW){
    $isSelf = ($aud === 'private');
    $showImg = function(string $visKey) use ($profileVis, $aud, $isSelf) {
        if ($isSelf) return true;
        $level = $profileVis[$visKey] ?? 'private';
        return audience_can_see($level, $aud);
    };
    ?>
    <div>
      <?php if ($titelURL && $showImg('vis_titelbild')): ?>
        <div class="top-title">
          <img src="<?= h($titelURL) ?>" alt="Titelbild" style="width:100%;max-height:<?= (int)$tbMaxH ?>px;object-fit:cover;">
        </div>
      <?php endif; ?>

      <div class="summary">
        <div class="left">
          <?php if ($profilURL && $showImg('vis_profilbild')): ?>
            <img src="<?= h($profilURL) ?>" alt="Profilbild" style="max-width:<?= (int)$pbMaxW ?>px;border-radius:50%;">
          <?php endif; ?>
        </div>

        <div>
          <?php foreach ($FIELDS as $k=>$label):
            $val = trim((string)($user[$k] ?? ''));
            if ($val==='' && $k!=='name') continue;
            if (!$isSelf && !audience_can_see($profileVis[$k] ?? 'private', $aud)) continue; ?>
            <div class="kv">
              <div class="key"><strong><?= h($label) ?></strong></div>
              <div class="val"><?= $val!=='' ? nl2br(h($val)) : '—' ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="right">
          <?php if ($logoURL && $showImg('vis_firmenlogo')): ?>
            <img src="<?= h($logoURL) ?>" alt="Firmenlogo" style="max-width:<?= (int)$lgMaxW ?>px;">
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
}

/* ===== Speichern / Anlegen / Löschen ===== */
$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    benutzer_csrf_validate_or_throw($_POST['csrf_benutzer'] ?? null);
    $action = $_POST['action'] ?? '';

    /* === Einladung senden === */
    if ($action === 'invite_user') {
      if (!$canViewOthers) throw new Exception("Keine Berechtigung, Einladungen zu versenden.");
      $uid = (int)($_POST['id'] ?? 0);
      if ($uid <= 0) throw new Exception("Ungültige Benutzer-ID.");

      $res = invite_send_for_user($mysqli, $uid, $PREFIX);
      $url  = (string)($res['url']  ?? '');
      $sent = (bool)  ($res['sent'] ?? false);

      $flash = $sent
        ? "✉️ Einladung per E-Mail gesendet. Link: ".h($url)
        : "⚠️ Konnte keine E-Mail versenden. Link: ".h($url)." (siehe logs/mailer.log oder logs/emails/)";
    }

    /* === Ordner Sync === */
    elseif ($action === 'sync_folder') {
      $uid = (int)($_POST['id'] ?? 0);
      $res = user_folder_sync($mysqli, $uid);
      $flash = ($res['ok'] ? "✅ " : "❌ ") . $res['msg'];
    }

    /* === Benutzer löschen === */
    elseif ($action === 'delete_user') {
      if (!$canViewOthers) throw new Exception("Keine Berechtigung, Benutzer zu löschen.");
      $delId = (int)($_POST['id'] ?? 0);
      if ($delId <= 0) throw new Exception("Ungültige ID.");
      if ($delId === $sid) throw new Exception("Du kannst dich nicht selbst löschen.");

      // Hierarchie-Schutz
      $target = $mysqli->query("SELECT rolle FROM benutzer WHERE id=$delId")->fetch_assoc();
      if (($target['rolle'] ?? '') === 'superadmin' && $currentRole !== 'superadmin') {
          throw new Exception("⚠️ Zugriff verweigert: Ein Superadmin kann nicht gelöscht werden.");
      }

      $st = $mysqli->prepare("SELECT profilbild,firmenlogo,titelbild FROM benutzer WHERE id=?");
      $st->bind_param("i", $delId);
      $st->execute();
      $delRow = $st->get_result()->fetch_assoc();
      $st->close();

      $st = $mysqli->prepare("DELETE FROM benutzer WHERE id=?");
      $st->bind_param("i", $delId);
      $st->execute();
      $ok = ($st->affected_rows > 0);
      $st->close();

      if (!$ok) throw new Exception("Löschen fehlgeschlagen (evtl. referenzierte Daten).");

      foreach (['profilbild','firmenlogo','titelbild'] as $k) remove_local_upload($delRow[$k] ?? null, $PREFIX);

      $flash = "🗑️ Benutzer #$delId gelöscht.";
    }

    /* === Benutzer sperren / entsperren === */
    elseif ($action === 'toggle_block') {
      if (!$canViewOthers) throw new Exception("Keine Berechtigung.");
      $bId = (int)($_POST['id'] ?? 0);
      if ($bId <= 0) throw new Exception("Ungültige ID.");
      if ($bId === $sid) throw new Exception("Du kannst dich nicht selbst sperren.");

      // Hierarchie-Schutz
      $target = $mysqli->query("SELECT rolle FROM benutzer WHERE id=$bId")->fetch_assoc();
      $tRole = $target['rolle'] ?? 'benutzer';
      
      if ($tRole === 'superadmin' && $currentRole !== 'superadmin') {
          throw new Exception("⚠️ Zugriff verweigert: Ein Superadmin kann nicht gesperrt werden.");
      }
      if ($tRole === 'admin' && $currentRole !== 'superadmin') {
          throw new Exception("⚠️ Zugriff verweigert: Nur ein Superadmin kann Admins sperren.");
      }

      $mysqli->query("UPDATE benutzer SET is_blocked = 1 - is_blocked WHERE id = $bId");
      $flash = "🔐 Status für Benutzer #$bId aktualisiert.";
    }

    /* === Benutzer anlegen === */
    elseif ($action === 'create_user') {
      if (!$canViewOthers) throw new Exception("Keine Berechtigung, Benutzer anzulegen.");

      $new_name  = trim($_POST['new_name']  ?? '');
      $new_email = trim($_POST['new_email'] ?? '');
      $new_role  = trim($_POST['new_role']  ?? 'benutzer');
      if ($new_name === '' || $new_email === '') throw new Exception("Name und E-Mail sind Pflichtfelder.");

      $allowedRoles = ['benutzer','admin','gast'];
      if ($currentRole === 'superadmin') $allowedRoles[] = 'superadmin';
      if (!in_array($new_role, $allowedRoles, true)) $new_role = 'benutzer';

      $st = $mysqli->prepare("SELECT id FROM benutzer WHERE email=? LIMIT 1");
      $st->bind_param("s", $new_email);
      $st->execute(); $exists = $st->get_result()->fetch_assoc(); $st->close();
      if ($exists) throw new Exception("Diese E-Mail ist bereits vergeben.");

      $new_telefonnummer = trim($_POST['new_telefonnummer'] ?? '');
      $new_adresse       = trim($_POST['new_adresse'] ?? '');
      $new_geburtsdatum  = $_POST['new_geburtsdatum'] ?? null;
      $new_heimatland    = trim($_POST['new_heimatland'] ?? '');
      $new_position      = trim($_POST['new_position'] ?? '');
      $new_beruf         = trim($_POST['new_beruf'] ?? '');

      $new_person_type_id   = isset($_POST['new_person_type_id'])   ? (int)$_POST['new_person_type_id']   : null;
      $new_person_status_id = isset($_POST['new_person_status_id']) ? (int)$_POST['new_person_status_id'] : null;

      $selFirmaId = (int)($_POST['firma_select_id_new'] ?? 0);
      $f  = $selFirmaId ? firmen_by_id($mysqli, $selFirmaId) : null;
      $mf = [
        'name'    => trim($_POST['new_firma_name']    ?? ''),
        'adresse' => trim($_POST['new_firma_adresse'] ?? ''),
        'telefon' => trim($_POST['new_firma_telefon'] ?? ''),
        'email'   => trim($_POST['new_firma_email']   ?? ''),
        'website' => trim($_POST['new_firma_website'] ?? ''),
        'logo'    => trim($_POST['new_firma_logo']    ?? ''),
      ];
      $firma_name    = $mf['name']    !== '' ? $mf['name']    : ($f['name']    ?? null);
      $firma_adresse = $mf['adresse'] !== '' ? $mf['adresse'] : ($f['adresse'] ?? null);
      $firma_telefon = $mf['telefon'] !== '' ? $mf['telefon'] : ($f['telefon'] ?? null);
      $firma_email   = $mf['email']   !== '' ? $mf['email']   : ($f['email']   ?? null);
      $firma_website = $mf['website'] !== '' ? $mf['website'] : ($f['website'] ?? null);
      $firmenlogoUrl = $mf['logo']    !== '' ? $mf['logo']    : ($f['logo']    ?? null);

      $pb_w = (int)($_POST['new_profilbild_max_size_num'] ?? $_POST['new_profilbild_max_size'] ?? 160);
      $lg_w = (int)($_POST['new_firmenlogo_max_size_num'] ?? $_POST['new_firmenlogo_max_size'] ?? 160);
      $tb_h = (int)($_POST['new_titelbild_max_height_num'] ?? $_POST['new_titelbild_max_height'] ?? 260);
      $pb_w = max(80, min(400, $pb_w)); $lg_w = max(80, min(400, $lg_w)); $tb_h = max(100, min(800, $tb_h));

      $profileVisNew = [];
      foreach (array_keys($FIELDS) as $k) {
        $v = $_POST['new_vis_'.$k] ?? 'private';
        $profileVisNew[$k] = in_array($v, $LEVELS, true) ? $v : 'private';
      }
      foreach (['profilbild','firmenlogo','titelbild'] as $imgK) {
        $v = $_POST['new_vis_'.$imgK] ?? 'private';
        $profileVisNew['vis_'.$imgK] = in_array($v, $LEVELS, true) ? $v : 'private';
      }
      $profileVisNew['profilbild_max_size']   = $pb_w;
      $profileVisNew['firmenlogo_max_size']   = $lg_w;
      $profileVisNew['titelbild_max_height']  = $tb_h;
      $visJson = json_encode($profileVisNew, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // Dynamisches INSERT inkl. optionaler Typ/Status
      $cols = [
        'name','email','rolle',
        'telefonnummer','adresse',
        'firma_name','firma_adresse','firma_telefon','firma_email','firma_website',
        'geburtsdatum','heimatland','position','beruf',
        'profile_vis'
      ];
      $types = 'sssssssssssssss'; // 15 Strings
      $vals  = [
        $new_name,$new_email,$new_role,
        $new_telefonnummer,$new_adresse,
        $firma_name,$firma_adresse,$firma_telefon,$firma_email,$firma_website,
        $new_geburtsdatum,$new_heimatland,$new_position,$new_beruf,
        $visJson
      ];

      $assignment = resolve_assignment_ids(
          $mysqli,
          (int)($_POST['new_projekt_id'] ?? 0),
          (int)($_POST['new_objekt_id'] ?? 0),
          (int)($_POST['new_wohnung_id'] ?? 0)
      );

      if (in_array('mieter_phase',$benutzerCols,true)) {
          $cols[] = 'mieter_phase'; $types .= 's'; $vals[] = normalize_mieter_phase($_POST['new_mieter_phase'] ?? 'interessent');
      }
      if (in_array('wohnung_id',$benutzerCols,true)) {
          $cols[] = 'wohnung_id'; $types .= 'i'; $vals[] = $assignment['wohnung_id'] ?: null;
      }
      if (in_array('kontaktweg',$benutzerCols,true)) {
          $cols[] = 'kontaktweg'; $types .= 's'; $vals[] = trim($_POST['new_kontaktweg'] ?? 'email');
      }

      if (in_array('person_type_id',$benutzerCols,true)) {
        $cols[] = 'person_type_id'; $types .= 'i'; $vals[] = $new_person_type_id ?: null;
      }
      if (in_array('person_status_id',$benutzerCols,true)) {
        $cols[] = 'person_status_id'; $types .= 'i'; $vals[] = $new_person_status_id ?: null;
      }

      if (in_array('vorgangsart_id', table_has_columns($mysqli, 'benutzer', ['vorgangsart_id']), true)) {
        $cols[] = 'vorgangsart_id'; $types .= 'i'; $vals[] = (int)($_POST['new_vorgangsart_id'] ?? 0) ?: null;
      }

      if (in_array('projekt_id',$benutzerCols,true)) {
        $cols[] = 'projekt_id'; $types .= 'i'; $vals[] = $assignment['projekt_id'] ?: null;
      }
      if (in_array('business_type', $benutzerCols, true)) {
          $cols[] = 'business_type'; $types .= 's'; $vals[] = trim($_POST['new_business_type'] ?? 'standard');
      }

      if (in_array('objekt_id',$benutzerCols,true)) {
        $cols[] = 'objekt_id'; $types .= 'i'; $vals[] = $assignment['objekt_id'] ?: null;
      }

      $cols[]='profile_updated_at'; $types.='s'; $vals[] = date('Y-m-d H:i:s');

      $place = implode(',', array_fill(0,count($cols),'?'));
      $sql = "INSERT INTO benutzer (".implode(',',$cols).") VALUES ($place)";
      $stI = $mysqli->prepare($sql);
      $stI->bind_param($types, ...$vals);
      $stI->execute();
      $newId = (int)$mysqli->insert_id;
      $stI->close();

      // Unternehmer-Projekt-Zuweisung & BKP (für Pendenzen-Automatik)
      // Uploads ≤1MB
      $profilbild      = upload_image_max1mb('new_profilbild', null, $newId, ($new_name ?: 'user'), 800, 800, $PREFIX);
      $firmenlogoLocal = upload_image_max1mb('new_firmenlogo', null, $newId, ($new_name ?: 'user'), 800, 800, $PREFIX);
      $titelbild       = upload_image_max1mb('new_titelbild',  null, $newId, ($new_name ?: 'user'), 2400, 1200, $PREFIX);

      $firmenlogo = $firmenlogoLocal ?? ($firmenlogoUrl ? sanitize_image_url($firmenlogoUrl, $PREFIX) : null);

      $up = $mysqli->prepare("UPDATE benutzer SET profilbild=?, firmenlogo=?, titelbild=? WHERE id=?");
      $up->bind_param("sssi", $profilbild, $firmenlogo, $titelbild, $newId);
      $up->execute(); $up->close();

      // Automatischer Ordner-Sync
      user_folder_sync($mysqli, $newId);

      // BKP & Projekt-Zuweisung (für Unternehmer)
      $target_pid = (int)$assignment['projekt_id'];
      $target_bkp = (int)($_POST['new_bkp_id'] ?? 0);
      if ($newId > 0 && $target_pid > 0) {
          $stUP = $mysqli->prepare("INSERT INTO unternehmer_projekte (benutzer_id, projekt_id, bkp_id) VALUES (?, ?, ?)");
          // bkp_id might be null
          $bkp_val = $target_bkp ?: null;
          $stUP->bind_param("iii", $newId, $target_pid, $bkp_val);
          $stUP->execute();
          $stUP->close();
      }


      // Optional: Firma in Stammdaten
      $saveTemplate = isset($_POST['save_firma_template']) && $_POST['save_firma_template'] === '1';
      if ($saveTemplate && !$selFirmaId && $firma_name) {
          firmen_insert_dynamic($mysqli, [
              'name'    => $firma_name,
              'adresse' => $firma_adresse,
              'telefon' => $firma_telefon,
              'email'   => $firma_email,
              'website' => $firma_website,
              'logo'    => $firmenlogo,
          ]);
      }

      // Optional: gleich Einladung senden
      $sendNow = isset($_POST['send_invite_now']) && $_POST['send_invite_now'] === '1';
      $flash = "✅ Benutzer angelegt.";
      if ($sendNow) {
        try {
          invite_send_for_user($mysqli, $newId, $PREFIX);
          $flash .= " · ✉️ Einladung gesendet.";
        } catch (Throwable $ie) {
          $flash .= " · ⚠️ Einladung fehlgeschlagen: ".$ie->getMessage();
        }
      }

      // Reload Daten für Ansicht
      $viewId = $newId;
      $stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
      $stmt->bind_param("i", $viewId);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      $profileVis = json_decode($user['profile_vis'] ?? "{}", true) ?: $profileVis;

      $profilURL = sanitize_image_url($user['profilbild'] ?? null, $PREFIX);
      $logoURL   = sanitize_image_url($user['firmenlogo'] ?? null, $PREFIX);
      $titelURL  = sanitize_image_url($user['titelbild']  ?? null, $PREFIX);
    }

    /* === Profil-Update === */
    elseif ($action === 'save_profile') {
      $targetId = (int)($_POST['id'] ?? 0);
      if ($targetId !== $sid && !$canViewOthers) throw new Exception("Keine Berechtigung, andere Profile zu ändern.");

      $stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
      $stmt->bind_param("i",$targetId);
      $stmt->execute();
      $targetUser = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if (!$targetUser) throw new Exception("Ziel-Benutzer nicht gefunden.");

      // --- Rolle vorbereiten (nur Admin/Superadmin dürfen ändern)
      $finalRole = $targetUser['rolle'] ?? 'benutzer';
      if ($canViewOthers && isset($_POST['rolle'])) {
        $requestedRole = trim($_POST['rolle']);
        $allowed = ['gast','benutzer','admin'];
        if ($currentRole === 'superadmin') $allowed[] = 'superadmin';

        // Admin darf Superadmin weder setzen noch ändern
        $targetIsSuperadmin = ($targetUser['rolle'] ?? '') === 'superadmin';
        if ($targetIsSuperadmin && $currentRole !== 'superadmin') {
          // ignore request, keep finalRole as is
        } else {
          if (in_array($requestedRole, $allowed, true)) {
            $finalRole = $requestedRole;
          }
        }
      }

      // --- Felder/Visibility
      $data = [];
      foreach ($FIELDS as $key => $label) {
        $data[$key] = ($key === 'geburtsdatum') ? ($_POST[$key] ?: null) : trim($_POST[$key] ?? '');
        $visKey = 'vis_'.$key;
        if (isset($_POST[$visKey]) && in_array($_POST[$visKey], $LEVELS, true)) {
          $profileVis[$key] = $_POST[$visKey];
        }
      }

      // Typ/Status (nur speichern, wenn Spalten existieren)
      $upd_type_id   = isset($_POST['person_type_id'])   ? (int)$_POST['person_type_id']   : null;
      $upd_status_id = isset($_POST['person_status_id']) ? (int)$_POST['person_status_id'] : null;

      $firmaSelectId = (int)($_POST['firma_select_id'] ?? 0);
      $f = null;
      if ($firmaSelectId > 0) {
        $f = firmen_by_id($mysqli, $firmaSelectId);
        if ($f) {
          if (($data['firma_name']     ?? '') === '') $data['firma_name']    = $f['name']    ?? '';
          if (($data['firma_adresse']  ?? '') === '') $data['firma_adresse'] = $f['adresse'] ?? '';
          if (($data['firma_telefon']  ?? '') === '') $data['firma_telefon'] = $f['telefon'] ?? '';
          if (($data['firma_email']    ?? '') === '') $data['firma_email']   = $f['email']   ?? '';
          if (($data['firma_website']  ?? '') === '') $data['firma_website'] = $f['website'] ?? '';
        }
      }

      $oldP = $targetUser['profilbild'] ?? null;
      $oldL = $targetUser['firmenlogo'] ?? null;
      $oldT = $targetUser['titelbild']  ?? null;

      // Delete-Flags
      $delP = !empty($_POST['profilbild_delete']);
      $delL = !empty($_POST['firmenlogo_delete']);
      $delT = !empty($_POST['titelbild_delete']);

      // Uploads neu (≤1MB)
      $newP = upload_image_max1mb('profilbild', $oldP, $targetId, ($data['name'] ?: 'user'), 800, 800, $PREFIX);
      $newL = upload_image_max1mb('firmenlogo', $oldL, $targetId, ($data['name'] ?: 'user'), 800, 800, $PREFIX);
      $newT = upload_image_max1mb('titelbild',  $oldT, $targetId, ($data['name'] ?: 'user'), 2400, 1200, $PREFIX);

      $profilbild = $newP ?? $oldP;
      $firmenlogo = $newL ?? $oldL;
      if (!$firmenlogo && !empty($f) && !empty($f['logo'])) $firmenlogo = sanitize_image_url($f['logo'], $PREFIX);
      $titelbild  = $newT ?? $oldT;

      // Löschen nur, wenn KEIN neues hochgeladen wurde
      if ($delP && !$newP) { remove_local_upload($oldP, $PREFIX); $profilbild = null; }
      if ($delL && !$newL) { remove_local_upload($oldL, $PREFIX); $firmenlogo = null; }
      if ($delT && !$newT) { remove_local_upload($oldT, $PREFIX); $titelbild  = null; }

      foreach (['profilbild','firmenlogo','titelbild'] as $k) {
        $v = $_POST['vis_'.$k] ?? 'private';
        $profileVis['vis_'.$k] = in_array($v, $LEVELS, true) ? $v : 'private';
      }

      $tb_h = (int)($_POST['titelbild_max_height_num'] ?? $_POST['titelbild_max_height'] ?? $profileVis['titelbild_max_height']);
      $tb_h = max(100, min(800, $tb_h)); $profileVis['titelbild_max_height'] = $tb_h;
      $pb_w = (int)($_POST['profilbild_max_size_num'] ?? $_POST['profilbild_max_size'] ?? $profileVis['profilbild_max_size']);
      $pb_w = max(80, min(400, $pb_w));  $profileVis['profilbild_max_size'] = $pb_w;
      $lg_w = (int)($_POST['firmenlogo_max_size_num'] ?? $_POST['firmenlogo_max_size'] ?? $profileVis['firmenlogo_max_size']);
      $lg_w = max(80, min(400, $lg_w));  $profileVis['firmenlogo_max_size'] = $lg_w;

      if (($data['name'] ?? '') === "" || ($data['email'] ?? '') === "") {
        throw new Exception("Name und E-Mail sind Pflichtfelder.");
      }

      $visJson = json_encode($profileVis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // Dynamisches UPDATE: jetzt MIT rolle=?, plus optional Typ/Status
      $sql = "
        UPDATE benutzer SET
          name=?, email=?, rolle=?,
          telefonnummer=?, adresse=?,
          firma_name=?, firma_adresse=?, firma_telefon=?, firma_email=?, firma_website=?,
          geburtsdatum=?, heimatland=?, position=?, beruf=?,
          mieter_phase=?, kontaktweg=?,
          profilbild=?, firmenlogo=?, titelbild=?,
          profile_vis=?, profile_updated_at=NOW()";
      $types = 'ssssssssssssssssssss'; // 20 items
      $vals = [
        $data['name'], $data['email'], $finalRole,
        $data['telefonnummer'], $data['adresse'],
        $data['firma_name'], $data['firma_adresse'], $data['firma_telefon'], $data['firma_email'], $data['firma_website'],
        $data['geburtsdatum'], $data['heimatland'], $data['position'], $data['beruf'],
        normalize_mieter_phase($_POST['mieter_phase'] ?? 'interessent'),
        trim($_POST['kontaktweg'] ?? 'email'),
        $profilbild, $firmenlogo, $titelbild,
        $visJson
      ];

      if (in_array('person_type_id',$benutzerCols,true)) {
        $sql .= ", person_type_id=?";
        $types .= 'i';
        $vals[] = $upd_type_id ?: null;
      }
      if (in_array('person_status_id',$benutzerCols,true)) {
        $sql .= ", person_status_id=?";
        $types .= 'i';
        $vals[] = $upd_status_id ?: null;
      }
      
      if (in_array('vorgangsart_id', $benutzerCols, true)) {
        $sql .= ", vorgangsart_id=?";
        $types .= 'i';
        $vals[] = (int)($_POST['vorgangsart_id'] ?? 0) ?: null;
      }

      $assignment = resolve_assignment_ids(
        $mysqli,
        (int)($_POST['projekt_id'] ?? 0),
        (int)($_POST['objekt_id'] ?? 0),
        (int)($_POST['wohnung_id'] ?? 0)
      );

      if (in_array('projekt_id',$benutzerCols,true)) {
        $sql .= ", projekt_id=?"; $types .= 'i'; $vals[] = $assignment['projekt_id'] ?: null;
      }
      if (in_array('objekt_id',$benutzerCols,true)) {
        $sql .= ", objekt_id=?"; $types .= 'i'; $vals[] = $assignment['objekt_id'] ?: null;
      }
      if (in_array('wohnung_id',$benutzerCols,true)) {
        $sql .= ", wohnung_id=?"; $types .= 'i'; $vals[] = $assignment['wohnung_id'] ?: null;
      }

      $sql .= " WHERE id=?";
      $types .= 'i';
      $vals[] = $targetId;

      $stmt = $mysqli->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();

      // Automatischer Ordner-Sync
      user_folder_sync($mysqli, $targetId);

      $flash = "✅ Profil gespeichert.";

      // Reload
      $viewId = $targetId;
      $stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
      $stmt->bind_param("i", $viewId);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      $profileVis = json_decode($user['profile_vis'] ?? "{}", true) ?: $profileVis;
      $profilURL = sanitize_image_url($user['profilbild'] ?? null, $PREFIX);
      $logoURL   = sanitize_image_url($user['firmenlogo'] ?? null, $PREFIX);
      $titelURL  = sanitize_image_url($user['titelbild']  ?? null, $PREFIX);
    }

  } catch (Throwable $e) {
    $flash = "❌ " . h($e->getMessage());
  }
}
?>
<style>
/* Accordion & Premium UI */
.accordion-section { margin-bottom: 20px; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0; background: #fff; }
.accordion-header { 
    padding: 16px 20px; background: #f8fafc; cursor: pointer; display:flex; justify-content: space-between; align-items: center;
    font-weight: 700; color: #1e293b; user-select: none; transition: background 0.2s;
}
.accordion-header:hover { background: #f1f5f9; }
.accordion-content { padding: 0; display: none; border-top: 1px solid #e2e8f0; }
.accordion-section.active .accordion-content { display: block; }
.accordion-section.active .accordion-header { background: #eff6ff; color: #2563eb; }
.accordion-header .icon { transition: transform 0.3s; }
.accordion-section.active .accordion-header .icon { transform: rotate(180deg); }

.user-count-pill { 
    background: #e2e8f0; color: #475569; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 800;
}
.accordion-section.active .user-count-pill { background: #2563eb; color: #fff; }

.profile-action-bar { display: flex; gap: 10px; margin-bottom: 20px; }
.btn-premium {
    padding: 12px 24px; border-radius: 12px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: white; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    text-decoration: none; transition: transform 0.2s;
}
.btn-premium:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3); color: #fff; }

/* Table overrides */
.table th { background: #f8fafc; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
.table tr:hover { background: #f1f5f9; }

#my-profile-card {
    border: 2px solid #2563eb; background: linear-gradient(to right, #ffffff, #f0f7ff);
}

/* Modern Form UI */
.modern-form-section { 
    background:#ffffff; 
    border:1px solid #e2e8f0; 
    border-radius:16px; 
    padding:24px; 
    margin-bottom:28px; 
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}
.modern-form-title { 
    font-size:18px; 
    font-weight:800; 
    color:#1e293b; 
    margin-bottom:20px; 
    display:flex; 
    align-items:center; 
    gap:10px; 
    border-bottom:2px solid #f1f5f9; 
    padding-bottom:14px; 
}
.modern-grid { 
    display:grid; 
    grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); 
    gap:24px; 
}
.modern-field { 
    display:flex; 
    flex-direction:column; 
    gap:8px; 
}
.modern-field label { 
    font-size:14px; 
    font-weight:700; 
    color:#334155; 
}
.modern-input-group { 
    display:flex; 
    gap:10px; 
    align-items:center; 
}
.modern-input-group input[type="text"], 
.modern-input-group input[type="email"], 
.modern-input-group input[type="url"], 
.modern-input-group input[type="date"], 
.modern-input-group select { 
    flex:1; 
    padding:12px 16px; 
    border:1px solid #cbd5e1; 
    border-radius:10px; 
    font-size:15px; 
    background:#fff; 
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
    width:100%; 
    box-sizing:border-box; 
    color: #1e293b;
}
.modern-input-group input:focus, 
.modern-input-group select:focus { 
    border-color:#3b82f6; 
    box-shadow:0 0 0 4px rgba(59,130,246,0.1); 
    outline:none; 
}
.icon-toggle-new { 
    background:#f8fafc; 
    border:1px solid #e2e8f0; 
    cursor:pointer; 
    border-radius:10px; 
    width:44px; 
    height:44px; 
    display:flex; 
    align-items:center; 
    justify-content:center; 
    transition:all 0.2s; 
    flex-shrink:0; 
    font-size: 18px;
}
.icon-toggle-new:hover { 
    background:#f1f5f9; 
    border-color: #cbd5e1;
}

/* Specific select styling */
select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2364748b'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 16px;
    padding-right: 40px !important;
}

.modern-field.required label::after {
    content: " *";
    color: #ef4444;
}
</style>

<div class="container" id="profile-root" data-prefix="<?= h($PREFIX) ?>">

  <header class="hero">
    <div class="hero-left">
      <h1 class="hero-title" style="color: #0f172a;">👥 Partner & Benutzer-Verwaltung</h1>
      <div class="hero-sub" style="color: #475569;">Übersicht und Verwaltung aller Kontakte und System-User.</div>
    </div>
    <div class="hero-right">
      <a href="<?= h($PREFIX) ?>pages/profil.php" class="btn-premium">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
        Mein Profil
      </a>
      <a href="<?= h($PREFIX) ?>pages/personen_taxonomie.php" class="btn-outline btn-small">Taxonomie</a>
      <a href="<?= h($PREFIX) ?>pages/firmen.php" class="btn-outline btn-small">Firmen</a>
      <?php if (in_array($currentRole, ['admin','superadmin'], true)): ?>
        <button class="btn btn-teal btn-small" onclick="document.getElementById('create-user-section').scrollIntoView({behavior:'smooth'})">➕ Neu</button>
      <?php endif; ?>
    </div>
  </header>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= $flash ?></div>
  <?php endif; ?>

  <?php if ($canViewOthers): ?>
  <div class="card" id="create-user-section">
    <h2 style="font-size:24px; font-weight:800; color:#0f172a; margin-bottom:20px;">✨ Neuen Benutzer anlegen</h2>
    <form method="post" autocomplete="on" enctype="multipart/form-data" id="create-user-form">
      <?= benutzer_csrf_input() ?>
      <input type="hidden" name="action" value="create_user">

      <div class="modern-form-section">
        <div class="modern-form-title">👤 Grunddaten</div>
        <div class="modern-grid">
          <div class="modern-field">
            <label>Name*</label>
            <div class="modern-input-group">
              <input type="text" id="new_name" name="new_name" required>
              <?php $lvl='private'; ?>
              <button type="button" class="icon-toggle-new" data-for="new_name" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
            </div>
            <input type="hidden" name="new_vis_name" id="new_vis_name" value="private">
          </div>
          <div class="modern-field">
            <label>E-Mail*</label>
            <div class="modern-input-group">
              <input type="email" id="new_email" name="new_email" required>
              <button type="button" class="icon-toggle-new" data-for="new_email" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
            </div>
            <input type="hidden" name="new_vis_email" id="new_vis_email" value="private">
          </div>
          <div class="modern-field">
            <label>User-Typ*</label>
            <div class="modern-input-group">
              <select id="new_business_type" name="new_business_type" onchange="handleUserTypeChange(this.value)" required>
                <option value="standard" selected>🏢 Team / Mitarbeiter</option>
                <option value="handwerker">🏗️ Partner / Unternehmer</option>
                <option value="mieter">🔑 Kunde / Mieter</option>
              </select>
            </div>
          </div>
          <div class="modern-field" id="role-field-wrap">
            <label>Berechtigungs-Rolle</label>
            <div class="modern-input-group">
              <select id="new_role" name="new_role">
                <option value="benutzer" selected>benutzer</option>
                <option value="admin">admin</option>
                <option value="gast">gast</option>
                <?php if ($currentRole === 'superadmin'): ?>
                  <option value="superadmin">superadmin</option>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label d-flex align-items-center">
                <span>📑 Standard-Vorgangsart (Typ)</span>
              </label>
              <select name="vorgangsart_id" class="form-select border-radius-10">
                <option value="">— Keine (Standard) —</option>
                <?php foreach ($vorgangsarten as $va): ?>
                  <option value="<?= (int)$va['id'] ?>" <?= (int)($user['vorgangsart_id'] ?? 0) === (int)$va['id'] ? 'selected' : '' ?>>
                    <?= h($va['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Standard-Typ für neue Pendenzen dieses Benutzers</div>
            </div>
          </div>
        </div>
      </div>

      <div class="modern-form-section">
        <div class="modern-form-title">🏢 Zuordnung & Status</div>
        <div class="modern-grid">
          
          <div class="modern-field">
            <label>Projekt</label>
            <div class="modern-input-group">
              <select id="new_projekt_id" name="new_projekt_id">
                <option value="">— Kein Projekt —</option>
                <?php foreach($projects as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= ($p['id'] == $preP ? 'selected':'') ?>><?= h($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="modern-field">
            <label>Haus / Objekt</label>
            <div class="modern-input-group">
              <select id="new_objekt_id" name="new_objekt_id" disabled>
                <option value="">— Erst Projekt wählen —</option>
              </select>
            </div>
            <input type="hidden" id="new_objekt_id_val" value="<?= $preO ?>">
          </div>

          <div class="modern-field">
            <label>Wohnung / Einheit</label>
            <div class="modern-input-group">
              <select id="new_wohnung_id" name="new_wohnung_id" disabled>
                <option value="">— Erst Haus wählen —</option>
              </select>
            </div>
            <input type="hidden" id="new_wohnung_id_val" value="<?= $preW ?>">
          </div>

          <div class="modern-field">
            <label>📑 Standard-Vorgangsart (Typ)</label>
            <div class="modern-input-group">
              <select name="new_vorgangsart_id" id="new_vorgangsart_id">
                <option value="">— Geerbt vom Projekt —</option>
                <?php foreach ($vorgangsarten as $va): ?>
                  <option value="<?= (int)$va['id'] ?>"><?= h($va['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:4px;">Standard-Typ für Pendenzen, die dieser Benutzer erstellt</div>
          </div>

          <div class="modern-field" id="wrap_mieter_phase" style="display:none;">
            <label>Mieter-Phase</label>
            <div class="modern-input-group">
              <select id="new_mieter_phase" name="new_mieter_phase">
                <?php $prePhase = $_GET['new_mieter_phase'] ?? 'interessent'; ?>
                <option value="interessent" <?= $prePhase==='interessent'?'selected':'' ?>>⭐ Interessent</option>
                <option value="mieter"      <?= $prePhase==='mieter'?'selected':'' ?>>🔑 Mieter</option>
                <option value="vormieter"   <?= $prePhase==='vormieter'?'selected':'' ?>>📁 Vormieter</option>
                <option value="gekündigt"   <?= $prePhase==='gekündigt'?'selected':'' ?>>🚫 Gekündigt</option>
              </select>
            </div>
          </div>

          <div class="modern-field">
            <label>Bevorz. Kontaktweg</label>
            <div class="modern-input-group">
              <select id="new_kontaktweg" name="new_kontaktweg">
                <option value="email" selected>📧 E-Mail</option>
                <option value="telefon">📞 Telefon</option>
                <option value="whatsapp">💬 WhatsApp</option>
                <option value="sms">📱 SMS</option>
                <option value="post">📮 Postweg</option>
              </select>
            </div>
          </div>

          <?php if (!empty($types)): ?>
            <div class="modern-field" id="wrap_person_type_inner">
              <label>Personen/Sub-Typ</label>
              <div class="modern-input-group">
                <select id="new_person_type_id" name="new_person_type_id">
                  <option value="">— auswählen —</option>
                  <?php foreach ($types as $t): ?>
                    <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="modern-field" id="wrap_person_status_inner">
              <label>Status</label>
              <div class="modern-input-group">
                <select id="new_person_status_id" name="new_person_status_id" disabled>
                  <option value="">— zuerst Typ wählen —</option>
                </select>
              </div>
            </div>
          <?php endif; ?>

          <div class="modern-field" id="wrap_bkp_id" style="display:none;">
            <label>BKP – Hauptgewerk (Zuweisung)</label>
            <div class="modern-input-group">
              <select id="new_bkp_id_secondary" name="new_bkp_id_secondary">
                <option value="">— Kein BKP gewählt —</option>
                <?php foreach($bkp_codes_all as $bc): ?>
                  <option value="<?= (int)$bc['id'] ?>"><?= h($bc['code']) ?> – <?= h($bc['bezeichnung']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

        </div>
      </div>

      <div class="modern-form-section">
        <div class="modern-form-title">📞 Weitere Kontaktdaten & Info</div>
        <div class="modern-grid">
          <?php
            $createFields = [
              ['new_telefonnummer','Telefon'],
              ['new_adresse','Adresse'],
              ['new_geburtsdatum','Geburtstag','date'],
              ['new_heimatland','Heimatland'],
              ['new_position','Position'],
              ['new_beruf','Beruf']
            ];
            foreach ($createFields as $cf) {
              [$id,$lbl,$typ] = [$cf[0],$cf[1],$cf[2] ?? 'text'];
              $base = str_replace('new_','',$id);
              $lvl='private';
          ?>
            <div class="modern-field">
              <label><?= h($lbl) ?></label>
              <div class="modern-input-group">
                <input type="<?= h($typ) ?>" id="<?= h($id) ?>" name="<?= h($id) ?>">
                <button type="button" class="icon-toggle-new" data-for="<?= h($id) ?>" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
              </div>
              <input type="hidden" name="new_vis_<?= h($base) ?>" id="new_vis_<?= h($base) ?>" value="private">
            </div>
          <?php } ?>
        </div>
      </div>

      <div class="modern-form-section">
        <div class="modern-form-title">🏢 Firmendaten</div>
        <?php if (!empty($firmen)): ?>
          <div class="modern-field" style="margin-bottom:16px;">
            <label>Firma (aus Stammdaten)</label>
            <div class="modern-input-group">
              <select id="firma_select_id_new" name="firma_select_id_new">
                <option value="">— auswählen —</option>
                <?php foreach ($firmen as $f): ?>
                  <option value="<?= (int)$f['id'] ?>"><?= h($f['name'] ?: ('Firma #'.(int)$f['id'])) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endif; ?>

        <div class="modern-grid">
          <?php
            $createFieldsF = [
              ['new_firma_name','Firma – Name'],
              ['new_firma_adresse','Firma – Adresse'],
              ['new_firma_telefon','Firma – Telefon'],
              ['new_firma_email','Firma – E-Mail','email'],
              ['new_firma_website','Firma – Website','url']
            ];
            foreach ($createFieldsF as $cf) {
              [$id,$lbl,$typ] = [$cf[0],$cf[1],$cf[2] ?? 'text'];
              $base = str_replace('new_','',$id);
              $lvl='private';
          ?>
            <div class="modern-field">
              <label><?= h($lbl) ?></label>
              <div class="modern-input-group">
                <input type="<?= h($typ) ?>" id="<?= h($id) ?>" name="<?= h($id) ?>" placeholder="<?= h($lbl) ?>">
                <button type="button" class="icon-toggle-new" data-for="<?= h($id) ?>" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
              </div>
              <input type="hidden" name="new_vis_<?= h($base) ?>" id="new_vis_<?= h($base) ?>" value="private">
            </div>
          <?php } ?>
          <div class="modern-field">
            <label>Logo-URL</label>
            <div class="modern-input-group">
              <input type="url" id="new_firma_logo" name="new_firma_logo" placeholder="https://...">
            </div>
          </div>
        </div>
        
        <div style="margin-top:16px; padding:12px; border-left:4px solid #3b82f6; background:#eff6ff; border-radius:4px;">
          <label style="display:flex;align-items:center;gap:8px; cursor:pointer; margin:0;">
            <input type="checkbox" id="save_firma_template" name="save_firma_template" value="1">
            <span style="font-size:13px; font-weight:600; color:#1e40af;">Firma als Vorlage in Stammdaten speichern (nur wenn oben keine bestehende Firma ausgewählt ist)</span>
          </label>
        </div>
      </div>

      <div class="modern-form-section">
        <div class="modern-form-title">🖼️ Medien & Bilder</div>
        <div class="modern-grid">
          <!-- Profilbild -->
          <div class="modern-field">
            <label>Profilbild</label>
            <div class="modern-input-group">
              <div style="flex:1;">
                <input type="file" name="new_profilbild" accept="image/*" style="width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:6px; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:8px; font-size:12px; color:#64748b;">
                  Größe: <input type="number" id="new_profilbild_max_size_num" name="new_profilbild_max_size_num" min="80" max="400" step="5" value="160" style="width:60px; padding:2px 4px; border:1px solid #cbd5e1; border-radius:4px;"> px
                </div>
              </div>
              <?php $lvl='private'; ?>
              <button type="button" class="icon-toggle-new" data-for="new_profilbild" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
              <input type="hidden" name="new_vis_profilbild" id="new_vis_profilbild" value="private">
            </div>
          </div>

          <!-- Firmenlogo -->
          <div class="modern-field">
            <label>Firmenlogo</label>
            <div class="modern-input-group">
              <div style="flex:1;">
                <input type="file" name="new_firmenlogo" accept="image/*" style="width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:6px; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:8px; font-size:12px; color:#64748b;">
                  Größe: <input type="number" id="new_firmenlogo_max_size_num" name="new_firmenlogo_max_size_num" min="80" max="400" step="5" value="160" style="width:60px; padding:2px 4px; border:1px solid #cbd5e1; border-radius:4px;"> px
                </div>
              </div>
              <button type="button" class="icon-toggle-new" data-for="new_firmenlogo" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
              <input type="hidden" name="new_vis_firmenlogo" id="new_vis_firmenlogo" value="private">
            </div>
          </div>

          <!-- Titelbild -->
          <div class="modern-field">
            <label>Titelbild</label>
            <div class="modern-input-group">
              <div style="flex:1;">
                <input type="file" name="new_titelbild" accept="image/*" style="width:100%; border:1px solid #e2e8f0; border-radius:8px; padding:6px; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:8px; font-size:12px; color:#64748b;">
                  Höhe: <input type="number" id="new_titelbild_max_height_num" name="new_titelbild_max_height_num" min="100" max="800" step="10" value="260" style="width:60px; padding:2px 4px; border:1px solid #cbd5e1; border-radius:4px;"> px
                </div>
              </div>
              <button type="button" class="icon-toggle-new" data-for="new_titelbild" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
              <input type="hidden" name="new_vis_titelbild" id="new_vis_titelbild" value="private">
            </div>
          </div>
        </div>
        <div style="margin-top:12px; font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em;">Medien werden beim Upload automatisch auf ≤ 1 MB komprimiert.</div>
      </div>

      <div style="padding:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <label style="display:inline-flex;align-items:center;gap:8px; cursor:pointer; margin:0;">
          <input type="checkbox" id="send_invite_now" name="send_invite_now" value="1" checked style="width:18px;height:18px;">
          <span style="font-size:14px; font-weight:700; color:#0f172a;">📧 Nach dem Anlegen automatisch E-Mail mit Einladungslink senden</span>
        </label>
        <button class="btn-premium" type="submit" style="padding:14px 40px; font-size:16px; border:none; cursor:pointer;">Benutzer Anlegen &rarr;</button>
      </div>

    </form>
  </div>
  <?php endif; ?>

  <?php if ($canViewOthers): ?>
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
        <h2 style="margin:0;">👥 Benutzer-Verwaltung</h2>
        <div style="display:flex; gap:10px;">
           <input type="text" id="userListSearch" placeholder="Suchen..." style="padding:8px 12px; border-radius:8px; border:1px solid #e2e8f0; font-size:13px;">
        </div>
    </div>

    <?php
    // --- QUERY & GROUPING ---
    $nav_pid = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));
    $sql_all = "SELECT u.*, 
                  (SELECT bc.code FROM unternehmer_projekte up 
                   JOIN bkp_codes bc ON bc.id = up.bkp_id 
                   WHERE up.benutzer_id = u.id AND (up.projekt_id = $nav_pid OR $nav_pid = 0) 
                   LIMIT 1) as bkp_code_current
               FROM benutzer u 
               ORDER BY u.name ASC";
    $allUsers = $mysqli->query($sql_all)->fetch_all(MYSQLI_ASSOC);
    
    $groups = [
        'mitarbeiter' => ['label' => '🏢 Mitarbeiter / Administration', 'users' => [], 'icon' => '👨‍💼'],
        'unternehmer' => ['label' => '🏗️ Unternehmer / Handwerker', 'users' => [], 'icon' => '👷'],
        'mieter'      => ['label' => '🔑 Aktuelle Mieter', 'users' => [], 'icon' => '🏠'],
        'vormieter'   => ['label' => '📁 Vormieter / Ehemalige', 'users' => [], 'icon' => '📦'],
        'bewerber'    => ['label' => '📝 Mietinteressenten / Bewerber', 'users' => [], 'icon' => '📋'],
        'andere'      => ['label' => '❓ Sonstige / Unbekannt', 'users' => [], 'icon' => '👥']
    ];

    foreach($allUsers as $u) {
        // Superadmin aus Standard-Liste entfernen (wenn es man selbst ist und man eine saubere Liste will)
        // Aber für die Verwaltung zeigen wir ihn (außer man will ihn wirklich verstecken).
        // Der User sagte "mein profil soll nun nicht angezeigt werden".
        if ((int)$u['id'] === $sid) continue; 

        $phase = $u['mieter_phase'] ?? '';
        $bType = $u['business_type'] ?? 'standard';
        $role  = $u['rolle'] ?? 'benutzer';

        if ($bType === 'handwerker') {
            $groups['unternehmer']['users'][] = $u;
        } elseif ($phase === 'mieter') {
            $groups['mieter']['users'][] = $u;
        } elseif ($phase === 'vormieter' || $phase === 'gekündigt') {
            $groups['vormieter']['users'][] = $u;
        } elseif ($phase === 'interessent' || $phase === 'bewerber' || $bType === 'mietinteressent') {
            $groups['bewerber']['users'][] = $u;
        } elseif ($bType === 'standard' || $role === 'admin' || $role === 'superadmin') {
            $groups['mitarbeiter']['users'][] = $u;
        } else {
            $groups['andere']['users'][] = $u;
        }
    }
    ?>

    <?php foreach ($groups as $key => $g): ?>
        <div class="accordion-section" id="group-<?= h($key) ?>">
            <div class="accordion-header" onclick="this.parentElement.classList.toggle('active')">
                <div style="display:flex; align-items:center; gap:12px;">
                    <span style="font-size:20px;"><?= $g['icon'] ?></span>
                    <span><?= h($g['label']) ?></span>
                    <span class="user-count-pill"><?= count($g['users']) ?></span>
                </div>
                <span class="icon">▼</span>
            </div>
            <div class="accordion-content">
                <?php if (empty($g['users'])): ?>
                    <div style="padding:20px; text-align:center; color:#94a3b8; font-style:italic; font-size:13px;">Keine Benutzer in dieser Kategorie.</div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:50px;">ID</th><th>Name</th><th>Email</th><th>Status</th><th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($g['users'] as $u): 
                                $pu = sanitize_image_url($u['profilbild'] ?? null, $PREFIX);
                            ?>
                                <tr>
                                    <td><?= (int)$u['id'] ?></td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <?php if($pu): ?><img src="<?= h($pu) ?>" style="width:24px; height:24px; border-radius:50%; object-fit:cover;"><?php endif; ?>
                                            <strong><?= h($u['name']) ?></strong>
                                        </div>
                                    </td>
                                    <td><?= h($u['email']) ?></td>
                                    <td>
                                        <?php if((int)($u['is_blocked'] ?? 0) === 1): ?>
                                            <span style="color:#ef4444; font-size:10px; font-weight:800; text-transform:uppercase;">🚫 Gesperrt</span>
                                        <?php else: ?>
                                            <span style="color:#10b981; font-size:10px; font-weight:800; text-transform:uppercase;">● Aktiv</span>
                                        <?php endif; ?>
                                        <div style="font-size:10px; color:#64748b; margin-top:2px;">
                                            <?= h($u['rolle']) ?> <?= $u['business_type']!=='standard' ? '· '.h($u['business_type']) : '' ?>
                                            <?php if(!empty($u['bkp_code_current'])): ?>
                                                <span style="background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-weight:800; margin-left:5px;">BKP <?= h($u['bkp_code_current']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px;">
                                            <a class="btn-outline btn-small" href="profil.php?view=<?= (int)$u['id'] ?>" title="Bearbeiten">✏️</a>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Einladung senden?');">
                                                <?= benutzer_csrf_input() ?>
                                                <input type="hidden" name="action" value="invite_user">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button class="btn-outline btn-small" type="submit" title="Einladung senden">✉️</button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <?= benutzer_csrf_input() ?>
                                                <input type="hidden" name="action" value="sync_folder">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button class="btn-outline btn-small" type="submit" title="Ordner Sync">📁</button>
                                            </form>
                                            <form method="post" style="display:inline;">
                                                <?= benutzer_csrf_input() ?>
                                                <input type="hidden" name="action" value="toggle_block">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button class="btn-outline btn-small" type="submit" title="<?= (int)$u['is_blocked'] ? 'Entsperren':'Sperren' ?>">
                                                    <?= (int)$u['is_blocked'] ? '🔓':'🚫' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Firmen JSON -->
<script>
window.FIRMEN_DATA = <?= json_encode($firmen_by_id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.HIERARCHY_DATA = <?= json_encode($hierarchyJS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<!-- Auto-Fill & Toggle-Logik & Hierarchy Logic -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  // --- User List Search ---
  const searchInput = document.getElementById('userListSearch');
  if(searchInput){
    searchInput.addEventListener('input', e => {
      const q = e.target.value.toLowerCase();
      document.querySelectorAll('.accordion-section').forEach(section => {
        let hasMatches = false;
        section.querySelectorAll('tbody tr').forEach(tr => {
          const text = tr.textContent.toLowerCase();
          const match = text.includes(q);
          tr.style.display = match ? '' : 'none';
          if(match) hasMatches = true;
        });
        // Auto-reveal section if search matches
        if(q.length > 1 && hasMatches) section.classList.add('active');
        else if(q.length < 1) section.classList.remove('active');
      });
    });
  }

  // --- Hierarchy Cascading Logic (Using Window Data) ---
  function setupHierarchy(prefix){
    const pSel = document.getElementById(prefix + 'projekt_id');
    const oSel = document.getElementById(prefix + 'objekt_id');
    const uSel = document.getElementById(prefix + 'wohnung_id');
    if(!pSel || !oSel || !uSel) return;

    const oValHidden = document.getElementById(prefix + 'objekt_id_val');
    const uValHidden = document.getElementById(prefix + 'wohnung_id_val');
    const initialO = oValHidden ? oValHidden.value : null;
    const initialU = uValHidden ? uValHidden.value : null;

    function resetSelect(sel, placeholder, disabled = true) {
      sel.innerHTML = `<option value="">${placeholder}</option>`;
      sel.disabled = disabled;
    }

    function updateObjects(pId, selectedId){
      resetSelect(oSel, pId ? '— auswählen —' : '— Erst Projekt wählen —', !pId);
      resetSelect(uSel, '— Erst Haus wählen —', true);
      
      if(!pId || !window.HIERARCHY_DATA || !window.HIERARCHY_DATA.objects || !window.HIERARCHY_DATA.objects[pId]){
          return;
      }
      
      window.HIERARCHY_DATA.objects[pId].forEach(o => {
          const opt = document.createElement('option');
          opt.value = o.id; opt.textContent = o.name;
          if(selectedId && String(o.id) === String(selectedId)) opt.selected = true;
          oSel.appendChild(opt);
      });
      
      if(selectedId) updateUnits(selectedId, initialU);
      else if(oSel.options.length === 2) { // Only placeholder + 1 object? Auto-select it
          oSel.selectedIndex = 1;
          updateUnits(oSel.value, '');
      }
    }

    function updateUnits(oId, selectedId){
      resetSelect(uSel, oId ? '— auswählen —' : '— Erst Haus wählen —', !oId);
      
      if(!oId || !window.HIERARCHY_DATA || !window.HIERARCHY_DATA.units || !window.HIERARCHY_DATA.units[oId]){
          return;
      }
      
      window.HIERARCHY_DATA.units[oId].forEach(u => {
          const opt = document.createElement('option');
          opt.value = u.id;
          let dName = u.display_name || u.name;
          opt.textContent = dName;
          if(selectedId && String(u.id) === String(selectedId)) opt.selected = true;
          uSel.appendChild(opt);
      });
      
      if(!selectedId && uSel.options.length === 2) {
          uSel.selectedIndex = 1;
      }
    }

    pSel.addEventListener('change', () => {
        if(oValHidden) oValHidden.value = '';
        if(uValHidden) uValHidden.value = '';
        updateObjects(pSel.value, '');
    });
    
    oSel.addEventListener('change', () => {
        if(uValHidden) uValHidden.value = '';
        updateUnits(oSel.value, '');
    });

  // Initial Trigger
    if(pSel.value) {
        updateObjects(pSel.value, initialO);
    }
  }

  setupHierarchy('new_');
  setupHierarchy('edit_');

  // --- Firmen-Autofill ---
  function fillNewFirmFieldsById(id){
    if(!id || !window.FIRMEN_DATA) return;
    const f = window.FIRMEN_DATA[id]; if(!f) return;
    const set = (id,val)=>{ const el=document.getElementById(id); if(el){ el.value = val||''; } };
    set('new_firma_name',    f.name);
    set('new_firma_adresse', f.adresse);
    set('new_firma_telefon', f.telefon);
    set('new_firma_email',   f.email);
    set('new_firma_website', f.website);
    set('new_firma_logo',    f.logo);
  }
  const selNew = document.getElementById('firma_select_id_new');
  if(selNew){
    selNew.addEventListener('change', function(){
      const id = parseInt(selNew.value,10);
      if(id > 0) fillNewFirmFieldsById(id);
    });
  }

  // Edit-Form: Firma auswählen -> leere Felder füllen + Logo-Preview (ohne Platzhalter)
  const selEdit = document.getElementById('firma_select_id');
  const prevTop  = document.getElementById('logoPreviewTop');
  const prevForm = document.getElementById('logoPreviewForm');
  if(selEdit){
    selEdit.addEventListener('change', function(){
      const id = parseInt(selEdit.value,10);
      if(!id || !window.FIRMEN_DATA) return;
      const f = window.FIRMEN_DATA[id]; if(!f) return;
      const setEmpty = (name,val)=>{ const el=document.getElementById('f_'+name); if(el && !el.value) el.value = val||''; };
      setEmpty('firma_name',    f.name);
      setEmpty('firma_adresse', f.adresse);
      setEmpty('firma_telefon', f.telefon);
      setEmpty('firma_email',   f.email);
      setEmpty('firma_website', f.website);

      if (f.logo) {
        if(prevTop)  { prevTop.src  = f.logo;  prevTop.style.display=''; }
        if(prevForm) { prevForm.src = f.logo;  prevForm.style.display=''; }
      } else {
        if(prevTop)  prevTop.style.display='none';
        if(prevForm) prevForm.style.display='none';
      }
    });
  }

  // Sichtbarkeits-Toggles (Bearbeiten)
  const LEVELS  = ['private','internal','project','public'];
  const ICONS   = {'private':'🔒','internal':'🏢','project':'👥','public':'🌍'};
  document.querySelectorAll('.icon-toggle').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const key = btn.getAttribute('data-for'); if(!key) return;
      const hidden = document.getElementById('vis_'+key); if(!hidden) return;
      const cur = hidden.value || 'private';
      const idx = LEVELS.indexOf(cur);
      const next = LEVELS[(idx+1) % LEVELS.length];
      hidden.value = next;
      btn.textContent = ICONS[next] || '🔒';
      btn.title = {'private':'Nur ich','internal':'Intern','project':'Projekt','public':'Öffentlich'}[next] || 'Nur ich';
    });
  });

  // Sichtbarkeits-Toggles (Create)
  document.querySelectorAll('.icon-toggle-new').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const forId = btn.getAttribute('data-for'); if(!forId) return;
      const base  = forId.replace(/^new_/, '');
      const hiddenId = 'new_vis_'+base;
      const hidden = document.getElementById(hiddenId); if(!hidden) return;
      const cur = hidden.value || 'private';
      const idx = LEVELS.indexOf(cur);
      const next = LEVELS[(idx+1) % LEVELS.length];
      hidden.value = next;
      btn.textContent = ICONS[next] || '🔒';
      btn.title = {'private':'Nur ich','internal':'Intern','project':'Projekt','public':'Öffentlich'}[next] || 'Nur ich';
    });
  });
});

/**
 * Simplifies the form by showing/hiding context-relevant fields
 */
function handleUserTypeChange(val) {
    const wrapPhase = document.getElementById('wrap_mieter_phase');
    const wrapBkp   = document.getElementById('wrap_bkp_id');
    const wrapTypeInner   = document.getElementById('wrap_person_type_inner');
    const wrapStatusInner = document.getElementById('wrap_person_status_inner');
    const roleSel   = document.getElementById('new_role');

    if (!roleSel) return;

    // Reset visibility
    if (wrapPhase) wrapPhase.style.display = 'none';
    if (wrapBkp)   wrapBkp.style.display   = 'none';
    if (wrapTypeInner)  wrapTypeInner.style.display  = 'none';
    if (wrapStatusInner) wrapStatusInner.style.display = 'none';

    if (val === 'standard') {
        // Team / Mitarbeiter
        roleSel.value = 'benutzer'; 
        if (wrapTypeInner) wrapTypeInner.style.display = 'block';
        if (wrapStatusInner) wrapStatusInner.style.display = 'block';
    } 
    else if (val === 'handwerker') {
        // Partner / Unternehmer
        roleSel.value = 'gast';
        if (wrapBkp) wrapBkp.style.display = 'block';
    } 
    if (val === 'mieter') {
        // Kunde / Mieter
        roleSel.value = 'gast';
        if (wrapPhase) wrapPhase.style.display = 'block';
        
        // Smart-Select Object "10_Mietsache" if it exists in the list
        const objSel = document.getElementById('new_objekt_id');
        if (objSel) {
            for (let i = 0; i < objSel.options.length; i++) {
                if (objSel.options[i].text.includes('10_Mietsache')) {
                    objSel.selectedIndex = i;
                    objSel.dispatchEvent(new Event('change')); // Trigger units load
                    break;
                }
            }
        }
    }
}
</script>

<script>
// Status-Auswahl dynamisch nach Typ (für Edit & Create)
const STATUSES = <?= json_encode($statusesMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

function fillStatuses(selectEl, typeId, selectedId){
  if (!selectEl) return;
  selectEl.innerHTML = '';
  if (!typeId || !STATUSES[typeId] || STATUSES[typeId].length === 0) {
    selectEl.disabled = true;
    const opt = document.createElement('option'); opt.value=''; opt.textContent='— zuerst Typ wählen —';
    selectEl.appendChild(opt);
    return;
  }
  selectEl.disabled = false;
  const def = document.createElement('option'); def.value=''; def.textContent='— auswählen —';
  selectEl.appendChild(def);
  STATUSES[typeId].forEach(s=>{
    const o = document.createElement('option');
    o.value = s.id; o.textContent = s.name;
    if (selectedId && String(selectedId) === String(s.id)) o.selected = true;
    selectEl.appendChild(o);
  });
}

// EDIT
const typeEdit = document.getElementById('person_type_id');
const statEdit = document.getElementById('person_status_id');
if (typeEdit && statEdit) {
  typeEdit.addEventListener('change', ()=>{
    const tid = parseInt(typeEdit.value || '0',10);
    fillStatuses(statEdit, tid, null);
  });
}
// CREATE
const typeNew = document.getElementById('new_person_type_id');
const statNew = document.getElementById('new_person_status_id');
if (typeNew && statNew) {
  typeNew.addEventListener('change', ()=>{
    const tid = parseInt(typeNew.value || '0',10);
    fillStatuses(statNew, tid, null);
  });
}
</script>

<script src="<?= h($PREFIX) ?>assets/js/benutzer.js?v=20251002c" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
