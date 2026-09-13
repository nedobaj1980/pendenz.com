<?php
// api/manage_pdf_templates.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) { exit(json_encode(['success' => false, 'error' => 'Auth required'])); }

$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);

if ($action === 'list') {
    $res = $mysqli->query("SELECT id, name, is_default FROM pdf_templates ORDER BY name ASC");
    $list = []; while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(['success' => true, 'templates' => $list]);
}

if ($action === 'load') {
    $id = (int)($_GET['id'] ?? 0);
    $res = $mysqli->query("SELECT config_json FROM pdf_templates WHERE id=$id");
    $r = $res->fetch_assoc();
    echo json_encode(['success' => true, 'config' => json_decode($r['config_json'], true)]);
}

if ($action === 'save') {
    $name = $data['name'] ?? 'Neue Vorlage';
    $cfg = json_encode($data['config']);
    $stmt = $mysqli->prepare("INSERT INTO pdf_templates (name, config_json) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $cfg);
    if ($stmt->execute()) echo json_encode(['success' => true, 'id' => $mysqli->insert_id]);
    else echo json_encode(['success' => false, 'error' => $mysqli->error]);
}

if ($action === 'set_default') {
    $id = (int)$data['id'];
    $mysqli->query("UPDATE pdf_templates SET is_default = 0");
    $mysqli->query("UPDATE pdf_templates SET is_default = 1 WHERE id = $id");
    
    // Also update the global setting for the PDF generator
    $res = $mysqli->query("SELECT config_json FROM pdf_templates WHERE id=$id");
    $cfg = $res->fetch_assoc()['config_json'];
    $stmt = $mysqli->prepare("INSERT INTO settings (k, v) VALUES ('pdf_mask_default', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $stmt->bind_param("s", $cfg);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}

if ($action === 'delete') {
    $id = (int)$data['id'];
    $mysqli->query("DELETE FROM pdf_templates WHERE id = $id AND is_default = 0");
    echo json_encode(['success' => true]);
}
