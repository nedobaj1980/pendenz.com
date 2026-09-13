<?php
require_once __DIR__ . '/../config.php';

echo "<h2>Migration: pendenz_export_profiles -> listen</h2>\n";

$rs = $mysqli->query("SELECT * FROM pendenz_export_profiles");
if (!$rs) die("Konnte pendenz_export_profiles nicht lesen: " . $mysqli->error);

while ($r = $rs->fetch_assoc()) {
    $name = $r['name'];
    $owner = (int)$r['created_by'];
    
    // Check if it already exists
    $ch = $mysqli->query("SELECT id FROM listen WHERE name = '" . $mysqli->real_escape_string($name) . "'");
    if ($ch && $ch->num_rows > 0) {
        echo "Skip: $name already exists.<br>\n";
        continue;
    }
    
    // Insert into listen
    $sql = "INSERT INTO listen (name, table_name, filters_json, sort_col, sort_dir, per_page, owner_id, shared) 
            VALUES ('".$mysqli->real_escape_string($name)."', 'pendenzen', '{}', 'id', 'desc', 25, $owner, 1)";
    $mysqli->query($sql);
    $newId = $mysqli->insert_id;
    
    if ($newId) {
        $cols = json_decode($r['columns_json'], true);
        if (is_array($cols)) {
            $ord = 10;
            foreach ($cols as $c) {
                $cSafe = $mysqli->real_escape_string($c);
                $mysqli->query("INSERT INTO listen_columns (list_id, col_name, sort_order) VALUES ($newId, '$cSafe', $ord)");
                $ord += 10;
            }
        }
        echo "Fertig migriert: $name<br>\n";
    } else {
        echo "Fehler bei $name: " . $mysqli->error . "<br>\n";
    }
}
echo "Migration abgeschlossen.\n";
