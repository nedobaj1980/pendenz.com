<?php
// pages/benutzer.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

/* === Auswahlmodus (für Rückkehr in eine andere Seite) === */
$select_mode = (isset($_GET['select']) && $_GET['select']=='1' && !empty($_GET['return_to']));
$return_to   = $select_mode ? (string)$_GET['return_to'] : '';
$ve_id_pick  = $select_mode ? (int)($_GET['ve_id'] ?? 0) : 0;

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
chips_style_once(); // Jetzt hier

/* ===== User laden ===== */
$stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
$stmt->bind_param("i", $viewId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { http_response_code(404); exit('Benutzer nicht gefunden.'); }

/* ===== Tabellen-/Spalten-Checker ===== */
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
$benutzerCols = table_has_columns($mysqli,'benutzer',['person_type_id','person_status_id','projekt_id','objekt_id','wohnung_id','bkp_id','business_type','mieter_phase','vorgangsart_id']);

// BKP Codes
$bkp_codes_all = [];
$res_bkp = $mysqli->query("SELECT id, code, bezeichnung FROM bkp_codes ORDER BY code ASC");
if ($res_bkp) while($rb = $res_bkp->fetch_assoc()) $bkp_codes_all[] = $rb;

// Firma laden für diesen User
$currentFirmaId = 0;
$resF = $mysqli->query("SELECT firma_id FROM firma_user WHERE user_id = " . (int)$viewId . " ORDER BY is_primary DESC LIMIT 1");
if ($resF && $rowF = $resF->fetch_assoc()) {
    $currentFirmaId = (int)$rowF['firma_id'];
}

// Hierarchy Data for Dropdowns
$projects = $mysqli->query("SELECT id, name FROM projekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$objectsAll = $mysqli->query("SELECT id, projekt_id, name FROM objekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$unitsAll = $mysqli->query("SELECT id, objekt_id, name FROM wohnungen ORDER BY name")->fetch_all(MYSQLI_ASSOC);
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

// Vorgangsarten (Typen) laden
$vorgangsarten = [];
try {
    $vRes = $mysqli->query("SELECT id, name FROM pendenzen_arten ORDER BY sort_order ASC, name ASC");
    if ($vRes) {
        while ($vr = $vRes->fetch_assoc()) $vorgangsarten[] = $vr;
        $vRes->close();
    }
} catch (Throwable $ve) {}
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
        $st=$db->prepare("SELECT name,adresse,telefon,email,website,logo FROM firmen WHERE id=?");
        $st->bind_param("i",$id); $st->execute();
        $r=$st->get_result()->fetch_assoc(); $st->close();
        if($r) return $r;
    } catch(Throwable $e){}
    try {
        $st=$db->prepare("SELECT name FROM firmen WHERE id=?");
        $st->bind_param("i",$id); $st->execute();
        $r=$st->get_result()->fetch_assoc(); $st->close();
        if($r) return $r+['adresse'=>null,'telefon'=>null,'email'=>null,'website'=>null,'logo'=>null];
    } catch(Throwable $e){}
    return null;
}
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

      if (in_array('mieter_phase',$benutzerCols,true)) {
          $cols[] = 'mieter_phase'; $types .= 's'; $vals[] = trim($_POST['new_mieter_phase'] ?? 'interessent');
      }
      if (in_array('wohnung_id',$benutzerCols,true)) {
          $cols[] = 'wohnung_id'; $types .= 'i'; $vals[] = (int)($_POST['new_wohnung_id'] ?? 0) ?: null;
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

      if (in_array('projekt_id',$benutzerCols,true)) {
        $cols[] = 'projekt_id'; $types .= 'i'; $vals[] = (int)($_POST['new_projekt_id'] ?? 0) ?: null;
      }
      if (in_array('objekt_id',$benutzerCols,true)) {
        $cols[] = 'objekt_id'; $types .= 'i'; $vals[] = (int)($_POST['new_objekt_id'] ?? 0) ?: null;
      }

      $cols[]='profile_updated_at'; $types.='s'; $vals[] = date('Y-m-d H:i:s');

      $place = implode(',', array_fill(0,count($cols),'?'));
      $sql = "INSERT INTO benutzer (".implode(',',$cols).") VALUES ($place)";
      $stI = $mysqli->prepare($sql);
      $stI->bind_param($types, ...$vals);
      $stI->execute();
      $newId = (int)$mysqli->insert_id;
      $stI->close();

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
      $types = 'ssssssssssssssssssss'; // 20 Strings
      $vals = [
        $data['name'], $data['email'], $finalRole,
        $data['telefonnummer'], $data['adresse'],
        $data['firma_name'], $data['firma_adresse'], $data['firma_telefon'], $data['firma_email'], $data['firma_website'],
        $data['geburtsdatum'], $data['heimatland'], $data['position'], $data['beruf'],
        trim($_POST['mieter_phase'] ?? 'interessent'),
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

      if (in_array('projekt_id',$benutzerCols,true)) {
        $sql .= ", projekt_id=?"; $types .= 'i'; $vals[] = (int)($_POST['projekt_id'] ?? 0) ?: null;
      }
      if (in_array('objekt_id',$benutzerCols,true)) {
        $sql .= ", objekt_id=?"; $types .= 'i'; $vals[] = (int)($_POST['objekt_id'] ?? 0) ?: null;
      }
      if (in_array('wohnung_id',$benutzerCols,true)) {
        $sql .= ", wohnung_id=?"; $types .= 'i'; $vals[] = (int)($_POST['wohnung_id'] ?? 0) ?: null;
      }

      $sql .= " WHERE id=?";
      $types .= 'i';
      $vals[] = $targetId;

      $stmt = $mysqli->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();

      // Verknüpfung in firma_user aktualisieren
      if (isset($_POST['firma_select_id'])) {
          $newFid = (int)$_POST['firma_select_id'];
          if ($newFid > 0) {
              // Wir suchen nach einer bestehenden Verknüpfung
              $resC = $mysqli->query("SELECT id FROM firma_user WHERE user_id = $targetId LIMIT 1");
              if ($resC && $resC->num_rows > 0) {
                  $mysqli->query("UPDATE firma_user SET firma_id = $newFid WHERE user_id = $targetId");
              } else {
                  $mysqli->query("INSERT INTO firma_user (user_id, firma_id, is_primary) VALUES ($targetId, $newFid, 1)");
              }
          } else {
              $mysqli->query("DELETE FROM firma_user WHERE user_id = $targetId");
          }
      }

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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
  --p-primary: #2563eb;
  --p-primary-dark: #1e40af;
  --p-accent: #3b82f6;
  --p-text-main: #0f172a;
  --p-text-muted: #64748b;
  --p-bg: #f8fafc;
  --p-card-bg: #ffffff;
  --p-border: #e2e8f0;
  --radius-xl: 32px;
  --radius-lg: 24px;
  --radius-md: 16px;
  --shadow-premium: 0 20px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
  --font-main: 'Inter', sans-serif;
  --font-title: 'Outfit', sans-serif;
}

body { font-family: var(--font-main); color: var(--p-text-main); background: var(--p-bg); -webkit-font-smoothing: antialiased; }

.hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    padding: 60px 40px 120px;
    border-radius: var(--radius-lg);
    margin-bottom: -80px;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    box-shadow: var(--shadow-premium);
    position: relative;
    border: 1px solid rgba(255,255,255,0.05);
}
.hero-title { font-family: var(--font-title); font-size: 38px; font-weight: 900; margin: 0; letter-spacing: -0.04em; }
.hero-sub { opacity: 0.7; font-size: 15px; font-weight: 500; margin-top: 10px; }

.badge-role {
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    padding: 10px 20px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    border: 1px solid rgba(255, 255, 255, 0.15);
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.profile-main-card {
    background: var(--p-card-bg);
    border-radius: var(--radius-lg);
    overflow: hidden;
    border: 1px solid var(--p-border);
    margin-bottom: 40px;
    box-shadow: var(--shadow-premium);
    position: relative;
    z-index: 10;
}

.top-title {
    height: 260px;
    background: #1e293b;
    position: relative;
    overflow: hidden;
}
.top-title img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s ease; }
.top-title:hover img { transform: scale(1.05); }

.profile-summary {
    display: flex;
    padding: 0 40px 40px;
    margin-top: -100px;
    position: relative;
    gap: 48px;
    background: linear-gradient(to bottom, transparent, var(--p-card-bg) 80px);
}

.profile-avatar-wrap {
    width: 200px;
    height: 200px;
    border-radius: 48px;
    border: 10px solid var(--p-card-bg);
    background: var(--p-card-bg);
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
    transition: transform 0.3s ease;
}
.profile-avatar-wrap:hover { transform: translateY(-5px); }
.profile-avatar-wrap img { width: 100%; height: 100%; object-fit: cover; }

.profile-info {
    padding-top: 110px;
    flex: 1;
}

.kv-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
}

.kv-card {
    background: #f8fafc;
    padding: 20px;
    border-radius: var(--radius-md);
    border: 1px solid #e2e8f0;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    align-items: center;
    gap: 20px;
}
.kv-card:hover { 
    background: #fff; 
    border-color: var(--p-primary); 
    box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.08); 
    transform: translateY(-3px);
}

.kv-icon { font-size: 22px; width: 52px; height: 52px; display: flex; align-items: center; justify-content: center; background: #fff; border-radius: 14px; color: var(--p-primary); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.kv-content { display: flex; flex-direction: column; gap: 4px; }
.kv-label { font-size: 11px; color: var(--p-text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; }
.kv-value { font-size: 16px; color: var(--p-text-main); font-weight: 700; line-height: 1.3; }

.company-badge {
    background: #fff;
    padding: 32px;
    border-radius: var(--radius-lg);
    border: 1px solid var(--p-border);
    display: flex;
    flex-direction: column;
    gap: 20px;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
    position: relative;
    overflow: hidden;
}
.company-badge::before {
    content: "";
    position: absolute;
    top:0; left:0; right:0; height:6px;
    background: linear-gradient(to right, #2563eb, #3b82f6);
}

.company-info-row {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    color: var(--p-text-main);
}
.company-info-row .kv-icon {
    width: 32px; height: 32px; font-size: 14px; border-radius: 8px; flex-shrink: 0;
}

.modern-form-section { 
    background: var(--p-card-bg); 
    border: 1px solid var(--p-border); 
    border-radius: var(--radius-lg); 
    padding: 48px; 
    margin-bottom: 40px; 
    box-shadow: var(--shadow-premium);
}
.modern-form-title { 
    font-family: var(--font-title);
    font-size: 24px; 
    font-weight: 900; 
    color: var(--p-text-main); 
    margin-bottom: 40px; 
    display: flex; 
    align-items: center; 
    gap: 16px; 
    border-bottom: 2px solid #f8fafc; 
    padding-bottom: 20px; 
    letter-spacing: -0.02em;
}

.modern-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 32px; }
.modern-field { display: flex; flex-direction: column; gap: 10px; }
.modern-field label { font-size: 13px; font-weight: 800; color: var(--p-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.modern-input-group { display: flex; gap: 14px; align-items: center; }
.modern-input-group input, .modern-input-group select { 
    flex: 1; padding: 16px 20px; border: 2px solid #eef2f6; border-radius: 14px; 
    font-size: 16px; font-family: var(--font-main); background: #fdfdfe; transition: 0.25s; color: var(--p-text-main);
}
.modern-input-group input:focus, .modern-input-group select:focus { border-color: var(--p-primary); box-shadow: 0 0 0 5px rgba(37, 99, 235, 0.1); outline: none; background: #fff; }

.btn-premium {
    padding: 18px 36px; border-radius: 18px; background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
    color: white !important; font-weight: 800; font-family: var(--font-title); display: inline-flex; align-items: center; gap: 12px; 
    box-shadow: 0 12px 20px -4px rgba(37, 99, 235, 0.3); text-decoration: none; border: none; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 16px;
}
.btn-premium:hover { transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(37, 99, 235, 0.4); background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }

.icon-toggle { 
    background: #f8fafc; border: 2px solid #eef2f6; cursor: pointer; border-radius: 14px; width: 56px; height: 56px; 
    display: flex; align-items: center; justify-content: center; transition: 0.25s; font-size: 22px;
}
.icon-toggle:hover { background: #fff; border-color: var(--p-primary); color: var(--p-primary); box-shadow: 0 6px 10px -2px rgba(0,0,0,0.08); }

.img-preview-wrap { background: #f8fafc; border: 2.5px dashed #e2e8f0; padding: 24px; border-radius: 20px; display: flex; align-items: center; gap: 24px; transition: 0.25s; }
.img-preview-wrap:hover { border-color: var(--p-primary); background: #fff; }
.img-preview-thumb { width: 100px; height: 100px; border-radius: 16px; object-fit: cover; box-shadow: 0 8px 12px -3px rgba(0,0,0,0.15); border: 4px solid #fff; }

.visibility-card { background: var(--p-card-bg); border-radius: var(--radius-lg); border: 1px solid var(--p-border); margin-bottom: 32px; padding: 40px; box-shadow: var(--shadow-premium); }
.visibility-preview-btn { width: 100%; text-align: left; background: #f8fafc; border: 1.5px solid #eef2f6; padding: 18px 28px; border-radius: 16px; font-weight: 800; margin-bottom: 12px; cursor: pointer; transition: 0.25s; display: flex; align-items: center; gap: 14px; font-family: var(--font-title); font-size: 16px; }
.visibility-preview-btn:hover { background: #fff; border-color: var(--p-primary); transform: translateX(6px); color: var(--p-primary); }

.btn-outline { padding: 10px 20px; border: 2px solid rgba(255,255,255,0.2); border-radius: 12px; color: #fff; text-decoration: none; font-weight: 700; font-size: 13px; transition: 0.2s; }
.btn-outline:hover { background: rgba(255,255,255,0.1); border-color: #fff; }

@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
.profile-main-card, .modern-form-section, .visibility-card { animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
</style>

<div class="container" id="profile-root" data-prefix="<?= h($PREFIX) ?>">

  <header class="hero">
    <div class="hero-left">
      <h1 class="hero-title">
        <?= (int)$viewId === (int)$sid ? '👤 Mein Profil' : 'Profil von '.h($user['name'] ?: 'Unbenannt') ?>
      </h1>

      <?php if ($select_mode): ?>
        <div style="margin-top:6px">
          <a class="btn btn-small" href="<?=
            h(
              $return_to
              . (str_contains($return_to,'?') ? '&' : '?')
              . 'selected_user=' . (int)$user['id']
              . ($ve_id_pick ? '&ve_id='.(int)$ve_id_pick : '')
            )
          ?>">✅ Diesen Benutzer übernehmen</a>
        </div>
      <?php endif; ?>

      <div class="hero-sub">
        Rolle: <strong><?= h($user['rolle'] ?? 'gast') ?></strong>
        <?php if ($viewId !== $sid): ?>
          · Eingeloggt als <strong><?= h($currentRole) ?></strong>
        <?php endif; ?>
      </div>
    </div>
    <div class="hero-right">
      <?php if ($canViewOthers && (int)$viewId !== (int)$sid): ?>
         <a href="benutzer.php?view=<?= $sid ?>" class="btn-premium">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            Mein Profil
         </a>
      <?php endif; ?>

      <a href="<?= h($PREFIX) ?>pages/personen_taxonomie.php" class="btn-outline btn-small">
        Taxonomie
      </a>
      <a href="<?= h($PREFIX) ?>pages/firmen.php" class="btn-outline btn-small">Firmen</a>
      <?php if (in_array($currentRole, ['admin','superadmin'], true)): ?>
        <button class="btn btn-teal btn-small" onclick="document.getElementById('create-user-section').scrollIntoView({behavior:'smooth'})">➕ Neu</button>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($select_mode): ?>
    <div class="card" style="background:#fff7ed;border-left:4px solid #f59e0b;display:flex;justify-content:space-between;align-items:center;gap:8px">
      <div>
        🔎 <strong>Auswahlmodus</strong>: Bitte einen Benutzer wählen.
        <span class="muted">Ziel: <?= h(parse_url($return_to, PHP_URL_PATH) ?: $return_to) ?></span>
      </div>
      <div class="hero-right" style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a class="btn-outline btn-small" href="<?= h($return_to) ?>">↩️ Zurück</a>
        <a class="btn btn-small" href="<?=
          h(
            $return_to
            . (str_contains($return_to,'?') ? '&' : '?')
            . 'selected_user=' . (int)$user['id']
            . ($ve_id_pick ? '&ve_id='.(int)$ve_id_pick : '')
          )
        ?>">✅ Diesen Benutzer übernehmen</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= $flash ?></div>
  <?php endif; ?>

  <!-- OBERER BEREICH: Privat-Ansicht -->
  <div class="profile-main-card" id="<?= (int)$viewId === (int)$sid ? 'my-profile-card' : 'profile' ?>">
    <div class="top-title">
      <?php if ($titelURL): ?>
        <img src="<?= h($titelURL) ?>" alt="Titelbild">
      <?php else: ?>
        <div style="width:100%; height:100%; background: linear-gradient(45deg, #3b82f6, #1d4ed8);"></div>
      <?php endif; ?>
      
      <div style="position:absolute; top:20px; left:20px;">
        <span class="badge-role">
          <?= (int)$viewId === (int)$sid ? '🏠 Mein Privates Profil' : '🔒 Privat-Ansicht' ?>
        </span>
      </div>
    </div>

    <div class="profile-summary">
      <div class="profile-avatar-wrap">
        <?php if ($profilURL): ?>
          <img src="<?= h($profilURL) ?>" alt="Profilbild">
        <?php else: ?>
          <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:#f1f5f9; color:#94a3b8;">
            <svg width="48" height="48" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"></path></svg>
          </div>
        <?php endif; ?>
      </div>

      <div class="profile-info">
        <h2 style="margin:0 0 20px; font-size:32px; font-weight:900; color: var(--p-text-main); letter-spacing:-0.03em;"><?= h($user['name'] ?: 'Unbenannt') ?></h2>
        
        <div class="profile-hero-layout" style="display:grid; grid-template-columns: 1.5fr 1fr; gap: 40px; align-items: start;">
          <!-- Persönliche Daten -->
          <div class="kv-grid">
            <?php 
              $mainFields = [
                'email' => ['📧', 'E-Mail'],
                'telefonnummer' => ['📞', 'Telefon / Mobil'],
                'adresse' => ['📍', 'Privatadresse'],
                'beruf' => ['💼', 'Beruf / Tätigkeit'],
                'geburtsdatum' => ['📅', 'Geburtsdatum'],
                'heimatland' => ['🌍', 'Nationalität']
              ];
              foreach ($mainFields as $k => $cfg): 
                $icon = $cfg[0]; $label = $cfg[1];
                $val = trim((string)($user[$k] ?? ''));
                if ($val === '') continue;
                if ($k === 'geburtsdatum') $val = date('d.m.Y', strtotime($val));
            ?>
              <div class="kv-card">
                <div class="kv-icon"><?= $icon ?></div>
                <div class="kv-content">
                  <span class="kv-label"><?= h($label) ?></span>
                  <span class="kv-value"><?= h($val) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <!-- Unternehmens-Daten -->
          <div class="company-section">
            <?php if ($logoURL || $user['firma_name']): ?>
              <div class="company-badge">
                <div style="display:flex; align-items:center; gap:20px; border-bottom:1px solid #f1f5f9; padding-bottom:20px;">
                  <?php if ($logoURL): ?>
                    <img id="logoPreviewTop" src="<?= h($logoURL) ?>" alt="Firmenlogo" style="height:60px; max-width:120px; object-fit:contain; border-radius:8px;">
                  <?php else: ?>
                    <div style="width:60px; height:60px; border-radius:12px; background:#f8fafc; display:flex; align-items:center; justify-content:center; color:#cbd5e1; font-size:28px; border:1px solid #f1f5f9;">🏢</div>
                  <?php endif; ?>
                  
                  <div style="display:flex; flex-direction:column;">
                    <span class="kv-label" style="font-size:10px;">Zugeordnet</span>
                    <h3 style="margin:0; font-size:18px; font-weight:900; color:var(--p-text-main);"><?= h($user['firma_name'] ?: 'Keine Firma') ?></h3>
                    <?php if ($user['position']): ?>
                      <span style="font-size:13px; color:var(--p-primary); font-weight:700;"><?= h($user['position']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div style="display:grid; gap:12px;">
                  <?php if ($user['firma_email']): ?>
                    <div class="company-info-row" title="Firmen-E-Mail">
                      <div class="kv-icon">📧</div>
                      <span style="font-weight:600;"><?= h($user['firma_email']) ?></span>
                    </div>
                  <?php endif; ?>
                  
                  <?php if ($user['firma_telefon']): ?>
                    <div class="company-info-row" title="Firmen-Telefon">
                      <div class="kv-icon">📞</div>
                      <span style="font-weight:600;"><?= h($user['firma_telefon']) ?></span>
                    </div>
                  <?php endif; ?>

                  <?php if ($user['firma_website']): ?>
                    <div class="company-info-row" title="Website">
                      <div class="kv-icon">🌐</div>
                      <a href="<?= h((str_contains($user['firma_website'],'://')?'':'https://').$user['firma_website']) ?>" target="_blank" style="color:var(--p-primary); font-weight:700; text-decoration:none;">
                        <?= h($user['firma_website']) ?>
                      </a>
                    </div>
                  <?php endif; ?>

                  <?php if ($user['firma_adresse']): ?>
                    <div class="company-info-row" style="align-items:start; margin-top:4px;">
                      <div class="kv-icon">🏢</div>
                      <span style="font-size:13px; color:var(--p-text-muted); line-height:1.4;">
                        <?= nl2br(h($user['firma_adresse'])) ?>
                      </span>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- SICHTBARKEITS-VORSCHAU -->
  <div class="visibility-card">
    <h2 style="font-family: var(--font-title); font-size:20px; font-weight:800; margin-bottom:24px; color: var(--p-text-main); display:flex; align-items:center; gap:12px;">
      <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
      Sichtbarkeits-Vorschau
    </h2>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
      <details style="background:transparent; border:none;">
        <summary class="visibility-preview-btn">👥 Interne Projekt-Ansicht</summary>
        <div style="padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; margin-top: 10px;">
          <?php render_profile_preview_block($user,$profileVis,$FIELDS,'project',$profilURL,$logoURL,$titelURL,$tbMaxH,$pbMaxW,$lgMaxW); ?>
        </div>
      </details>

      <details style="background:transparent; border:none;">
        <summary class="visibility-preview-btn">🌍 Öffentliche Profil-Ansicht</summary>
        <div style="padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; margin-top: 10px;">
          <?php render_profile_preview_block($user,$profileVis,$FIELDS,'public',$profilURL,$logoURL,$titelURL,$tbMaxH,$pbMaxW,$lgMaxW); ?>
        </div>
      </details>
    </div>
  </div>

  <!-- BEARBEITEN -->
  <div class="modern-form-section">
    <div class="modern-form-title">
      <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
      Profil bearbeiten
    </div>
    
    <form method="post" enctype="multipart/form-data" id="profile-form" autocomplete="on">
      <?= benutzer_csrf_input() ?>
      <input type="hidden" name="action" value="save_profile">
      <input type="hidden" name="id" value="<?= (int)$viewId ?>">

      <div class="modern-grid">
        
        <!-- Stammdaten / Rolle -->
        <div style="grid-column: 1 / -1; display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:24px;">
          <?php if (!empty($firmen)): ?>
            <div class="modern-field">
              <label for="firma_select_id">Firma (aus Stammdaten)</label>
              <div class="modern-input-group">
                <select id="firma_select_id" name="firma_select_id" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                  <option value="">— auswählen —</option>
                  <?php foreach ($firmen as $f): 
                    $sel = ((int)$currentFirmaId === (int)$f['id']) ? 'selected' : '';
                  ?>
                    <option value="<?= (int)$f['id'] ?>" <?= $sel ?>><?= h($f['name'] ?: ('Firma #'.(int)$f['id'])) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($canViewOthers): 
              $roleEditable = ($currentRole === 'superadmin') || ($viewId !== $sid);
              $roleSel = $user['rolle'] ?? 'benutzer';
          ?>
            <div class="modern-field">
              <label for="rolle">Benutzer-Rolle</label>
              <div class="modern-input-group">
                <select id="rolle" name="rolle" <?= $roleEditable ? '' : 'disabled' ?>>
                  <option value="gast"     <?= $roleSel==='gast'?'selected':'' ?>>gast</option>
                  <option value="benutzer" <?= $roleSel==='benutzer'?'selected':'' ?>>benutzer</option>
                  <option value="admin"    <?= $roleSel==='admin'?'selected':'' ?>>admin</option>
                  <?php if ($currentRole === 'superadmin'): ?>
                    <option value="superadmin" <?= $roleSel==='superadmin'?'selected':'' ?>>superadmin</option>
                  <?php endif; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Personen-Typ/Status & Zuordnung -->
        <div style="grid-column: 1 / -1; display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:24px;">
          <?php if (in_array('business_type', $benutzerCols, true)): ?>
            <div class="modern-field">
              <label>User-Typ (Kontext)</label>
              <div class="modern-input-group">
                <select id="business_type" name="business_type">
                  <option value="standard"   <?= ($user['business_type']??'standard')==='standard'?'selected':'' ?>>🏢 Team / Mitarbeiter</option>
                  <option value="handwerker" <?= ($user['business_type']??'')==='handwerker'?'selected':'' ?>>🏗️ Partner / Unternehmer</option>
                  <option value="mieter"     <?= ($user['business_type']??'')==='mieter'?'selected':'' ?>>🔑 Kunde / Mieter</option>
                </select>
              </div>
            </div>
          <?php endif; ?>

          <?php 
            $hasType   = in_array('person_type_id', $benutzerCols, true);
            $hasStatus = in_array('person_status_id', $benutzerCols, true);
            if ($hasType): $curType = (int)($user['person_type_id'] ?? 0); 
          ?>
            <div class="modern-field">
              <label for="person_type_id">Personen-Typ (Status-Gruppe)</label>
              <div class="modern-input-group">
                <select id="person_type_id" name="person_type_id" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                  <option value="">— auswählen —</option>
                  <?php foreach ($types as $t): $sel = ($curType === (int)$t['id']) ? 'selected' : ''; ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $sel ?>><?= h($t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="modern-field">
              <label for="person_status_id">Detail-Status</label>
              <div class="modern-input-group">
                <?php 
                  $curStat = (int)($user['person_status_id'] ?? 0);
                  $disabled = $curType ? '':'disabled';
                ?>
                <select id="person_status_id" name="person_status_id" <?= $disabled ?> <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                  <option value=""><?= $curType ? '— auswählen —' : '— zuerst Typ wählen —' ?></option>
                  <?php foreach (($statusesMap[$curType] ?? []) as $s): $sel = ($curStat === (int)$s['id']) ? 'selected' : ''; ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $sel ?>><?= h($s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>

          <div class="modern-field">
            <label for="vorgangsart_id">📑 Standard-Vorgangsart (Typ)</label>
            <div class="modern-input-group">
              <select id="vorgangsart_id" name="vorgangsart_id" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                <option value="">— Keine (Standard) —</option>
                <?php foreach ($vorgangsarten as $va): ?>
                  <option value="<?= (int)$va['id'] ?>" <?= (int)($user['vorgangsart_id'] ?? 0) === (int)$va['id'] ? 'selected' : '' ?>>
                    <?= h($va['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-text" style="font-size:11px; color:#64748b; margin-top:4px;">Standard-Typ für neue Pendenzen dieses Benutzers</div>
          </div>
        </div>

          <div class="modern-field" id="wrap_mieter_phase" style="display:none;">
            <label for="mieter_phase">Mieter-Phase</label>
            <div class="modern-input-group">
              <select id="mieter_phase" name="mieter_phase" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                <?php
                  $curPhase = $user['mieter_phase'] ?? 'interessent';
                  $pOpts = ['interessent'=>'⭐ Interessent','bewerber'=>'📝 Bewerber','mieter'=>'🔑 Mieter','vormieter'=>'📁 Vormieter'];
                  foreach($pOpts as $v=>$l): $sel = ($curPhase===$v ? 'selected':'');
                ?>
                  <option value="<?= h($v) ?>" <?= $sel ?>><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="modern-field">
            <label for="kontaktweg">Bevorz. Kontaktweg</label>
            <div class="modern-input-group">
              <select id="kontaktweg" name="kontaktweg" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                <?php
                  $curWay = $user['kontaktweg'] ?? 'email';
                  $wOpts = ['email'=>'📧 E-Mail','telefon'=>'📞 Telefon','whatsapp'=>'💬 WhatsApp','sms'=>'📱 SMS','post'=>'📮 Postweg'];
                  foreach($wOpts as $v=>$l) : 
                ?>
                  <option value="<?= h($v) ?>" <?= ($curWay===$v?'selected':'') ?>><?= h($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        

        <!-- Projekt-Hierarchie -->
        <?php if (in_array('wohnung_id', $benutzerCols, true)): ?>
          <div class="modern-field">
            <label>Projekt</label>
            <div class="modern-input-group">
              <select id="edit_projekt_id" name="projekt_id" <?= (!$canViewOthers)?'disabled':'' ?>>
                <option value="">— Kein Projekt —</option>
                <?php foreach($projects as $p): ?>
                  <option value="<?= (int)$p['id'] ?>" <?= ((int)($user['projekt_id'] ?? 0)===(int)$p['id']?'selected':'') ?>><?= h($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="modern-field">
            <label>Haus / Objekt</label>
            <div class="modern-input-group">
              <select id="edit_objekt_id" name="objekt_id" <?= (!$canViewOthers)?'disabled':'' ?>>
                <option value="">— Erst Projekt wählen —</option>
              </select>
            </div>
            <input type="hidden" id="edit_objekt_id_val" value="<?= (int)($user['objekt_id'] ?? 0) ?>">
          </div>

          <div class="modern-field">
            <label>Wohnung / Einheit</label>
            <div class="modern-input-group">
              <select id="edit_wohnung_id" name="wohnung_id" <?= (!$canViewOthers)?'disabled':'' ?>>
                <option value="">— Erst Haus wählen —</option>
              </select>
            </div>
            <input type="hidden" id="edit_wohnung_id_val" value="<?= (int)($user['wohnung_id'] ?? 0) ?>">
          </div>
        <?php endif; ?>

        <!-- Dynamic Fields -->
        <?php foreach ($FIELDS as $key => $label):
          $type = (strpos($key,'email')!==false ? 'email' : ($key==='geburtsdatum' ? 'date' : 'text'));
          $lvl  = $profileVis[$key] ?? 'private';
          $isFullWidth = in_array($key, ['adresse', 'beruf', 'position'], true);
        ?>
          <div class="modern-field" style="<?= $isFullWidth ? 'grid-column: 1 / -1;' : '' ?>">
            <label for="f_<?= $key ?>"><?= h($label) ?></label>
            <div class="modern-input-group">
              <input type="<?= $type ?>" id="f_<?= $key ?>" name="<?= $key ?>" value="<?= h($user[$key] ?? '') ?>" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
              <button type="button" class="icon-toggle" data-for="<?= $key ?>" title="<?= h($LEVEL_LABEL[$lvl]) ?>"><?= $LEVEL_ICON[$lvl] ?></button>
              <input type="hidden" name="vis_<?= $key ?>" id="vis_<?= $key ?>" value="<?= h($lvl) ?>">
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Mediathek / Bilder -->
        <div style="grid-column: 1 / -1; margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px;">
          
          <!-- Profilbild -->
          <div class="modern-field">
            <label>Profilbild</label>
            <div class="img-preview-wrap">
              <?php if ($profilURL): ?>
                <img src="<?= h($profilURL) ?>" class="img-preview-thumb img-thumb" alt="Profilbild">
              <?php endif; ?>
              <div style="flex:1; display:flex; flex-direction:column; gap:8px;">
                <input type="file" name="profilbild" accept="image/*" class="btn-small" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:500;">
                  <input type="checkbox" name="profilbild_delete" value="1"> Löschen
                </label>
              </div>
              <button type="button" class="icon-toggle" data-for="profilbild"><?= $LEVEL_ICON[$profileVis['vis_profilbild'] ?? 'private'] ?></button>
              <input type="hidden" name="vis_profilbild" id="vis_profilbild" value="<?= h($profileVis['vis_profilbild'] ?? 'private') ?>">
            </div>
          </div>

          <!-- Firmenlogo -->
          <div class="modern-field">
            <label>Firmenlogo</label>
            <div class="img-preview-wrap">
              <?php if ($logoURL): ?>
                <img src="<?= h($logoURL) ?>" class="img-preview-thumb img-thumb" alt="Firmenlogo">
              <?php endif; ?>
              <div style="flex:1; display:flex; flex-direction:column; gap:8px;">
                <input type="file" name="firmenlogo" accept="image/*" class="btn-small" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:500;">
                  <input type="checkbox" name="firmenlogo_delete" value="1"> Löschen
                </label>
              </div>
              <button type="button" class="icon-toggle" data-for="firmenlogo"><?= $LEVEL_ICON[$profileVis['vis_firmenlogo'] ?? 'private'] ?></button>
              <input type="hidden" name="vis_firmenlogo" id="vis_firmenlogo" value="<?= h($profileVis['vis_firmenlogo'] ?? 'private') ?>">
            </div>
          </div>

          <!-- Titelbild -->
          <div class="modern-field">
            <label>Titelbild</label>
            <div class="img-preview-wrap">
              <?php if ($titelURL): ?>
                <img src="<?= h($titelURL) ?>" class="img-preview-thumb img-thumb" alt="Titelbild">
              <?php endif; ?>
              <div style="flex:1; display:flex; flex-direction:column; gap:8px;">
                <input type="file" name="titelbild" accept="image/*" class="btn-small" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:500;">
                  <input type="checkbox" name="titelbild_delete" value="1"> Löschen
                </label>
              </div>
              <button type="button" class="icon-toggle" data-for="titelbild"><?= $LEVEL_ICON[$profileVis['vis_titelbild'] ?? 'private'] ?></button>
              <input type="hidden" name="vis_titelbild" id="vis_titelbild" value="<?= h($profileVis['vis_titelbild'] ?? 'private') ?>">
            </div>
          </div>
        </div>

      </div>

      <div style="margin-top:24px; display:flex; justify-content:flex-end;">
        <button class="btn-premium" type="submit" <?= (!$canViewOthers && $viewId!==$sid)?'disabled':'' ?>>
          <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
          Profil speichern
        </button>
        <?php if(!$canViewOthers && $viewId!==$sid): ?>
          <small style="color:#6b7280; margin-left:12px; align-self:center;">(Nur Admins können fremde Profile ändern.)</small>
        <?php endif; ?>
      </div>
    </form>
  </div>


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
      else if(oSel.options.length === 2) { 
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
          opt.textContent = u.name;
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

  // Dynamische Sichtbarkeit Felder nach Business-Type
  const bizTypeSel = document.getElementById('business_type');
  const wrapPhase  = document.getElementById('wrap_mieter_phase');
  const wrapBkp    = document.getElementById('wrap_bkp_id');
  const wrapWohnung = document.getElementById('edit_wohnung_id')?.closest('.modern-field');
  
  function updateConditionalFields() {
    if(!bizTypeSel) return;
    const val = bizTypeSel.value;
    
    if(wrapPhase)  wrapPhase.style.display  = (val === 'mieter') ? 'flex' : 'none';
    if(wrapBkp)    wrapBkp.style.display    = (val === 'handwerker') ? 'flex' : 'none';
    if(wrapWohnung) wrapWohnung.style.display = (val === 'mieter') ? 'flex' : 'none';
  }

  if(bizTypeSel) {
    bizTypeSel.addEventListener('change', updateConditionalFields);
    updateConditionalFields(); // Initial
  }

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
