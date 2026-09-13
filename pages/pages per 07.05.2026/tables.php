<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login(); require_role('projektleiter');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $name = trim($_POST['name'] ?? '');
  $schema = $_POST['schema_json'] ?? '[]';
  db_exec("INSERT INTO custom_tables(name, schema_json, created_by, created_at) VALUES(?,?,?,NOW())",
          "ssi", [$name,$schema,(int)current_user()['id']]);
  flash('Tabelle erstellt','info');
  redirect(base_url('pages/tables.php'));
}
$rows = db_all("SELECT * FROM custom_tables ORDER BY created_at DESC");

include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <h2>Eigene Tabellen</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label>Name<input name="name" required placeholder="Mängelliste, Abnahmeprotokoll…"></label>
    <label>Schema (JSON)<textarea name="schema_json" rows="4" placeholder='[{"key":"titel","label":"Titel","type":"text"},{"key":"menge","label":"Menge","type":"number"}]'></textarea></label>
    <button class="btn">Anlegen</button>
  </form>
  <hr>
  <table class="table">
    <tr><th>Name</th><th>Erstellt</th><th>Aktionen</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['created_at']) ?></td>
        <td><a href="<?= base_url('pages/table_rows.php?id='.(int)$r['id']) ?>">Öffnen</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
