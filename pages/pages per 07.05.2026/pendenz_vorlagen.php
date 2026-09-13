<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_role('projektleiter');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $name = trim($_POST['name'] ?? '');
  $scope = $_POST['scope'] ?? 'projekt';
  $defaults = $_POST['defaults'] ?? '{}';
  if ($name) {
    db_exec("INSERT INTO pendenz_vorlagen(name, scope, defaults_json, created_at) VALUES(?,?,?,NOW())","sss",[$name,$scope,$defaults]);
    flash('Pendenz-Vorlage erstellt','info');
  }
  redirect(base_url('pages/pendenz_vorlagen.php'));
}

$rows = db_all("SELECT * FROM pendenz_vorlagen ORDER BY created_at DESC");
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <h2>Pendenz-Vorlagen</h2>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <label>Name<input name="name" required></label>
    <label>Scope
      <select name="scope"><option value="projekt">Projekt</option><option value="objekt">Objekt</option></select>
    </label>
    <label>Defaults (JSON)<textarea name="defaults" rows="3" placeholder='{"status":"gesendet","sichtbarkeit_typ":"project"}'></textarea></label>
    <button class="btn">Anlegen</button>
  </form>
  <hr>
  <table class="table">
    <tr><th>Name</th><th>Scope</th><th>Defaults</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['scope']) ?></td>
        <td><code><?= e($r['defaults_json']) ?></code></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
