<?php
// pages/ajax_applicants.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$wid = (int)($_GET['wohnung_id'] ?? 0);
if ($wid <= 0) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$res = $mysqli->query("SELECT * FROM miet_interessenten WHERE wohnung_id = $wid AND status = 'offen' ORDER BY created_at DESC");
$applicants = [];
while($row = $res->fetch_assoc()) {
    $applicants[] = $row;
}

header('Content-Type: application/json');
echo json_encode($applicants);
exit;
