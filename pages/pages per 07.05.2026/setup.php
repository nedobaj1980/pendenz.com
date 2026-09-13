<?php
require_once __DIR__ . '/../config.php';

// Create schema if not present
$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
  if ($stmt) { db_exec($stmt); }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['pass'] ?? '';
  if ($name && $email && $pass) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    db_exec("INSERT INTO benutzer(name,email,rolle,password_hash,status,created_at) VALUES(?,?,?,?, 'active', NOW())",
      "ssss", [$name,$email,'superadmin',$hash]);
    flash('Superadmin erstellt. Bitte einloggen.', 'info');
    redirect(base_url('pages/login.php'));
  } else {
    flash('Bitte alle Felder ausfüllen.', 'error');
  }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="card" style="max-width:520px;margin:40px auto;">
  <h2>Ersteinrichtung</h2>
  <p>1) Datenbank wurde/ wird initialisiert.<br>2) Erstelle den ersten Benutzer (Superadmin).</p>
  <form method="post">
    <?= csrf_field() ?>
    <label>Name<input name="name" required></label>
    <label>E-Mail<input name="email" type="email" required></label>
    <label>Passwort<input name="pass" type="password" required></label>
    <button class="btn">Benutzer anlegen</button>
  </form>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
