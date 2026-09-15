<?php
declare(strict_types=1);

/* API-Antworten dürfen niemals durch PHP-Warnungen/Notices beschädigt werden. */
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/* auth.php setzt den Session-Namen und startet die Session bei Bedarf. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('json_response')) {
    function json_response(array $data, int $code = 200): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}

if (!function_exists('api_try')) {
    function api_try(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            error_log(sprintf(
                '[pendenz-api] %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            json_response([
                'ok' => false,
                'error' => 'INTERNAL_ERROR',
                'message' => 'Interner Serverfehler. Bitte erneut versuchen.'
            ], 500);
        }
    }
}

if (!function_exists('db')) {
    function db(): mysqli
    {
        global $mysqli;

        if (!($mysqli instanceof mysqli)) {
            json_response([
                'ok' => false,
                'error' => 'DB_NOT_AVAILABLE',
                'message' => 'Datenbank momentan nicht verfügbar.'
            ], 500);
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }
}

if (!function_exists('require_login_json')) {
    function require_login_json(): int
    {
        if (!is_logged_in()) {
            json_response([
                'ok' => false,
                'error' => 'UNAUTHENTICATED',
                'message' => 'Bitte einloggen.'
            ], 401);
        }

        $userId = (int) (current_user_id() ?? 0);
        if ($userId <= 0) {
            json_response([
                'ok' => false,
                'error' => 'INVALID_SESSION',
                'message' => 'Ungültige Sitzung. Bitte neu einloggen.'
            ], 401);
        }

        return $userId;
    }
}

if (!function_exists('require_superadmin_json')) {
    function require_superadmin_json(): void
    {
        require_login_json();
        if (!is_superadmin()) {
            json_response(['ok' => false, 'error' => 'FORBIDDEN'], 403);
        }
    }
}

if (!function_exists('require_project_access_json')) {
    function require_project_access_json(mysqli $db, int $projectId): void
    {
        $userId = require_login_json();

        if ($projectId <= 0) {
            json_response([
                'ok' => false,
                'error' => 'PROJECT_REQUIRED',
                'message' => 'Keine gültige Liegenschaft / kein gültiges Projekt ausgewählt.'
            ], 422);
        }

        if (is_admin()) {
            return;
        }

        if (!is_project_member($db, $projectId, $userId)) {
            json_response([
                'ok' => false,
                'error' => 'PROJECT_FORBIDDEN',
                'message' => 'Kein Zugriff auf dieses Projekt.'
            ], 403);
        }
    }
}

if (!function_exists('table_exists')) {
    function table_exists(mysqli $db, string $name): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) c FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count > 0;
    }
}

if (!function_exists('col_exists')) {
    function col_exists(mysqli $db, string $table, string $col): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) c FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->bind_param('ss', $table, $col);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count > 0;
    }
}
