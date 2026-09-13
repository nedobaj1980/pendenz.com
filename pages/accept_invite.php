<?php
// pages/accept_invite.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf_core.php'; // wir nutzen den 'invite'-Namespace

function csrf_input_invite(): string { return csrf_input_ns('invite', 'csrf_invite'); }
function csrf_check_invite(?string $t): void { csrf_validate_or_throw_ns('invite', $t); }

$flash = '';
$mode  = 'form';
$token = $_GET['token'] ?? ($_POST['token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
  http_response_code(400);
  echo "Ungültiger Token."; exit;
}

// DB holen
$st = $mysqli->prepare("SELECT ui.id, ui.user_id, ui.expires_at, ui.used_at, b.email, b.name
                        FROM user_invites ui
                        JOIN benutzer b ON b.id = ui.user_id
                        WHERE ui.token=? LIMIT 1");
$st->bind_param("s", $token);
$st->execute();
$inv = $st->get_result()->fetch_assoc();
$st->close();

if (!$inv) { http_response_code(404); echo "Einladung nicht gefunden."; exit; }

$now = new DateTime();
$exp = new DateTime($inv['expires_at']);
if ($inv['used_at']) { $mode='used'; }
elseif ($now > $exp) { $mode='expired'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode==='form') {
  try {
    csrf_check_invite($_POST['csrf_invite'] ?? null);
    $pw1 = (string)($_POST['pw1'] ?? '');
    $pw2 = (string)($_POST['pw2'] ?? '');
    if (strlen($pw1) < 8) throw new Exception("Passwort muss mindestens 8 Zeichen haben.");
    if ($pw1 !== $pw2) throw new Exception("Passwörter stimmen nicht überein.");

    $hash = password_hash($pw1, PASSWORD_DEFAULT);
    // Passwort speichern & Invite verbrauchen
    $st = $mysqli->prepare("UPDATE benutzer SET passwort_hash=?, aktiviert_am=NOW() WHERE id=?");
    $st->bind_param("si", $hash, $inv['user_id']); $st->execute(); $st->close();

    $st = $mysqli->prepare("UPDATE user_invites SET used_at=NOW() WHERE id=?");
    $st->bind_param("i", $inv['id']); $st->execute(); $st->close();

    // Optional: Auto-Login
    $_SESSION['user_id'] = (int)$inv['user_id'];
    $flash = "✅ Passwort gesetzt. Du bist jetzt eingeloggt.";
    $mode  = 'done';
  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Einladung annehmen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f5f7fb;margin:0;padding:20px}
    .card{max-width:520px;margin:20px auto;background:#fff;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:20px}
    .title{margin:0 0 8px 0}
    .hint{color:#555;font-size:14px;margin:0 0 16px 0}
    .row{display:flex;flex-direction:column;gap:6px;margin:10px 0}
    input[type=password]{padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:16px}
    .btn{padding:10px 14px;border:0;border-radius:10px;background:#0a2a6e;color:#fff;cursor:pointer}
  </style>
</head>
<body>
  <div class="card">
    <h1 class="title">Einladung annehmen</h1>
    <p class="hint">Für: <strong><?= htmlspecialchars($inv['email']) ?></strong> (<?= htmlspecialchars($inv['name'] ?: '') ?>)</p>

    <?php if ($flash): ?>
      <div style="background:#eefbf4;color:#0f5132;border:1px solid #badbcc;border-radius:8px;padding:10px;margin-bottom:12px;">
        <?= $flash ?>
      </div>
    <?php endif; ?>

    <?php if ($mode==='expired'): ?>
      <p>❌ Diese Einladung ist abgelaufen. Bitte lass dir eine neue Einladung schicken.</p>
    <?php elseif ($mode==='used'): ?>
      <p>ℹ️ Diese Einladung wurde bereits verwendet. Du kannst dich <a href="<?= htmlspecialchars(rtrim($PREFIX,'/')) ?>/pages/login.php">hier anmelden</a>.</p>
    <?php elseif ($mode==='done'): ?>
      <p>Alles erledigt. <a href="<?= htmlspecialchars(rtrim($PREFIX,'/')) ?>/index.php">Weiter</a></p>
    <?php else: ?>
      <form method="post" autocomplete="new-password">
        <?= csrf_input_invite() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="row">
          <label for="pw1">Neues Passwort</label>
          <input id="pw1" name="pw1" type="password" required minlength="8">
        </div>
        <div class="row">
          <label for="pw2">Passwort wiederholen</label>
          <input id="pw2" name="pw2" type="password" required minlength="8">
        </div>
        <div class="row">
          <button class="btn" type="submit">Passwort setzen & anmelden</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
