<?php
// pages/unterkategorie_loeschen.php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0) die("❌ Ungültige Unterkategorie-ID.");

// Knoten laden
$st = $mysqli->prepare("SELECT id, vorlage_id, parent_id, code FROM unterkategorien WHERE id=?");
$st->bind_param("i",$id);
$st->execute();
$node = $st->get_result()->fetch_assoc();
$st->close();
if (!$node) die("❌ Unterkategorie nicht gefunden.");
$vorlageId = (int)$node['vorlage_id'];
$code = $node['code'] ?: ('id'.$node['id']);

// Kinder prüfen
$st = $mysqli->prepare("SELECT COUNT(*) c FROM unterkategorien WHERE parent_id=?");
$st->bind_param("i",$id);
$st->execute();
$childCount = (int)($st->get_result()->fetch_assoc()['c'] ?? 0);
$st->close();
if ($childCount>0) die("❌ Löschen nicht möglich: es existieren noch {$childCount} Unterordner. Bitte zuerst Unterordner entfernen.");

// Dateien prüfen
$base = realpath(__DIR__ . '/../uploads/vorlagen');
$dir  = $base ? ($base . DIRECTORY_SEPARATOR . $vorlageId . DIRECTORY_SEPARATOR . $code) : null;
if ($dir && is_dir($dir)) {
  $items = array_diff(scandir($dir), ['.','..']);
  if (count($items)>0) {
    die("❌ Löschen nicht möglich: Ordner enthält noch Dateien/Unterordner: /uploads/vorlagen/{$vorlageId}/{$code}/");
  }
  // leeres Verzeichnis optional entfernen
  @rmdir($dir);
}

// Datensatz löschen
$st = $mysqli->prepare("DELETE FROM unterkategorien WHERE id=? LIMIT 1");
$st->bind_param("i",$id);
$st->execute();
$st->close();

header("Location: vorlage_bearbeiten.php?id=".$vorlageId);
exit;
