<?php
require_once '../config.php';
header('Content-Type: application/json');

$wid = (int)($_GET['wohnung_id'] ?? 0);
if (!$wid) { echo json_encode([]); exit; }

// Check table name
$intTable = "miet_interessenten";
$resCheck = $mysqli->query("SHOW TABLES LIKE 'miet_interessenten'");
if (!$resCheck || $resCheck->num_rows == 0) {
    $resCheck2 = $mysqli->query("SHOW TABLES LIKE 'interessenten'");
    if ($resCheck2 && $resCheck2->num_rows > 0) $intTable = "interessenten";
}

$applicants = [];
$res = $mysqli->query("SELECT id, vorname, nachname, email, status FROM $intTable WHERE wohnung_id = $wid AND status != 'abgelehnt' ORDER BY created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $applicants[] = $row;
    }
}

echo json_encode($applicants);
