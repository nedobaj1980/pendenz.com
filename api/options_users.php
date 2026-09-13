<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// === Debug einschalten via ?debug=1 ===
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

  // Falls require_login() intern umleitet/HTML schreibt: hier API-sicher abfangen
  if (!function_exists('is_logged_in')) {
    // Fallback: simple Check aus Session
    $logged = !empty($_SESSION['user_id']);
  } else {
    $logged = is_logged_in();
  }
  if (!$logged) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
  }

  if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    throw new RuntimeException('DB-Verbindung fehlt ($mysqli).');
  }

  // Prüfe, ob Tabelle/Spalten existieren (verhindert 500 bei Schreibfehlern im Schema)
  $check = $mysqli->prepare("
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() AND table_name='benutzer' AND column_name IN ('id','name','email')
  ");
  $check->execute();
  $check->bind_result($cnt);
  $check->fetch();
  $check->close();
  if ((int)$cnt < 1) {
    throw new RuntimeException('Tabelle/Spalten benutzer(id,name,email) nicht gefunden.');
  }

  $items = [];
  $sql = "SELECT id, name, email FROM benutzer 
          WHERE deleted_at IS NULL 
          ORDER BY name, id";
  if ($res = $mysqli->query($sql)) {
    while ($r = $res->fetch_assoc()) {
      $items[] = [
        'id'    => (int)$r['id'],
        'name'  => ($r['name'] ?? '') !== '' ? $r['name'] : ('User #'.$r['id']),
        'email' => $r['email'] ?? ''
      ];
    }
    $res->free();
  }

  echo json_encode(['items'=>$items], JSON_UNESCAPED_UNICODE);

} catch (mysqli_sql_exception $e) {
  if ($DEBUG) { throw $e; }
  http_response_code(500);
  echo json_encode(['error'=>'sql_exception','message'=>$e->getMessage()]);
} catch (Throwable $e) {
  if ($DEBUG) { throw $e; }
  http_response_code(500);
  echo json_encode(['error'=>'exception','message'=>$e->getMessage()]);
}
