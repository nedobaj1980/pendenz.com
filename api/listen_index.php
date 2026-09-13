<?php
declare(strict_types=1);
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$table = isset($_GET['table']) ? trim((string)$_GET['table']) : 'pendenzen';
$mine  = isset($_GET['mine']) ? (int)$_GET['mine'] : 0; // 1 = nur eigene
$uid   = $_SESSION['user_id'] ?? null;

$isAdmin = in_array($_SESSION['rolle'] ?? '', ['admin', 'superadmin'], true);

if ($isAdmin) {
    $sql = "SELECT l.* FROM listen l WHERE l.table_name = ? ORDER BY l.shared DESC, l.name ASC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $table);
} else {
    $sql = "SELECT l.*
            FROM listen l
            WHERE l.table_name = ?
              AND (l.shared = 1 ".($uid ? " OR l.owner_id = ?" : "").") 
            ORDER BY l.shared DESC, l.name ASC";
    $stmt = $mysqli->prepare($sql);
    if ($uid) $stmt->bind_param('si', $table, $uid);
    else      $stmt->bind_param('s',  $table);
}

$stmt->execute();
$res  = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode(['ok'=>true,'rows'=>$rows]);
