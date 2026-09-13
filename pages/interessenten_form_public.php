<?php
require_once '../config.php';
require_once '../includes/fs.php';

$token = $_GET['token'] ?? '';
$interessent = null;
$wohnung = null;

if ($token) {
    $res = $mysqli->query("SELECT i.*, w.name as w_name, o.name as o_name, p.name as p_name, p.id as p_id 
                           FROM interessenten i 
                           JOIN wohnungen w ON i.wohnung_id = w.id 
                           JOIN objekte o ON w.objekt_id = o.id 
                           JOIN projekte p ON o.projekt_id = p.id 
                           WHERE i.token = '" . $mysqli->real_escape_string($token) . "'");
    $interessent = $res->fetch_assoc();
}

if (!$interessent) {
    die("Ungültiger oder abgelaufener Link. Bitte kontaktieren Sie die Verwaltung.");
}

$success = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vorname = trim($_POST['vorname'] ?? '');
    $nachname = trim($_POST['nachname'] ?? '');
    $telefon = trim($_POST['telefon'] ?? '');
    $geb = $_POST['geburtsdatum'] ?? null;
    $beruf = trim($_POST['beruf'] ?? '');
    $arbeitgeber = trim($_POST['arbeitgeber'] ?? '');
    $gehalt = (float)($_POST['gehalt'] ?? 0);
    $kinder = (int)($_POST['anzahl_kinder'] ?? 0);
    $haustiere = trim($_POST['haustiere'] ?? '');

    // Ordner im Pool finden
    $root = project_root_path($mysqli, $interessent['p_id']);
    $userDir = "";
    if ($root) {
        $cleanName = preg_replace('/[^a-zA-Z0-9]/', '_', $interessent['vorname'] . "_" . $interessent['nachname']);
        $userDir = $root . DIRECTORY_SEPARATOR . '00_Pool' . DIRECTORY_SEPARATOR . 'Interessenten' . DIRECTORY_SEPARATOR . $cleanName . "_" . $interessent['benutzer_id'];
        if (!is_dir($userDir)) @mkdir($userDir, 0777, true);
    }

    // Datei hochladen
    if (isset($_FILES['betreibungsauszug']) && $_FILES['betreibungsauszug']['error'] === UPLOAD_ERR_OK && $userDir) {
        $ext = pathinfo($_FILES['betreibungsauszug']['name'], PATHINFO_EXTENSION);
        $targetFile = $userDir . DIRECTORY_SEPARATOR . "Betreibungsauszug_" . date('Ymd_His') . "." . $ext;
        move_uploaded_file($_FILES['betreibungsauszug']['tmp_name'], $targetFile);
    }

    // Datenbank aktualisieren
    $stmt = $mysqli->prepare("UPDATE interessenten SET 
        vorname = ?, nachname = ?, telefon = ?, geburtsdatum = ?, beruf = ?, arbeitgeber = ?, gehalt_monat = ?, anzahl_personen = ?, haustiere = ?, status = 'eingereicht' 
        WHERE token = ?");
    $anzahl_total = $kinder + 1;
    $stmt->bind_param("ssssssdiss", $vorname, $nachname, $telefon, $geb, $beruf, $arbeitgeber, $gehalt, $anzahl_total, $haustiere, $token);
    
    if ($stmt->execute()) {
        $success = true;
    } else {
        $error = "Fehler beim Speichern: " . $mysqli->error;
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Anmeldung für Mietinteressenten | pendenz.com</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --mp-green: #007a3d; --bg: #f8fafc; --text: #1e293b; --border: #e2e8f0; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: var(--bg); color: var(--text); padding: 20px; line-height: 1.5; }
        .container { max-width: 850px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .header { border-bottom: 3px solid var(--mp-green); padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        .logo-text { font-weight: 800; font-size: 24px; color: var(--mp-green); }
        
        .section-title { background: var(--mp-green); color: white; padding: 8px 15px; font-weight: bold; margin: 30px 0 15px 0; border-radius: 4px; font-size: 14px; text-transform: uppercase; }
        
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 5px; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; box-sizing: border-box; }
        input:focus { outline: none; border-color: var(--mp-green); box-shadow: 0 0 0 3px rgba(0,122,61,0.1); }
        
        .btn { background: var(--mp-green); color: white; padding: 15px 30px; border: none; border-radius: 8px; font-weight: 700; font-size: 16px; cursor: pointer; width: 100%; margin-top: 30px; }
        .btn:hover { background: #006633; }
        
        .info-pill { background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; padding: 15px; border-radius: 8px; margin-bottom: 25px; }

        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="logo-text">pendenz.com</div>
        <div style="text-align: right; color: #64748b; font-size: 14px;">Anmeldeformular</div>
    </div>

    <?php if ($success): ?>
        <div style="text-align: center; padding: 40px;">
            <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
            <h2>Vielen Dank für Ihre Anmeldung!</h2>
            <p>Ihre Daten wurden erfolgreich übermittelt und werden nun geprüft.<br>Wir melden uns in Kürze bei Ihnen.</p>
        </div>
    <?php else: ?>
        <div class="info-pill">
            <strong>Bewerbung für:</strong> <?= htmlspecialchars($interessent['w_name']) ?> <br>
            <small><?= htmlspecialchars($interessent['o_name']) ?> in <?= htmlspecialchars($interessent['p_name']) ?></small>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="section-title">1. Angaben zum Hauptmieter</div>
            <div class="grid">
                <div class="form-group">
                    <label>Vorname</label>
                    <input type="text" name="vorname" value="<?= htmlspecialchars($interessent['vorname']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Nachname</label>
                    <input type="text" name="nachname" value="<?= htmlspecialchars($interessent['nachname']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Strasse / Nr.</label>
                    <input type="text" name="adresse" placeholder="Musterstrasse 123">
                </div>
                <div class="form-group">
                    <label>PLZ / Ort</label>
                    <input type="text" name="plz_ort" placeholder="8000 Zürich">
                </div>
                <div class="form-group">
                    <label>Telefon</label>
                    <input type="tel" name="telefon" placeholder="+41 79 123 45 67">
                </div>
                <div class="form-group">
                    <label>E-Mail</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($interessent['email']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Zivilstand</label>
                    <select name="zivilstand">
                        <option>Ledig</option>
                        <option>Verheiratet</option>
                        <option>Geschieden</option>
                        <option>Verwitwet</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Geburtsdatum</label>
                    <input type="date" name="geburtsdatum">
                </div>
                <div class="form-group">
                    <label>Beruf</label>
                    <input type="text" name="beruf">
                </div>
                <div class="form-group">
                    <label>Arbeitgeber</label>
                    <input type="text" name="arbeitgeber">
                </div>
            </div>

            <div class="section-title">2. Weitere Personen / Mitmieter</div>
            <div class="form-group">
                <label>Anzahl Kinder / Weitere Haushaltsmitglieder</label>
                <input type="number" name="anzahl_kinder" value="0">
            </div>
            <div class="form-group">
                <label>Haustiere (Art und Anzahl)</label>
                <input type="text" name="haustiere" placeholder="z.B. 1 Hund">
            </div>

            <div class="section-title">3. Finanzielle Angaben & Dokumente</div>
            <div class="grid">
                <div class="form-group">
                    <label>Monatliches Nettoeinkommen (CHF)</label>
                    <input type="number" name="gehalt">
                </div>
                <div class="form-group">
                    <label>Aktueller Betreibungsauszug (PDF/Bild)*</label>
                    <input type="file" name="betreibungsauszug" required>
                </div>
            </div>

            <div class="section-title">4. Bestätigung</div>
            <p style="font-size: 12px; color: #64748b;">
                Ich bestätige die Richtigkeit der oben gemachten Angaben und ermächtige die Verwaltung, Referenzauskünfte einzuholen.
            </p>
            
            <button type="submit" class="btn">Bewerbung verbindlich absenden</button>
        </form>
    <?php endif; ?>
</div>

<div style="text-align: center; margin-top: 20px; font-size: 12px; color: #94a3b8;">
    &copy; Baupartnerschaft AG via pendenz.com - Sicher und Digital
</div>

</body>
</html>
