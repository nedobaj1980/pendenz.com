<?php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

/**
 * Hilfsfunktionen
 */
function table_exists(mysqli $db, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
            LIMIT 1";
    $st = $db->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $exists = (bool)$st->get_result()->fetch_column();
    $st->close();
    return $exists;
}

/** Verzeichnisse rekursiv löschen */
function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.','..']);
    foreach ($items as $it) {
        $path = $dir . DIRECTORY_SEPARATOR . $it;
        if (is_dir($path)) rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Eingaben
 */
$vorlageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vorlageId <= 0) {
    http_response_code(400);
    echo "❌ Ungültige ID.";
    exit;
}

/**
 * Vorlage laden & Berechtigung prüfen
 */
$st = $mysqli->prepare("SELECT id, name, user_id FROM struktur_vorlagen WHERE id=?");
$st->bind_param("i", $vorlageId);
$st->execute();
$tpl = $st->get_result()->fetch_assoc();
$st->close();

if (!$tpl) {
    http_response_code(404);
    echo "❌ Vorlage nicht gefunden.";
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$isOwner = ((int)$tpl['user_id'] === $currentUserId);

// Wenn du hier Admin-Rechte prüfen willst, ersetze das nach deinem RBAC:
$mayDelete = $isOwner || true; // ← ggf. auf has_role('admin') prüfen
if (!$mayDelete) {
    http_response_code(403);
    echo "❌ Keine Berechtigung zum Löschen.";
    exit;
}

/**
 * Transaktion: Mappings → Unterkategorien (Vorlage) → Vorlage
 * (Projekt-Strukturen bleiben unberührt, da dort projekt_id <> NULL ist.)
 */
$mysqli->begin_transaction();

try {
    // 1) Projekt-Mappings entfernen (falls Tabelle existiert)
    if (table_exists($mysqli, 'projekt_vorlagen')) {
        $st = $mysqli->prepare("DELETE FROM projekt_vorlagen WHERE vorlage_id=?");
        $st->bind_param("i", $vorlageId);
        $st->execute();
        $st->close();
    }

    // 2) Unterkategorien der Vorlage löschen (NUR die Template-Knoten, nicht Projektkopien)
    //    (Falls ON DELETE CASCADE bereits existiert, ist das hier idempotent und stört nicht.)
    if (table_exists($mysqli, 'unterkategorien')) {
        $st = $mysqli->prepare("DELETE FROM unterkategorien WHERE vorlage_id=? AND (projekt_id IS NULL OR projekt_id=0)");
        $st->bind_param("i", $vorlageId);
        $st->execute();
        $st->close();
    }

    // 3) Vorlage löschen
    $st = $mysqli->prepare("DELETE FROM struktur_vorlagen WHERE id=?");
    $st->bind_param("i", $vorlageId);
    $st->execute();
    $affected = $st->affected_rows;
    $st->close();

    if ($affected < 1) {
        throw new RuntimeException("Vorlage konnte nicht gelöscht werden (evtl. Fremdschlüsselblockade).");
    }

    $mysqli->commit();

} catch (Throwable $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo "❌ Fehler beim Löschen: " . htmlspecialchars($e->getMessage());
    exit;
}

/**
 * Dateisystem aufräumen (außerhalb der Transaktion)
 */
$base = realpath(__DIR__ . '/../uploads');
if ($base !== false) {
    $tplDir = $base . DIRECTORY_SEPARATOR . 'vorlagen' . DIRECTORY_SEPARATOR . $vorlageId;
    rrmdir($tplDir);
}

/**
 * Zurück zur Übersicht
 */
header("Location: /pendenz.com/pages/vorlagen.php?msg=" . urlencode("Vorlage „{$tpl['name']}“ gelöscht."));
exit;
