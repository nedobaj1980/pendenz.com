<?php
require_once "config.php";

echo "<h1>Migration: Erweiterte BKP 2 Gebäude Liste</h1>";
echo "<pre>";

$bkp_data = [
    // 20 Vorbereitende Arbeiten
    ['code' => '20', 'bezeichnung' => 'Vorbereitende Arbeiten'],
    ['code' => '201', 'bezeichnung' => 'Bauplatzinstallationen'],
    ['code' => '202', 'bezeichnung' => 'Sicherung, Provisorien'],
    ['code' => '203', 'bezeichnung' => 'Abbruch / Demontagen'],

    // 21 Rohbau 1
    ['code' => '21', 'bezeichnung' => 'Rohbau 1'],
    ['code' => '211', 'bezeichnung' => 'Baumeisterarbeiten'],
    ['code' => '211.1', 'bezeichnung' => 'Baugrubenaushub'],
    ['code' => '211.2', 'bezeichnung' => 'Beton- und Stahlbeton'],
    ['code' => '211.3', 'bezeichnung' => 'Maurerarbeiten'],
    ['code' => '211.4', 'bezeichnung' => 'Kanalisation / Werkleitungen'],
    ['code' => '212', 'bezeichnung' => 'Spezialtiefbau'],
    ['code' => '214', 'bezeichnung' => 'Montagebau (Beton / Stein)'],
    ['code' => '215', 'bezeichnung' => 'Stahlbau'],

    // 22 Rohbau 2
    ['code' => '22', 'bezeichnung' => 'Rohbau 2'],
    ['code' => '221', 'bezeichnung' => 'Fenster, Aussentüren, Tore'],
    ['code' => '221.1', 'bezeichnung' => 'Fenster aus Holz / Metall'],
    ['code' => '221.2', 'bezeichnung' => 'Aussentüren / Tore'],
    ['code' => '222', 'bezeichnung' => 'Spenglerarbeiten'],
    ['code' => '223', 'bezeichnung' => 'Fassadenbekleidungen'],
    ['code' => '224', 'bezeichnung' => 'Bedachung / Abdichtung'],
    ['code' => '225', 'bezeichnung' => 'Sonnenschutz / Lamellen'],

    // 23 Elektro
    ['code' => '23', 'bezeichnung' => 'Elektroanlagen'],
    ['code' => '231', 'bezeichnung' => 'Starkstromanlagen'],
    ['code' => '232', 'bezeichnung' => 'Schwachstrom / Telecom'],
    ['code' => '233', 'bezeichnung' => 'Leuchten / Lampen'],

    // 24 HLK
    ['code' => '24', 'bezeichnung' => 'Heizung / Lüftung / Klima'],
    ['code' => '242', 'bezeichnung' => 'Heizungsanlagen'],
    ['code' => '243', 'bezeichnung' => 'Lüftungsanlagen'],
    ['code' => '244', 'bezeichnung' => 'Klimaanlagen'],

    // 25 Sanitär
    ['code' => '25', 'bezeichnung' => 'Sanitäranlagen'],
    ['code' => '251', 'bezeichnung' => 'Sanitärapparate'],
    ['code' => '252', 'bezeichnung' => 'Sanitärleitungen'],
    ['code' => '258', 'bezeichnung' => 'Kücheneinrichtungen'],

    // 27 Ausbau 1
    ['code' => '27', 'bezeichnung' => 'Ausbau 1'],
    ['code' => '271', 'bezeichnung' => 'Gipserarbeiten'],
    ['code' => '271.1', 'bezeichnung' => 'Innenputze / Grundputz'],
    ['code' => '271.2', 'bezeichnung' => 'Trockenbau / Leichtbau'],
    ['code' => '272', 'bezeichnung' => 'Metallbauarbeiten (innen)'],
    ['code' => '273', 'bezeichnung' => 'Schreinerarbeiten'],
    ['code' => '273.1', 'bezeichnung' => 'Innentüren'],
    ['code' => '273.2', 'bezeichnung' => 'Wandschränke / Garderoben'],
    ['code' => '275', 'bezeichnung' => 'Schliessanlagen'],

    // 28 Ausbau 2
    ['code' => '28', 'bezeichnung' => 'Ausbau 2'],
    ['code' => '281', 'bezeichnung' => 'Bodenbeläge'],
    ['code' => '281.1', 'bezeichnung' => 'Unterlagsböden'],
    ['code' => '281.2', 'bezeichnung' => 'Parkett / Holz'],
    ['code' => '281.3', 'bezeichnung' => 'Plattenbeläge (Keramik / Stein)'],
    ['code' => '281.4', 'bezeichnung' => 'Textile Beläge / Teppich'],
    ['code' => '285', 'bezeichnung' => 'Malerarbeiten'],
    ['code' => '285.1', 'bezeichnung' => 'Malerarbeiten (innen)'],
    ['code' => '285.2', 'bezeichnung' => 'Malerarbeiten (aussen)'],
    ['code' => '287', 'bezeichnung' => 'Reinigungsarbeiten'],
    ['code' => '287.1', 'bezeichnung' => 'Baugrobreinigung'],
    ['code' => '287.2', 'bezeichnung' => 'Baufeinreinigung'],

    // 29 Honorare
    ['code' => '29', 'bezeichnung' => 'Honorare'],
    ['code' => '291', 'bezeichnung' => 'Architekt'],
    ['code' => '292', 'bezeichnung' => 'Bauingenieur'],
    ['code' => '293', 'bezeichnung' => 'Elektroingenieur'],
    ['code' => '294', 'bezeichnung' => 'HLK-Ingenieur'],
    ['code' => '295', 'bezeichnung' => 'Sanitäringenieur'],
];

foreach ($bkp_data as $item) {
    $code = $mysqli->real_escape_string($item['code']);
    $bez = $mysqli->real_escape_string($item['bezeichnung']);
    
    $res = $mysqli->query("SELECT id FROM bkp_codes WHERE code = '$code'");
    if ($res->num_rows > 0) {
        $mysqli->query("UPDATE bkp_codes SET bezeichnung = '$bez' WHERE code = '$code'");
        echo "Updated BKP $code: $bez\n";
    } else {
        $mysqli->query("INSERT INTO bkp_codes (code, bezeichnung) VALUES ('$code', '$bez')");
        echo "Inserted BKP $code: $bez\n";
    }
}

echo "\nErweiterte Migration abgeschlossen.";
echo "</pre>";
?>
