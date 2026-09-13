<?php
// pages/projekte.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();
require_once __DIR__ . '/../includes/functions.php'; // site_prefix(), url(), page_url(), best_image_url(), handle_upload(), ...
/* ===== Layout / Nav (erst ab hier wird ausgegeben) ===== */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

$PREFIX = site_prefix();

$title = "Benachrichtigungen";
?>




<main class="container" style="max-width:960px;margin:0 auto;padding:16px;">
  <header class="page-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0;">Benachrichtigungen</h1>
      <p class="muted" style="margin:4px 0 0;">Letzte Ereignisse in deinem Konto</p>
    </div>
    <div class="actions" style="display:flex;gap:8px;align-items:center;">
      <button class="btn js-refresh" type="button" title="Aktualisieren (R)">⟲ Aktualisieren</button>
      <button class="btn btn-secondary js-mark-all" type="button">Alle als gelesen</button>
    </div>
  </header>

  <section class="notify-toolbar" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;">
    <div class="search" style="flex:1;min-width:240px;">
      <input id="notifySearch" class="input" type="search" placeholder="In Benachrichtigungen suchen…" autocomplete="off">
    </div>
    <div class="view-toggles" style="display:flex;gap:6px;">
      <button class="btn btn-ghost is-active" data-view="all" type="button">Alle</button>
      <button class="btn btn-ghost" data-view="link" type="button">Mit Link</button>
      <button class="btn btn-ghost" data-view="nolink" type="button">Ohne Link</button>
    </div>
  </section>

  <div id="notifications-app"
       data-endpoint-list="<?= htmlspecialchars(url('api/notifications_list.php')) ?>"
       data-endpoint-mark="<?= htmlspecialchars(url('api/notifications_mark_seen.php')) ?>">
    <div class="loading">Lade…</div>
  </div>

  <footer class="page-foot" style="margin-top:12px;color:#6b7280;font-size:13px;">
    Tipp: Drücke <kbd>R</kbd> zum Aktualisieren.
  </footer>
</main>

<link rel="stylesheet" href="<?= htmlspecialchars(asset_url('css/notifications.css')) ?>">
<script src="<?= htmlspecialchars(asset_url('js/notifications_page.js')) ?>" defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
