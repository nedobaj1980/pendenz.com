<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mail.php';

$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  if ($email !== '') {
    $u = $mysqli->prepare("SELECT id,name FROM benutzer WHERE email=? LIMIT 1");
    $u->bind_param("s",$email); $u->execute();
    if ($usr = $u->get_result()->fetch_assoc()) {
      $token = bin2hex(random_bytes(32));
      $expires = (new DateTime('+2 hours'))->format('Y-m-d H:i:s');
      $ins = $mysqli->prepare("INSERT INTO user_tokens (benutzer_id, token, typ, expires_at) VALUES (?,?, 'reset', ?)");
      $ins->bind_param("iss",$usr['id'],$token,$expires); $ins->execute();

      $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https':'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . dirname($_SERVER['REQUEST_URI'] ?? '/')
            . '/password_reset.php?token=' . $token;

      $html = "<p>Hallo,</p><p>zum Zurücksetzen deines Passworts klicke hier:</p><p><a href=\"$link\">$link</a></p><p>Gültig 2 Stunden.</p>";
      send_mail($email, "Passwort zurücksetzen", $html);
    }
    $flash = "Wenn die E-Mail existiert, wurde ein Link gesendet.";
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
      <label class="col-2">E-Mail<input type="email" name="email" required></label>
      <div class="col-2"><button class="btn" type="submit">Link senden</button></div>
    </form>
  </div>
</div>
</body>
</html>
