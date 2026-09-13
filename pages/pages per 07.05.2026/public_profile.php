<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Keine Auth nötig – öffentlich
require_once __DIR__ . '/../config.php';

// Header + eine „öffentliche“ Navi (falls vorhanden)
require_once __DIR__ . '/../includes/header.php';
$navPublic = __DIR__ . '/../includes/nav_public.php';
if (is_file($navPublic)) include $navPublic;

/* ===== Helper: Pfad/URL ===== */
function site_prefix(): string {
  $sn = $_SERVER['SCRIPT_NAME'] ?? '';
  if ($sn === '/pendenz.com' || $sn === '/pendenz.com/index.php' || strpos($sn, '/pendenz.com/') === 0) {
    return '/pendenz.com/';
  }
  return '/';
}
$PREFIX = site_prefix();

/* ===== Helper: best_image_url (lokal, autark) ===== */
function best_image_url_public(?string $dbVal): ?string {
  if (!$dbVal) return null;
  $v = ltrim($dbVal, '/');
  if (!str_starts_with($v, 'uploads/')) $v = 'uploads/' . $v;

  $abs = realpath(__DIR__ . "/../" . $v);
  if (!$abs || !is_file($abs)) return null;

  $pi = pathinfo($abs);
  $dir = $pi['dirname'];
  $fn  = $pi['filename'];
  $ext = strtolower($pi['extension'] ?? '');

  $thumbAbs = $dir . DIRECTORY_SEPARATOR . $fn . '_thumb.' . $ext;
  $webpAbs  = $dir . DIRECTORY_SEPARATOR . $fn . '.webp';

  $choose = $abs;
  if (is_file($webpAbs))  $choose = $webpAbs;
  if (is_file($thumbAbs)) $choose = $thumbAbs;

  $root = realpath(__DIR__ . '/..');
  $chooseRel = str_replace($root, '', $choose);
  $chooseRel = str_replace(DIRECTORY_SEPARATOR, '/', $chooseRel);
  if (!str_starts_with($chooseRel, '/')) $chooseRel = '/' . $chooseRel;

  $url = site_prefix() . ltrim($chooseRel, '/');
  $ts  = @filemtime($choose);
  if ($ts) $url .= (strpos($url,'?')===false ? '?' : '&') . 'v=' . $ts;
  return $url;
}

/* ===== Sichtbarkeitslogik ===== */
function field_is_public(array $vis, string $field): bool {
  $val = $vis[$field] ?? 'private';
  return $val === 'public';
}

/* ===== Benutzerdaten laden ===== */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo '<div class="container"><div class="card">Ungültige Anfrage.</div></div>';
  include __DIR__ . '/../includes/footer.php';
  exit;
}

$stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
  http_response_code(404);
  echo '<div class="container"><div class="card">Profil nicht gefunden.</div></div>';
  include __DIR__ . '/../includes/footer.php';
  exit;
}

/* ===== Sichtbarkeit/Anzeige vorbereiten ===== */
$vis = [];
if (!empty($user['profile_vis'])) {
  $tmp = json_decode($user['profile_vis'], true);
  if (is_array($tmp)) $vis = $tmp;
}
$publicSrc = ($user['public_image_source'] ?? 'profil') === 'logo' ? 'logo' : 'profil';

$showTitel = (int)($user['show_titelbild_public'] ?? 1) === 1;
$showProf  = (int)($user['show_profilbild_public'] ?? 1) === 1;
$showLogo  = (int)($user['show_firmenlogo_public'] ?? 1) === 1;

$titelURL = $showTitel ? best_image_url_public($user['titelbild'] ?? null) : null;

$profilURL = ($showProf ? best_image_url_public($user['profilbild'] ?? null) : null);
$logoURL   = ($showLogo ? best_image_url_public($user['firmenlogo'] ?? null) : null);

/* ===== Render ===== */
?>
<div class="container">
  <header class="hero hero-teal" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Öffentliches Profil</h1>
    <a class="btn btn-head" href="<?= htmlspecialchars($PREFIX) ?>">Start</a>
  </header>

  <div class="card">
    <?php if($titelURL): ?>
      <div style="margin:-8px -8px 16px -8px; overflow:hidden; border-radius:8px;">
        <img src="<?= htmlspecialchars($titelURL) ?>" alt="Titelbild" style="width:100%; max-height:240px; object-fit:cover;">
      </div>
    <?php endif; ?>

    <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
      <!-- Linke Spalte: Bild laut public_image_source -->
      <div style="flex:0 0 180px;">
        <?php
          $publicImg = ($publicSrc === 'logo') ? ($logoURL ?: $profilURL) : ($profilURL ?: $logoURL);
        ?>
        <?php if($publicImg): ?>
          <img src="<?= htmlspecialchars($publicImg) ?>" alt="Profilbild" style="width:160px;height:160px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <div style="width:160px;height:160px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:56px;">👤</div>
        <?php endif; ?>
      </div>

      <!-- Rechte Spalte: Daten -->
      <div style="flex:1; min-width:260px;">
        <h2 style="margin:0 0 6px 0;"><?= htmlspecialchars($user['name'] ?? 'Profil') ?></h2>
        <?php if(!empty($user['firma_name'])): ?>
          <div style="color:#6b7280;"><?= htmlspecialchars($user['firma_name']) ?></div>
        <?php endif; ?>

        <div style="margin-top:12px; display:grid; grid-template-columns: 160px 1fr; gap:8px 16px;">
          <?php if(field_is_public($vis,'position') && !empty($user['position'])): ?>
            <div><strong>Position</strong></div><div><?= htmlspecialchars($user['position']) ?></div>
          <?php endif; ?>
          <?php if(field_is_public($vis,'beruf') && !empty($user['beruf'])): ?>
            <div><strong>Beruf</strong></div><div><?= htmlspecialchars($user['beruf']) ?></div>
          <?php endif; ?>
          <?php if(field_is_public($vis,'heimatland') && !empty($user['heimatland'])): ?>
            <div><strong>Heimatland</strong></div><div><?= htmlspecialchars($user['heimatland']) ?></div>
          <?php endif; ?>
          <?php if(field_is_public($vis,'aufenthaltstitel') && !empty($user['aufenthaltstitel'])): ?>
            <div><strong>Aufenthaltstitel</strong></div><div><?= htmlspecialchars($user['aufenthaltstitel']) ?></div>
          <?php endif; ?>
          <?php if(field_is_public($vis,'geburtsdatum') && !empty($user['geburtsdatum'])): ?>
            <div><strong>Geburtstag</strong></div><div><?= htmlspecialchars($user['geburtsdatum']) ?></div>
          <?php endif; ?>
        </div>

        <?php if(!empty($user['firma_name']) || !empty($user['firma_adresse']) || !empty($user['firma_telefon']) || !empty($user['firma_email']) || !empty($user['firma_website'])): ?>
          <h3 style="margin-top:18px;">Firma</h3>
          <div style="display:flex; gap:16px; align-items:flex-start;">
            <div style="flex:1;">
              <?php if(!empty($user['firma_name'])): ?><div><strong><?= htmlspecialchars($user['firma_name']) ?></strong></div><?php endif; ?>
              <?php if(!empty($user['firma_adresse'])): ?><div><?= htmlspecialchars($user['firma_adresse']) ?></div><?php endif; ?>
              <?php if(!empty($user['firma_telefon'])): ?><div>☎ <?= htmlspecialchars($user['firma_telefon']) ?></div><?php endif; ?>
              <?php if(!empty($user['firma_email'])): ?><div>✉ <?= htmlspecialchars($user['firma_email']) ?></div><?php endif; ?>
              <?php if(!empty($user['firma_website'])): ?>
                <div>🌐 <a href="<?= htmlspecialchars($user['firma_website']) ?>" rel="noopener" target="_blank"><?= htmlspecialchars($user['firma_website']) ?></a></div>
              <?php endif; ?>
            </div>
            <div style="text-align:right;">
              <?php if($logoURL): ?>
                <img src="<?= htmlspecialchars($logoURL) ?>" alt="Logo" style="max-width:140px; border-radius:6px;">
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
