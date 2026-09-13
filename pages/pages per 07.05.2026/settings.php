<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login(); require_role('admin');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  flash('Gespeichert (Demo). Passen Sie config/settings nach Bedarf an.','info');
  redirect(base_url('pages/settings.php'));
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:720px;">
  <h2>Einstellungen</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label>Absender E-Mail (System) <input name="from" value="<?= e(APP_FROM_EMAIL) ?>"></label>
    <button class="btn">Speichern</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
