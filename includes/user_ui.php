<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * UI-Helfer-Datei.
 * WICHTIG: Basishilfen wie site_prefix(), asset_url(), h() sind in includes/functions.php definiert.
 * Diese Datei definiert absichtlich KEINE dieser Funktionen erneut, um Kollisionen zu vermeiden.
 *
 * Falls du später reine UI-Komponenten (Renderer) brauchst, kannst du sie hier ergänzen.
 */

// Beispiel – wenn du mal kleine UI-Renderer brauchst, nutze NUR neue Namen.
// function render_badge(string $text, string $type = 'default'): string {
//     $cls = match($type) {
//         'ok' => 'badge badge-ok',
//         'warn' => 'badge badge-warn',
//         'err' => 'badge badge-err',
//         default => 'badge',
//     };
//     return '<span class="'.h($cls).'">'.h($text).'</span>';
// }
