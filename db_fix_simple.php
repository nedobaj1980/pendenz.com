<?php
require_once 'config.php';
// Einfaches SQL ohne mysqli_report Komplexität
$sql = "ALTER TABLE listen ADD COLUMN is_default TINYINT(1) DEFAULT 0";
if ($mysqli->query($sql)) {
    echo "✅ Success: is_default added.";
} else {
    echo "❌ Error: " . $mysqli->error;
}
?>
