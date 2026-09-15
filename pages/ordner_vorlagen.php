<?php
declare(strict_types=1);

// pages/ordner_vorlagen.php - Verwalte globale Ordner-Muster (Templates)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();
require_role(['admin', 'superadmin']);

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'new_tpl') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $error = 'Bitte einen Namen für das Muster eingeben.';
        } elseif (mb_strlen($name, 'UTF-8') > 120) {
            $error = 'Der Mustername ist zu lang.';
        } else {
            $stmt = $mysqli->prepare('INSERT INTO ordner_vorlagen (name) VALUES (?)');
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->close();
            $flash = "Muster «{$name}» angelegt.";
        }
    }

    if ($action === 'add_node') {
        $tplId = (int) ($_POST['tpl_id'] ?? 0);
        $folder = trim((string) ($_POST['folder_name'] ?? ''));
        $folder = str_replace('\\', '/', $folder);
        $folder = trim($folder, "/ \t\n\r\0\x0B");

        if ($tplId <= 0 || $folder === '') {
            $error = 'Muster oder Ordnername fehlt.';
        } elseif (mb_strlen($folder, 'UTF-8') > 500) {
            $error = 'Der Ordnerpfad ist zu lang.';
        } elseif (str_contains($folder, '../') || str_contains($folder, '..\\')) {
            $error = 'Relative Pfadwechsel sind nicht erlaubt.';
        } else {
            $stmt = $mysqli->prepare('SELECT 1 FROM ordner_vorlagen WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $tplId);
            $stmt->execute();
            $exists = (bool) $stmt->get_result()->fetch_row();
            $stmt->close();

            if (!$exists) {
                $error = 'Das gewählte Muster existiert nicht mehr.';
            } else {
                $stmt = $mysqli->prepare(
                    'INSERT INTO ordner_vorlagen_nodes (vorlage_id, rel_path, is_dir, sort)
                     VALUES (?, ?, 1, 0)'
                );
                $stmt->bind_param('is', $tplId, $folder);
                $stmt->execute();
                $stmt->close();
                $flash = 'Ordner hinzugefügt.';
            }
        }
    }

    if ($action === 'del_node') {
        $nodeId = (int) ($_POST['node_id'] ?? 0);
        if ($nodeId <= 0) {
            $error = 'Ungültiger Ordner.';
        } else {
            $stmt = $mysqli->prepare('DELETE FROM ordner_vorlagen_nodes WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $nodeId);
            $stmt->execute();
            $stmt->close();
            $flash = 'Ordner entfernt.';
        }
    }
}

$templates = $mysqli->query('SELECT id, name FROM ordner_vorlagen ORDER BY name ASC');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
  .folder-template-page { max-width: 1400px; padding-top: 20px; padding-bottom: 40px; }
  .folder-template-head { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:30px; }
  .folder-template-head h1 { margin:0; }
  .folder-template-head p { color:#64748b; margin:5px 0 0; }
  .folder-template-create { display:flex; gap:10px; align-items:center; }
  .folder-template-create input { min-width:260px; padding:10px; border-radius:8px; border:1px solid #d1d5db; }
  .tpl-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:24px; margin-top:20px; }
  .tpl-card { min-width:0; background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; display:flex; flex-direction:column; }
  .tpl-card h3 { margin:0 0 15px; font-size:20px; border-bottom:2px solid #3b82f6; display:inline-block; padding-bottom:4px; overflow-wrap:anywhere; }
  .node-list { list-style:none; padding:0; margin:15px 0; flex:1; }
  .node-item { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:8px 12px; background:#f8fafc; border-radius:8px; margin-bottom:6px; font-size:14px; }
  .node-item:hover { background:#f1f5f9; }
  .node-item span { min-width:0; overflow-wrap:anywhere; }
  .node-del { color:#ef4444; text-decoration:none; font-weight:bold; cursor:pointer; border:none; background:transparent; font-size:20px; line-height:1; min-width:36px; min-height:36px; }
  .node-add { display:flex; gap:8px; margin-top:15px; padding-top:15px; border-top:1px solid #f1f5f9; }
  .node-add input { flex:1; min-width:0; padding:9px; border-radius:6px; border:1px solid #e2e8f0; font-size:14px; }
  .folder-template-alert { padding:12px; border-radius:8px; margin-bottom:20px; font-weight:600; }
  .folder-template-alert--ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
  .folder-template-alert--error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

  @media (max-width: 768px) {
    .folder-template-page { padding-left:12px; padding-right:12px; }
    .folder-template-head { flex-direction:column; margin-bottom:20px; }
    .folder-template-create { width:100%; flex-direction:column; align-items:stretch; }
    .folder-template-create input { width:100%; min-width:0; box-sizing:border-box; font-size:16px; }
    .folder-template-create .btn { width:100%; min-height:44px; }
    .tpl-grid { grid-template-columns:1fr; gap:14px; }
    .tpl-card { padding:16px; border-radius:12px; }
    .node-add input { font-size:16px; min-height:44px; }
    .node-add .btn { min-width:48px; min-height:44px; }
  }
</style>

<div class="container folder-template-page">
  <header class="folder-template-head">
    <div>
      <h1>📦 Ordner-Muster Designer</h1>
      <p>Definiere Standard-Strukturen für Wohnungen und Einheiten.</p>
    </div>

    <form method="post" class="folder-template-create">
      <?= csrf_input() ?>
      <input type="text" name="name" maxlength="120" placeholder="Name (z.B. Standard-Wohnung)" required>
      <button type="submit" name="action" value="new_tpl" class="btn primary">➕ Muster erstellen</button>
    </form>
  </header>

  <?php if ($flash !== ''): ?>
    <div class="folder-template-alert folder-template-alert--ok"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="folder-template-alert folder-template-alert--error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="tpl-grid">
    <?php while ($tpl = $templates->fetch_assoc()): ?>
      <?php
        $tplId = (int) $tpl['id'];
        $stmtNodes = $mysqli->prepare(
            'SELECT id, vorlage_id, rel_path
             FROM ordner_vorlagen_nodes
             WHERE vorlage_id = ?
             ORDER BY sort ASC, rel_path ASC'
        );
        $stmtNodes->bind_param('i', $tplId);
        $stmtNodes->execute();
        $nodes = $stmtNodes->get_result();
        $nodeCount = $nodes->num_rows;
      ?>

      <section class="tpl-card">
        <h3><?= htmlspecialchars((string) $tpl['name'], ENT_QUOTES, 'UTF-8') ?></h3>

        <ul class="node-list">
          <?php while ($node = $nodes->fetch_assoc()): ?>
            <li class="node-item">
              <span>📁 <?= htmlspecialchars((string) ($node['rel_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
              <form method="post" style="margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="node_id" value="<?= (int) $node['id'] ?>">
                <button type="submit" name="action" value="del_node" class="node-del" aria-label="Ordner entfernen" onclick="return confirm('Ordner aus Muster entfernen?')">×</button>
              </form>
            </li>
          <?php endwhile; ?>

          <?php if ($nodeCount === 0): ?>
            <li style="color:#94a3b8; font-style:italic; padding:10px; text-align:center;">Noch keine Ordner definiert.</li>
          <?php endif; ?>
        </ul>

        <?php $stmtNodes->close(); ?>

        <form method="post" class="node-add">
          <?= csrf_input() ?>
          <input type="hidden" name="tpl_id" value="<?= $tplId ?>">
          <input type="text" name="folder_name" maxlength="500" placeholder="Neuer Ordner..." required>
          <button type="submit" name="action" value="add_node" class="btn btn-small secondary" aria-label="Ordner hinzufügen">➕</button>
        </form>
      </section>
    <?php endwhile; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
