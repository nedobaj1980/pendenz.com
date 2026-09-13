<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/user_folder_automation.php';

$userId = 8; // Nedim
echo "Syncing User $userId...\n";
$res = user_folder_sync($mysqli, $userId);
print_r($res);

echo "\nFolder Path in DB:\n";
$row = $mysqli->query("SELECT folder_path FROM benutzer WHERE id=$userId")->fetch_assoc();
echo $row['folder_path'] . "\n";
?>
