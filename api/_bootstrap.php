<?php
/* Keine PHP-Notices im Output (zerstören JSON) */
@ini_set('display_errors','0');
error_reporting(0);

/* Einbinden – auth.php setzt session_name('PENDENZ_SESSID') und startet die Session */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

/* Immer pures JSON ausgeben */
if (!function_exists('json_response')) {
  function json_response(array $data, int $code = 200): void {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }
}

/* Try/Catch-Wrapper für jede API */
if (!function_exists('api_try')) {
  function api_try(callable $fn): void {
    try {
      $fn();
    } catch (Throwable $e) {
      json_response([
        'ok' => false,
        'error' => 'EXCEPTION',
        'message' => $e->getMessage()
      ], 500);
    }
  }
}

/* DB-Zugriff (mit Exceptions) */
if (!function_exists('db')) {
  function db(): mysqli {
    global $mysqli;
    if (!($mysqli instanceof mysqli)) {
      json_response(['ok'=>false,'error'=>'DB not available'], 500);
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
  }
}

/* Rechteprüfung */
if (!function_exists('require_superadmin_json')) {
  function require_superadmin_json(): void {
    if (!is_logged_in())  json_response(['ok'=>false,'error'=>'UNAUTHENTICATED'], 401);
    if (!is_superadmin()) json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
  }
}

/* Schema-Hilfen */
if (!function_exists('table_exists')) {
  function table_exists(mysqli $db, string $name): bool {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM information_schema.tables
                          WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c > 0;
  }
}

if (!function_exists('col_exists')) {
  function col_exists(mysqli $db, string $table, string $col): bool {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->bind_param("ss", $table, $col);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c > 0;
  }
}
