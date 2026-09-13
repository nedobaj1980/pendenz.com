<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* ===== Helper / Fallbacks ===== */
$fn = __DIR__ . '/functions.php';
if (is_file($fn)) require_once $fn;

if (!function_exists('e')) {
  function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('site_prefix')) {
  function site_prefix(): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    return ($sn === '/pendenz.com' || $sn === '/pendenz.com/index.php' || strpos($sn, '/pendenz.com/') === 0) ? '/pendenz.com/' : '/';
  }
}
if (!function_exists('base_url')) {
  function base_url(string $path = ''): string {
    $base = rtrim(site_prefix(), '/'); // '/pendenz.com' oder ''
    return $path === '' ? ($base === '' ? '/' : $base) : $base . '/' . ltrim($path, '/');
  }
}
if (!function_exists('url')) {
  function url(string $path = ''): string { return site_prefix() . ltrim($path, '/'); }
}
if (!function_exists('roleRank')) {
  function roleRank($r){ return ['gast'=>0,'benutzer'=>1,'projektleiter'=>2,'admin'=>3,'superadmin'=>4][$r] ?? 0; }
}
if (!function_exists('user_has_role')) {
  function user_has_role(string $minRole): bool {
    $eff = $_SESSION['simulate_role'] ?? ($_SESSION['rolle'] ?? 'gast');
    return roleRank($eff) >= roleRank($minRole);
  }
}
if (!function_exists('current_user')) {
  function current_user(): array {
    return [
      'id'    => (int)($_SESSION['user_id'] ?? 0),
      'name'  => (string)($_SESSION['user_name'] ?? ''),
      'email' => (string)($_SESSION['user_email'] ?? ''),
      'role'  => (string)($_SESSION['simulate_role'] ?? ($_SESSION['rolle'] ?? 'gast')),
    ];
  }
}

/* Aktive-Status-Helfer */
$__uri = $_SERVER['REQUEST_URI'] ?? '/';
if (!function_exists('nav_is_active')) {
  function nav_is_active(string $needle, string $uri): bool {
    return strpos($uri, $needle) !== false;
  }
}

/* projekt_id aus Kontext anhängen (optional) */
$projektId = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_SESSION['current_project_id'] ?? 0);
$withPid = function (string $href) use ($projektId): string {
  if ($projektId <= 0) return $href;
  return (strpos($href, '?') === false) ? ($href . '?projekt_id=' . $projektId) : ($href . '&projekt_id=' . $projektId);
};

/* ===== Menüdefinition ===== */
$u = current_user();
$items = [];

// Central menu defined here, used everywhere
$items[] = ['Dashboard',            'pages/dashboard.php',         'layout-dashboard', 'any'];
$items[] = ['Pendenzen',            'pages/pendenzen_liste.php',   'check-square',     'any'];
$items[] = ['Terminprogramm',      'pages/terminprogramm.php',    'calendar',         'any'];
$items[] = ['Neue Pendenz',         'pages/pendenzen.php?expand=1#formTopMarker', 'plus-circle',      'any'];
$items[] = ['Projekte',             'pages/projekte.php',          'folder',           'projektleiter'];
$items[] = ['Vorlagen (Ordner)',    'pages/ordner_vorlagen.php',   'layers',           'projektleiter'];
$items[] = ['Pendenz-Vorlagen',     'pages/pendenz_vorlagen.php',  'file-plus',        'projektleiter'];
$items[] = ['Teams',                'pages/teams.php',             'users',            'admin'];
$items[] = ['Benutzer',             'pages/benutzer.php',          'user',             'admin'];
$items[] = ['Tabellen',             'pages/tables.php',            'table',            'projektleiter'];
$items[] = ['Einstellungen',        'pages/settings.php',          'settings',         'admin'];

/* ===== Render ===== */
echo '<ul class="nav">';
foreach ($items as $it) {
  [$label, $href, $icon, $minrole] = $it;

  if ($minrole !== 'any' && !user_has_role($minrole)) {
    continue;
  }

  // vollständige URL + optional projekt_id
  $url = base_url($href);
  $url = $withPid($url);

  $active = nav_is_active('/' . ltrim($href,'/'), $__uri);
  $cls = $active ? ' class="active"' : '';
  $aria = $active ? ' aria-current="page"' : '';

  // Icon-Platz: falls du Lucide nutzt, kannst du via JS <i data-icon="..."></i> ersetzen
  $iconHtml = $icon ? '<i class="nav-icon" data-icon="' . e($icon) . '"></i>' : '';

  echo '<li' . $cls . '><a href="' . e($url) . '"' . $aria . '>' . $iconHtml . '<span>' . e($label) . '</span></a></li>';
}
echo '</ul>';
