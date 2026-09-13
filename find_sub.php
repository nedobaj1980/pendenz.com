<?php
/**
 * DIESE DATEI WURDE VON ANTIGRAVITY ERSTELLT.
 * Falls sie leer erscheint, bitte die Datei im Editor schließen und neu öffnen.
 */
require_once 'config.php';

echo "<h2 style='font-family:sans-serif;'>Suche nach 'Anrufen an' in den Vorlagen...</h2>";

$res = $mysqli->query("SELECT id, name, beschreibung FROM pendenz_subkategorien_vermieter WHERE name LIKE '%Anrufen an%'");

if ($res && $res->num_rows > 0) {
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse; font-family:sans-serif; width:100%;'>";
    echo "<tr style='background:#0f172a; color:#fff;'><th>ID</th><th>Name (Kurzbeschreibung)</th><th>Beschreibung (Langtext)</th></tr>";
    while($r = $res->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='font-weight:bold;'>" . $r['id'] . "</td>";
        echo "<td>" . htmlspecialchars($r['name']) . "</td>";
        echo "<td style='color:#64748b; font-size:13px;'>" . nl2br(htmlspecialchars($r['beschreibung'] ?? '')) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='font-family:sans-serif; color:#ef4444;'>Keine Einträge mit 'Anrufen an' gefunden.</p>";
}

echo "<br><hr><p style='font-family:sans-serif;'>Tipp: Du kannst auch die <b>fix_web.php</b> aufrufen, um die Namen automatisch zu korrigieren.</p>";
