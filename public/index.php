<?php
require_once __DIR__ . '/../config.php';
$u = current_user();
include __DIR__ . '/../includes/header.php';
?>
<div class="grid cols-3">
  <div class="card">
    <h2>Was ist pendenz.com?</h2>
    <p>Ein schlankes System für Pendenzen, Projekte, Teams und Vorlagen – schnell, nachvollziehbar, offline-fähig, mit JSON-Flexfeldern.</p>
    <p><a class="btn" href="<?= base_url('pages/login.php') ?>">Zum Login</a></p>
  </div>
  <div class="card">
    <h3>Features</h3>
    <ul>
      <li>Pendenzen mit Status-Workflow</li>
      <li>Projekt/Team/Benutzer Sichtbarkeit</li>
      <li>Ordner-Vorlagen mit Hierarchie (nummeriert)</li>
      <li>Benachrichtigungen (Stub)</li>
      <li>Eigene Tabellen (z.B. Mängelliste)</li>
    </ul>
  </div>
  <div class="card">
    <h3>Öffentliche Projekte</h3>
    <p>Hier können später freigegebene Projekte gelistet werden.</p>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
