<?php
// pages/pendenz_ordner.php
if (session_status()===PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

if (!function_exists('h')){ function h($v){ return htmlspecialchars((string)($v ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

function fetch_projects(mysqli $db){ return $db->query("SELECT id,name FROM projekte ORDER BY name"); }

$proj = (int)($_GET['projekt_id'] ?? ($_POST['projekt_id'] ?? 0));
$flash='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $act = $_POST['action'] ?? '';
  try {
    if ($act==='create') {
      $name=trim($_POST['name'] ?? ''); if($name==='') throw new Exception('Name erforderlich');
      $parent = ($_POST['parent_id']!=='') ? (int)$_POST['parent_id'] : null;
      $slug = slugify($name);
      $st=$mysqli->prepare("INSERT INTO pendenz_ordner (projekt_id,parent_id,name,slug) VALUES (?,?,?,?)");
      $st->bind_param("iiss",$proj,$parent,$name,$slug); $st->execute();
      $flash='✅ Ordner angelegt.';
    } elseif ($act==='rename') {
      $id=(int)$_POST['id']; $name=trim($_POST['name'] ?? ''); if(!$id||$name==='') throw new Exception('Ungültig');
      $slug=slugify($name);
      $st=$mysqli->prepare("UPDATE pendenz_ordner SET name=?, slug=? WHERE id=? AND projekt_id=?");
      $st->bind_param("ssii",$name,$slug,$id,$proj); $st->execute();
      $flash='✅ Ordner umbenannt.';
    } elseif ($act==='move') {
      $id=(int)$_POST['id']; $parent = ($_POST['parent_id']!=='') ? (int)$_POST['parent_id'] : null;
      if($id===($parent??0)) throw new Exception('Ordner kann nicht sein eigener Parent sein.');
      $st=$mysqli->prepare("UPDATE pendenz_ordner SET parent_id=? WHERE id=? AND projekt_id=?");
      $st->bind_param("iii",$parent,$id,$proj); $st->execute();
      $flash='✅ Ordner verschoben.';
    } elseif ($act==='delete') {
      $id=(int)$_POST['id']; if(!$id) throw new Exception('Ungültig');
      $hasChildren = $mysqli->query("SELECT 1 FROM pendenz_ordner WHERE parent_id={$id} LIMIT 1")->num_rows>0;
      $hasPend     = $mysqli->query("SELECT 1 FROM pendenzen WHERE ordner_id={$id} LIMIT 1")->num_rows>0;
      if($hasChildren || $hasPend) throw new Exception('Ordner nicht leer.');
      $mysqli->query("DELETE FROM pendenz_ordner WHERE id={$id} AND projekt_id={$proj}");
      $flash='✅ Ordner gelöscht.';
    }
  } catch(Throwable $e){ $flash='❌ '.$e->getMessage(); }
  header("Location: pendenz_ordner.php?projekt_id=".$proj); exit;
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

function load_tree(mysqli $db, int $proj): array {
  $r=$db->query("SELECT id,parent_id,name FROM pendenz_ordner WHERE projekt_id={$proj} ORDER BY parent_id,name");
  $nodes=[]; while($row=$r->fetch_assoc()) $nodes[(int)$row['id']]=$row+['children'=>[]];
  foreach($nodes as $id=>&$n){ $pid=(int)($n['parent_id']??0); if($pid && isset($nodes[$pid])) $nodes[$pid]['children'][]=&$n; }
  unset($n);
  return array_values(array_filter($nodes, fn($n)=>empty($n['parent_id'])));
}
function render_tree(array $nodes, int $level=0){
  foreach($nodes as $n){
    echo '<div style="margin-left:'.(16*$level).'px; padding:3px 0">';
    echo '📁 '.h($n['name']).' <small style="opacity:.6">#'.(int)$n['id'].'</small>';
    echo '</div>';
    if(!empty($n['children'])) render_tree($n['children'],$level+1);
  }
}
?>
<div class="container">
  <header class="hero hero-teal" style="display:flex;gap:8px;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Pendenz-Ordner</h1>
    <a class="btn" href="pendenzen.php">← Zurück</a>
  </header>

  <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c;"><?= h($flash) ?></div><?php endif; ?>

  <div class="card">
    <form method="get" style="display:flex; gap:8px; align-items:center;">
      <label>Projekt
        <select name="projekt_id" onchange="this.form.submit()">
          <option value="">— wählen —</option>
          <?php $ps=fetch_projects($mysqli); while($p=$ps->fetch_assoc()): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)$p['id']===$proj)?'selected':''; ?>><?= h($p['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </label>
      <?php if($proj): ?><noscript><button class="btn">Laden</button></noscript><?php endif; ?>
    </form>
  </div>

  <?php if($proj): $tree=load_tree($mysqli,$proj); ?>
  <div class="card">
    <h3>Struktur</h3>
    <div><?php render_tree($tree); ?></div>
  </div>

  <div class="card">
    <h3>Aktionen</h3>
    <div class="form-grid">
      <!-- Create -->
      <form method="post">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="projekt_id" value="<?= $proj ?>">
        <label>Name* <input type="text" name="name" required></label>
        <label>Parent
          <select name="parent_id">
            <option value="">— (Top) —</option>
            <?php
              $opt=$mysqli->query("SELECT id,name,parent_id FROM pendenz_ordner WHERE projekt_id={$proj} ORDER BY parent_id,name");
              while($o=$opt->fetch_assoc()): ?>
                <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
            <?php endwhile; ?>
          </select>
        </label>
        <button class="btn">➕ Anlegen</button>
      </form>

      <!-- Rename -->
      <form method="post">
        <input type="hidden" name="action" value="rename">
        <input type="hidden" name="projekt_id" value="<?= $proj ?>">
        <label>Ordner
          <select name="id" required>
            <option value="">— wählen —</option>
            <?php
              $opt=$mysqli->query("SELECT id,name FROM pendenz_ordner WHERE projekt_id={$proj} ORDER BY name");
              while($o=$opt->fetch_assoc()): ?>
                <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
            <?php endwhile; ?>
          </select>
        </label>
        <label>Neuer Name* <input type="text" name="name" required></label>
        <button class="btn">✏️ Umbenennen</button>
      </form>

      <!-- Move -->
      <form method="post">
        <input type="hidden" name="action" value="move">
        <input type="hidden" name="projekt_id" value="<?= $proj ?>">
        <label>Ordner
          <select name="id" required>
            <option value="">— wählen —</option>
            <?php
              $opt=$mysqli->query("SELECT id,name FROM pendenz_ordner WHERE projekt_id={$proj} ORDER BY name");
              while($o=$opt->fetch_assoc()): ?>
                <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
            <?php endwhile; ?>
          </select>
        </label>
        <label>Neuer Parent
          <select name="parent_id">
            <option value="">— (Top) —</option>
            <?php
              $opt=$mysqli->query("SELECT id,name FROM pendenz_ordner WHERE projekt_id={$proj} ORDER BY name");
              while($o=$opt->fetch_assoc()): ?>
                <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
            <?php endwhile; ?>
          </select>
        </label>
        <button class="btn">📦 Verschieben</button>
      </form>

      <!-- Delete -->
      <form method="post" onsubmit="return confirm('Ordner wirklich löschen? Nur leere Ordner können gelöscht werden.');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="projekt_id" value="<?= $proj ?>">
        <label>Ordner
          <select name="id" required>
            <option value="">— wählen —</option>
            <?php
              $opt=$mysqli->query("SELECT id,name FROM pendenz_ordner WHERE projekt_id={$proj} ORDER BY name");
              while($o=$opt->fetch_assoc()): ?>
                <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?> (#<?= (int)$o['id'] ?>)</option>
            <?php endwhile; ?>
          </select>
        </label>
        <button class="btn btn-danger">🗑 Löschen</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
