<?php
require_once __DIR__ . '/config.php';
try {
    $mysqli->query("ALTER TABLE benutzer ADD COLUMN folder_path VARCHAR(2048) DEFAULT NULL");
    echo "✅ Column 'folder_path' added to table 'benutzer'.\n";
} catch (Exception $e) {
    echo "⚠️ Info: " . $e->getMessage() . "\n";
}
try {
    // Falls noch nicht da, Mieter-Interessenten Basisordner anlegen
    $base = __DIR__ . '/storage/files/00_Mieter_Interessenten';
    if (!is_dir($base)) {
        mkdir($base, 0777, true);
        echo "✅ Base directory created: $base\n";
    }
} catch (Exception $e) {
    echo "⚠️ Error creating directory: " . $e->getMessage() . "\n";
}
?>
