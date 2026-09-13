<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

function invalid($m){ http_response_code(400); echo "<div style='font-family:Arial;padding:20px'>❌ ".htmlspecialchars($m)."</div>"; exit; }

$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9]{64}$/',$token)) invalid('Ungültiger Token');

$stmt = $mysqli->prepare("SELECT ut.*, b.email, b.name FROM user_tokens ut JOIN benutzer b ON b.id=ut.benutzer_id
                          WHERE ut.token=? AND ut.typ='reset' LIMIT 1");
$stmt->bind_param("s",$token); $stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) invalid('Token nicht gefunden');
if ($row['used_at']) invalid('Token bereits benutzt');
if (new DateTime($row['expires_at']) < new DateTime()) invalid('Token abgelaufen');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pw  = $_POST['passwort'] ?? '';
  $pw2 = $_POST['passwort2'] ?? '';
  if (strlen($pw) < 8) invalid('Passwort zu kurz (min 8)');
  if ($pw !== $pw2)    invalid('Passwörter stimmen nicht überein');

  $hash = password_hash($pw, PASSWORD_DEFAULT);
  $upd = $mysqli->prepare("UPDATE benutzer SET passwort=?, must_change_password=0 WHERE id=?");
  $upd->bind_param("si",$hash,$row['benutzer_id']); $upd->execute();

  $use = $mysqli->prepare("UPDATE user_tokens SET used_at=NOW() WHERE id=?");
  $use->bind_param("i",$row['id']); $use->execute();

  // optional direkt einloggen
  set_login_session((int)$row['benutzer_id'], $row['name'] ?: $row['email'], $_SESSION['rolle'] ?? 'benutzer');
  header('Location: ../index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><title>Neues Passwort</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="container">
  <header class="hero hero-orange"><h1>Neues Passwort setzen</h1></header>
  <div class="card">
    <form method="post" class="form-grid">
      <label>Neues Passwort*<input type="password" name="passwort" required></label>
      <label>Wiederholen*<input type="password" name="passwort2" required></label>
      <div class="col-2"><button class="btn" type="submit">Speichern & einloggen</button></div>
    </form>
  </div>
</div>
</body>
</html>
<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/functions.php';

// ----- Layout: Header + Superadmin Navigation -----
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/nav_superadmin.php'; // <<<<<< NUR Superadmin

if (($_SESSION['rolle'] ?? null) !== 'superadmin') {
    die("Zugriff verweigert: Nur Superadmin hat Zugriff auf dieses Tool.");
}
