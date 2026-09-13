<?php
// Nur Kommandozeile
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/email_templates.php';

// 1) Abgelaufene Einladungen markieren
$mysqli->query("
  UPDATE benutzer
  SET invite_status='expired'
  WHERE invite_expires IS NOT NULL
    AND invite_expires < NOW()
    AND invite_status IN ('invited','opened')
");
if (function_exists('app_log')) app_log('[cron] invite expiry run executed');

// 2) Erinnerungen senden nach X Tagen
$reminderDays = 3;
$sql = "
SELECT id, email, vorname, nachname, invite_token_hash
FROM benutzer
WHERE invite_status IN ('invited','opened')
  AND invited_at IS NOT NULL
  AND invited_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $reminderDays);
$stmt->execute();
$res = $stmt->get_result();

while ($u = $res->fetch_assoc()) {
    if (empty($u['invite_token_hash'])) continue;

    $newToken = bin2hex(random_bytes(32));
    $newHash  = hash('sha256', $newToken);
    $newExp   = (new DateTime('+'.(defined('INVITE_EXPIRY_DAYS') ? INVITE_EXPIRY_DAYS : 7).' days'))->format('Y-m-d H:i:s');

    $uId = (int)$u['id'];
    $upd = $mysqli->prepare("UPDATE benutzer SET invite_token_hash=?, invite_expires=?, invited_at=NOW(), invite_status='invited' WHERE id=?");
    $upd->bind_param("ssi", $newHash, $newExp, $uId);
    $upd->execute(); $upd->close();

    $accept_url = APP_URL_BASE . "/pages/invite_accept.php?token=" . $newToken;
    $display_name = trim(($u['vorname'] ?? '').' '.($u['nachname'] ?? '')) ?: $u['email'];
    $open_pixel   = APP_URL_BASE . "/pages/invite_open.php?uid={$uId}&h={$newHash}";

    global $EMAIL_TEMPLATE_INVITE;
    $html = render_email_template($EMAIL_TEMPLATE_INVITE, [
        'display_name' => htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'),
        'accept_url'   => $accept_url,
        'expires_at'   => (new DateTime($newExp))->format('d.m.Y H:i'),
        'brand_logo'   => APP_URL_BASE . "/assets/logo.png",
        'open_pixel'   => $open_pixel
    ]);

    $ok = send_mail_html($u['email'], "Erinnerung: Einladung zu pendenz.com", $html, ['x_category'=>'invite_reminder']);
    if ($ok) {
        $ev = $mysqli->prepare("INSERT INTO user_events (user_id, type) VALUES (?, 'invite_resent')");
        $ev->bind_param("i", $uId); $ev->execute(); $ev->close();
        if (function_exists('app_log')) app_log("[cron] invite reminder sent to {$u['email']}");
    } else {
        if (function_exists('app_log')) app_log("[cron] invite reminder FAILED for {$u['email']}");
    }
}
