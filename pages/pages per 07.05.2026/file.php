<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/authz.php';
require_login();
require_once __DIR__.'/../includes/fs.php';

$projectId=(int)($_GET['projekt_id'] ?? 0);
if($projectId<=0) { http_response_code(400); exit('projekt_id fehlt.'); }
if (function_exists('require_project_access')) require_project_access($projectId);

$rel = isset($_GET['path']) ? str_replace('\\','/', trim($_GET['path'])) : '';
$dl  = (int)($_GET['dl'] ?? 0);

$root=project_root_path($mysqli,$projectId);
if(!$root){ http_response_code(404); exit('Root fehlt.'); }

$abs=fs_safe_join($root,$rel);
if(!$abs || !is_file($abs)){ http_response_code(404); exit('Datei nicht gefunden.'); }

$mime = mime_content_type($abs) ?: 'application/octet-stream';
header('Content-Type: '.$mime);
$basename=basename($abs);
header('Content-Disposition: '.($dl===1?'attachment':'inline').'; filename="'.rawurlencode($basename).'"');
header('Content-Length: '.filesize($abs));
header('X-Content-Type-Options: nosniff');
readfile($abs);
