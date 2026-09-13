<?php
// pages/files.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_once __DIR__ . '/../includes/fs.php';

$projectId = (int)($_GET['projekt_id'] ?? 0);
if ($projectId <= 0) die("Projekt ID fehlt.");
if (function_exists('require_project_access')) require_project_access($projectId);

$proj = ['name' => 'Unbenanntes Projekt'];
$stP = $mysqli->prepare("SELECT name FROM projekte WHERE id=?");
$stP->bind_param("i", $projectId);
$stP->execute();
if ($rowP = $stP->get_result()->fetch_assoc()) $proj = $rowP;
$stP->close();

$pathRel = isset($_GET['path']) ? (str_replace('\\', '/', ltrim(rtrim((string)$_GET['path'], '/'), '/'))) : '';

/** Logic Actions (Redirect before output) **/
if (isset($_POST['action']) && $_POST['action'] === 'scan') {
    $res = fs_scan_project($mysqli, $projectId, 0);
    header("Location: " . url('pages/files.php?projekt_id=' . $projectId . '&path=' . rawurlencode($pathRel) . '&scan_ok=' . ($res['ok'] ? 1 : 0) . '&count=' . ($res['count'] ?? 0)));
    exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'sync_fs') {
    $stats = sync_project_folders($mysqli, $projectId);
    header("Location: " . url('pages/files.php?projekt_id=' . $projectId . '&path=' . rawurlencode($pathRel) . '&sync_ok=1&count=' . ($stats['total'] ?? 0) . '&created=' . ($stats['created_disk'] ?? 0)));
    exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'new_folder') {
    $folderName = trim((string)($_POST['folder_name'] ?? ''));
    $folderName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $folderName);
    if ($folderName !== '') {
        $root = project_root_path($mysqli, $projectId);
        if ($root) {
            $targetRel = ($pathRel !== '' ? $pathRel . '/' : '') . $folderName;
            $targetAbs = fs_abs_from_rel($root, $targetRel);
            if ($targetAbs && !is_dir($targetAbs)) {
                @mkdir($targetAbs, 0777, true);
                $parent = $pathRel !== '' ? $pathRel : null;
                $st = $mysqli->prepare("INSERT INTO fs_nodes (project_id, rel_path, name, parent_rel_path, is_dir, size, mtime) VALUES (?,?,?,?,1,0,NOW()) ON DUPLICATE KEY UPDATE is_dir=1");
                $st->bind_param("isss", $projectId, $targetRel, $folderName, $parent);
                $st->execute();
                $st->close();
            }
        }
    }
    header("Location: " . url('pages/files.php?projekt_id=' . $projectId . '&path=' . rawurlencode($pathRel) . '&folder_ok=1'));
    exit;
}
if (isset($_POST['action']) && $_POST['action'] === 'upload_file' && !empty($_FILES['upload_file']['name'])) {
    $root = project_root_path($mysqli, $projectId);
    if ($root) {
        $targetDirAbs = $pathRel !== '' ? fs_abs_from_rel($root, $pathRel) : $root;
        if ($targetDirAbs && is_dir($targetDirAbs)) {
            $fName = basename($_FILES['upload_file']['name']);
            $fName = preg_replace('/[\/\\\\:*?"<>|]/', '_', $fName);
            $dest = $targetDirAbs . DIRECTORY_SEPARATOR . $fName;
            if (@move_uploaded_file($_FILES['upload_file']['tmp_name'], $dest)) {
                $targetRel = ($pathRel !== '' ? $pathRel . '/' : '') . $fName;
                $sz = (int)@filesize($dest);
                $mt = date('Y-m-d H:i:s', @filemtime($dest) ?: time());
                $parent = $pathRel !== '' ? $pathRel : null;
                $st = $mysqli->prepare("INSERT INTO fs_nodes (project_id, rel_path, name, parent_rel_path, is_dir, size, mtime) VALUES (?,?,?,?,0,?,?) ON DUPLICATE KEY UPDATE size=VALUES(size), mtime=VALUES(mtime)");
                $st->bind_param("isssis", $projectId, $targetRel, $fName, $parent, $sz, $mt);
                $st->execute();
                $st->close();
            }
        }
    }
    header("Location: " . url('pages/files.php?projekt_id=' . $projectId . '&path=' . rawurlencode($pathRel) . '&upload_ok=1'));
    exit;
}

/** Data Preparation **/
$root = project_root_path($mysqli, $projectId);
$children = [];
if ($root) {
    $children = fs_list_children_smart_sync($mysqli, $projectId, $pathRel);
}

$crumbs = fs_breadcrumbs($pathRel);

function fmtSizeLocal($b) {
    $b = (int)$b;
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
    return round($b / 1073741824, 2) . ' GB';
}

/** Inclusion **/
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
  :root { --side-bg: #111822; --list-bg: #0b0f14; --border: #1f2937; --hi: #3b82f6; --text-m: #9ca3af; }
  .explorer { display: grid; grid-template-columns: 240px 1fr; border: 1px solid var(--border); border-radius: 12px; height: 82vh; overflow: hidden; background: var(--list-bg); margin-top:20px;}
  .sidebar { background: var(--side-bg); border-right: 1px solid var(--border); padding: 12px; overflow-y: auto; }
  .sidebar section { margin-bottom: 24px; }
  .sidebar h4 { font-size: 11px; text-transform: uppercase; color: var(--text-m); letter-spacing: 0.05em; margin: 0 0 8px 6px; }
  .sidebar a { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; color: #d1d5db; font-size: 13px; transition: 0.2s; }
  .sidebar a:hover { background: #1f2937; color: #fff; }
  .sidebar a.active { background: #312e81; color: #fff; border-left: 3px solid var(--hi); }
  .main-view { display: flex; flex-direction: column; overflow: hidden; }
  .toolbar { padding: 8px 12px; background: #0f172a; border-bottom: 1px solid var(--border); display: flex; gap: 8px; align-items: center; }
  .addr-bar { flex: 1; display: flex; background: #0b0f14; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; height: 34px; align-items: center; padding: 0 8px; gap: 4px; }
  .addr-bar .crumb { color: var(--text-m); font-size: 13px; display: flex; align-items: center; gap: 4px; }
  .addr-bar .crumb:after { content: "›"; font-size: 14px; opacity: 0.4; }
  .addr-bar .crumb:last-child:after { content: ""; }
  .addr-bar .crumb a { color: inherit; }
  .addr-bar .crumb a:hover { color: #fff; text-decoration: underline; }
  .file-scroll { flex: 1; overflow-y: auto; padding: 0; }
  .ex-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .ex-table th { position: sticky; top: 0; background: #0f172a; border-bottom: 1px solid var(--border); text-align: left; padding: 10px 12px; color: var(--text-m); font-weight: 600; }
  .ex-table tr:hover { background: #111827; }
  .ex-table td { padding: 8px 12px; border-bottom: 1px solid #111827; }
  .ex-table .name-col { display: flex; align-items: center; gap: 10px; }
  .ex-table .icon { font-size: 18px; width: 24px; text-align: center; }
  .btn-icon { padding: 6px; border-radius: 6px; border: 1px solid var(--border); background: transparent; cursor: pointer; color: var(--text-m); }
  .btn-icon:hover { background: var(--border); color: #fff; }
  @media (max-width: 800px) { .explorer { grid-template-columns: 1fr; } .sidebar { display: none; } }
</style>

<div class="container" style="max-width:1300px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
    <h2 style="margin:0;">📁 Dateimanager: <span style="color:var(--hi)"><?= htmlspecialchars($proj['name'] ?? 'Projekt') ?></span></h2>
    <div style="display:flex; gap:8px;">
       <a class="btn" href="<?= url('pages/project_storage.php?projekt_id='.$projectId) ?>">⚙️ Speicher & Scan</a>
       <form method="post" style="margin:0">
         <input type="hidden" name="action" value="scan">
         <button class="btn primary" type="submit">🔄 Scan & Refresh</button>
       </form>
       <form method="post" style="margin:0">
         <input type="hidden" name="action" value="sync_fs">
         <button class="btn btn-outline" type="submit" title="Gleicht die Wohnungs-Ordner mit der Datenbank ab und legt fehlende an">🔄 Drive-Sync</button>
       </form>
    </div>
  </div>

  <?php if (isset($_GET['scan_ok'])): ?>
    <div class="flash info" style="margin-bottom:16px;">
      <?= (int)$_GET['scan_ok'] === 1 ? ("✅ Scan erfolgreich: ".(int)($_GET['count']??0)." Einträge.") : "❌ Scan fehlgeschlagen." ?>
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['sync_ok'])): ?>
    <div class="flash info" style="margin-bottom:16px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
      ✅ Drive-Abgleich abgeschlossen (<?= (int)($_GET['count']??0) ?> geprüft, <?= (int)($_GET['created']??0) ?> neu angelegt).
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['folder_ok'])): ?>
    <div class="flash info" style="margin-bottom:16px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
      ✅ Ordner erfolgreich im Google Drive erstellt.
    </div>
  <?php endif; ?>
  <?php if (isset($_GET['upload_ok'])): ?>
    <div class="flash info" style="margin-bottom:16px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
      ✅ Datei erfolgreich ins Google Drive hochgeladen.
    </div>
  <?php endif; ?>

  <?php if (!$root): ?>
    <div class="flash warn">
      ⚠️ Kein Root-Pfad für dieses Projekt gesetzt. <a href="<?= htmlspecialchars(url('/pages/project_storage.php?projekt_id='.$projectId)) ?>">Jetzt konfigurieren</a>.
    </div>
  <?php endif; ?>

  <div class="explorer">
    <aside class="sidebar">
      <section>
        <h4>Favoriten</h4>
        <a href="<?= url('pages/files.php?projekt_id='.$projectId) ?>"><span class="icon">🏠</span> Home (Root)</a>
        <a href="<?= url('pages/pendenzen.php?projekt_id='.$projectId) ?>"><span class="icon">📋</span> Pendenzen</a>
        <a href="<?= url('pages/projekt_dashboard.php?projekt_id='.$projectId) ?>"><span class="icon">📊</span> Dashboard</a>
      </section>

      <?php
        $units = $mysqli->query("SELECT w.id, w.name, o.name as objekt_name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$projectId ORDER BY o.name, w.name");
      ?>
      <section>
        <h4>Einheiten / Wohnungen</h4>
        <?php if($units) while($u = $units->fetch_assoc()): ?>
          <a href="<?= url('pages/files.php?projekt_id='.$projectId.'&path='.rawurlencode('10_Wohnungen/'.$u['name'])) ?>">
            <span class="icon">🏢</span> <?= htmlspecialchars($u['objekt_name']) ?> - <?= htmlspecialchars($u['name']) ?>
          </a>
        <?php endwhile; ?>
      </section>
      
      <section>
        <h4>Werkzeuge</h4>
        <a href="<?= url('pages/ordner_vorlagen.php?projekt_id='.$projectId) ?>"><span class="icon">📚</span> Ordner-Vorlagen</a>
      </section>
    </aside>

    <main class="main-view">
      <div class="toolbar">
        <button class="btn-icon" onclick="history.back()" title="Zurück">⬅️</button>
        <button class="btn-icon" onclick="location.href='<?= url('pages/files.php?projekt_id='.$projectId) ?>'" title="Aufwärts">⬆️</button>
        <div class="addr-bar">
          <div class="crumb"><a href="<?= url('pages/files.php?projekt_id='.$projectId) ?>">Root</a></div>
          <?php foreach($crumbs as $c): ?>
            <div class="crumb"><a href="<?= url('pages/files.php?projekt_id=' . $projectId . '&path=' . rawurlencode($c['rel'])) ?>"><?= htmlspecialchars($c['label']) ?></a></div>
          <?php endforeach; ?>
        </div>
        
        <!-- Schnell-Aktionen für Drive -->
        <button type="button" class="btn" style="padding:4px 10px; font-size:12px;" onclick="promptNewFolder()">➕ Neuer Ordner</button>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data" style="margin:0; display:inline;">
          <input type="hidden" name="action" value="upload_file">
          <input type="file" id="fileUploadInput" name="upload_file" style="display:none;" onchange="document.getElementById('uploadForm').submit();">
          <button type="button" class="btn" style="padding:4px 10px; font-size:12px;" onclick="document.getElementById('fileUploadInput').click();">⬆️ Hochladen</button>
        </form>

        <form id="newFolderForm" method="post" style="display:none;">
          <input type="hidden" name="action" value="new_folder">
          <input type="hidden" name="folder_name" id="newFolderNameInput" value="">
        </form>

        <script>
        function promptNewFolder() {
          const name = prompt('Name des neuen Ordners im Google Drive:');
          if (name && name.trim().length > 0) {
            document.getElementById('newFolderNameInput').value = name.trim();
            document.getElementById('newFolderForm').submit();
          }
        }
        </script>

        <div style="width:160px">
           <input type="text" placeholder="Suchen..." style="padding:4px 8px; font-size:12px; height:34px;">
        </div>
      </div>

      <div class="file-scroll">
        <table class="ex-table">
          <thead>
            <tr>
              <th style="width:45%">Name</th>
              <th style="width:15%">Geändert</th>
              <th style="width:15%">Typ</th>
              <th style="width:15%; text-align:right;">Grösse</th>
              <th style="width:10%; text-align:center;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if(!$children): ?>
              <tr><td colspan="5" style="padding:40px; text-align:center; color:var(--text-m)">Dieser Ordner ist leer oder existiert nicht.</td></tr>
            <?php else: foreach($children as $row): ?>
              <?php
                $isDir = (int)$row['is_dir'] === 1;
                $ext = strtolower(pathinfo($row['name'], PATHINFO_EXTENSION));
                $icon = $isDir ? '📁' : '📄';
                if ($ext==='pdf') $icon = '📕';
                elseif (in_array($ext,['jpg','png','webp','gif','jpeg'])) $icon = '🖼️';
                elseif (in_array($ext,['zip','7z','rar'])) $icon = '📦';
                elseif (in_array($ext,['doc','docx'])) $icon = '📘';
                elseif (in_array($ext,['xls','xlsx','csv'])) $icon = '📗';

                $href = $isDir
                  ? url('/pages/files.php?projekt_id='.$projectId.'&path='.rawurlencode($row['rel_path']))
                  : url('/pages/file.php?projekt_id='.$projectId.'&path='.rawurlencode($row['rel_path']));
              ?>
              <tr>
                <td>
                  <a href="<?= $href ?>" class="name-col" style="text-decoration:none; color:inherit;">
                    <span class="icon text-primary"><?= $icon ?></span>
                    <span><?= htmlspecialchars($row['name']) ?></span>
                  </a>
                </td>
                <td class="small muted"><?= htmlspecialchars($row['mtime'] ?? '—') ?></td>
                <td class="small muted"><?= $isDir ? 'Ordner' : strtoupper($ext).' Datei' ?></td>
                <td style="text-align:right" class="small muted"><?= $isDir ? '-' : fmtSizeLocal((int)$row['size']) ?></td>
                <td style="text-align:center">
                  <?php if(!$isDir): ?>
                    <a href="<?= $href.'&dl=1' ?>" class="btn-icon" title="Herunterladen">💾</a>
                  <?php endif; ?>
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

