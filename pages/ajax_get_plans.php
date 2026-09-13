<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$projekt_id = (int)($_GET['projekt_id'] ?? 0);
if (!$projekt_id) {
    echo json_encode(['error' => 'Keine Projekt-ID']);
    exit;
}

$plaene = [];
$stmt = $mysqli->prepare("SELECT id, name, datei_pfad FROM projekt_plaene WHERE projekt_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) {
    $plaene[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'url' => url($row['datei_pfad'])
    ];
}
$stmt->close();

$zonen = [];
$stmt2 = $mysqli->prepare("
    SELECT z.plan_id, z.wohnung_id, z.x_pct, z.y_pct, z.width_pct, z.height_pct, w.objekt_id
    FROM plan_zonen z
    JOIN projekt_plaene p ON z.plan_id = p.id
    JOIN wohnungen w ON z.wohnung_id = w.id
    WHERE p.projekt_id = ?
");
$stmt2->bind_param("i", $projekt_id);
$stmt2->execute();
$res2 = $stmt2->get_result();
while($row = $res2->fetch_assoc()) {
    $zonen[] = [
        'plan_id' => $row['plan_id'],
        'wohnung_id' => $row['wohnung_id'],
        'objekt_id' => $row['objekt_id'],
        'x' => (float)$row['x_pct'],
        'y' => (float)$row['y_pct'],
        'w' => (float)$row['width_pct'],
        'h' => (float)$row['height_pct'],
    ];
}
$stmt2->close();

echo json_encode([
    'plaene' => $plaene,
    'zonen' => $zonen
]);
