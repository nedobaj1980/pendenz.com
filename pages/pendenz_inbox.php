<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$rows = $mysqli->query("SELECT * FROM notifications WHERE ref_type='pendenz' ORDER BY created_at DESC LIMIT 200");

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>
<div class="container">
  <header class="hero hero-teal" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Eingänge (Pendenzen)</h1>
    <a class="btn btn-head" href="pendenz_inbox.php">Neu laden</a>
  </header>

  <div class="card">
    <table class="table">
      <thead><tr><th>Zeit</th><th>Typ</th><th>Nachricht</th><th>Pendenz</th><th>Status</th><th>Aktion</th></tr></thead>
      <tbody>
        <?php while($n = $rows->fetch_assoc()): ?>
          <?php
            $pid = (int)$n['ref_id'];
            $pQ  = $mysqli->query("SELECT id, titel, status_workflow FROM pendenzen WHERE id=".$pid." LIMIT 1");
            $p   = $pQ ? $pQ->fetch_assoc() : null;
          ?>
          <tr>
            <td><?= htmlspecialchars($n['created_at']) ?></td>
            <td><?= htmlspecialchars($n['type']) ?></td>
            <td><?= htmlspecialchars($n['message']) ?></td>
            <td>#<?= $pid ?> — <?= htmlspecialchars($p['titel'] ?? '-') ?></td>
            <td><?= htmlspecialchars($p['status_workflow'] ?? '-') ?></td>
            <td><a class="btn btn-small" href="pendenz_review.php?id=<?= $pid ?>">Prüfen</a></td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
