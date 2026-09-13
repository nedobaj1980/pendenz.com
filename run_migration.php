<?php
require_once __DIR__ . "/config.php";

$sqlFile = __DIR__ . "/sql/zusätzliche geladen/erweiterung projekte.sql";
if (!file_exists($sqlFile)) {
    die("Migration file not found: $sqlFile");
}

$sql = file_get_contents($sqlFile);

// Split by semicolons, but be careful with JSON or other things. 
// For simplicity, we use multi_query if available or just execute the whole thing if it's small.
// But better to execute step by step.

// Remove comments
$sql = preg_replace('/--.*$/m', '', $sql);
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

$queries = explode(';', $sql);

echo "<pre>";
foreach ($queries as $query) {
    $query = trim($query);
    if (empty($query) || $query == 'START TRANSACTION' || $query == 'COMMIT') continue;
    
    echo "Executing: " . substr($query, 0, 100) . "...\n";
    if ($mysqli->query($query)) {
        echo "✅ Success\n";
    } else {
        echo "❌ Error: " . $mysqli->error . "\n";
    }
}
echo "</pre>";
