<?php
// pages/pendenz_public.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$fmtDate=function($d){ if(!$d) return ''; $ts=strtotime($d); return $ts?date('d.m.Y',$ts):$d; };

$token = $_GET['t'] ?? '';
if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) { http_response_code(400); exit('Invalid token'); }

$q = $mysqli->prepare("
  SELECT p.*, pr.name AS projekt_name, w.name AS wohnung_name
  FROM pendenzen p
  LEFT JOIN projekte pr ON p.projekt_id=pr.id
  LEFT JOIN wohnungen w ON w.id=p.wohnung_id
  WHERE p.public_enabled=1 AND p.public_token=? LIMIT 1
");
$q->bind_param("s",$token); $q->execute(); $res=$q->get_result();
$p=$res?$res->fetch_assoc():null;
if(!$p){ http_response_code(404); exit('Not found'); }

// --- EINSTELLUNGEN LADEN ---
$cfg = json_decode($p['extra_json'] ?? 'null', true) ?: [];
$view = $cfg['_view'] ?? [];
$view += [
  'layout'=>'classic','font_size'=>'normal','brand_source'=>'firma_logo','brand_from'=>'ersteller',
  'hero'=>'cover','sections'=>['meta','kurzbeschreibung','langbeschreibung','notiz','anhaenge'],
  'meta_fields'=>['status','wichtigkeit','startdatum','enddatum','projekt_name','zustaendig_name'],
  'meta_order'=>['status','wichtigkeit','startdatum','enddatum','projekt_name','zustaendig_name'],
  'sections_order'=>['meta','kurzbeschreibung','langbeschreibung','notiz','anhaenge']
];

// --- Unternehmer-Rückmeldung verarbeiten ---
$flash="";
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='confirm_done') {
  $now = date('Y-m-d H:i:s');
  $status = 'Unt. Erledigt.'; 
  $bemerkung = $_POST['unt_bemerkung'] ?? '';
  
  $uploadMap = [
    'unt_img1' => ['type'=>'image', 'title'=>'Unternehmer Bild 1'],
    'unt_img2' => ['type'=>'image', 'title'=>'Unternehmer Bild 2'],
    'unt_pdf'  => ['type'=>'file',  'title'=>'Unternehmer Dokument']
  ];

  foreach ($uploadMap as $key => $info) {
    if (!empty($_FILES[$key]['name'])) {
        $f = $_FILES[$key];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ($info['type']==='image') ? ['jpg','jpeg'] : ['pdf'];
        
        if (in_array($ext, $allowed, true)) {
           // Altes Bild/Dokument in diesem Slot löschen
           $old = $mysqli->prepare("SELECT id, pfad FROM pendenz_dateien WHERE pendenz_id=? AND titel=?");
           $old->bind_param("is", $p['id'], $info['title']);
           $old->execute(); $oldRes = $old->get_result()->fetch_assoc();
           if ($oldRes) {
               $oldPath = __DIR__ . '/../' . ltrim($oldRes['pfad'],'/');
               if (is_file($oldPath)) @unlink($oldPath);
               $mysqli->query("DELETE FROM pendenz_dateien WHERE id=" . (int)$oldRes['id']);
           }

           $destDir = __DIR__ . '/../uploads/pendenzen/' . (int)$p['id'] . '/unternehmer';
           if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
           $filename = 'unt_' . $key . '_' . uniqid() . '.' . $ext;
           $destPath = $destDir . '/' . $filename;
           
           if (move_uploaded_file($f['tmp_name'], $destPath)) {
              // --- Nur Stempel unten links (für Bilder) ---
              if ($info['type']==='image' && function_exists('imagecreatefromjpeg')) {
                  $img = @imagecreatefromjpeg($destPath);
                  if ($img) {
                      $w=imagesx($img); $h=imagesy($img);
                      $white=imagecolorallocate($img, 255, 255, 255);
                      $bg=imagecolorallocatealpha($img, 0, 0, 0, 60);
                      $text="Unternehmer Bild"; $font=5; $pad=10;
                      $tw=imagefontwidth($font)*strlen($text); $th=imagefontheight($font);
                      imagefilledrectangle($img, 0, $h - $th - $pad*2, $tw + $pad*2, $h, $bg);
                      imagestring($img, $font, $pad, $h - $th - $pad, $text, $white);
                      imagejpeg($img, $destPath, 90); imagedestroy($img);
                  }
              }
              $dbPath = 'uploads/pendenzen/' . (int)$p['id'] . '/unternehmer/' . $filename;
              $stFile = $mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel) VALUES (?, ?, ?, ?, ?, ?)");
              $mime = ($ext==='pdf') ? 'application/pdf' : 'image/jpeg';
              $stFile->bind_param("isssis", $p['id'], $info['type'], $dbPath, $mime, $f['size'], $info['title']);
              $stFile->execute();
           }
        }
    }
  }

  $st=$mysqli->prepare("UPDATE pendenzen SET confirmation_at=?, status=?, unt_bemerkung=?, unt_new_input=1 WHERE id=?");
  $st->bind_param("sssi",$now, $status, $bemerkung, $p['id']); 
  $st->execute();
  $flash="✅ Rückmeldung gespeichert.";
  header("Location: pendenz_public.php?t=".rawurlencode($token)); exit;
}

// Medien
$imgs = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND typ='image' ORDER BY COALESCE(is_cover,0) DESC, CASE WHEN titel='Plan-Ausschnitt' THEN 1 ELSE 0 END ASC, id ASC");
$files= $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND typ<>'image' ORDER BY sort_index IS NULL, sort_index, id");
$cover = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND is_cover=1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$firstImg = ($imgs && $imgs->num_rows) ? $imgs->fetch_assoc() : null; if($imgs) $imgs->data_seek(0);
$heroImg = ($view['hero']==='first') ? ($firstImg ?: $cover) : ($cover ?: $firstImg);

// Branding
$createdUser = get_user_with_company($mysqli, (int)($p['erstellt_von']??0));
$assigneeUser = get_user_with_company($mysqli, (int)($p['zustaendig_id']??0));
$brandUser = ($view['brand_from']==='zustaendiger' ? $assigneeUser : $createdUser) ?: $createdUser;
$brandPath = $brandUser['firmenlogo'] ?? null;

// Details Logic
$labels = ['status'=>'Status','wichtigkeit'=>'Wichtigkeit','startdatum'=>'Startdatum','enddatum'=>'Enddatum','projekt_name'=>'Projekt','zustaendig_name'=>'Zuständig','erstellt_von_name'=>'Erstellt von'];
$values = ['status'=>$p['status'],'wichtigkeit'=>$p['wichtigkeit'],'startdatum'=>$fmtDate($p['startdatum']),'enddatum'=>$fmtDate($p['enddatum']),'projekt_name'=>$p['projekt_name'],'zustaendig_name'=>$assigneeUser['name']??'','erstellt_von_name'=>$createdUser['name']??''];

// Bestehende Unternehmer-Dateien finden
$untFiles = [];
$res = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND titel LIKE 'Unternehmer %'");
while($row=$res->fetch_assoc()) $untFiles[$row['titel']] = $row;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= h($p['titel']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; background:#f8fafc; margin:0; color:#1e293b; line-height:1.5; }
    .container { max-width:900px; margin:0 auto; padding:20px; }
    .card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
    .hero-teal { background:#10b981; color:white; border-radius:10px; padding:25px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; }
    .hero-teal h1 { margin:0; color:white; font-size:24px; }
    .btn { display:inline-block; background:#10b981; color:white; padding:10px 20px; border-radius:8px; text-decoration:none; border:0; cursor:pointer; font-weight:bold; }
    .meta-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
    .meta-item { padding:10px; border:1px solid #f1f5f9; border-radius:8px; background:#fff; }
    .hero-img img { width:100%; height:380px; object-fit:cover; border-radius:10px; cursor:pointer; border:1px solid #ddd; }
    .thumbs { display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:10px; margin-top:10px; }
    .thumbs img { width:100%; height:80px; object-fit:cover; border-radius:6px; cursor:pointer; border:1px solid #eee; }
    .upload-row { display:flex; flex-direction:column; gap:8px; margin-bottom:15px; padding:10px; background:#fff; border-radius:8px; border:1px solid #e2e8f0; }
    .upload-row label { font-weight:bold; font-size:14px; color:#475569; }
    .upload-row input { font-size:13px; }
    .existing-file { font-size:12px; color:#10b981; display:flex; align-items:center; gap:5px; margin-top:4px; }
    @media (max-width:600px){ .hero-teal { flex-direction:column; align-items:flex-start; gap:10px; } .meta-grid { grid-template-columns:1fr; } }
    /* Lightbox */
    #lb-overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.95); display:none; z-index:10000; flex-direction:column; align-items:center; justify-content:center; }
    #lb-img { max-width:95%; max-height:85%; object-fit:contain; }
    .lb-close { position:absolute; top:20px; right:20px; color:#fff; font-size:40px; cursor:pointer; }
    .lb-nav { position:absolute; top:50%; width:100%; display:flex; justify-content:space-between; padding:0 20px; transform:translateY(-50%); }
    .lb-btn { background:rgba(255,255,255,0.1); color:#fff; width:50px; height:50px; display:flex; align-items:center; justify-content:center; border-radius:50%; cursor:pointer; font-size:24px; }
  </style>
</head>
<body>
<div class="container" style="<?= $view['font_size']==='small'?'font-size:13px;':($view['font_size']==='large'?'font-size:17px;':'') ?>">
  <div class="card" style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #10b981; padding:15px;">
    <div><?php if($brandUser): ?><strong style="font-size:18px;"><?= h($brandUser['firma_name']??'') ?></strong><br><small><?= h($brandUser['firma_email']??'') ?></small><?php endif; ?></div>
    <?php if($brandPath): ?><img src="<?= h('../'.ltrim($brandPath,'/')) ?>" style="max-width:180px; max-height:60px; object-fit:contain;"><?php endif; ?>
  </div>
  <header class="hero-teal">
    <div><h1><?= h($p['titel']) ?></h1><div style="opacity:0.9">Projekt: <strong><?= h($p['projekt_name']) ?></strong> • Status: <strong><?= h($p['status']) ?></strong></div></div>
    <a href="pendenz_pdf.php?id=<?= $p['id'] ?>&t=<?= $token ?>" class="btn" style="background:rgba(255,255,255,0.2);">📄 PDF</a>
  </header>
  <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c; background:#f0fdf4;"><?= h($flash) ?></div><?php endif; ?>
  <div class="card" style="padding:0; overflow:hidden;">
    <div class="hero-img" onclick="openLightbox(0)"><?php if($heroImg): ?><img src="<?= h('../'.ltrim($heroImg['pfad'],'/')) ?>" class="gallery-img"><?php else: ?><div style="height:300px; display:flex; align-items:center; justify-content:center; color:#94a3b8;">Kein Bild vorhanden</div><?php endif; ?></div>
    <?php if($imgs->num_rows > 1): ?><div class="thumbs" style="padding:10px; background:#f8fafc;"><?php $idx=0; while($i=$imgs->fetch_assoc()): if($heroImg && $i['id']==$heroImg['id']) continue; ?><img src="<?= h('../'.ltrim($i['pfad'],'/')) ?>" onclick="openLightbox(<?= ++$idx ?>)" class="gallery-img"><?php endwhile; $imgs->data_seek(0); ?></div><?php endif; ?>
  </div>
  <?php foreach($view['sections_order'] as $sec): if(!in_array($sec,$view['sections'])) continue; ?>
    <?php if($sec==='meta'): ?>
      <div class="card"><h3>Details</h3><div class="meta-grid" style="<?= $view['layout']==='compact'?'grid-template-columns:1fr;':'' ?>"><?php foreach($view['meta_order'] as $f): if(!in_array($f,$view['meta_fields'])) continue; ?><div class="meta-item"><small style="color:#64748b; font-size:10px; font-weight:bold; text-transform:uppercase;"><?= h($labels[$f]??$f) ?></small><br><strong><?= h($values[$f]??'') ?></strong></div><?php endforeach; ?></div></div>
    <?php elseif($sec==='kurzbeschreibung' && !empty($p['kurzbeschreibung'])): ?><div class="card"><h3>Kurzbeschreibung</h3><div><?= nl2br(h($p['kurzbeschreibung'])) ?></div></div>
    <?php elseif($sec==='langbeschreibung' && !empty($p['langbeschreibung'])): ?><div class="card"><h3>Beschreibung</h3><div><?= nl2br(h($p['langbeschreibung'])) ?></div></div>
    <?php endif; ?>
  <?php endforeach; ?>
  <div class="card" style="background:#f1f5f9; border-color:#cbd5e1;"><h3>Info</h3><div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:15px;"><div><small>Status</small><br><strong><?= h($p['status']) ?></strong></div><div><small>Fällig am</small><br><strong><?= h($fmtDate($p['enddatum'])) ?></strong></div></div></div>
  <div class="card" style="border-top:4px solid #10b981; background:#f0fdf4;">
    <h3 style="margin-top:0; color:#059669;">✅ Rückmeldung</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="confirm_done">
      <div style="margin-bottom:15px"><label style="display:block; font-weight:bold; margin-bottom:5px;">Bemerkung</label><textarea name="unt_bemerkung" class="meta-item" style="width:100%; box-sizing:border-box; height:80px; border:1px solid #d1d5db; padding:10px;"><?= h($p['unt_bemerkung']??'') ?></textarea></div>
      <div class="upload-row"><label>Bild 1 auswählen</label><input type="file" name="unt_img1" accept=".jpg,.jpeg"><?php if(isset($untFiles['Unternehmer Bild 1'])): ?><div class="existing-file">✅ Vorhanden: <a href="<?= h('../'.ltrim($untFiles['Unternehmer Bild 1']['pfad'],'/')) ?>" target="_blank">Ansehen</a></div><?php endif; ?></div>
      <div class="upload-row"><label>Bild 2 auswählen</label><input type="file" name="unt_img2" accept=".jpg,.jpeg"><?php if(isset($untFiles['Unternehmer Bild 2'])): ?><div class="existing-file">✅ Vorhanden: <a href="<?= h('../'.ltrim($untFiles['Unternehmer Bild 2']['pfad'],'/')) ?>" target="_blank">Ansehen</a></div><?php endif; ?></div>
      <div class="upload-row"><label>Dokument auswählen (PDF)</label><input type="file" name="unt_pdf" accept=".pdf"><?php if(isset($untFiles['Unternehmer Dokument'])): ?><div class="existing-file">✅ Vorhanden: <a href="<?= h('../'.ltrim($untFiles['Unternehmer Dokument']['pfad'],'/')) ?>" target="_blank">Ansehen</a></div><?php endif; ?></div>
      <button class="btn" style="width:100%; padding:15px; font-size:16px;">Absenden (Überschreiben)</button>
    </form>
  </div>
</div>
<div id="lb-overlay"><div class="lb-close" onclick="closeLightbox()">&times;</div><div class="lb-nav"><div class="lb-btn" onclick="prevImg()">&#10094;</div><div class="lb-btn" onclick="nextImg()">&#10095;</div></div><img id="lb-img" src=""></div>
<script>
  let curIdx=0; const galleryImgs=[...document.querySelectorAll('.gallery-img')];
  window.openLightbox=idx=>{ if(!galleryImgs[idx]) return; curIdx=idx; document.getElementById('lb-img').src=galleryImgs[curIdx].src; document.getElementById('lb-overlay').style.display='flex'; document.body.style.overflow='hidden'; };
  window.closeLightbox=()=>{ document.getElementById('lb-overlay').style.display='none'; document.body.style.overflow=''; };
  window.nextImg=()=>{ curIdx=(curIdx+1)%galleryImgs.length; document.getElementById('lb-img').src=galleryImgs[curIdx].src; };
  window.prevImg=()=>{ curIdx=(curIdx-1+galleryImgs.length)%galleryImgs.length; document.getElementById('lb-img').src=galleryImgs[curIdx].src; };
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeLightbox(); if(e.key==='ArrowRight') nextImg(); if(e.key==='ArrowLeft') prevImg(); });
</script>
</body>
</html>
