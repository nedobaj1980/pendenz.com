<?php
echo __DIR__, "<br>";
$paths = [
  __DIR__ . "/includes/authz.php",
  __DIR__ . "/includes/audit.php",
  __DIR__ . "/includes/auth.php",
];
foreach ($paths as $p) {
  echo basename($p) . ': ' . (file_exists($p) ? 'OK' : 'FEHLT') . "<br>";
}
