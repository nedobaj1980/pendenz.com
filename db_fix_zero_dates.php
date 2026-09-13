<?php
require 'config.php';
$mysqli->query("UPDATE projekte SET deleted_at = NULL WHERE deleted_at = '0000-00-00 00:00:00'");
echo "Projects updated: " . $mysqli->affected_rows . "\n";
$mysqli->query("UPDATE pendenzen SET deleted_at = NULL WHERE deleted_at = '0000-00-00 00:00:00'");
echo "Pendenzen updated: " . $mysqli->affected_rows . "\n";
