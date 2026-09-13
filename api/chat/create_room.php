<?php
// C:\xampp\htdocs\pendenz.com\api\chat\create_room.php
// Robust: JSON-Fehlerausgaben, CSRF-Check, Schema-Erkennung (company_id/project_id optional)
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/csrf.php';

function jexit($ok, array $extra = [], int $code = 200) {
  http_response_code($code);
  echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function has_col(mysqli $db, string $table, string $col): bool {
  $t = $db->real_escape_string($table);
  $c = $db->real_escape_string($col);
  $rs = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$c}'");
  $ok = ($rs && $rs->num_rows > 0);
  if ($rs) $rs->close();
  return $ok;
}
function find_company_table(mysqli $db): ?string {
  foreach (['firmen','unternehmen','companies','company'] as $cand) {
    $x = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$cand}'");
    if ($x && $x->num_rows) { if ($x) $x->close(); return $cand; }
    if ($x) $x->close();
  }
  return null;
}

try {
  $me = (int)($_SESSION['user_id'] ?? 0);
  if ($me <= 0) jexit(false, ['error'=>'not_logged_in'], 401);
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_validate_request()) {
    jexit(false, ['error'=>'bad_request'], 400);
  }

  $type      = strtolower(trim((string)($_POST['type'] ?? '')));
  $name      = trim((string)($_POST['name'] ?? ''));
  $projectId = (int)($_POST['project_id'] ?? 0);
  $companyId = (int)($_POST['company_id'] ?? 0);
  $toUserId  = (int)($_POST['to_user_id'] ?? 0);
  $members   = trim((string)($_POST['member_ids'] ?? ''));
  $memberIds = [];
  if ($members !== '') {
    foreach (explode(',', $members) as $m) { $v=(int)trim($m); if($v>0) $memberIds[$v]=true; }
  }
  unset($memberIds[$me]);

  if (!in_array($type, ['dm','project','team','company'], true)) {
    jexit(false, ['error'=>'invalid_type'], 400);
  }

  $hasCompany = has_col($mysqli,'chat_rooms','company_id');
  $hasProject = has_col($mysqli,'chat_rooms','project_id');

  $roomId = 0;
  $roomName = '';

  if ($type === 'dm') {
    if ($toUserId <= 0) jexit(false, ['error'=>'to_user_id required'], 400);

    // schon vorhandenen DM-Chat finden
    $sql = "SELECT r.id
            FROM chat_rooms r
            JOIN chat_members m1 ON m1.room_id=r.id AND m1.user_id=?
            JOIN chat_members m2 ON m2.room_id=r.id AND m2.user_id=?
            WHERE r.room_type='dm'
            LIMIT 1";
    $st=$mysqli->prepare($sql); $st->bind_param("ii",$me,$toUserId); $st->execute();
    if ($row=$st->get_result()->fetch_assoc()) { $st->close(); jexit(true, ['room_id'=>(int)$row['id'],'existed'=>true]); }
    $st->close();

    $nm='';
    if ($ps=$mysqli->prepare("SELECT name FROM benutzer WHERE id=?")) { $ps->bind_param("i",$toUserId); $ps->execute(); if($r=$ps->get_result()->fetch_assoc()) $nm=$r['name']; $ps->close(); }
    $roomName = $nm ? "DM: {$nm}" : "Direktchat #{$toUserId}";

    $st=$mysqli->prepare("INSERT INTO chat_rooms (room_type, name, created_by) VALUES ('dm', ?, ?)");
    $st->bind_param("si",$roomName,$me); $st->execute(); $roomId=(int)$mysqli->insert_id; $st->close();

    $ins=$mysqli->prepare("INSERT IGNORE INTO chat_members (room_id, user_id, role) VALUES (?,?,?)");
    $role='admin'; $ins->bind_param("iis",$roomId,$me,$role); $ins->execute();
    $role='member'; $ins->bind_param("iis",$roomId,$toUserId,$role); $ins->execute();
    foreach(array_keys($memberIds) as $uid){ $role='member'; $ins->bind_param("iis",$roomId,$uid,$role); $ins->execute(); }
    $ins->close();
  }
  elseif ($type === 'project') {
    if ($projectId <= 0) jexit(false, ['error'=>'project_id required'], 400);
    if (!$hasProject)  jexit(false, ['error'=>'schema:chat_rooms.project_id missing'], 500);

    $st=$mysqli->prepare("SELECT id,name FROM chat_rooms WHERE room_type='project' AND project_id=? LIMIT 1");
    $st->bind_param("i",$projectId); $st->execute();
    if ($row=$st->get_result()->fetch_assoc()) { $roomId=(int)$row['id']; $roomName=$row['name']; $st->close(); }
    else {
      $st->close();
      $pname='Projekt #'.$projectId;
      if ($ps=$mysqli->prepare("SELECT name FROM projekte WHERE id=?")){ $ps->bind_param("i",$projectId); $ps->execute(); if($r=$ps->get_result()->fetch_assoc()) $pname=$r['name']; $ps->close(); }
      $roomName=$pname;
      $st=$mysqli->prepare("INSERT INTO chat_rooms (room_type, project_id, name, created_by) VALUES ('project', ?, ?, ?)");
      $st->bind_param("isi",$projectId,$roomName,$me); $st->execute(); $roomId=(int)$mysqli->insert_id; $st->close();
    }
    $ins=$mysqli->prepare("INSERT IGNORE INTO chat_members (room_id, user_id, role) VALUES (?,?,?)");
    $role='admin'; $ins->bind_param("iis",$roomId,$me,$role); $ins->execute();
    foreach(array_keys($memberIds) as $uid){ $role='member'; $ins->bind_param("iis",$roomId,$uid,$role); $ins->execute(); }
    $ins->close();
  }
  elseif ($type === 'team') {
    if ($name === '') jexit(false, ['error'=>'name required'], 400);
    $roomName=$name;
    $st=$mysqli->prepare("INSERT INTO chat_rooms (room_type, name, created_by) VALUES ('team', ?, ?)");
    $st->bind_param("si",$roomName,$me); $st->execute(); $roomId=(int)$mysqli->insert_id; $st->close();

    $ins=$mysqli->prepare("INSERT IGNORE INTO chat_members (room_id, user_id, role) VALUES (?,?,?)");
    $role='admin'; $ins->bind_param("iis",$roomId,$me,$role); $ins->execute();
    foreach(array_keys($memberIds) as $uid){ $role='member'; $ins->bind_param("iis",$roomId,$uid,$role); $ins->execute(); }
    $ins->close();
  }
  elseif ($type === 'company') {
    if ($companyId <= 0) jexit(false, ['error'=>'company_id required'], 400);
    $roomName = $name;
    if ($roomName === '') {
      $t = find_company_table($mysqli);
      if ($t) {
        $rs = $mysqli->query("SELECT name FROM {$t} WHERE id={$companyId} LIMIT 1");
        if ($rs && $r=$rs->fetch_assoc()) $roomName = $r['name'];
        if ($rs) $rs->close();
      }
      if ($roomName === '') $roomName = 'Unternehmenschat #'.$companyId;
    }
    if ($hasCompany) {
      $st=$mysqli->prepare("INSERT INTO chat_rooms (room_type, company_id, name, created_by) VALUES ('company', ?, ?, ?)");
      $st->bind_param("isi",$companyId,$roomName,$me);
    } else {
      // Fallback ohne company_id-Spalte
      $st=$mysqli->prepare("INSERT INTO chat_rooms (room_type, name, created_by) VALUES ('company', ?, ?)");
      $st->bind_param("si",$roomName,$me);
    }
    $st->execute(); $roomId=(int)$mysqli->insert_id; $st->close();

    $ins=$mysqli->prepare("INSERT IGNORE INTO chat_members (room_id, user_id, role) VALUES (?,?,?)");
    $role='admin'; $ins->bind_param("iis",$roomId,$me,$role); $ins->execute();
    foreach(array_keys($memberIds) as $uid){ $role='member'; $ins->bind_param("iis",$roomId,$uid,$role); $ins->execute(); }
    $ins->close();
  }

  $st=$mysqli->prepare("UPDATE chat_rooms SET updated_at=NOW() WHERE id=?");
  $st->bind_param("i",$roomId); $st->execute(); $st->close();

  jexit(true, ['room_id'=>$roomId, 'name'=>$roomName], 200);
} catch (mysqli_sql_exception $e) {
  jexit(false, ['error'=>'sql','message'=>$e->getMessage()], 500);
} catch (Throwable $e) {
  jexit(false, ['error'=>'server','message'=>$e->getMessage()], 500);
}
