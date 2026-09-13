<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401); echo json_encode(['ok'=>false, 'error'=>'Unauthorized']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'update_cell') {
    $table = $input['table'] ?? '';
    $id    = (int)($input['id'] ?? 0);
    $field = $input['field'] ?? '';
    $value = $input['value'] ?? null;
    $idField = $input['id_field'] ?? 'id';

    // Whitelist Tables
    $allowedTables = ['pendenzen', 'projekte', 'benutzer', 'wohnung_pendenzen'];
    if (!in_array($table, $allowedTables)) {
        echo json_encode(['ok'=>false, 'error'=>'Invalid table']); exit;
    }

    // Whitelist Fields (Minimal for safety)
    $stmt = $mysqli->prepare("UPDATE `$table` SET `$field` = ? WHERE `$idField` = ?");
    if (!$stmt) {
        echo json_encode(['ok'=>false, 'error'=>$mysqli->error]); exit;
    }
    
    // Auto-detect type
    $type = 's';
    if (is_int($value)) $type = 'i';
    elseif (is_double($value)) $type = 'd';
    elseif (is_null($value)) {
        // Special null handle
        $mysqli->query("UPDATE `$table` SET `$field` = NULL WHERE `$idField` = $id");
        echo json_encode(['ok'=>true, 'value'=>null]); exit;
    }

    $stmt->bind_param($type.'i', $value, $id);
    if ($stmt->execute()) {
        echo json_encode(['ok'=>true, 'value'=>$value]);
    } else {
        echo json_encode(['ok'=>false, 'error'=>$stmt->error]);
    }
    $stmt->close();
}
elseif ($action === 'reorder_rows') {
    $table = $input['table'] ?? '';
    $orderField = $input['order_field'] ?? '';
    $rows = $input['rows'] ?? [];

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $pos = (int)$r['pos'];
        $mysqli->query("UPDATE `$table` SET `$orderField` = $pos WHERE id = $id");
    }
    echo json_encode(['ok'=>true]);
}
else {
    echo json_encode(['ok'=>false, 'error'=>'Unknown action']);
}
