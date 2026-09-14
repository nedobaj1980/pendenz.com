<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';

require_login();

$pid = (int)($_GET['projekt_id'] ?? 0);
$uid = (int)($_GET['unit_id'] ?? 0);

if ($uid <= 0) {
    die("Ungültige Wohnungs-ID.");
}

$unit = $mysqli->query("SELECT w.*, p.id as p_id, p.name as p_name, p.adresse as p_adresse, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid")->fetch_assoc();
if (!$unit) {
    die("Wohnung nicht gefunden.");
}
if ($pid <= 0 && !empty($unit['p_id'])) {
    $pid = (int)$unit['p_id'];
}

$mieter = $mysqli->query("SELECT * FROM wohnung_mieter WHERE wohnung_id = $uid AND status = 'aktiv' LIMIT 1")->fetch_assoc();

$defaultName = $mieter['mieter_name'] ?? '';
$defaultNetto = !empty($mieter['mietzins_netto']) ? $mieter['mietzins_netto'] : ($unit['mietzins_netto_soll'] ?? '');
$defaultNk = !empty($mieter['nk_akonto']) ? $mieter['nk_akonto'] : ($unit['mietzins_nk_soll'] ?? '');
$defaultStart = !empty($mieter['startdatum']) ? $mieter['startdatum'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mietvertrag generieren - <?= htmlspecialchars($unit['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Outfit', sans-serif; 
            background: radial-gradient(at 0% 0%, rgba(241,245,249,1) 0, transparent 50%), radial-gradient(at 100% 100%, rgba(219,234,254,1) 0, transparent 50%);
            min-height: 100vh;
            padding: 40px 20px; 
            color: #1e293b;
            margin: 0;
            box-sizing: border-box;
        }
        .card { 
            max-width: 620px; 
            margin: 0 auto; 
            background: white; 
            padding: 35px 40px; 
            border-radius: 20px; 
            box-shadow: 0 20px 40px -15px rgba(0,0,0,0.1); 
            border: 1px solid #e2e8f0;
        }
        .header-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #dbeafe;
            color: #1d4ed8;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
        }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 13px; color: #475569; }
        input { 
            width: 100%; 
            padding: 12px 14px; 
            border: 1px solid #cbd5e1; 
            border-radius: 10px; 
            box-sizing: border-box; 
            font-family: inherit;
            font-size: 14px;
            transition: 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .btn { 
            display: block; 
            width: 100%; 
            background: #2563eb; 
            color: white; 
            padding: 14px; 
            border-radius: 12px; 
            text-decoration: none; 
            font-weight: 800; 
            font-size: 15px; 
            border: none; 
            cursor: pointer; 
            text-align: center;
            transition: 0.2s;
        }
        .btn:hover { background: #1d4ed8; transform: translateY(-1px); }
        .unit-info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="header-badge">Mietvertrags-Generator</span>
        <h1 style="margin:0 0 5px 0; font-size:26px; font-weight:800; letter-spacing:-0.5px;">📜 Mietvertrag erstellen</h1>
        <p style="color:#64748b; font-size:14px; margin:0 0 20px 0;">Erstellt einen standardisierten Schweizer Mietvertrag und legt das PDF direkt im Google Drive Ordner dieser Wohnung ab.</p>
        
        <div class="unit-info-box">
            <div>
                <div style="font-weight:800; font-size:15px; color:#1e293b;"><?= htmlspecialchars($unit['name']) ?></div>
                <div style="font-size:12px; color:#64748b;"><?= htmlspecialchars($unit['p_name']) ?> &bull; <?= htmlspecialchars($unit['obj_name'] ?? '') ?></div>
            </div>
            <div style="text-align:right; font-size:12px; color:#64748b;">
                Etage: <strong><?= htmlspecialchars($unit['etage'] ?? '-') ?></strong><br>
                Zimmer: <strong><?= htmlspecialchars($unit['zimmer'] ?? '-') ?></strong>
            </div>
        </div>
        
        <form action="vertrag_save.php?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>" method="POST">
            <div class="form-group">
                <label>Mieter Name(n)</label>
                <input type="text" name="mieter_name" required value="<?= htmlspecialchars($defaultName) ?>" placeholder="z.B. Frau Anna Muster & Herr Max Muster">
            </div>
            
            <div class="grid-2">
                <div class="form-group">
                    <label>Netto-Mietzins (CHF)</label>
                    <input type="number" name="mietzins_netto" step="0.05" required value="<?= htmlspecialchars($defaultNetto) ?>" placeholder="2100.00">
                </div>
                
                <div class="form-group">
                    <label>Nebenkosten Akonto (CHF)</label>
                    <input type="number" name="nk_akonto" step="0.05" required value="<?= htmlspecialchars($defaultNk) ?>" placeholder="250.00">
                </div>
            </div>

            <div class="form-group">
                <label>Mietbeginn</label>
                <input type="date" name="move_in" required value="<?= htmlspecialchars($defaultStart) ?>">
            </div>

            <button type="submit" class="btn">📄 Vertrag PDF generieren & auf Drive ablegen</button>
            <div style="text-align:center; margin-top:15px;">
                <a href="mieterspiegel.php?projekt_id=<?= $pid ?>" style="color:#64748b; font-size:13px; font-weight:600; text-decoration:none;">← Abbrechen & zurück zum Mieterspiegel</a>
            </div>
        </form>
    </div>
</body>
</html>
