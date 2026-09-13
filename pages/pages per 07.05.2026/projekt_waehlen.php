<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/header.php';

$back = $_GET['back'] ?? url('index_admin.php');

$res = $mysqli->query("SELECT id, name FROM projekte ORDER BY name");
$rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>
<div class="container" style="max-width:700px;margin:24px auto;">
  <h1>Projekt wählen</h1>
  <p>Bitte ein Projekt auswählen, um fortzufahren.</p>
  <ul class="list" style="list-style:none;padding:0;margin:16px 0;">
    <?php foreach ($rows as $r): ?>
      <li style="margin:8px 0;">
        <a class="btn" href="<?= e($back . (strpos($back,'?')===false?'?':'&') . 'projekt_id='.(int)$r['id']) ?>">
          <?= e($r['name']) ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <p><a href="<?= e(url('index_admin.php')) ?>">Zurück</a></p>
</div>
