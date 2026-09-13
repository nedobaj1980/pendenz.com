<?php
// pages/hard_reset.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

// Sicherheit: Nur Superadmins dürfen das
if (!is_superadmin()) { die("Keine Berechtigung."); }

$tables = [
    'pendenzen', 'pendenz_dateien', 'pendenz_kommentare', 'pendenz_acl', 'pendenz_ordner', 'pendenz_anhaenge',
    'wohnungen', 'objekte', 'projekte', 'finanzen_konto', 'fs_nodes',
    'mietverhaeltnisse', 'mieter', 'mietvertraege',
    'chat_messages', 'chat_rooms', 'chat_members', 'chat_message_recipients', 'chat_attachments',
    'documents', 'files', 'folders', 'ordner', 'anhaenge', 'comments', 'notifications'
];

$results = [];
$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
foreach ($tables as $t) {
    if ($mysqli->query("TRUNCATE TABLE `$t`")) {
        $results[] = "✅ Tabelle `$t` geleert.";
    } else {
        // Falls Truncate fehlschlägt (z.B. Tabelle existiert nicht), versuchen wir Delete
        if ($mysqli->query("DELETE FROM `$t`")) {
            $results[] = "⚠️ Tabelle `$t` via DELETE geleert.";
        } else {
            $results[] = "❌ Fehler bei Tabelle `$t`: " . $mysqli->error;
        }
    }
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container">
    <div class="card" style="margin-top:50px; border-top:5px solid #e74c3c;">
        <h1>🚀 Hard Reset abgeschlossen</h1>
        <p>Das System wurde erfolgreich bereinigt. Folgende Tabellen wurden geleert:</p>
        <ul style="max-height:300px; overflow:auto; background:#f8fafc; padding:15px; border-radius:8px; font-family:monospace; font-size:12px;">
            <?php foreach($results as $r): ?>
                <li><?= $r ?></li>
            <?php endforeach; ?>
        </ul>
        <div style="margin-top:20px;">
            <a href="projekte.php" class="btn primary">Zu den Projekten</a>
            <a href="index.php" class="btn">Zum Dashboard</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
