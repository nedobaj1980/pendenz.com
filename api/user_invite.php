<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mail.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit('Nicht eingeloggt'); }

$input = json_decode(file_get_contents('php://input'), true);
$uid   = (int)($input['user_id'] ?? 0);

if ($uid <= 0) { http_response_code(400); exit('user_id fehlt'); }

// Berechtigung: superadmin alles; admin/benutzer dürfen i.d.R. Gäste (und Admin evtl. Benutzer)
$actorRole = $_SESSION['rolle'] ?? 'gast';
if (!in_array($actorRole, ['superadmin','admin','benutzer'], true)) {
  http_response_code(403); exit('Keine Berechtigung');
}

$u = $mysqli->prepare("SELECT id, email, name FROM benutzer WHERE id=? LIMIT 1");
$u->bind_param("i", $uid); $u->execute();
$user = $u->get_result()->fetch_assoc();
if (!$user || empty($user['email'])) { http_response_code(404); exit('Benutzer/Email nicht gefunden'); }

// Token erzeugen
$token = bin2hex(random_bytes(32));
$expires = (new DateTime('+48 hours'))->format('Y-m-d H:i:s');

$ins = $mysqli->prepare("INSERT INTO user_tokens (benutzer_id, token, typ, expires_at) VALUES (?,?, 'invite', ?)");
$ins->bind_param("iss", $user['id'], $token, $expires);
$ins->execute();

$link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https':'http')
      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
      . dirname($_SERVER['REQUEST_URI'] ?? '/')
      . '/../pages/invite_accept.php?token=' . $token;

// Mail senden (DEV -> mail_log.html)
$subject = "Einladung zu pendenz.com";
$html = "<p>Hallo ".htmlspecialchars($user['name'] ?: $user['email']).",</p>
<p>du wurdest zu <strong>pendenz.com</strong> eingeladen. Klicke auf den Link, um dein Passwort zu setzen:</p>
<p><a href=\"$link\">$link</a></p>
<p>Der Link ist 48 Stunden gültig.</p>";

send_mail($user['email'], $subject, $html);

header('Content-Type: application/json');
echo json_encode(['ok'=>true, 'msg'=>'Einladung versendet', 'link_dev'=>$link]);
