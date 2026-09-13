<?php
require_once 'config.php';
$mysqli->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
try {
    $mysqli->query("ALTER TABLE listen ADD COLUMN is_default TINYINT(1) DEFAULT 0");
    echo "✅ Spalte is_default erfolgreich hinzugefügt.";
} catch (mysqli_sql_exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "ℹ️ Spalte existiert bereits.";
    } else {
        echo "❌ Fehler: " . $e->getMessage();
    }
}
?>
