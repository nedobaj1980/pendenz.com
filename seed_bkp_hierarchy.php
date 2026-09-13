<?php
require_once "config.php";

echo "<h1>BKP Seeder: Standard-Kategorien & Vorlagen</h1>";
echo "<pre>";

$data = [
    '211' => [
        'Betonwaende / Decken' => ['Rissbildung', 'Kiesnester sichtbar', 'Überzahn bei Schalungsstoss', 'Oberfläche unschön / porig'],
        'Mauerwerk' => ['Lagerfuge unregelmässig', 'Stein ausgebrochen', 'Anschluss an Beton unsauber']
    ],
    '221' => [
        'Fensterrahmen / Flügel' => ['Kratzer im Profil', 'Dichtung nicht umlaufend', 'Entwässerung verstopft', 'Farbschaden'],
        'Verglasung' => ['Kratzer im Glas', 'Einschluss im Isolierglas', 'Verschmutzung zwischen Scheiben'],
        'Beschläge / Funktion' => ['Griff locker', 'Flügel streift unten', 'Schliessdruck ungenügend']
    ],
    '23' => [
        'Apparate / Dosen' => ['Abdeckung verkratzt', 'Dose sitzt schief', 'Beschriftung fehlt'],
        'Tableau / Verteilung' => ['Legende unvollständig', 'Leitung unsauber geführt']
    ],
    '24' => [
        'Heizkörper' => ['Kratzer im Lack', 'Thermostatkopf schief', 'Halterung lose'],
        'Leitungen / Isolation' => ['Isolation beschädigt', 'Kennzeichnung fehlt']
    ],
    '25' => [
        'Sanitaerapparate' => ['Kratzer in Email/Keramik', 'Silikonfuge unsauber', 'Armatur wackelt'],
        'Garnituren' => ['Handtuchhalter lose', 'Spiegelhalterung schief']
    ],
    '271' => [
        'Grundputz' => ['Haarriss', 'Hohle Stelle', 'Eckschutzschiene steht vor'],
        'Weissputz / Abrieb' => ['Oberfläche wolkig', 'Struktur ungleichmässig', 'Anschluss an Decke unsauber']
    ],
    '281' => [
        'Parkett / Laminat' => ['Kratzer in Oberfläche', 'Fuge zwischen Riemen zu gross', 'Sockelleiste hat Spalt zur Wand', 'Hohllage'],
        'Plattenbeläge' => ['Fugenbild unregelmässig', 'Silikonfuge gerissen', 'Plattenkante angeschlagen (Chip)']
    ],
    '285' => [
        'Malerarbeiten Innen' => ['Deckkraft ungenügend', 'Farbnasen / Läufer', 'Verschmutzung durch andere Gewerke', 'Anschlüsse unsauber beschnitten'],
        'Malerarbeiten Holz/Metall' => ['Schleifspuren sichtbar', 'Staubbeinschlüsse im Lack']
    ]
];

foreach ($data as $code => $kats) {
    // BKP ID suchen
    $res = $mysqli->query("SELECT id FROM bkp_codes WHERE code = '" . $mysqli->real_escape_string($code) . "'");
    if ($row = $res->fetch_assoc()) {
        $bkp_id = $row['id'];
        
        foreach ($kats as $katName => $templates) {
            // Kategorie anlegen
            $mysqli->query("INSERT IGNORE INTO bkp_kategorien (bkp_id, name) VALUES ($bkp_id, '" . $mysqli->real_escape_string($katName) . "')");
            $kat_id = $mysqli->insert_id ?: $mysqli->query("SELECT id FROM bkp_kategorien WHERE bkp_id=$bkp_id AND name='".$mysqli->real_escape_string($katName)."'")->fetch_assoc()['id'];

            foreach ($templates as $text) {
                $mysqli->query("INSERT IGNORE INTO bkp_vorlagen_texte (kategorie_id, text) VALUES ($kat_id, '" . $mysqli->real_escape_string($text) . "')");
            }
        }
        echo "BKP $code aktualisiert.\n";
    }
}

echo "\nSeeding abgeschlossen.";
echo "</pre>";
?>
