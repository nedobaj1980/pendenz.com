<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// === Debug via ?debug=1 ===
$DEBUG = (isset($_GET['debug']) && $_GET['debug'] === '1');
if ($DEBUG) {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

try {
  require_once __DIR__ . '/../config.php';
  require_once __DIR__ . '/../includes/auth.php';
  require_once __DIR__ . '/../includes/pendenz_domain.php';

  // API-sicherer Login-Check
  if (!function_exists('is_logged_in')) {
    $logged = !empty($_SESSION['user_id']);
  } else {
    $logged = is_logged_in();
  }
  if (!$logged) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'unauthorized']);
    exit;
  }

  if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    throw new RuntimeException('DB-Verbindung fehlt ($mysqli).');
  }

  // Payload lesen
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    echo json_encode(['ok'=>false,'message'=>'Bad payload']); exit;
  }

  $titel         = trim($data['titel'] ?? '');
  if ($titel === '') { echo json_encode(['ok'=>false,'message'=>'Titel fehlt']); exit; }
  $beschreibung  = trim((string)($data['beschreibung'] ?? $data['langbeschreibung'] ?? ''));
  $kurzbeschreibung = trim((string)($data['kurzbeschreibung'] ?? ''));
  $status        = pendenz_normalize_status((string)($data['status'] ?? 'offen'));
  $prioritaet    = isset($data['prioritaet']) ? (int)$data['prioritaet'] : (int)($data['wichtigkeit'] ?? 0);
  $zugewiesen_an = (isset($data['zugewiesen_an']) && $data['zugewiesen_an']!=='') ? (int)$data['zugewiesen_an'] : ((isset($data['zustaendig_id']) && $data['zustaendig_id']!=='') ? (int)$data['zustaendig_id'] : null);
  $send_now      = !empty($data['send_now']) ? 1 : 0;
  $projekt_id    = (isset($data['projekt_id']) && $data['projekt_id']!=='') ? (int)$data['projekt_id'] : null;
  $wohnung_id    = (isset($data['wohnung_id']) && $data['wohnung_id']!=='') ? (int)$data['wohnung_id'] : null;

  if ($wohnung_id) {
    $rel = $mysqli->prepare("SELECT o.projekt_id FROM wohnungen w JOIN objekte o ON o.id=w.objekt_id WHERE w.id=? LIMIT 1");
    $rel->bind_param('i', $wohnung_id); $rel->execute(); $relRow = $rel->get_result()->fetch_assoc(); $rel->close();
    if (!$relRow || ($projekt_id && (int)$relRow['projekt_id'] !== $projekt_id)) {
      echo json_encode(['ok'=>false,'message'=>'Wohnung gehört nicht zum gewählten Projekt']); exit;
    }
  }

  // Minimale Schema-Checks (verhindern 500, geben klare Meldung)
  $needCols = ['titel','kurzbeschreibung','langbeschreibung','status','wichtigkeit','zustaendig_id','send_now','created_at','updated_at'];
  $placeholders = implode(',', array_fill(0, count($needCols), '?'));
  // Schneller Check: gibt es pendenzen überhaupt?
  $chk = $mysqli->query("SHOW TABLES LIKE 'pendenzen'");
  if ($chk->num_rows === 0) throw new RuntimeException("Tabelle 'pendenzen' existiert nicht.");
  $chk->free();

  $stmt = $mysqli->prepare("INSERT INTO pendenzen (projekt_id, wohnung_id, titel, kurzbeschreibung, langbeschreibung, beschreibung, status, wichtigkeit, zustaendig_id, send_now, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
  $stmt->bind_param(
    'iisssssiii',
    $projekt_id, $wohnung_id, $titel, $kurzbeschreibung, $beschreibung, $beschreibung, $status, $prioritaet, $zugewiesen_an, $send_now
  );
  $ok = $stmt->execute();
  $newId = $ok ? $stmt->insert_id : 0;
  $err  = $ok ? null : $stmt->error;
  $stmt->close();

  if (!$ok) { echo json_encode(['ok'=>false,'message'=>$err ?: 'Insert fehlgeschlagen']); exit; }

  echo json_encode(['ok'=>true,'id'=>$newId]);

} catch (mysqli_sql_exception $e) {
  if ($DEBUG) { throw $e; }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'sql_exception','message'=>$e->getMessage()]);
} catch (Throwable $e) {
  if ($DEBUG) { throw $e; }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()]);
}
