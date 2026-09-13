<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

// Helper: Site-Prefix
function site_prefix(): string {
  $sn = $_SERVER['SCRIPT_NAME'] ?? '';
  if ($sn === '/pendenz.com' || $sn === '/pendenz.com/index.php' || strpos($sn, '/pendenz.com/') === 0) return '/pendenz.com/';
  return '/';
}
$PREFIX = site_prefix();

$flash = "";
$link  = null;

// Benutzerliste für Auswahl
$users = $mysqli->query("SELECT id, name, email FROM benutzer ORDER BY name ASC");

// Absenden → Token erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate_or_throw($_POST['csrf'] ?? null);

    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id <= 0) throw new Exception("Bitte einen Benutzer wählen.");

    // Felder definieren (Mapping: POST-Name → DB-Feld)
    $allFields = [
      // Person
      'geburtsdatum'     => 'geburtsdatum',
      'heimatland'       => 'heimatland',
      'position'         => 'position',
      'aufenthaltstitel' => 'aufenthaltstitel',
      'beruf'            => 'beruf',
      'adresse'          => 'adresse',
      'telefonnummer'    => 'telefonnummer',
      // Bilder
      'profilbild'       => 'profilbild',
      'firmenlogo'       => 'firmenlogo',
      'titelbild'        => 'titelbild',
      // Firma
      'firma_name'       => 'firma_name',
      'firma_adresse'    => 'firma_adresse',
      'firma_telefon'    => 'firma_telefon',
      'firma_email'      => 'firma_email',
      'firma_website'    => 'firma_website',
    ];

    $required = array_values(array_intersect(array_keys($allFields), $_POST['required'] ?? []));
    $optional = array_values(array_intersect(array_keys($allFields), $_POST['optional'] ?? []));

    if (empty($required) && empty($optional)) {
      throw new Exception("Bitte mindestens ein Feld auswählen (Pflicht oder Optional).");
    }

    // Ablauf (optional)
    $expires_at = null;
    if (!empty($_POST['expires_days'])) {
      $days = max(1, (int)$_POST['expires_days']);
      $expires_at = (new DateTime("+$days days"))->format('Y-m-d H:i:s');
    }

    // Token generieren
    $token = bin2hex(random_bytes(24));

    $stmt = $mysqli->prepare("INSERT INTO profile_tokens (user_id, token, required_fields, optional_fields, expires_at) VALUES (?,?,?,?,?)");
    $reqJson = json_encode($required, JSON_UNESCAPED_UNICODE);
    $optJson = json_encode($optional, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("issss", $user_id, $token, $reqJson, $optJson, $expires_at);
    $stmt->execute();

    $link = $PREFIX . "pages/profile_fill.php?t=" . urlencode($token);
    $flash = "✅ Link erstellt.";
  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>
<div class="container">
  <header class="hero hero-blue" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Profil-Anfrage-Link erstellen</h1>
    <a class="btn btn-head" href="benutzer_request.php">Neu</a>
  </header>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Felder auswählen</h2>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_input() ?>

      <label for="req_user">Benutzer</label>
      <select name="user_id" id="req_user" required>
        <option value="">– wählen –</option>
        <?php while($u = $users->fetch_assoc()): ?>
          <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name'] . " <" . $u['email'] . ">") ?></option>
        <?php endwhile; ?>
      </select>

      <div class="col-2"><h3>Pflichtfelder</h3></div>
      <div class="col-2" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
        <?php
          $f = [
            'geburtsdatum'=>'Geburtstag','heimatland'=>'Heimatland','position'=>'Position',
            'aufenthaltstitel'=>'Aufenthaltstitel','beruf'=>'Beruf','adresse'=>'Adresse','telefonnummer'=>'Telefon',
            'profilbild'=>'Profilbild','firmenlogo'=>'Firmenlogo','titelbild'=>'Titelbild',
            'firma_name'=>'Firma – Name','firma_adresse'=>'Firma – Adresse','firma_telefon'=>'Firma – Telefon',
            'firma_email'=>'Firma – E-Mail','firma_website'=>'Firma – Website'
          ];
          foreach($f as $key=>$label):
        ?>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="required[]" value="<?= $key ?>"> <span><?= htmlspecialchars($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="col-2"><h3>Optionale Felder</h3></div>
      <div class="col-2" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
        <?php foreach($f as $key=>$label): ?>
          <label style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="optional[]" value="<?= $key ?>"> <span><?= htmlspecialchars($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <label for="expires_days">Ablauf (Tage, optional)</label>
      <input type="number" name="expires_days" id="expires_days" min="1" placeholder="z. B. 7">

      <div class="col-2"><button class="btn" type="submit">Link erzeugen</button></div>
    </form>
  </div>

  <?php if($link): ?>
    <div class="card">
      <h2>Einladungslink</h2>
      <p><input type="text" value="<?= htmlspecialchars($link) ?>" readonly style="width:100%;"></p>
      <p>Schicke diesen Link dem Interessenten (E-Mail, SMS, WhatsApp …).</p>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
