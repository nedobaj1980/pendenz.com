<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_role('projektleiter');

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { die('ID fehlt'); }
$proj = db_one("SELECT * FROM projekte WHERE id=?", "i", [$id]);
if (!$proj) { die('Projekt nicht gefunden'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $name = trim($_POST['name'] ?? '');
  $adresse = trim($_POST['adresse'] ?? '');
  $status = trim($_POST['status'] ?? '');
  $bild = handle_upload('bild') ?: $proj['bild_url'];
  db_exec("UPDATE projekte SET name=?, adresse=?, status=?, bild_url=? WHERE id=?",
    "ssssi", [$name,$adresse,$status,$bild,$id]);
  flash('Gespeichert','info');
  redirect(base_url('pages/projekt_bearbeiten.php?id='.$id));
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:720px;">
  <h2>Projekt bearbeiten</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label>Projektname*<input name="name" value="<?= e($proj['name']) ?>" required></label>
    <div class="form-row">
      <label>Adresse<input name="adresse" value="<?= e($proj['adresse']) ?>"></label>
      <label>Status
        <select name="status">
         <?php foreach (['Geplant','Laufend','Bewirtschaftung','Abgeschlossen'] as $s): ?>
            <option <?= $proj['status']===$s?'selected':'' ?>><?= e($s) ?></option>
         <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Bild (optional)<input type="file" name="bild" accept="image/*"></label>
    <p><small>Aktuelles Bild bleibt, wenn kein neues gewählt wird.</small></p>
    <button class="btn">Speichern</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
