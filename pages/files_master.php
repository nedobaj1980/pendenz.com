<?php
// pages/files_master.php - Der übergreifende Master File-Explorer
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_login();

$projectId = (int)($_GET['projekt_id'] ?? 0);
$pathRel = isset($_GET['path']) ? (string)$_GET['path'] : '';

// Alle Projekte für die Sidebar holen
$projects = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");

// Kinder für den aktuellen Pfad holen (Project-spezifisch oder Projekt-Übersicht)
$children = [];
if ($projectId > 0) {
    $children = fs_list_children_smart($mysqli, $projectId, $pathRel);
} else {
    // Wenn kein Projekt gewählt, zeigen wir die Projekt-Roots als Ordner
    $pr = $mysqli->query("SELECT id, name, root_path FROM projekte ORDER BY name ASC");
    while($p = $pr->fetch_assoc()) {
        $children[] = [
            'name' => $p['name'],
            'rel_path' => '',
            'is_dir' => 1,
            'size' => 0,
            'mtime' => '-',
            'is_project_root' => true,
            'project_id' => $p['id']
        ];
    }
}

$crumbs = fs_breadcrumbs($pathRel);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_auto.php';
?>

<style>
  :root {
    --side-bg: #1e293b; --list-bg: #fff; --border: #e2e8f0;
    --hi: #3b82f6; --text-m: #64748b;
  }
  .master-explorer { display: grid; grid-template-columns: 300px 1fr; background: #f8fafc; height: calc(100vh - 120px); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-top:10px; }
  
  .master-sidebar { background: var(--side-bg); color: #fff; padding: 20px; overflow-y: auto; border-right: 1px solid var(--border); }
  .master-sidebar h4 { font-size: 11px; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.1em; margin-bottom: 12px; }
  .tree-node { display: block; padding: 8px 12px; color: #cbd5e1; text-decoration: none; border-radius: 8px; font-size: 14px; transition: 0.2s; margin-bottom: 4px; }
  .tree-node:hover { background: rgba(255,255,255,0.1); color: #fff; }
  .tree-node.active { background: #3b82f6; color: #fff; font-weight: 600; }
  
  .master-view { background: var(--list-bg); overflow-y: auto; display: flex; flex-direction: column; }
  .master-toolbar { padding: 16px 20px; border-bottom: 1px solid var(--border); background: #fff; display: flex; align-items: center; gap: 15px; }
  
  .m-table { width: 100%; border-collapse: collapse; }
  .m-table th { text-align: left; padding: 12px 20px; background: #f1f5f9; position: sticky; top: 0; font-size: 12px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid var(--border); }
  .m-table tr:hover { background: #f8fafc; }
  .m-table td { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
  .m-icon { font-size: 20px; margin-right: 10px; }
  
  .m-crumb-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #64748b; margin-bottom: 20px; padding: 0 5px; }
  .m-crumb-row a { color: #3b82f6; text-decoration: none; }
</style>

<div class="container" style="max-width:1500px; margin-top:20px;">

  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
    <h2 style="margin:0;">🗂️ Portfolio Master Explorer</h2>
    <div style="display:flex; gap:10px;">
       <a href="<?= url('portfolio_master.php') ?>" class="btn secondary">📊 Portfolio Dashboard</a>
       <button class="btn primary" onclick="location.reload()">🔄 Refresh</button>
    </div>
  </div>

  <div class="master-explorer">
    
    <aside class="master-sidebar">
       <h4>Projekte (Roots)</h4>
       <a href="files_master.php" class="tree-node <?= $projectId === 0 ? 'active' : '' ?>">🏠 Portfolio Übersicht</a>
       <hr style="border:0; border-top:1px solid rgba(255,255,255,0.1); margin:15px 0;">
       
       <?php while($p = $projects->fetch_assoc()): ?>
          <a href="files_master.php?projekt_id=<?= $p['id'] ?>" class="tree-node <?= $projectId === $p['id'] && $pathRel === '' ? 'active' : '' ?>">
             🏢 <?= htmlspecialchars($p['name']) ?>
          </a>
          <?php if($projectId === $p['id']): ?>
             <div style="padding-left:15px; margin-top:5px; border-left:1px solid rgba(255,255,255,0.1);">
                <a href="files_master.php?projekt_id=<?= $projectId ?>&path=Wohnungen" class="tree-node <?= $pathRel === 'Wohnungen' ? 'active' : '' ?>" style="font-size:13px;">↳ Wohnungen</a>
             </div>
          <?php endif; ?>
       <?php endwhile; ?>
    </aside>

    <main class="master-view">
       <div class="master-toolbar">
          <div style="display:flex; gap:5px; font-size:14px; color:#64748b;">
             <a href="files_master.php" style="text-decoration:none; color:#3b82f6;">Portfolio</a>
             <?php if($projectId > 0): ?>
                <span>›</span> <?php 
                    $prName = $mysqli->query("SELECT name FROM projekte WHERE id=$projectId")->fetch_column();
                    echo '<a href="files_master.php?projekt_id='.$projectId.'" style="text-decoration:none; color:#3b82f6;">'.htmlspecialchars($prName).'</a>';
                ?>
                <?php foreach($crumbs as $c): ?>
                   <span>›</span> <a href="files_master.php?projekt_id=<?= $projectId ?>&path=<?= rawurlencode($c['rel']) ?>" style="text-decoration:none; color:#3b82f6;"><?= htmlspecialchars($c['label']) ?></a>
                <?php endforeach; ?>
             <?php endif; ?>
          </div>
       </div>

       <div style="flex:1; overflow-y:auto;">
          <table class="m-table">
             <thead>
                <tr>
                   <th>Name</th>
                   <th>Status / Info</th>
                   <th>Typ</th>
                   <th style="text-align:right;">Grösse</th>
                </tr>
             </thead>
             <tbody>
                <?php if(empty($children)): ?>
                   <tr><td colspan="4" style="padding:60px; text-align:center; color:#94a3b8;">Dieser Ordner ist noch physisch leer.</td></tr>
                <?php else: foreach($children as $c): 
                    $isDir = (int)$c['is_dir'] === 1;
                    $isProj = isset($c['is_project_root']);
                    $icon = $isDir ? ($isProj ? '🏢' : '📁') : '📄';
                    
                    if($isProj) {
                        $href = "files_master.php?projekt_id=".$c['project_id'];
                    } else {
                        $href = $isDir 
                          ? "files_master.php?projekt_id=$projectId&path=".rawurlencode($c['rel_path'])
                          : "file.php?projekt_id=$projectId&path=".rawurlencode($c['rel_path']);
                    }
                ?>
                   <tr>
                      <td>
                         <a href="<?= $href ?>" style="text-decoration:none; color:#1e293b; display:flex; align-items:center; font-weight:<?= $isDir ? '600':'400' ?>;">
                            <span class="m-icon"><?= $icon ?></span>
                            <?= htmlspecialchars($c['name']) ?>
                         </a>
                      </td>
                      <td style="color:#64748b; font-size:12px;">
                         <?= $isProj ? 'Projekt-Root' : ($isDir ? 'Verzeichnis' : 'Datei') ?>
                      </td>
                      <td style="color:#94a3b8; font-size:12px;">
                         <?= $isDir ? 'Ordner' : strtoupper(pathinfo($c['name'], PATHINFO_EXTENSION)) ?>
                      </td>
                      <td style="text-align:right; color:#94a3b8; font-size:12px;">
                         <?= $isDir ? '-' : round($c['size']/1024,1).' KB' ?>
                      </td>
                   </tr>
                <?php endforeach; endif; ?>
             </tbody>
          </table>
       </div>
    </main>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
