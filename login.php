<?php
// login.php – korrigiert (saubere Redirect-Logik + kein Output vor Redirect)

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php"; // h(), site_prefix(), url() ...
$PREFIX = site_prefix();

/* optional, falls vorhanden */
$auth_path = __DIR__ . "/includes/auth.php";
if (file_exists($auth_path)) {
    require_once $auth_path;
}

/* ---------- Hilfsfunktionen ---------- */

if (!function_exists('verify_password_flex')) {
    function verify_password_flex(string $plain, ?string $hashOrPw): bool {
        if ($hashOrPw === null) return false;
        $info = password_get_info($hashOrPw);
        return !empty($info['algo']) ? password_verify($plain, $hashOrPw) : hash_equals($hashOrPw, $plain);
    }
}

if (!function_exists('route_after_login')) {
    function route_after_login(string $rolle): string {
        switch ($rolle) {
            case 'superadmin': return "/pendenz.com/index_superadmin.php";
            case 'admin':      return "/pendenz.com/index_admin.php";
            case 'benutzer':   return "/pendenz.com/index_private.php";
            default:           return "/pendenz.com/index_public.php";
        }
    }
}

/** Nur „menschliche“ Ziele erlauben (keine /api, keine absoluten fremden URLs) */
function sanitize_redirect(?string $url, string $fallback): string {
    if (!$url) return $fallback;
    // absolute fremde URLs blocken
    if (preg_match('~^https?://~i', $url)) return $fallback;
    // nur Pfade im gleichen Projekt
    if (strpos($url, '/pendenz.com/') !== 0 && $url !== '/pendenz.com') return $fallback;
    // API & JSON/Service-Endpoints vermeiden
    if (strpos($url, '/pendenz.com/api/') === 0) return $fallback;
    return $url;
}

if (!function_exists('set_login_session')) {
    function set_login_session(array $user): void {
        if (!headers_sent()) {
            if (function_exists('regenerate_session_id')) regenerate_session_id();
            else session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['rolle']   = $user['rolle'] ?? 'benutzer';
        $_SESSION['email']   = $user['email'] ?? null;

        $name = '';
        if (!empty($user['name'])) {
            $name = $user['name'];
        } else {
            $vn = trim($user['vorname'] ?? '');
            $nn = trim($user['nachname'] ?? '');
            $name = trim($vn.' '.$nn);
        }
        $_SESSION['name'] = $name;
    }
}

if (!function_exists('mark_login_and_event')) {
    function mark_login_and_event(mysqli $db, int $user_id): void {
        if ($stmt = $db->prepare("UPDATE benutzer SET last_login_at=NOW(), first_login_at=IFNULL(first_login_at, NOW()) WHERE id=?")) {
            $stmt->bind_param("i", $user_id); $stmt->execute(); $stmt->close();
        } elseif ($stmt2 = $db->prepare("UPDATE benutzer SET letzter_login=NOW() WHERE id=?")) {
            $stmt2->bind_param("i", $user_id); $stmt2->execute(); $stmt2->close();
        }
        if ($ev = $db->prepare("INSERT INTO user_events (user_id, type) VALUES (?, 'login')")) {
            $ev->bind_param("i", $user_id); $ev->execute(); $ev->close();
        }
    }
}

function do_redirect_after_login(string $rolle): void {
    // 1) Falls eine Zielroute aus auth.php existiert, nimm die
    if (function_exists('redirect_after_login_or_default')) {
        redirect_after_login_or_default($rolle);
        exit;
    }
    // 2) Session-Ziel nur verwenden, wenn es „menschlich“ ist (keine /api)
    $redirect = $_SESSION['redirect_after_login'] ?? null;
    unset($_SESSION['redirect_after_login']);
    $ziel = sanitize_redirect($redirect, route_after_login($rolle));

    header("Location: " . $ziel);
    exit;
}

/* ---------- Login-Verarbeitung (ohne Output davor!) ---------- */

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST['email'] ?? '');
    $passwort = $_POST['passwort'] ?? '';

    if ($email === '' || $passwort === '') {
        $error = "Bitte E-Mail und Passwort eingeben.";
    } else {
        $cleanPhone = preg_replace('/[^0-9]+/', '', $email);
        $stmt = $mysqli->prepare("
            SELECT id, email, rolle, name, vorname, nachname, passwort_hash, passwort
            FROM benutzer
            WHERE email = ?
               OR (telefonnummer = ? AND telefonnummer <> '')
               OR (REPLACE(REPLACE(REPLACE(REPLACE(telefonnummer, ' ', ''), '-', ''), '+', ''), '/', '') = ? AND telefonnummer <> '')
            LIMIT 1
        ");
        if (!$stmt) {
            $error = "Interner Fehler (DB-Prepare): " . $mysqli->error;
        } else {
            $stmt->bind_param("sss", $email, $email, $cleanPhone);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($user = $result->fetch_assoc()) {
                $storedHash = $user['passwort_hash'] ?? null;
                $storedPw   = $user['passwort'] ?? null;

                $ok = false;
                if ($storedHash) $ok = verify_password_flex($passwort, $storedHash);
                elseif ($storedPw) $ok = verify_password_flex($passwort, $storedPw);

                if ($ok) {
                    if (function_exists('finalize_successful_login')) {
                        $fullName = !empty($user['name']) ? $user['name'] : trim(($user['vorname'] ?? '') . ' ' . ($user['nachname'] ?? ''));
                        finalize_successful_login($mysqli, (int)$user['id'], ($user['rolle'] ?? 'benutzer'), $fullName, $user['email'] ?? null);
                    }
                    set_login_session($user);
                    mark_login_and_event($mysqli, (int)$user['id']);
                    do_redirect_after_login($_SESSION['rolle']);
                } else {
                    $error = "❌ Falsches Passwort!";
                }
            } else {
                $error = "❌ Benutzer nicht gefunden!";
            }
            $stmt->close();
        }
    }
}

/* ---------- AB HIER: AUSGABE (Header/Nav, Formular) ---------- */

require_once __DIR__ . "/includes/header.php";
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . "/includes/nav_dispatch.php";
} else {
    require_once __DIR__ . "/includes/nav_public.php";
}
?>
<main style="max-width:520px;margin:24px auto;padding:0 12px;">
  <div class="login-container" style="display:flex;justify-content:center;align-items:center;min-height:65vh;">
    <div class="login-card" style="background:#fff;padding:30px;border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,0.1);width:100%;max-width:420px;text-align:center;">
      <h2 style="margin-bottom:20px;color:#2c3e50;">Login</h2>

      <?php if ($error): ?>
        <div class="error" style="color:#b00020;margin-bottom:15px;font-size:14px;border:1px solid #f3c2c2;background:#ffecec;padding:8px;border-radius:6px;">
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="on">
        <input type="text" name="email" placeholder="E-Mail oder Telefonnummer" required autocomplete="username"
               style="width:100%;padding:12px;margin-bottom:12px;border:1px solid #ccc;border-radius:6px;font-size:15px;">
        <input type="password" name="passwort" placeholder="Passwort" required autocomplete="current-password"
               style="width:100%;padding:12px;margin-bottom:12px;border:1px solid #ccc;border-radius:6px;font-size:15px;">
        <button type="submit"
                style="width:100%;padding:12px;background:#1abc9c;border:none;border-radius:6px;color:#fff;font-size:16px;font-weight:bold;cursor:pointer;">
          Einloggen
        </button>
      </form>

      <!-- Registrierung deaktiviert -->
      <?php if (false): ?>
      <p style="margin-top:15px; font-size:14px;">
        Noch kein Konto? <a href="register.php">Jetzt registrieren</a>
      </p>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
