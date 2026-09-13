<?php
require_once "config.php";

echo "<h1>Migration: BKP 2 Gebäude Liste</h1>";
echo "<pre>";

$bkp_data = [
    ['code' => '20', 'bezeichnung' => 'Vorbereitende Arbeiten'],
    ['code' => '201', 'bezeichnung' => 'Bauplatzinstallation'],
    ['code' => '21', 'bezeichnung' => 'Rohbau 1'],
    ['code' => '211', 'bezeichnung' => 'Baumeisterarbeiten'],
    ['code' => '211.1', 'bezeichnung' => 'Baugrube'],
    ['code' => '211.2', 'bezeichnung' => 'Beton- und Stahlbetonarbeiten'],
    ['code' => '211.3', 'bezeichnung' => 'Maurerarbeiten'],
    ['code' => '22', 'bezeichnung' => 'Rohbau 2'],
    ['code' => '221', 'bezeichnung' => 'Fenster, Aussentüren, Tore'],
    ['code' => '222', 'bezeichnung' => 'Spenglerarbeiten / Bedachung'],
    ['code' => '222.1', 'bezeichnung' => 'Steildach'],
    ['code' => '222.2', 'bezeichnung' => 'Flachdach'],
    ['code' => '223', 'bezeichnung' => 'Fassadenbekleidung'],
    ['code' => '23', 'bezeichnung' => 'Elektroanlagen'],
    ['code' => '24', 'bezeichnung' => 'Heizungs-, Lüftungs-, Klima-, Kälteanlagen'],
    ['code' => '242', 'bezeichnung' => 'Heizungsanlagen'],
    ['code' => '243', 'bezeichnung' => 'Lüftungsanlagen'],
    ['code' => '244', 'bezeichnung' => 'Klimaanlagen'],
    ['code' => '25', 'bezeichnung' => 'Sanitäranlagen'],
    ['code' => '27', 'bezeichnung' => 'Ausbau 1'],
    ['code' => '271', 'bezeichnung' => 'Gipserarbeiten'],
    ['code' => '272', 'bezeichnung' => 'Metallbauarbeiten'],
    ['code' => '273', 'bezeichnung' => 'Schreinerarbeiten'],
    ['code' => '275', 'bezeichnung' => 'Schliessanlagen'],
    ['code' => '28', 'bezeichnung' => 'Ausbau 2'],
    ['code' => '281', 'bezeichnung' => 'Bodenbeläge'],
    ['code' => '281.2', 'bezeichnung' => 'Parkett / Holz'],
    ['code' => '281.3', 'bezeichnung' => 'Plattenbeläge'],
    ['code' => '285', 'bezeichnung' => 'Malerarbeiten'],
    ['code' => '287', 'bezeichnung' => 'Reinigung'],
    ['code' => '29', 'bezeichnung' => 'Honorare'],
    ['code' => '291', 'bezeichnung' => 'Architekt'],
    ['code' => '292', 'bezeichnung' => 'Spezialisten / Ingenieure'],
];

foreach ($bkp_data as $item) {
    $code = $mysqli->real_escape_string($item['code']);
    $bez = $mysqli->real_escape_string($item['bezeichnung']);
    
    // Check if code exists
    $res = $mysqli->query("SELECT id FROM bkp_codes WHERE code = '$code'");
    if ($res->num_rows > 0) {
        $mysqli->query("UPDATE bkp_codes SET bezeichnung = '$bez' WHERE code = '$code'");
        echo "Updated BKP $code: $bez\n";
    } else {
        $mysqli->query("INSERT INTO bkp_codes (code, bezeichnung) VALUES ('$code', '$bez')");
        echo "Inserted BKP $code: $bez\n";
    }
}

echo "\nMigration abgeschlossen.";
echo "</pre>";
?>
