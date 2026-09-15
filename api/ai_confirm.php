<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
api_try(function(){
  if(!is_logged_in()) json_response(['ok'=>false,'error'=>'UNAUTHORIZED'],401);
  $data=json_decode(file_get_contents('php://input'),true)?:$_POST; $token=(string)($data['token']??''); $pending=$_SESSION['gimi_pending_action']??null;
  if(!$pending || !hash_equals((string)$pending['token'],$token) || (int)$pending['user_id']!==(int)($_SESSION['user_id']??0) || time()-(int)$pending['created_at']>900) json_response(['ok'=>false,'error'=>'ACTION_EXPIRED'],400);
  if($pending['type']!=='CREATE_PENDENZ') json_response(['ok'=>false,'error'=>'ACTION_UNSUPPORTED'],400);
  $p=$pending['params']; $db=db(); $title=trim((string)($p['title']??'')); if($title==='') json_response(['ok'=>false,'error'=>'TITLE_MISSING'],400);
  $pid=(int)($p['project_id']??($_SESSION['current_project_id']??1)); $wid=!empty($p['wohnung_id'])?(int)$p['wohnung_id']:null; $prio=max(1,min(5,(int)($p['priority']??3))); $due=(string)($p['due']??date('Y-m-d')); $status='offen'; $uid=(int)($_SESSION['user_id']??0); $creator=$db->query("SELECT id FROM benutzer WHERE id=".$uid." LIMIT 1")->num_rows?$uid:null; $subject=trim((string)($p['subject']??$p['short']??'')); $long=trim((string)($p['long']??$p['description']??''));
  $st=$db->prepare('INSERT INTO pendenzen (titel,projekt_id,wohnung_id,kurzbeschreibung,langbeschreibung,wichtigkeit,erstellt_von,status,enddatum) VALUES (?,?,?,?,?,?,?,?,?)'); $st->bind_param('siissiiss',$title,$pid,$wid,$subject,$long,$prio,$creator,$status,$due); $st->execute(); $id=(int)$st->insert_id; $st->close(); unset($_SESSION['gimi_pending_action']); json_response(['ok'=>true,'id'=>$id,'title'=>$title]);
});
