<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login(); require_role('admin');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $name = trim($_POST['name'] ?? '');
  if ($name) {
    db_exec("INSERT INTO teams(name, created_at) VALUES(?,NOW())","s",[$name]);
    flash('Team erstellt','info');
  }
  redirect(base_url('pages/teams.php'));
}

$teams = db_all("SELECT * FROM teams ORDER BY name");
include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <h2>Teams</h2>
  <form method="post" class="form-row"><?= csrf_field() ?><label>Name<input name="name" required></label><button class="btn">Erstellen</button></form>
  <hr>
  <ul><?php foreach ($teams as $t): ?><li><?= e($t['name']) ?></li><?php endforeach; ?></ul>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
