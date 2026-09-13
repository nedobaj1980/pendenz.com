<?php
require_once "config.php";

echo "<h1>Fixing BKP Schema</h1>";
echo "<pre>";

$sqls = [
    "ALTER TABLE bkp_codes ADD COLUMN IF NOT EXISTS titel VARCHAR(255) NULL AFTER bezeichnung",
    "ALTER TABLE bkp_codes ADD COLUMN IF NOT EXISTS beschreibung TEXT NULL AFTER titel",
    "ALTER TABLE bkp_vorlagen_texte ADD COLUMN IF NOT EXISTS titel VARCHAR(255) NULL AFTER kategorie_id",
    "ALTER TABLE bkp_vorlagen_texte ADD COLUMN IF NOT EXISTS beschreibung TEXT NULL AFTER titel"
];

foreach ($sqls as $sql) {
    echo "Executing: $sql\n";
    if ($mysqli->query($sql)) {
        echo "OK.\n";
    } else {
        echo "Error: " . $mysqli->error . "\n";
    }
}

echo "\nDone.";
echo "</pre>";
?>
