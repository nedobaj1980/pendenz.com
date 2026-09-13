<?php
// api/pendenz_media.php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';
$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'invalid id']); exit; }

$p = $mysqli->query("SELECT * FROM pendenzen WHERE id={$pid}")->fetch_assoc();
if (!$p || !can_view_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) {
  echo json_encode(['ok'=>false,'error'=>'no access']); exit;
}

switch ($action) {
  case 'list':
    $rows=[];
    $res=$mysqli->query("SELECT id,typ,titel,pfad,mimetype,groesse,is_cover FROM pendenz_dateien WHERE pendenz_id={$pid} ORDER BY is_cover DESC, sort_index IS NULL, sort_index, id");
    while($r=$res->fetch_assoc()){
      $rows[]=[
        'id'=>(int)$r['id'],'typ'=>$r['typ'],'titel'=>$r['titel'],'pfad'=>$r['pfad'],
        'mimetype'=>$r['mimetype'],'groesse'=>(int)$r['groesse'],'is_cover'=>(int)$r['is_cover'],
      ];
    }
    echo json_encode(['ok'=>true,'items'=>$rows], JSON_UNESCAPED_UNICODE);
    break;

  case 'set_cover':
    if (!can_edit_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) { echo json_encode(['ok'=>false,'error'=>'no edit']); exit; }
    $fid=(int)($_POST['file_id']??0);
    $row=$mysqli->query("SELECT id FROM pendenz_dateien WHERE id={$fid} AND pendenz_id={$pid} AND typ='image'")->fetch_assoc();
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pid}");
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id={$fid}");
    echo json_encode(['ok'=>true]);
    break;

  case 'delete':
    if (!can_edit_pendenz($mysqli,$p,(int)($_SESSION['user_id']??0))) { echo json_encode(['ok'=>false,'error'=>'no edit']); exit; }
    $fid=(int)($_POST['file_id']??0);
    $row=$mysqli->query("SELECT * FROM pendenz_dateien WHERE id={$fid} AND pendenz_id={$pid}")->fetch_assoc();
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
    $abs = realpath(__DIR__ . '/../') . '/' . ltrim($row['pfad'],'/');
    if(is_file($abs)) @unlink($abs);
    $mysqli->query("DELETE FROM pendenz_dateien WHERE id={$fid}");
    if ((int)$row['is_cover']===1) {
      $next=$mysqli->query("SELECT id FROM pendenz_dateien WHERE pendenz_id={$pid} AND typ='image' ORDER BY id ASC LIMIT 1")->fetch_assoc();
      if($next) $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id=".(int)$next['id']);
    }
    echo json_encode(['ok'=>true]);
    break;

  default:
    echo json_encode(['ok'=>false,'error'=>'unknown action']);
}
