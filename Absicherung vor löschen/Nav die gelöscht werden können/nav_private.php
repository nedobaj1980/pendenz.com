<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$__uri = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('nav_active')) {
  function nav_active(string $needle, string $uri): string {
    return (strpos($uri, $needle) !== false) ? ' class="active"' : '';
  }
}

/* DB laden, falls nicht vorhanden */
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  require_once __DIR__ . '/../config.php';
}

/* Rollenranking für require_role */
if (!function_exists('roleRank')) {
  function roleRank($r){ return ['gast'=>0,'benutzer'=>1,'admin'=>2,'superadmin'=>3][$r] ?? 0; }
}
$role = $_SESSION['rolle'] ?? 'benutzer';
$rr   = roleRank($role);

/* ggf. aktuelles Projekt (per GET oder Session) */
$projektId = 0;
if (isset($_GET['projekt_id'])) {
  $projektId = (int)$_GET['projekt_id'];
} elseif (!empty($_SESSION['current_project_id'])) {
  $projektId = (int)$_SESSION['current_project_id'];
}

/* Globale SmartTable-Punkte */
$smartGlobal = [];
if ($mysqli instanceof mysqli) {
  $stmt = $mysqli->prepare("
    SELECT nav_label, route_path
    FROM smarttable_tables
    WHERE show_in_nav='global'
      AND COALESCE(nav_label,'') <> ''
      AND (
        CASE require_role
          WHEN 'superadmin' THEN ? >= 3
          WHEN 'admin'      THEN ? >= 2
          WHEN 'benutzer'   THEN ? >= 1
          ELSE 1
        END
      )
    ORDER BY nav_order, nav_label
  ");
  $stmt->bind_param('iii',$rr,$rr,$rr);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) $smartGlobal[] = $row;
  $stmt->close();
}

/* Projekt-spezifische Punkte (nur wenn Projekt-Kontext vorhanden) */
$smartProject = [];
if ($projektId > 0 && $mysqli instanceof mysqli) {
  $stmt = $mysqli->prepare("
    SELECT st.nav_label, st.route_path
    FROM smarttable_project_prefs pp
    JOIN smarttable_tables st ON st.id = pp.table_id
    WHERE pp.project_id = ?
      AND pp.is_enabled = 1
      AND pp.show_in_project_nav = 1
      AND st.show_in_nav IN ('project','global')
      AND COALESCE(st.nav_label,'') <> ''
      AND (
        CASE st.require_role
          WHEN 'superadmin' THEN ? >= 3
          WHEN 'admin'      THEN ? >= 2
          WHEN 'benutzer'   THEN ? >= 1
          ELSE 1
        END
      )
    ORDER BY st.nav_order, st.nav_label
  ");
  $stmt->bind_param('iiii',$projektId,$rr,$rr,$rr);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) $smartProject[] = $row;
  $stmt->close();
}

/* Doppelte Routen gegenüber statischen Punkten filtern */
$normalize = function(string $p): string {
  $path = parse_url($p, PHP_URL_PATH) ?? $p;
  return preg_replace('#/+#','/', rtrim($path,'/'));
};
$staticPaths = [
  $normalize(page_url('projekte.php')),
  $normalize(page_url('pendenzen.php')),
];
$smartGlobal = array_values(array_filter($smartGlobal, fn($it)=>!in_array($normalize($it['route_path']), $staticPaths, true)));
$smartProject = array_values(array_filter($smartProject, fn($it)=>!in_array($normalize($it['route_path']), $staticPaths, true)));

/* Hilfsfunktion: projekt_id an URL anhängen */
$withPid = function(string $href) use ($projektId): string {
  if ($projektId <= 0) return $href;
  return strpos($href,'?') === false ? ($href.'?projekt_id='.$projektId) : ($href.'&projekt_id='.$projektId);
};
?>
<nav class="main-nav">
  <a class="brand" href="<?= htmlspecialchars(url('index_private.php')) ?>">
    <picture>
      <source srcset="<?= htmlspecialchars(brand_url('logo-mark.webp')) ?>" type="image/webp">
      <img src="<?= htmlspecialchars(brand_url('logo-mark.png')) ?>" alt="pendenz.com" width="28" height="28" loading="lazy">
    </picture>
    <span>pendenz.com</span>
  </a>
  <ul class="menu">
    <li<?= nav_active('/projekte.php', $__uri) ?>><a href="<?= htmlspecialchars(page_url('projekte.php')) ?>">Projekte</a></li>
    <li<?= nav_active('/pendenzen', $__uri) ?>><a href="<?= htmlspecialchars(page_url('pendenzen.php')) ?>">Pendenzen</a></li>

    <?php /* Globale SmartTable-Links (zusätzlich) */ ?>
    <?php foreach ($smartGlobal as $item): ?>
      <li<?= nav_active($item['route_path'], $__uri) ?>>
        <a href="<?= htmlspecialchars($withPid($item['route_path'])) ?>"><?= htmlspecialchars($item['nav_label']) ?></a>
      </li>
    <?php endforeach; ?>

    <?php /* Projekt-Menüeinträge, wenn Projekt-Kontext existiert */ ?>
    <?php foreach ($smartProject as $item): ?>
      <li<?= nav_active($item['route_path'], $__uri) ?>>
        <a href="<?= htmlspecialchars($withPid($item['route_path'])) ?>"><?= htmlspecialchars($item['nav_label']) ?></a>
      </li>
    <?php endforeach; ?>
<?php if (file_exists(__DIR__.'/nav_notifications.php')) require __DIR__ . '/nav_notifications.php'; ?>

    <li class="spacer"></li>
    <li><a href="<?= htmlspecialchars(url('logout.php')) ?>">Logout</a></li>
  </ul>
</nav>
