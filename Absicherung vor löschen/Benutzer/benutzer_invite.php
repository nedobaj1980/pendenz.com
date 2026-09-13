<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); }

$user_id = (int)($_POST['user_id'] ?? 0);
if ($user_id <= 0) exit('Ungültige Benutzer-ID');

// optional: aus Formular
$project_id  = isset($_POST['project_id']) ? (int)$_POST['project_id'] : null;
$target_role = $_POST['target_role'] ?? null;

$stmt = $mysqli->prepare("SELECT id, email, vorname, nachname, invite_status, invited_at FROM benutzer WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u || empty($u['email'])) { $_SESSION['flash'] = "Benutzer oder E-Mail fehlt."; header("Location: ".APP_URL_BASE."/pages/benutzer.php"); exit; }

// Rate-Limit
if (!empty($u['invited_at'])) {
    $last = new DateTime($u['invited_at']);
    $min  = (clone $last)->modify('+'.(defined('INVITE_RESEND_MIN_HOURS') ? INVITE_RESEND_MIN_HOURS : 12).' hours');
    if (new DateTime() < $min) {
        $_SESSION['flash'] = "Zu häufig eingeladen. Erneut möglich ab: ".$min->format('d.m.Y H:i');
        header("Location: ".APP_URL_BASE."/pages/benutzer.php"); exit;
    }
}

$token  = bin2hex(random_bytes(32));
$hash   = hash('sha256', $token);
$expiry = (new DateTime('+'.(defined('INVITE_EXPIRY_DAYS') ? INVITE_EXPIRY_DAYS : 7).' days'))->format('Y-m-d H:i:s');
$now    = (new DateTime())->format('Y-m-d H:i:s');
$invited_by = current_user_id() ?: null;

// speichern
$upd = $mysqli->prepare("UPDATE benutzer SET invite_token_hash=?, invite_expires=?, invite_status='invited', invited_by=?, invited_at=? WHERE id=?");
$upd->bind_param("ssisi", $hash, $expiry, $invited_by, $now, $user_id);
$upd->execute(); $upd->close();

// optional: Projekt vormerken
if ($project_id) {
    $chk = $mysqli->prepare("SELECT 1 FROM projekt_mitglieder WHERE projekt_id=? AND benutzer_id=? LIMIT 1");
    $chk->bind_param("ii", $project_id, $user_id); $chk->execute();
    $exists = (bool)$chk->get_result()->fetch_row(); $chk->close();
    if (!$exists) {
        $role = 'member';
        $ins = $mysqli->prepare("INSERT INTO projekt_mitglieder (projekt_id, benutzer_id, rolle, erstellt_at) VALUES (?,?,?,NOW())");
        $ins->bind_param("iis", $project_id, $user_id, $role);
        @$ins->execute(); @$ins->close();
    }
}
// optional: Zielrolle-Hinweis
if ($target_role && in_array($target_role, ['benutzer','admin'], true)) {
    $meta = json_encode(['suggested_role' => $target_role], JSON_UNESCAPED_UNICODE);
    $ev = $mysqli->prepare("INSERT INTO user_events (user_id, type, meta) VALUES (?, 'invite_with_role_hint', ?)");
    $ev->bind_param("is", $user_id, $meta); $ev->execute(); $ev->close();
}

// Event loggen
$ev2 = $mysqli->prepare("INSERT INTO user_events (user_id, type, meta) VALUES (?, 'invite_sent', JSON_OBJECT('invited_by', ?, 'expires', ?))");
$ev2->bind_param("iis", $user_id, $invited_by, $expiry); $ev2->execute(); $ev2->close();

// E-Mail
$display_name = trim(($u['vorname'] ?? '').' '.($u['nachname'] ?? '')) ?: $u['email'];
$accept_url   = APP_URL_BASE . "/pages/invite_accept.php?token=" . $token;
$open_pixel   = APP_URL_BASE . "/pages/invite_open.php?uid={$user_id}&h={$hash}";

$html = render_email_template($EMAIL_TEMPLATE_INVITE, [
    'display_name' => htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'),
    'accept_url'   => $accept_url,
    'expires_at'   => (new DateTime($expiry))->format('d.m.Y H:i'),
    'brand_logo'   => APP_URL_BASE . "/assets/logo.png",
    'open_pixel'   => $open_pixel
]);

$subject = "Einladung zu pendenz.com";
$ok = send_mail_html($u['email'], $subject, $html, ['x_category'=>'invite']);

$_SESSION['flash'] = $ok ? "Einladung verschickt." : "Einladung gespeichert, aber E-Mail-Versand fehlgeschlagen.";
header("Location: ".APP_URL_BASE."/pages/benutzer.php");
exit;
