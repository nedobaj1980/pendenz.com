<?php
// includes/csrf.php
// Kompakte CSRF-Helper ohne Token-Rotation (Token gilt für die ganze Session)
// Unterstützt Feld "csrf" oder "csrf_token" und Header "X-CSRF-Token"/"X-CSRF".
// Neu: unterstützt auch JSON-Body (application/json) und liefert bei JSON-Requests JSON-Fehler.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('CSRF_SESSION_KEY')) {
    define('CSRF_SESSION_KEY', 'csrf_token');
}

/** Liefert ein Session-Token (erzeugt bei Bedarf) */
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        $t = $_SESSION[CSRF_SESSION_KEY] ?? '';
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            $_SESSION[CSRF_SESSION_KEY] = $t;
        }
        return $t;
    }
}

/** Hidden-Input im Formular (Standardname: "csrf") – gibt String zurück */
if (!function_exists('csrf_input')) {
    function csrf_input(string $name = 'csrf'): string {
        return '<input type="hidden" name="' .
               htmlspecialchars($name, ENT_QUOTES, 'UTF-8') .
               '" value="' .
               htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') .
               '">';
    }
}

/**
 * Alias – **echo’t** den Hidden-Input UND gibt den String zurück.
 * Damit funktionieren sowohl `<?php csrf_field(); ?>` (echo) als auch `<?= csrf_field() ?>` (return).
 */
if (!function_exists('csrf_field')) {
    function csrf_field(string $name = 'csrf'): string {
        $html = csrf_input($name);
        echo $html;
        return $html;
    }
}

/** Optionales Meta für JS */
if (!function_exists('csrf_meta')) {
    function csrf_meta(string $name = 'csrf-token'): string {
        return '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') .
               '" content="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

/** Erkennen, ob Request JSON ist (Content-Type oder Accept) */
if (!function_exists('csrf_is_json_request')) {
    function csrf_is_json_request(): bool {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return (stripos($ct, 'application/json') !== false) ||
               (stripos($accept, 'application/json') !== false);
    }
}

/** JSON-Body (einmalig) lesen & cachen */
if (!function_exists('csrf_json_body')) {
    function csrf_json_body(): ?array {
        static $cached = null, $done = false;
        if ($done) return $cached;
        $done = true;

        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') === false) {
            $cached = null; return null;
        }
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        $cached = is_array($j) ? $j : null;
        return $cached;
    }
}

/** Token aus Request lesen (POST / Header / JSON-Body) */
if (!function_exists('csrf_request_token')) {
    function csrf_request_token(): ?string {
        // 1) Klassisch: POST-Felder / Header
        $cands = [
            $_POST['csrf']                ?? null,
            $_POST['csrf_token']          ?? null,
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
            $_SERVER['HTTP_X_CSRF']       ?? null,
        ];
        foreach ($cands as $t) {
            if (is_string($t) && $t !== '') return $t;
        }
        // 2) JSON-Body
        $j = csrf_json_body();
        if (is_array($j)) {
            foreach (['csrf','csrf_token'] as $k) {
                if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') {
                    return $j[$k];
                }
            }
        }
        return null;
    }
}

/** TRUE/FALSE – nur für schreibende Methoden relevant */
if (!function_exists('csrf_validate_request')) {
    function csrf_validate_request(): bool {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
            return true; // GET/HEAD/OPTIONS: kein CSRF nötig
        }
        $given = csrf_request_token();
        return is_string($given) && hash_equals(csrf_token(), $given);
    }
}

/**
 * Erzwingt gültiges CSRF, sonst 400.
 * Liefert bei JSON-Requests JSON-Fehler, sonst Plaintext (bestehendes Verhalten bleibt erhalten).
 */
if (!function_exists('csrf_require')) {
    function csrf_require(): void {
        if (!csrf_validate_request()) {
            http_response_code(400);
            if (csrf_is_json_request()) {
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['success' => false, 'error' => 'Ungültiges Sicherheits-Token.']);
            } else {
                echo 'CSRF-Check fehlgeschlagen.';
            }
            exit;
        }
    }
}

/** Komfort: kompatibler Alias wie in einigen Endpoints verwendet */
if (!function_exists('csrf_require_for_state_changing_requests')) {
    function csrf_require_for_state_changing_requests(): void {
        csrf_require();
    }
}

/** (Optional) Header-Name + Value für Fetch-Requests in JS */
if (!function_exists('csrf_header_name')) {
    function csrf_header_name(): string { return 'X-CSRF'; }
}
if (!function_exists('csrf_header_value')) {
    function csrf_header_value(): string { return csrf_token(); }
}

/** ***Abwärtskompatibel***: manche Seiten rufen `csrf_validate()` auf */
if (!function_exists('csrf_validate')) {
    function csrf_validate(): void {
        // identisches Verhalten wie zuvor: wir erzwingen im Fehlerfall einen 400-Abbruch
        csrf_require();
    }
}
