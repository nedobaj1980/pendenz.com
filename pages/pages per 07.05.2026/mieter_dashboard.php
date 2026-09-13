<?php
// pages/mieter_dashboard.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$user_id = (int)$_SESSION['user_id'];
$uData   = $mysqli->query("SELECT * FROM benutzer WHERE id=$user_id")->fetch_assoc();
$wid     = (int)($uData['wohnung_id'] ?? 0);

if ($wid <= 0) {
    require_once __DIR__ . '/../includes/header.php';
    require_once __DIR__ . '/../includes/nav_dispatch.php';
    echo '<div class="container"><div class="card"><h3>Willkommen</h3><p>Ihnen ist aktuell noch keine Wohnung zugewiesen. Bitte kontaktieren Sie die Verwaltung.</p></div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$wohnung = $mysqli->query("
    SELECT w.*, o.name AS objekt_name, o.adresse AS objekt_adresse 
    FROM wohnungen w 
    JOIN objekte o ON o.id = w.objekt_id 
    WHERE w.id = $wid
")->fetch_assoc();

// Bilder/Dokumente
$bilder = $mysqli->query("SELECT * FROM wohnung_bilder WHERE wohnung_id=$wid ORDER BY is_cover DESC");
$docs   = $mysqli->query("SELECT * FROM wohnung_dokumente WHERE wohnung_id=$wid ORDER BY erstellt_am DESC");

// Pendenzen/Protokolle (nur öffentliche oder für den Benutzer freigegebene)
// Hier nutzen wir die neue wohnung_id Verknüpfung
$pendenzen = $mysqli->query("
    SELECT p.* FROM pendenzen p 
    WHERE p.wohnung_id = $wid 
    AND (p.public_enabled = 1 OR p.sichtbarkeit = 'projekt') 
    ORDER BY p.erstellt_am DESC
");

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="container">
    <header class="hero hero-teal">
        <h1>Mein Mieter-Portal</h1>
        <p><?= htmlspecialchars($wohnung['objekt_name']) ?> — <?= htmlspecialchars($wohnung['name']) ?></p>
    </header>

    <div class="grid" style="display:grid; grid-template-columns:1fr 340px; gap:20px;">
        <div>
            <!-- Wohnungsinfos -->
            <div class="card">
                <h3>Details zur Wohnung</h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div><strong>Adresse:</strong> <?= htmlspecialchars($wohnung['objekt_adresse']) ?></div>
                    <div><strong>Etage:</strong> <?= htmlspecialchars($wohnung['etage']) ?></div>
                    <div><strong>Zimmer:</strong> <?= (float)$wohnung['zimmer'] ?></div>
                    <div><strong>Fläche:</strong> <?= (float)$wohnung['flaeche'] ?> m²</div>
                </div>
            </div>

            <!-- Dokumente & Protokolle -->
            <div class="card">
                <h3>Meine Dokumente & Protokolle</h3>
                <ul style="list-style:none; padding:0;">
                    <?php while($d = $docs->fetch_assoc()): ?>
                        <li style="padding:10px; border-bottom:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                            <span>
                                <strong><?= htmlspecialchars($d['titel']) ?></strong><br>
                                <small style="color:#666;"><?= htmlspecialchars($d['kategorie']) ?> — <?= date('d.m.Y', strtotime($d['erstellt_am'])) ?></small>
                            </span>
                            <a href="<?= htmlspecialchars($d['pfad']) ?>" class="btn btn-small" target="_blank">Öffnen</a>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>

            <!-- Pendenzen / Übergaben -->
            <div class="card">
                <h3>Aktuelle Aufgaben & Protokolle</h3>
                <table class="table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Titel</th>
                            <th>Typ</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($p = $pendenzen->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('d.m.Y', strtotime($p['erstellt_am'])) ?></td>
                                <td><a href="pendenz_show.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['titel']) ?></a></td>
                                <td><?= htmlspecialchars($p['protocol_type'] !== 'none' ? $p['protocol_type'] : 'Aufgabe') ?></td>
                                <td><?= htmlspecialchars($p['status']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Finanz-Kontoauszug -->
            <div class="card">
                <h3>Mein Kontostand / Zahlungen</h3>
                <table class="table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Text</th>
                            <th style="text-align:right;">Soll</th>
                            <th style="text-align:right;">Haben</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $fRes = $mysqli->query("SELECT * FROM finanzen_konto WHERE benutzer_id=$user_id OR wohnung_id=$wid ORDER BY datum DESC, id DESC");
                        $saldo = 0;
                        while($f = $fRes->fetch_assoc()):
                            $saldo += ($f['haben'] - $f['soll']);
                        ?>
                            <tr>
                                <td><?= date('d.m.Y', strtotime($f['datum'])) ?></td>
                                <td><?= htmlspecialchars($f['text']) ?></td>
                                <td style="text-align:right; color:#e74c3c;">-<?= number_format($f['soll'], 2) ?></td>
                                <td style="text-align:right; color:#2ecc71;">+<?= number_format($f['haben'], 2) ?></td>
                            </tr>
                        <?php endwhile; ?>
                        <tr style="background:#f9fafb; font-weight:bold;">
                            <td colspan="2">Aktueller Saldo:</td>
                            <td colspan="2" style="text-align:right; color:<?= $saldo >= 0 ? '#2ecc71' : '#e74c3c' ?>;">
                                <?= number_format($saldo, 2) ?> CHF
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <aside>
            <!-- Wohnungsgalerie -->
            <div class="card">
                <h3>Bilder</h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <?php while($b = $bilder->fetch_assoc()): ?>
                        <a href="<?= htmlspecialchars($b['pfad']) ?>" target="_blank">
                            <img src="<?= htmlspecialchars($b['pfad']) ?>" style="width:100%; height:80px; object-fit:cover; border-radius:6px;">
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Messenger (Quick Link) -->
            <div class="card">
                <h3>Nachrichten</h3>
                <p>Haben Sie eine Frage? Hinterlassen Sie uns eine Nachricht.</p>
                <a href="chat.php" class="btn" style="width:100%; text-align:center;">Chat öffnen</a>
            </div>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
