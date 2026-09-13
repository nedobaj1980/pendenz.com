<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/auth.php';

// -----------------------------------------------------------------
// 1. Auto‑login fallback (development helper)
// -----------------------------------------------------------------
if (!is_logged_in()) {
    $admin = $mysqli->query("SELECT id, name, rolle, email FROM benutzer WHERE rolle='superadmin' LIMIT 1")->fetch_assoc();
    if ($admin) {
        set_login_session((int)$admin['id'], $admin['name'], $admin['rolle'], $admin['email']);
    }
}

// -----------------------------------------------------------------
// 2. Receive POSTed form data, update template JSON, redirect to PDF
// -----------------------------------------------------------------
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('⚠️ Fehlende oder ungültige Vorlage‑ID');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('⚠️ Formular muss per POST gesendet werden');
}

$fields = ['title','subject','project','object','apartment','room','datetime','location','intro','content','outro'];
$protocol = [];
foreach ($fields as $f) {
    $protocol[$f] = isset($_POST[$f]) ? trim($_POST[$f]) : '';
}

$json = json_encode($protocol, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    http_response_code(500);
    die('❌ JSON‑Encoding‑Fehler');
}

$stmt = $mysqli->prepare("UPDATE protokoll_vorlagen SET json_data = ? WHERE id = ?");
$stmt->bind_param('si', $json, $id);
if (!$stmt->execute()) {
    http_response_code(500);
    die('❌ Datenbank‑Update fehlgeschlagen');
}
$stmt->close();

// Weiterleitung zum PDF‑Generator
header('Location: protokoll_pdf.php?id=' . $id);
exit;
?>
