<?php
// pages/projekt_verknuepfungen.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();

require_once __DIR__ . '/../includes/functions.php'; // e(), page_url(), ...
require_once __DIR__ . '/../includes/user_ui.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/fs.php'; // project_root_path(), fs_list_children_smart(), ...

// ------- Fallbacks, falls in deiner Umgebung anders heißen -----------------
if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('page_url')) { function page_url(string $p){ return '/pendenz.com/pages/'.ltrim($p,'/'); } }
// CSRF shims:
if (!function_exists('csrf_field')) {
  function csrf_field() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $t = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $t;
    echo '<input type="hidden" name="csrf" value="' . e($t) . '">';
  }
}
if (!function_exists('csrf_validate')) {
  function csrf_validate() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $tok  = $_POST['csrf'] ?? $_POST['csrf_token'] ?? '';
    $sess = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
    if ($tok !== '' && $sess !== '' && !hash_equals($sess, $tok)) {
      throw new Exception('Ungültiger CSRF-Token.');
    }
  }
}
// ---------- Kleine DB-Helper ----------
function table_exists(mysqli $db, string $table): bool {
  $st=$db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
  $st->bind_param("s",$table); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $st=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1");
  $st->bind_param("ss",$table,$col); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

// ---------- Eingaben ----------
$projekt_id = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_GET['id'] ?? 0);
$ctxRelSpec = isset($_GET['path']) ? ltrim(str_replace('\\','/', trim($_GET['path'])), '/') : '';
$ctxRel = $ctxRelSpec; // alias for internal use

// ---------- Projekte laden (für Auswahl) ----------
$projekte = [];
if (table_exists($mysqli, 'projekte')) {
  $res = $mysqli->query("SELECT id, name, status FROM projekte ORDER BY id");
  if ($res) { while($r=$res->fetch_assoc()) $projekte[] = $r; $res->close(); }
}

// Aktuelles Projekt (optional) laden
$projekt = null;
if ($projekt_id > 0 && table_exists($mysqli,'projekte')) {
  $sel = "SELECT id,name,adresse,status";
  if (column_exists($mysqli,'projekte','root_path')) $sel .= ", root_path";
  $sel .= " FROM projekte WHERE id=?";
  $st=$mysqli->prepare($sel); $st->bind_param("i",$projekt_id); $st->execute();
  $projekt = $st->get_result()->fetch_assoc(); $st->close();
}

// ---------- FS-Helfer wie im Dashboard ----------
if (!function_exists('crumbs')) {
function crumbs(string $rel): array {
  $rel = ltrim($rel,'/');
  if($rel==='') return [];
  $parts = explode('/',$rel);
  $acc=[]; $out=[];
  foreach($parts as $p){ $acc[]=$p; $out[]=['label'=>$p,'rel'=>implode('/',$acc)]; }
  return $out;
}
}
function child_folders(mysqli $db, int $pid, string $parentRel=''): array {
  if (!table_exists($db,'fs_nodes')) return [];
  if ($parentRel==='') {
    $sql="SELECT name, rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND (parent_rel_path IS NULL OR parent_rel_path='') ORDER BY name ASC";
    $st=$db->prepare($sql); $st->bind_param("i",$pid);
  } else {
    $sql="SELECT name, rel_path FROM fs_nodes WHERE project_id=? AND is_dir=1 AND parent_rel_path=? ORDER BY name ASC";
    $st=$db->prepare($sql); $st->bind_param("is",$pid,$parentRel);
  }
  $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
  return $rows;
}

// ---------- Aktionen (nur Navigation / keine CRUD jetzt) ----------
$flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    csrf_validate();
    $act = $_POST['action'] ?? '';
    if ($act === 'goto_project') {
      $p_id = (int)($_POST['projekt_id'] ?? 0);
      header('Location: '.page_url('projekt_verknuepfungen.php?projekt_id='.$p_id)); exit;
    }
  } catch(Throwable $e) { $flash = '❌ '.$e->getMessage(); }
}

// ---------- Navigation (Header & globale Nav) ----------
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

// ---------- Daten für Ordnerliste ----------
$subfolders = [];
$filesQuick = [];
if ($projekt_id > 0 && $projekt) {
  $liveUsed = false;
  $children = fs_list_children_smart($mysqli, $projekt_id, $ctxRel, true, $liveUsed);
  $subfolders = array_values(array_filter($children, fn($r)=>((int)$r['is_dir'])===1));

  // Optionale kleine Dateiliste direkt im aktuellen Ordner
  foreach ($children as $c) {
    if ((int)$c['is_dir']===0 && ($c['parent_rel_path'] ?? '') === $ctxRel) {
      $filesQuick[] = $c;
    }
  }
}

?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Projekt-Verknüpfungen • Ordner</title>
<link rel="stylesheet" href="../assets/app.css">
<style>
.container{max-width:1200px;margin:20px auto;padding:10px}
.grid{display:grid;grid-template-columns:280px 1fr;gap:16px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px}
label{font-weight:600}
select,input,button{padding:8px;border:1px solid #e5e7eb;border-radius:8px}
.btn{display:inline-block;padding:8px 10px;border-radius:8px;background:#0a2a6e;color:#fff;text-decoration:none;border:0;cursor:pointer}
.btn:hover{background:#071c4a}
.small{font-size:12px;color:#6b7280}
.badge{display:inline-flex;align-items:center;gap:6px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:6px 10px;font-size:12px}
.tree a{display:block;padding:6px 8px;border-radius:8px;text-decoration:none;color:#111827;margin:1px 0}
.tree a:hover{background:#f1f5f9}
.tree a.active{background:#111827;color:#fff}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:8px;border-bottom:1px solid #eef2f7;text-align:left}
.crumbs{display:flex;gap:6px;align-items:center;margin:6px 0 10px}
.crumbs a{text-decoration:none;color:#374151}
.filebar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:8px 0}
.filebar .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;background:#fff;border-radius:999px;padding:6px 10px;text-decoration:none;color:#111827}
.filebar .chip:hover{background:#f8fafc}
</style>
</head>
<body>

<div class="container">
  <?php if ($flash): ?>
    <div class="card" style="border-color:#fca5a5;color:#991b1b;background:#fef2f2"><?php echo e($flash); ?></div>
  <?php endif; ?>

  <!-- Kopf/Navi -->
  <div class="card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
      <strong>Projekt-Verknüpfungen • Ordner</strong>
      <a class="badge" href="<?php echo e(page_url('projekt_dashboard.php'.($projekt_id?('?id='.$projekt_id):''))); ?>">Projekt-Dashboard</a>
      <a class="badge" href="<?php echo e(page_url('pendenzen.php')); ?>">Pendenzen</a>
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <?php csrf_field(); ?>
      <input type="hidden" name="action" value="goto_project">
      <label for="projekt_id">Projekt</label>
      <select id="projekt_id" name="projekt_id" onchange="this.form.submit()">
        <option value="">– wählen –</option>
        <?php foreach($projekte as $p): ?>
          <option value="<?php echo (int)$p['id']; ?>" <?php echo $projekt_id===(int)$p['id']?'selected':''; ?>>
            <?php echo e('#'.$p['id'].' '.$p['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($projekt_id): ?>
        <a class="btn" href="<?php echo e(page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id)); ?>">Ordner-Root</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$projekt_id || !$projekt): ?>
    <div class="card" style="margin-top:10px">Bitte zuerst ein Projekt wählen.</div>
  <?php else: ?>
    <div class="grid" style="margin-top:10px">
      <!-- Sidebar: Ordnerbaum -->
      <aside class="card">
        <div class="tree">
          <div style="font-weight:600;margin-bottom:8px;">Ordner-Navigation</div>
          <?php
            $anc = crumbs($ctxRel);
            array_unshift($anc, ['label'=>'Root','rel'=>'']);
            $current = $ctxRel;
            foreach ($anc as $i=>$node):
              $rel = $node['rel'];
              $childrenL = child_folders($mysqli, $projekt_id, $rel);
              $open = ($rel==='' || strpos($current, $rel.'/')===0 || $current===$rel);
          ?>
            <details <?php echo $open?'open':''; ?> style="margin-bottom:6px;">
              <summary style="cursor:pointer;padding:6px 8px;border-radius:8px;background:#f8fafc">
                <span class="badge">#<?php echo (int)$i; ?></span>
                <strong style="margin-left:6px;"><?php echo e($node['label']); ?></strong>
              </summary>
              <div style="padding:6px 0 0 8px">
                <?php foreach ($childrenL as $ch):
                  $href = page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id.'&path='.rawurlencode($ch['rel_path']));
                  $active = ($current === $ch['rel_path']) ? 'active' : '';
                ?>
                  <a class="<?php echo $active; ?>" href="<?php echo e($href); ?>">📁 <?php echo e($ch['name']); ?></a>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </aside>

      <!-- Hauptbereich -->
      <section>
        <!-- Breadcrumbs / Tools -->
        <div class="card">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between">
            <div>
              <div class="crumbs">
                <a href="<?php echo e(page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id)); ?>">🏠 Projekt</a>
                <?php $bc = crumbs($ctxRel); foreach($bc as $idx=>$c): ?>
                  <span>›</span>
                  <a href="<?php echo e(page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id.'&path='.$c['rel'])); ?>">
                    <?php echo ($idx+1).': '.e($c['label']); ?>
                  </a>
                <?php endforeach; ?>
              </div>
              <div class="small">Projekt: <strong><?php echo e($projekt['name']); ?></strong> &middot; Status: <strong><?php echo e($projekt['status']); ?></strong></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <?php $filesUrl = page_url('files.php?projekt_id='.$projekt_id.($ctxRel!==''?'&path='.rawurlencode($ctxRel):'')); ?>
              <a class="btn" target="_blank" href="<?php echo e($filesUrl); ?>">📁 Im Dateibrowser öffnen</a>
              <?php if ($ctxRel!==''):
                $parent = ($ctxRel && strrpos($ctxRel,'/')!==false) ? substr($ctxRel,0,strrpos($ctxRel,'/')) : '';
                $upHref = page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id.($parent!==''?'&path='.rawurlencode($parent):''));
              ?>
                <a class="badge" href="<?php echo e($upHref); ?>">↥ Eine Ebene hoch</a>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Schnellzugriff auf Unterordner -->
        <?php if (!empty($subfolders)): ?>
          <div class="filebar">
            <?php foreach ($subfolders as $sf):
              $href = page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id.'&path='.rawurlencode($sf['rel_path']));
            ?>
              <a class="chip" href="<?php echo e($href); ?>">📁 <?php echo e($sf['name']); ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Ordnerliste -->
        <div class="card" style="margin-top:10px">
          <h3 style="margin:4px 0 10px;">Ordner in „<?php echo $ctxRel!=='' ? e($ctxRel) : 'Root'; ?>”</h3>
          <table class="tbl">
            <thead><tr><th>Ordner</th><th>Relativer Pfad</th><th>Aktion</th></tr></thead>
            <tbody>
            <?php if (empty($subfolders)): ?>
              <tr><td colspan="3" class="small" style="padding:10px;color:#666">Keine Unterordner.</td></tr>
            <?php else: foreach ($subfolders as $sf):
              $openDash  = page_url('projekt_verknuepfungen.php?projekt_id='.$projekt_id.'&path='.rawurlencode($sf['rel_path']));
              $openFiles = page_url('files.php?projekt_id='.$projekt_id.'&path='.rawurlencode($sf['rel_path']));
            ?>
              <tr>
                <td>📁 <a href="<?php echo e($openDash); ?>"><?php echo e($sf['name']); ?></a></td>
                <td><code><?php echo e($sf['rel_path']); ?></code></td>
                <td>
                  <a class="btn" href="<?php echo e($openDash); ?>">Öffnen</a>
                  <a class="badge" target="_blank" href="<?php echo e($openFiles); ?>">Dateibrowser</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- (Optional) kleine Dateiliste im aktuellen Ordner -->
        <div class="card" style="margin-top:10px">
          <h3 style="margin:4px 0 10px;">Dateien in „<?php echo $ctxRel!=='' ? e($ctxRel) : 'Root'; ?>”</h3>
          <table class="tbl">
            <thead><tr><th>Datei</th><th>Geändert</th><th>Größe</th><th>Aktion</th></tr></thead>
            <tbody>
            <?php if (!$filesQuick): ?>
              <tr><td colspan="4" class="small" style="padding:10px;color:#666">Keine Dateien auf dieser Ebene.</td></tr>
            <?php else: foreach ($filesQuick as $f):
              $fileUrl  = page_url('file.php?projekt_id='.$projekt_id.'&path='.rawurlencode($f['rel_path']));
              $filesUrl = page_url('files.php?projekt_id='.$projekt_id.'&path='.rawurlencode($ctxRel));
              $mt = !empty($f['mtime']) ? date('d.m.Y H:i', strtotime($f['mtime'])) : '—';
              $sz = isset($f['size']) ? ( (int)$f['size']>=1024 ? number_format($f['size']/1024,1,',','.').' KB' : ((int)$f['size']).' B' ) : '—';
            ?>
              <tr>
                <td><a href="<?php echo e($fileUrl); ?>">📄 <?php echo e($f['name']); ?></a></td>
                <td><?php echo e($mt); ?></td>
                <td><?php echo e($sz); ?></td>
                <td><a class="badge" target="_blank" href="<?php echo e($filesUrl); ?>">im Dateibrowser</a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </section>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
