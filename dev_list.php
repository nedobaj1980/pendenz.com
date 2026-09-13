<?php
// dev_list.php — nur zur Diagnose, danach wieder löschen!
if (session_status()===PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=utf-8');

$base = __DIR__;
$allow = ['includes','pages','assets','']; // '' = Projekt-Root

$skipNames = ['.','..','.git','vendor','node_modules','storage','cache','tmp'];

function list_dir($path) {
  global $skipNames;
  $out = [];
  if (!is_dir($path)) return $out;
  $items = scandir($path);
  foreach ($items as $it) {
    if (in_array($it,$skipNames,true)) continue;
    $full = $path.DIRECTORY_SEPARATOR.$it;
    $isDir = is_dir($full);
    $out[] = [
      'name'=>$it,
      'type'=>$isDir?'dir':'file',
      'size'=>$isDir?null:filesize($full),
      'mtime'=>date('Y-m-d H:i:s', filemtime($full)),
      'path'=>str_replace('\\','/',$full)
    ];
    if ($isDir) {
      $out[count($out)-1]['children'] = list_dir($full);
    }
  }
  usort($out, function($a,$b){
    if ($a['type']!==$b['type']) return $a['type']==='dir'?-1:1; // Ordner zuerst
    return strcasecmp($a['name'],$b['name']);
  });
  return $out;
}

function render_tree($nodes,$level=0){
  if (!$nodes) return;
  echo '<ul style="margin:4px 0 4px '.(12*$level).'px; list-style: none; font-family: ui-sans-serif,Segoe UI,Arial;">';
  foreach($nodes as $n){
    $label = htmlspecialchars($n['name']);
    $meta = $n['type']==='file'
      ? (' · '.number_format((int)$n['size']).' B · '.$n['mtime'])
      : (' · '.$n['mtime']);
    echo '<li>';
    echo $n['type']==='dir' ? '📁 ' : '📄 ';
    echo "<strong>{$label}</strong><span style='opacity:.7'>{$meta}</span>";
    if (!empty($n['children'])) render_tree($n['children'],$level+1);
    echo '</li>';
  }
  echo '</ul>';
}

echo '<h2 style="font-family: ui-sans-serif,Segoe UI,Arial; margin:8px 0;">pendenz.com – Dateistruktur</h2>';
echo '<p style="font-family: ui-sans-serif,Segoe UI,Arial; opacity:.8">Nur zur Diagnose. Bitte nach dem Senden wieder löschen.</p>';

foreach ($allow as $rel) {
  $dir = $rel==='' ? $base : ($base.DIRECTORY_SEPARATOR.$rel);
  if (!is_dir($dir)) continue;
  echo '<h3 style="font-family: ui-sans-serif,Segoe UI,Arial; margin:12px 0;">/'.htmlspecialchars($rel?:'.').'</h3>';
  render_tree(list_dir($dir));
}
