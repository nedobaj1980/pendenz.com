<?php
// C:\xampp\htdocs\pendenz.com\test_dompdf.php

// 1) Sichtbare Fehlerausgabe aktivieren
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2) Autoloader prüfen (kein Fatal Error, sondern klare Meldung)
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
  http_response_code(500);
  echo "<h2>Fehler: vendor/autoload.php nicht gefunden</h2>";
  echo "<p>Im Projektordner ausführen:</p>";
  echo "<pre>cd " . htmlspecialchars(__DIR__) . "\ncomposer require dompdf/dompdf</pre>";
  exit;
}
require $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// 3) Diagnose-Modus (Aufruf: /test_dompdf.php?debug=1)
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  $checks = [
    'PHP-Version'        => PHP_VERSION,
    'mbstring geladen?'  => extension_loaded('mbstring') ? 'ja' : 'NEIN',
    'gd geladen?'        => extension_loaded('gd') ? 'ja' : 'NEIN',
    'iconv geladen?'     => function_exists('iconv') ? 'ja' : 'NEIN',
    'temp sys_get_temp_dir()' => sys_get_temp_dir(),
  ];
  echo "<h2>Dompdf Diagnose</h2><ul>";
  foreach ($checks as $k => $v) echo "<li><strong>$k:</strong> ".htmlspecialchars(is_string($v)?$v:json_encode($v))."</li>";
  echo "</ul><p>Wenn <em>mbstring</em> oder <em>gd</em> NEIN ist: <code>php.ini</code> öffnen und aktivieren:</p>";
  echo "<pre>extension=mbstring\nextension=gd</pre>";
  echo "<p>Apache danach neu starten.</p>";
  exit;
}

// 4) Eigene Temp-/Font-Ordner (verhindert Rechte-Probleme)
$storage = __DIR__ . '/storage';
$tmpDir  = $storage . '/tmp';
$fontDir = $storage . '/fonts';
@mkdir($tmpDir, 0777, true);
@mkdir($fontDir, 0777, true);

// 5) Dompdf-Optionen
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');   // Umlaute
$options->set('chroot', __DIR__);              // lokale Pfade absichern
$options->set('tempDir', $tmpDir);
$options->set('fontDir', $fontDir);
// Optional: $options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);

// 6) Test-HTML
$html = '
<!doctype html><html><head><meta charset="utf-8">
<style>
  body{ font-family: DejaVu Sans, sans-serif; font-size:12pt; }
  h1{ margin:0 0 8px 0; }
  table{ border-collapse:collapse; width:100%; }
  th,td{ border:1px solid #999; padding:6px; text-align:left; }
  thead th{ background:#f0f0f0; }
</style></head><body>
<h1>Hallo PDF 👋</h1>
<p>Umlaute: ä ö ü ß — Datum: '.date('d.m.Y H:i:s').'</p>
<table>
  <thead><tr><th>Spalte A</th><th>Spalte B</th></tr></thead>
  <tbody><tr><td>Test</td><td>Zeile</td></tr></tbody>
</table>
</body></html>
';

try {
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait'); // 'landscape' möglich
  $dompdf->render();
  // Direkt-Download
  $dompdf->stream('test.pdf', ['Attachment' => true]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo "<h2>Dompdf-Fehler</h2>";
  echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
