<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* DEV: Fehler unterdrücken, wenn sie JSON-Antworten stören könnten */
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* DB-Verbindung – nutzt die zentrale config.php */
require_once __DIR__ . '/../config.php';

/* Helper */
function api_json($code, $payload){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function api_bad($code, $msg, $extra = []){
  api_json($code, ['error'=>$msg] + $extra);
}
function api_int($v,$d=0){ $v=filter_var($v, FILTER_VALIDATE_INT); return $v===false?$d:$v; }

/* DEV: default Rolle, falls leer */
if (empty($_SESSION['rolle'])) $_SESSION['rolle'] = 'benutzer';
