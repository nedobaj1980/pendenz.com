<?php
require_once "C:/xampp/htdocs/pendenz.com/config.php";
require_once "C:/xampp/htdocs/pendenz.com/includes/auth.php";
require_login();

$rolle = $_SESSION['rolle'] ?? '';
if (!in_array($rolle, ['admin','superadmin'], true)) {
    http_response_code(403);
    die("Zugriff verweigert: Adminbereich.");
}
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die("DB-Verbindung nicht verfügbar: \$mysqli ist nicht gesetzt (config.php).");
}

include "C:/xampp/htdocs/pendenz.com/includes/header.php";
include ($rolle === 'superadmin'
    ? "C:/xampp/htdocs/pendenz.com/includes/nav_superadmin.php"
    : "C:/xampp/htdocs/pendenz.com/includes/nav_admin.php");
?>
<main style="max-width:1000px;margin:24px auto;padding:0 12px;">
  <h1>Admin-Start</h1>
  <p>Willkommen im Administrationsbereich.</p>
  <ul>
    <li><a href="http://localhost/pendenz.com/pages/pendenzen.php">Pendenzen</a></li>
    <li><a href="http://localhost/pendenz.com/pages/projekte.php">Projekte</a></li>
    <?php if ($rolle === 'superadmin'): ?>
      <li><a href="http://localhost/pendenz.com/pages/benutzer.php">Benutzerverwaltung</a></li>
      <li><a href="http://localhost/pendenz.com/tools/konto_verwaltung/index.php">Konto-Verwaltung (Tool)</a></li>
    <?php endif; ?>
  </ul>
</main>
<?php include "C:/xampp/htdocs/pendenz.com/includes/footer.php"; ?>
