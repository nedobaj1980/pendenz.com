<?php
// pages/wohnung_detail.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fs.php';
require_once __DIR__ . '/../includes/user_folder_automation.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: wohnungen_liste.php'); exit; }

// SICHERHEIT: Mieter-Check
$isTenant = ($_SESSION['rolle'] ?? '') === 'benutzer';
if ($isTenant) {
    $resT = $mysqli->query("SELECT wohnung_id FROM benutzer WHERE id=" . (int)$_SESSION['user_id']);
    $tWohn = $resT->fetch_column();
    if ((int)$tWohn !== $id) {
        die("❌ Zugriff verweigert: Sie haben keine Berechtigung für diese Einheit.");
    }
}

$flash = "";

// AKTIONEN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  
  // Sichtbarkeit für Mieter umschalten (is_shared)
  if (isset($_POST['action']) && $_POST['action'] === 'toggle_visibility') {
      $nodeId = (int)$_POST['node_id'];
      $mysqli->query("UPDATE fs_nodes SET is_shared = 1 - is_shared WHERE id=$nodeId");
      $flash = "👁️ Sichtbarkeit aktualisiert.";
  }

  // Standard-Struktur anwenden
  if (isset($_POST['action']) && $_POST['action'] === 'apply_template') {
      // Find project_id & uPath
      $rowP = $mysqli->query("SELECT o.projekt_id FROM wohnungen w JOIN objekte o ON o.id=w.objekt_id WHERE w.id=$id")->fetch_assoc();
      $pid = (int)$rowP['projekt_id'];
      $wName = $mysqli->query("SELECT name FROM wohnungen WHERE id=$id")->fetch_column();
      $uPath = "Wohnungen/$wName"; 
      
      $root = project_root_path($mysqli, $pid);
      if ($root && is_dir($root)) {
          $unitAbs = fs_abs_from_rel($root, $uPath);
          if (!is_dir($unitAbs)) @mkdir($unitAbs, 0777, true);
          $nodes = $mysqli->query("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id=(SELECT id FROM ordner_vorlagen WHERE name='Liegenschafts-Standard' LIMIT 1)");
          while($n = $nodes->fetch_assoc()) {
              $subAbs = fs_abs_from_rel($root, $uPath . '/' . $n['rel_path']);
              if ($subAbs && !is_dir($subAbs)) @mkdir($subAbs, 0777, true);
          }
          fs_scan_project($mysqli, $pid, 0);
          $flash = "✅ Standard-Struktur angewendet.";
      }
  }

  // Mieter zuweisen / Status ändern
  if (isset($_POST['action']) && $_POST['action'] === 'assign_tenant') {
      $mieterId = (int)$_POST['mieter_id'];
      $von = $_POST['von'] ?: date('Y-m-d');
      
      // Bestehende Verträge dieser Wohnung beenden
      $mysqli->query("UPDATE mietvertraege SET ende=NOW() WHERE wohnung_id=$id AND ende IS NULL");
      
      // Neuen Vertrag anlegen
      $st = $mysqli->prepare("INSERT INTO mietvertraege (wohnung_id, benutzer_id, beginn) VALUES (?,?,?)");
      $st->bind_param("iis", $id, $mieterId, $von);
      if ($st->execute()) {
          // Automatischer Ordner-Sync & Vormieter-Handling
          user_folder_handle_eviction($mysqli, $id, $mieterId);
          user_folder_sync($mysqli, $mieterId);
          
          $flash = "👤 Mieter erfolgreich zugewiesen.";
      }
      $st->close();
  }
}

// DATEN LADEN
$wohnung = $mysqli->query("
    SELECT w.*, o.name AS objekt_name, pr.adresse AS objekt_adresse, pr.id AS projekt_id
    FROM wohnungen w 
    JOIN objekte o ON o.id = w.objekt_id 
    JOIN projekte pr ON pr.id = o.projekt_id
    WHERE w.id = $id
")->fetch_assoc();

if (!$wohnung) die("Wohnung nicht gefunden.");
$projectId = (int)$wohnung['projekt_id'];

// Path & Structural Meta (Universal Path Service)
require_once __DIR__ . '/../includes/fs.php';
$pathInfo = fs_get_entity_path($mysqli, 'wohnung', $id);
$uPath = $pathInfo['rel'] ?: "Wohnungen/$wName";

// Files laden
$fCond = $isTenant ? "AND is_shared=1" : "";
$resSub = $mysqli->query("SELECT * FROM fs_nodes WHERE project_id=$projectId AND parent_rel_path='$uPath' $fCond ORDER BY is_dir DESC, name ASC");
$subfolders = ($resSub) ? $resSub->fetch_all(MYSQLI_ASSOC) : [];

// Pendenzen laden (Fix für Fatal Error)
$pendenzen = $mysqli->query("
    SELECT * FROM pendenzen 
    WHERE wohnung_id = $id AND status != 'archiviert' 
    ORDER BY FIELD(status, 'offen', 'in_bearbeitung', 'erledigt'), erstellt_am DESC
");

// Token generieren falls fehlt
if (empty($wohnung['apply_token'])) {
    $newToken = bin2hex(random_bytes(16));
    $mysqli->query("UPDATE wohnungen SET apply_token='$newToken' WHERE id=$id");
    $wohnung['apply_token'] = $newToken;
}

// Anzahl Bewerber zählen
$resB = $mysqli->query("SELECT COUNT(*) FROM benutzer WHERE wohnung_id=$id AND mieter_phase='bewerber'");
$cntBewerber = $resB->fetch_column();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
  .unit-dashboard { display: grid; grid-template-columns: 1fr 360px; gap: 24px; margin-top:20px; }
  .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
  .kpi-card { background: #fff; border: 1px solid #e5e7eb; padding: 20px; border-radius: 16px; text-align: center; }
  .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; margin-bottom: 24px; }
  .section-title { font-size: 18px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
  
  .folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
  .folder-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px; text-decoration: none; color: #1e293b; display: flex; flex-direction: column; align-items: center; text-align: center; position: relative; }
  .folder-card:hover { border-color: #3b82f6; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
  .folder-card .icon { font-size: 36px; margin-bottom: 8px; }
  .vis-toggle { position: absolute; top: 8px; right: 8px; background: transparent; border: none; cursor: pointer; font-size: 18px; padding: 4px; border-radius: 6px; }
  .vis-toggle:hover { background: #f3f4f6; }
  
  .tenant-avatar { width: 56px; height: 56px; background: #eff6ff; color: #3b82f6; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 22px; margin-right: 15px; }
  .status-badge { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase; }
  
  @media (max-width: 1100px) { .unit-dashboard { grid-template-columns: 1fr; } }
</style>

<div class="container" style="max-width:1400px; padding-bottom: 60px;">
  
  <nav style="margin: 10px 0 20px; font-size:13px; color:#64748b;">
    <a href="wohnungen_liste.php?projekt_id=<?= $projectId ?>">← Einheiten</a> › 
    <a href="mieterspiegel.php?projekt_id=<?= $projectId ?>">📈 Mieterspiegel</a> › 
    <strong><?= htmlspecialchars($wohnung['name']) ?></strong> Dashboard
  </nav>

  <header style="display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
    <div>
       <h1 style="margin:0; font-size:32px; font-weight:800;"><?= htmlspecialchars($wohnung['name']) ?></h1>
       <div style="color:#64748b; font-size:15px; margin-top:5px;">📍 <?= htmlspecialchars($wohnung['objekt_name']) ?> • Etage: <?= htmlspecialchars($wohnung['etage']) ?></div>
    </div>
    <div style="display:flex; gap:10px;">
       <?php if(!$isTenant): ?>
         <a href="wohnung_edit.php?id=<?= $id ?>" class="btn secondary">✏️ Bearbeiten</a>
       <?php endif; ?>
       <a href="pendenzen.php?projekt_id=<?= $projectId ?>&wohnung_id=<?= $id ?>" class="btn primary">📝 Neue Pendenz</a>
    </div>
  </header>

  <?php if($flash): ?><div style="padding:12px 20px; background:#f0fdf4; border-radius:12px; border:1px solid #bbf7d0; color:#166534; margin-bottom:25px; font-weight:600;">✅ <?= htmlspecialchars($flash) ?></div><?php endif; ?>

  <div class="kpi-row">
     <div class="kpi-card"><h2><?= (float)$wohnung['zimmer'] ?></h2><small>Zimmer</small></div>
     <div class="kpi-card"><h2><?= (float)$wohnung['flaeche'] ?> m²</h2><small>Fläche</small></div>
     <div class="kpi-card"><h2><?= $pendenzen->num_rows ?></h2><small>Offene Aufgaben</small></div>
     <div class="kpi-card"><h2 style="color:#10b981;">CHF <?= number_format($wohnung['miete_netto']??0,0) ?></h2><small>Miete Netto</small></div>
  </div>

  <div class="unit-dashboard">
    
    <div class="main-content">
      
      <!-- FOLDER SECTION -->
      <div class="panel">
        <h3 class="section-title">📁 Dokumente & Archiv</h3>
        <div class="folder-grid">
           <?php foreach($subfolders as $sf): $isDir = (int)$sf['is_dir'] === 1; ?>
             <div class="folder-card-wrap" style="position:relative;">
                <a href="<?= $isDir ? "files.php?projekt_id=$projectId&path=".rawurlencode($sf['rel_path']) : "file.php?projekt_id=$projectId&path=".rawurlencode($sf['rel_path']) ?>" class="folder-card">
                   <span class="icon"><?= $isDir ? '📁':'📄' ?></span>
                   <span style="font-weight:700; font-size:14px;"><?= htmlspecialchars($sf['name']) ?></span>
                </a>
                <?php if(!$isTenant): ?>
                  <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="toggle_visibility">
                    <input type="hidden" name="node_id" value="<?= $sf['id'] ?>">
                    <button type="submit" class="vis-toggle" title="Sichtbarkeit für Mieter umschalten">
                       <?= (int)$sf['is_shared'] === 1 ? '👁️' : '🕶️' ?>
                    </button>
                  </form>
                <?php endif; ?>
             </div>
           <?php endforeach; ?>
           
           <?php if(empty($subfolders) && !$isTenant): ?>
              <div style="grid-column: 1/-1; padding:40px; text-align:center; background:#f8fafc; border-radius:14px; border:2px dashed #e2e8f0;">
                 <p style="color:#64748b;">Keine Ordner oder Dateien gefunden.</p>
                 <form method="post"><button type="submit" name="action" value="apply_template" class="btn primary">Standard-Struktur anwenden</button></form>
              </div>
           <?php endif; ?>
        </div>
      </div>

      <!-- PENDENZEN TABLE -->
      <div class="panel">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
           <h3 class="section-title" style="margin:0;">📋 Aktuelle Pendenzen</h3>
           <a href="pendenzen.php?projekt_id=<?= $projectId ?>&wohnung_id=<?= $id ?>" style="font-size:14px; font-weight:600; color:#3b82f6; text-decoration:none;">Alle anzeigen →</a>
        </div>
        <table style="width:100%; border-collapse:collapse;">
           <?php while($p = $pendenzen->fetch_assoc()): ?>
             <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:15px 0;">
                   <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($p['titel']) ?></div>
                   <div style="font-size:12px; color:#64748b; margin-top:3px;"><?= date('d.m.Y', strtotime($p['erstellt_am'])) ?> • <?= htmlspecialchars($p['kategorie'] ?? '') ?></div>
                </td>
                <td style="text-align:right;">
                   <span class="status-badge" style="background:#fef3c7; color:#92400e;"><?= htmlspecialchars($p['status']) ?></span>
                </td>
             </tr>
           <?php endwhile; ?>
           <?php if($pendenzen->num_rows === 0): ?>
             <tr><td style="padding:20px; text-align:center; color:#94a3b8;">Keine offenen Pendenzen.</td></tr>
           <?php endif; ?>
        </table>
      </div>

    </div>

    <!-- SIDEBAR -->
    <aside>
      
      <!-- MIETER PANEL -->
      <div class="panel" style="background: #fdfdfd;">
        <h3 class="section-title">👤 Mieter-Verwaltung</h3>
        
        <?php
          $resMV = $mysqli->query("
            SELECT u.name, u.email, mv.beginn, mv.ende 
            FROM mietvertraege mv 
            JOIN benutzer u ON mv.benutzer_id = u.id 
            WHERE mv.wohnung_id = $id AND (mv.ende IS NULL OR mv.ende > NOW())
            ORDER BY mv.beginn DESC LIMIT 1
          ");
          $curr = $resMV->fetch_assoc();
        ?>

        <?php if($curr): ?>
          <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:15px;">
             <div style="display:flex; align-items:center; margin-bottom:12px;">
                <div class="tenant-avatar"><?= substr($curr['name'],0,1) ?></div>
                <div>
                  <div style="font-weight:800; font-size:16px;"><?= htmlspecialchars($curr['name']) ?></div>
                  <div style="font-size:12px; color:#64748b;"><?= htmlspecialchars($curr['email']) ?></div>
                </div>
             </div>
             <div style="font-size:12px; padding:8px 0; border-top:1px solid #f1f5f9;">
                📅 <strong>Mietbeginn:</strong> <?= date('d.m.Y', strtotime($curr['beginn'])) ?>
             </div>
             <?php if(!$isTenant): ?>
               <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:12px;">
                  <a href="mailto:<?= htmlspecialchars($curr['email']) ?>" class="btn btn-small secondary" style="text-align:center;">✉️ E-Mail</a>
                  <a href="chat.php?user_id=<?= (int)$_SESSION['user_id'] ?>" class="btn btn-small secondary" style="text-align:center;">💬 Chat</a>
               </div>
             <?php endif; ?>
          </div>
        <?php else: ?>
          <div style="padding:20px; text-align:center; border:2px dashed #e2e8f0; border-radius:12px;">
             <div style="font-size:24px;">🏢</div>
             <div style="font-size:13px; color:#64748b; margin-top:10px;">Einheit aktuell vakant</div>
          </div>
        <?php endif; ?>

        <?php if(!$isTenant): ?>
           <hr style="border:0; border-top:1px solid #f1f5f9; margin:15px 0;">
           <form method="post">
             <input type="hidden" name="action" value="assign_tenant">
             <label style="display:block; font-size:12px; font-weight:700; color:#64748b; margin-bottom:5px;">Neuen Mieter zuweisen:</label>
             <select name="mieter_id" required style="width:100%; padding:8px; border-radius:8px; margin-bottom:10px; font-size:13px;">
                <option value="">-- Benutzer auswählen --</option>
                <?php
                  $resU = $mysqli->query("SELECT id, name FROM benutzer WHERE rolle='benutzer' ORDER BY name");
                  while($u = $resU->fetch_assoc()) echo "<option value='{$u['id']}'>".h($u['name'])."</option>";
                ?>
             </select>
             <label style="display:block; font-size:12px; font-weight:700; color:#64748b; margin-bottom:5px;">Mietbeginn:</label>
             <input type="date" name="von" value="<?= date('Y-m-d') ?>" style="width:100%; padding:8px; border-radius:8px; margin-bottom:15px; font-size:13px; border:1px solid #cbd5e1;">
             <button type="submit" class="btn btn-small primary" style="width:100%; background:#3b82f6;">✅ Als Aktuell setzen</button>
           </form>
           
           <div style="margin-top:20px;">
              <h4 style="font-size:12px; color:#64748b; margin-bottom:10px;">Vormieter (Archiv):</h4>
              <div style="font-size:12px;">
                 <?php
                   $resHist = $mysqli->query("
                     SELECT u.name, mv.beginn, mv.ende 
                     FROM mietvertraege mv 
                     JOIN benutzer u ON mv.benutzer_id = u.id 
                     WHERE mv.wohnung_id = $id AND mv.ende IS NOT NULL AND mv.ende <= NOW()
                     ORDER BY mv.ende DESC
                   ");
                   while($h = $resHist->fetch_assoc()):
                 ?>
                   <div style="padding:5px 0; border-top:1px solid #f8fafc; color:#94a3b8;">
                      <?= h($h['name']) ?> (<?= date('y', strtotime($h['beginn'])) ?>-<?= date('y', strtotime($h['ende'])) ?>)
                   </div>
                 <?php endwhile; if($resHist->num_rows===0) echo "<em style='color:#cbd5e1;'>Keine Vormieter</em>"; ?>
              </div>
           </div>
        <?php endif; ?>
      </div>

      <!-- BEWERBER PANEL -->
      <?php if(!$isTenant): ?>
      <div class="panel" style="border-left: 4px solid #3b82f6;">
         <h3 class="section-title">📢 Direkt-Bewerbungen</h3>
         <p style="font-size:13px; color:#64748b; margin-bottom:15px;">Versende diesen Link an Interessenten. Sie können ihre Daten direkt digital erfassen.</p>
         
         <div style="display:flex; gap:8px; margin-bottom:15px;">
           <?php 
             $applyUrl = (isset($_SERVER['HTTPS']) ? 'https':'http').'://'.$_SERVER['HTTP_HOST'].'/pendenz.com/pages/bewerbung.php?token='.$wohnung['apply_token'];
           ?>
           <input type="text" readonly value="<?= $applyUrl ?>" id="applyUrl" style="flex:1; padding:8px; border:1px solid #e2e8f0; border-radius:8px; font-size:12px; background:#f8fafc; color:#64748b;">
           <button class="btn btn-small" onclick="copyToClipboard()" style="white-space:nowrap;">📋 Kopieren</button>
         </div>

         <div style="display:flex; justify-content:space-between; align-items:center; background:#eff6ff; padding:12px; border-radius:12px; margin-bottom:15px;">
            <div style="font-size:12px; font-weight:700; color:#1e40af;">AKTIVE BEWERBER</div>
            <div style="font-size:18px; font-weight:800; color:#1d4ed8;"><?= (int)$cntBewerber ?></div>
         </div>

         <a href="benutzer.php?new_mieter_phase=bewerber&wohnung_id=<?= $id ?>#create-user-form" class="btn secondary" style="width:100%; display:block; text-align:center; font-size:13px;">✍️ Bewerber manuell erfassen</a>
         <div style="margin-top:10px; font-size:11px; color:#94a3b8; text-align:center;">Ideal für Post- od. Telefon-Bewerbungen</div>
      </div>

      <script>
      function copyToClipboard() {
        var copyText = document.getElementById("applyUrl");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        alert("Link kopiert! ✅\nDu kannst ihn jetzt via WhatsApp oder E-Mail versenden.");
      }
      </script>
      <?php endif; ?>

      <!-- SPEICHER & ADMIN PANEL -->
      <?php if(!$isTenant): ?>
      <div class="panel">
        <h4 style="font-size:11px; text-transform:uppercase; color:#94a3b8; margin-bottom:15px; letter-spacing:0.05em;">🛠️ Administration</h4>
        <form method="post">
           <button type="submit" name="action" value="move_to_storage" class="btn btn-small outline" style="width:100%;" onclick="return confirm('Dateien archivieren?')">📦 Dateien zu Drive verschieben</button>
        </form>
        <div style="margin-top:20px; font-size:12px; color:#64748b;">
           <strong>Info:</strong> Mieter sehen nur Ordner mit dem Symbol 👁️.
        </div>
      </div>
      <?php endif; ?>

    </aside>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
