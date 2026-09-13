<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../vendor/autoload.php'; // Dompdf

// -----------------------------------------------------------------
// 1. Auto‑login fallback (Entwicklungs‑Helper)
// -----------------------------------------------------------------
if (!is_logged_in()) {
    $admin = $mysqli->query("SELECT id, name, rolle, email FROM benutzer WHERE rolle='superadmin' LIMIT 1")->fetch_assoc();
    if ($admin) {
        set_login_session((int)$admin['id'], $admin['name'], $admin['rolle'], $admin['email']);
    }
}

// -----------------------------------------------------------------
// 2. Load the requested protocol template
// -----------------------------------------------------------------
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('⚠️ Fehlende oder ungültige Vorlage‑ID');
}

$stmt = $mysqli->prepare(
    "SELECT v.*, t.name AS typ_name, t.icon AS typ_icon
     FROM protokoll_vorlagen v
     JOIN protokoll_typen t ON v.typ_id = t.id
     WHERE v.id = ?"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$template = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$template) {
    http_response_code(404);
    die('❌ Vorlage nicht gefunden');
}

// JSON‑Daten der Designer‑Ansicht (Layout + Felder)
$protocol = [];
if (!empty($template['json_data'])) {
    $protocol = json_decode($template['json_data'], true) ?? [];
}

// -----------------------------------------------------------------
// 3. Branding (Logo, Firma, Farben)
// -----------------------------------------------------------------
$uid = current_user_id();
$user = $mysqli->query("SELECT b.*, f.* FROM benutzer b LEFT JOIN firmen f ON b.firma_id = f.id WHERE b.id = $uid")->fetch_assoc();
$brand = [
    'name'    => $user['f_name']    ?? $user['firma_name'] ?? $user['firma'] ?? 'Pendenz GmbH',
    'address' => $user['f_adresse'] ?? $user['firma_adresse'] ?? '',
    'city'    => $user['f_ort']     ?? $user['firma_ort'] ?? '',
    'tel'     => $user['f_tel']     ?? $user['firma_telefon'] ?? '',
    'email'   => $user['f_email']   ?? $user['firma_email'] ?? '',
    'web'     => $user['f_web']     ?? $user['firma_website'] ?? '',
    'logo'    => $user['f_logo']    ?? $user['firmenlogo'] ?? ''
];

$brandLogo = null;
if (!empty($brand['logo'])) {
    $logoPath = realpath(__DIR__ . '/../' . ltrim($brand['logo'], '/'));
    if ($logoPath && is_file($logoPath)) {
        $data = @file_get_contents($logoPath);
        if ($data) {
            $brandLogo = 'data:image/png;base64,' . base64_encode($data);
        }
    }
}

// -----------------------------------------------------------------
// 4. Render HTML for Dompdf (minimal, print‑optimiert)
// -----------------------------------------------------------------
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

ob_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title><?= esc($template['name'] ?? 'Protokoll') ?> – PDF</title>
    <style>
        @page { margin: 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; color:#222; line-height:1.45; }
        h1 { font-size:1.6em; margin:0 0 .3em; color:#0f172a; }
        h2 { font-size:1.2em; margin:1em 0 .3em; color:#1e293b; }
        .header { border-bottom:2px solid #0f172a; padding-bottom:.5em; margin-bottom:1em; }
        .logo { max-height:60px; margin-bottom:.5em; }
        .grid { width:100%; border-collapse:collapse; margin-top:.5em; }
        .grid td { padding:.4em 0; vertical-align:top; }
        .label { font-weight:600; color:#64748b; width:120px; }
        .section { margin-bottom:1.5em; }
    </style>
</head>
<body>
    <div class="header">
        <?php if ($brandLogo): ?>
            <img src="<?= $brandLogo ?>" alt="Logo" class="logo">
        <?php endif; ?>
        <h1><?= esc($brand['name']) ?></h1>
        <p>
            <?= esc($brand['address']) ?><br>
            <?= esc($brand['city']) ?>
            <?php if ($brand['tel']): ?> | Tel: <?= esc($brand['tel']) ?><?php endif; ?>
            <?php if ($brand['email']): ?> | Mail: <?= esc($brand['email']) ?><?php endif; ?>
        </p>
    </div>

    <div class="section">
        <h2><?= esc($template['name'] ?? 'Protokoll') ?></h2>
        <?php if (!empty($protocol['title'])): ?><p><strong>Titel:</strong> <?= esc($protocol['title']) ?></p><?php endif; ?>
        <?php if (!empty($protocol['subject'])): ?><p><strong>Betreff:</strong> <?= esc($protocol['subject']) ?></p><?php endif; ?>
    </div>

    <table class="grid">
        <?php if (!empty($protocol['project'])): ?>
            <tr><td class="label">Projekt:</td><td><?= esc($protocol['project']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($protocol['object'])): ?>
            <tr><td class="label">Objekt:</td><td><?= esc($protocol['object']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($protocol['apartment'])): ?>
            <tr><td class="label">Wohnung:</td><td><?= esc($protocol['apartment']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($protocol['room'])): ?>
            <tr><td class="label">Raum:</td><td><?= esc($protocol['room']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($protocol['datetime'])): ?>
            <tr><td class="label">Datum/Zeit:</td><td><?= esc($protocol['datetime']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($protocol['location'])): ?>
            <tr><td class="label">Ort:</td><td><?= esc($protocol['location']) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if (!empty($protocol['intro'])): ?>
        <div class="section"><h2>Einleitung</h2><p><?= nl2br(esc($protocol['intro'])) ?></p></div>
    <?php endif; ?>

    <?php if (!empty($protocol['participants'])): ?>
        <div class="section"><h2>Teilnehmer</h2>
            <table class="grid">
                <?php foreach ($protocol['participants'] as $p): ?>
                    <tr>
                        <td class="label"><?= esc($p['role'] ?? $p['position'] ?? '-') ?></td>
                        <td><?= esc($p['name'] ?? '-') ?> – <?= esc($p['email'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($protocol['content'])): ?>
        <div class="section"><h2>Inhalt</h2><p><?= nl2br(esc($protocol['content'])) ?></p></div>
    <?php endif; ?>

    <?php if (!empty($protocol['outro'])): ?>
        <div class="section"><h2>Schlusswort</h2><p><?= nl2br(esc($protocol['outro'])) ?></p></div>
    <?php endif; ?>

    <footer style="margin-top:2cm; font-size:9pt; text-align:center; color:#777;">
        Erstellt am <?= date('d.m.Y H:i') ?>
    </footer>
</body>
</html>
<?php
$html = ob_get_clean();

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'Protokoll_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $template['name'] ?? 'download') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
exit;
?>
