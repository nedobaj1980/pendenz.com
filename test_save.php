<?php
require_once 'config.php';
$mysqli = new mysqli('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $pTitel = "Test Titel";
    $comment = "Test Kommentar";
    $vorgangsartId = 1;
    $bkpId = null;
    $pErsteller = 1;
    $pZustaendig = 1;
    $pStart = date('Y-m-d');
    $pDauer = 7;
    $existingId = 0;
    
    // Simulate INSERT
    $stmtP = $mysqli->prepare("INSERT INTO pendenzen (projekt_id, objekt_id, wohnung_id, titel, kurzbeschreibung, vorgangsart_id, bkp_id, status, erstellt_von, zustaendig_id, startdatum, dauer, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'offen', ?, ?, ?, ?, NOW())");
    
    $pid = 4;
    $objId = 1;
    $uid = 85;
    
    $stmtP->bind_param("iiissiiiisi", $pid, $objId, $uid, $pTitel, $comment, $vorgangsartId, $bkpId, $pErsteller, $pZustaendig, $pStart, $pDauer);
    
    echo "Executing INSERT...\n";
    $stmtP->execute();
    echo "INSERT Success! ID: " . $stmtP->insert_id . "\n";
    
    $newId = $stmtP->insert_id;
    
    // Simulate UPDATE
    $stmtU = $mysqli->prepare("UPDATE pendenzen SET titel=?, kurzbeschreibung=?, vorgangsart_id=?, bkp_id=?, zustaendig_id=?, startdatum=?, dauer=?, geaendert_am=NOW() WHERE id=?");
    $stmtU->bind_param("ssiiisii", $pTitel, $comment, $vorgangsartId, $bkpId, $pZustaendig, $pStart, $pDauer, $newId);
    
    echo "Executing UPDATE...\n";
    $stmtU->execute();
    echo "UPDATE Success!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
