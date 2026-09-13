<?php
// favoriten_toggle.php
if(session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$data = json_decode(file_get_contents('php://input'), true);
$unterkategorie_id = (int)($data['unterkategorie_id'] ?? 0);
$aktiv = isset($data['aktiv']) ? (bool)$data['aktiv'] : true;

if($unterkategorie_id <= 0){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Unterkategorie fehlt']);
    exit;
}

// Für simplicity: Entity = user, entity_id = current user
$userId = (int)($_SESSION['user_id'] ?? 0);

// Prüfen ob Favorit schon existiert
$stmt = $mysqli->prepare("SELECT id FROM favoriten WHERE entity_type='user' AND entity_id=? AND unterkategorie_id=?");
$stmt->bind_param("ii",$userId,$unterkategorie_id);
$stmt->execute(); $res=$stmt->get_result(); $exists=$res->fetch_assoc();
$stmt->close();

if($exists){
    $stmt = $mysqli->prepare("UPDATE favoriten SET aktiv=? WHERE id=?");
    $stmt->bind_param("ii",$aktiv,$exists['id']); $stmt->execute(); $stmt->close();
}else{
    $stmt = $mysqli->prepare("INSERT INTO favoriten (entity_type, entity_id, unterkategorie_id, aktiv) VALUES ('user',?,?,?)");
    $stmt->bind_param("iii",$userId,$unterkategorie_id,$aktiv); $stmt->execute(); $stmt->close();
}

echo json_encode(['ok'=>true,'aktiv'=>$aktiv]);
?>
<link rel="stylesheet" href="/pendenz.com/assets/css/projekt_baum.css">
<script src="/pendenz.com/assets/js/projekt_baum.js"></script>
