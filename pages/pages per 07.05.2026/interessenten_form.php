<?php
// pages/interessenten_form.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$wid = isset($_GET['wid']) ? (int)$_GET['wid'] : 0;
$wohnung = null;
if ($wid > 0) {
    $res = $mysqli->query("SELECT w.*, o.name as objekt_name, p.name as projekt_name 
                           FROM wohnungen w 
                           JOIN objekte o ON w.objekt_id = o.id 
                           JOIN projekte p ON o.projekt_id = p.id 
                           WHERE w.id = $wid");
    $wohnung = $res->fetch_assoc();
}

$flash = "";
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $vorname    = trim($_POST['vorname'] ?? '');
        $nachname   = trim($_POST['nachname'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $telefon    = trim($_POST['telefon'] ?? '');
        $geburtsdatum = $_POST['geburtsdatum'] ?: null;
        $gehalt     = $_POST['gehalt_monat'] ?: null;
        $beruf      = trim($_POST['beruf'] ?? '');
        $arbeitgeber= trim($_POST['arbeitgeber'] ?? '');
        $anzahl     = (int)($_POST['anzahl_personen'] ?? 1);
        $haustiere  = trim($_POST['haustiere'] ?? '');
        $instrumente= trim($_POST['instrumente'] ?? '');
        $bemerkungen= trim($_POST['bemerkungen'] ?? '');

        if (!$vorname || !$nachname || !$email) throw new Exception("Bitte füllen Sie alle Pflichtfelder aus (Vorname, Nachname, Email).");

        $stmt = $mysqli->prepare("INSERT INTO interessenten (wohnung_id, vorname, nachname, email, telefon, geburtsdatum, gehalt_monat, beruf, arbeitgeber, anzahl_personen, haustiere, instrumente, bemerkungen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("isssssdssisss", $wid, $vorname, $nachname, $email, $telefon, $geburtsdatum, $gehalt, $beruf, $arbeitgeber, $anzahl, $haustiere, $instrumente, $bemerkungen);
        $stmt->execute();
        $success = true;
        $flash = "✅ Vielen Dank! Ihre Bewerbung wurde erfolgreich übermittelt.";
    } catch (Exception $e) {
        $flash = "❌ Fehler: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mietinteressentenformular | pendenz.com</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .form-container { max-width: 800px; margin: 40px auto; padding: 32px; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        h1 { color: #1e293b; margin-bottom: 8px; }
        .property-info { background: #f1f5f9; padding: 16px; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid #3b82f6; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full-width { grid-column: 1 / -1; }
        label { display: block; font-weight: 600; color: #475569; margin-bottom: 6px; font-size: 14px; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
        button { background: #3b82f6; color: white; border: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s; width: 100%; margin-top: 20px; font-size: 16px; }
        button:hover { background: #2563eb; }
        .message { padding: 16px; border-radius: 8px; margin-bottom: 24px; font-weight: 500; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <div class="form-container">
        <?php if ($flash): ?>
            <div class="message <?= $success ? 'success' : 'error' ?>"><?= $flash ?></div>
        <?php endif; ?>

        <?php if (!$success): ?>
            <h1>Mietinteressentenformular</h1>
            <p style="color: #64748b; margin-bottom: 32px;">Bitte füllen Sie das Formular aus, um sich für die Wohnung zu bewerben.</p>

            <?php if ($wohnung): ?>
                <div class="property-info">
                    <strong>Interesse an:</strong> <?= htmlspecialchars($wohnung['name']) ?> (<?= htmlspecialchars($wohnung['objekt_name']) ?> in <?= htmlspecialchars($wohnung['projekt_name']) ?>)<br>
                    <span><?= htmlspecialchars($wohnung['etage']) ?> • <?= htmlspecialchars($wohnung['zimmer']) ?> Zimmer • <?= htmlspecialchars($wohnung['flaeche']) ?> m²</span>
                </div>
            <?php endif; ?>

            <form method="post" class="form-grid">
                <div>
                    <label>Vorname*</label>
                    <input type="text" name="vorname" required>
                </div>
                <div>
                    <label>Nachname*</label>
                    <input type="text" name="nachname" required>
                </div>
                <div class="full-width">
                    <label>E-Mail Adresse*</label>
                    <input type="email" name="email" required>
                </div>
                <div>
                    <label>Telefonnummer</label>
                    <input type="tel" name="telefon">
                </div>
                <div>
                    <label>Geburtsdatum</label>
                    <input type="date" name="geburtsdatum">
                </div>
                <div>
                    <label>Beruf</label>
                    <input type="text" name="beruf">
                </div>
                <div>
                    <label>Aktueller Arbeitgeber</label>
                    <input type="text" name="arbeitgeber">
                </div>
                <div>
                    <label>Monatliches Netto-Einkommen (CHF)</label>
                    <input type="number" step="0.01" name="gehalt_monat">
                </div>
                <div>
                    <label>Anzahl Personen</label>
                    <input type="number" name="anzahl_personen" value="1" min="1">
                </div>
                <div class="full-width">
                    <label>Haustiere (Art und Anzahl)</label>
                    <textarea name="haustiere" rows="2"></textarea>
                </div>
                <div class="full-width">
                    <label>Musikinstrumente</label>
                    <textarea name="instrumente" rows="2"></textarea>
                </div>
                <div class="full-width">
                    <label>Zusätzliche Bemerkungen</label>
                    <textarea name="bemerkungen" rows="4"></textarea>
                </div>
                <div class="full-width">
                    <button type="submit">Bewerbung absenden</button>
                </div>
            </form>
        <?php else: ?>
            <p>Vielen Dank für Ihr Interesse. Wir werden Ihre Unterlagen prüfen und uns gegebenenfalls bei Ihnen melden.</p>
            <p><a href="javascript:window.location.reload()">Neues Formular ausfüllen</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
