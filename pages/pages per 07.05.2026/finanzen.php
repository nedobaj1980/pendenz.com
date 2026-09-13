<?php
// pages/finanzen.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_superadmin() && !is_admin()) { die("Keine Berechtigung."); }

$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_entry') {
        $wid = (int)$_POST['wohnung_id'];
        $uid = (int)$_POST['benutzer_id'];
        $datum = $_POST['datum'];
        $text = $_POST['text'];
        $soll = (float)$_POST['soll'];
        $haben = (float)$_POST['haben'];
        $st = $mysqli->prepare("INSERT INTO finanzen_konto (wohnung_id, benutzer_id, datum, text, soll, haben) VALUES (?,?,?,?,?,?)");
        $st->bind_param("iissdd", $wid, $uid, $datum, $text, $soll, $haben);
        $st->execute();
        $flash = "✅ Buchung erfolgreich.";
    }
}

$wohnungId = (int)($_GET['wohnung_id'] ?? 0);
$where = "";
if ($wohnungId > 0) {
    $where = " WHERE f.wohnung_id = $wohnungId ";
}

$entries = $mysqli->query("
    SELECT f.*, w.name AS wohnung_name, b.name AS benutzer_name 
    FROM finanzen_konto f 
    LEFT JOIN wohnungen w ON w.id = f.wohnung_id 
    LEFT JOIN benutzer b ON b.id = f.benutzer_id 
    $where
    ORDER BY f.datum DESC, f.id DESC
");

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="container">
    <header class="hero hero-teal">
        <h1>Finanzen / Kontoauszug</h1>
        <p>Zahlungen, Mieten und Kontobewegungen im Überblick.</p>
    </header>

    <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <div class="grid" style="display:grid; grid-template-columns:1fr 340px; gap:20px;">
        <div class="column">
            <div class="card">
                <h3>Letzte Buchungen</h3>
                <table class="table" style="width:100%;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #ddd;">
                            <th>Datum</th>
                            <th>Einheit / Mieter</th>
                            <th>Buchungstext</th>
                            <th style="text-align:right;">Soll (Bel.)</th>
                            <th style="text-align:right;">Haben (Gut.)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($e = $entries->fetch_assoc()): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td><?= date('d.m.Y', strtotime($e['datum'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($e['wohnung_name'] ?: 'N/A') ?></strong><br>
                                    <small><?= htmlspecialchars($e['benutzer_name'] ?: 'N/A') ?></small>
                                </td>
                                <td><?= htmlspecialchars($e['text']) ?></td>
                                <td style="text-align:right; color:#e74c3c;">-<?= number_format($e['soll'], 2) ?></td>
                                <td style="text-align:right; color:#2ecc71;">+<?= number_format($e['haben'], 2) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <aside class="column">
            <div class="card">
                <h3>Neue Buchung</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_entry">
                    <label>Wohnung
                        <select name="wohnung_id">
                            <option value="">— wählen —</option>
                            <?php $ws = $mysqli->query("SELECT id, name FROM wohnungen ORDER BY name");
                            while($w = $ws->fetch_assoc()): ?>
                                <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </label>
                    <label>Mieter
                        <select name="benutzer_id">
                            <option value="">— wählen —</option>
                            <?php $us = $mysqli->query("SELECT id, name FROM benutzer WHERE rolle='benutzer' ORDER BY name");
                            while($u = $us->fetch_assoc()): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </label>
                    <label>Datum<input type="date" name="datum" value="<?= date('Y-m-d') ?>" required></label>
                    <label>Text<input type="text" name="text" placeholder="z.B. Miete 04/2024" required></label>
                    <label>Soll (Belastung)<input type="number" step="0.01" name="soll" value="0.00"></label>
                    <label>Haben (Gutschrift)<input type="number" step="0.01" name="haben" value="0.00"></label>
                    <button class="btn" style="width:100%; margin-top:10px;">Buchen</button>
                </form>
            </div>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
