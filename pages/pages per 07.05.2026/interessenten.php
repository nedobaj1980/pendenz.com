<?php
// pages/interessenten.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
if (!is_superadmin() && !is_admin()) { die("Keine Berechtigung."); }

$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $id = (int)$_POST['id'];
        $status = $_POST['status'];
        $st = $mysqli->prepare("UPDATE interessenten SET status = ? WHERE id = ?");
        $st->bind_param("si", $status, $id);
        if ($st->execute()) $flash = "✅ Status aktualisiert.";
    }
}

$rs = $mysqli->query("
    SELECT i.*, w.name AS wohnung_name, o.name AS objekt_name 
    FROM interessenten i 
    LEFT JOIN wohnungen w ON w.id = i.wohnung_id 
    LEFT JOIN objekte o ON o.id = w.objekt_id 
    ORDER BY i.erstellt_am DESC
");

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="container">
    <header class="hero hero-teal">
        <h1>Mietinteressenten</h1>
        <p>Eingegangene Bewerbungen über das öffentliche Formular.</p>
    </header>

    <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <div class="card">
        <table class="table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f9fafb; text-align:left;">
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Datum</th>
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Wohnung</th>
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Name</th>
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Kontakt</th>
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Einkommen</th>
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Status</th>
                    <th style="padding:10px; border-bottom:1px solid #ddd;">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rs && $rs->num_rows > 0): ?>
                    <?php while($row = $rs->fetch_assoc()): ?>
                        <tr>
                            <td style="padding:10px; border-bottom:1px solid #eee;"><?= date('d.m.Y H:i', strtotime($row['erstellt_am'])) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #eee;">
                                <strong><?= htmlspecialchars($row['wohnung_name'] ?: 'N/A') ?></strong><br>
                                <small style="color:#666;"><?= htmlspecialchars($row['objekt_name'] ?: '') ?></small>
                            </td>
                            <td style="padding:10px; border-bottom:1px solid #eee;"><?= htmlspecialchars($row['vorname'] . ' ' . $row['nachname']) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #eee;">
                                <?= htmlspecialchars($row['email']) ?><br>
                                <small><?= htmlspecialchars($row['telefon']) ?></small>
                            </td>
                            <td style="padding:10px; border-bottom:1px solid #eee;">CHF <?= number_format($row['gehalt_monat'], 2) ?></td>
                            <td style="padding:10px; border-bottom:1px solid #eee;">
                                <span class="badge" style="background:<?= $row['status']==='neu'?'#3498db':($row['status']==='akzeptiert'?'#2ecc71':'#e74c3c') ?>; color:#fff; padding:2px 8px; border-radius:12px; font-size:12px;">
                                    <?= htmlspecialchars($row['status']) ?>
                                </span>
                            </td>
                            <td style="padding:10px; border-bottom:1px solid #eee;">
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <select name="status" onchange="this.form.submit()" style="padding:4px; font-size:12px; border-radius:4px;">
                                        <option value="neu" <?= $row['status']==='neu'?'selected':'' ?>>Neu</option>
                                        <option value="in Prüfung" <?= $row['status']==='in Prüfung'?'selected':'' ?>>In Prüfung</option>
                                        <option value="akzeptiert" <?= $row['status']==='akzeptiert'?'selected':'' ?>>Akzeptiert</option>
                                        <option value="abgelehnt" <?= $row['status']==='abgelehnt'?'selected':'' ?>>Abgelehnt</option>
                                    </select>
                                </form>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="7" style="padding:0 10px 10px 10px; border-bottom:1px solid #eee; font-size:13px; color:#555;">
                                <em>Beruf: <?= htmlspecialchars($row['beruf']) ?> | Arbeitgeber: <?= htmlspecialchars($row['arbeitgeber']) ?> | Personen: <?= (int)$row['anzahl_personen'] ?></em><br>
                                Nachricht: <?= nl2br(htmlspecialchars($row['bemerkungen'])) ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="padding:20px; text-align:center; color:#999;">Keine Interessenten vorhanden.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
