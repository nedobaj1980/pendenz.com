<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/fs.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

header('Content-Type: application/json');

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$pid = (int)($_GET['projekt_id'] ?? 0);
$uid = (int)($_GET['unit_id'] ?? 0);

if ($uid <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige Wohnungs-ID']);
    exit;
}

$netto = (float)($_POST['mietzins_netto'] ?? 0);
$nk = (float)($_POST['nk_akonto'] ?? 0);
$rawDate = $_POST['move_in'] ?? date('Y-m-d');
$move_in = date('Y-m-d', strtotime($rawDate) ?: time());
$mieter_name = trim($_POST['mieter_name'] ?? 'N/A');

// DB Update via Prepared Statement
$stmtU = $mysqli->prepare("UPDATE wohnung_mieter SET mietzins_netto = ?, nk_akonto = ?, startdatum = ?, mieter_name = ? WHERE wohnung_id = ? AND status = 'aktiv'");
$stmtU->bind_param("ddssi", $netto, $nk, $move_in, $mieter_name, $uid);
$stmtU->execute();
$stmtU->close();

// Daten für PDF laden
$unit = $mysqli->query("SELECT w.*, p.name as p_name, p.adresse as p_adresse FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid")->fetch_assoc();

// Template laden
$templatePath = '../templates/mietvertrag_template.html';
if (!file_exists($templatePath)) {
    echo json_encode(['success' => false, 'error' => 'Template nicht gefunden']);
    exit;
}
$html = file_get_contents($templatePath);

$brutto = (float)$netto + (float)$nk;

$replacements = [
    '[[VERMIETER_ADRESSE]]' => 'Baupartnerschaft AG, Musterstrasse 1, 8000 Zürich',
    '[[MIETER_NAME]]' => $mieter_name,
    '[[MIETER_ADRESSE]]' => 'Gemäss Anmeldung',
    '[[PROJEKT_NAME]]' => $unit['p_name'],
    '[[OBJEKT_ADRESSE]]' => $unit['p_adresse'] ?? 'Liegenschaftsadresse',
    '[[WOHNUNG_NAME]]' => $unit['name'],
    '[[ETAGE]]' => $unit['etage'] ?? '-',
    '[[ZIMMER]]' => $unit['zimmer'] ?? '-',
    '[[NETTO]]' => number_format((float)$netto, 2, '.', "'"),
    '[[NK]]' => number_format((float)$nk, 2, '.', "'"),
    '[[BRUTTO]]' => number_format($brutto, 2, '.', "'"),
    '[[BEGINN]]' => date('d.m.Y', strtotime($move_in)),
    '[[ORT]]' => 'Zürich',
    '[[HEUTE]]' => date('d.m.Y')
];

foreach ($replacements as $key => $val) {
    $html = str_replace($key, $val, $html);
}

// PDF Generieren
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$output = $dompdf->output();

// Speichern (Folder-First)
$targetDir = realpath('../') . DIRECTORY_SEPARATOR . $unit['p_name'] . DIRECTORY_SEPARATOR . ($unit['obj_name'] ?? '') . DIRECTORY_SEPARATOR . 'Wohnungen' . DIRECTORY_SEPARATOR . $unit['folder_name'];

if (!is_dir($targetDir)) {
    $targetDir = realpath('../uploads/contracts');
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
}

$filename = 'Mietvertrag_' . str_replace(' ', '_', $mieter_name) . '_' . date('Ymd') . '.pdf';
$filePath = $targetDir . DIRECTORY_SEPARATOR . $filename;

file_put_contents($filePath, $output);

$relativeUrl = str_replace(realpath('../'), '', $filePath);
$relativeUrl = str_replace(DIRECTORY_SEPARATOR, '/', $relativeUrl);
if ($relativeUrl[0] !== '/') $relativeUrl = '/' . $relativeUrl;

// Return success page
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Vertrag erstellt</title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); text-align: center; }
        .success-icon { font-size: 48px; color: #22c55e; margin-bottom: 20px; }
        .btn { display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="success-icon">✅</div>
        <h2>Vertrag erfolgreich erstellt!</h2>
        <p>Das Dokument wurde im Wohnungsordner gespeichert.</p>
        <a href="<?= $relativeUrl ?>" target="_blank" class="btn">PDF jetzt öffnen</a>
        <br>
        <a href="mieterspiegel.php?projekt_id=<?= $pid ?>" style="display:inline-block; margin-top:15px; color: #64748b; font-size:14px;">Zurück zum Mieterspiegel</a>
    </div>
</body>
</html>
