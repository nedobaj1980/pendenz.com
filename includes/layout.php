<?php
require_once __DIR__.'/bootstrap.php';

function render_header(string $active = '', bool $show_sim = false): void {
  $items = [
    'dashboard' => ['label'=>'Dashboard',        'href'=>url('index_superadmin.php')],
    'benutzer'  => ['label'=>'Benutzer',         'href'=>url('pages/benutzer.php')],
    'projekte'  => ['label'=>'Projekte',         'href'=>url('pages/projekte.php')],
    'pendenzen' => ['label'=>'Pendenzen',        'href'=>url('pages/pendenzen.php')],
    'listen'    => ['label'=>'Pendenzen-Listen', 'href'=>url('pages/pendenzen_liste.php')],
    'chat'      => ['label'=>'Chat',             'href'=>url('pages/chat.php')],
    'konto'     => ['label'=>'Konto-Verwaltung', 'href'=>url('pages/konto.php')],
    'ki'        => ['label'=>'KI-Logs',          'href'=>url('pages/ai_logs.php')],
  ];
  ?>
<!doctype html><html lang="de"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?= asset_url('app.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('nav.css') ?>">
  <title>pendenz.com</title>
</head><body>
<header class="topbar">
  <a class="brand" href="<?= url('index_superadmin.php') ?>">
    <img class="brand-logo" src="<?= brand_url('logo.svg') ?>" alt="pendenz.com">
    <span class="brand-text">pendenz.com</span>
  </a>
  <nav class="mainnav">
    <?php foreach ($items as $key=>$it): ?>
      <a class="navlink <?= $active===$key?'active':'' ?>" href="<?= $it['href'] ?>"><?= htmlspecialchars($it['label']) ?></a>
    <?php endforeach; ?>
    <a class="navlink" href="<?= url('pages/logout.php') ?>">Logout</a>
  </nav>
</header>

<?php if ($show_sim): ?>
<div class="simbar">
  <span>🧪 Simulation starten:</span>
  <a href="<?= url('simulate.php?as=guest') ?>">Als Gast</a>
  <span class="sep">|</span>
  <a href="<?= url('simulate.php?as=user') ?>">Als Benutzer</a>
  <span class="sep">|</span>
  <a href="<?= url('simulate.php?as=admin') ?>">Als Admin</a>
</div>
<?php endif; ?>

<main class="page">
<?php }

function render_footer(): void { ?>
</main>
</body></html>
<?php }
