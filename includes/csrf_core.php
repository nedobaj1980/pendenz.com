<?php
// includes/csrf_core.php
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * CSRF-Token je Namespace (z.B. 'core', 'benutzer', 'pendenzen')
 */
function csrf_token_ns(string $ns = 'core'): string {
  $key = "csrf_token_$ns";
  if (empty($_SESSION[$key])) {
    $_SESSION[$key] = bin2hex(random_bytes(32));
  }
  return $_SESSION[$key];
}

function csrf_input_ns(string $ns = 'core', string $inputName = 'csrf'): string {
  $t = csrf_token_ns($ns);
  return '<input type="hidden" name="'.htmlspecialchars($inputName, ENT_QUOTES).'" value="'.htmlspecialchars($t, ENT_QUOTES).'">';
}

function csrf_validate_or_throw_ns(string $ns, ?string $token): void {
  $key = "csrf_token_$ns";
  $ok = $token && isset($_SESSION[$key]) && hash_equals($_SESSION[$key], $token);
  if (!$ok) throw new Exception('Ungültiges CSRF-Token.');
  // Optional: Token-Rotation
  // unset($_SESSION[$key]);
}
