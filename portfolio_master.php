<?php
// portfolio_master.php - Das übergreifende Portfolio-Dashboard
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/fs.php';
require_login();

// Globale Statistiken
$stats = [
    'projects' => $mysqli->query("SELECT COUNT(*) FROM projekte")->fetch_column(),
    'units'    => $mysqli->query("SELECT COUNT(*) FROM wohnungen")->fetch_column(),
    'pendenzen'=> $mysqli->query("SELECT COUNT(*) FROM pendenzen WHERE status != 'erledigt'")->fetch_column(),
    'vacant'   => $mysqli->query("SELECT COUNT(*) FROM wohnungen w LEFT JOIN benutzer b ON b.wohnung_id=w.id WHERE b.id IS NULL")->fetch_column(),
];

// Neueste übergreifende Pendenzen
$recentTasks = $mysqli->query("
    SELECT p.*, pr.name as projekt_name, pr.id as projekt_id 
    FROM pendenzen p 
    JOIN projekte pr ON p.projekt_id = pr.id 
    WHERE p.status != 'erledigt' 
    ORDER BY p.id DESC LIMIT 8
");

// Alle Projekte für die Matrix
$projects = $mysqli->query("
    SELECT p.*, 
    (SELECT COUNT(*) FROM wohnungen WHERE objekt_id IN (SELECT id FROM objekte WHERE projekt_id=p.id)) as unit_count
    FROM projekte p 
    ORDER BY p.name ASC
");

require_once __DIR__ . '/includes/header.php';
// Wir nutzen das Nav-System
if (is_superadmin()) include __DIR__ . '/includes/nav_superadmin.php';
else include __DIR__ . '/includes/nav_auto.php';
?>

<style>
  .pm-hero { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #fff; padding: 40px 20px; border-radius: 20px; margin-bottom: 30px; position: relative; overflow: hidden; }
  .pm-hero h1 { margin: 0; font-size: 32px; font-weight: 800; }
  .pm-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 30px; }
  .pm-stat-card { background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); padding: 20px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.2); }
  .pm-stat-num { font-size: 28px; font-weight: 800; display: block; }
  .pm-stat-label { font-size: 13px; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.05em; }

  .pm-main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
  .pm-section-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
  
  .project-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
  .p-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 20px; transition: 0.2s; }
  .p-card:hover { border-color: #3b82f6; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
  .p-card h3 { margin: 0 0 10px 0; font-size: 18px; }
  
  .task-list { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; overflow: hidden; }
  .task-item { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; display: flex; flex-direction: column; gap: 4px; }
  .task-item:last-child { border-bottom: none; }
  .task-proj { font-size: 11px; font-weight: 700; color: #3b82f6; text-transform: uppercase; }
  .task-title { font-size: 14px; color: #1e293b; font-weight: 600; text-decoration: none; }
  .task-title:hover { color: #3b82f6; }
  
  .search-bar-global { background: #fff; border: 1px solid #e5e7eb; border-radius: 99px; padding: 12px 24px; width: 100%; font-size: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 30px; }
</style>

<div class="container" style="max-width: 1400px; margin-top: 20px;">
  
  <div class="pm-hero">
    <div style="position:relative; z-index:2;">
      <h1>Portfolio-Master Dashboard</h1>
      <p style="color: #cbd5e1;">Übergreifende Verwaltung & Analyse deines gesamten Bestands.</p>
      
      <div class="pm-stats-grid">
        <div class="pm-stat-card">
          <span class="pm-stat-num"><?= $stats['projects'] ?></span>
          <span class="pm-stat-label">Projekte</span>
        </div>
        <div class="pm-stat-card">
          <span class="pm-stat-num"><?= $stats['units'] ?></span>
          <span class="pm-stat-label">Einheiten</span>
        </div>
        <div class="pm-stat-card">
          <span class="pm-stat-num" style="color: #fbbf24;"><?= $stats['vacant'] ?></span>
          <span class="pm-stat-label">Vakant</span>
        </div>
        <div class="pm-stat-card" style="background: rgba(244, 63, 94, 0.2);">
          <span class="pm-stat-num" style="color: #ff8c94;"><?= $stats['pendenzen'] ?></span>
          <span class="pm-stat-label">Offene Pendenzen</span>
        </div>
      </div>
    </div>
  </div>

  <input type="text" class="search-bar-global" placeholder="🔍 Global suchen: Wohnung, Mieter, Projekt oder Pendenz...">

  <div class="pm-main-grid">
    
    <!-- Linke Spalte: Projekte -->
    <section>
      <div class="pm-section-title">🏢 Deine Projekte</div>
      <div class="project-card-grid">
        <?php while($p = $projects->fetch_assoc()): 
            $root = project_root_path($mysqli, $p['id']);
        ?>
          <div class="p-card">
            <h3 style="display:flex; justify-content:space-between;">
              <?= htmlspecialchars($p['name']) ?>
              <span style="font-size:12px; color:#6b7280; font-weight:400;"><?= $p['unit_count'] ?> Einheiten</span>
            </h3>
            <div style="margin:15px 0; font-size:13px; color:#64748b;">
               📍 <?= htmlspecialchars($p['adresse'] ?? 'Keine Adresse') ?>
            </div>
            <div style="display:flex; gap:8px;">
               <a href="pages/projekt_dashboard.php?id=<?= $p['id'] ?>" class="btn btn-small" style="flex:1; text-align:center;">Öffnen</a>
               <?php if($root): ?>
                  <a href="pages/project_storage.php?projekt_id=<?= $p['id'] ?>" class="btn btn-small secondary" title="Google Drive / Speicher">📂 Drive</a>
               <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
        <a href="pages/projekt_neu.php" class="p-card" style="border: 2px dashed #d1d5db; display:flex; flex-direction:column; align-items:center; justify-content:center; text-decoration:none; color:#9ca3af;">
           <span style="font-size:30px;">➕</span>
           <span>Neues Projekt anlegen</span>
        </a>
      </div>
    </section>

    <!-- Rechte Spalte: Aktuelle Aufgaben -->
    <aside>
      <div class="pm-section-title">🔔 Übergreifende Pendenzen</div>
      <div class="task-list">
        <?php if($recentTasks->num_rows > 0): ?>
          <?php while($t = $recentTasks->fetch_assoc()): ?>
            <div class="task-item">
               <span class="task-proj"><?= htmlspecialchars($t['projekt_name']) ?></span>
               <a href="pages/pendenzen.php?projekt_id=<?= $t['projekt_id'] ?>&id=<?= $t['id'] ?>" class="task-title"><?= htmlspecialchars($t['titel']) ?></a>
               <div style="display:flex; justify-content:space-between; font-size:11px; color:#9ca3af; margin-top:4px;">
                  <span>Status: <?= htmlspecialchars($t['status'] ?? 'offen') ?></span>
                  <span>#<?= $t['id'] ?></span>
               </div>
            </div>
          <?php endwhile; ?>
          <a href="pages/pendenzen.php" style="display:block; padding:12px; text-align:center; font-size:13px; color:#3b82f6; text-decoration:none; font-weight:600; border-top:1px solid #f3f4f6;">Alle Pendenzen ansehen</a>
        <?php else: ?>
          <div style="padding:30px; text-align:center; color:#9ca3af;">Keine offenen Aufgaben</div>
        <?php endif; ?>
      </div>

      <div class="pm-section-title" style="margin-top:30px;">🛠️ Schnell-Links</div>
      <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
         <a href="pages/wohnungen_liste.php" class="btn secondary" style="text-align:left;">🏠 Alle Wohnungen (Portfolio)</a>
         <a href="pages/benutzer.php" class="btn secondary" style="text-align:left;">👥 Mieter & Benutzer</a>
         <a href="tools/konto_verwaltung/index.php" class="btn secondary" style="text-align:left;">💰 Konto-Verwaltung / CSV</a>
      </div>
    </aside>

  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
