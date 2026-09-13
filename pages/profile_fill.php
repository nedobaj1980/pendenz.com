<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php'; // handle_upload()
require_once __DIR__ . '/../includes/csrf.php';

// Helper: Site-Prefix
function site_prefix(): string {
  $sn = $_SERVER['SCRIPT_NAME'] ?? '';
  if ($sn === '/pendenz.com' || $sn === '/pendenz.com/index.php' || strpos($sn, '/pendenz.com/') === 0) return '/pendenz.com/';
  return '/';
}
$PREFIX = site_prefix();

$flash = "";
$token = $_GET['t'] ?? '';
if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
  http_response_code(400);
  $flash = "Ungültiger Link.";
  $token = '';
}

$pt = null;   // Datensatz aus profile_tokens
$user = null; // betroffener Benutzer

if ($token) {
  $stmt = $mysqli->prepare("SELECT * FROM profile_tokens WHERE token=? LIMIT 1");
  $stmt->bind_param("s",$token);
  $stmt->execute();
  $pt = $stmt->get_result()->fetch_assoc();

  if (!$pt) {
    http_response_code(404);
    $flash = "Token nicht gefunden oder bereits verwendet.";
  } else {
    if (!empty($pt['used_at'])) {
      $flash = "Dieser Link wurde bereits verwendet.";
      $pt = null;
    } else if (!empty($pt['expires_at']) && (new DateTime($pt['expires_at'])) < new DateTime()) {
      $flash = "Dieser Link ist abgelaufen.";
      $pt = null;
    }
  }

  if ($pt) {
    $uid = (int)$pt['user_id'];
    $u = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
    $u->bind_param("i",$uid);
    $u->execute();
    $user = $u->get_result()->fetch_assoc();
    if (!$user) {
      $flash = "Benutzer existiert nicht mehr.";
      $pt = null;
    }
  }
}

// Mapping Feld → Label & Typ
$fieldMeta = [
  'geburtsdatum'     => ['label'=>'Geburtstag',        'type'=>'date'],
  'heimatland'       => ['label'=>'Heimatland',        'type'=>'text'],
  'position'         => ['label'=>'Position',          'type'=>'text'],
  'aufenthaltstitel' => ['label'=>'Aufenthaltstitel',  'type'=>'text'],
  'beruf'            => ['label'=>'Beruf',             'type'=>'text'],
  'adresse'          => ['label'=>'Adresse',           'type'=>'text'],
  'telefonnummer'    => ['label'=>'Telefon',           'type'=>'text'],

  'profilbild'       => ['label'=>'Profilbild',        'type'=>'file'],
  'firmenlogo'       => ['label'=>'Firmenlogo',        'type'=>'file'],
  'titelbild'        => ['label'=>'Titelbild',         'type'=>'file'],

  'firma_name'       => ['label'=>'Firma – Name',      'type'=>'text'],
  'firma_adresse'    => ['label'=>'Firma – Adresse',   'type'=>'text'],
  'firma_telefon'    => ['label'=>'Firma – Telefon',   'type'=>'text'],
  'firma_email'      => ['label'=>'Firma – E-Mail',    'type'=>'email'],
  'firma_website'    => ['label'=>'Firma – Website',   'type'=>'text'],
];

// POST: speichern
if ($pt && $user && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate_or_throw($_POST['csrf'] ?? null);

    $required = json_decode($pt['required_fields'] ?? '[]', true) ?: [];
    $optional = json_decode($pt['optional_fields'] ?? '[]', true) ?: [];
    $allowed  = array_merge($required, $optional);

    // Validierung Pflichtfelder (nur Nicht-Datei-Felder)
    foreach ($required as $key) {
      if (!isset($fieldMeta[$key])) continue;
      if ($fieldMeta[$key]['type'] === 'file') {
        if (empty($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
          throw new Exception("Bitte Pflichtdatei hochladen: " . $fieldMeta[$key]['label']);
        }
      } else {
        $val = trim($_POST[$key] ?? '');
        if ($val === '') throw new Exception("Bitte Pflichtfeld ausfüllen: " . $fieldMeta[$key]['label']);
      }
    }

    $updates = [];
    $params  = [];
    $types   = '';

    // Bestehende Bildpfade holen (falls überschrieben werden soll)
    $uid = (int)$user['id'];
    $old = $mysqli->prepare("SELECT profilbild, firmenlogo, titelbild FROM benutzer WHERE id=?");
    $old->bind_param("i",$uid);
    $old->execute();
    $oldRow = $old->get_result()->fetch_assoc() ?: [];

    // Durch erlaubte Felder iterieren und in DB mappen
    foreach ($allowed as $key) {
      if (!isset($fieldMeta[$key])) continue;
      $meta = $fieldMeta[$key];

      if ($meta['type'] === 'file') {
        // Upload (in benutzer/<id>_<slug>/)
        $val = handle_upload(
          $key,
          $oldRow[$key] ?? null,
          (int)$user['id'],
          (string)$user['name'],
          ['image/jpeg','image/png','image/gif'],
          ($key === 'titelbild' ? 8_000_000 : 5_000_000),
          ($key === 'titelbild' ? 2400 : 800),
          ($key === 'titelbild' ? 1200 : 800)
        );
        if ($val) {
          $updates[] = "$key=?";
          $params[]  = $val;
          $types    .= 's';
        }
      } else {
        $raw = trim($_POST[$key] ?? '');
        if ($raw !== '') {
          $updates[] = "$key=?";
          $params[]  = $raw;
          $types    .= 's';
        } else if (in_array($key, $required, true)) {
          throw new Exception("Bitte Pflichtfeld ausfüllen: " . $meta['label']);
        }
      }
    }

    if (!empty($updates)) {
      $sql = "UPDATE benutzer SET " . implode(', ', $updates) . " WHERE id=?";
      $stmt = $mysqli->prepare($sql);
      $types .= 'i';
      $params[] = (int)$user['id'];
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
    }

    // Token verbrauchen
    $mysqli->query("UPDATE profile_tokens SET used_at = NOW() WHERE id=" . (int)$pt['id']);

    $flash = "✅ Vielen Dank! Deine Angaben wurden gespeichert.";
    // Nach Speicherung Token invalidieren
    $pt = null;

  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_public.php'; // Absichtlich public – Interessent ist evtl. nicht eingeloggt
?>
<div class="container">
  <header class="hero hero-teal" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Profilangaben</h1>
    <div></div>
  </header>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <?php if($pt && $user): ?>
    <?php
      $required = json_decode($pt['required_fields'] ?? '[]', true) ?: [];
      $optional = json_decode($pt['optional_fields'] ?? '[]', true) ?: [];
      $showFields = array_merge($required, $optional);
    ?>
    <div class="card">
      <h2><?= htmlspecialchars($user['name']) ?> – bitte fülle folgende Felder aus</h2>
      <form method="post" enctype="multipart/form-data" class="form-grid" autocomplete="on">
        <?= csrf_input() ?>
        <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">

        <?php foreach ($showFields as $key): if(!isset($fieldMeta[$key])) continue; $meta = $fieldMeta[$key]; ?>
          <?php
            $label = $meta['label'];
            $isReq = in_array($key, $required, true);
            $value = $user[$key] ?? '';
          ?>
          <?php if ($meta['type'] === 'file'): ?>
            <label for="f_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?> <?= $isReq?'<span style="color:#b00020">*</span>':'' ?></label>
            <input type="file" name="<?= htmlspecialchars($key) ?>" id="f_<?= htmlspecialchars($key) ?>" accept="image/*" <?= $isReq?'required':'' ?>>
            <?php if(!empty($value)): ?>
              <div style="margin-top:6px;"><img src="<?= htmlspecialchars($value) ?>" class="thumb" alt=""></div>
            <?php endif; ?>
          <?php else: ?>
            <label for="f_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?> <?= $isReq?'<span style="color:#b00020">*</span>':'' ?></label>
            <input
              type="<?= htmlspecialchars($meta['type']) ?>"
              name="<?= htmlspecialchars($key) ?>"
              id="f_<?= htmlspecialchars($key) ?>"
              value="<?= htmlspecialchars($value) ?>"
              <?= $isReq?'required':'' ?>
            >
          <?php endif; ?>
        <?php endforeach; ?>

        <div class="col-2"><button class="btn" type="submit">Senden</button></div>
      </form>
      <?php if(!empty($pt['expires_at'])): ?>
        <p style="font-size:12px;color:#6b7280;margin-top:10px;">Hinweis: Link gültig bis <?= htmlspecialchars($pt['expires_at']) ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <p>Kein aktiver Profil-Link. Bitte wende dich an den Absender.</p>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
