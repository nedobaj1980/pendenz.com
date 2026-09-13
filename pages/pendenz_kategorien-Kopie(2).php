<?php
// pages/pendenz_kategorien.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_login();
require_once __DIR__.'/../includes/functions.php';

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$PREFIX = site_prefix();
$flash = "";

// Tabellen sicherstellen
$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_kategorien (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  projekt_id INT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(projekt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_subkategorien (
  id INT AUTO_INCREMENT PRIMARY KEY,
  kategorie_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(kategorie_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Sicherstellen dass projekt_id Spalte existiert
@$mysqli->query("ALTER TABLE pendenz_kategorien ADD COLUMN IF NOT EXISTS projekt_id INT NULL");

// Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $act = $_POST['act'] ?? '';
        if ($act === 'add_cat') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Name erforderlich.');
            $pid = (int)($_POST['projekt_id'] ?? 0);
            if ($pid <= 0) $pid = null;
            $st = $mysqli->prepare("INSERT INTO pendenz_kategorien (name, projekt_id) VALUES (?, ?)");
            $st->bind_param("si", $name, $pid); $st->execute();
            $flash = "✅ Titel-Vorlage erstellt.";
        } elseif ($act === 'rename_cat') {
            $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') throw new Exception('Parameter fehlen.');
            $st = $mysqli->prepare("UPDATE pendenz_kategorien SET name=? WHERE id=?");
            $st->bind_param("si", $name, $id); $st->execute();
            $flash = "✅ Titel-Vorlage umbenannt.";
        } elseif ($act === 'del_cat') {
            $id = (int)($_POST['id'] ?? 0); if ($id <= 0) throw new Exception('ID fehlt.');
            $mysqli->query("DELETE FROM pendenz_subkategorien WHERE kategorie_id=$id");
            $st = $mysqli->prepare("DELETE FROM pendenz_kategorien WHERE id=?"); $st->bind_param("i", $id); $st->execute();
            $flash = "🗑️ Titel-Vorlage gelöscht.";
        } elseif ($act === 'add_sub') {
            $cid = (int)($_POST['cid'] ?? 0); $name = trim($_POST['name'] ?? '');
            if ($cid <= 0 || $name === '') throw new Exception('Titel & Betreff angeben.');
            $st = $mysqli->prepare("INSERT INTO pendenz_subkategorien (kategorie_id,name) VALUES (?,?)");
            $st->bind_param("is", $cid, $name); $st->execute();
            $flash = "✅ Betreff-Eintrag erstellt.";
        } elseif ($act === 'rename_sub') {
            $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? '');
            if ($id <= 0 || $name === '') throw new Exception('Parameter fehlen.');
            $st = $mysqli->prepare("UPDATE pendenz_subkategorien SET name=? WHERE id=?");
            $st->bind_param("si", $name, $id); $st->execute();
            $flash = "✅ Betreff-Eintrag umbenannt.";
        } elseif ($act === 'del_sub') {
            $id = (int)($_POST['id'] ?? 0); if ($id <= 0) throw new Exception('ID fehlt.');
            $st = $mysqli->prepare("DELETE FROM pendenz_subkategorien WHERE id=?"); $st->bind_param("i", $id); $st->execute();
            $flash = "🗑️ Betreff-Eintrag gelöscht.";
        }
    } catch (Throwable $e) {
        $flash = "❌ " . $e->getMessage();
    }
    $redir = $PREFIX . "pages/pendenz_kategorien.php";
    if (!empty($_POST['filter_projekt_id'])) $redir .= '?projekt_id=' . (int)$_POST['filter_projekt_id'];
    header("Location: " . $redir);
    exit;
}

// Filter
$filterPid = (int)($_GET['projekt_id'] ?? 0);

// Daten laden
$allProjs = $mysqli->query("SELECT id, name FROM projekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$whereCat = $filterPid > 0 ? "WHERE (projekt_id = $filterPid OR projekt_id IS NULL)" : "";
$cats = [];
$rs = $mysqli->query("SELECT k.id, k.name, k.sort_order, k.projekt_id, p.name AS projekt_name
    FROM pendenz_kategorien k LEFT JOIN projekte p ON p.id=k.projekt_id
    $whereCat ORDER BY k.sort_order, k.name");
if ($rs) while($x = $rs->fetch_assoc()) $cats[] = $x;

$subsByCat = [];
$r2 = $mysqli->query("SELECT id, kategorie_id, name FROM pendenz_subkategorien ORDER BY sort_order, name");
if ($r2) while($x = $r2->fetch_assoc()) $subsByCat[(int)$x['kategorie_id']][] = $x;

$projById = [];
foreach ($allProjs as $p) $projById[(int)$p['id']] = $p['name'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
:root { --teal: #0ea5e9; --teal-dark: #0284c7; }
body { background: #f1f5f9; }
.kcat-wrap { max-width: 1200px; margin: 0 auto; padding: 24px; }

/* Header */
.kcat-hero { background: linear-gradient(135deg, #1abc9c, #0e8a72); border-radius: 16px; padding: 24px 28px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.kcat-hero h1 { margin: 0; color: #fff; font-size: 22px; font-weight: 800; }
.kcat-hero p { margin: 4px 0 0; color: rgba(255,255,255,0.85); font-size: 13px; }

/* Toolbar */
.kcat-toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; }
.kcat-toolbar select, .kcat-toolbar input[type=text] { height: 40px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; }
.kcat-toolbar input[type=text] { flex: 1; min-width: 180px; }
.kcat-toolbar .btn-teal { background: #1abc9c; color: #fff; border: none; padding: 0 20px; height: 40px; border-radius: 8px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.kcat-toolbar .btn-teal:hover { background: #0e8a72; }

/* Filter bar */
.kcat-filter { display: flex; gap: 10px; align-items: center; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 16px; margin-bottom: 20px; flex-wrap: wrap; }
.kcat-filter label { font-size: 13px; font-weight: 600; color: #475569; }
.kcat-filter select { height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; background: #f8fafc; }
.badge-global { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 20px; font-size: 11px; font-weight: 700; padding: 2px 8px; }
.badge-proj { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; border-radius: 20px; font-size: 11px; font-weight: 700; padding: 2px 8px; }

/* Category list */
.kcat-list { display: flex; flex-direction: column; gap: 10px; }
.kcat-row { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
.kcat-row-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; }
.kcat-row-head .kcat-name { font-weight: 700; font-size: 15px; color: #0f172a; flex: 1; }
.kcat-row-name-form { display: flex; gap: 6px; flex: 1; align-items: center; }
.kcat-row-name-form input { flex: 1; height: 34px; padding: 0 10px; border: 1px solid #e2e8f0; border-radius: 7px; font-size: 13px; font-weight: 600; background: #f8fafc; }
.kcat-row-name-form input:focus { border-color: #1abc9c; outline: none; background: #fff; }
.kcat-row-name-form .btn-save { background: #f0fdf4; border: 1px solid #86efac; color: #166534; height: 34px; padding: 0 14px; border-radius: 7px; font-weight: 700; font-size: 12px; cursor: pointer; }
.kcat-row-name-form .btn-save:hover { background: #dcfce7; }
.btn-del-sm { background: #fff0f3; border: 1px solid #fecdd3; color: #be123c; height: 34px; padding: 0 12px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.btn-del-sm:hover { background: #ffe4e6; }

/* Subs */
.kcat-subs { padding: 10px 16px 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sub-chip { display: inline-flex; align-items: center; gap: 6px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px; padding: 4px 10px; font-size: 13px; }
.sub-chip input.sub-edit { border: none; background: transparent; font-size: 13px; width: 100px; outline: none; padding: 0; font-weight: 600; color: #1e293b; }
.sub-chip input.sub-edit:focus { border-bottom: 1px solid #1abc9c; }
.sub-chip .btn-sub-save { background: none; border: none; cursor: pointer; font-size: 13px; opacity: 0.6; padding: 0 2px; }
.sub-chip .btn-sub-save:hover { opacity: 1; }
.sub-chip .btn-sub-del { background: none; border: none; cursor: pointer; color: #e11d48; font-size: 14px; padding: 0 2px; line-height:1; }
.sub-chip .btn-sub-del:hover { color: #be123c; }
.kcat-add-sub { display: flex; gap: 6px; align-items: center; margin-top: 4px; }
.kcat-add-sub input { height: 32px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; width: 180px; }
.kcat-add-sub .btn-add-sub { background: #1abc9c22; border: 1px solid #1abc9c66; color: #0e7a62; height: 32px; padding: 0 14px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; }
.kcat-add-sub .btn-add-sub:hover { background: #1abc9c44; }

.flash-ok { background: #f0fdf4; border: 1px solid #86efac; color: #166534; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-weight: 600; }
.flash-err { background: #fff0f3; border: 1px solid #fecdd3; color: #be123c; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-weight: 600; }
.empty-hint { text-align: center; padding: 40px 20px; color: #94a3b8; font-size: 14px; }
</style>

<div class="kcat-wrap">

  <!-- Header -->
  <div class="kcat-hero">
    <div>
      <h1>📝 Text-Vorlagen verwalten</h1>
      <p>Schneller erfassen — vordefinierte Titel (Hauptgruppe) & Betreff (Untergruppe)</p>
    </div>
    <div style="display:flex;gap:10px;">
      <a class="btn" style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);color:#fff;" href="pendenzen.php">← Dashboard</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="<?= str_starts_with($flash,'✅') ? 'flash-ok' : 'flash-err' ?>"><?= h($flash) ?></div>
  <?php endif; ?>

  <!-- Neue Kategorie hinzufügen -->
  <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin-bottom:20px;">
    <div style="font-size:13px;font-weight:700;color:#475569;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px;">+ Neue Titel-Vorlage</div>
    <form method="post" class="kcat-toolbar" style="margin:0;padding:0;border:none;background:transparent;">
      <input type="hidden" name="act" value="add_cat">
      <input type="hidden" name="filter_projekt_id" value="<?= $filterPid ?>">
      <input type="text" name="name" placeholder="Titel-Name (z.B. Malerarbeiten)…" required style="flex:2;min-width:200px;height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
      <select name="projekt_id" style="height:40px;padding:0 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;min-width:200px;">
        <option value="">🌐 Global (alle Projekte)</option>
        <?php foreach ($allProjs as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $filterPid === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-teal" type="submit">Hinzufügen</button>
    </form>
  </div>

  <!-- Filter -->
  <form method="get" class="kcat-filter">
    <label>Anzeigen:</label>
    <select name="projekt_id" onchange="this.form.submit()">
      <option value="">Alle (Global + Projektspezifisch)</option>
      <option value="-1" <?= $filterPid === -1 ? 'selected' : '' ?>>Nur Globale</option>
      <?php foreach ($allProjs as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $filterPid === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <span style="color:#94a3b8;font-size:13px;"><?= count($cats) ?> Titel-Vorlagen</span>
  </form>

  <!-- Kategorien-Liste -->
  <div class="kcat-list">
    <?php if (empty($cats)): ?>
      <div class="empty-hint">Keine Vorlagen — erstelle deinen ersten Titel oben! 👆</div>
    <?php else: foreach ($cats as $c): $cid = (int)$c['id']; $subs = $subsByCat[$cid] ?? []; ?>
      <div class="kcat-row">
        <!-- Kategorie-Header -->
        <div class="kcat-row-head">
          <form method="post" class="kcat-row-name-form" style="flex:1">
            <input type="hidden" name="act" value="rename_cat">
            <input type="hidden" name="id" value="<?= $cid ?>">
            <input type="hidden" name="filter_projekt_id" value="<?= $filterPid ?>">
            <input type="text" name="name" value="<?= h($c['name']) ?>" required>
            <button class="btn-save" type="submit">Speichern</button>
          </form>
          <!-- Projekt-Badge -->
          <?php if ($c['projekt_id']): ?>
            <span class="badge-proj">📁 <?= h($c['projekt_name'] ?? 'Projekt #'.$c['projekt_id']) ?></span>
          <?php else: ?>
            <span class="badge-global">🌐 Global</span>
          <?php endif; ?>
          <span style="color:#94a3b8;font-size:12px;"><?= count($subs) ?> Sub<?= count($subs) !== 1 ? 's' : '' ?></span>
          <!-- Löschen -->
          <form method="post" onsubmit="return confirm('Titel inkl. aller Betreff-Vorlagen löschen?')">
            <input type="hidden" name="act" value="del_cat">
            <input type="hidden" name="id" value="<?= $cid ?>">
            <input type="hidden" name="filter_projekt_id" value="<?= $filterPid ?>">
            <button class="btn-del-sm" type="submit">🗑️</button>
          </form>
        </div>

        <!-- Unterkategorien als Chips -->
        <div class="kcat-subs">
          <?php foreach ($subs as $s): ?>
            <div class="sub-chip">
              <form method="post" style="display:inline-flex;align-items:center;gap:4px;">
                <input type="hidden" name="act" value="rename_sub">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="filter_projekt_id" value="<?= $filterPid ?>">
                <input class="sub-edit" type="text" name="name" value="<?= h($s['name']) ?>" required title="Enter zum Speichern" onkeydown="if(event.key==='Enter'){this.closest('form').submit();}">
                <button class="btn-sub-save" type="submit" title="Speichern">✓</button>
              </form>
              <form method="post" onsubmit="return confirm('Unterkategorie löschen?')" style="display:inline">
                <input type="hidden" name="act" value="del_sub">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="hidden" name="filter_projekt_id" value="<?= $filterPid ?>">
                <button class="btn-sub-del" type="submit" title="Löschen">×</button>
              </form>
            </div>
          <?php endforeach; ?>

          <!-- Neue Unterkategorie -->
          <form method="post" class="kcat-add-sub">
            <input type="hidden" name="act" value="add_sub">
            <input type="hidden" name="cid" value="<?= $cid ?>">
            <input type="hidden" name="filter_projekt_id" value="<?= $filterPid ?>">
            <input type="text" name="name" placeholder="+ Detail / Betreff…">
            <button class="btn-add-sub" type="submit">Hinzufügen</button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
