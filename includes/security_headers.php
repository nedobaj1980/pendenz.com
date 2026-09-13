<?php
// includes/security_headers.php
// Strenge, praxisnahe Security-Header + CSP (kein Inline-CSS/JS erlaubt)
if (!headers_sent()) {
  header("X-Frame-Options: SAMEORIGIN");
  header("X-Content-Type-Options: nosniff");
  header("Referrer-Policy: strict-origin-when-cross-origin");
  header("Permissions-Policy: geolocation=(), camera=(), microphone=(), usb=(), payment=()");

  $csp = [
    "default-src 'self'",
    "script-src 'self'",          // Keine Inline-Skripte
    "style-src 'self'",           // Kein Inline-CSS -> alles in .css-Dateien
    "img-src 'self' data:",       // Bilder lokal + data: erlaubt
    "font-src 'self' data:",      // Webfonts lokal + data:
    "connect-src 'self'",         // XHR/Fetch nur zu eigener Domain
    "frame-ancestors 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "upgrade-insecure-requests",
  ];
  header("Content-Security-Policy: " . implode('; ', $csp));
}
