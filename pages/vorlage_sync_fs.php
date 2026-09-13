<?php
if(session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$vorlageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($vorlageId<=0) die("❌ Vorlage ID fehlt.");

$stmt = $mysqli->prepare("SELECT id, name FROM struktur_vorlagen WHERE id=?");
$stmt->bind_param("i",$vorlageId);
$stmt->execute();
$vorlage = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$vorlage) die("❌ Vorlage nicht gefunden.");

$base = realpath(__DIR__ . '/../uploads/vorlagen');
if(!$base) {
    $base = __DIR__ . '/../uploads/vorlagen';
    @mkdir($base, 0777, true);
}
$targetBase = $base . DIRECTORY_SEPARATOR . $vorlageId;
@mkdir($targetBase, 0777, true);

// Alle Knoten holen
$res = $mysqli->prepare("SELECT id, code FROM unterkategorien WHERE vorlage_id=? ORDER BY id");
$res->bind_param("i",$vorlageId);
$res->execute();
$rows = $res->get_result()->fetch_all(MYSQLI_ASSOC);
$res->close();

$created = 0; $copied = 0; $skipped = 0;

foreach($rows as $r){
    $code = $r['code'] ?: ('id'.$r['id']);
    $dirByCode = $targetBase . DIRECTORY_SEPARATOR . $code;
    if(!is_dir($dirByCode)){
        @mkdir($dirByCode, 0777, true);
        $created++;
    }

    // Falls es noch Altordner nach ID gibt, optional Dateien rüberkopieren (OHNE löschen!)
    $legacyDir = $targetBase . DIRECTORY_SEPARATOR . 'id'.$r['id'];
    if(is_dir($legacyDir)){
        $items = array_diff(scandir($legacyDir), ['.','..']);
        foreach($items as $it){
            $src = $legacyDir . DIRECTORY_SEPARATOR . $it;
            $dst = $dirByCode . DIRECTORY_SEPARATOR . $it;
            if(is_dir($src)){
                if(!is_dir($dst)) @mkdir($dst, 0777, true);
                // keine rekursive Kopie, um nichts zu riskieren
                $skipped++;
            } else {
                if(!file_exists($dst)){
                    @copy($src, $dst);
                    $copied++;
                } else {
                    $skipped++;
                }
            }
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "✅ Sync für Vorlage #{$vorlageId} – ".($vorlage['name'] ?? '')."\n";
echo "Ordner angelegt: {$created}\n";
echo "Dateien kopiert: {$copied}\n";
echo "Übersprungen: {$skipped}\n";
echo "\nHinweis: Es wurde NICHTS gelöscht. Alte ID-Ordner bleiben bestehen.";
