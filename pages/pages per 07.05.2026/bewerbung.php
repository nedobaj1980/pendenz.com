<?php
// pages/bewerbung.php - Öffentliches Bewerbungsformular
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

$token = isset($_GET['token']) ? $_GET['token'] : '';
if (empty($token)) die("❌ Ungültiger Link.");

// Wohnung laden
$resW = $mysqli->query("
    SELECT w.*, o.name AS objekt_name, pr.adresse AS objekt_adresse
    FROM wohnungen w
    JOIN objekte o ON o.id = w.objekt_id
    JOIN projekte pr ON pr.id = o.projekt_id
    WHERE w.apply_token = '" . $mysqli->real_escape_string($token) . "'
    LIMIT 1
");
$wohnung = $resW->fetch_assoc();

if (!$wohnung) die("❌ Diese Wohnung ist aktuell nicht für Bewerbungen freigeschaltet.");

$flash = "";
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tel = trim($_POST['telefonnummer'] ?? '');
        $beruf = trim($_POST['beruf'] ?? '');
        $adresse = trim($_POST['adresse'] ?? '');
        $kontaktweg = $_POST['kontaktweg'] ?? 'email';
        
        if (empty($name) || empty($email)) {
            throw new Exception("Bitte geben Sie mindestens Ihren Namen und Ihre E-Mailadresse an.");
        }

        // Existiert der Benutzer bereits?
        $resCheck = $mysqli->query("SELECT id FROM benutzer WHERE email='" . $mysqli->real_escape_string($email) . "'");
        $existing = $resCheck->fetch_assoc();

        if ($existing) {
            // Update bestehender Interessent
            $uid = (int)$existing['id'];
            $st = $mysqli->prepare("UPDATE benutzer SET name=?, telefonnummer=?, beruf=?, adresse=?, kontaktweg=?, wohnung_id=? WHERE id=$uid");
            $st->bind_param("sssssi", $name, $tel, $beruf, $adresse, $kontaktweg, $wohnung['id']);
            $st->execute();
            $st->close();
        } else {
            // Neu anlegen als Gast / Bewerber
            $st = $mysqli->prepare("INSERT INTO benutzer (name, email, rolle, telefonnummer, beruf, adresse, kontaktweg, wohnung_id) VALUES (?,?,'gast',?,?,?,?,?)");
            $st->bind_param("ssssssi", $name, $email, $tel, $beruf, $adresse, $kontaktweg, $wohnung['id']);
            $st->execute();
            $st->close();
        }

        $success = true;
        $flash = "Vielen Dank! Ihre Bewerbung für die Wohnung <strong>".htmlspecialchars($wohnung['name'])."</strong> wurde erfolgreich übermittelt. Wir werden uns in Kürze bei Ihnen melden.";

    } catch (Throwable $e) {
        $flash = "❌ Fehler: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Bewerbung für <?= htmlspecialchars($wohnung['name']) ?> | pendenz.com</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #3b82f6; --primary-dark: #2563eb; --slate: #1e293b; --light: #f8fafc; }
        body { font-family: 'Inter', sans-serif; background: var(--light); color: var(--slate); margin:0; padding:0; display:flex; justify-content:center; align-items:center; min-height:100vh; }
        .apply-container { background:#fff; width:100%; max-width:550px; padding:40px; border-radius:24px; box-shadow: 0 20px 50px rgba(0,0,0,0.08); margin:20px; }
        .logo { font-weight:800; font-size:24px; color: var(--primary); margin-bottom:30px; display:flex; align-items:center; gap:8px; text-decoration:none; }
        h1 { font-size:28px; font-weight:800; margin-bottom:10px; line-height:1.2; }
        .apartment-info { background: #eff6ff; padding:15px 20px; border-radius:12px; margin-bottom:30px; border-left: 4px solid var(--primary); }
        .field { margin-bottom:20px; }
        label { display:block; font-weight:700; font-size:14px; margin-bottom:8px; color: #475569; }
        input, select, textarea { width:100%; padding:12px 16px; border:1px solid #e2e8f0; border-radius:10px; font-family:inherit; font-size:15px; box-sizing:border-box; transition: border-color 0.2s; }
        input:focus { border-color: var(--primary); outline:none; }
        .btn { display:block; width:100%; padding:14px; background: var(--primary); color:#fff; border:none; border-radius:12px; font-size:16px; font-weight:700; cursor:pointer; transition: background 0.2s; margin-top:10px; }
        .btn:hover { background: var(--primary-dark); }
        .flash { padding:20px; border-radius:12px; line-height:1.5; margin-bottom:30px; }
        .flash-success { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
        .flash-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .footer { margin-top:30px; text-align:center; font-size:12px; color:#94a3b8; }
    </style>
</head>
<body>

<div class="apply-container">
    <a href="#" class="logo">🏗️ pendenz.com</a>
    
    <?php if($success): ?>
        <div class="flash flash-success"><?= $flash ?></div>
        <p style="text-align:center; color:#64748b;">Sie können dieses Fenster jetzt schliessen.</p>
    <?php else: ?>
        <h1>Mietbewerbung</h1>
        <p style="color:#64748b; margin-bottom:20px;">Wir freuen uns über Ihr Interesse an dieser Wohneinheit. Bitte füllen Sie das Formular aus.</p>

        <div class="apartment-info">
            <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($wohnung['objekt_name']) ?></div>
            <div style="font-size:14px; color:#3b82f6; font-weight:600;"><?= htmlspecialchars($wohnung['name']) ?></div>
            <div style="font-size:12px; color:#64748b; margin-top:4px;">📍 <?= htmlspecialchars($wohnung['objekt_adresse']) ?></div>
        </div>

        <?php if($flash): ?><div class="flash flash-error"><?= $flash ?></div><?php endif; ?>

        <form method="post">
            <div class="field">
                <label>Vorname & Nachname *</label>
                <input type="text" name="name" required placeholder="z.B. Max Mustermann">
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                <div class="field">
                    <label>E-Mail Adresse *</label>
                    <input type="email" name="email" required placeholder="name@beispiel.ch">
                </div>
                <div class="field">
                    <label>Telefonnummer</label>
                    <input type="tel" name="telefonnummer" placeholder="+41 79 123 45 67">
                </div>
            </div>

            <div class="field">
                <label>Aktuelle Adresse</label>
                <input type="text" name="adresse" placeholder="Strasse, PLZ, Ort">
            </div>

            <div class="field">
                <label>Aktueller Beruf / Arbeitgeber</label>
                <input type="text" name="beruf" placeholder="z.B. Projektleiter bei XY AG">
            </div>

            <div class="field">
                <label>Wie sollen wir Sie am besten kontaktieren?</label>
                <select name="kontaktweg">
                    <option value="email">📧 Per E-Mail</option>
                    <option value="telefon">📞 Telefonisch</option>
                    <option value="whatsapp">💬 Via WhatsApp</option>
                    <option value="sms">📱 Per SMS</option>
                    <option value="post">📮 Per Postweg</option>
                </select>
            </div>

            <button type="submit" class="btn">Bewerbung absenden</button>
        </form>
    <?php endif; ?>

    <div class="footer">
        &copy; <?= date('Y') ?> pendenz.com | Professionelles Immobilienmanagement
    </div>
</div>

</body>
</html>
