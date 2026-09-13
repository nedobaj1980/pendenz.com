<?php
// pages/wohnungen_liste.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_login();

$pid = (int)($_GET['projekt_id'] ?? 0);
$oid = (int)($_GET['objekt_id'] ?? 0);
$tid = (int)($_GET['typ_id'] ?? 0);

// Logic-First: Query preparation
$query = "
    SELECT w.*, o.name AS objekt_name, pr.name AS projekt_name, pr.id as projekt_id,
           et.name AS type_name, et.icon AS type_icon, et.gruppe AS type_group
    FROM wohnungen w
    JOIN objekte o ON o.id = w.objekt_id
    JOIN projekte pr ON pr.id = o.projekt_id
    LEFT JOIN einheit_typen et ON et.id = w.typ_id
    WHERE 1=1";
if ($pid > 0) $query .= " AND pr.id = $pid";
if ($oid > 0) $query .= " AND o.id = $oid";
if ($tid > 0) $query .= " AND w.typ_id = $tid";
$query .= " ORDER BY pr.name, o.name, w.name";

$rs = $mysqli->query($query);
$count = $rs ? $rs->num_rows : 0;

// Feature: Auto-select object if only one exists for this project
if ($pid > 0 && $oid <= 0) {
    $objRes = $mysqli->query("SELECT id FROM objekte WHERE projekt_id = $pid");
    if ($objRes && $objRes->num_rows === 1) {
        $oid = (int)$objRes->fetch_assoc()['id'];
        // Re-run query with oid filter for consistency if needed, 
        // but the main query already includes it in the loop via $w
    }
}

// Header and Nav inclusion
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>


<style>
  .unit-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 24px; padding: 20px 0; }
  .unit-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; overflow: hidden; transition: 0.2s; position: relative; }
  .unit-card:hover { border-color: #3b82f6; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
  .unit-card-header { padding: 16px; border-bottom: 1px solid #f3f4f6; background:#fafbfd; }
  .unit-card-body { padding: 16px; }
  .unit-title { font-size: 18px; font-weight: 700; margin: 0; display:flex; justify-content:space-between; align-items:center; }
  .unit-meta { display: flex; gap: 12px; margin-top: 10px; font-size: 13px; color: #6b7280; }
  .unit-kpis { display: flex; gap: 12px; margin: 16px 0; }
  .kpi-chip { background: #f3f4f6; padding: 4px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; color: #374151; }
  
  .folder-preview-row { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; padding-bottom: 4px; }
  .folder-mini-chip { flex: 0 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px 10px; font-size: 11px; text-decoration: none; color: #374151; display:flex; align-items:center; gap:6px; }
  .folder-mini-chip:hover { border-color: #3b82f6; background:#eff6ff; }
  
  .status-badge { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; }
  .status-vacant { background: #fee2e2; color: #b91c1c; }
  .status-rented { background: #dcfce7; color: #166534; }
  
  .unit-footer { padding: 12px 16px; background: #f9fafb; border-top: 1px solid #f3f4f6; display: flex; gap: 8px; }
  .btn-full { flex: 1; text-align: center; }
</style>

<div class="container" style="max-width:1400px;">
  
  <header style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:30px; margin-top:20px; flex-wrap:wrap; gap:20px;">
    <div>
      <h1 style="margin:0;">Einheiten-Zentrale</h1>
      <div style="display:flex; gap:12px; margin-top:8px; align-items:center;">
        <form method="get" style="display:flex; gap:8px; align-items:center;">
          <select name="projekt_id" onchange="this.form.submit()" style="padding:8px 12px; border-radius:10px; border:1px solid #d1d5db; background:#fff; font-weight:600;">
            <option value="0">📂 Alle Projekte</option>
            <?php 
              $pList = $mysqli->query("SELECT id, name FROM projekte ORDER BY name");
              while($pl = $pList->fetch_assoc()): ?>
                <option value="<?= $pl['id'] ?>" <?= $pid==$pl['id']?'selected':'' ?>><?= htmlspecialchars($pl['name']) ?></option>
              <?php endwhile; ?>
          </select>
        </form>
        
        <?php if ($pid > 0): ?>
          <span style="color:#d1d5db;">|</span>
          <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:2px;">
             <a href="?projekt_id=<?= $pid ?>" class="btn btn-small <?= ($oid <= 0) ? 'primary':'outline' ?>">Alle Objekte</a>
             <?php 
               $objList = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=$pid ORDER BY name");
               while($ol = $objList->fetch_assoc()): ?>
                 <a href="?projekt_id=<?= $pid ?>&objekt_id=<?= $ol['id'] ?>" class="btn btn-small <?= (int)$oid==$ol['id'] ? 'primary':'outline' ?>"><?= h($ol['name']) ?></a>
               <?php endwhile; ?>
          </div>
        <?php endif; ?>

        <span style="color:#d1d5db;">|</span>
        <div style="display:flex; gap:10px; align-items:center;">
           <?php 
             $groupsRes = $mysqli->query("SELECT DISTINCT gruppe FROM einheit_typen WHERE gruppe IS NOT NULL AND gruppe != '' ORDER BY gruppe");
             while($g = $groupsRes->fetch_assoc()): 
               $isGActive = ($_GET['group']??'') == $g['gruppe'];
           ?>
              <div class="group-capsule" style="display:flex; background:#f3f4f6; padding:2px 8px; border-radius:99px; gap:6px; align-items:center;">
                <span style="font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase;"><?= h($g['gruppe']) ?></span>
                <?php 
                  $types = $mysqli->query("SELECT id, name, icon FROM einheit_typen WHERE gruppe='".$mysqli->real_escape_string($g['gruppe'])."'");
                  while($t = $types->fetch_assoc()): 
                    $isActive = (int)($_GET['typ_id']??0) == $t['id'];
                ?>
                  <a href="?projekt_id=<?= $pid ?>&objekt_id=<?= $oid ?>&typ_id=<?= $t['id'] ?>" 
                     style="text-decoration:none; font-size:13px; color:<?= $isActive ? '#3b82f6':'#64748b' ?>; font-weight:<?= $isActive ? '800':'400' ?>;" 
                     title="<?= h($t['name']) ?>">
                     <?= ($t['icon'] && $t['icon'] !== '???') ? $t['icon'] : '🏠' ?>
                  </a>
                <?php endwhile; ?>
              </div>
           <?php endwhile; ?>
           <a href="?projekt_id=<?= $pid ?>&objekt_id=<?= $oid ?>" class="btn btn-small <?= empty($_GET['typ_id']) ? 'primary':'outline' ?>" style="font-size:11px;">Alle löschen ×</a>
        </div>
      </div>
    </div>


    <div style="display:flex; gap:10px;">
      <?php if ($pid > 0): ?>
         <form method="post" action="wohnungen_import_fs.php">
            <input type="hidden" name="projekt_id" value="<?= $pid ?>">
            <button type="submit" class="btn secondary">📂 Import aus Google Drive</button>
         </form>
      <?php endif; ?>
      <a href="objekt_neu.php?projekt_id=<?= $pid ?>" class="btn primary">➕ Neue Einheit anlegen</a>
    </div>
  </header>


  <?php if ($count === 0): ?>
    <div style="text-align:center; padding:100px 20px; background:#fff; border-radius:16px; border:1px dashed #d1d5db;">
       <div style="font-size:60px; margin-bottom:20px;">🏘️</div>
       <h2>Keine Einheiten gefunden</h2>
       <p style="color:#6b7280; max-width:500px; margin:0 auto 30px;">
         Es sind noch keine Wohnungen manuell angelegt oder aus deinem Dateisystem importiert worden.
       </p>
       <?php if ($pid > 0): ?>
         <form method="post" action="wohnungen_import_fs.php">
            <input type="hidden" name="projekt_id" value="<?= $pid ?>">
            <button type="submit" class="btn primary" style="padding:12px 30px;">Jetzt aus Google Drive importieren</button>
         </form>
       <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="unit-grid">
      <?php while ($w = $rs->fetch_assoc()): 
          $wId = $w['id'];
          $wProj = (int)$w['projekt_id'];
          // Smart Search für den Ordnerpfad
          $wName = $w['name'];
          $oName = $w['objekt_name'];
          $qP = $mysqli->prepare("SELECT rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND name=? ORDER BY LENGTH(rel_path) DESC");
          $qP->bind_param("is", $wProj, $wName);
          $qP->execute();
          $allPathsRes = $qP->get_result();
          $allPaths = $allPathsRes ? $allPathsRes->fetch_all(MYSQLI_ASSOC) : [];
          $qP->close();
          $uPath = 'Wohnungen/' . $wName; // Fallback
          foreach($allPaths as $ap) { if (stripos($ap['rel_path'], $oName) !== false) { $uPath = $ap['rel_path']; break; } }
          
          // Mieter prüfen (einfach)
          $resT = $mysqli->query("SELECT COUNT(*) FROM benutzer WHERE wohnung_id=$wId");
          $tCount = (int)($resT ? $resT->fetch_column() : 0);
          
          // Subfolder Preview (wie im Dashboard)
          $subs = fs_list_children_smart($mysqli, $wProj, $uPath);
      ?>
        <div class="unit-card">
          <div class="unit-card-header">
             <div class="unit-title">
               <?= ($w['type_icon'] && $w['type_icon'] !== '???') ? $w['type_icon'] : '🏠' ?> <?= h($w['name']) ?>
               <span class="status-badge <?= $tCount > 0 ? 'status-rented' : 'status-vacant' ?>">
                 <?= $tCount > 0 ? 'Vermietet' : 'Vakant' ?>
               </span>
             </div>
             <div class="unit-meta">
                <span>🏙️ <?= h($w['objekt_name']) ?></span>
                <span>📍 <?= h($w['etage']) ?></span>
             </div>
          </div>
          
          <div class="unit-card-body">
             <div class="unit-kpis">
                <span class="kpi-chip">📏 <?= (float)$w['flaeche'] ?> m²</span>
                <span class="kpi-chip">🛏️ <?= (float)$w['zimmer'] ?> Zimmer</span>
                <span class="kpi-chip">💰 CHF <?= number_format($w['miete_netto'] ?? 0, 0) ?></span>
             </div>
             
             <div style="font-size:12px; font-weight:700; color:#9ca3af; text-transform:uppercase; margin-bottom:8px;">📁 Ordner-Vorschau</div>
             <div class="folder-preview-row">
               <?php foreach(array_slice($subs, 0, 3) as $s): ?>
                 <a href="files.php?projekt_id=<?= $wProj ?>&path=<?= rawurlencode($s['rel_path']) ?>" class="folder-mini-chip">
                   📁 <?= h(substr($s['name'], 0, 15)) ?>
                 </a>
               <?php endforeach; ?>
               <a href="files.php?projekt_id=<?= $wProj ?>&path=<?= rawurlencode($uPath) ?>" class="folder-mini-chip" style="background:#f3f4f6;">+ mehr</a>
               <?php if (empty($subs)): ?>
                  <span style="font-size:12px; color:#9ca3af; padding:4px 0;">Keine Ordner gefunden</span>
               <?php endif; ?>
             </div>
          </div>
          
          <div class="unit-footer">
             <a href="wohnung_detail.php?id=<?= $wId ?>" class="btn btn-small btn-full">🔍 Dashboard</a>
             <a href="pendenzen.php?projekt_id=<?= $wProj ?>&wohnung_id=<?= $wId ?>" class="btn btn-small btn-full secondary">📋 Pendenzen</a>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
