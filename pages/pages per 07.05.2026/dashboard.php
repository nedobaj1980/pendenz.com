<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
include __DIR__ . '/../includes/header.php';

$me = current_user();
$myPendenzen = db_all("SELECT p.*, pr.name AS projekt_name
                       FROM pendenzen p
                       LEFT JOIN projekte pr ON pr.id=p.projekt_id
                       WHERE p.zustaendig_typ='user' AND p.zustaendig_id=?
                       ORDER BY p.faellig_am ASC LIMIT 10", "i", [$me['id']]);
$projects = db_all("SELECT * FROM projekte ORDER BY name");
?>
<div class="grid cols-3">
  <div class="card">
    <h3>Schnellstart</h3>
    <form action="<?= base_url('pages/pendenzen.php') ?>" method="get">
      <input type="hidden" name="new" value="1">
      <label>Projekt
        <select name="projekt_id">
          <option value="">— bitte wählen —</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= e($p['id']) ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Titel<input name="titel" placeholder="Was ist zu tun?"></label>
      <button class="btn">Neue Pendenz</button>
    </form>
  </div>
  <div class="card">
    <h3>Meine Pendenzen</h3>
    <table class="table">
      <tr><th>Titel</th><th>Projekt</th><th>Fällig</th><th>Status</th></tr>
      <?php foreach ($myPendenzen as $p): ?>
        <tr>
          <td><a href="<?= base_url('pages/pendenzen.php?id='.(int)$p['id']) ?>"><?= e($p['titel']) ?></a></td>
          <td><?= e($p['projekt_name'] ?? '—') ?></td>
          <td><?= e($p['faellig_am']) ?></td>
          <td><span class="status <?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <div class="card">
    <h3>Projekt-Status</h3>
    <p>Einfache Übersicht über aktive Projekte (Demo).</p>
    <ul>
      <?php foreach ($projects as $p): ?>
        <li><a href="<?= base_url('pages/projekt_bearbeiten.php?id='.(int)$p['id']) ?>"><?= e($p['name']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
