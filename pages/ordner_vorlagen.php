<?php
// pages/ordner_vorlagen.php - Verwalte deine Ordner-Muster (Templates)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_login();

$flash = "";

// AKTIONEN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Neues Muster anlegen
    if (isset($_POST['action']) && $_POST['action'] === 'new_tpl') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $mysqli->query("INSERT INTO ordner_vorlagen (name) VALUES ('".$mysqli->real_escape_string($name)."')");
            $flash = "✅ Muster '$name' angelegt.";
        }
    }

    // Ordner zu Muster hinzufügen
    if (isset($_POST['action']) && $_POST['action'] === 'add_node') {
        $tplId = (int)$_POST['tpl_id'];
        $folder = trim($_POST['folder_name'] ?? '');
        if ($folder) {
            $mysqli->query("INSERT INTO ordner_vorlagen_nodes (vorlage_id, rel_path, is_dir, sort) VALUES ($tplId, '".$mysqli->real_escape_string($folder)."', 1, 0)");
            $flash = "✅ Ordner hinzugefügt.";
        }
    }

    // Ordner aus Muster löschen
    if (isset($_POST['action']) && $_POST['action'] === 'del_node') {
        $nodeId = (int)$_POST['node_id'];
        $mysqli->query("DELETE FROM ordner_vorlagen_nodes WHERE id=$nodeId");
        $flash = "🗑️ Ordner entfernt.";
    }
}

// DATEN LADEN
$templates = $mysqli->query("SELECT * FROM ordner_vorlagen ORDER BY name");

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
  .tpl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 24px; margin-top:20px; }
  .tpl-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; display: flex; flex-direction: column; }
  .tpl-card h3 { margin: 0 0 15px 0; font-size: 20px; border-bottom: 2px solid #3b82f6; display: inline-block; padding-bottom: 4px; }
  .node-list { list-style: none; padding: 0; margin: 15px 0; flex: 1; }
  .node-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #f8fafc; border-radius: 8px; margin-bottom: 6px; font-size: 14px; }
  .node-item:hover { background: #f1f5f9; }
  .node-del { color: #ef4444; text-decoration: none; font-weight: bold; cursor: pointer; border: none; background: transparent; }
</style>

<div class="container" style="max-width:1400px; padding-top: 20px;">
  
  <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
    <div>
       <h1 style="margin:0;">📦 Ordner-Muster Designer</h1>
       <p style="color:#64748b; margin:5px 0 0 0;">Definiere Standard-Strukturen für deine Wohnungen und Einheiten.</p>
    </div>
    <form method="post" style="display:flex; gap:10px;">
       <input type="text" name="name" placeholder="Name (z.B. Standard-Wohnung)" style="padding:10px; border-radius:8px; border:1px solid #d1d5db;" required>
       <button type="submit" name="action" value="new_tpl" class="btn primary">➕ Muster erstellen</button>
    </form>
  </header>

  <?php if($flash): ?><div style="padding:12px; background:#f0fdf4; border-radius:8px; border:1px solid #bbf7d0; color:#166534; margin-bottom:20px; font-weight:600;"><?= $flash ?></div><?php endif; ?>

  <div class="tpl-grid">
     <?php while($tpl = $templates->fetch_assoc()): 
         $tplId = (int)$tpl['id'];
         $nodes = $mysqli->query("SELECT id, vorlage_id, rel_path, IFNULL(rel_path, '') AS name FROM ordner_vorlagen_nodes WHERE vorlage_id=$tplId ORDER BY sort ASC, rel_path ASC");
     ?>
        <div class="tpl-card">
           <h3><?= htmlspecialchars($tpl['name']) ?></h3>
           <ul class="node-list">
              <?php while($n = $nodes->fetch_assoc()): ?>
                 <li class="node-item">
                    <span>📁 <?= htmlspecialchars($n['rel_path'] ?? $n['name'] ?? '') ?></span>
                    <form method="post" style="margin:0;">
                       <input type="hidden" name="node_id" value="<?= $n['id'] ?>">
                       <button type="submit" name="action" value="del_node" class="node-del" onclick="return confirm('Ordner aus Muster entfernen?')">×</button>
                    </form>
                 </li>
              <?php endwhile; ?>
              <?php if($nodes->num_rows === 0): ?>
                 <li style="color:#94a3b8; font-style:italic; padding:10px; text-align:center;">Noch keine Ordner definiert.</li>
              <?php endif; ?>
           </ul>
           
           <form method="post" style="display:flex; gap:8px; margin-top:15px; border-top:1px solid #f1f5f9; pt:15px;">
              <input type="hidden" name="tpl_id" value="<?= $tplId ?>">
              <input type="text" name="folder_name" placeholder="Neuer Ordner..." style="flex:1; padding:8px; border-radius:6px; border:1px solid #e2e8f0; font-size:13px;" required>
              <button type="submit" name="action" value="add_node" class="btn btn-small secondary">➕</button>
           </form>
        </div>
     <?php endwhile; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
