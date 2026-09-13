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
  SELECT p.*, pr.name AS projekt_name
  FROM pendenzen p
  LEFT JOIN projekte pr ON p.projekt_id=pr.id
  WHERE p.public_enabled=1 AND p.public_token=? LIMIT 1
");
$q->bind_param("s",$token); $q->execute(); $res=$q->get_result();
$p=$res?$res->fetch_assoc():null;
if(!$p){ http_response_code(404); exit('Not found'); }

// Optional: Bestätigen
$flash="";
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='confirm_done') {
  $now = date('Y-m-d H:i:s');
  $status = 'erledigt'; // oder $p['status'] lassen – hier bewusst auf erledigt setzen
  $st=$mysqli->prepare("UPDATE pendenzen SET confirmation_at=?, status=? WHERE id=?");
  $st->bind_param("ssi",$now,$status,$p['id']); $st->execute();
  $flash="✅ Vielen Dank! Die Pendenz wurde als erledigt markiert.";
  // neu laden
  header("Location: pendenz_public.php?t=".rawurlencode($token)); exit;
}

// Medien
$cover = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND is_cover=1 ORDER BY id DESC LIMIT 1")->fetch_assoc();
$imgs  = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND typ='image' ORDER BY is_cover DESC, sort_index IS NULL, sort_index, id");
$files = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id=".(int)$p['id']." AND typ<>'image' ORDER BY sort_index IS NULL, sort_index, id");

// Branding
$brandUser = get_user_with_company($mysqli, (int)($p['created_by']??0));
$brandPath = $brandUser['firmenlogo'] ?? null;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= h($p['titel']) ?> – Pendenz</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f7f7f8;margin:0;color:#222}
    .container{max-width:100%;margin:0 auto;padding:0}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;margin-bottom:12px}
    .hero img{width:100%;height:340px;object-fit:cover;border-radius:8px;border:1px solid #ddd;cursor:pointer}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media (max-width:900px){
      .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; width: 100% !important; }
      .card { border-radius: 0; border-left: 0; border-right: 0; margin-bottom: 8px; width: 100% !important; box-sizing: border-box; }
      .hero img { border-radius: 0; height: auto; max-height: 400px; }
      .grid{grid-template-columns:1fr}
    }
    .btn{display:inline-block;background:#1f9d8d;color:#fff;padding:8px 12px;border-radius:8px;text-decoration:none;border:0;cursor:pointer;font-weight:bold;width:100%;box-sizing:border-box;text-align:center}
    .btn-danger{background:#d64545}
    .meta-item{padding:8px;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
    .imggrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
    .imggrid img{width:100%;height:110px;object-fit:cover;border:1px solid #eee;border-radius:6px;cursor:pointer}
    
    /* Lightbox Styles */
    #lightbox-overlay {
      position: fixed; top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.95); display: none; z-index: 10000;
      flex-direction: column; align-items: center; justify-content: center;
    }
    #lightbox-img { max-width: 95%; max-height: 85%; object-fit: contain; border-radius: 4px; }
    .lightbox-close { position: absolute; top: 20px; right: 20px; color: #fff; font-size: 40px; cursor: pointer; user-select: none; z-index: 10001; }
    .lightbox-nav { position: absolute; top: 50%; width: 100%; display: flex; justify-content: space-between; padding: 0 20px; transform: translateY(-50%); pointer-events: none; }
    .lightbox-btn { pointer-events: auto; background: rgba(255,255,255,0.1); color: #fff; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 24px; cursor: pointer; user-select: none; }
    .lightbox-caption { color: #ccc; margin-top: 15px; font-size: 14px; }
  </style>
</head>
<body>
  <div class="header-brand" style="display:flex; justify-content:space-between; align-items:flex-end; padding:15px; background:#fff; border-bottom:1px solid #e5e7eb;">
      <div class="header-brand-info" style="font-size:13px; color:#4b5563; line-height:1.4;">
        <?php if($brandUser): ?>
          <?php if(!empty($brandUser['firmenname'])): ?><strong style="font-size:18px; color:#1e293b;"><?= h($brandUser['firmenname']) ?></strong><br><?php endif; ?>
          <?php if(!empty($brandUser['strasse'])): ?><?= h($brandUser['strasse']) ?><br><?php endif; ?>
          <?php if(!empty($brandUser['plz']) || !empty($brandUser['ort'])): ?><?= h(($brandUser['plz']??'').' '.($brandUser['ort']??'')) ?><br><?php endif; ?>
          <div style="margin-top:4px; font-size:12px; color:#64748b;">
            <?php if(!empty($brandUser['telefon'])): ?>📞 <?= h($brandUser['telefon']) ?> &nbsp;<?php endif; ?>
            <?php if(!empty($brandUser['email'])): ?>✉️ <?= h($brandUser['email']) ?><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="header-brand-logo">
        <?php if($brandPath && is_file(__DIR__.'/../'.ltrim($brandPath,'/'))): ?>
          <img src="<?= h('../'.ltrim($brandPath,'/')) ?>" alt="Logo" style="max-width:180px; max-height:80px; object-fit:contain;">
        <?php endif; ?>
      </div>
  </div>

  <div class="container">
    <div class="card" style="display:flex; justify-content:space-between; align-items:center;">
      <div>
        <h1 style="margin:0 0 6px 0;"><?= h($p['titel']) ?></h1>
        <div style="color:#555">Projekt: <strong><?= h($p['projekt_name'] ?? '') ?></strong> • Status: <strong><?= $p['status']==='in Bearbeitung' ? 'in Bearbeitung' : h($p['status']) ?></strong></div>
      </div>
      <a href="pendenz_pdf.php?id=<?= (int)$p['id'] ?>&t=<?= h($token) ?>" class="btn" style="width:auto; padding:8px 20px; background:#10b981;">📄 PDF-Bericht</a>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
      <div class="hero" onclick="openLightbox(0)">
        <?php if($cover): ?>
          <img src="<?= h('../'.ltrim($cover['pfad'],'/')) ?>" alt="Cover" class="gallery-img">
        <?php else: ?>
          <div style="height:340px;display:flex;align-items:center;justify-content:center;color:#777">Kein Bild</div>
        <?php endif; ?>
      </div>

      <?php if($imgs && $imgs->num_rows > 1): ?>
        <div class="imggrid" style="padding:10px;">
          <?php $idx=0; while($i=$imgs->fetch_assoc()): 
            if($cover && $i['id'] == $cover['id']) continue; 
          ?>
            <img src="<?= h('../'.ltrim($i['pfad'],'/')) ?>" onclick="openLightbox(<?= ++$idx ?>)" class="gallery-img">
          <?php endwhile; $imgs->data_seek(0); ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c;"><?= h($flash) ?></div><?php endif; ?>

    <?php if(!empty($p['kurzbeschreibung'])): ?>
      <div class="card"><h3>Kurzbeschreibung</h3><div class="meta-item"><?= nl2br(h($p['kurzbeschreibung'])) ?></div></div>
    <?php endif; ?>
    <?php if(!empty($p['langbeschreibung'])): ?>
      <div class="card"><h3>Beschreibung</h3><div class="meta-item"><?= nl2br(h($p['langbeschreibung'])) ?></div></div>
    <?php endif; ?>


    <?php if($files && $files->num_rows): ?>
      <div class="card">
        <h3>Anhänge</h3>
        <ul style="margin-left:18px">
          <?php while($f=$files->fetch_assoc()): ?>
            <li><a href="<?= h('../'.ltrim($f['pfad'],'/')) ?>" target="_blank"><?= h($f['titel'] ?: basename($f['pfad'])) ?></a>
              <span style="color:#666">— <?= h($f['mimetype'] ?: 'Datei') ?>, <?= number_format((int)($f['groesse']??0)/1024,0,'\'','.') ?> KB</span></li>
          <?php endwhile; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="post" onsubmit="return confirm('Erledigt bestätigen?');">
        <input type="hidden" name="action" value="confirm_done">
        <button class="btn">✅ Erledigt bestätigen</button>
      </form>
    </div>
  </div>

  <!-- Lightbox Overlay -->
  <div id="lightbox-overlay">
    <div class="lightbox-close" onclick="closeLightbox()">&times;</div>
    <div class="lightbox-nav">
      <div class="lightbox-btn" onclick="prevImg()">&#10094;</div>
      <div class="lightbox-btn" onclick="nextImg()">&#10095;</div>
    </div>
    <img id="lightbox-img" src="" alt="">
    <div class="lightbox-caption" id="lightbox-caption"></div>
  </div>

  <script>
    let currentImgIdx = 0;
    const galleryImgs = [...document.querySelectorAll('.gallery-img')];
    const overlay = document.getElementById('lightbox-overlay');
    const lbImg = document.getElementById('lightbox-img');
    const lbCaption = document.getElementById('lightbox-caption');

    window.openLightbox = function(idx) {
      currentImgIdx = idx;
      updateLightbox();
      overlay.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    window.closeLightbox = function() {
      overlay.style.display = 'none';
      document.body.style.overflow = '';
    }

    window.nextImg = function() {
      currentImgIdx = (currentImgIdx + 1) % galleryImgs.length;
      updateLightbox();
    }

    window.prevImg = function() {
      currentImgIdx = (currentImgIdx - 1 + galleryImgs.length) % galleryImgs.length;
      updateLightbox();
    }

    function updateLightbox() {
      const img = galleryImgs[currentImgIdx];
      if (!img) return;
      lbImg.src = img.src;
      lbCaption.innerText = `Bild ${currentImgIdx + 1} von ${galleryImgs.length}`;
    }

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowRight') nextImg();
      if (e.key === 'ArrowLeft') prevImg();
    });
    overlay.addEventListener('click', e => {
      if (e.target === overlay) closeLightbox();
    });
  </script>
</body>
</html>
