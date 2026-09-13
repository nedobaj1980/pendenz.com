<?php
// pages/personen_taxonomie.php
if (session_status()===PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_login();

$role = $_SESSION['rolle'] ?? 'gast';
if (!in_array($role, ['admin','superadmin'], true)) { http_response_code(403); exit('Keine Berechtigung'); }

require_once __DIR__.'/../includes/csrf.php';           // Standard-CSRF
require_once __DIR__.'/../includes/user_taxonomy.php';  // Funktionen oben
// Sicherstellen, dass Tabellen existieren
user_taxonomy_ensure_tables($mysqli);

$flash = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require();
  try{
    $action = $_POST['action'] ?? '';
    if ($action==='type_add') {
      user_type_create($mysqli, (string)($_POST['name']??''), (int)($_POST['sort']??100));
      $flash='✅ Typ angelegt.';
    }
    elseif ($action==='type_rename') {
      user_type_rename($mysqli, (int)$_POST['id'], (string)$_POST['name']);
      $flash='✅ Typ umbenannt.';
    }
    elseif ($action==='type_delete') {
      user_type_delete($mysqli, (int)$_POST['id']);
      $flash='🗑️ Typ gelöscht.';
    }
    elseif ($action==='type_move') {
      user_type_move($mysqli, (int)$_POST['id'], (string)$_POST['dir']);
      $flash='↕️ Typ sortiert.';
    }
    elseif ($action==='status_add') {
      user_status_create($mysqli, (int)$_POST['type_id'], (string)$_POST['name'], (int)($_POST['sort']??100), 0);
      $flash='✅ Status angelegt.';
    }
    elseif ($action==='status_rename') {
      user_status_rename($mysqli, (int)$_POST['id'], (string)$_POST['name']);
      $flash='✅ Status umbenannt.';
    }
    elseif ($action==='status_delete') {
      user_status_delete($mysqli, (int)$_POST['id']);
      $flash='🗑️ Status gelöscht.';
    }
    elseif ($action==='status_move') {
      user_status_move($mysqli, (int)$_POST['id'], (string)$_POST['dir']);
      $flash='↕️ Status sortiert.';
    }
    elseif ($action==='status_set_default') {
      user_status_set_default($mysqli, (int)$_POST['id']);
      $flash='⭐ Standard-Status gesetzt.';
    }
  } catch(Throwable $e){
    $flash = '❌ '.h($e->getMessage());
  }
  // PRG
  $PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';
  header('Location: '.$PREFIX.'pages/personen_taxonomie.php?msg='.urlencode($flash));
  exit;
}

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/nav_dispatch.php';

$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

// Daten laden
$types = user_types_all($mysqli);
$statusesMap = user_statuses_all_grouped($mysqli);
$flash = $_GET['msg'] ?? $flash;
?>
<style>
.container{max-width:1100px;margin:20px auto;font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;color:#0f172a}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin:12px 0}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:900px){.grid{grid-template-columns:1fr}}
h1{margin:0 0 8px;font-size:22px}
h2{margin:0 0 8px;font-size:18px}
.small{font-size:12px;color:#475569}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid #eef2f7;padding:8px;text-align:left}
.btn{padding:6px 10px;border:0;border-radius:8px;background:#0b5cff;color:#fff;cursor:pointer}
.btn:hover{background:#1e40af}
.btn-light{padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;cursor:pointer}
.btn-light:hover{background:#f1f5f9}
.btn-danger{background:#b00020}
.row{display:flex;gap:8px;flex-wrap:wrap}
input[type="text"],input[type="number"],select{padding:8px;border:1px solid #cbd5e1;border-radius:8px}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#0b5cff;font-size:12px}
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px}
</style>

<div class="container">
  <div class="toolbar">
    <h1>Personen-Typen & Status</h1>
    <div class="row">
      <a class="btn-light" href="<?= h($PREFIX) ?>pages/benutzer.php">← Zur Benutzerseite</a>
      <a class="btn-light" href="<?= h($PREFIX) ?>pages/admin_matrix_hub.php">Mitgliedschaften verwalten</a>
      <a class="btn-light" href="<?= h($PREFIX) ?>pages/firmen.php">Firmen-Vorlagen</a>
    </div>
  </div>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #22c55e"><strong><?= $flash ?></strong></div>
  <?php endif; ?>

  <div class="grid">
    <!-- Typen -->
    <div class="card">
      <h2>Typen</h2>
      <form class="row" method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="type_add">
        <input type="text" name="name" placeholder="Neuer Typ (z. B. Mieter)" required>
        <input type="number" name="sort" placeholder="Sortierung" value="100" style="width:120px">
        <button class="btn">+ Anlegen</button>
      </form>

      <table class="table" style="margin-top:8px">
        <thead><tr><th>Sort</th><th>Name</th><th>Aktionen</th></tr></thead>
        <tbody>
          <?php foreach($types as $t): ?>
          <tr>
            <td>
              <form method="post" style="display:inline">
                <?= csrf_input() ?><input type="hidden" name="action" value="type_move">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="dir" value="up">
                <button class="btn-light" title="nach oben">▲</button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_input() ?><input type="hidden" name="action" value="type_move">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="dir" value="down">
                <button class="btn-light" title="nach unten">▼</button>
              </form>
              <span class="badge">#<?= (int)$t['sort'] ?></span>
            </td>
            <td>
              <form method="post" class="row" style="align-items:center">
                <?= csrf_input() ?><input type="hidden" name="action" value="type_rename">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input type="text" name="name" value="<?= h($t['name']) ?>" required>
                <button class="btn-light">Speichern</button>
              </form>
            </td>
            <td>
              <form method="post" onsubmit="return confirm('Typ wirklich löschen? (zugehörige Status werden entfernt)')">
                <?= csrf_input() ?><input type="hidden" name="action" value="type_delete">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-danger">Löschen</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$types): ?><tr><td colspan="3" class="small">Noch keine Typen angelegt.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Status -->
    <div class="card">
      <h2>Status</h2>
      <form class="row" method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="status_add">
        <select name="type_id" required>
          <option value="">— Typ wählen —</option>
          <?php foreach($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="name" placeholder="Neuer Status (z. B. Interessent)" required>
        <input type="number" name="sort" placeholder="Sortierung" value="100" style="width:120px">
        <button class="btn">+ Anlegen</button>
      </form>

      <?php foreach($types as $t): $tid=(int)$t['id']; $list=$statusesMap[$tid]??[]; ?>
        <div class="card" style="margin-top:10px">
          <div class="row" style="justify-content:space-between">
            <strong>Status für Typ: <?= h($t['name']) ?></strong>
            <span class="small">Reihenfolge: sort ASC</span>
          </div>
          <table class="table" style="margin-top:6px">
            <thead><tr><th>Sort</th><th>Name</th><th>Standard</th><th>Aktionen</th></tr></thead>
            <tbody>
              <?php foreach($list as $s): ?>
              <tr>
                <td>
                  <form method="post" style="display:inline">
                    <?= csrf_input() ?><input type="hidden" name="action" value="status_move">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="dir" value="up">
                    <button class="btn-light" title="nach oben">▲</button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_input() ?><input type="hidden" name="action" value="status_move">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="dir" value="down">
                    <button class="btn-light" title="nach unten">▼</button>
                  </form>
                  <span class="badge">#<?= (int)$s['sort'] ?></span>
                </td>
                <td>
                  <form method="post" class="row" style="align-items:center">
                    <?= csrf_input() ?><input type="hidden" name="action" value="status_rename">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <input type="text" name="name" value="<?= h($s['name']) ?>" required>
                    <button class="btn-light">Speichern</button>
                  </form>
                </td>
                <td>
                  <form method="post" style="display:inline">
                    <?= csrf_input() ?><input type="hidden" name="action" value="status_set_default">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn-light" title="Als Standard markieren"><?= !empty($s['is_default'])?'⭐ Standard':'☆ Setzen' ?></button>
                  </form>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('Status wirklich löschen?')">
                    <?= csrf_input() ?><input type="hidden" name="action" value="status_delete">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-danger">Löschen</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if(!$list): ?><tr><td colspan="4" class="small">Noch keine Status für diesen Typ.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
