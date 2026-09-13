<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mail.php';

$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  if ($email !== '') {
    $cleanPhone = preg_replace('/[^0-9]+/', '', $email);
    $u = $mysqli->prepare("
      SELECT id, name, email, telefonnummer, kontaktweg 
      FROM benutzer 
      WHERE email=? 
         OR (telefonnummer=? AND telefonnummer<>'')
         OR (REPLACE(REPLACE(REPLACE(REPLACE(telefonnummer, ' ', ''), '-', ''), '+', ''), '/', '') = ? AND telefonnummer <> '')
      LIMIT 1
    ");
    $u->bind_param("sss", $email, $email, $cleanPhone); 
    $u->execute();
    if ($usr = $u->get_result()->fetch_assoc()) {
      $token = bin2hex(random_bytes(32));
      $expires = (new DateTime('+2 hours'))->format('Y-m-d H:i:s');
      $ins = $mysqli->prepare("INSERT INTO user_tokens (benutzer_id, token, typ, expires_at) VALUES (?,?, 'reset', ?)");
      $ins->bind_param("iss",$usr['id'],$token,$expires); $ins->execute();

      $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https':'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . dirname($_SERVER['REQUEST_URI'] ?? '/')
            . '/password_reset.php?token=' . $token;

      // Check if user is phone-only or prefers SMS
      $isPhoneOnly = (strpos($usr['email'] ?? '', '@no-email.pendenz.com') !== false);
      $prefersSms = (($usr['kontaktweg'] ?? '') === 'sms' || ($usr['kontaktweg'] ?? '') === 'telefon');

      if ($isPhoneOnly || $prefersSms) {
        // Send SMS
        require_once __DIR__ . '/../includes/mail.php'; // which has send_sms
        $message = "Hallo " . $usr['name'] . ", zum Zurücksetzen deines Passworts öffne diesen Link: " . $link;
        if (send_sms($usr['telefonnummer'], $message)) {
          $flash = "Der Link zum Zurücksetzen wurde per SMS an Ihre Telefonnummer gesendet.";
        } else {
          $flash = "Fehler beim Senden der SMS. Bitte Administrator kontaktieren.";
        }
      } else {
        // Send Email
        $html = "<p>Hallo " . htmlspecialchars($usr['name']) . ",</p><p>zum Zurücksetzen deines Passworts klicke hier:</p><p><a href=\"$link\">$link</a></p><p>Gültig 2 Stunden.</p>";
        send_mail($usr['email'], "Passwort zurücksetzen", $html);
        $flash = "Wenn die E-Mail existiert, wurde ein Link gesendet.";
      }
    } else {
      $flash = "Benutzer nicht gefunden.";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><title>Passwort zurücksetzen</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
  <header class="hero hero-blue"><h1>Passwort zurücksetzen</h1></header>
  <?php if($flash): ?><div class="card"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <div class="card">
    <form method="post" class="form-grid">
      <label class="col-2">E-Mail oder Telefonnummer<input type="text" name="email" placeholder="z.B. +41791234567 oder E-Mail" required></label>
      <div class="col-2"><button class="btn" type="submit">Link senden / SMS anfordern</button></div>
    </form>
  </div>
</div>
</body>
</html>
