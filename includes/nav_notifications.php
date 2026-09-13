<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (defined('NAV_NOTIFICATIONS_LOADED')) return;
define('NAV_NOTIFICATIONS_LOADED', true);

if (!isset($mysqli) || !($mysqli instanceof mysqli)) require_once __DIR__ . '/../config.php';
if (!function_exists('url')) {
  function url(string $file): string {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = (strpos($sn, '/pendenz.com/') === 0 || $sn === '/pendenz.com' || $sn === '/pendenz.com/index.php')
      ? '/pendenz.com' : '';
    return rtrim($base, '/') . '/' . ltrim($file, '/');
  }
}
if (!function_exists('page_url')) { function page_url(string $f): string { return url('pages/'.$f); } }

$userId = (int)($_SESSION['user_id'] ?? 0);
$unseen_count = 0;

if ($userId > 0 && $mysqli instanceof mysqli) {
  $stmt = $mysqli->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND seen_at IS NULL");
  $stmt->bind_param('i', $userId);
  $stmt->execute(); $stmt->bind_result($unseen_count); $stmt->fetch(); $stmt->close();
}

$endpointList = url('api/notifications_list.php');
$endpointMark = url('api/notifications_mark_seen.php');
$pageLink     = page_url('benachrichtigungen.php');
?>

<li class="nav-item nav-notify">
  <button
    class="nav-bell js-notify-toggle"
    <?php if ($userId > 0): ?>
      data-notify-list="<?= htmlspecialchars($endpointList) ?>"
      data-notify-mark="<?= htmlspecialchars($endpointMark) ?>"
      data-notify-page="<?= htmlspecialchars($pageLink) ?>"
      aria-haspopup="true" aria-expanded="false"
    <?php else: ?>
      aria-disabled="true" title="Bitte einloggen"
    <?php endif; ?>
  >
    🔔
    <span class="badge js-notify-badge<?= ($unseen_count === 0 ? ' is-zero' : '') ?>">
      <?= (int)$unseen_count ?>
    </span>
  </button>
</li>
