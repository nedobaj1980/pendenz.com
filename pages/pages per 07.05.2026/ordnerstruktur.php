<?php
// pages/ordnerstruktur.php
// Einfache & sichere Ordnerverwaltung mit max. Tiefe 10, verlustfreiem Umbauen und ZIP-Backup.

if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_role(['admin','superadmin']);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }

$PREFIX = site_prefix();
$flash  = "";

// ============================ Konfiguration ============================
const MAX_DEPTH = 10;                                 // max. Hierarchie-Tiefe (Ebenen)
$STORAGE_ROOT   = __DIR__ . '/../storage/files';      // logischer „PC-Ordnerstil“-Wurzelordner
$BACKUP_DIR     = __DIR__ . '/../storage/backups';    // wohin ZIP-Backups geschrieben werden

@is_dir($STORAGE_ROOT) || @mkdir($STORAGE_ROOT, 0775, true);
@is_dir($BACKUP_DIR)   || @mkdir($BACKUP_DIR,   0775, true);

// ============================ DB-Struktur sicherstellen ============================
$mysqli->query("
CREATE TABLE IF NOT EXISTS folders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  name VARCHAR(120) NOT NULL,               -- Anzeigename
  fs_name VARCHAR(120) NOT NULL,            -- Dateisystem-tauglicher Name
  path VARCHAR(1024) NOT NULL,              -- /root/unter/folder (aus fs_name gebaut)
  depth TINYINT UNSIGNED NOT NULL,          -- Ebene (root=1)
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_folders_parent FOREIGN KEY (parent_id) REFERENCES folders(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  UNIQUE KEY uq_parent_name (parent_id, name, deleted_at),
  KEY idx_path (path),
  KEY idx_depth (depth)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$mysqli->query("
CREATE TABLE IF NOT EXISTS documents (
  id INT AUTO_INCREMENT PRIMARY KEY,
  folder_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,           -- Anzeigename/Dateiname
  storage_relpath VARCHAR(2048) NOT NULL,   -- z.B. /Kunden/Alpha/Vertrag.pdf
  size BIGINT NULL,
  checksum CHAR(64) NULL,                    -- z.B. SHA256
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  CONSTRAINT fk_docs_folder FOREIGN KEY (folder_id) REFERENCES folders(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  KEY idx_storage (storage_relpath)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$mysqli->query("
CREATE TABLE IF NOT EXISTS folder_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  happened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action ENUM('create','rename','move','soft_delete','restore') NOT NULL,
  folder_id INT NOT NULL,
  before_path VARCHAR(1024) NULL,
  after_path  VARCHAR(1024) NULL,
  note TEXT NULL,
  KEY idx_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ============================ Helpers ============================
function ident_ok($s){ return (bool)preg_match('/^\S(.*\S)?$/u', (string)$s); } // irgendwas nicht-leeres (keine führenden/folgenden Spaces)
function fs_slug(string $name): string {
  // Dateisystem-sicherer Name, trotzdem lesbar
  $s = trim($name);
  $s = preg_replace('~[\\\/:*?"<>|]+~u', '-', $s);  // Windows no-nos
  $s = preg_replace('~\s+~u', ' ', $s);
  $s = preg_replace('~[^\pL\pN\-\._ ]+~u', '', $s);
  $s = trim($s, ". "); // keine Punkte/Spaces am Rand
  if ($s==='') $s = 'ordner';
  return $s;
}
function fetch_all_folders(mysqli $db) : array {
  $res = $db->query("SELECT * FROM folders WHERE deleted_at IS NULL ORDER BY depth, parent_id, name");
  $out=[]; while($r=$res->fetch_assoc()) $out[(int)$r['id']]=$r; return $out;
}
function fetch_folder(mysqli $db, int $id) : ?array {
  $st=$db->prepare("SELECT * FROM folders WHERE id=? LIMIT 1");
  $st->bind_param("i",$id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
  return $r ?: null;
}
function children_of(array $all, ?int $pid): array {
  $out=[]; foreach($all as $f){ $p = $f['parent_id']!==null ? (int)$f['parent_id'] : null; if ($p===$pid) $out[]=$f; } return $out;
}
function is_descendant(array $all, int $id, int $candidateParent): bool {
  // check ob parent in Unterbaum liegt -> verboten
  while (true) {
    $cur = $all[$candidateParent] ?? null; if (!$cur) return false;
    if ($cur['parent_id']===null) return false;
    if ((int)$cur['parent_id']===$id) return true;
    $candidateParent = (int)$cur['parent_id'];
  }
}
function subtree_ids(array $all, int $rootId): array {
  $stack=[$rootId]; $out=[];
  while($stack){
    $id=array_pop($stack); $out[]=$id;
    foreach($all as $f){ if ((int)($f['parent_id']??0)===$id) $stack[]=(int)$f['id']; }
  }
  return $out;
}
function subtree_max_extra_depth(array $all, int $rootId): int {
  // Höhe des Unterbaums relativ zum root (root selbst = 0)
  $root = $all[$rootId];
  $max = 0;
  foreach($all as $f){
    if (strpos($f['path'].'/', rtrim($root['path'],'/').'/')===0) {
      $d = (int)$f['depth'] - (int)$root['depth'];
      if ($d>$max) $max=$d;
    }
  }
  return $max;
}
function build_path(mysqli $db, ?int $parentId, string $fsName): string {
  if ($parentId===null) return '/'.$fsName;
  $p = fetch_folder($db, $parentId);
  return rtrim($p['path'],'/').'/'.$fsName;
}
function ensure_depth_ok(mysqli $db, ?int $parentId, int $extraHeight=0) {
  $depth = 1;
  $cur = $parentId;
  while($cur!==null){
    $st=$db->prepare("SELECT parent_id FROM folders WHERE id=? AND deleted_at IS NULL");
    $st->bind_param("i",$cur); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    if (!$r) throw new Exception("Übergeordneter Ordner nicht gefunden.");
    $depth++;
    $cur = $r['parent_id']!==null ? (int)$r['parent_id'] : null;
    if ($depth + $extraHeight > MAX_DEPTH) throw new Exception("Maximale Tiefe (".MAX_DEPTH.") überschritten.");
  }
  if ($depth + $extraHeight > MAX_DEPTH) throw new Exception("Maximale Tiefe (".MAX_DEPTH.") überschritten.");
}
function log_folder(mysqli $db, string $action, int $folderId, ?string $before, ?string $after, ?string $note=null){
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $st=$db->prepare("INSERT INTO folder_audit_log (user_id, action, folder_id, before_path, after_path, note) VALUES (?,?,?,?,?,?)");
  $st->bind_param("isisss",$uid,$action,$folderId,$before,$after,$note); $st->execute(); $st->close();
}

// ============================ Aktionen ============================
try {
  // 1) Ordner anlegen
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_folder') {
    $name = trim($_POST['name'] ?? '');
    $parentId = $_POST['parent_id']!=='' ? (int)$_POST['parent_id'] : null;
    if (!ident_ok($name)) throw new Exception("Bitte einen Ordnernamen eingeben.");
    ensure_depth_ok($mysqli, $parentId, 0);

    $fs = fs_slug($name);
    $path = build_path($mysqli, $parentId, $fs);
    $depth = 1;
    $cur = $parentId;
    while($cur!==null){ $depth++; $p = fetch_folder($mysqli, $cur); $cur = $p['parent_id']!==null ? (int)$p['parent_id'] : null; }

    $st=$mysqli->prepare("INSERT INTO folders (parent_id,name,fs_name,path,depth) VALUES (?,?,?,?,?)");
    $st->bind_param("isssi", $parentId, $name, $fs, $path, $depth);
    if (!$st->execute()) throw new Exception("Ordner konnte nicht angelegt werden: ".$st->error);
    $newId = $st->insert_id; $st->close();

    // Audit & optional physisch (wir fassen die echten Dateien NICHT an, nur Mirror-Struktur)
    @mkdir($STORAGE_ROOT . $path, 0775, true);
    log_folder($mysqli,'create',$newId,null,$path,null);
    $flash = "✅ Ordner „".h($name)."“ angelegt.";
    header("Location: ".$PREFIX."pages/ordnerstruktur.php");
    exit;
  }

  // 2) Umbenennen
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='rename_folder') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$id) throw new Exception("Ungültige Auswahl.");
    if (!ident_ok($name)) throw new Exception("Bitte einen Ordnernamen eingeben.");

    $mysqli->begin_transaction();
    $cur = fetch_folder($mysqli,$id); if (!$cur || $cur['deleted_at']!==null) throw new Exception("Ordner nicht gefunden.");
    $beforePath = $cur['path'];

    $fs = fs_slug($name);
    $newPath = build_path($mysqli, $cur['parent_id']!==null?(int)$cur['parent_id']:null, $fs);

    // Alle Nachfahren-Pfade anpassen (prefix ersetzen)
    $all = fetch_all_folders($mysqli);
    $ids = subtree_ids($all, $id);
    foreach ($ids as $fid){
      $f = fetch_folder($mysqli,$fid);
      $repl = preg_replace('~^'.preg_quote($beforePath,'~').'~', $newPath, $f['path'], 1);
      $st=$mysqli->prepare("UPDATE folders SET path=?, updated_at=NOW() WHERE id=?");
      $st->bind_param("si",$repl,$fid); $st->execute(); $st->close();
    }
    // Root selbst: name/fs_name
    $st=$mysqli->prepare("UPDATE folders SET name=?, fs_name=? WHERE id=?");
    $st->bind_param("ssi",$name,$fs,$id); $st->execute(); $st->close();

    $mysqli->commit();
    log_folder($mysqli,'rename',$id,$beforePath,$newPath,null);
    $flash = "✅ Ordner umbenannt.";
    header("Location: ".$PREFIX."pages/ordnerstruktur.php");
    exit;
  }

  // 3) Verschieben
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='move_folder') {
    $id   = (int)($_POST['id'] ?? 0);
    $newParent = $_POST['parent_id']!=='' ? (int)$_POST['parent_id'] : null;
    if (!$id) throw new Exception("Ungültige Auswahl.");
    $cur = fetch_folder($mysqli,$id); if (!$cur || $cur['deleted_at']!==null) throw new Exception("Ordner nicht gefunden.");

    $all = fetch_all_folders($mysqli);
    if ($newParent!==null) {
      if (!isset($all[$newParent])) throw new Exception("Zielordner gibt es nicht.");
      if (is_descendant($all, $id, $newParent)) throw new Exception("Ordner kann nicht in sich selbst verschoben werden.");
    }

    // Tiefe prüfen: neueTiefe + höheUnterbaum <= MAX_DEPTH
    $extraHeight = subtree_max_extra_depth($all, $id);
    ensure_depth_ok($mysqli, $newParent, $extraHeight);

    $mysqli->begin_transaction();
    $beforePath = $cur['path'];
    $newPath = build_path($mysqli, $newParent, $cur['fs_name']);

    // neue Tiefe des Root bestimmen
    $newDepth = 1;
    $tmp = $newParent;
    while($tmp!==null){ $newDepth++; $p=fetch_folder($mysqli,$tmp); $tmp = $p['parent_id']!==null?(int)$p['parent_id']:null; }
    $delta = $newDepth - (int)$cur['depth'];

    // Unterbaum Pfade + depth aktualisieren
    $ids = subtree_ids($all, $id);
    foreach ($ids as $fid){
      $f = fetch_folder($mysqli,$fid);
      $newp = preg_replace('~^'.preg_quote($beforePath,'~').'~', $newPath, $f['path'], 1);
      $newd = (int)$f['depth'] + $delta;
      $st=$mysqli->prepare("UPDATE folders SET path=?, depth=?, updated_at=NOW() WHERE id=?");
      $st->bind_param("sii",$newp,$newd,$fid); $st->execute(); $st->close();
    }
    // parent setzen
    $st=$mysqli->prepare("UPDATE folders SET parent_id=? WHERE id=?");
    if ($newParent===null) { $null = null; $st->bind_param("ii",$null,$id); }
    else { $st->bind_param("ii",$newParent,$id); }
    $st->execute(); $st->close();

    $mysqli->commit();
    log_folder($mysqli,'move',$id,$beforePath,$newPath,null);
    $flash = "✅ Ordner verschoben.";
    header("Location: ".$PREFIX."pages/ordnerstruktur.php");
    exit;
  }

  // 4) Soft-Delete (nur wenn leer) & Restore
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='soft_delete_folder') {
    $id   = (int)($_POST['id'] ?? 0);
    $cur = fetch_folder($mysqli,$id); if (!$cur) throw new Exception("Ordner nicht gefunden.");

    // Sicherheit: nur löschen, wenn keine Unterordner/Dateien
    $st=$mysqli->prepare("SELECT COUNT(*) FROM folders WHERE parent_id=? AND deleted_at IS NULL");
    $st->bind_param("i",$id); $st->execute(); $c1=(int)$st->get_result()->fetch_row()[0]; $st->close();
    $st=$mysqli->prepare("SELECT COUNT(*) FROM documents WHERE folder_id=? AND deleted_at IS NULL");
    $st->bind_param("i",$id); $st->execute(); $c2=(int)$st->get_result()->fetch_row()[0]; $st->close();
    if ($c1>0 || $c2>0) throw new Exception("Ordner ist nicht leer. Bitte zuerst Inhalte verschieben.");

    $mysqli->query("UPDATE folders SET deleted_at=NOW() WHERE id=".(int)$id);
    log_folder($mysqli,'soft_delete',$id,$cur['path'],null,null);
    $flash = "🗑️ Ordner in den Papierkorb verschoben (nichts gelöscht).";
    header("Location: ".$PREFIX."pages/ordnerstruktur.php");
    exit;
  }
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='restore_folder') {
    $id   = (int)($_POST['id'] ?? 0);
    $cur = fetch_folder($mysqli,$id); if (!$cur) throw new Exception("Ordner nicht gefunden.");
    $mysqli->query("UPDATE folders SET deleted_at=NULL WHERE id=".(int)$id);
    log_folder($mysqli,'restore',$id,null,$cur['path'],null);
    $flash = "✅ Ordner wiederhergestellt.";
    header("Location: ".$PREFIX."pages/ordnerstruktur.php");
    exit;
  }

  // 5) Backup erstellen (ZIP)
  if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='make_backup_zip') {
    // Erzeugt ZIP mit voller Struktur (/storage/files/*)
    $ts = date('Ymd_His');
    $zipPath = rtrim($BACKUP_DIR,'/')."/backup_{$ts}.zip";
    $root = realpath($STORAGE_ROOT) ?: $STORAGE_ROOT;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE)!==TRUE) throw new Exception("Konnte ZIP nicht anlegen.");
    $rootLen = strlen($root);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $file) {
      $abs = $file->getPathname();
      $rel = substr($abs, $rootLen);
      if ($file->isDir()) { $zip->addEmptyDir(ltrim($rel,'/\\')); }
      else { $zip->addFile($abs, ltrim($rel,'/\\')); }
    }
    $zip->close();
    $flash = "✅ Backup erstellt: ".basename($zipPath);
    header("Location: ".$PREFIX."pages/ordnerstruktur.php?dl=".urlencode(basename($zipPath)));
    exit;
  }

} catch (Throwable $e) {
  $flash = "❌ ".$e->getMessage();
}

// Download-Link (optional)
if (isset($_GET['dl'])) {
  $f = basename($_GET['dl']);
  $p = rtrim($BACKUP_DIR,'/')."/".$f;
  if (is_file($p)) {
    header("Content-Type: application/zip");
    header("Content-Disposition: attachment; filename=\"".$f."\"");
    header("Content-Length: ".filesize($p));
    readfile($p);
    exit;
  }
}

// ============================ Daten für Anzeige ============================
$all = fetch_all_folders($mysqli);
$roots = children_of($all, null);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
  :root { --bd:#e5e7eb; --mut:#64748b; --ink:#0f172a; --hi:#2563eb; --bg:#f8fafc; --dang:#ef4444; }
  .layout { max-width:1320px; margin:0 auto; padding:18px; display:grid; gap:18px; }
  .card{ background:#fff; border:1px solid var(--bd); border-radius:12px; overflow:hidden; }
  .card>header{ padding:12px 14px; background:#f1f5f9; font-weight:700; display:flex; justify-content:space-between; align-items:center;}
  .card .content{ padding:14px; display:grid; gap:12px; }
  .btn{ border:1px solid var(--bd); background:#fff; color:#111827; border-radius:10px; padding:8px 12px; cursor:pointer; font-weight:600; }
  .btn.small{ padding:4px 8px; font-size:12px; }
  .btn.primary{ background:var(--hi); border-color:#1d4ed8; color:#fff; }
  .btn.danger{ background:var(--dang); border-color:#dc2626; color:#fff; }
  .muted{ color:#64748b; } .small{ font-size:12px; }
  input[type=text], select{ width:100%; padding:10px; border:1px solid var(--bd); border-radius:10px; }
  .cols{ display:grid; grid-template-columns: 1.1fr 1.4fr; gap:16px; }
  .tree ul{ list-style:none; padding-left:16px; margin:4px 0; }
  .tree li{ margin:2px 0; }
  .badge{ display:inline-block; padding:2px 6px; border:1px dashed var(--bd); border-radius:8px; font-size:12px; color:#475569; background:#f8fafc; }
  .help{ background:#f8fafc; border:1px dashed var(--bd); border-radius:10px; padding:10px; }
  .kbd{ font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background:#f3f4f6; border:1px solid #e5e7eb; padding:2px 6px; border-radius:6px; font-size:12px; }
</style>

<div class="layout">
  <header style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Ordnerstruktur</h1>
    <div style="display:flex; gap:8px;">
      <a class="btn" href="<?= h($PREFIX) ?>pages/pendenzen.php">↩︎ Pendenzen</a>
      <form method="post" onsubmit="return confirm('Jetzt ZIP-Backup der Dateien erstellen?');" style="margin:0;">
        <input type="hidden" name="action" value="make_backup_zip">
        <button class="btn small">ZIP-Backup erstellen</button>
      </form>
    </div>
  </header>

  <?php if($flash): ?><div class="help"><?= h($flash) ?></div><?php endif; ?>

  <section class="card">
    <header>
      <div>So funktioniert’s</div>
    </header>
    <div class="content">
      <div class="help small">
        <ul style="margin:0 0 0 16px;">
          <li><b>Hierarchie</b>: die Struktur ist strikt von oben nach unten. <b>Max. Tiefe: <?= MAX_DEPTH ?></b> Ebenen.</li>
          <li><b>Neuer Ordner</b>: lege immer den <u>übergeordneten</u> Ordner fest (außer für die oberste Ebene).</li>
          <li><b>Sicherheit</b>: Beim Umbenennen/Verschieben werden <u>keine Dateien gelöscht</u>, nur Zuordnungen aktualisiert.</li>
          <li><b>Löschen</b>: nur <i>Papierkorb (Soft-Delete)</i>. Dauerhaftes Löschen ist deaktiviert.</li>
          <li><b>Backup</b>: ZIP-Backup bildet die Struktur wie am PC ab. Optional kannst du <span class="kbd">/storage/backups</span> spiegeln.</li>
        </ul>
      </div>
      <div class="cols">
        <!-- Tree -->
        <div class="tree">
          <div style="display:flex;align-items:center;gap:8px;">
            <div class="badge">Struktur</div><span class="muted small">– Klick auf einen Ordner zum Bearbeiten</span>
          </div>
          <?php
          function render_tree($all, $parent_id=null){
            $kids=[]; foreach($all as $f){ $p = $f['parent_id']!==null?(int)$f['parent_id']:null; if ($p===$parent_id) $kids[]=$f; }
            if (!$kids) return;
            echo "<ul>";
            foreach($kids as $k){
              echo "<li>";
              echo '<a href="?sel='.$k['id'].'" class="small">'.h($k['name']).'</a> ';
              echo '<span class="muted small">('.h($k['path']).')</span>';
              render_tree($all, (int)$k['id']);
              echo "</li>";
            }
            echo "</ul>";
          }
          render_tree($all, null);
          if (!$all) echo '<div class="muted small">Noch keine Ordner – lege unten einen an.</div>';
          ?>
        </div>

        <!-- Editor -->
        <div>
          <div class="badge">Aktionen</div>

          <!-- Neuen Ordner anlegen -->
          <details open style="margin-top:8px;">
            <summary><b>Neuen Ordner anlegen</b></summary>
            <div class="content" style="padding:8px 0;">
              <form method="post" class="cols" style="grid-template-columns:1.2fr 1fr; gap:12px;">
                <input type="hidden" name="action" value="create_folder">
                <div>
                  <label>Name</label>
                  <input type="text" name="name" placeholder="z. B. Kunden">
                </div>
                <div>
                  <label>Übergeordnet</label>
                  <select name="parent_id">
                    <option value="">(oberste Ebene)</option>
                    <?php foreach($all as $f): ?>
                      <option value="<?= (int)$f['id'] ?>">Ebene <?= (int)$f['depth'] ?> · <?= h($f['path']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div style="grid-column:1/-1; display:flex; justify-content:flex-end;">
                  <button class="btn primary">Anlegen</button>
                </div>
              </form>
              <div class="muted small">Der technische Ordnername wird automatisch in einen PC-tauglichen Namen umgewandelt (keine Sonderzeichen wie <code>\ / : * ? " &lt; &gt; |</code>).</div>
            </div>
          </details>

          <?php
            $sel = isset($_GET['sel']) ? (int)$_GET['sel'] : 0;
            $cur = $sel ? fetch_folder($mysqli,$sel) : null;
          ?>
          <?php if ($cur): ?>
          <details open style="margin-top:8px;">
            <summary><b>Ausgewählt:</b> <?= h($cur['name']) ?> <span class="muted">– <?= h($cur['path']) ?></span></summary>
            <div class="content" style="padding:8px 0; gap:10px;">
              <!-- Umbenennen -->
              <form method="post" class="cols" style="grid-template-columns: 1.5fr auto;">
                <input type="hidden" name="action" value="rename_folder">
                <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
                <div>
                  <label>Neuer Name</label>
                  <input type="text" name="name" value="<?= h($cur['name']) ?>">
                </div>
                <div style="display:flex;align-items:end;justify-content:flex-end;">
                  <button class="btn">Umbenennen</button>
                </div>
              </form>

              <!-- Verschieben -->
              <form method="post" class="cols" style="grid-template-columns: 1.5fr auto;">
                <input type="hidden" name="action" value="move_folder">
                <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
                <div>
                  <label>Verschieben nach (neuer übergeordneter Ordner)</label>
                  <select name="parent_id" required>
                    <option value="">(oberste Ebene)</option>
                    <?php foreach($all as $f):
                      if ((int)$f['id']===(int)$cur['id']) continue; // sich selbst nicht auswählen
                    ?>
                      <option value="<?= (int)$f['id'] ?>">Ebene <?= (int)$f['depth'] ?> · <?= h($f['path']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div style="display:flex;align-items:end;justify-content:flex-end;">
                  <button class="btn">Verschieben</button>
                </div>
              </form>

              <!-- Soft-Delete -->
              <form method="post" onsubmit="return confirm('Ordner in den Papierkorb verschieben? Inhalte müssen vorher leer sein.');">
                <input type="hidden" name="action" value="soft_delete_folder">
                <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
                <button class="btn danger small">In Papierkorb verschieben (nur wenn leer)</button>
              </form>

              <div class="help small">
                <b>Sicherheit:</b> Beim Umbenennen/Verschieben passen wir nur die <i>Pfade</i> an – <u>keine</u> Dateien werden gelöscht oder überschrieben.
                Löschaktionen sind weich (Papierkorb).
              </div>
            </div>
          </details>
          <?php endif; ?>

          <details style="margin-top:8px;">
            <summary><b>Papierkorb (wiederherstellen)</b></summary>
            <div class="content" style="padding:8px 0;">
              <table>
                <thead><tr><th>Ordner</th><th>Gelöscht am</th><th>Aktion</th></tr></thead>
                <tbody>
                <?php
                  $trash = $mysqli->query("SELECT * FROM folders WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");
                  if ($trash->num_rows===0) echo '<tr><td colspan="3" class="muted small">Papierkorb ist leer.</td></tr>';
                  while($r=$trash->fetch_assoc()):
                ?>
                  <tr>
                    <td><?= h($r['name']) ?> <span class="muted small">(<?= h($r['path']) ?>)</span></td>
                    <td class="small"><?= h($r['deleted_at']) ?></td>
                    <td>
                      <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="restore_folder">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn small">Wiederherstellen</button>
                      </form>
                    </td>
                  </tr>
                <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </details>

        </div>
      </div>
    </div>
  </section>

  <section class="card">
    <header><div>Protokoll</div></header>
    <div class="content" style="overflow:auto;">
      <table>
        <thead><tr><th>Zeit</th><th>Aktion</th><th>Ordner</th><th>Vorher</th><th>Nachher</th><th>Hinweis</th></tr></thead>
        <tbody>
          <?php
            $log = $mysqli->query("SELECT * FROM folder_audit_log ORDER BY happened_at DESC LIMIT 50");
            if ($log->num_rows===0) echo '<tr><td colspan="6" class="muted small">Noch keine Einträge.</td></tr>';
            while($r=$log->fetch_assoc()):
          ?>
            <tr>
              <td class="small"><?= h($r['happened_at']) ?></td>
              <td class="small"><?= h($r['action']) ?></td>
              <td class="small">#<?= (int)$r['folder_id'] ?></td>
              <td class="small"><?= h($r['before_path']) ?></td>
              <td class="small"><?= h($r['after_path']) ?></td>
              <td class="small"><?= h($r['note']) ?></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <div class="muted small">Max. 50 Einträge. In der Datenbanktabelle <code>folder_audit_log</code> findest du alles.</div>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
