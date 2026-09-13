<?php
// pages/wohnungen_import_fs.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_login();

$pid = (int)($_POST['projekt_id'] ?? 0);
if ($pid <= 0) {
    header("Location: wohnungen_liste.php?error=no_project");
    exit;
}

// 1. Root holen
$root = project_root_path($mysqli, $pid);
if (!$root || !is_dir($root)) {
    header("Location: wohnungen_liste.php?projekt_id=$pid&error=no_root");
    exit;
}

// 2. Objekt holen oder anlegen (Hauptobjekt)
$resO = $mysqli->query("SELECT id FROM objekte WHERE projekt_id=$pid LIMIT 1");
$objId = ($row = $resO ? $resO->fetch_assoc() : null) ? (int)$row['id'] : 0;
if ($objId === 0) {
    $mysqli->query("INSERT INTO objekte (projekt_id, name) VALUES ($pid, 'Hauptgebäude')");
    $objId = $mysqli->insert_id;
}


// 3. Nach 'Wohnungen/' Ordnern suchen
// Wir schauen in fs_nodes nach Ordnern deren Parent_rel_path 'Wohnungen' ist oder die 'Wohnungen/' im Pfad haben
$sql = "SELECT DISTINCT name FROM fs_nodes 
        WHERE project_id=? AND is_dir=1 
        AND (parent_rel_path LIKE '%10_Mietsache' OR rel_path LIKE '10_Mietsache/%')
        AND name NOT LIKE '.%'";
$st = $mysqli->prepare($sql);
$st->bind_param("i", $pid);
$st->execute();
$res = $st->get_result();

$count = 0;
while($row = $res->fetch_assoc()){
    $name = $row['name'];
    // Existenzprüfung
    $stE = $mysqli->prepare("SELECT id FROM wohnungen WHERE objekt_id=? AND name=?");
    $stE->bind_param("is", $objId, $name);
    $stE->execute();
    if (!$stE->get_result()->fetch_assoc()) {
        // Anlegen
        $stI = $mysqli->prepare("INSERT INTO wohnungen (objekt_id, name) VALUES (?,?)");
        $stI->bind_param("is", $objId, $name);
        $stI->execute();
        $count++;
    }
    $stE->close();
}
$st->close();

header("Location: wohnungen_liste.php?projekt_id=$pid&imported=$count");
exit;
