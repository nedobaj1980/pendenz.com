<?php
// C:\xampp\htdocs\pendenz.com\tools\check_assets.php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$base = '/pendenz.com';

$files = [
  'assets/brand/favicon-16.png',
  'assets/brand/favicon-32.png',
  'assets/brand/apple-touch-icon.png',
  'assets/brand/logo-mark.png',
  'assets/site.webmanifest',
];

?><!doctype html>
<html lang="de"><meta charset="utf-8"><title>Assets Check</title>
<body style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;max-width:900px;margin:24px auto;padding:16px;">
<h1>Brand Assets Check</h1>
<p>Root: <code><?= htmlspecialchars($root) ?></code></p>
<table border="1" cellpadding="8" cellspacing="0">
  <tr><th>Datei</th><th>Existiert</th><th>Realpath</th><th>URL</th><th>Preview</th></tr>
  <?php foreach ($files as $rel): 
    $abs = $root . '/' . $rel;
    $ok  = is_file($abs);
    $url = $base . '/' . $rel;
  ?>
  <tr>
    <td><code><?= htmlspecialchars($rel) ?></code></td>
    <td style="color:<?= $ok?'green':'red' ?>"><?= $ok ? 'JA' : 'NEIN' ?></td>
    <td><code><?= htmlspecialchars($ok ? realpath($abs) : '—') ?></code></td>
    <td><a target="_blank" href="<?= htmlspecialchars($url) ?>"><?= htmlspecialchars($url) ?></a></td>
    <td>
      <?php if ($ok && preg_match('~\.png$~i', $rel)): ?>
        <img src="<?= htmlspecialchars($url) ?>" alt="" style="max-height:64px">
      <?php else: ?>
        —
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</body></html>
