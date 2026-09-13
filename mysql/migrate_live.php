<?php
/**
 * migrate_live.php
 * Synchronisiert die Datenbankstruktur auf dem Live-Server für Pendenzen und Benutzerverwaltung.
 */
require_once "config.php";
if (session_status() === PHP_SESSION_NONE) session_start();

if (($_SESSION['rolle'] ?? '') !== 'superadmin' && $_SERVER['HTTP_HOST'] !== 'localhost') {
    die("Zugriff verweigert. Bitte als Superadmin einloggen.");
}

echo "<h1>Datenbank-Migration (Live-Fix)</h1><pre>";

function add_col($db, $table, $col, $type) {
    $res = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$table' AND column_name='$col'");
    if ($res && $res->num_rows === 0) {
        echo "Füge Spalte $table.$col hinzu...\n";
        if ($db->query("ALTER TABLE $table ADD COLUMN $col $type")) {
            echo "✅ OK\n";
        } else {
            echo "❌ Fehler: " . $db->error . "\n";
        }
    } else {
        echo "Spalte $table.$col existiert bereits.\n";
    }
}

// 1. Benutzer Tabelle
add_col($mysqli, 'benutzer', 'is_blocked', "TINYINT(1) DEFAULT 0");
add_col($mysqli, 'benutzer', 'last_login_at', "DATETIME NULL");
add_col($mysqli, 'benutzer', 'first_login_at', "DATETIME NULL");
add_col($mysqli, 'benutzer', 'person_type_id', "INT NULL");
add_col($mysqli, 'benutzer', 'person_status_id', "INT NULL");
add_col($mysqli, 'benutzer', 'deleted_at', "DATETIME NULL");

// 2. Pendenzen Tabelle
add_col($mysqli, 'pendenzen', 'objekt_id', "INT NULL AFTER projekt_id");
add_col($mysqli, 'pendenzen', 'vorgangsart_id', "INT NULL");
add_col($mysqli, 'pendenzen', 'raum_id', "INT NULL");
add_col($mysqli, 'pendenzen', 'is_protocol', "TINYINT(1) DEFAULT 0");

// 3. Neue Tabellen (BKP Kontext)
$mysqli->query("CREATE TABLE IF NOT EXISTS bkp_kategorien (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bkp_id INT NOT NULL,
    name VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("CREATE TABLE IF NOT EXISTS bkp_vorlagen_texte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kategorie_id INT NOT NULL,
    text TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$mysqli->query("CREATE TABLE IF NOT EXISTS rollen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rolle_key VARCHAR(50) UNIQUE,
    rolle_bezeichnung VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Standard-Rollen einfügen falls leer
$res = $mysqli->query("SELECT COUNT(*) FROM rollen");
if ($res && (int)$res->fetch_row()[0] === 0) {
    $mysqli->query("INSERT INTO rollen (rolle_key, rolle_bezeichnung) VALUES ('superadmin','Superadmin'),('admin','Administrator'),('benutzer','Standard-Benutzer'),('gast','Gast')");
    echo "Standard-Rollen angelegt.\n";
}

echo "\nMigration abgeschlossen. Bitte lösche diese Datei nach der Ausführung.";
echo "</pre>";
