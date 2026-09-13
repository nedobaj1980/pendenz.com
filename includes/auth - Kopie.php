<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* =========================
   Pfad-/URL-Helper
   ========================= */
function _in_pages_dir(): bool {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    return (strpos($sn, '/pages/') !== false);
}
function _login_url(): string {
    return _in_pages_dir() ? '../login.php' : 'login.php';
}
/** ermittelt das Projekt-Basispräfix (z.B. "/pendenz.com") */
function _site_prefix(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    return (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php')
        ? '/pendenz.com'
        : '';
}

/* =========================
   Utilities
   ========================= */
function is_https_request(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '80') === '443');
}
function regenerate_session_id(): void {
    if (!headers_sent()) session_regenerate_id(true);
}
/** nur „menschliche“ Ziele erlauben: gleiche App, kein /api/, keine absoluten URLs */
function _sanitize_redirect_target(?string $url): ?string {
    if (!$url) return null;
    // keine absoluten fremden URLs
    if (preg_match('~^https?://~i', $url)) return null;

    $base = _site_prefix();
    if ($url === $base) return $url;
    if (strpos($url, $base . '/') !== 0) return null;         // muss in der App liegen
    if (strpos($url, $base . '/api/') === 0) return null;     // keine API-Endpoints
    return $url;
}

/* =========================
   Aktueller User / Rollen
   ========================= */
function current_user(): array {
    $realRole = $_SESSION['rolle'] ?? 'gast';
    $simRole  = ($realRole === 'superadmin' && !empty($_SESSION['simulate_role'])) ? $_SESSION['simulate_role'] : null;
    $simId    = ($realRole === 'superadmin' && !empty($_SESSION['simulate_user_id'])) ? (int)$_SESSION['simulate_user_id'] : null;
    
    return [
        'id'    => $simId   ?? ($_SESSION['user_id'] ?? null),
        'name'  => ($_SESSION['simulate_name'] ?? ($_SESSION['name'] ?? null)),
        'rolle' => $simRole ?: $realRole,
        'email' => $_SESSION['email'] ?? null,
    ];
}
function current_user_id() { 
    $realRole = $_SESSION['rolle'] ?? 'gast';
    if ($realRole === 'superadmin' && !empty($_SESSION['simulate_user_id'])) return (int)$_SESSION['simulate_user_id'];
    return $_SESSION['user_id'] ?? null; 
}
function current_role()    { 
    $realRole = $_SESSION['rolle'] ?? 'gast';
    if ($realRole === 'superadmin' && !empty($_SESSION['simulate_role'])) return $_SESSION['simulate_role'];
    return $_SESSION['rolle'] ?? 'gast'; 
}
function is_superadmin()   { return current_role() === 'superadmin'; }
function is_admin()        { return in_array(current_role(), ['admin','superadmin'], true); }
function is_logged_in(): bool { 
    // Im Simulationsmodus (Superadmin simuliert was anderes) gilt man als "logged in"
    if (isset($_SESSION['rolle']) && $_SESSION['rolle'] === 'superadmin' && !empty($_SESSION['simulate_role'])) return true;
    return isset($_SESSION['user_id'], $_SESSION['rolle']); 
}

/* =========================
   Session setzen
   ========================= */
function set_login_session(int $user_id, string $name, string $rolle, ?string $email=null): void {
    regenerate_session_id();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name']    = $name;
    $_SESSION['rolle']   = $rolle;
    if ($email !== null) $_SESSION['email'] = $email;
}

/* =========================
   Login erzwingen
   ========================= */
function require_login(): void {
    if (is_logged_in()) {
        // Sicherheits-Check: Ist der Benutzer gesperrt?
        global $mysqli;
        if (isset($mysqli) && isset($_SESSION['user_id'])) {
            $uid = (int)$_SESSION['user_id'];
            $res = $mysqli->query("SELECT is_blocked FROM benutzer WHERE id = $uid");
            if ($res && $row = $res->fetch_assoc()) {
                if ((int)$row['is_blocked'] === 1) {
                    // Rauswurf
                    $_SESSION = [];
                    if (ini_get("session.use_cookies")) {
                        $p = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
                    }
                    session_destroy();
                    $loginUrl = _login_url();
                    header("Location: $loginUrl?error=blocked");
                    exit;
                }
            }
        }
        return;
    }

    // Nur GET-Requests als Rückkehrziel vormerken und nur "menschliche" Ziele speichern
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if ($uri) {
            $candidate = _sanitize_redirect_target($uri);
            if ($candidate) {
                $_SESSION['redirect_after_login'] = $candidate;
            } else {
                unset($_SESSION['redirect_after_login']);
            }
        }
    }

    header('Location: ' . _login_url());
    exit;
}

/* =========================
   Rollen-Gate
   ========================= */
function require_role($allowed_roles): void {
    if (!is_logged_in()) require_login();
    $role = $_SESSION['rolle'] ?? 'gast';
    if (is_string($allowed_roles)) $allowed_roles = [$allowed_roles];
    if (!in_array($role, $allowed_roles, true)) {
        http_response_code(403);
        echo 'Zugriff verweigert.';
        exit;
    }
}

/* =========================
   Routing nach Login
   ========================= */
if (!function_exists('route_after_login')) {
    function route_after_login(string $rolle): string {
        // APP_URL_BASE wird unterstützt – sonst automatisch erkannt
        $base = defined('APP_URL_BASE') ? rtrim(APP_URL_BASE, '/') : _site_prefix();
        switch ($rolle) {
            case 'superadmin': return $base . "/index_superadmin.php";
            case 'admin':      return $base . "/index_admin.php";
            case 'benutzer':   return $base . "/index_private.php";
            default:           return $base . "/index_public.php";
        }
    }
}
/** nutzt ggf. gespeichertes Ziel, aber NIE /api/ */
function redirect_after_login_or_default(string $rolle): void {
    $stored = $_SESSION['redirect_after_login'] ?? null;
    unset($_SESSION['redirect_after_login']);
    $safe = _sanitize_redirect_target($stored);
    $ziel = $safe ?: route_after_login($rolle);
    header("Location: " . $ziel);
    exit;
}

/* =========================
   Events & Login-Timestamps
   ========================= */
function log_user_event(mysqli $db, int $user_id, string $type, array $meta = []): void {
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
    if ($stmt = $db->prepare("INSERT INTO user_events (user_id, type, meta) VALUES (?, ?, ?)")) {
        $stmt->bind_param("iss", $user_id, $type, $json);
        $stmt->execute(); $stmt->close();
    }
}
function update_login_timestamps(mysqli $db, int $user_id): void {
    if ($upd = $db->prepare("UPDATE benutzer SET last_login_at=NOW(), first_login_at=IFNULL(first_login_at, NOW()) WHERE id=?")) {
        $upd->bind_param("i", $user_id);
        $upd->execute(); $upd->close();
    } else {
        if ($stmt2 = $db->prepare("UPDATE benutzer SET letzter_login=NOW() WHERE id=?")) {
            $stmt2->bind_param("i", $user_id);
            $stmt2->execute(); $stmt2->close();
        }
    }
}
/**
 * Vollständiger Login-Abschluss:
 * - Session setzen
 * - Timestamps/Events
 * - sicherer Redirect (nie /api/)
 *
 * Du kannst diesen Helper direkt aus login.php aufrufen,
 * dann brauchst du dort keinen separaten Redirect.
 */
function finalize_successful_login(mysqli $db, int $user_id, string $rolle, string $name, ?string $email=null): void {
    set_login_session($user_id, $name, $rolle, $email);
    update_login_timestamps($db, $user_id);
    log_user_event($db, $user_id, 'login');
    redirect_after_login_or_default($rolle);
}

/* =========================
   Projekt-Berechtigungen
   ========================= */
function is_project_member(mysqli $db, int $projekt_id, int $user_id): bool {
  $stmt = $db->prepare("SELECT 1 FROM projekt_mitglieder WHERE projekt_id=? AND benutzer_id=? LIMIT 1");
  $stmt->bind_param("ii", $projekt_id, $user_id);
  $stmt->execute();
  return (bool)$stmt->get_result()->fetch_row();
}
function project_role(mysqli $db, int $projekt_id, int $user_id): ?string {
  $stmt = $db->prepare("SELECT rolle FROM projekt_mitglieder WHERE projekt_id=? AND benutzer_id=? LIMIT 1");
  $stmt->bind_param("ii", $projekt_id, $user_id);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  return $r['rolle'] ?? null;
}
function can_view_pendenz(mysqli $db, array $p, int $user_id): bool {
  if (is_superadmin()) return true;
  
  // Fetch user details for isolation
  $uRes = $db->query("SELECT rolle, business_type, wohnung_id FROM benutzer WHERE id=$user_id");
  $uInfo = $uRes ? $uRes->fetch_assoc() : null;
  if (!$uInfo) return false;

  // 1. Mieter-Isolation
  if ($uInfo['business_type'] === 'mieter') {
      return (int)($p['wohnung_id'] ?? 0) === (int)($uInfo['wohnung_id'] ?? -1);
  }

  // 2. Unternehmer-Isolation (Handwerker)
  if ($uInfo['business_type'] === 'handwerker') {
      $upRes = $db->query("SELECT bkp_id FROM unternehmer_projekte WHERE benutzer_id=$user_id AND projekt_id=".(int)$p['projekt_id']);
      $up = $upRes ? $upRes->fetch_assoc() : null;
      if ($up) {
          // Falls BKP eingeschränkt -> nur diese Gattung sehen
          if ($up['bkp_id'] && (int)($p['bkp_id'] ?? 0) !== (int)$up['bkp_id']) return false;
          return true;
      }
      return false; // Keine Zuweisung zu diesem Projekt
  }

  // Standard-Logik
  if ((int)($p['erstellt_von'] ?? 0) === $user_id) return true;
  if ((int)($p['zustaendig_id'] ?? 0) === $user_id) return true;
  
  $sicht = $p['sichtbarkeit'] ?? 'projekt';
  if ($sicht === 'projekt' && is_project_member($db, (int)$p['projekt_id'], $user_id)) return true;
  
  $stmt = $db->prepare("SELECT can_view FROM pendenz_acl WHERE pendenz_id=? AND benutzer_id=?");
  $stmt->bind_param("ii", $p['id'], $user_id);
  $stmt->execute();
  $acl = $stmt->get_result()->fetch_assoc();
  return (bool)($acl['can_view'] ?? 0);
}

function can_edit_pendenz(mysqli $db, array $p, int $user_id): bool {
  if (is_superadmin()) return true;
  
  $uRes = $db->query("SELECT business_type FROM benutzer WHERE id=$user_id");
  $uType = $uRes ? $uRes->fetch_assoc()['business_type'] : 'standard';

  // Mieter darf grundsätzlich nur lesen (Pendenzen ansehen)
  if ($uType === 'mieter') return false;

  // Unternehmer darf nur eigene bearbeiten
  if ($uType === 'handwerker') {
      return (int)($p['zustaendig_id'] ?? 0) === $user_id && (int)($p['assignee_can_edit'] ?? 1) === 1;
  }

  $role = project_role($db, (int)$p['projekt_id'], $user_id);
  if (in_array($role, ['owner','manager'], true)) return true;
  if ((int)($p['erstellt_von'] ?? 0) === $user_id) return true;
  if ((int)($p['zustaendig_id'] ?? 0) === $user_id && (int)($p['assignee_can_edit'] ?? 1) === 1) return true;
  
  $stmt = $db->prepare("SELECT can_edit FROM pendenz_acl WHERE pendenz_id=? AND benutzer_id=?");
  $stmt->bind_param("ii", $p['id'], $user_id);
  $stmt->execute();
  $acl = $stmt->get_result()->fetch_assoc();
  return (bool)($acl['can_edit'] ?? 0);
}
