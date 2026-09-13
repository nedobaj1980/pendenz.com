<?php
// Autoloader für Klassen unter App\*
// WICHTIG: Verzeichnisse sind lowercase (modules/pendenzen/), Dateiname bleibt original (Service.php)
// So funktioniert es auf Linux-Servern (case-sensitive) UND Windows (case-insensitive)
spl_autoload_register(function(string $class): void {
  if (strpos($class, 'App\\') !== 0) return;

  // Namespace-Pfad aufteilen: App\Modules\Pendenzen\Service -> parts: ['Modules','Pendenzen','Service']
  $parts = explode('\\', ltrim(str_replace('App\\', '', $class), '\\'));
  $fileName = array_pop($parts); // 'Service' (Dateiname bleibt original)
  $dirPart  = implode('/', array_map('strtolower', $parts)); // 'modules/pendenzen'

  $path = __DIR__ . '/../' . ($dirPart ? $dirPart . '/' : '') . $fileName . '.php';

  if (is_file($path)) {
    require $path;
  }
});
