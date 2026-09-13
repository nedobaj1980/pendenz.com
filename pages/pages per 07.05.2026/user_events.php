<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin','superadmin']);

$uid = (int)($_GET['id'] ?? 0);
$stmt = $mysqli->prepare("SELECT id, email, vorname, nachname FROM benutzer WHERE id=?");
$stmt->bind_param("i", $uid); $stmt->execute();
$u = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$u) die('Benutzer nicht gefunden.');

$ev = $mysqli->prepare("SELECT type, meta, created_at FROM user_events WHERE user_id=? ORDER BY created_at DESC LIMIT 200");
$ev->bind_param("i", $uid); $ev->execute();
$res = $ev->get_result(); $events = $res->fetch_all(MYSQLI_ASSOC); $ev->close();
?>
<!doctype html><meta charset="utf-8"><title>Events – <?= htmlspecialchars($u['email']) ?></title>
<h2>Events für <?= htmlspecialchars(($u['vorname'] ?? '').' '.($u['nachname'] ?? '').' <'.$u['email'].'>') ?></h2>
<table border="1" cellpadding="6" cellspacing="0">
  <tr><th>Datum</th><th>Event</th><th>Details</th></tr>
  <?php foreach ($events as $e): ?>
    <tr>
      <td><?= htmlspecialchars($e['created_at']) ?></td>
      <td><?= htmlspecialchars($e['type']) ?></td>
      <td><pre style="margin:0"><?= htmlspecialchars($e['meta'] ?? '') ?></pre></td>
    </tr>
  <?php endforeach; ?>
</table>
