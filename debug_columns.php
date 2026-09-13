<?php
require __DIR__ . '/config.php';

// Fix: delete rows with empty typ that are plan snapshots
$r = $mysqli->query("DELETE FROM pendenz_dateien WHERE typ = '' AND titel = 'Plan-Ausschnitt'");
echo "Deleted old broken snapshot rows: " . $mysqli->affected_rows . "\n";

// Fix: also delete duplicate plan snapshots per pendenz (keep only the newest)
$res = $mysqli->query("SELECT pendenz_id FROM pendenz_dateien WHERE typ='image' AND titel='Plan-Ausschnitt' GROUP BY pendenz_id HAVING COUNT(*) > 1");
while ($row = $res->fetch_assoc()) {
    $pid = (int)$row['pendenz_id'];
    // Keep the newest (highest id), delete the rest
    $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id=$pid AND titel='Plan-Ausschnitt' AND id NOT IN (SELECT id FROM (SELECT MAX(id) as id FROM pendenz_dateien WHERE pendenz_id=$pid AND titel='Plan-Ausschnitt') t)");
    echo "Cleaned duplicates for pendenz $pid\n";
}

// Show current state
$res = $mysqli->query("SELECT id, pendenz_id, typ, pfad, titel FROM pendenz_dateien WHERE titel='Plan-Ausschnitt' OR pfad LIKE '%plan_snap%' ORDER BY id DESC");
echo "<h3>Aktuelle Plan-Snapshots:</h3><pre>";
while ($row = $res->fetch_assoc()) {
    $exists = file_exists(__DIR__ . '/' . $row['pfad']) ? 'FILE OK' : 'FILE MISSING!';
    echo "ID={$row['id']} | pendenz_id={$row['pendenz_id']} | typ='{$row['typ']}' | titel='{$row['titel']}' | $exists\n";
    echo "  {$row['pfad']}\n\n";
}
echo "</pre>";
