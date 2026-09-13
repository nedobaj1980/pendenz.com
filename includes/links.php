<?php
// includes/links.php
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
if (!function_exists('site_prefix')) {
  function site_prefix(){ return '/pendenz.com/'; } // Fallback
}

/* ===== Kanonische URLs für Entitäten ===== */
function url_benutzer(int $id): string {
  return site_prefix().'pages/benutzer.php?edit='.$id;
}
function url_projekt(int $id): string {
  return site_prefix().'pages/projekte.php?edit='.$id;
}
function url_ve(int $id): string {
  // Falls deine VE-Seite anders heißt -> hier anpassen
  return site_prefix().'pages/vermietungseinheiten.php?edit='.$id;
}
function url_liegenschaft(int $id): string {
  // Falls eigene Seite existiert
  return site_prefix().'pages/liegenschaften.php?edit='.$id;
}
function url_konto_tool(array $filters=[]): string {
  $base = site_prefix().'tools/konto_verwaltung/index.php';
  if (!$filters) return $base;
  return $base.'?'.http_build_query($filters);
}
function url_mieterspiegel_for_user(int $benutzer_id): string {
  // Wenn du eine eigene Seite hast:
  return site_prefix().'pages/mieterspiegel.php?benutzer_id='.$benutzer_id;
}
function url_mieterspiegel_for_proj(int $projekt_id): string {
  return site_prefix().'pages/mieterspiegel.php?projekt_id='.$projekt_id;
}
function url_fs(string $fs_rel_path): string {
  // Dein Ordner-Browser; ggf. Pfad anpassen
  return site_prefix().'pages/fs_browser.php?path='.urlencode($fs_rel_path);
}

/* ===== Link-Badges (einheitliche Optik) ===== */
function link_chip(string $label, string $href, string $emoji='🔗', array $attrs=[]): string {
  $a=' class="chip-link"';
  foreach ($attrs as $k=>$v){ $a.=' '.h($k).'="'.h($v).'"'; }
  return '<a href="'.h($href).'"'.$a.'>'.$emoji.' '.h($label).'</a>';
}

/* ===== Stil (einmalig einbinden) ===== */
function chips_style_once(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  echo '<style>
  .chip-link{display:inline-flex;align-items:center;gap:6px;margin:2px 6px 2px 0;padding:6px 10px;border:1px solid #e2e8f0;border-radius:999px;background:#fff;text-decoration:none;color:#0f172a;font-size:13px}
  .chip-link:hover{background:#f8fafc}
  </style>';
}
