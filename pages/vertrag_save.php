<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

require_login();

$pid = (int)($_GET['projekt_id'] ?? 0);
$uid = (int)($_GET['unit_id'] ?? 0);

if ($uid <= 0) {
    die("Ungültige Wohnungs-ID.");
}

// Load apartment & project data
$unit = $mysqli->query("SELECT w.*, p.id as p_id, p.name as p_name, p.adresse as p_adresse, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid")->fetch_assoc();
if (!$unit) {
    die("Wohnung nicht gefunden.");
}
if ($pid <= 0 && !empty($unit['p_id'])) {
    $pid = (int)$unit['p_id'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: vertrag_gen.php?projekt_id=$pid&unit_id=$uid");
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

// Template laden
$templatePath = dirname(__DIR__) . '/templates/mietvertrag_template.html';
if (!file_exists($templatePath)) {
    die("Template 'templates/mietvertrag_template.html' nicht gefunden.");
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
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$output = $dompdf->output();

// 1. Lokale Kopie für Web-Vorschau in uploads/contracts
$localDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'contracts';
if (!is_dir($localDir)) {
    @mkdir($localDir, 0777, true);
}
$safeMieter = preg_replace('/[^a-zA-Z0-9_-]/', '_', $mieter_name);
$filename = 'Mietvertrag_' . $safeMieter . '_' . date('Ymd_His') . '.pdf';
$localFilePath = $localDir . DIRECTORY_SEPARATOR . $filename;
file_put_contents($localFilePath, $output);
$relativeUrl = '../uploads/contracts/' . $filename;

// 2. Automatischer Speicherort im Google Drive der Wohnung: 10_Mietsache/<Wohnung>/04_Vertraege/
$driveSavedPath = null;
if ($uid > 0) {
    $entityPath = fs_get_entity_path($mysqli, 'wohnung', $uid);
    if (!empty($entityPath['abs']) && is_dir($entityPath['abs'])) {
        $driveVertraegeDir = $entityPath['abs'] . DIRECTORY_SEPARATOR . '04_Vertraege';
        if (!is_dir($driveVertraegeDir)) @mkdir($driveVertraegeDir, 0777, true);
        
        $drivePdfFile = $driveVertraegeDir . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($drivePdfFile, $output)) {
            $driveSavedPath = $drivePdfFile;
            
            // Im Dateimanager (fs_nodes) registrieren
            $driveRel = $entityPath['rel'] . '/04_Vertraege/' . $filename;
            $parentRel = $entityPath['rel'] . '/04_Vertraege';
            $sz = strlen($output);
            $stN = $mysqli->prepare("INSERT INTO fs_nodes (project_id, rel_path, name, parent_rel_path, is_dir, size, mtime) VALUES (?, ?, ?, ?, 0, ?, NOW()) ON DUPLICATE KEY UPDATE size=VALUES(size), mtime=NOW()");
            if ($stN) {
                $stN->bind_param("isssi", $pid, $driveRel, $filename, $parentRel, $sz);
                $stN->execute();
                $stN->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mietvertrag erstellt - <?= htmlspecialchars($unit['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Outfit', sans-serif; 
            background: radial-gradient(at 0% 0%, rgba(241,245,249,1) 0, transparent 50%), radial-gradient(at 100% 100%, rgba(219,234,254,1) 0, transparent 50%);
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 0; 
            padding: 20px;
        }
        .card { 
            background: white; 
            padding: 40px; 
            border-radius: 20px; 
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.1); 
            max-width: 580px; 
            width: 100%; 
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .success-badge { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            width: 70px; 
            height: 70px; 
            border-radius: 50%; 
            background: #dcfce7; 
            color: #16a34a; 
            font-size: 36px; 
            margin-bottom: 20px; 
        }
        .btn { 
            display: inline-block; 
            background: #3b82f6; 
            color: white; 
            padding: 12px 24px; 
            border-radius: 12px; 
            text-decoration: none; 
            font-weight: 700; 
            transition: 0.2s; 
        }
        .btn:hover { 
            background: #2563eb; 
            transform: translateY(-2px); 
        }
        .drive-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="success-badge">✅</div>
        <h2 style="margin:0 0 8px 0; font-size:24px; font-weight:800; color:#1e293b;">Mietvertrag generiert!</h2>
        <p style="color:#64748b; margin:0 0 20px 0; font-size:15px;">
            Der Vertrag für <strong><?= htmlspecialchars($mieter_name) ?></strong> (<?= htmlspecialchars($unit['name']) ?>) wurde erfolgreich erstellt.
        </p>

        <div class="drive-box">
            <div style="font-weight:700; color:#1e293b; margin-bottom:4px;">☁️ Ablage im Google Drive:</div>
            <?php if ($driveSavedPath): ?>
                <div style="font-family:monospace; color:#059669; word-break:break-all; font-size:11px;">
                    <?= htmlspecialchars($driveSavedPath) ?>
                </div>
            <?php else: ?>
                <div style="color:#64748b; font-size:12px;">In 'uploads/contracts' gesichert.</div>
            <?php endif; ?>
        </div>

        <div style="display:flex; flex-direction:column; gap:10px; margin-top:20px;">
            <a href="<?= $relativeUrl ?>" target="_blank" class="btn" style="background:#2563eb;">📄 PDF-Vertrag jetzt öffnen & drucken</a>
            <div style="display:flex; gap:10px; justify-content:center; margin-top:10px;">
                <a href="wohnungsabnahme_protokoll.php?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>" class="btn" style="background:#10b981; font-size:13px; padding:10px 18px;">🔑 Abnahmeprotokoll erstellen</a>
                <a href="files.php?projekt_id=<?= $pid ?>" class="btn" style="background:#6366f1; font-size:13px; padding:10px 18px;">📁 In Drive öffnen</a>
            </div>
            <a href="mieterspiegel.php?projekt_id=<?= $pid ?>" style="color:#64748b; font-size:14px; font-weight:600; text-decoration:none; margin-top:15px;">← Zurück zum Mieterspiegel</a>
        </div>
    </div>
</body>
</html>
