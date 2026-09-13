<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/security_headers.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Request URL ===\n";
echo $_SERVER['REQUEST_URI']."\n\n";

echo "=== Effective Response Headers (aus PHP-Sicht) ===\n";
foreach (headers_list() as $h) {
  if (stripos($h, 'content-security-policy') !== false) {
    echo $h . "\n";
  }
}
echo "\nHinweis: Wenn hier mehr als EINE Content-Security-Policy auftaucht, wird der Browser sie schneiden und strenger machen.\n";
