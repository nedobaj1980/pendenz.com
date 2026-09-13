<?php
/**
 * pages/ajax_send_pendenz_mail.php
 * Sends an enriched email notification for a specific task to the responsible user.
 */
header('Content-Type: application/json; charset=UTF-8');

// Disable error display for JSON response
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige ID']);
    exit;
}

// Fetch task details and assignee with additional location info
$sql = "
    SELECT p.*, 
           pr.name AS projekt_name, 
           o.name AS objekt_name, 
           w.name AS wohnung_name, 
           r.name AS raum_name,
           b.name AS assignee_name, 
           b.email AS assignee_email,
           eb.name AS creator_name
    FROM pendenzen p
    LEFT JOIN projekte pr ON pr.id = p.projekt_id
    LEFT JOIN objekte o ON o.id = p.objekt_id
    LEFT JOIN wohnungen w ON w.id = p.wohnung_id
    LEFT JOIN raeume r ON r.id = p.raum_id
    LEFT JOIN benutzer b ON b.id = p.zustaendig_id
    LEFT JOIN benutzer eb ON eb.id = p.erstellt_von
    WHERE p.id = $id LIMIT 1
";
$res = $mysqli->query($sql);
$p = $res ? $res->fetch_assoc() : null;

if (!$p) {
    echo json_encode(['success' => false, 'error' => 'Pendenz nicht gefunden']);
    exit;
}

$to = $p['assignee_email'];
if (empty($to)) {
    echo json_encode(['success' => false, 'error' => 'Dem zuständigen Benutzer ist keine E-Mail-Adresse hinterlegt.']);
    exit;
}

// Fetch images
$imgs = [];
$resImgs = $mysqli->query("SELECT pfad, titel FROM pendenz_dateien WHERE pendenz_id = $id AND typ = 'image' ORDER BY is_cover DESC, id ASC LIMIT 5");
if ($resImgs) {
    while ($img = $resImgs->fetch_assoc()) {
        $imgs[] = $img;
    }
}

// Prepare Dates
$erstelltAm = !empty($p['erstellt_am']) ? date('d.m.Y', strtotime($p['erstellt_am'])) : date('d.m.Y');
$startDatum = !empty($p['startdatum']) ? date('d.m.Y', strtotime($p['startdatum'])) : null;
$endDatum = !empty($p['enddatum']) ? date('d.m.Y', strtotime($p['enddatum'])) : null;
$dauer = (int)($p['dauer'] ?? 0);

// Prepare Email Content
$subject = "📧 Pendenz #" . $id . ": " . $p['titel'];

// Build Links
$host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$prefix = rtrim(site_prefix(), '/');
$publicUrl = "";
if ((int)$p['public_enabled'] === 1 && !empty($p['public_token'])) {
    $publicUrl = $host . $prefix . "/pages/pendenz_public.php?t=" . $p['public_token'];
}
$internalUrl = $host . $prefix . "/pages/pendenz_show.php?id=" . $id;
$link = $publicUrl ?: $internalUrl;

// Location String
$locParts = [];
if ($p['projekt_name']) $locParts[] = "<strong>Projekt:</strong> " . h($p['projekt_name']);
if ($p['objekt_name'])  $locParts[] = "<strong>Objekt:</strong> " . h($p['objekt_name']);
if ($p['wohnung_name']) $locParts[] = "<strong>Wohnung:</strong> " . h($p['wohnung_name']);
if ($p['raum_name'])    $locParts[] = "<strong>Raum:</strong> " . h($p['raum_name']);
$locationHtml = implode("<br>", $locParts);

$html = "
<div style='font-family: sans-serif; line-height: 1.6; color: #333; max-width: 600px; border: 1px solid #eee; border-radius: 12px; padding: 20px; background: #fff;'>
    <div style='border-bottom: 2px solid #1abc9c; padding-bottom: 10px; margin-bottom: 20px;'>
        <h2 style='color: #1abc9c; margin: 0;'>Pendenz-Benachrichtigung</h2>
        <p style='font-size: 14px; color: #666; margin: 5px 0 0 0;'>ID #" . $id . "</p>
    </div>

    <p>Guten Tag <strong>" . h($p['assignee_name']) . "</strong>,</p>
    
    <p>Am <strong>" . $erstelltAm . "</strong> wurde eine neue Pendenz erstellt.</p>";

if ($startDatum || $endDatum) {
    $html .= "<p style='background: #fff9db; padding: 10px; border-left: 4px solid #fcc419;'>";
    if ($startDatum) {
        $html .= "Sie müssen mit der Pendenz am <strong>" . $startDatum . "</strong> beginnen";
    }
    if ($dauer > 0) {
        $html .= " und haben dafür <strong>" . $dauer . " Tage</strong> Zeit";
    }
    if ($endDatum) {
        $html .= " bis am <strong>" . $endDatum . "</strong>";
    }
    $html .= ".</p>";
}

$html .= "
    <h3 style='margin-top: 25px; border-bottom: 1px solid #eee; padding-bottom: 5px;'>Info zu der Pendenz</h3>
    <table style='width: 100%; border-collapse: collapse;'>
        <tr><td style='padding: 5px 0; color: #666; width: 120px; vertical-align: top;'>Titel:</td><td style='padding: 5px 0;'><strong>" . h($p['titel']) . "</strong></td></tr>";

if ($p['kurzbeschreibung']) {
    $html .= "<tr><td style='padding: 5px 0; color: #666; vertical-align: top;'>Beschreibung:</td><td style='padding: 5px 0;'>" . nl2br(h($p['kurzbeschreibung'])) . "</td></tr>";
}
if ($p['langbeschreibung']) {
    $html .= "<tr><td style='padding: 5px 0; color: #666; vertical-align: top;'>Details:</td><td style='padding: 5px 0;'>" . nl2br(h($p['langbeschreibung'])) . "</td></tr>";
}

$html .= "</table>

    <div style='margin-top: 20px; padding: 15px; background: #f8fafc; border-radius: 8px;'>
        " . $locationHtml . "
    </div>";

if (!empty($imgs)) {
    $html .= "<h3 style='margin-top: 25px; font-size: 16px; color: #1abc9c;'>Bilder-Vorschau</h3>";
    foreach ($imgs as $img) {
        $imgPath = $host . $prefix . "/" . ltrim($img['pfad'], '/');
        $html .= "<div style='margin-bottom: 15px;'>
                    <a href='" . $link . "' style='text-decoration: none;'>
                        <img src='" . $imgPath . "' width='280' style='display: block; width: 280px; max-width: 100%; border: 1px solid #ddd; border-radius: 8px;' alt='Vorschau'>
                    </a>
                  </div>";
    }
}

$html .= "
    <p style='margin-top: 30px; text-align: center;'>
        <a href='" . $link . "' style='background: #1abc9c; color: #fff; padding: 12px 30px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>Pendenz öffnen & bearbeiten</a>
    </p>
    
    <hr style='border: 0; border-top: 1px solid #eee; margin: 30px 0;'>
    <p style='font-size: 11px; color: #999; text-align: center;'>Erstellt von: " . h($p['creator_name']) . " • pendenz.com</p>
</div>
";

// Attachments logic
$attachments = [];
foreach ($imgs as $img) {
    $fullPath = __DIR__ . '/../' . ltrim($img['pfad'], '/');
    if (file_exists($fullPath)) {
        $attachments[] = [
            'path' => $fullPath,
            'name' => basename($img['pfad'])
        ];
    }
}

$ok = send_mail_html($to, $subject, $html, [
    'from_name' => $p['creator_name'] ?: 'pendenz.com',
    'x_category' => 'pendenz_notification',
    'attachments' => $attachments
]);

if ($ok) {
    $mysqli->query("UPDATE pendenzen SET unt_new_input = 0 WHERE id = $id");
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'E-Mail-Versand fehlgeschlagen.']);
}
