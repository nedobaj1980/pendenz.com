<?php
require_once "config.php";

echo "<h1>Vollständiger BKP Seeder: Alle Gewerke</h1>";
echo "<pre>";

$data = [
    // 20 Vorbereitende Arbeiten (Hauptgruppe)
    '20' => [
        'Bauplatz-Installation' => ['Bau-WC unhygienisch', 'Bauwasser/-strom nicht gesichert', 'Signalisation fehlt', 'Absperrung mangelhaft'],
        'Sicherheit/Schutz' => ['Nachbarobjekt nicht geschützt', 'Bestehende Leitungen nicht markiert']
    ],
    // 201 Vorbereitungen
    '201' => [
        'Installation' => ['Bauzaun fehlt teilweise', 'Container unsauber gestellt', 'Provisorische Wege ungenügend'],
        'Abbruch / Rodung' => ['Wurzelstock nicht entfernt', 'Abbruchmaterial nicht fachgerecht getrennt']
    ],
    // 21 Rohbau 1
    '21' => [
        'Geruest / Sicherheit' => ['Stirnabsturzsicherung fehlt', 'Gerüstgang verstellt', 'Leiteraufstieg nicht gesichert'],
        'Baureinigung' => ['Grobschutt nicht entfernt', 'Wassersammlung in Kellern']
    ],
    '214' => [
        'Stahlbau' => ['Schweissnaht unsauber / Schlacke', 'Korrosionsschutz beschädigt', 'Schraubverbindung nicht fest', 'Verzinkung hat Fehler']
    ],
    '215' => [
        'Spezialtiefbau' => ['Ankerkopf ungeschützt', 'Riss in Spritzbeton', 'Pfahlkopf nicht sauber freigelegt', 'Schlamm in Baugrubensohle']
    ],
    '211' => [
        'Betonwaende / Stuetzen' => ['Rissbildung (statisch prüfen)', 'Kiesnester / Lunker', 'Überzahn bei Schalungsstoss', 'Armierung liegt frei', 'Konische Löcher nicht verschlossen'],
        'Bodenplatten' => ['Ebenheitstoleranz überschritten', 'Risse an den Ecken', 'Anschlussbewehrung fehlt'],
        'Mauerwerk' => ['Lagerfuge zu dick', 'Schlitze unsauber ausgeführt', 'Anschlussanker fehlen', 'Sturz falsch gelagert']
    ],
    // 22 Rohbau 2
    '22' => [
        'Gebaeudehuelle (Allg.)' => ['Notabdichtung fehlt', 'Schutz vor Witterung ungenügend', 'Beschattung wackelt'],
        'Dachwasser' => ['Notentwässerung blockiert']
    ],
    '221' => [
        'Fenster / Tore' => ['Kratzer im Rahmenprofil', 'Dichtung nicht umlaufend bündig', 'Funktionsprüfung negativ (klemmt)', 'Entwässerungskappen fehlen', 'Transportschäden an Kanten'],
        'Verglasung' => ['Kratzer im Glas (Sichtprüfung)', 'Blasen / Einschlüsse im Glas', 'Distanzhalter im Isolierglas schief'],
        'Beschläge' => ['Griff locker', 'Schliessdruck ungenügend', 'Feststeller fehlt']
    ],
    '222' => [
        'Spenglerarbeiten' => ['Lötstelle unsauber', 'Blechbeule / Delle', 'Gefälle Dachrinne ungenügend', 'Anschluss an Kamin undicht'],
        'Blitzschutz' => ['Verbindung lose', 'Prüfplakette fehlt']
    ],
    '224' => [
        'Bedachung' => ['Ziegel beschädigt / Eckbruch', 'Unterdach nicht regendicht', 'Kiesleiste fehlt', 'Verschmutzung durch Mörtel']
    ],
    '225' => [
        'Fassadenisolation' => ['Plattenstösse offen', 'Dübelabdrücke sichtbar', 'Gewebe nicht überlappt', 'Sockelanschluss fehlt']
    ],
    '228' => [
        'Sonnenschutz / Storen' => ['Storenbehang schief', 'Führungsschiene locker', 'Endabschaltung falsch eingestellt', 'Kratzer am Lamellenprofil', 'Windwächter ohne Funktion']
    ],
    // 23 Elektro
    '23' => [
        'Apparate' => ['Dose sitzt schief', 'Abdeckung verkratzt / verschmutzt', 'Funktion Schalter/Steckdose prüfen', 'Beschriftung fehlt', 'Lichtfarbe ungleich'],
        'Verteilung' => ['Sicherungslegende fehlt', 'Kabelbeschriftung unvollständig', 'Tür/Verschluss klemmt']
    ],
    '237' => [
        'Photovoltaik' => ['Modul verkratzt', 'Kabelführung lose', 'Unterkonstruktion nicht fachgerecht fixiert', 'Wechselrichter Fehlermeldung']
    ],
    // 24 HLK
    '242' => [
        'Heizung / Kälte' => ['Heizkörper verkratzt', 'Ventil tropft', 'Isolation fehlt an Ventilen', 'Entlüftungsschlüssel fehlt', 'Druckprüfung Protokoll fehlt']
    ],
    '243' => [
        'Lüftung' => ['Filter verschmutzt', 'Tellerventil fehlt', 'Geräuschentwicklung zu hoch', 'Brandschutzklappe nicht beschriftet']
    ],
    // 25 Sanitär
    '25' => [
        'Apparate' => ['Kratzer in Email / Keramik', 'Armatur wackelt', 'Silikonfuge unsauber / Löcher', 'Ablauf verstopft / Bauschutt'],
        'Accessoires' => ['Halterung lose', 'Spiegel hat Kantenkorrosion', 'Drückerplatte WC locker']
    ],
    '258' => [
        'Kücheneinrichtungen' => ['Front verkratzt', 'Scharnier nicht eingestellt', 'Arbeitsplatte Stossfuge offen', 'Gerätefunktion (Dampfabzug Prüf)', 'Besteckkasten fehlt']
    ],
    // 27 Ausbau 1
    '271' => [
        'Grundputzarbeiten' => ['Haarrisse sichtbar', 'Hohllagen im Putz', 'Eckschutzschienen schief', 'Anschluss an Rahmen unsauber'],
        'Weissputz / Deckputz' => ['Oberfläche wolkig / scheckig', 'Kratzer / Unebenheit im Abrieb', 'Struktur ungleichmässig', 'Kellenschlag sichtbar']
    ],
    '272' => [
        'Metallbau' => ['Schweissnaht unsauber', 'Farbschaden am Geländer', 'Handlauf wackelt', 'Anschlagpuffer fehlt']
    ],
    '273' => [
        'Schreinerarbeiten' => ['Türe streift', 'Zarge hat Montageschäden', 'Drückergarnitur locker', 'Schattenfuge unsauber', 'Abschluss an Boden mangelhaft']
    ],
    '274' => [
        'Schlosserarbeiten' => ['Gitterrost klappert', 'Handlauf hat Grat (Verletzungsgefahr)', 'Torschliesser zu stark eingestellt']
    ],
    '277' => [
        'Signaletik / Beschriftung' => ['Briefkastenschild fehlt', 'Wegweiser schief montiert', 'Brandschutztüre nicht gekennzeichnet']
    ],
    // 28 Ausbau 2
    '281' => [
        'Bodenbeläge' => ['Kratzer in Oberfläche (Parkett/Laminat)', 'Fugenbreite unzulässig gross', 'Hohllage / Wippen', 'Sockelleiste hat Spalt zur Wand', 'Verschmutzung durch Kleberreste'],
        'Plattenarbeiten' => ['Plattenkante abgeplatzt', 'Fugenfarbe ungleich', 'Gefälle im Bad zu gering (Duschbereich)', 'Silikonfuge gerissen']
    ],
    '282' => [
        'Teppich / Kunststoff' => ['Nahtstelle sichtbar', 'Blasenbildung', 'Verschmutzung / Flecken', 'Randabschluss lose']
    ],
    '285' => [
        'Malerarbeiten' => ['Deckkraft ungenügend', 'Farbausläufer / Rotznasen', 'Anschlüsse an Decke/Wand unsauber', 'Verschmutzung Fenster/Boden', 'Schleifspuren im Streiflicht sichtbar']
    ],
    '287' => [
        'Reinigung' => ['Baustaub in Ecken', 'Fensterrahmen unsauber', 'Schutzfolien nicht entfernt', 'Kalkflecken auf Armaturen']
    ],
    // 29 Honorare / Diverses
    '291' => [
        'Architekt' => ['Pläne nicht nachgeführt (As-built)', 'Dokumentation unvollständig']
    ],
    // 4 Umgebung
    '4' => [
        'Gartenbau / Umgebung' => ['Pflanze abgestorben / vertrocknet', 'Bodenplatte Weg uneben', 'Entwässerungsschacht verstopft', 'Humusierung ungenügend']
    ],
    '5' => [
        'Baunebenkosten' => ['Dokumentation / Ordner fehlt', 'Schlüsselverzeichnis unvollständig', 'Bedienungsanleitungen fehlen']
    ]
];

foreach ($data as $code => $kats) {
    // BKP ID suchen - wir suchen nach dem exakten Code oder dem Prefix
    $res = $mysqli->query("SELECT id FROM bkp_codes WHERE code = '" . $mysqli->real_escape_string($code) . "' OR code LIKE '" . $mysqli->real_escape_string($code) . ".%'");
    while ($row = $res->fetch_assoc()) {
        $bkp_id = $row['id'];
        
        foreach ($kats as $katName => $templates) {
            // Kategorie anlegen
            $mysqli->query("INSERT IGNORE INTO bkp_kategorien (bkp_id, name) VALUES ($bkp_id, '" . $mysqli->real_escape_string($katName) . "')");
            $kat_id = $mysqli->insert_id ?: $mysqli->query("SELECT id FROM bkp_kategorien WHERE bkp_id=$bkp_id AND name='".$mysqli->real_escape_string($katName)."'")->fetch_assoc()['id'];

            if ($kat_id) {
                foreach ($templates as $text) {
                    $mysqli->query("INSERT IGNORE INTO bkp_vorlagen_texte (kategorie_id, text) VALUES ($kat_id, '" . $mysqli->real_escape_string($text) . "')");
                }
            }
        }
    }
    echo "BKP $code verarbeitet.\n";
}

echo "\nGlobales Seeding abgeschlossen.";
echo "</pre>";
?>
