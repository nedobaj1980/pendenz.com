<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: text/plain');

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    echo "Attempting to delete template ID $id...\n";
    $ok = $mysqli->query("DELETE FROM bkp_vorlagen_texte WHERE id = $id");
    if ($ok) {
        echo "Success. Affected rows: " . $mysqli->affected_rows . "\n";
    } else {
        echo "Error: " . $mysqli->error . "\n";
    }
} else {
    echo "Searching for 'Test' templates in category 143...\n";
    $res = $mysqli->query("SELECT id FROM bkp_vorlagen_texte WHERE titel='Test' AND kategorie_id=143");
    while($row = $res->fetch_assoc()) {
        $tid = $row['id'];
        echo "Found ID $tid. Deleting...\n";
        $ok = $mysqli->query("DELETE FROM bkp_vorlagen_texte WHERE id = $tid");
        if ($ok) {
            echo "Success. Affected rows: " . $mysqli->affected_rows . "\n";
        } else {
            echo "Error: " . $mysqli->error . "\n";
        }
    }
}
