<?php
require_once 'config.php';

$pid = 5; // Marbach

echo "--- DEBUG WOHNUNGEN ---\n";
$res = $mysqli->query("DESCRIBE wohnungen");
while($row = $res->fetch_assoc()) echo $row['Field'] . " (" . $row['Type'] . ")\n";
echo "-----------------------\n";

$data = [
    ['UG', 'Einzellgarage', '', '05.07.2024', 0, 0, 'Ghilotti Michael und Rusu Laura', 110.00, 0.00],
    ['UG', 'Einzellgarage', '', '01.04.2022', 0, 0, 'Zbieg Pawel Marek', 100.00, 0.00],
    ['UG', 'PP 5 Stk', '', '01.02.2022', 0, 0, '', 200.00, 0.00],
    ['UG', '1.5 Zi. Wohnung', 'Rechts', '14.06.2024', 1.5, 1, 'Raducanu Iliutä und Braileanu Garofita', 980.00, 100.00],
    ['UG', '1 Zi. Hobbyraum', 'Links', '12.07.2024', 1, 1, 'Suter Stephan', 560.00, 80.00],
    ['EG', '3.5 Zi. Wohnung', 'Links', '01.10.2023', 3.5, 2, 'Zbieg Pawel Marek & Jankowiak Dorota Waleria', 1180.00, 150.00],
    ['EG', '4.5 Zi. Wohnung', 'Rechts', '01.06.2024', 4.5, 1, 'Sabina Martinello Sekou Sasay', 1350.00, 200.00],
    ['OG1', '3.5 Zi. Wohnung', 'Links', '15.06.2024', 3.5, 2, 'Gjekaj Marjan', 1180.00, 150.00],
    ['OG1', '4.5 Zi. Wohnung', 'Rechts', '15.06.2024', 4.5, 1, 'Kamberi Shpetim', 1350.00, 200.00],
    ['OG2', '3.5 Zi. Wohnung', 'Links', '01.08.2024', 3.5, 2, 'Wunderli Patrick', 1180.00, 150.00],
    ['OG2', '4.5 Zi. Wohnung', 'Rechts', '01.09.2024', 4.5, 1, 'Maliqi Rrezon & Maliqi Marigona', 1350.00, 200.00],
    ['DG', '3.5 Zi. Attika', 'Links', '01.07.2024', 3.5, 1, 'Zogaj Alban', 1250.00, 150.00],
    ['DG', '4.5 Zi. Attika', 'Rechts', '01.09.2024', 4.5, 1, 'Sabani Adnan', 1450.00, 200.00],
];

foreach ($data as $row) {
    list($etage, $name, $ausr, $beginn, $zimmer, $bad, $mieter, $netto, $nk) = $row;
    
    $name_esc = $mysqli->real_escape_string($name);
    $etage_esc = $mysqli->real_escape_string($etage);
    $ausr_esc = $mysqli->real_escape_string($ausr);

    echo "Verarbeite: $name in $etage ($ausr)\n";

    $sql_check = "SELECT id FROM wohnungen WHERE objekt_id = $pid AND name = '$name_esc' AND etage = '$etage_esc' AND ausrichtung = '$ausr_esc'";
    $check = $mysqli->query($sql_check);
    
    if (!$check) {
        die("Fehler bei Check: " . $mysqli->error . "\nSQL: $sql_check");
    }

    if ($check->num_rows > 0) {
        $wid = $check->fetch_assoc()['id'];
        echo "Gefunden ID: $wid\n";
    } else {
        $sql_ins = "INSERT INTO wohnungen (objekt_id, name, etage, zimmer, baeder, ausrichtung, typ) VALUES ($pid, '$name_esc', '$etage_esc', ".(float)$zimmer.", ".(float)$bad.", '$ausr_esc', 'wohnung')";
        if (!$mysqli->query($sql_ins)) {
            die("Fehler bei Insert Wohnung: " . $mysqli->error . "\nSQL: $sql_ins");
        }
        $wid = $mysqli->insert_id;
        echo "Neu erstellt ID: $wid\n";
    }
    
    if ($mieter) {
        $start = date('Y-m-d', strtotime($beginn));
        $mieter_esc = $mysqli->real_escape_string($mieter);
        $sql_mieter = "INSERT INTO wohnung_mieter (wohnung_id, benutzer_id, mieter_name, mietzins_netto, nk_akonto, rolle, startdatum, status) 
                       VALUES ($wid, 10, '$mieter_esc', $netto, $nk, 'mieter', '$start', 'aktiv')
                       ON DUPLICATE KEY UPDATE mieter_name = VALUES(mieter_name), mietzins_netto = VALUES(mietzins_netto), nk_akonto = VALUES(nk_akonto)";
        
        if (!$mysqli->query($sql_mieter)) {
            die("Fehler bei Mieter: " . $mysqli->error . "\nSQL: $sql_mieter");
        }
        echo "Mieter zugeordnet: $mieter\n";
    }
    echo "-------------------\n";
}

echo "Import für Marbach abgeschlossen.\n";
?>
