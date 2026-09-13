<?php
require_once 'config.php';

echo "<pre>";
echo "Dropping any existing Foreign Key constraints on vorgaenger_id...\n";
// Trying multiple common names just in case
$mysqli->query("ALTER TABLE pendenzen DROP FOREIGN KEY fk_pendenzen_vorgaenger");
$mysqli->query("ALTER TABLE pendenzen DROP INDEX fk_pendenzen_vorgaenger");

echo "Updating vorgaenger_id to VARCHAR(255) to support MS Project formulas...\n";

$sql = "ALTER TABLE pendenzen MODIFY COLUMN vorgaenger_id VARCHAR(255) NULL";
if ($mysqli->query($sql)) {
    echo "SUCCESS: Column 'vorgaenger_id' is now VARCHAR.\n";
} else {
    echo "ERROR: " . $mysqli->error . "\n";
}

// Also check 'dauer' just in case, though it seems okay in fix_schema
$mysqli->query("ALTER TABLE pendenzen MODIFY COLUMN dauer VARCHAR(50) NULL");

echo "Done.";
?>
