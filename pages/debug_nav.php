<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/header.php';
?>
<main style="padding:1rem">
  <pre><?php
  echo "Rolle: " . ($_SESSION['rolle'] ?? 'gast') . "\n";
  echo "User:  " . ($_SESSION['user_name'] ?? '—') . "\n";
  echo "URI:   " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
  ?></pre>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
