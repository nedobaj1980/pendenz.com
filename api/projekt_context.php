<?php
// api/projekt_context.php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$projekt_id = (int)($_GET['projekt_id'] ?? 0);
$out = ['members'=>[], 'teams'=>[], 'folders'=>[]];
if ($projekt_id <= 0) { echo json_encode($out); exit; }

// Mitglieder
$stmt=$mysqli->prepare("
  SELECT DISTINCT b.id,b.name,b.email
  FROM benutzer b
  LEFT JOIN benutzer_projekte bp ON bp.benutzer_id=b.id AND bp.projekt_id=?
  LEFT JOIN team_projekte tp ON tp.projekt_id=?
  LEFT JOIN benutzer_teams bt ON bt.benutzer_id=b.id AND bt.team_id=tp.team_id
  WHERE bp.projekt_id IS NOT NULL OR bt.team_id IS NOT NULL
  ORDER BY b.name
");
$stmt->bind_param("ii",$projekt_id,$projekt_id);
$stmt->execute(); $res=$stmt->get_result();
while($r=$res->fetch_assoc()) $out['members'][]=['id'=>(int)$r['id'],'name'=>$r['name'],'email'=>$r['email']];
$stmt->close();

// Teams
$stmt=$mysqli->prepare("SELECT t.id,t.name FROM team_projekte tp JOIN teams t ON t.id=tp.team_id WHERE tp.projekt_id=? ORDER BY t.name");
$stmt->bind_param("i",$projekt_id); $stmt->execute(); $res=$stmt->get_result();
while($r=$res->fetch_assoc()) $out['teams'][]=['id'=>(int)$r['id'],'name'=>$r['name']];
$stmt->close();

// Ordner (flach, mit Pfadbeschriftung)
$res=$mysqli->prepare("SELECT id,name,parent_id FROM pendenz_ordner WHERE projekt_id=? ORDER BY parent_id,name");
$res->bind_param("i",$projekt_id); $res->execute(); $rs=$res->get_result();
$cache=[]; while($row=$rs->fetch_assoc()) $cache[(int)$row['id']]=$row;
$path=function($id) use (&$cache,&$path){
  $p=[]; while($id && isset($cache[$id])){ $p[]=$cache[$id]['name']; $id=(int)$cache[$id]['parent_id']; }
  return implode(' / ', array_reverse($p));
};
$folders=[];
foreach($cache as $id=>$row){ $folders[]=['id'=>$id,'label'=>$path($id) ?: $row['name']]; }
usort($folders, fn($a,$b)=>strcmp($a['label'],$b['label']));
$out['folders']=$folders;

echo json_encode($out, JSON_UNESCAPED_UNICODE);
