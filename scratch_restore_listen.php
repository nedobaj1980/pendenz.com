<?php
require_once 'config.php';

echo "Restoring list templates visibility...\n";

// Alle Listen dem Benutzer mit ID 8 zuordnen und auf "geteilt" setzen
$mysqli->query("UPDATE listen SET owner_id = 8, shared = 1 WHERE owner_id IS NULL OR owner_id = 0 OR owner_id = ''");
$mysqli->query("UPDATE listen SET shared = 1"); // Sicherstellen dass alle sichtbar sind

echo "Restored visibility for " . $mysqli->affected_rows . " templates.\n";

$res = $mysqli->query("SELECT id, name, owner_id, shared FROM listen");
while($row = $res->fetch_assoc()) {
    echo "ID: $row[id] | Name: '$row[name]' | Owner: $row[owner_id] | Shared: $row[shared]\n";
}
