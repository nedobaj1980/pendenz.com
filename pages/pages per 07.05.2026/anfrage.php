<?php
// pages/anfrage.php (Öffentlich!)
require_once __DIR__ . '/../config.php';

$wid = (int)($_GET['wohnung_id'] ?? 0);
if ($wid <= 0) die("Ungültige Anfrage.");

// Wohnungsdaten für die Anzeige holen
$res = $mysqli->query("
    SELECT w.name as w_name, o.name as o_name, w.zimmer, w.flaeche, w.etage 
    FROM wohnungen w 
    JOIN objekte o ON w.objekt_id = o.id 
    WHERE w.id = $wid
");
$w = $res->fetch_assoc();
if (!$w) die("Wohnung nicht gefunden.");

$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $tel = trim($_POST['telefon']);
    $msg = trim($_POST['nachricht']);
    
    $selbstauskunft = json_encode([
        'nachricht' => $msg,
        'personen' => $_POST['personen'] ?? '',
        'beruf' => $_POST['beruf'] ?? '',
        'einkommen' => $_POST['einkommen'] ?? ''
    ]);

    $stmt = $mysqli->prepare("INSERT INTO miet_interessenten (wohnung_id, name, email, telefon, selbstauskunft_json) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $wid, $name, $email, $tel, $selbstauskunft);
    if ($stmt->execute()) {
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mietanfrage - <?= htmlspecialchars($w['w_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .form-card { background: #fff; max-width: 600px; width: 100%; padding: 40px; border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .header { text-align: center; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 800; color: #1e293b; }
        .unit-badge { display: inline-block; background: #3b82f6; color: #fff; padding: 6px 15px; border-radius: 20px; font-size: 13px; font-weight: 700; margin-top: 10px; }
        
        .input-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
        input, textarea, select { width: 100%; padding: 12px 15px; border-radius: 12px; border: 1px solid #cbd5e1; font-family: inherit; font-size: 16px; box-sizing: border-box; }
        input:focus, textarea:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        
        .btn-submit { background: #3b82f6; color: #fff; border: none; padding: 15px; width: 100%; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 10px; }
        .btn-submit:hover { background: #2563eb; transform: translateY(-2px); }
        
        .success-overlay { text-align: center; padding: 40px; }
        .success-icon { font-size: 60px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="form-card">
    <?php if ($success): ?>
        <div class="success-overlay">
            <div class="success-icon">✅</div>
            <h1>Vielen Dank!</h1>
            <p>Deine Mietanfrage wurde erfolgreich übermittelt. Wir werden uns in Kürze bei dir melden.</p>
        </div>
    <?php else: ?>
        <div class="header">
            <h1>Mietanfrage senden</h1>
            <div class="unit-badge">🏠 <?= htmlspecialchars($w['w_name']) ?> (<?= htmlspecialchars($w['etage']) ?>)</div>
            <p style="color:#64748b; margin-top:15px;"><?= htmlspecialchars($w['o_name']) ?> • <?= $w['zimmer'] ?> Zimmer • <?= $w['flaeche'] ?> m²</p>
        </div>

        <form method="POST">
            <div class="input-group">
                <label>Vollständiger Name</label>
                <input type="text" name="name" placeholder="Erika Mustermann" required>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                <div class="input-group">
                    <label>E-Mail Adresse</label>
                    <input type="email" name="email" placeholder="name@beispiel.de" required>
                </div>
                <div class="input-group">
                    <label>Telefonnummer</label>
                    <input type="tel" name="telefon" placeholder="+41 79 ..." required>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                <div class="input-group">
                    <label>Anzahl Personen</label>
                    <input type="number" name="personen" min="1" value="1">
                </div>
                <div class="input-group">
                    <label>Aktueller Beruf</label>
                    <input type="text" name="beruf" placeholder="z.B. IT-Spezialist">
                </div>
            </div>

            <div class="input-group">
                <label>Ungefähres Hauserhaltseinkommen (mtl. Netto)</label>
                <input type="text" name="einkommen" placeholder="z.B. CHF 6'500.-">
            </div>

            <div class="input-group">
                <label>Deine Nachricht / Bemerkungen</label>
                <textarea name="nachricht" rows="4" placeholder="Erzähle uns kurz etwas über dich..."></textarea>
            </div>

            <button type="submit" class="btn-submit">Anfrage digital senden</button>
            <p style="font-size:12px; color:#94a3b8; text-align:center; margin-top:20px;">
                Mit dem Absenden erklärst du dich mit der Verarbeitung deiner Daten einverstanden.
            </p>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
