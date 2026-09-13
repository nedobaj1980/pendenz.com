<?php
$host   = '127.0.0.1';
$user   = 'root';
$pass   = '';
$dbname = 'pendenz_com';
$mysqli = new mysqli($host, $user, $pass, $dbname);
if ($mysqli->connect_error) { die("❌ Connection failed: " . $mysqli->connect_error); }

$sql = "ALTER TABLE listen ADD COLUMN is_default TINYINT(1) DEFAULT 0";
if ($mysqli->query($sql)) {
    echo "✅ Success: is_default added to pendenz_com.";
} else {
    if (strpos($mysqli->error, 'Duplicate column') !== false) {
        echo "ℹ️ Column already exists.";
    } else {
        echo "❌ Error: " . $mysqli->error;
    }
}
?>
