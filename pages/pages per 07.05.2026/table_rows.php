<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login(); require_role('projektleiter');

$id = (int)($_GET['id'] ?? 0);
$tbl = db_one("SELECT * FROM custom_tables WHERE id=?","i",[$id]);
if (!$tbl) die('Tabelle nicht gefunden');
$schema = json_decode($tbl['schema_json'] ?? '[]', true) ?: [];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $data = [];
  foreach ($schema as $f) { $k=$f['key']; $data[$k] = $_POST[$k] ?? null; }
  db_exec("INSERT INTO custom_table_rows(table_id, data_json, created_by, created_at) VALUES(?,?,?,NOW())",
          "isi", [$id, json_encode($data,JSON_UNESCAPED_UNICODE), (int)current_user()['id']]);
  flash('Zeile hinzugefügt','info');
  redirect(base_url('pages/table_rows.php?id='.$id));
}
$rows = db_all("SELECT * FROM custom_table_rows WHERE table_id=? ORDER BY created_at DESC","i",[$id]);

include __DIR__ . '/../includes/header.php';
?>
<div class="card">
  <h2><?= e($tbl['name']) ?></h2>
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-row">
    <?php foreach ($schema as $f): ?>
      <label><?= e($f['label']) ?>
        <?php if (($f['type'] ?? 'text')==='number'): ?>
          <input type="number" name="<?= e($f['key']) ?>">
        <?php elseif (($f['type'] ?? 'text')==='date'): ?>
          <input type="date" name="<?= e($f['key']) ?>">
        <?php else: ?>
          <input name="<?= e($f['key']) ?>">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    </div>
    <button class="btn">Hinzufügen</button>
  </form>
  <hr>
  <table class="table">
    <tr><?php foreach ($schema as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?><th>Erstellt</th></tr>
    <?php foreach ($rows as $r): $d=json_decode($r['data_json'],true) ?: []; ?>
      <tr>
        <?php foreach ($schema as $f): $k=$f['key']; ?><td><?= e($d[$k] ?? '') ?></td><?php endforeach; ?>
        <td><?= e($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
