<?php
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

echo "<h1>Probe</h1>";
echo "<p>PHP Version: ".phpversion()."</p>";

// Prüfe, ob includes/functions.php syntaktisch sauber ist:
$fn = __DIR__ . "/includes/functions.php";
echo "<p>functions.php: ".(is_file($fn) ? "gefunden" : "FEHLT")."</p>";
if (is_file($fn)) {
    $ok = @include_once $fn;
    echo "<p>Include functions.php: OK</p>";
}

// Teste site_prefix():
if (function_exists('site_prefix')) {
    echo "<p>site_prefix(): ".htmlspecialchars(site_prefix())."</p>";
} else {
    echo "<p style='color:red'>site_prefix() fehlt!</p>";
}

echo "<p>Alles bis hierher OK.</p>";
