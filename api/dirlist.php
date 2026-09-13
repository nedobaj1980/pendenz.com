<?php
// api/dirlist.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

if (empty($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'msg'=>'Not authenticated']); exit;
}

function norm_sep($p){ return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p), DIRECTORY_SEPARATOR); }
function is_windows(){ return stripos(PHP_OS_FAMILY, 'Windows') !== false; }

function list_roots(): array {
  $roots = [];
  if (is_windows()) {
    foreach (range('C','Z') as $l) {
      $d = $l . ':\\';
      if (@is_dir($d)) $roots[] = ['label'=>$d, 'path'=>$d];
    }
  } else {
    $roots[] = ['label'=>'/', 'path'=>'/'];
    $home = getenv('HOME'); if ($home && is_dir($home)) $roots[] = ['label'=>'~', 'path'=>$home];
  }
  return $roots;
}

function breadcrumbs($path): array {
  $out = [];
  if (is_windows()) {
    $p = str_replace(['/','\\'], '\\', $path);
    if (!preg_match('#^[A-Za-z]:\\\\#',$p)) return $out;
    $drive = substr($p,0,3);
    $rest  = substr($p,3);
    $out[] = ['label'=>$drive,'path'=>$drive];
    $parts = array_values(array_filter(explode('\\',$rest), fn($x)=>$x!==''));
    $acc = $drive;
    foreach ($parts as $part) { $acc = rtrim($acc,'\\') . '\\' . $part; $out[] = ['label'=>$part,'path'=>$acc]; }
    return $out;
  } else {
    $p = str_replace('\\','/',$path);
    if ($p === '' || $p[0] !== '/') return $out;
    $out[] = ['label'=>'/','path'=>'/'];
    $parts = array_values(array_filter(explode('/', ltrim($p,'/')), fn($x)=>$x!==''));
    $acc = '';
    foreach ($parts as $part){ $acc .= '/'.$part; $out[] = ['label'=>$part,'path'=>$acc]; }
    return $out;
  }
}

/* ---- Operations ----
   GET  ?p=PATH                -> List Directory
   GET  (no p)                 -> List Roots
   POST op=mkdir base=PATH name=FOLDER (CSRF benötigt)
*/
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
  // Nur für schreibende Operationen CSRF prüfen
  $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!$hdr || !hash_equals($_SESSION['_csrf_token'] ?? '', $hdr)) {
    http_response_code(419);
    echo json_encode(['ok'=>false,'msg'=>'CSRF failed']); exit;
  }
  $op   = trim($_POST['op'] ?? '');
  if ($op === 'mkdir') {
    $base = norm_sep((string)($_POST['base'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    if ($base === '' || !@is_dir($base)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültiger Basisordner']); exit; }
    if ($name === '' || preg_match('~[<>:"/\\\\|?*]~u', $name)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Ungültiger Ordnername']); exit; }
    $target = $base . DIRECTORY_SEPARATOR . $name;
    if (is_dir($target)) { echo json_encode(['ok'=>true,'msg'=>'Ordner existiert bereits','path'=>$target]); exit; }
    if (@mkdir($target, 0777, true)) { echo json_encode(['ok'=>true,'msg'=>'Ordner erstellt','path'=>$target]); exit; }
    http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'Ordner konnte nicht erstellt werden']); exit;
  }
  http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Unknown op']); exit;
}

/* GET: Listing */
$reqPath = rawurldecode(trim((string)($_GET['p'] ?? '')));
if ($reqPath === '') {
  echo json_encode(['ok'=>true,'mode'=>'roots','roots'=>list_roots()]); exit;
}

$path = norm_sep($reqPath);
if (!@is_dir($path)) { http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Kein gültiger Ordner: '.$reqPath]); exit; }

$scan = @scandir($path);
if ($scan === false) { http_response_code(403); echo json_encode(['ok'=>false,'msg'=>'Zugriff verweigert: '.$path]); exit; }

$dirs = [];
foreach ($scan as $entry) {
  if ($entry === '.' || $entry === '..') continue;
  $full = $path . DIRECTORY_SEPARATOR . $entry;
  if (@is_dir($full)) $dirs[] = ['name'=>$entry, 'path'=>$full];
}
usort($dirs, fn($a,$b)=> strnatcasecmp($a['name'],$b['name']));

echo json_encode([
  'ok'=>true,
  'mode'=>'list',
  'current'=>$path,
  'crumbs'=>breadcrumbs($path),
  'entries'=>$dirs
], JSON_UNESCAPED_UNICODE);
