<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
$auth = __DIR__ . '/../includes/auth.php'; if (file_exists($auth)) require_once $auth;

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if (!$token) { http_response_code(400); exit('Token fehlt.'); }

$hash = hash('sha256', $token);
$stmt = $mysqli->prepare("SELECT id, email, vorname, nachname, rolle, invite_status FROM benutzer WHERE invite_token_hash=? AND (invite_expires IS NULL OR invite_expires >= NOW()) LIMIT 1");
$stmt->bind_param("s", $hash);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { http_response_code(410); exit('Dieser Einladungslink ist ungültig oder abgelaufen.'); }

function strong_pw(?string $pw): bool {
    if (!$pw || strlen($pw) < 8) return false;
    $d = preg_match('/\d/', $pw);
    $l = preg_match('/[a-z]/', $pw);
    $u = preg_match('/[A-Z]/', $pw);
    return ($d + $l + $u) >= 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($user['invite_status'] !== 'accepted') {
        $upd = $mysqli->prepare("UPDATE benutzer SET invite_status='opened', invite_last_opened_at=NOW() WHERE id=?");
        $upd->bind_param("i", $user['id']); $upd->execute(); $upd->close();
        $ev = $mysqli->prepare("INSERT INTO user_events (user_id, type) VALUES (?, 'invite_opened')");
        $ev->bind_param("i", $user['id']); $ev->execute(); $ev->close();
    }
    ?>
    <!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Konto einrichten</title>
    <style>body{font-family:Arial;margin:24px}label{display:block;margin:12px 0 6px}input{padding:10px;width:320px;max-width:100%}button{padding:10px 16px;margin-top:16px}.hint{color:#666;font-size:12px}</style>
    </head><body>
      <h1>Konto einrichten</h1>
      <form method="post">
        <input type="hidden" name="token" value="<?=htmlspecialchars($token)?>">
        <label>Neues Passwort</label>
        <input type="password" name="pass1" required>
        <div class="hint">Mind. 8 Zeichen, und mind. 2 von 3: Zahl / Gross- / Kleinbuchstaben.</div>
        <label>Passwort wiederholen</label>
        <input type="password" name="pass2" required>
        <button type="submit">Speichern & weiter</button>
      </form>
    </body></html>
    <?php
    exit;
}

$pass1 = $_POST['pass1'] ?? '';
$pass2 = $_POST['pass2'] ?? '';
if ($pass1 !== $pass2 || !strong_pw($pass1)) { exit('Passwort ungültig oder stimmt nicht überein.'); }

$pwd = password_hash($pass1, PASSWORD_DEFAULT);
$uid = (int)$user['id'];

$upd = $mysqli->prepare("UPDATE benutzer SET passwort_hash=?, invite_status='accepted', invite_token_hash=NULL, invite_expires=NULL, first_login_at=IFNULL(first_login_at, NOW()) WHERE id=?");
$upd->bind_param("si", $pwd, $uid); $upd->execute(); $upd->close();

$ev = $mysqli->prepare("INSERT INTO user_events (user_id, type) VALUES (?, 'invite_accepted')");
$ev->bind_param("i", $uid); $ev->execute(); $ev->close();

/* Optional Auto-Login */
if (function_exists('finalize_successful_login')) {
    $name = trim(($user['vorname'] ?? '').' '.($user['nachname'] ?? '')) ?: ($user['email'] ?? 'Benutzer');
    finalize_successful_login($mysqli, $uid, ($user['rolle'] ?? 'benutzer'), $name, $user['email'] ?? null);
}

$_SESSION['flash'] = "Konto erstellt. Bitte anmelden.";
header("Location: " . APP_URL_BASE . "/login.php");
exit;
