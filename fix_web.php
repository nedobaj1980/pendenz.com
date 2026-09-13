<?php
/**
 * fix_web.php
 * Script zur Reparatur der Einheit-Typen und Umlaute auf dem Web-Server.
 * Nach der Ausführung bitte wieder vom Server löschen!
 */
require_once 'config.php';

// Sicherstellen, dass die Verbindung UTF-8 nutzt
if (method_exists($mysqli, 'set_charset')) {
    $mysqli->set_charset("utf8mb4");
}

echo "<h2>Starte Reparatur auf pendenz.com...</h2>";

// 1. Umlaute in den Typen reparieren (z.B. B³roflaeche -> Bürofläche)
// Wir suchen nach Mustern, die auf kaputte Umlaute hindeuten
$mysqli->query("UPDATE einheit_typen SET name = 'Bürofläche' WHERE name LIKE 'B%roflaeche%'");
$mysqli->query("UPDATE einheit_typen SET name = 'Bürofläche' WHERE name LIKE 'B%rofl%'");
echo "✅ Einheit-Typ 'Bürofläche' repariert.<br>";

// 2. Fehlende Typen hinzufügen (Garten & Umgebung)
$types = [
    ['Garten', '🌳', 'Aussen'],
    ['Umgebung', '🏞️', 'Aussen']
];

foreach ($types as $t) {
    $name = $t[0];
    $icon = $t[1];
    $grp  = $t[2];
    
    // Check mit korrekter Kodierung
    $stmt = $mysqli->prepare("SELECT id FROM einheit_typen WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows === 0) {
        $stmtIns = $mysqli->prepare("INSERT INTO einheit_typen (name, icon, gruppe) VALUES (?, ?, ?)");
        $stmtIns->bind_param("sss", $name, $icon, $grp);
        $stmtIns->execute();
        echo "✅ Typ '$name' hinzugefügt.<br>";
    }
}

// 3. Vorlagen-Update: 'Anrufen an' -> 'Anrufen an, Nedim'
$mysqli->query("UPDATE pendenz_subkategorien_vermieter SET name = 'Anrufen an, Nedim' WHERE name = 'Anrufen an'");
echo "✅ Vorlage 'Anrufen an' zu 'Anrufen an, Nedim' aktualisiert.<br>";

echo "<h3>Fertig! Bitte prüfe jetzt die Seite im Web und lösche diese Datei danach vom Server.</h3>";
