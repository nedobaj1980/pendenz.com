<?php
// includes/media.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php'; // slugify(), abs_path_from() ggf. vorhanden

if (!function_exists('slugify')) {
  function slugify(string $t): string {
    $t = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$t);
    $t = strtolower(preg_replace('~[^a-z0-9]+~','-',$t));
    $t = trim($t,'-');
    return $t ?: 'x';
  }
}

/** baut path "uploads/pendenzen/{projekt}/{ordner-pfad oder custom-pfad}/{id-slug}" */
function pendenz_fs_base(mysqli $db, int $projekt_id, ?int $ordner_id, int $pendenz_id, string $titel='', string $customRelPath=''): array {
  $base = 'uploads/pendenzen';
  
  if ($customRelPath !== '') {
      $folder = trim(str_replace(['\\','//'], '/', $customRelPath), '/');
  } else {
      $folder = ordner_slug_path($db, $ordner_id);
      // Fallback if no folder but projekt? Usually we use projekt_id as subfolder
      if (!$folder) $folder = (string)$projekt_id;
  }
  
  $pendSlug = $pendenz_id . '-' . substr(slugify($titel ?: 'pendenz'), 0, 40);
  $rel = trim($base . '/' . $folder . '/' . $pendSlug, '/');
  
  $abs = realpath(__DIR__ . '/..') . '/' . $rel;
  return [$rel, $abs];
}

function ordner_slug_path(mysqli $db, ?int $ordner_id): string {
  if(!$ordner_id) return '';
  $parts = [];
  $cur = (int)$ordner_id;
  while ($cur) {
    $res = $db->query("SELECT id,parent_id,slug,name FROM pendenz_ordner WHERE id={$cur} LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) break;
    $slug = $row['slug'] ?: slugify($row['name']);
    array_unshift($parts, $slug);
    $cur = (int)($row['parent_id'] ?? 0);
  }
  return implode('/', $parts);
}

/**
 * recompress => JPEG <= ~1MB, max Größe ~2000x1600
 * Rückgabe: [absPfad, mime, size]
 */
function image_to_max_1mb(string $absPath, string $mimeIn='image/jpeg'): array {
  $info = @getimagesize($absPath);
  if (!$info) {
    clearstatcache(true, $absPath);
    return [$absPath, ($mimeIn ?: 'image/jpeg'), (int)@filesize($absPath)];
  }
  $src = false;
  switch ($info['mime']) {
    case 'image/jpeg': $src = @imagecreatefromjpeg($absPath); break;
    case 'image/png':  $src = @imagecreatefrompng($absPath);  break;
    case 'image/gif':  $src = @imagecreatefromgif($absPath);  break;
    case 'image/webp': if (function_exists('imagecreatefromwebp')) $src=@imagecreatefromwebp($absPath); break;
  }
  if (!$src) {
    clearstatcache(true, $absPath);
    return [$absPath, ($mimeIn ?: $info['mime'] ?: 'image/jpeg'), (int)@filesize($absPath)];
  }

  $w=imagesx($src); $h=imagesy($src);
  $maxW=2000; $maxH=1600;
  $scale=min(1.0, $maxW/$w, $maxH/$h);
  $nw=max(1,(int)round($w*$scale)); $nh=max(1,(int)round($h*$scale));

  $dst=imagecreatetruecolor($nw,$nh);
  imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
  // imagedestroy($src); // Deprecated in PHP 8.x


  // iterativ Qualität senken
  $q=85; $ok=false;
  while($q>=55){
    ob_start(); imagejpeg($dst,null,$q); $bin=ob_get_clean();
    if(strlen($bin) <= 1024*1024) { file_put_contents($absPath,$bin); $ok=true; break; }
    $q-=5;
  }
  if(!$ok) imagejpeg($dst,$absPath,60);
  // imagedestroy($dst); // Deprecated in PHP 8.x


  clearstatcache(true, $absPath);
  return [$absPath, 'image/jpeg', (int)@filesize($absPath)];
}
