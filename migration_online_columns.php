<?php
/**
 * migration_online_columns.php
 * Fügt alle fehlenden Spalten zur Online-Datenbank hinzu.
 * Einmalig ausführen unter: https://pendenz.com/migration_online_columns.php
 */
require_once 'config.php';

// Sicherheit: nur für Admins
session_start();
if (empty($_SESSION['user_id']) && empty($_SESSION['benutzer_id'])) {
    die('Nicht eingeloggt. Bitte zuerst einloggen.');
}

$results = [];

function addColumnIfMissing(mysqli $db, string $table, string $column, string $definition): string {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $check = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    if ($check && $check->num_rows > 0) {
        return "✅ <code>$table.$column</code> existiert bereits.";
    }
    $sql = "ALTER TABLE `$t` ADD COLUMN `$c` $definition";
    if ($db->query($sql)) {
        return "➕ <code>$table.$column</code> erfolgreich hinzugefügt.";
    } else {
        return "❌ <code>$table.$column</code> Fehler: " . htmlspecialchars($db->error);
    }
}

function addTableIfMissing(mysqli $db, string $table, string $createSql): string {
    $check = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    if ($check && $check->num_rows > 0) {
        return "✅ Tabelle <code>$table</code> existiert bereits.";
    }
    if ($db->query($createSql)) {
        return "➕ Tabelle <code>$table</code> erfolgreich erstellt.";
    } else {
        return "❌ Tabelle <code>$table</code> Fehler: " . htmlspecialchars($db->error);
    }
}

// =====================================================
// TABELLE: pendenzen – fehlende Spalten
// =====================================================
$results[] = "<h3>📋 Tabelle: pendenzen</h3>";

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'empfaenger_typ',
    "VARCHAR(50) NULL DEFAULT NULL AFTER `vorgangsart_id`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'vorlagen_welt',
    "VARCHAR(50) NULL DEFAULT NULL AFTER `empfaenger_typ`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'raum_id',
    "INT(11) NULL DEFAULT NULL AFTER `wohnung_id`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'wichtigkeit',
    "TINYINT(1) NOT NULL DEFAULT 3 AFTER `status`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'langbeschreibung',
    "TEXT NULL DEFAULT NULL AFTER `kurzbeschreibung`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'beschreibung',
    "TEXT NULL DEFAULT NULL AFTER `langbeschreibung`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'empfaenger_benutzer_id',
    "INT(11) NULL DEFAULT NULL AFTER `zustaendig_id`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'ersteller_benutzer_id',
    "INT(11) NULL DEFAULT NULL AFTER `empfaenger_benutzer_id`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'confirmation_required',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER `send_now`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'external_can_view',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER `confirmation_required`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'external_can_upload',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER `external_can_view`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'public_enabled',
    "TINYINT(1) NOT NULL DEFAULT 0 AFTER `external_can_upload`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'zustaendig_typ',
    "VARCHAR(20) NULL DEFAULT 'user' AFTER `zustaendig_id`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'extra_json',
    "TEXT NULL DEFAULT NULL AFTER `public_enabled`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'erstellt_am',
    "DATETIME NULL DEFAULT NULL AFTER `extra_json`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'erstellt_von',
    "INT(11) NULL DEFAULT NULL AFTER `erstellt_am`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'notiz',
    "TEXT NULL DEFAULT NULL AFTER `langbeschreibung`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'uhrzeit',
    "VARCHAR(10) NULL DEFAULT NULL AFTER `enddatum`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen', 'dauer',
    "INT(11) NOT NULL DEFAULT 0 AFTER `uhrzeit`");

// =====================================================
// TABELLE: pendenzen_arten – fehlende Spalten
// =====================================================
$results[] = "<h3>📋 Tabelle: pendenzen_arten</h3>";

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'empfaenger_typ',
    "VARCHAR(50) NULL DEFAULT NULL AFTER `name`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'vorlagen_welt',
    "VARCHAR(50) NULL DEFAULT NULL AFTER `empfaenger_typ`");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'bkp_erforderlich',
    "TINYINT(1) NOT NULL DEFAULT 0");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'nur_firmen_bkp',
    "TINYINT(1) NOT NULL DEFAULT 0");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'firma_bkp_filter',
    "TINYINT(1) NOT NULL DEFAULT 0");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'bkp_mode',
    "VARCHAR(50) NULL DEFAULT NULL");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'default_projekt_id',
    "INT(11) NULL DEFAULT NULL");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'default_objekt_id',
    "INT(11) NULL DEFAULT NULL");

$results[] = addColumnIfMissing($mysqli, 'pendenzen_arten', 'default_wohnung_id',
    "INT(11) NULL DEFAULT NULL");

// =====================================================
// TABELLE: firmen_vorlagen_map – falls nicht vorhanden
// =====================================================
$results[] = "<h3>📋 Tabelle: firmen_vorlagen_map</h3>";
$results[] = addTableIfMissing($mysqli, 'firmen_vorlagen_map', "
    CREATE TABLE `firmen_vorlagen_map` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `firma_id` INT(11) NOT NULL,
        `welt` VARCHAR(50) NOT NULL DEFAULT 'bkp',
        `ref_id` INT(11) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `firma_welt_ref` (`firma_id`, `welt`, `ref_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

");

// =====================================================
// TABELLEN: Medien & Marketing Spalten (wohnungen, bilder, dokumente)
// =====================================================
$results[] = "<h3>📸 Tabelle: wohnungen (Marketing & Medien Spalten)</h3>";
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'marketing_titel', "VARCHAR(255) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'marketing_beschreibung', "TEXT NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'marketing_highlight1', "VARCHAR(150) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'marketing_highlight2', "VARCHAR(150) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'marketing_highlight3', "VARCHAR(150) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'marketing_status', "VARCHAR(50) DEFAULT 'entwurf'");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'link_homegate', "VARCHAR(500) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'link_immoscout', "VARCHAR(500) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'link_flatfox', "VARCHAR(500) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnungen', 'link_comparis', "VARCHAR(500) NULL DEFAULT NULL");

$results[] = "<h3>🖼️ Tabelle: wohnung_bilder</h3>";
$results[] = addColumnIfMissing($mysqli, 'wohnung_bilder', 'titel', "VARCHAR(255) NULL DEFAULT NULL");
$results[] = addColumnIfMissing($mysqli, 'wohnung_bilder', 'sort_order', "INT(11) DEFAULT 0");

$results[] = "<h3>📄 Tabelle: wohnung_dokumente</h3>";
$results[] = addColumnIfMissing($mysqli, 'wohnung_dokumente', 'kategorie', "VARCHAR(100) DEFAULT 'sonstiges'");

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Migration – Online Spalten</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; background: #0f172a; color: #e2e8f0; }
h1 { color: #60a5fa; }
h3 { color: #94a3b8; border-top: 1px solid #1e293b; padding-top: 16px; }
p { padding: 6px 12px; margin: 4px 0; border-radius: 6px; background: #1e293b; }
p:contains("✅") { border-left: 3px solid #22c55e; }
p:contains("➕") { border-left: 3px solid #3b82f6; }
p:contains("❌") { border-left: 3px solid #ef4444; }
code { background: #0f172a; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.done { margin-top: 30px; padding: 16px; background: #166534; border-radius: 8px; font-weight: bold; color: #bbf7d0; }
</style>
</head>
<body>
<h1>🔧 Datenbank-Migration: Fehlende Spalten</h1>
<?php foreach ($results as $r): ?>
    <?php if (strpos($r, '<h3>') === 0): echo $r; else: ?>
    <p><?= $r ?></p>
    <?php endif; ?>
<?php endforeach; ?>
<div class="done">✅ Migration abgeschlossen! Diese Datei kann jetzt gelöscht werden.</div>
</body>
</html>
