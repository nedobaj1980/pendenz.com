<?php
// pages/ajax_template_preview.php
require_once __DIR__ . '/../config.php';
$tid = (int)($_GET['id'] ?? 0);
if ($tid <= 0) exit(json_encode([]));

// Korrekte Tabellennamen: ordner_vorlagen_nodes und rel_path
$res = $mysqli->query("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id = $tid ORDER BY rel_path");
$out = [];
if ($res) {
    while($r = $res->fetch_assoc()) $out[] = $r['rel_path'];
}

header('Content-Type: application/json');
echo json_encode($out);
