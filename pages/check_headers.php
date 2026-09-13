<?php
$dir = __DIR__;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$found = [];
foreach ($files as $file) {
    if ($file->isDir()) continue;
    if ($file->getExtension() !== 'php') continue;
    $content = file_get_contents($file->getPathname());
    
    // Indikatoren für Layout-Ausgabe
    $layoutPos = PHP_INT_MAX;
    $layoutTags = ['header.php', 'nav_auto.php', 'nav_dispatch.php', 'nav_superadmin.php', 'nav_public.php'];
    foreach($layoutTags as $tag) {
        $p = strpos($content, $tag);
        if($p !== false && $p < $layoutPos) $layoutPos = $p;
    }
    
    if ($layoutPos !== PHP_INT_MAX) {
        // Suche header() nach dieser Pos
        $headerPos = strpos($content, 'header(', $layoutPos);
        if ($headerPos !== false) {
             // Es gibt einen header-aufruf nach dem layout.
             // Prüfen ob es ein Content-Type JSON ist (oft ok in AJAX)
             $snippet = substr($content, $headerPos, 100);
             if (strpos($snippet, 'application/json') === false) {
                 $found[] = $file->getFilename() . " (Snippet: $snippet)";
             }
        }
    }
}

if (empty($found)) {
    echo "NO_HEADER_ISSUES_FOUND";
} else {
    echo "Potentially problematic files:\n" . implode("\n", $found);
}
