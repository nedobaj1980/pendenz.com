<?php
// C:\xampp\htdocs\pendenz.com\includes\csp.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csp_send_header(): string {
    $nonce = base64_encode(random_bytes(16));

    // Sehr locker für Debugging
    $csp = [
        "default-src * 'self' 'unsafe-inline' 'unsafe-eval' data: blob:",
        "script-src * 'self' 'unsafe-inline' 'unsafe-eval' data: blob: cdnjs.cloudflare.com",
        "style-src * 'self' 'unsafe-inline' cdnjs.cloudflare.com fonts.googleapis.com",
        "img-src * 'self' data: blob:",
        "font-src * 'self' data: fonts.gstatic.com",
        "connect-src * 'self'",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "form-action 'self'"
    ];

    header("Content-Security-Policy: " . implode('; ', $csp));

    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: no-referrer-when-downgrade");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-XSS-Protection: 0");

    return $nonce;
}

function csp_script_attr(string $nonce): string {
    return "nonce=\"{$nonce}\"";
}
