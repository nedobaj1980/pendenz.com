<?php
// pages/storage_manager.php — v4.3 (kompakt) · Master: Browse/Assign/Cover/Templates/Locations/Trash/Log
if (session_status()===PHP_SESSION_NONE) session_start();
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/csrf.php';
require_login();

// --- Guards/Env
if (defined('SM_PAGE_ALREADY_RENDERED')) return; define('SM_PAGE_ALREADY_RENDERED',true);
$ROLE=$_SESSION['rolle']??'benutzer'; $IS_ADMIN=in_array($ROLE,['admin','superadmin'],true); $IS_SUPERADMIN=($ROLE==='superadmin');
$PREFIX=function_exists('site_prefix')?site_prefix():'/pendenz.com/';

// --- Helpers (NEU v4.3) ------------------------------------------------------
if (!function_exists('sm_cfg_root')) {
  function sm_cfg_root(): ?string {
    if (defined('PROJECT_STORAGE_ROOT') && PROJECT_STORAGE_ROOT) {
      $c = (string) PROJECT_STORAGE_ROOT;
      return rtrim(str_replace(['\\','/'], DIRECTORY_SEPARATOR, $c), DIRECTORY_SEPARATOR);
    }
    return null;
  }
}
if (!function_exists('sm__get_mysqli')) {
  function sm__get_mysqli() {
    if (!empty($GLOBALS['mysqli'])) return $GLOBALS['mysqli'];
    if (function_exists('db')) { try { $dbc = db(); if ($dbc) return $dbc; } catch (Throwable $e) {} }
    $dbphp = __DIR__.'/../includes/db.php';
    if (is_file($dbphp)) { require_once $dbphp; if (!empty($GLOBALS['mysqli'])) return $GLOBALS['mysqli']; }
    return null;
  }
}
if (!function_exists('sm_project_root')) {
  function sm_project_root(int $pid): string {
    $m = sm__get_mysqli();
    if ($m instanceof mysqli) {
      try {
        if ($st = $m->prepare("SELECT root_path, storage_root FROM projekte WHERE id=? LIMIT 1")) {
          $st->bind_param('i',$pid);
          if ($st->execute() && ($r=$st->get_result()) && ($row=$r->fetch_assoc())) {
            $root_path = trim((string)($row['root_path'] ?? ''));
            if ($root_path !== '') return rtrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$root_path),DIRECTORY_SEPARATOR);
            $storage_root = trim((string)($row['storage_root'] ?? ''));
            if ($storage_root !== '') return rtrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$storage_root),DIRECTORY_SEPARATOR);
          }
        }
      } catch (Throwable $e) { /* fallthrough */ }
    }
    $cfg = sm_cfg_root(); if ($cfg) return $cfg.DIRECTORY_SEPARATOR.'project_'.$pid;
    $base = realpath(__DIR__.'/../storage'); if(!$base){@mkdir(__DIR__.'/../storage',0777,true); $base=realpath(__DIR__.'/../storage');}
    return $base.DIRECTORY_SEPARATOR.'project_'.$pid;
  }
}
if (!function_exists('sm_project_root_source')) {
  function sm_project_root_source(int $pid): string {
    $m = sm__get_mysqli();
    if ($m instanceof mysqli) {
      try {
        if ($st = $m->prepare("SELECT root_path, storage_root FROM projekte WHERE id=? LIMIT 1")) {
          $st->bind_param('i',$pid);
          if ($st->execute() && ($r=$st->get_result()) && ($row=$r->fetch_assoc())) {
            if (trim((string)$row['root_path'])   !== '') return 'DB: projekte.root_path';
            if (trim((string)$row['storage_root'])!== '') return 'DB: projekte.storage_root';
          }
        }
      } catch (Throwable $e) {}
    }
    if (defined('PROJECT_STORAGE_ROOT') && PROJECT_STORAGE_ROOT) return 'Konstante PROJECT_STORAGE_ROOT';
    return 'Fallback: /storage/project_'.$pid;
  }
}
if (!function_exists('sm_relpath_sanitize')) {
  function sm_relpath_sanitize(string $rel): string {
    $rel = trim($rel);
    $rel = str_replace(["\0"], '', $rel);
    $parts = array_filter(explode('/', str_replace('\\','/',$rel)), fn($p)=>$p!=='' && $p!=='.' && $p!=='..');
    return implode('/', $parts);
  }
}
if (!function_exists('sm_path_join')) {
  function sm_path_join(string $root,string $rel):string { return rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.sm_relpath_sanitize($rel); }
}
if (!function_exists('sm_is_subpath')) {
  function sm_is_subpath(string $root,string $path):bool{
    $root=rtrim(realpath($root)?:$root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    $p=realpath($path); if(!$p) return false;
    $p=rtrim($p,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    return str_starts_with($p,$root);
  }
}
if (!function_exists('sm_uuid')) { function sm_uuid():string{
  if (function_exists('random_bytes')) return bin2hex(random_bytes(16));
  if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes(16));
  return substr(md5(uniqid('',true)),0,32);
}}
if (!function_exists('sm_json')) { function sm_json($ok,$data=null,$msg=''){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>$ok,'data'=>$data,'msg'=>$msg], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}}
if (!function_exists('sm_require_admin')) { function sm_require_admin(){ if(!($GLOBALS['IS_ADMIN']??false)) sm_json(false,null,'Keine Berechtigung'); } }
if (!function_exists('sm_require_superadmin')) { function sm_require_superadmin(){ if(!($GLOBALS['IS_SUPERADMIN']??false)) sm_json(false,null,'Nur Superadmin'); } }
if (!function_exists('sm_csrf_check')) { function sm_csrf_check(){ if(!csrf_validate($_POST['csrf_token']??'')) sm_json(false,null,'CSRF ungültig'); } }
if (!function_exists('sm_log')) { function sm_log(int $pid,string $user,string $action,array $payload=[]):void{
  $root=sm_project_root($pid); $log=$root.DIRECTORY_SEPARATOR.'._audit.log';
  $row=['ts'=>date('c'),'user'=>$user,'action'=>$action,'payload'=>$payload];
  @file_put_contents($log,json_encode($row,JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND);
}}
if (!function_exists('sm_load_meta')) { function sm_load_meta(string $abs): array {
  $f=rtrim($abs,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.meta.json';
  if (is_file($f) && ($j=@file_get_contents($f))) { $d=json_decode($j,true); if (is_array($d)) return $d; }
  return [];
}}
if (!function_exists('sm_save_meta')) { function sm_save_meta(string $abs,array $meta): bool {
  if (!is_dir($abs)) return false;
  $f=rtrim($abs,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.meta.json';
  return (bool)@file_put_contents($f, json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}}

// --- Projekt erzwingen
$projekt_id=(int)($_GET['projekt_id']??$_POST['projekt_id']??0); if($projekt_id<=0) $projekt_id=(int)($_SESSION['last_project_id']??0);
if($projekt_id<=0){echo '<div style="padding:16px;font:14px/1.4 system-ui">Projekt wählen: <code>/pages/storage_manager.php?projekt_id=4&path=Projekt/…</code></div>'; exit;}
$_SESSION['last_project_id']=$projekt_id; $root=sm_project_root($projekt_id); $root_src=sm_project_root_source($projekt_id);
$api=isset($_GET['api'])?(int)$_GET['api']:0;

// --- API
if($api===1){
  header('Cache-Control: no-store');
  $action=$_GET['action']??''; $rel=sm_relpath_sanitize($_GET['path']??$_POST['path']??''); $abs=$rel?sm_path_join($root,$rel):$root; if(!is_dir($root)) @mkdir($root,0777,true);
  try{
    switch($action){
      case 'list':{
        $path=is_dir($abs)?$abs:dirname($abs); if(!sm_is_subpath($root,$path)) sm_json(false,null,'Pfad ungültig');
        $q=trim((string)($_GET['q']??'')); $items=[]; if($d=@opendir($path)){while(($e=readdir($d))!==false){
          if($e==='.'||$e==='..'||$e==='._trash') continue; if($q!==''&&stripos($e,$q)===false) continue;
          $ap=$path.DIRECTORY_SEPARATOR.$e; $st=@stat($ap); $isDir=is_dir($ap);
          $rel_item=trim(str_replace($root,'',$ap),'\\/'); $meta=$isDir?sm_load_meta($ap):[];
          $thumb=null; if($isDir&&!empty($meta['cover'])) $thumb=$meta['cover'];
          if(!$thumb&&!$isDir){$ext=strtolower(pathinfo($e,PATHINFO_EXTENSION)); if(in_array($ext,['jpg','jpeg','png','gif','webp'])) $thumb=$rel_item;}
          $items[]=['name'=>$e,'type'=>$isDir?'dir':'file','rel_path'=>$rel_item,'size'=>$isDir?null:($st['size']??null),'mtime'=>isset($st['mtime'])?date('Y-m-d H:i',(int)$st['mtime']):null,'assign'=>$meta['assign']??null,'cover'=>$meta['cover']??null,'thumb'=>$thumb];
        } closedir($d);}
        $crumbs=[]; $rel_curr=trim(str_replace($root,'',$path),'\\/'); $acc=''; foreach(array_filter(explode('/',$rel_curr)) as $seg){$acc=$acc?($acc.'/'.$seg):$seg; $crumbs[]=['name'=>$seg,'rel'=>$acc];}
        sm_json(true,['root'=>$root,'cwd'=>$rel_curr,'breadcrumbs'=>$crumbs,'items'=>$items,'role'=>$GLOBALS['ROLE']??'benutzer','csrf'=>csrf_token()]);
      }
      case 'mkdir':{ sm_require_admin(); sm_csrf_check(); $name=trim($_POST['name']??''); if($name===''||preg_match('/[\\:*?"<>|]/',$name)) sm_json(false,null,'Ungültiger Ordnername'); $dest=rtrim($abs,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name; if(!sm_is_subpath($root,$dest)) sm_json(false,null,'Pfad ungültig'); if(file_exists($dest)) sm_json(false,null,'Existiert bereits'); if(!@mkdir($dest,0777,true)) sm_json(false,null,'mkdir fehlgeschlagen'); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'mkdir',['path'=>$rel.'/'.$name]); sm_json(true,['ok'=>1]);}
      case 'rename':{ sm_require_admin(); sm_csrf_check(); $new=trim($_POST['new_name']??''); if($new===''||preg_match('/[\\:*?"<>|]/',$new)) sm_json(false,null,'Ungültiger Name'); $parent=dirname($abs); $dest=rtrim($parent,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$new; if(!sm_is_subpath($root,$dest)) sm_json(false,null,'Pfad ungültig'); if(file_exists($dest)) sm_json(false,null,'Ziel existiert'); if(!@rename($abs,$dest)) sm_json(false,null,'rename fehlgeschlagen'); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'rename',['from'=>str_replace($root.'/','',$abs),'to'=>str_replace($root.'/','',$dest)]); sm_json(true,['ok'=>1]);}
      case 'move':{ sm_require_admin(); sm_csrf_check(); $toRel=sm_relpath_sanitize($_POST['to']??''); $toAbs=sm_path_join($root,$toRel); if(!is_dir($toAbs)) sm_json(false,null,'Zielordner existiert nicht'); $dest=rtrim($toAbs,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($abs); if(!sm_is_subpath($root,$dest)) sm_json(false,null,'Pfad ungültig'); if(file_exists($dest)) sm_json(false,null,'Ziel existiert'); if(!@rename($abs,$dest)) sm_json(false,null,'move fehlgeschlagen'); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'move',['from'=>str_replace($root.'/','',$abs),'to'=>str_replace($root.'/','',$dest)]); sm_json(true,['ok'=>1]);}
      case 'delete_soft':{ sm_require_admin(); sm_csrf_check(); $trash=$root.DIRECTORY_SEPARATOR.'._trash'; if(!is_dir($trash)) @mkdir($trash,0777,true); $bucket=$trash.DIRECTORY_SEPARATOR.sm_uuid(); @mkdir($bucket,0777,true); $dest=$bucket.DIRECTORY_SEPARATOR.basename($abs); if(!sm_is_subpath($root,$abs)||!sm_is_subpath($root,$bucket)) sm_json(false,null,'Pfad ungültig'); if(!@rename($abs,$dest)) sm_json(false,null,'Papierkorb verschieben fehlgeschlagen'); @file_put_contents($bucket.DIRECTORY_SEPARATOR.'restore.json',json_encode(['orig_rel'=>str_replace($root.DIRECTORY_SEPARATOR,'',$abs),'deleted_by'=>(string)($_SESSION['name']??'user'),'deleted_at'=>date('c')],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); $_SESSION['sm_last_delete_bucket']=basename($bucket); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'delete_soft',['path'=>str_replace($root.'/','',$abs)]); sm_json(true,['ok'=>1,'bucket'=>basename($bucket)]);}
      case 'trash_list':{ $rows=[]; $trash=$root.DIRECTORY_SEPARATOR.'._trash'; if(is_dir($trash)&&($dh=opendir($trash))){while(($b=readdir($dh))!==false){ if($b==='.'||$b==='..') continue; $bucket=$trash.DIRECTORY_SEPARATOR.$b; if(!is_dir($bucket)) continue; $metaFile=$bucket.DIRECTORY_SEPARATOR.'restore.json'; $meta=[]; if(is_file($metaFile)) $meta=json_decode(@file_get_contents($metaFile),true)?:[]; $cont=[]; if($cdh=@opendir($bucket)){while(($e=readdir($cdh))!==false){ if(in_array($e,['.','..','restore.json'])) continue; $cont[]=$e;} closedir($cdh);} $rows[]=['bucket'=>$b,'orig_rel'=>$meta['orig_rel']??null,'deleted_by'=>$meta['deleted_by']??null,'deleted_at'=>$meta['deleted_at']??null,'contents'=>$cont];} closedir($dh);} sm_json(true,['items'=>$rows]);}
      case 'restore':{ sm_require_admin(); sm_csrf_check(); $bucket=sm_relpath_sanitize($_POST['bucket']??''); $bdir=$root.DIRECTORY_SEPARATOR.'._trash'.DIRECTORY_SEPARATOR.$bucket; $metaFile=$bdir.DIRECTORY_SEPARATOR.'restore.json'; if(!is_dir($bdir)||!is_file($metaFile)) sm_json(false,null,'Bucket ungültig'); $meta=json_decode((string)@file_get_contents($metaFile),true)?:[]; $orig=$meta['orig_rel']??''; if($orig==='') sm_json(false,null,'Orig-Pfad fehlt'); $target=sm_path_join($root,$orig); $parent=dirname($target); if(!is_dir($parent)) @mkdir($parent,0777,true); $ok=true;$err=''; if($cdh=@opendir($bdir)){while(($e=readdir($cdh))!==false){ if(in_array($e,['.','..','restore.json'])) continue; $src=$bdir.DIRECTORY_SEPARATOR.$e; $dst=$parent.DIRECTORY_SEPARATOR.$e; if(file_exists($dst)){ $ok=false;$err='Ziel existiert bereits'; break;} if(!@rename($src,$dst)){ $ok=false;$err='restore rename fehlgeschlagen'; break;}} closedir($cdh);} if($ok){@unlink($metaFile); @rmdir($bdir); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'restore',['path'=>$orig]); sm_json(true,['ok'=>1]);} sm_json(false,null,$err?:'Restore fehlgeschlagen');}
      case 'delete_hard':{ sm_require_superadmin(); sm_csrf_check(); $bucket=sm_relpath_sanitize($_POST['bucket']??''); $bdir=$root.DIRECTORY_SEPARATOR.'._trash'.DIRECTORY_SEPARATOR.$bucket; if(!is_dir($bdir)) sm_json(false,null,'Bucket ungültig'); $it=new RecursiveDirectoryIterator($bdir,FilesystemIterator::SKIP_DOTS); $ri=new RecursiveIteratorIterator($it,RecursiveIteratorIterator::CHILD_FIRST); foreach($ri as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($bdir); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'delete_hard',['bucket'=>$bucket]); sm_json(true,['ok'=>1]);}
      case 'assign':{ sm_require_admin(); sm_csrf_check(); $aj=(string)($_POST['assign_json']??'{}'); $data=json_decode($aj,true); if(!is_array($data)) sm_json(false,null,'assign_json ungültig'); if(!is_dir($abs)) sm_json(false,null,'Nur Ordner können zugewiesen werden'); $meta=sm_load_meta($abs); $meta['assign']=$data; sm_save_meta($abs,$meta); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'assign',['path'=>str_replace($root.'/','',$abs),'assign'=>$data]); sm_json(true,['ok'=>1]);}
      case 'cover_select':{ sm_require_admin(); sm_csrf_check(); $cover_rel=sm_relpath_sanitize($_POST['cover_rel']??''); if(!is_dir($abs)) sm_json(false,null,'Nur Ordner'); $cover_abs=sm_path_join($root,$cover_rel); if(!sm_is_subpath($abs,$cover_abs)) sm_json(false,null,'Cover muss im selben Ordnerzweig liegen'); if(!is_file($cover_abs)) sm_json(false,null,'Cover existiert nicht'); $meta=sm_load_meta($abs); $meta['cover']=$cover_rel; sm_save_meta($abs,$meta); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'cover_select',['path'=>str_replace($root+'/','',$abs),'cover'=>$cover_rel]); sm_json(true,['ok'=>1]);}
      case 'cover_candidates':{ $p=is_dir($abs)?$abs:dirname($abs); $cand=rtrim($p,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'_preview'; $out=[]; if(is_dir($cand)&&($dh=opendir($cand))){while(($e=readdir($dh))!==false){ if($e==='.'||$e==='..') continue; $ext=strtolower(pathinfo($e,PATHINFO_EXTENSION)); if(!in_array($ext,['jpg','jpeg','png','webp','gif'])) continue; $out[]=trim(str_replace($root,'',$cand.DIRECTORY_SEPARATOR.$e),'\\/');} closedir($dh);} sm_json(true,['items'=>$out]);}
      case 'upload':{ sm_require_admin(); sm_csrf_check(); if(!is_dir($abs)) sm_json(false,null,'Zielordner existiert nicht'); if(empty($_FILES['files'])) sm_json(false,null,'Keine Dateien'); $moved=[]; foreach($_FILES['files']['name'] as $i=>$name){ $tmp=$_FILES['files']['tmp_name'][$i]; $safe=preg_replace('/[\\/:*?"<>|]+/','_',$name); $dst=rtrim($abs,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$safe; if(!@move_uploaded_file($tmp,$dst)) sm_json(false,null,"Upload fehlgeschlagen: $safe"); $moved[]=$safe;} sm_log($projekt_id,(string)($_SESSION['name']??'user'),'upload',['path'=>str_replace($root.'/','',$abs),'files'=>$moved]); sm_json(true,['files'=>$moved]);}
      case 'logs':{ $log=$root.DIRECTORY_SEPARATOR.'._audit.log'; $out=[]; if(is_file($log)){$lines=@file($log,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]; $lines=array_slice(array_reverse($lines),0,500); foreach($lines as $ln){$j=json_decode($ln,true); if($j) $out[]=$j;}} sm_json(true,['items'=>$out]);}
      case 'search':{ $q=trim((string)($_GET['q']??'')); if($q==='') sm_json(true,['items'=>[]]); $baseRel=sm_relpath_sanitize($_GET['path']??''); $baseAbs=$baseRel?sm_path_join($root,$baseRel):$root; if(!is_dir($baseAbs)||!sm_is_subpath($root,$baseAbs)) sm_json(false,null,'Startpfad ungültig'); $max=max(25,(int)($_GET['max']??200)); $out=[]; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseAbs,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST); foreach($it as $p){ $fn=$p->getFilename(); if(strpos($p->getPathname(),DIRECTORY_SEPARATOR.'._trash'.DIRECTORY_SEPARATOR)!==false) continue; if(stripos($fn,$q)===false) continue; $rel_item=trim(str_replace($root,'',$p->getPathname()),'\\/'); $st=@stat($p->getPathname()); $isDir=$p->isDir(); $meta=$isDir?sm_load_meta($p->getPathname()):[]; $thumb=null; if($isDir&&!empty($meta['cover'])) $thumb=$meta['cover']; if(!$thumb&&!$isDir){$ext=strtolower(pathinfo($fn,PATHINFO_EXTENSION)); if(in_array($ext,['jpg','jpeg','png','gif','webp'])) $thumb=$rel_item;} $out[]=['name'=>$fn,'type'=>$isDir?'dir':'file','rel_path'=>$rel_item,'size'=>$isDir?null:($st['size']??null),'mtime'=>isset($st['mtime'])?date('Y-m-d H:i',(int)$st['mtime']):null,'assign'=>$meta['assign']??null,'cover'=>$meta['cover']??null,'thumb'=>$thumb]; if(count($out)>=$max) break;} sm_json(true,['items'=>$out,'q'=>$q,'base'=>$baseRel,'csrf'=>csrf_token()]);}
      case 'undo_delete':{ sm_require_admin(); sm_csrf_check(); $bucket=(string)($_SESSION['sm_last_delete_bucket']??''); if($bucket==='') sm_json(false,null,'Nichts zu rückgängig machen'); $bdir=$root.DIRECTORY_SEPARATOR.'._trash'.DIRECTORY_SEPARATOR.$bucket; $metaFile=$bdir.DIRECTORY_SEPARATOR.'restore.json'; if(!is_dir($bdir)||!is_file($metaFile)) sm_json(false,null,'Bucket ungültig'); $meta=json_decode((string)@file_get_contents($metaFile),true)?:[]; $orig=$meta['orig_rel']??''; if($orig==='') sm_json(false,null,'Orig-Pfad fehlt'); $target=sm_path_join($root,$orig); $parent=dirname($target); if(!is_dir($parent)) @mkdir($parent,0777,true); $ok=true;$err=''; if($cdh=@opendir($bdir)){while(($e=readdir($cdh))!==false){ if(in_array($e,['.','..','restore.json'])) continue; $src=$bdir.DIRECTORY_SEPARATOR.$e; $dst=$parent.DIRECTORY_SEPARATOR.$e; if(file_exists($dst)){ $ok=false;$err='Ziel existiert bereits'; break;} if(!@rename($src,$dst)){ $ok=false;$err='restore rename fehlgeschlagen'; break;}} closedir($cdh);} if($ok){@unlink($metaFile); @rmdir($bdir); unset($_SESSION['sm_last_delete_bucket']); sm_log($projekt_id,(string)($_SESSION['name']??'user'),'undo_delete',['path'=>$orig,'bucket'=>$bucket]); sm_json(true,['ok'=>1]);} sm_json(false,null,$err?:'Undo fehlgeschlagen');}
      default: sm_json(false,null,'Unbekannte Aktion');
    }
  }catch(Throwable $e){ sm_json(false,null,'Fehler: '.$e->getMessage()); }
}

$path_init=sm_relpath_sanitize($_GET['path']??''); $csrf_tok=csrf_token();
?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Storage Manager – Projekt <?php echo (int)$projekt_id; ?></title>
<style>
:root{--bg:#f7f8fb;--card:#fff;--muted:#667085;--border:#e6e9ef;--text:#1f2937;--acc:#2563eb;--acc-soft:rgba(37,99,235,.08)}
*{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,Segoe UI,Roboto,Arial}
.container{max-width:1600px;margin:0 auto;padding:16px} .row{display:flex;gap:16px;flex-wrap:wrap} .col{flex:1 1 0} .col-3{flex:0 0 360px;max-width:360px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.04)} .card .card-body{padding:16px}
.mb-3{margin-bottom:12px}.mb-2{margin-bottom:8px}.p-3{padding:12px}.small{font-size:12px}.text-muted{color:var(--muted)} .h4{font-size:18px;margin:0}
.toolbar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap} .btn{display:inline-block;border:1px solid var(--border);background:#fff;border-radius:8px;padding:6px 10px;font-size:13px;cursor:pointer}
.btn:hover{background:#f3f4f6}.btn.primary{border-color:#1d4ed8;background:#2563eb;color:#fff}.btn.danger{border-color:#b91c1c;background:#dc2626;color:#fff}.btn.sm{padding:4px 8px;font-size:12.5px}
.input,.select,textarea{width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:8px;background:#fff;font-size:13px} .input-group{display:flex;gap:6px}
.table{width:100%;border-collapse:collapse} .table th,.table td{border-bottom:1px solid var(--border);padding:8px;vertical-align:middle} .sticky{position:sticky;top:0;background:var(--card);z-index:3;border-bottom:1px solid var(--border)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px} .grid .item{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:12px;box-shadow:0 6px 20px rgba(0,0,0,.04);cursor:pointer}
.grid .thumb{width:100%;height:120px;object-fit:cover;border-radius:10px;background:#f2f4f7}.cover-thumb{max-height:56px;border-radius:8px}
.file-row.selected,.grid .item.selected{outline:2px solid var(--acc);outline-offset:1px;background:var(--acc-soft)} .empty{border:1px dashed var(--border);border-radius:12px;padding:16px;text-align:center;color:var(--muted)}
.ctx{position:fixed;background:var(--card);border:1px solid var(--border);border-radius:.6rem;padding:.25rem;box-shadow:0 16px 36px rgba(2,6,23,.08);display:none;z-index:9999}
.ctx button{display:block;width:100%;text-align:left;padding:.45rem .7rem;background:transparent;border:none;border-radius:.45rem} .ctx button:hover{background:var(--acc-soft)}
.tabs{display:flex;gap:6px;flex-wrap:wrap}.tab{padding:6px 10px;border:1px solid var(--border);border-radius:8px;background:#fff;cursor:pointer}.tab.active{background:#eef2ff;border-color:#c7d2fe}
.tabpane{display:none}.tabpane.active{display:block}.selbar{position:sticky;bottom:0;z-index:4;display:none;margin-top:10px}
.selbar .wrap{display:flex;gap:8px;align-items:center;justify-content:space-between;background:#fff;border:1px solid var(--border);border-radius:12px;padding:8px 10px;box-shadow:0 10px 24px rgba(2,6,23,.06)}
.modal{position:fixed;inset:0;background:rgba(2,6,23,.45);display:none;align-items:center;justify-content:center;z-index:9998}
.modal .sheet{width:min(920px,92vw);max-height:80vh;overflow:auto;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 24px 64px rgba(2,6,23,.25)}
.modal .head,.modal .foot{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border)}
.modal .foot{border-top:1px solid var(--border);border-bottom:none} .modal .body{padding:12px}
.hero{background:linear-gradient(135deg,rgba(37,99,235,.06),rgba(99,102,241,.05));border:1px solid var(--border);border-radius:14px}
.topnav{background:#0f172a;color:#e5e7eb} .topnav .wrap{max-width:1600px;margin:0 auto;display:flex;align-items:center;gap:14px;padding:10px 16px}
.topnav a{color:#e5e7eb;text-decoration:none;padding:6px 10px;border-radius:8px} .topnav a:hover{background:#1f2937}
.tree .node .tw{display:flex;align-items:center;gap:6px} .btn-icon{border:1px solid var(--border);border-radius:8px;background:#fff;padding:2px 6px;cursor:pointer}
</style>
</head><body>
<nav class="topnav"><div class="wrap">
  <div class="brand">pendenz.com</div>
  <a href="<?php echo htmlspecialchars($PREFIX); ?>index.php">Dashboard</a>
  <a href="<?php echo htmlspecialchars($PREFIX); ?>pages/projekte.php">Projekte</a>
  <a href="<?php echo htmlspecialchars($PREFIX); ?>pages/pendenzen.php">Pendenzen</a>
  <a href="<?php echo htmlspecialchars($PREFIX); ?>pages/benutzer.php">Benutzer</a>
  <a href="<?php echo htmlspecialchars($PREFIX); ?>pages/storage_manager.php?projekt_id=<?php echo (int)$projekt_id; ?>">Storage</a>
  <span style="margin-left:auto" class="small">Rolle: <b><?php echo htmlspecialchars($ROLE); ?></b> · Projekt #<?php echo (int)$projekt_id; ?></span>
</div></nav>

<div class="container">
  <div class="hero p-3 mb-3">
    <div class="row" style="align-items:center">
      <div class="col">
        <h1 class="h4">Storage Manager <span class="text-muted">· Projekt #<?php echo (int)$projekt_id; ?></span></h1>
        <div class="small text-muted">Browse · Assign · Cover · Templates · Locations · Trash · Log</div>
      </div>
      <div class="col" style="flex:0 0 auto"><span class="small">ROLE</span> <b><?php echo htmlspecialchars($ROLE); ?></b></div>
    </div>
  </div>

  <div class="row">
    <!-- Sidebar -->
    <div class="col col-3 card p-3">
      <div class="mb-2" style="display:flex;align-items:center;justify-content:space-between"><strong>Navigation</strong>
        <div class="btn-group">
          <button class="btn tab" data-target="browse">Browse</button>
          <button class="btn tab" data-target="assign">Assign</button>
          <button class="btn tab" data-target="cover">Cover</button>
        </div>
      </div>

      <div class="mb-3">
        <label class="small">Pfad</label>
        <div class="input-group">
          <input class="input" id="inpPath" placeholder="z. B. Projekt/Objekt 1/…" value="<?php echo htmlspecialchars($path_init); ?>">
          <button class="btn primary" id="btnGo">Öffnen</button>
        </div>
        <div class="small text-muted" id="cwdInfo" style="margin-top:6px">Wähle einen Ordner oder tippe Pfad.</div>
        <div class="toolbar" style="margin-top:6px;gap:6px">
          <button class="btn" id="btnUp">↑ Ebene</button>
          <div class="input-group" style="width:100%"><input class="input" id="inpFilter" placeholder="Suchen… (Ordner)"><button class="btn" id="btnRefresh">⟳</button></div>
        </div>
      </div>

      <div class="small" id="breadcrumbs" style="margin-bottom:8px"></div>

      <div class="mb-3">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><strong>Ordnerstruktur</strong>
          <div class="btn-group"><button class="btn" id="btnExpandAll">Auf</button><button class="btn" id="btnCollapseAll">Zu</button></div>
        </div>
        <div class="tree" id="tree" style="max-height:45vh;overflow:auto;border:1px solid var(--border);border-radius:12px;padding:8px;background:#fafbff"></div>
        <div class="small text-muted" style="margin-top:6px">Tipp: Dateien/Ordner auf einen Zielordner ziehen.</div>
      </div>

      <div style="display:grid;gap:8px">
        <button class="btn primary" id="btnMkdir">+ Neuer Ordner</button>
        <div class="small text-muted">Shortcuts: <code>N</code> neu · <code>F2</code> umbenennen · <code>Entf</code> löschen</div>
      </div>
    </div>

    <!-- Main -->
    <div class="col">
      <div class="card mb-3"><div class="card-body">
        <div class="tabs" id="tabs">
          <div class="tab active" data-target="browse">Browse</div>
          <div class="tab" data-target="assign">Assign</div>
          <div class="tab" data-target="cover">Cover</div>
          <div class="tab" data-target="templates">Templates</div>
          <div class="tab" data-target="locations">Locations</div>
          <div class="tab" data-target="trash">Trash</div>
          <div class="tab" data-target="log">Log</div>
        </div>
      </div></div>

      <!-- Browse -->
      <div class="card tabpane active" id="pane-browse"><div class="card-body">
        <div class="toolbar mb-3">
          <div class="btn-group"><button class="btn" id="btnList">Liste</button><button class="btn" id="btnGrid">Kacheln</button></div>
          <div class="input-group" style="min-width:320px;margin-left:8px"><input class="input" id="inpGlobalSearch" placeholder="Global suchen… (rekursiv)"><button class="btn" id="btnGlobalSearch">Suchen</button></div>
          <div class="spacer" style="flex:1"></div>
          <div class="btn-group"><button class="btn" id="btnBulkMove">Verschieben…</button><button class="btn danger" id="btnBulkDelete">Löschen</button></div>
        </div>

        <div id="listView">
          <table class="table"><thead class="sticky"><tr>
            <th style="width:30px"><input type="checkbox" id="chkAll" title="Alles auswählen"></th>
            <th>Name</th><th>Typ</th><th>Größe</th><th>Geändert</th><th>Zuweisung</th><th>Cover/Vorschau</th><th style="width:240px">Aktionen</th>
          </tr></thead><tbody><tr class="empty"><td colspan="8">Noch nichts hier? Dateien per Drag&Drop hierher oder „+ Neuer Ordner“.</td></tr></tbody></table>
        </div>
        <div id="gridView" class="grid" style="display:none"><div class="empty">Kachelansicht für Ordner mit Covern/Bildern.</div></div>

        <div class="small text-muted" id="previewPanel" style="display:none;margin-top:12px"><div class="card"><div class="card-body">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><strong id="pvTitle">Vorschau</strong><div class="text-muted" id="pvMeta"></div></div>
          <div id="pvBody"></div>
        </div></div></div>

        <!-- Inspector (Assign & Cover Schnellzugriff) -->
        <div class="card" id="inspector" style="display:none;margin-top:12px"><div class="card-body">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><strong>Inspector</strong><span class="small text-muted">für ausgewählten Ordner</span></div>
          <div class="row" style="gap:12px;align-items:end;flex-wrap:wrap">
            <div class="col" style="min-width:280px"><label class="small">Ordner</label><input class="input" id="inspPath" readonly></div>
            <div class="col" style="min-width:160px"><label class="small">Mieter-ID</label><input class="input" id="inspMieter"></div>
            <div class="col" style="min-width:160px"><label class="small">Einheit-ID</label><input class="input" id="inspEinheit"></div>
            <div class="col" style="min-width:220px"><label class="small">Label</label><input class="input" id="inspLabel"></div>
            <div class="col" style="flex:0 0 auto"><button class="btn" id="inspSaveAssign">Assign speichern</button></div>
          </div>
          <div class="row" style="gap:12px;margin-top:10px;align-items:end;flex-wrap:wrap">
            <div class="col" style="min-width:320px"><label class="small">Cover</label>
              <div class="input-group"><input class="input" id="inspCover"><button class="btn" id="inspPickCover">Wählen…</button><button class="btn primary" id="inspSaveCover">Setzen</button></div>
            </div>
            <div class="col" style="flex:0 0 auto"><img id="inspCoverImg" class="cover-thumb" alt="" style="display:none"/></div>
          </div>
        </div></div>

        <div class="selbar" id="selBar"><div class="wrap">
          <div class="count"><span id="selCount">0</span> ausgewählt</div>
          <div class="actions"><button class="btn sm" id="sbMove">Verschieben…</button><button class="btn sm danger" id="sbDelete">Löschen</button><button class="btn sm" id="sbUseForAssign">Pfad → Assign</button></div>
        </div></div>
      </div></div>

      <!-- Assign -->
      <div class="card tabpane" id="pane-assign"><div class="card-body">
        <div class="small text-muted mb-2">Ordner einer Einheit/Mieter zuordnen (oder JSON direkt speichern).</div>
        <div class="row" style="gap:12px;align-items:end;flex-wrap:wrap">
          <div class="col" style="min-width:260px"><label class="small">Ordner (relativ)</label><div class="input-group"><input class="input" id="assignPath"><button class="btn" id="btnAssignFromSel">Aus Auswahl</button></div></div>
          <div class="col" style="min-width:180px"><label class="small">Mieter-ID</label><input class="input" id="assignMieter"></div>
          <div class="col" style="min-width:180px"><label class="small">Einheit-ID</label><input class="input" id="assignEinheit"></div>
          <div class="col" style="min-width:220px"><label class="small">Label</label><input class="input" id="assignLabel"></div>
          <div class="col" style="flex:0 0 auto"><button class="btn" id="btnBuildJson">JSON bauen</button><button class="btn primary" id="btnAssignSave">Speichern</button></div>
        </div>
        <div class="row" style="gap:12px;margin-top:10px;flex-wrap:wrap">
          <div class="col" style="min-width:420px;flex:1"><label class="small">Assign-JSON (1:1)</label>
            <textarea class="input" id="assignJson" rows="4" placeholder='{"mieter_id":123,"einheit_id":5,"label":"…"}'></textarea>
          </div>
        </div>
      </div></div>

      <!-- Cover -->
      <div class="card tabpane" id="pane-cover"><div class="card-body">
        <div class="small text-muted mb-2">Bild als <b>Cover</b> setzen (aus <code>_preview/</code> im Ziel-Ordner).</div>
        <div class="row" style="gap:12px;align-items:end;flex-wrap:wrap">
          <div class="col" style="min-width:280px"><label class="small">Ordner</label><input class="input" id="coverPath" placeholder="…/Wohnung3/Mieter"></div>
          <div class="col" style="min-width:280px"><label class="small">Cover-Datei</label><div class="input-group"><input class="input" id="coverRel"><button class="btn" id="btnPickCover">Wählen…</button></div></div>
          <div class="col" style="flex:0 0 auto"><button class="btn primary" id="btnCoverSave">Cover setzen</button></div>
        </div>
      </div></div>

      <!-- Templates -->
      <div class="card tabpane" id="pane-templates"><div class="card-body"><p class="mb-2">MVP-Platzhalter für Vorlagen-Aktionen.</p></div></div>

      <!-- Locations -->
      <div class="card tabpane" id="pane-locations"><div class="card-body">
        <div class="mb-2">Root-Verzeichnis: <code><?php echo htmlspecialchars($root); ?></code> · Quelle: <?php echo htmlspecialchars($root_src); ?></div>
        <p class="small text-muted">Pfad wird wie in <code>project_storage.php</code> aus der DB gelesen (<code>projekte.root_path</code>), mit kompatiblen Fallbacks.</p>
      </div></div>

      <!-- Trash -->
      <div class="card tabpane" id="pane-trash"><div class="card-body">
        <table class="table" id="tblTrash"><thead class="sticky"><tr><th>Bucket</th><th>Original</th><th>Gelöscht von</th><th>Zeit</th><th>Inhalt</th><th>Aktionen</th></tr></thead><tbody></tbody></table>
      </div></div>

      <!-- Log -->
      <div class="card tabpane" id="pane-log"><div class="card-body">
        <table class="table" id="tblLog"><thead class="sticky"><tr><th>Zeit</th><th>User</th><th>Aktion</th><th>Details</th></tr></thead><tbody></tbody></table>
      </div></div>
    </div>
  </div>
</div>

<!-- Kontextmenü -->
<div class="ctx" id="ctx"><button data-cmd="open">Öffnen</button><button data-cmd="rename">Umbenennen</button><button data-cmd="move">Verschieben…</button><button data-cmd="delete" style="color:#b91c1c">Löschen</button></div>

<!-- Cover Picker -->
<div class="modal" id="coverPicker"><div class="sheet">
  <div class="head"><strong>Cover auswählen</strong><button class="btn" id="cpClose">Schliessen</button></div>
  <div class="body"><div class="small text-muted">Bilder aus <code>_preview/</code>.</div><div class="grid" id="cpGrid"></div></div>
  <div class="foot"><button class="btn" id="cpCancel">Abbrechen</button><button class="btn primary" id="cpUse" disabled>Auswählen</button></div>
</div></div>

<!-- Move Picker -->
<div class="modal" id="movePicker"><div class="sheet">
  <div class="head"><strong>Zielordner wählen</strong><button class="btn" id="mvClose">Schliessen</button></div>
  <div class="body"><div class="small text-muted">Klicke im Baum den Zielordner an.</div><div class="tree" id="moveTree" style="max-height:60vh;overflow:auto;border:1px solid var(--border);border-radius:12px;padding:8px;background:#fafbff"></div></div>
  <div class="foot"><button class="btn" id="mvCancel">Abbrechen</button><button class="btn primary" id="mvUse" disabled>Verschieben</button></div>
</div></div>

<script>
const QS=new URLSearchParams(location.search); const projekt_id=parseInt(QS.get('projekt_id')||'0',10)||0; let csrf=<?php echo json_encode($csrf_tok); ?>; const PREFIX=<?php echo json_encode($PREFIX); ?>;
function $all(sel,root){return Array.prototype.slice.call((root||document).querySelectorAll(sel));}
function apiURL(action,params={}){const u=new URL(location.href); u.searchParams.set('api','1'); u.searchParams.set('action',action); u.searchParams.set('projekt_id',projekt_id); if(params.path) u.searchParams.set('path',params.path); if(params.q) u.searchParams.set('q',params.q); if(params.max) u.searchParams.set('max',params.max); return u.toString();}
async function apiPost(action,body){const u=apiURL(action); const f=new FormData(); f.append('projekt_id',projekt_id); f.append('csrf_token',csrf); for(const[k,v] of Object.entries(body||{})) f.append(k,v); const r=await fetch(u,{method:'POST',body:f}); return await r.json();}
async function apiGet(action,params){const r=await fetch(apiURL(action,params)); return await r.json();}
function toast(msg,type='info',action=null){const el=document.createElement('div'); el.style.cssText='position:fixed;bottom:12px;right:12px;border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;z-index:9999;display:flex;gap:8px;align-items:center;background:'+(type==='danger'?'#fee2e2':type==='success'?'#dcfce7':'#e5e7eb'); const t=document.createElement('span'); t.textContent=msg; el.appendChild(t); if(action){const b=document.createElement('button'); b.className='btn sm'; b.textContent=action.label||'Aktion'; b.onclick=async()=>{el.remove(); await action.run();}; el.appendChild(b);} document.body.appendChild(el); setTimeout(()=>el.remove(),action?6000:2200);}
function showTab(name){$all('.tabpane').forEach(p=>p.classList.remove('active')); $all('.tab').forEach(a=>a.classList.remove('active')); document.getElementById('pane-'+name)?.classList.add('active'); document.querySelector('.tab[data-target="'+name+'"]')?.classList.add('active');}
window.addEventListener('hashchange',()=>showTab((location.hash||'#browse').substring(1))); document.getElementById('tabs').addEventListener('click',e=>{const t=e.target.closest('.tab'); if(!t) return; e.preventDefault(); const name=t.dataset.target; location.hash='#'+name; showTab(name);});

let currentItems=[]; let selection=new Set(); function setSelection(paths){selection=new Set(paths); renderSelectionStyles();} function toggleSelect(p){selection.has(p)?selection.delete(p):selection.add(p); renderSelectionStyles();} function clearSelection(){selection.clear(); renderSelectionStyles();}
function renderSelectionStyles(){ $all('.file-row').forEach(tr=>{tr.classList.toggle('selected',selection.has(tr.dataset.path)); const cb=tr.querySelector('input[type="checkbox"]'); if(cb) cb.checked=selection.has(tr.dataset.path);}); $all('#gridView .item').forEach(d=>d.classList.toggle('selected',selection.has(d.dataset.path))); const all=document.getElementById('chkAll'); if(all) all.checked=selection.size&&selection.size===currentItems.length; const bar=document.getElementById('selBar'); document.getElementById('selCount').textContent=String(selection.size||0); bar.style.display=selection.size?'block':'none';}

async function loadList(path){const q=document.getElementById('inpFilter').value.trim(); const js=await apiGet('list',{path,q}); if(!js.ok) return toast(js.msg||'Fehler','danger'); csrf=js.data.csrf||csrf; document.getElementById('cwdInfo').textContent=js.data.cwd||''; document.getElementById('inpPath').value=js.data.cwd||''; const bc=document.getElementById('breadcrumbs'); bc.innerHTML=''; const add=(n,r)=>{ if(bc.childNodes.length) bc.appendChild(document.createTextNode(' / ')); const a=document.createElement('a'); a.href='#'; a.textContent=n; a.onclick=e=>{e.preventDefault(); loadList(r);}; bc.appendChild(a);}; add('Root',''); (js.data.breadcrumbs||[]).forEach(cr=>add(cr.name,cr.rel));
  currentItems=(js.data.items||[]).sort((a,b)=>a.type===b.type?a.name.localeCompare(b.name):a.type==='dir'?-1:1); renderList(); renderGrid(); clearSelection(); hidePreview(); document.getElementById('inpFilter')?.focus();}
function openItem(it){ if(it.type==='dir'){ loadList(it.rel_path); document.getElementById('assignPath').value=it.rel_path; document.getElementById('coverPath').value=it.rel_path; showPreview(it); } else showPreview(it); }
function mkBtn(text,cls,fn){const b=document.createElement('button'); b.className='btn'+(cls?' '+cls:''); b.style.marginRight='6px'; b.textContent=text; b.onclick=fn; return b;}
function escapeHtml(s){return (s||'').replace(/[&<>"]/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]));}

function renderList(){const tb=document.querySelector('#listView tbody'); if(!tb) return; tb.innerHTML=''; if(!currentItems.length){const tr=document.createElement('tr'); tr.className='empty'; const td=document.createElement('td'); td.colSpan=8; td.textContent='Noch nichts hier? Dateien ziehen oder „+ Neuer Ordner“.'; tr.appendChild(td); tb.appendChild(tr);}
  currentItems.forEach(it=>{const tr=document.createElement('tr'); tr.className='file-row'; tr.dataset.path=it.rel_path; tr.draggable=true;
    tr.addEventListener('dragstart',ev=>ev.dataTransfer.setData('text/plain',it.rel_path));
    tr.addEventListener('dragover',ev=>{ev.preventDefault(); tr.classList.add('dragover');});
    tr.addEventListener('dragleave',()=>tr.classList.remove('dragover'));
    tr.addEventListener('drop',async ev=>{ev.preventDefault(); tr.classList.remove('dragover'); if(it.type!=='dir') return; const src=ev.dataTransfer.getData('text/plain'); if(!confirm(`Verschiebe\n${src}\n→\n${it.rel_path}?`)) return; const r=await apiPost('move',{path:src,to:it.rel_path}); if(!r.ok) return toast(r.msg||'Move fehlgeschlagen','danger'); loadList(document.getElementById('inpPath').value);});
    const td0=document.createElement('td'); const chk=document.createElement('input'); chk.type='checkbox'; chk.onclick=e=>{e.stopPropagation(); toggleSelect(it.rel_path)}; td0.appendChild(chk);
    const nameTd=document.createElement('td'); const name=document.createElement('span'); name.textContent=it.name; name.style.cursor='pointer'; name.ondblclick=()=>inlineRename(it); name.onclick=()=>openItem(it); nameTd.appendChild(name);
    const typeTd=document.createElement('td'); typeTd.textContent=(it.type==='dir'?'Ordner':'Datei');
    const sizeTd=document.createElement('td'); sizeTd.textContent=it.size??'';
    const mtTd=document.createElement('td'); mtTd.textContent=it.mtime??'';
    const asTd=document.createElement('td'); asTd.innerHTML=it.assign?`<code class="small">${escapeHtml(JSON.stringify(it.assign))}</code>`:'';
    const cvTd=document.createElement('td'); cvTd.innerHTML=it.thumb?`<img class="cover-thumb" src="${PREFIX}files/serve.php?projekt_id=${projekt_id}&rel=${encodeURIComponent(it.thumb)}">`:'';
    const actTd=document.createElement('td'); const bOpen=mkBtn('Öffnen','',()=>openItem(it)); const bRen=mkBtn('Umbenennen','',()=>inlineRename(it)); const bDel=mkBtn('Löschen','danger',()=>deleteOne(it)); actTd.append(bOpen,bRen,bDel);
    tr.append(td0,nameTd,typeTd,sizeTd,mtTd,asTd,cvTd,actTd);
    tr.addEventListener('click',e=>{if(!['INPUT','BUTTON','A'].includes(e.target.tagName)) toggleSelect(it.rel_path);});
    tr.addEventListener('contextmenu',e=>{e.preventDefault(); showCtx(e.pageX,e.pageY,it);});
    tb.appendChild(tr);
  });
  renderSelectionStyles();
}

function renderGrid(){const grid=document.getElementById('gridView'); if(!grid) return; grid.innerHTML=''; if(!currentItems.length){const d=document.createElement('div'); d.className='empty'; d.textContent='Kachelansicht: Perfekt für Cover.'; grid.appendChild(d);}
  currentItems.forEach(it=>{const div=document.createElement('div'); div.className='item'; div.dataset.path=it.rel_path; div.onclick=()=>toggleSelect(it.rel_path); div.addEventListener('dblclick',()=>openItem(it));
    const img=document.createElement('img'); img.className='thumb'; if(it.thumb) img.src=`${PREFIX}files/serve.php?projekt_id=${projekt_id}&rel=${encodeURIComponent(it.thumb)}`; else img.style.display='none';
    const title=document.createElement('div'); title.className='small'; title.textContent=it.name;
    const meta=document.createElement('div'); meta.className='text-muted small'; meta.textContent=(it.type==='dir'?'Ordner':'Datei')+(it.mtime?` · ${it.mtime}`:'');
    const actions=document.createElement('div'); actions.className='small'; const aOpen=mkBtn('Öffnen','sm',e=>{e.stopPropagation(); openItem(it);}); const aDel=mkBtn('Löschen','sm danger',e=>{e.stopPropagation(); deleteOne(it);}); actions.append(aOpen,aDel);
    div.append(img,title,meta,actions); grid.appendChild(div);
  });
  renderSelectionStyles();
}

async function inlineRename(it){const nn=prompt('Neuer Name',it.name); if(!nn) return; const r=await apiPost('rename',{path:it.rel_path,new_name:nn}); if(!r.ok) return toast(r.msg||'Rename fehlgeschlagen','danger'); toast('Umbenannt','success'); loadList(document.getElementById('inpPath').value);}
async function deleteOne(it){
  const t=prompt('Zum Bestätigen tippe LÖSCHEN'); if(t!=='LÖSCHEN') return;
  if(it.type==='dir'){const second=confirm('Dieser Ordner (inkl. Unterordner/Dateien) wird in den Papierkorb verschoben. Sicher?'); if(!second) return;}
  const r=await apiPost('delete_soft',{path:it.rel_path}); if(!r.ok) return toast(r.msg||'Löschen fehlgeschlagen','danger');
  toast('In Papierkorb verschoben','info',{label:'Rückgängig',run:async()=>{const u=await apiPost('undo_delete',{}); if(!u.ok) return toast(u.msg||'Undo fehlgeschlagen','danger'); await loadList(document.getElementById('inpPath').value); await loadTrash(); toast('Wiederhergestellt','success'); }});
  await loadList(document.getElementById('inpPath').value); await loadTrash();
}
function hidePreview(){const p=document.getElementById('previewPanel'); if(p) p.style.display='none';}
function showPreview(it){const pnl=document.getElementById('previewPanel'); pnl.style.display='block'; document.getElementById('pvTitle').textContent=it.name; document.getElementById('pvMeta').textContent=(it.type==='dir'?'Ordner':'Datei')+(it.mtime?` · ${it.mtime}`:''); const body=document.getElementById('pvBody'); body.innerHTML='';
  if(it.type==='file'){const ext=it.name.split('.').pop().toLowerCase(); if(['jpg','jpeg','png','gif','webp'].includes(ext)){const im=new Image(); im.src=`${PREFIX}files/serve.php?projekt_id=${projekt_id}&rel=${encodeURIComponent(it.rel_path)}`; im.style.maxWidth='100%'; im.style.borderRadius='8px'; body.appendChild(im);} else if(ext==='pdf'){const ifr=document.createElement('iframe'); ifr.src=`${PREFIX}files/serve.php?projekt_id=${projekt_id}&rel=${encodeURIComponent(it.rel_path)}`; ifr.style.cssText='width:100%;height:520px;border:1px solid #e5e7eb;border-radius:8px'; body.appendChild(ifr);} else body.innerHTML='<div class="text-muted">Keine Vorschau verfügbar.</div>'; }
  else { body.innerHTML='<div class="text-muted">Ordner ausgewählt.</div>'; }
  const insp=document.getElementById('inspector');
  if(it.type==='dir'){ insp.style.display='block'; document.getElementById('inspPath').value=it.rel_path; document.getElementById('inspMieter').value=(it.assign&&it.assign.mieter_id)?it.assign.mieter_id:''; document.getElementById('inspEinheit').value=(it.assign&&it.assign.einheit_id)?it.assign.einheit_id:''; document.getElementById('inspLabel').value=(it.assign&&it.assign.label)?it.assign.label:''; document.getElementById('inspCover').value=it.cover||''; const im=document.getElementById('inspCoverImg'); if(it.thumb){im.src=`${PREFIX}files/serve.php?projekt_id=${projekt_id}&rel=${encodeURIComponent(it.thumb)}`; im.style.display='';} else im.style.display='none'; }
  else insp.style.display='none';
}

async function bulkDelete(){ if(!selection.size) return toast('Nichts ausgewählt','info'); const t=prompt(`Du löschst ${selection.size} Element(e). Tippe LÖSCHEN`); if(t!=='LÖSCHEN') return; const second=confirm('Mehrere Elemente in den Papierkorb verschieben. Sicher?'); if(!second) return; for(const p of selection){const r=await apiPost('delete_soft',{path:p}); if(!r.ok) return toast(r.msg||'Löschen fehlgeschlagen','danger');}
  await loadList(document.getElementById('inpPath').value); await loadTrash();
  toast('In Papierkorb verschoben','info',{label:'Rückgängig',run:async()=>{const u=await apiPost('undo_delete',{}); if(!u.ok) return toast(u.msg||'Undo fehlgeschlagen','danger'); await loadList(document.getElementById('inpPath').value); await loadTrash(); toast('Wiederhergestellt','success'); }});
}
async function bulkMove(){ if(!selection.size) return toast('Nichts ausgewählt','info'); openMovePicker(); }

// Selection-Bar
document.getElementById('sbDelete').onclick=bulkDelete; document.getElementById('sbMove').onclick=bulkMove;
document.getElementById('sbUseForAssign').onclick=()=>{const first=Array.from(selection)[0]; if(!first) return toast('Keine Auswahl','info'); const it=currentItems.find(x=>x.rel_path===first); const rel=it?(it.type==='dir'?it.rel_path:it.rel_path.split('/').slice(0,-1).join('/')):first; const inp=document.getElementById('assignPath'); if(inp) inp.value=rel; location.hash='#assign'; showTab('assign'); toast('Pfad ins Assign-Formular übernommen','success');};

// Assign-Form
function buildAssignJson(){const o={}; const m=document.getElementById('assignMieter').value.trim(); const e=document.getElementById('assignEinheit').value.trim(); const l=document.getElementById('assignLabel').value.trim(); if(m) o.mieter_id=isNaN(+m)?m:+m; if(e) o.einheit_id=isNaN(+e)?e:+e; if(l) o.label=l; return o;}
document.getElementById('btnBuildJson').onclick=()=>{const o=buildAssignJson(); const ta=document.getElementById('assignJson'); ta.value=Object.keys(o).length?JSON.stringify(o):'{}'; toast('JSON aktualisiert','info');};
document.getElementById('btnAssignFromSel').onclick=()=>{const first=Array.from(selection)[0]; if(!first) return toast('Nichts ausgewählt','info'); const it=currentItems.find(x=>x.rel_path===first); const rel=it?(it.type==='dir'?it.rel_path:it.rel_path.split('/').slice(0,-1).join('/')):first; document.getElementById('assignPath').value=rel; toast('Pfad übernommen','success');};
document.getElementById('btnAssignSave').onclick=async()=>{const path=document.getElementById('assignPath').value.trim(); if(!path) return toast('Ordnerpfad fehlt','danger'); let json=document.getElementById('assignJson').value.trim(); if(!json){const o=buildAssignJson(); json=Object.keys(o).length?JSON.stringify(o):'{}';} const r=await apiPost('assign',{path,assign_json:json}); if(!r.ok) return toast(r.msg||'Assign fehlgeschlagen','danger'); toast('Zuweisung gespeichert','success'); loadList(document.getElementById('inpPath').value);};

// Cover Picker
(function(){const modal=document.getElementById('coverPicker'),grid=document.getElementById('cpGrid'),btnUse=document.getElementById('cpUse'); const close=()=>{modal.style.display='none'; grid.innerHTML=''; btnUse.disabled=true; modal.dataset.choice='';}; document.getElementById('cpClose').onclick=close; document.getElementById('cpCancel').onclick=close;
document.getElementById('btnPickCover').onclick=async()=>{const path=document.getElementById('coverPath').value.trim()||document.getElementById('inspPath').value.trim(); if(!path) return toast('Ordnerpfad wählen','info'); const js=await apiGet('cover_candidates',{path}); if(!js.ok) return toast(js.msg||'Fehler','danger'); const files=js.data.items||[]; if(!files.length) return toast('Keine Bilder in _preview/ gefunden','info'); grid.innerHTML=''; files.forEach(rel=>{const card=document.createElement('div'); card.className='grid item'; card.style.cursor='pointer'; card.innerHTML=`<img class="thumb" src="${PREFIX}files/serve.php?projekt_id=${projekt_id}&rel=${encodeURIComponent(rel)}"><div class="small" style="margin-top:6px">${rel.split('/').pop()}</div>`; card.onclick=()=>{$all('.grid .item',grid).forEach(o=>o.style.outline=''); card.style.outline='2px solid var(--acc)'; modal.dataset.choice=rel; btnUse.disabled=false;}; grid.appendChild(card);}); modal.style.display='flex';};
btnUse.onclick=()=>{const rel=modal.dataset.choice||''; if(!rel) return; const cov=document.getElementById('coverRel'); if(cov) cov.value=rel; const ic=document.getElementById('inspCover'); if(ic) ic.value=rel; toast('Cover-Datei übernommen','success'); modal.style.display='none';};
})();

// Inspector Aktionen
document.getElementById('inspSaveAssign').onclick=async()=>{const path=document.getElementById('inspPath').value.trim(); const o={}; const m=document.getElementById('inspMieter').value.trim(); const e=document.getElementById('inspEinheit').value.trim(); const l=document.getElementById('inspLabel').value.trim(); if(m) o.mieter_id=isNaN(+m)?m:+m; if(e) o.einheit_id=isNaN(+e)?e:+e; if(l) o.label=l; const r=await apiPost('assign',{path,assign_json:JSON.stringify(o)}); if(!r.ok) return toast(r.msg||'Assign fehlgeschlagen','danger'); toast('Assign gespeichert','success'); loadList(document.getElementById('inpPath').value);};
document.getElementById('inspPickCover').onclick=()=>document.getElementById('btnPickCover').click();
document.getElementById('inspSaveCover').onclick=async()=>{const path=document.getElementById('inspPath').value.trim(); const cover_rel=document.getElementById('inspCover').value.trim(); const r=await apiPost('cover_select',{path,cover_rel}); if(!r.ok) return toast(r.msg||'Cover fehlgeschlagen','danger'); toast('Cover gesetzt','success'); loadList(document.getElementById('inpPath').value);};

// Kontextmenü
const ctx=document.getElementById('ctx'); let ctxItem=null; function showCtx(x,y,it){ctxItem=it; ctx.style.left=x+'px'; ctx.style.top=y+'px'; ctx.style.display='block';}
window.addEventListener('click',()=>ctx.style.display='none');
ctx.addEventListener('click',async e=>{const cmd=e.target.getAttribute('data-cmd'); if(!cmd||!ctxItem) return; if(cmd==='open') openItem(ctxItem); if(cmd==='rename') inlineRename(ctxItem); if(cmd==='move'){setSelection([ctxItem.rel_path]); openMovePicker();} if(cmd==='delete') deleteOne(ctxItem); ctx.style.display='none';});

// Sidebar/Toolbar
document.getElementById('btnGo').onclick=()=>loadList(document.getElementById('inpPath').value);
document.getElementById('btnRefresh').onclick=()=>loadList(document.getElementById('inpPath').value);
document.getElementById('btnMkdir').onclick=async()=>{const name=prompt('Ordnername'); if(!name) return; const p=document.getElementById('inpPath').value; const r=await apiPost('mkdir',{path:p,name}); if(!r.ok) return toast(r.msg||'mkdir fehlgeschlagen','danger'); toast('Ordner erstellt','success'); loadList(p);};
document.getElementById('inpFilter').addEventListener('input',()=>loadList(document.getElementById('inpPath').value));
document.getElementById('btnBulkDelete').onclick=bulkDelete; document.getElementById('btnBulkMove').onclick=bulkMove;
document.getElementById('btnList').onclick=()=>{document.getElementById('listView').style.display=''; document.getElementById('gridView').style.display='none';};
document.getElementById('btnGrid').onclick=()=>{document.getElementById('listView').style.display='none'; document.getElementById('gridView').style.display='';};
document.getElementById('btnCoverSave').onclick=async()=>{const path=document.getElementById('coverPath').value.trim(); const cover_rel=document.getElementById('coverRel').value.trim(); const r=await apiPost('cover_select',{path,cover_rel}); if(!r.ok) return toast(r.msg||'Cover fehlgeschlagen','danger'); toast('Cover gesetzt','success'); loadList(document.getElementById('inpPath').value);};
document.getElementById('btnUp').onclick=()=>{const cur=document.getElementById('inpPath').value.trim(); const parent=cur.split('/').slice(0,-1).join('/'); loadList(parent);};

// Drag&Drop Upload (auf Body des Browse-Panels)
(function(){const pane=document.querySelector('#pane-browse .card-body'); if(!pane) return; pane.addEventListener('dragover',e=>{e.preventDefault(); pane.style.outline='2px dashed var(--acc)';}); pane.addEventListener('dragleave',()=>{pane.style.outline='';}); pane.addEventListener('drop',async e=>{e.preventDefault(); pane.style.outline=''; const files=[...e.dataTransfer.files]; if(!files.length) return; const cwd=document.getElementById('inpPath').value.trim(); const u=apiURL('upload',{path:cwd}); const f=new FormData(); f.append('projekt_id',projekt_id); f.append('csrf_token',csrf); files.forEach(x=>f.append('files[]',x)); const r=await fetch(u,{method:'POST',body:f}); const js=await r.json(); if(!js.ok) return toast(js.msg||'Upload fehlgeschlagen','danger'); toast('Upload ok','success'); loadList(cwd);});})();

// Tree
async function fetchDirs(rel){const js=await apiGet('list',{path:rel||''}); if(!js.ok) {toast(js.msg||'Fehler','danger'); return [];} return (js.data.items||[]).filter(x=>x.type==='dir');}
function iconFolder(){return '📁';} function caret(open){return open?'▾':'▸';}
function nodeTemplate(rel,name){return `<div class="node" data-rel="${rel}"><div class="tw"><span class="caret" style="width:16px;text-align:center;cursor:pointer;color:#64748b">${caret(false)}</span><span class="folder" style="cursor:pointer">${iconFolder()} ${escapeHtml(name)}</span><div class="actions" style="margin-left:auto;display:none;gap:6px"><button class="btn-icon" data-a="new" title="Neu">＋</button><button class="btn-icon" data-a="ren" title="Umbenennen">✎</button><button class="btn-icon" data-a="del" title="Löschen">🗑</button></div></div><div class="children" style="margin-left:18px;display:none"></div></div>`;}
async function expandNode(div){ if(div.classList.contains('expanded')){div.classList.remove('expanded'); div.querySelector('.children').style.display='none'; div.querySelector('.caret').textContent=caret(false); return;} const rel=div.dataset.rel||''; const ch=div.querySelector('.children'); if(!ch.dataset.loaded){ch.innerHTML=''; const dirs=await fetchDirs(rel); dirs.forEach(d=>{const n=document.createElement('div'); n.innerHTML=nodeTemplate(d.rel_path,d.name); ch.appendChild(n.firstChild);}); ch.dataset.loaded='1';} div.classList.add('expanded'); ch.style.display='block'; div.querySelector('.caret').textContent=caret(true); highlightTreeCwd();}
function highlightTreeCwd(){const cwd=document.getElementById('inpPath').value.trim(); $all('#tree .node').forEach(n=>n.classList.toggle('active',(n.dataset.rel||'')===cwd));}
async function buildTree(){const tree=document.getElementById('tree'); tree.innerHTML=''; const root=document.createElement('div'); root.innerHTML=nodeTemplate('','Root'); tree.appendChild(root.firstChild); const rn=tree.querySelector('.node'); rn.classList.add('expanded'); rn.querySelector('.caret').textContent=caret(true); const dirs=await fetchDirs(''); const ch=rn.querySelector('.children'); dirs.forEach(d=>{const n=document.createElement('div'); n.innerHTML=nodeTemplate(d.rel_path,d.name); ch.appendChild(n.firstChild);}); ch.dataset.loaded='1'; highlightTreeCwd();}
document.getElementById('tree').addEventListener('click',async e=>{const node=e.target.closest('.node'); if(!node) return; if(e.target.classList.contains('caret')) return expandNode(node); if(e.target.classList.contains('folder')){const rel=node.dataset.rel||''; document.getElementById('inpPath').value=rel; await loadList(rel); highlightTreeCwd(); return;} const a=e.target.closest('[data-a]'); if(a){const rel=node.dataset.rel||''; if(a.dataset.a==='new'){const name=prompt('Unterordnername'); if(!name) return; const r=await apiPost('mkdir',{path:rel,name}); if(!r.ok) return toast(r.msg||'mkdir fehlgeschlagen','danger'); toast('Ordner erstellt','success'); await loadList(rel); await buildTree();} if(a.dataset.a==='ren'){if(!rel) return; const cur=rel.split('/').pop()||'Root'; const nn=prompt('Neuer Name',cur); if(!nn) return; const r=await apiPost('rename',{path:rel,new_name:nn}); if(!r.ok) return toast(r.msg||'Rename fehlgeschlagen','danger'); toast('Umbenannt','success'); const parent=rel.split('/').slice(0,-1).join('/'); await loadList(parent||''); await buildTree();} if(a.dataset.a==='del'){if(!rel) return; const conf=prompt('Zum Löschen tippe LÖSCHEN'); if(conf!=='LÖSCHEN') return; const second=confirm('Ordner (inkl. Unterordner) in den Papierkorb. Sicher?'); if(!second) return; const r=await apiPost('delete_soft',{path:rel}); if(!r.ok) return toast(r.msg||'Löschen fehlgeschlagen','danger'); toast('In Papierkorb verschoben','info'); const parent=rel.split('/').slice(0,-1).join('/'); await loadList(parent); await buildTree();}}});

// Tree DnD (Liste -> Tree)
const treeEl=document.getElementById('tree'); treeEl.addEventListener('dragover',e=>{const n=e.target.closest('.node'); if(!n) return; e.preventDefault(); n.classList.add('active');}); treeEl.addEventListener('dragleave',e=>{const n=e.target.closest('.node'); if(n) n.classList.remove('active');}); treeEl.addEventListener('drop',async e=>{const n=e.target.closest('.node'); if(!n) return; e.preventDefault(); n.classList.remove('active'); const dst=n.dataset.rel||''; const src=e.dataTransfer.getData('text/plain'); if(!src) return; if(!confirm(`Verschiebe\n${src}\n→\n${dst||'Root'}?`)) return; const r=await apiPost('move',{path:src,to:dst}); if(!r.ok) return toast(r.msg||'Move fehlgeschlagen','danger'); await loadList(dst); await buildTree();});

// Expand/Collapse all
document.getElementById('btnExpandAll').onclick=()=>{$all('#tree .node').forEach(n=>{n.classList.add('expanded'); const c=n.querySelector('.children'); if(c) c.style.display='block'; const car=n.querySelector('.caret'); if(car) car.textContent=caret(true);});};
document.getElementById('btnCollapseAll').onclick=()=>{$all('#tree .node').forEach(n=>{n.classList.remove('expanded'); const c=n.querySelector('.children'); if(c) c.style.display='none'; const car=n.querySelector('.caret'); if(car) car.textContent=caret(false);}); const root=document.querySelector('#tree .node'); if(root){root.classList.add('expanded'); const c=root.querySelector('.children'); if(c) c.style.display='block'; const car=root.querySelector('.caret'); if(car) car.textContent=caret(true);}};

// Global Search
document.getElementById('btnGlobalSearch').onclick=async()=>{const q=document.getElementById('inpGlobalSearch').value.trim(); if(!q) return toast('Bitte Suchbegriff eingeben'); const base=document.getElementById('inpPath').value.trim(); const js=await apiGet('search',{q,path:base,max:300}); if(!js.ok) return toast(js.msg||'Suche fehlgeschlagen','danger'); currentItems=js.data.items||[]; renderList(); renderGrid(); clearSelection(); hidePreview(); toast(`Suche: ${q} · ${currentItems.length} Treffer`,'info');};
document.getElementById('inpGlobalSearch').addEventListener('keydown',e=>{if(e.key==='Enter') document.getElementById('btnGlobalSearch').click();});

// Move Picker
async function buildMoveTree(){const host=document.getElementById('moveTree'); host.innerHTML=''; const root=document.createElement('div'); root.innerHTML=nodeTemplate('','Root'); host.appendChild(root.firstChild); const rn=host.querySelector('.node'); rn.classList.add('expanded'); rn.querySelector('.caret').textContent=caret(true); const dirs=await fetchDirs(''); const ch=rn.querySelector('.children'); dirs.forEach(d=>{const n=document.createElement('div'); n.innerHTML=nodeTemplate(d.rel_path,d.name); ch.appendChild(n.firstChild);}); ch.dataset.loaded='1';}
function openMovePicker(){(async()=>{await buildMoveTree(); document.getElementById('mvUse').disabled=true; document.getElementById('movePicker').style.display='flex';})();}
(function(){const modal=document.getElementById('movePicker'); const btnUse=document.getElementById('mvUse'); let dstRel=''; const close=()=>{modal.style.display='none';}; document.getElementById('mvClose').onclick=close; document.getElementById('mvCancel').onclick=close;
document.getElementById('moveTree').addEventListener('click',async e=>{const node=e.target.closest('.node'); if(!node) return; if(e.target.classList.contains('caret')) return expandNode(node); if(e.target.classList.contains('folder')){dstRel=node.dataset.rel||''; $all('#moveTree .node').forEach(n=>n.classList.remove('active')); node.classList.add('active'); btnUse.disabled=false;}});
btnUse.onclick=async()=>{modal.style.display='none'; if(!dstRel) return; if(!selection.size) return toast('Keine Auswahl','info'); for(const p of selection){const r=await apiPost('move',{path:p,to:dstRel}); if(!r.ok) return toast(r.msg||'Move fehlgeschlagen','danger');} await loadList(dstRel); await buildTree(); toast('Verschoben','success');};
document.getElementById('btnBulkMove').onclick=()=>selection.size?openMovePicker():toast('Nichts ausgewählt','info');
document.getElementById('sbMove').onclick=()=>selection.size?openMovePicker():toast('Nichts ausgewählt','info');
ctx.addEventListener('click',e=>{if(e.target.getAttribute('data-cmd')==='move'){if(ctxItem) setSelection([ctxItem.rel_path]); openMovePicker();}});
})();

// Trash & Log
async function loadTrash(){const js=await apiGet('trash_list',{}); const tb=document.querySelector('#tblTrash tbody'); if(!tb) return; tb.innerHTML=''; (js.data.items||[]).forEach(row=>{const tr=document.createElement('tr'); const actions=document.createElement('div'); const bRes=mkBtn('Wiederherstellen','sm',async()=>{const r=await apiPost('restore',{bucket:row.bucket}); if(!r.ok) return toast(r.msg||'Restore fehlgeschlagen','danger'); toast('Wiederhergestellt','success'); loadTrash(); loadList(document.getElementById('inpPath').value);}); const bDel=mkBtn('Endgültig löschen','sm danger',async()=>{if(!confirm('Endgültig löschen? Nur Superadmin.')) return; const r=await apiPost('delete_hard',{bucket:row.bucket}); if(!r.ok) return toast(r.msg||'Delete fehlgeschlagen','danger'); toast('Gelöscht','success'); loadTrash();}); actions.append(bRes,bDel); tr.innerHTML=`<td>${row.bucket}</td><td>${row.orig_rel||''}</td><td>${row.deleted_by||''}</td><td>${row.deleted_at||''}</td><td>${(row.contents||[]).join(', ')}</td>`; const td=document.createElement('td'); td.append(actions); tr.append(td); tb.appendChild(tr);});}
async function loadLog(){const js=await apiGet('logs',{}); const tb=document.querySelector('#tblLog tbody'); if(!tb) return; tb.innerHTML=''; (js.data.items||[]).forEach(l=>{const tr=document.createElement('tr'); const det=document.createElement('td'); det.textContent=JSON.stringify(l.payload||{}); tr.innerHTML=`<td>${l.ts||''}</td><td>${l.user||''}</td><td>${l.action||''}</td>`; tr.append(det); tb.appendChild(tr);});}

// Shortcuts
document.addEventListener('keydown',async e=>{ if(e.key==='N'||e.key==='n'){e.preventDefault(); document.getElementById('btnMkdir').click();} if(e.key==='F2'){e.preventDefault(); const first=selection.values().next().value; if(!first) return; const it=currentItems.find(x=>x.rel_path===first); if(it) inlineRename(it);} if(e.key==='Delete'){e.preventDefault(); bulkDelete();}});
document.getElementById('inpPath').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault(); document.getElementById('btnGo').click();}});

// Tabs (kleine Buttons oben)
$all('.tab').forEach(b=>b.addEventListener('click',()=>showTab(b.dataset.target)));

// Checkbox alle
document.getElementById('chkAll')?.addEventListener('change',e=>{e.target.checked?setSelection(currentItems.map(x=>x.rel_path)):clearSelection();});

// Init
(async function(){const h=(location.hash||'#browse').substring(1); showTab(h); await loadList(<?php echo json_encode($path_init); ?>); await loadTrash(); await loadLog(); buildTree();})();
</script>
</body></html>
