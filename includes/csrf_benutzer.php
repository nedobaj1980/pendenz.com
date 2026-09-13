<?php
// includes/csrf_benutzer.php
require_once __DIR__ . '/csrf_core.php';

// Einheitlicher Namespace + eigener Input-Name (vermeidet Kollisionen auf Seiten mit mehreren Formularen)
const BENUTZER_CSRF_NS   = 'benutzer';
const BENUTZER_CSRF_NAME = 'csrf_benutzer';

function benutzer_csrf_token(): string {
  return csrf_token_ns(BENUTZER_CSRF_NS);
}

function benutzer_csrf_input(): string {
  return csrf_input_ns(BENUTZER_CSRF_NS, BENUTZER_CSRF_NAME);
}

function benutzer_csrf_validate_or_throw(?string $token): void {
  csrf_validate_or_throw_ns(BENUTZER_CSRF_NS, $token);
}
