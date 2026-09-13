<?php
require_once __DIR__ . '/../config.php';

// First run: if no users exist, redirect to installer
$c = db_one("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='benutzer'");
if (!$c) { redirect(base_url('pages/setup.php')); }
$uCount = db_one("SELECT COUNT(*) c FROM benutzer");
if ($uCount && (int)$uCount['c'] === 0) { redirect(base_url('pages/setup.php')); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['pass'] ?? '';
  if (login($email, $pass)) {
    redirect(base_url('pages/dashboard.php'));
  } else {
    flash('Login fehlgeschlagen', 'error');
  }
}
include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:420px;margin:40px auto;">
  <h2>Login</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label>E-Mail<input name="email" type="email" required></label>
    <label>Passwort<input name="pass" type="password" required></label>
    <button class="btn" type="submit">Einloggen</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
