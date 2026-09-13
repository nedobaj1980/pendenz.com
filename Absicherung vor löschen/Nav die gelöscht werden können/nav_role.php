<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';

$role = $_SESSION['rolle'] ?? 'gast';
$items = [
  ['href' => url('index_superadmin.php'),      'label' => 'Dashboard',      'min' => 'benutzer'],
  ['href' => page_url('benutzer.php'),         'label' => 'Benutzer',       'min' => 'benutzer'],
  ['href' => page_url('projekte.php'),         'label' => 'Projekte',       'min' => 'benutzer'],
  ['href' => page_url('pendenzen.php'),        'label' => 'Pendenzen',      'min' => 'benutzer'],
  ['href' => page_url('pendenzen_liste.php'),  'label' => 'Pendenzen-Listen','min' => 'benutzer'],
  ['href' => page_url('chat.php'),             'label' => 'Chat',           'min' => 'benutzer'],
  ['href' => url('tools/konto_verwaltung/index.php'), 'label' => 'Konto-Verwaltung','min'=>'admin'],
  ['href' => url('admin/ki_logs.php'),         'label' => '🤖 KI-Logs',     'min' => 'superadmin'],
];

$rank = ['gast'=>0,'benutzer'=>1,'admin'=>2,'superadmin'=>3];
$my = $rank[$role] ?? 0;

$current = $_SERVER['REQUEST_URI'] ?? '';

function nav_active_cls(string $href, string $current): string {
  $hrefPath = parse_url($href, PHP_URL_PATH) ?? $href;
  return (strpos($current, $hrefPath) !== false) ? ' class="active"' : '';
}
?>
<nav class="main-nav">
  <ul class="menu">
    <?php foreach ($items as $it):
      $minRank = $rank[$it['min']] ?? 9;
      if ($my < $minRank) continue; ?>
      <li<?= nav_active_cls($it['href'], $current) ?>><a href="<?= h($it['href']) ?>"><?= h($it['label']) ?></a></li>
    <?php endforeach; ?>
    <li class="spacer"></li>
    <?php if (!empty($_SESSION['user_id'])): ?>
      <li><a class="muted" href="<?= h(url('logout.php')) ?>">Logout</a></li>
    <?php else: ?>
      <li><a class="muted" href="<?= h(url('login.php')) ?>">Login</a></li>
    <?php endif; ?>
  </ul>
</nav>
