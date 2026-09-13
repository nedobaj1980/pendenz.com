<?php
// /api/chat_rooms.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_bootstrap.php';   // stellt $mysqli, JSON helpers etc. bereit
require_once __DIR__ . '/../includes/auth.php';
require_login();

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role    = $_SESSION['rolle'] ?? 'gast';

// Kleine Helfer
function json_ok($data = []) { echo json_encode(['ok'=>true]+$data, JSON_UNESCAPED_UNICODE); exit; }
function json_err($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ------------------------------
// GET: Räume laden
// ------------------------------
if ($method === 'GET') {
    // Admins/Superadmins sehen alle Räume, andere nur eigene
    if ($role === 'superadmin' || $role === 'admin') {
        $sql = "SELECT * FROM chat_rooms ORDER BY updated_at DESC";
        $res = $mysqli->query($sql);
    } else {
        $sql = "SELECT r.* 
                  FROM chat_rooms r
                  JOIN chat_members m ON m.room_id=r.id AND m.user_id=?
                 ORDER BY r.updated_at DESC";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
    }

    $team = [];
    $project = [];
    while ($r = $res->fetch_assoc()) {
        if ($r['room_type'] === 'team') {
            $team[] = [
                'id'   => (int)$r['id'],
                'name' => $r['name'] ?: ('Team-Raum #'.$r['id']),
            ];
        } elseif ($r['room_type'] === 'project') {
            $project[] = [
                'id'           => (int)$r['id'],
                'name'         => $r['name'] ?: ('Projekt-Raum #'.$r['id']),
                'project_id'   => (int)($r['project_id'] ?? 0),
                'project_name' => $r['project_name'] ?? null
            ];
        }
    }

    json_ok(['team'=>$team,'project'=>$project]);
}

// ------------------------------
// POST: Räume erstellen
// ------------------------------
if ($method === 'POST' && $action === 'create') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $room_type = in_array(($payload['room_type'] ?? 'team'), ['team','project','direct'], true) ? $payload['room_type'] : 'team';
    $name      = trim($payload['name'] ?? '');
    $project_id = isset($payload['project_id']) ? (int)$payload['project_id'] : null;
    $member_ids = isset($payload['member_ids']) && is_array($payload['member_ids']) ? $payload['member_ids'] : [];

    if ($room_type !== 'direct' && $name === '') json_err("Name ist erforderlich.");
    if ($room_type === 'project' && !$project_id) json_err("project_id fehlt für project-Room.");

    // Direkt-Chat: exakt 2 Teilnehmer
    if ($room_type === 'direct') {
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
        $member_ids = array_diff($member_ids, [$user_id]);
        if (count($member_ids) !== 1) json_err("direct-Room benötigt genau 1 Gegenseite.");
    }

    // Raum anlegen
    $stmt = $mysqli->prepare("INSERT INTO chat_rooms (room_type, name, project_id, created_by) VALUES (?,?,?,?)");
    $stmt->bind_param("ssii", $room_type, $name, $project_id, $user_id);
    $stmt->execute();
    $room_id = $mysqli->insert_id;

    // Creator = admin
    $stmt = $mysqli->prepare("INSERT INTO chat_members(room_id, user_id, role) VALUES (?,?, 'admin') 
                               ON DUPLICATE KEY UPDATE role=VALUES(role)");
    $stmt->bind_param("ii", $room_id, $user_id);
    $stmt->execute();

    // Mitglieder hinzufügen
    if ($room_type === 'direct') {
        $other_id = (int)array_values($member_ids)[0];
        $stmt = $mysqli->prepare("INSERT INTO chat_members(room_id, user_id, role) VALUES (?,?, 'member') 
                                   ON DUPLICATE KEY UPDATE role=VALUES(role)");
        $stmt->bind_param("ii", $room_id, $other_id);
        $stmt->execute();
        // Auto-Name falls leer
        if ($name === '') {
            $q = $mysqli->prepare("SELECT CONCAT(b1.name,' ↔ ',b2.name) AS title 
                                     FROM benutzer b1, benutzer b2 
                                    WHERE b1.id=? AND b2.id=?");
            $q->bind_param("ii", $user_id, $other_id);
            $q->execute();
            $title = ($q->get_result()->fetch_assoc()['title'] ?? 'Direktchat');
            $q->close();
            $upd = $mysqli->prepare("UPDATE chat_rooms SET name=? WHERE id=?");
            $upd->bind_param("si", $title, $room_id);
            $upd->execute();
        }
    } else {
        if (!empty($member_ids)) {
            $ins = $mysqli->prepare("INSERT INTO chat_members(room_id, user_id, role) VALUES (?,?, 'member') 
                                      ON DUPLICATE KEY UPDATE role=VALUES(role)");
            foreach ($member_ids as $mid) {
                $mid = (int)$mid;
                if ($mid <= 0) continue;
                $ins->bind_param("ii", $room_id, $mid);
                $ins->execute();
            }
        }
    }

    $res = $mysqli->prepare("SELECT * FROM chat_rooms WHERE id=?");
    $res->bind_param("i", $room_id);
    $res->execute();
    $room = $res->get_result()->fetch_assoc();
    json_ok(['room'=>$room]);
}

// ------------------------------
// POST: Räume umbenennen
// ------------------------------
if ($method === 'POST' && $action === 'rename') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $room_id = (int)($payload['room_id'] ?? 0);
    $name = trim($payload['name'] ?? '');
    if ($room_id<=0 || $name==='') json_err("room_id und name erforderlich.");

    // Nur Admin oder Superadmin
    $stmt = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=? AND role='admin'");
    $stmt->bind_param("ii", $room_id, $user_id);
    $stmt->execute();
    $is_admin = (bool)$stmt->get_result()->fetch_column();
    if (!$is_admin && $role!=='superadmin') json_err("Keine Berechtigung.", 403);

    $upd = $mysqli->prepare("UPDATE chat_rooms SET name=?, updated_at=NOW() WHERE id=?");
    $upd->bind_param("si", $name, $room_id);
    $upd->execute();
    json_ok(['room_id'=>$room_id,'name'=>$name]);
}

json_err("Unbekannte Aktion oder Methode.", 404);
