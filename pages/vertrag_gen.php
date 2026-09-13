<?php
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/fs.php';

require_login();

$pid = (int)($_GET['projekt_id'] ?? 0);
$uid = (int)($_GET['unit_id'] ?? 0);

if ($uid <= 0) {
    die("Ungültige Wohnungs-ID.");
}

$unit = $mysqli->query("SELECT w.*, p.name as p_name, p.adresse as p_adresse FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid")->fetch_assoc();
$mieter = $mysqli->query("SELECT * FROM wohnung_mieter WHERE wohnung_id = $uid AND status = 'aktiv' LIMIT 1")->fetch_assoc();

if (!$unit) die("Wohnung nicht gefunden.");

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Mietvertrag generieren - <?= htmlspecialchars($unit['name']) ?></title>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; padding: 40px; }
        .card { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; }
        input { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; box-sizing: border-box; }
        .btn { display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Mietvertrag generieren</h2>
        <p>Bitte überprüfen Sie die Daten für den Vertrag:</p>
        
        <form action="vertrag_save.php?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>" method="POST">
            <div class="form-group">
                <label>Mieter Name</label>
                <input type="text" name="mieter_name" value="<?= htmlspecialchars($mieter['mieter_name'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Netto-Mietzins (CHF)</label>
                <input type="number" name="mietzins_netto" step="0.05" value="<?= htmlspecialchars($mieter['mietzins_netto'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label>Nebenkosten Akonto (CHF)</label>
                <input type="number" name="nk_akonto" step="0.05" value="<?= htmlspecialchars($mieter['nk_akonto'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label>Mietbeginn</label>
                <input type="date" name="move_in" value="<?= $mieter['move_in'] ?? date('Y-m-d') ?>">
            </div>

            <button type="submit" class="btn">Vertrag PDF erstellen & Speichern</button>
        </form>
    </div>
</body>
</html>
