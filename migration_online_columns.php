<?php
declare(strict_types=1);

/**
 * migration_online_columns.php
 * Einmalige, manuell bestätigte Schema-Migration für fehlende Online-Spalten.
 * Nur Superadmins dürfen sie ausführen.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

require_login();
require_role('superadmin');

$results = [];
$error = '';
$executed = false;

function migrationAddColumnIfMissing(mysqli $db, string $table, string $column, string $definition): string
{
    $tableEsc = $db->real_escape_string($table);
    $columnEsc = $db->real_escape_string($column);
    $check = $db->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");

    if ($check && $check->num_rows > 0) {
        return "✅ <code>{$table}.{$column}</code> existiert bereits.";
    }

    $sql = "ALTER TABLE `{$tableEsc}` ADD COLUMN `{$columnEsc}` {$definition}";
    if ($db->query($sql)) {
        return "➕ <code>{$table}.{$column}</code> erfolgreich hinzugefügt.";
    }

    return "❌ <code>{$table}.{$column}</code> Fehler: "
        . htmlspecialchars($db->error, ENT_QUOTES, 'UTF-8');
}

function migrationAddTableIfMissing(mysqli $db, string $table, string $createSql): string
{
    $tableEsc = $db->real_escape_string($table);
    $check = $db->query("SHOW TABLES LIKE '{$tableEsc}'");

    if ($check && $check->num_rows > 0) {
        return "✅ Tabelle <code>{$table}</code> existiert bereits.";
    }

    if ($db->query($createSql)) {
        return "➕ Tabelle <code>{$table}</code> erfolgreich erstellt.";
    }

    return "❌ Tabelle <code>{$table}</code> Fehler: "
        . htmlspecialchars($db->error, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if (($_POST['action'] ?? '') !== 'run') {
        $error = 'Ungültige Aktion.';
    } else {
        $executed = true;

        // =====================================================
        // TABELLE: pendenzen – fehlende Spalten
        // =====================================================
        $results[] = '<h3>📋 Tabelle: pendenzen</h3>';

        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'empfaenger_typ',
            "VARCHAR(50) NULL DEFAULT NULL AFTER `vorgangsart_id`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'vorlagen_welt',
            "VARCHAR(50) NULL DEFAULT NULL AFTER `empfaenger_typ`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'raum_id',
            "INT(11) NULL DEFAULT NULL AFTER `wohnung_id`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'wichtigkeit',
            "TINYINT(1) NOT NULL DEFAULT 3 AFTER `status`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'langbeschreibung',
            "TEXT NULL DEFAULT NULL AFTER `kurzbeschreibung`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'beschreibung',
            "TEXT NULL DEFAULT NULL AFTER `langbeschreibung`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'empfaenger_benutzer_id',
            "INT(11) NULL DEFAULT NULL AFTER `zustaendig_id`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'ersteller_benutzer_id',
            "INT(11) NULL DEFAULT NULL AFTER `empfaenger_benutzer_id`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'confirmation_required',
            "TINYINT(1) NOT NULL DEFAULT 0 AFTER `send_now`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'external_can_view',
            "TINYINT(1) NOT NULL DEFAULT 0 AFTER `confirmation_required`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'external_can_upload',
            "TINYINT(1) NOT NULL DEFAULT 0 AFTER `external_can_view`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'public_enabled',
            "TINYINT(1) NOT NULL DEFAULT 0 AFTER `external_can_upload`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'zustaendig_typ',
            "VARCHAR(20) NULL DEFAULT 'user' AFTER `zustaendig_id`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'extra_json',
            "TEXT NULL DEFAULT NULL AFTER `public_enabled`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'erstellt_am',
            "DATETIME NULL DEFAULT NULL AFTER `extra_json`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'erstellt_von',
            "INT(11) NULL DEFAULT NULL AFTER `erstellt_am`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'notiz',
            "TEXT NULL DEFAULT NULL AFTER `langbeschreibung`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'uhrzeit',
            "VARCHAR(10) NULL DEFAULT NULL AFTER `enddatum`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen',
            'dauer',
            "INT(11) NOT NULL DEFAULT 0 AFTER `uhrzeit`"
        );

        // =====================================================
        // TABELLE: pendenzen_arten – fehlende Spalten
        // =====================================================
        $results[] = '<h3>📋 Tabelle: pendenzen_arten</h3>';

        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'empfaenger_typ',
            "VARCHAR(50) NULL DEFAULT NULL AFTER `name`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'vorlagen_welt',
            "VARCHAR(50) NULL DEFAULT NULL AFTER `empfaenger_typ`"
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'bkp_erforderlich',
            'TINYINT(1) NOT NULL DEFAULT 0'
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'nur_firmen_bkp',
            'TINYINT(1) NOT NULL DEFAULT 0'
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'firma_bkp_filter',
            'VARCHAR(255) NULL DEFAULT NULL'
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'bkp_mode',
            'VARCHAR(50) NULL DEFAULT NULL'
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'default_projekt_id',
            'INT(11) NULL DEFAULT NULL'
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'default_objekt_id',
            'INT(11) NULL DEFAULT NULL'
        );
        $results[] = migrationAddColumnIfMissing(
            $mysqli,
            'pendenzen_arten',
            'default_wohnung_id',
            'INT(11) NULL DEFAULT NULL'
        );

        // =====================================================
        // TABELLE: firmen_vorlagen_map – falls nicht vorhanden
        // =====================================================
        $results[] = '<h3>📋 Tabelle: firmen_vorlagen_map</h3>';
        $results[] = migrationAddTableIfMissing(
            $mysqli,
            'firmen_vorlagen_map',
            "CREATE TABLE `firmen_vorlagen_map` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `firma_id` INT(11) NOT NULL,
                `welt` VARCHAR(50) NOT NULL DEFAULT 'bkp',
                `ref_id` INT(11) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `firma_welt_ref` (`firma_id`, `welt`, `ref_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // =====================================================
        // Medien & Marketing
        // =====================================================
        $results[] = '<h3>📸 Tabelle: wohnungen (Marketing & Medien)</h3>';
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'marketing_titel', 'VARCHAR(255) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'marketing_beschreibung', 'TEXT NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'marketing_highlight1', 'VARCHAR(150) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'marketing_highlight2', 'VARCHAR(150) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'marketing_highlight3', 'VARCHAR(150) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'marketing_status', "VARCHAR(50) DEFAULT 'entwurf'");
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'link_homegate', 'VARCHAR(500) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'link_immoscout', 'VARCHAR(500) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'link_flatfox', 'VARCHAR(500) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnungen', 'link_comparis', 'VARCHAR(500) NULL DEFAULT NULL');

        $results[] = '<h3>🖼️ Tabelle: wohnung_bilder</h3>';
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnung_bilder', 'titel', 'VARCHAR(255) NULL DEFAULT NULL');
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnung_bilder', 'sort_order', 'INT(11) DEFAULT 0');

        $results[] = '<h3>📄 Tabelle: wohnung_dokumente</h3>';
        $results[] = migrationAddColumnIfMissing($mysqli, 'wohnung_dokumente', 'kategorie', "VARCHAR(100) DEFAULT 'sonstiges'");
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migration – Online Spalten</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 860px; margin: 40px auto; padding: 20px; background: #0f172a; color: #e2e8f0; }
h1 { color: #60a5fa; }
h3 { color: #94a3b8; border-top: 1px solid #1e293b; padding-top: 16px; }
p { padding: 8px 12px; margin: 6px 0; border-radius: 6px; background: #1e293b; overflow-wrap: anywhere; }
code { background: #0f172a; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.notice { padding: 16px; border-radius: 10px; background: #1e293b; line-height: 1.5; }
.error { padding: 12px; border-radius: 8px; background: #7f1d1d; color: #fecaca; }
.done { margin-top: 30px; padding: 16px; background: #166534; border-radius: 8px; font-weight: bold; color: #bbf7d0; }
button { border: 0; border-radius: 10px; padding: 12px 18px; min-height: 44px; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; }
@media (max-width: 700px) { body { margin: 0; padding: 16px; } h1 { font-size: 1.5rem; } }
</style>
</head>
<body>
<h1>🔧 Datenbank-Migration: Fehlende Spalten</h1>

<?php if ($error !== ''): ?>
    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php if (!$executed): ?>
    <div class="notice">
        <p>Diese Wartungsseite verändert das Datenbankschema. Sie wird nur nach manueller Bestätigung ausgeführt.</p>
        <form method="post">
            <?= csrf_input() ?>
            <button type="submit" name="action" value="run" onclick="return confirm('Migration jetzt ausführen?')">Migration ausführen</button>
        </form>
    </div>
<?php else: ?>
    <?php foreach ($results as $result): ?>
        <?php if (str_starts_with($result, '<h3>')): ?>
            <?= $result ?>
        <?php else: ?>
            <p><?= $result ?></p>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="done">✅ Migration abgeschlossen. Die Wartungsdatei sollte anschliessend aus dem Webroot entfernt oder gesperrt werden.</div>
<?php endif; ?>
</body>
</html>
