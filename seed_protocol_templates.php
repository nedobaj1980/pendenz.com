<?php
// Standalone Seed Script for Professional Protocol Templates - FINAL VERSION
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'pendenz_com');
define('DB_USER', 'root');
define('DB_PASS', '');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($mysqli->connect_error) die("Connection failed: " . $mysqli->connect_error);
$mysqli->set_charset("utf8mb4");

// 1. Kategorien (Typen) EXAKT nach USER-Wunsch
$typen = [
    ['name' => 'Abnahmeprotokoll', 'icon' => '🔑'],
    ['name' => 'Baustellenprotokoll', 'icon' => '🏗️'],
    ['name' => 'Sitzungsprotokoll', 'icon' => '👥'],
    ['name' => 'Pendenzen & Erinnerungen', 'icon' => '📋']
];

foreach ($typen as $t) {
    $name = $mysqli->real_escape_string($t['name']);
    $icon = $mysqli->real_escape_string($t['icon']);
    $check = $mysqli->query("SELECT id FROM protokoll_typen WHERE name = '$name'");
    if ($check->num_rows == 0) {
        $mysqli->query("INSERT INTO protokoll_typen (name, icon) VALUES ('$name', '$icon')");
    } else {
        $mysqli->query("UPDATE protokoll_typen SET icon = '$icon' WHERE name = '$name'");
    }
}

// Helper: JSON Generator
function getFullTemplate($title, $subject, $intro, $outro, $sections = []) {
    $defaultSections = [
        ['id' => 101, 'title' => 'Allgemeine Feststellungen', 'content' => 'Zustand wurde geprüft.'],
        ['id' => 102, 'title' => 'Nächste Schritte', 'content' => 'Erledigung bis zum nächsten Fix-Termin.']
    ];
    return json_encode([
        'title' => $title,
        'subject' => $subject,
        'list_title' => 'Pendenzen & Details',
        'intro' => $intro,
        'outro' => $outro,
        'participants' => [],
        'customSections' => array_merge($defaultSections, $sections)
    ], JSON_UNESCAPED_UNICODE);
}

// 2. Mastervorlagen laut USER-Wunsch
$templates = [
    // --- ABNAHMEN ---
    [
        'type' => 'Abnahmeprotokoll',
        'name' => 'Mieterabnahme (Standard)',
        'data' => getFullTemplate('Mieterabnahme', 'Zustand bei Übergabe', 'Begehung der Wohnung.', 'Mieter bestätigt Zustand.', [['id' => 1, 'title' => 'Zählerstände', 'content' => 'Strom/Wasser/Heizung']])
    ],
    [
        'type' => 'Abnahmeprotokoll',
        'name' => 'Unternehmerabnahme',
        'data' => getFullTemplate('Unternehmerabnahme', 'Abnahme Bauleistung', 'Kontrolle Werkvertrag.', 'Leistung abgenommen.', [['id' => 2, 'title' => 'Garantiefristen', 'content' => 'Beginn/Ende']])
    ],
    [
        'type' => 'Abnahmeprotokoll',
        'name' => 'Bauherrenabnahme',
        'data' => getFullTemplate('Bauherrenabnahme', 'Endabnahme Bauherr', 'Abschlussbegehung.', 'Gefahrübergang erfolgt.', [['id' => 3, 'title' => 'Dokumentation', 'content' => 'Pläne übergeben.']])
    ],
    
    // --- BAUSTELLE ---
    [
        'type' => 'Baustellenprotokoll',
        'name' => 'Baustellenkontrolle',
        'data' => getFullTemplate('Baustellenkontrolle', 'Sicherheits-Check', 'SUVA-Konformität.', 'Massnahmen eingeleitet.', [['id' => 4, 'title' => 'Sicherheit', 'content' => 'Gerüste geprüft.']])
    ],
    [
        'type' => 'Baustellenprotokoll',
        'name' => 'Baujournal',
        'data' => getFullTemplate('Baujournal', 'Tagesbericht', 'Fortschritt der Arbeiten.', 'Besonderheiten dokumentiert.', [['id' => 5, 'title' => 'Witterung/Personal', 'content' => 'Wetter: [ ] / Firmen: [ ]']])
    ],

    // --- SITZUNGEN ---
    [
        'type' => 'Sitzungsprotokoll',
        'name' => 'Bauleitungssitzung',
        'data' => getFullTemplate('Bauleitungssitzung', 'Wöchentlicher Fix-Termin', 'Termine & Kosten.', 'Nächster Termin fest.', [['id' => 6, 'title' => 'Pendenzen', 'content' => 'Status besprochen.']])
    ],

    // --- LISTEN ---
    [
        'type' => 'Pendenzen & Erinnerungen',
        'name' => 'Pendenzenliste',
        'data' => getFullTemplate('Pendenzenliste', 'Projekt-Aufgaben', 'Zusammenfassung.', 'Aktualisierung wöchentlich.', [['id' => 7, 'title' => 'Priorität', 'content' => 'A / B / C']])
    ],
    [
        'type' => 'Pendenzen & Erinnerungen',
        'name' => 'Erinnerungsliste',
        'data' => getFullTemplate('Erinnerungsliste', 'Termin-Follow-up', 'Fristenüberwachung.', 'Status monatlich.', [['id' => 8, 'title' => 'Wiedervorlage', 'content' => 'Wichtige Termine.']])
    ]
];

// 3. Ausführung
foreach ($templates as $tpl) {
    $typeName = $mysqli->real_escape_string($tpl['type']);
    $res = $mysqli->query("SELECT id FROM protokoll_typen WHERE name = '$typeName'");
    if ($row = $res->fetch_assoc()) {
        $typ_id = $row['id'];
        $name = $mysqli->real_escape_string($tpl['name']);
        $json = $mysqli->real_escape_string($tpl['data']);
        $mysqli->query("DELETE FROM protokoll_vorlagen WHERE name = '$name' AND typ_id = $typ_id"); // Clean update
        $mysqli->query("INSERT INTO protokoll_vorlagen (typ_id, name, json_data) VALUES ($typ_id, '$name', '$json')");
        echo "Master '$name' bereit.\n";
    }
}
echo "Migration komplett.";
?>
