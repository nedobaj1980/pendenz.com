<?php
// Hardcode connection to avoid config issues in CLI
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');

$sql = "ALTER TABLE abnahme_protokolle ADD COLUMN IF NOT EXISTS protokoll_name VARCHAR(255) AFTER mieter_name_custom";
if ($mysqli->query($sql)) {
    echo "Column protokoll_name added successfully.";
} else {
    echo "Error: " . $mysqli->error;
}
