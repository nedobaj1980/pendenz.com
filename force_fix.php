<?php
$f = 'pages/projekt_dashboard.php';
$c = file_get_contents($f);

// Fix CSS
$oldCss = '.cover{aspect_ratio:16/9;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden}';
$newCss = '.cover{aspect_ratio:16/9;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden; position:relative;}
.cover img{width:100%;height:100%;object-fit:cover}
.cover .icon-placeholder { font-size: 64px; display:flex; align-items:center; justify-content:center; width:100%; height:100%; background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); }';
$c = str_replace($oldCss, $newCss, $c);

// Fix HTML
$oldHtml = '<a class="cover" href="<?= e($navUrl) ?>" title="Öffnen"><img src="<?= e($cover) ?>" alt=""></a>';
$newHtml = '<a class="cover" href="<?= e($navUrl) ?>" title="Öffnen">
            <?php $realImg = first_preview_for($mysqli,$projekt_id,$rel); ?>
            <?php if($realImg): ?>
              <img src="<?= e($realImg) ?>" alt="">
            <?php else: ?>
              <div class="icon-placeholder"><?= $icon ?></div>
            <?php endif; ?>
          </a>';
$c = str_replace($oldHtml, $newHtml, $c);

file_put_contents($f, $c);
echo "SUCCESS";
