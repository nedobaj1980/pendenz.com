<?php
// pages/vermietungseinheiten.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';   // site_prefix(), db(), page_url(), ...
require_once __DIR__ . '/../includes/links.php';       // url_projekt(), url_konto_tool(), url_benutzer(), url_fs(), link_chip(), ...
chips_style_once();

$mysqli = $mysqli ?? db();
$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

/* ---------- Helpers ---------- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('table_exists')) {
function table_exists(mysqli $db, string $name): bool {
  $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
  if (!$stmt = $db->prepare($sql)) return false;
  $stmt->bind_param("s",$name);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = (bool)($res && $res->fetch_row());
  $stmt->close();
  return $ok;
}
}
function ensure_schema(mysqli $db): void {
  if (!table_exists($db, 'vermietungseinheiten')) {
    $db->query("
      CREATE TABLE IF NOT EXISTS vermietungseinheiten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        projekt_id INT NULL,
        liegenschaft_id INT NULL,
        adresse VARCHAR(255) NULL,
        ve_code VARCHAR(60) NULL,
        mieter_benutzer_id INT NULL,
        flaeche_m2 DECIMAL(10,2) NULL,
        miete_monat DECIMAL(12,2) NULL,
        nk_monat DECIMAL(12,2) NULL,
        fs_rel_path VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        INDEX idx_proj (projekt_id),
        INDEX idx_lieg (liegenschaft_id),
        INDEX idx_mieter (mieter_benutzer_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
  }
  // Projekte optional: Root des Dateibaums (falls noch nicht vorhanden)
  if (table_exists($db,'projekte')) {
    $db->query("ALTER TABLE projekte ADD COLUMN IF NOT EXISTS fs_rel_path VARCHAR(255) NULL");
  }
}
ensure_schema($mysqli);

/* ---------- Utility: Pfad & Scan ---------- */
function norm_label(string $s): string {
  $s = mb_strtolower($s,'UTF-8');
  $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
  $s = preg_replace('/whg\.?/','wohnung',$s);
  $s = preg_replace('/[^a-z0-9]+/','',$s);
  return $s;
}
function list_dirs(string $root, int $maxDepth=3): array {
  $out = [];
  if (!is_dir($root) || !is_readable($root)) return $out;
  $root = rtrim($root, "\\/");

  $iter = function($base, $rel, $depth) use (&$out, &$iter, $maxDepth){
    if ($depth > $maxDepth) return;
    $path = $rel ? $base . DIRECTORY_SEPARATOR . $rel : $base;
    $scan = @scandir($path);
    if (!$scan) return;
    foreach ($scan as $name) {
      if ($name==='.'||$name==='..') continue;
      $full = $path . DIRECTORY_SEPARATOR . $name;
      if (is_dir($full)) {
        $relPath = ltrim(($rel ? $rel . DIRECTORY_SEPARATOR : '') . $name, "\\/");
        $out[] = ['label'=>$relPath, 'full'=>$base . DIRECTORY_SEPARATOR . $relPath];
        $iter($base, $relPath, $depth+1);
      }
    }
  };
  $iter($root, '', 0);
  return $out;
}
function best_guess(string $veName, array $dirs): ?string {
  $nv = norm_label($veName);
  $best = null; $score = -1;
  foreach ($dirs as $d) {
    $nd = norm_label($d['label']);
    $s  = 0;
    if ($nv && $nd && str_contains($nd, $nv)) $s += 80;
    if (preg_match('/(\d+)/',$veName,$m) && str_contains($nd, $m[1])) $s += 30; // Nummerntreffer
    similar_text($nv,$nd,$pct); $s += (int)round($pct/5); // Ähnlichkeit
    if ($s>$score) { $score=$s; $best=$d['full']; }
  }
  return $best;
}

/* ---------- Daten für Selects ---------- */
$projekte = [];
if ($r = $mysqli->query("SELECT id, name, COALESCE(fs_rel_path,'') AS fs_rel_path FROM projekte ORDER BY name ASC")) {
  while ($row = $r->fetch_assoc()) $projekte[] = $row;
  $r->free();
}
$benutzer = [];
if ($r = $mysqli->query("SELECT id, name FROM benutzer ORDER BY name ASC")) {
  while ($row = $r->fetch_assoc()) $benutzer[] = $row;
  $r->free();
}
$projById = []; $projFsRootById=[];
foreach ($projekte as $p) { $projById[(int)$p['id']] = $p['name']; $projFsRootById[(int)$p['id']] = $p['fs_rel_path']; }

/* ---------- Filter ---------- */
$flt_projekt_id = isset($_GET['projekt_id']) && $_GET['projekt_id'] !== '' ? (int)$_GET['projekt_id'] : 0;

/* ---------- Logic ---------- */
$flash = "";
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    // Projekt-Root speichern
    if (isset($_POST['save_fs_root'])) {
      $pid = (int)($_POST['pid'] ?? 0);
      $root = trim($_POST['fs_root'] ?? '');
      if ($pid<=0) throw new Exception('Projekt-ID fehlt.');
      $st=$mysqli->prepare("UPDATE projekte SET fs_rel_path=? WHERE id=?");
      $st->bind_param("si",$root,$pid); $st->execute();
      $flash = "✅ Projekt-Root gespeichert.";
      $_GET['projekt_id'] = $pid; // im Projekt bleiben
    }
    // Mapping speichern (mehrere VEs gleichzeitig)
    if (isset($_POST['save_fs_map'])) {
      $pid = (int)($_POST['pid'] ?? 0);
      $map = $_POST['map'] ?? [];
      if ($map && is_array($map)) {
        $st = $mysqli->prepare("UPDATE vermietungseinheiten SET fs_rel_path=?, updated_at=NOW() WHERE id=?");
        foreach ($map as $veId=>$path) {
          $veId=(int)$veId; $path=trim((string)$path);
          if ($veId>0 && $path!=='') { $st->bind_param("si",$path,$veId); $st->execute(); }
        }
        $st->close();
        $flash = "✅ Ordner-Zuordnung gespeichert.";
      }
      $_GET['projekt_id'] = $pid;
    }

    // Standard: VE anlegen/ändern
    if (!isset($_POST['save_fs_map']) && !isset($_POST['save_fs_root'])) {
      $id        = (int)($_POST['id'] ?? 0);
      $name      = trim($_POST['name'] ?? '');
      $projektId = $_POST['projekt_id'] !== '' ? (int)$_POST['projekt_id'] : null;
      $liegId    = $_POST['liegenschaft_id'] !== '' ? (int)$_POST['liegenschaft_id'] : null;
      $addr      = trim($_POST['adresse'] ?? '');
      $veCode    = trim($_POST['ve_code'] ?? '');
      $mieterId  = $_POST['mieter_benutzer_id'] !== '' ? (int)$_POST['mieter_benutzer_id'] : null;
      $flaeche   = $_POST['flaeche_m2'] !== '' ? (float)$_POST['flaeche_m2'] : null;
      $miete     = $_POST['miete_monat'] !== '' ? (float)$_POST['miete_monat'] : null;
      $nk        = $_POST['nk_monat'] !== '' ? (float)$_POST['nk_monat'] : null;
      $fsPath    = trim($_POST['fs_rel_path'] ?? '');

      if ($name === '') throw new Exception('Name/Bezeichnung ist erforderlich.');

      if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE vermietungseinheiten
          SET name=?, projekt_id=?, liegenschaft_id=?, adresse=?, ve_code=?, mieter_benutzer_id=?,
              flaeche_m2=?, miete_monat=?, nk_monat=?, fs_rel_path=?, updated_at=NOW()
          WHERE id=?");
        $stmt->bind_param(
          "siissiidisi",
          $name, $projektId, $liegId, $addr, $veCode, $mieterId,
          $flaeche, $miete, $nk, $fsPath, $id
        );
        $stmt->execute();
        log_action($mysqli, 'vermietungseinheit', $id, 'update', ['name'=>$name]);
        $flash = "✅ Einheit aktualisiert.";
      } else {
        $stmt = $mysqli->prepare("INSERT INTO vermietungseinheiten
          (name, projekt_id, liegenschaft_id, adresse, ve_code, mieter_benutzer_id,
           flaeche_m2, miete_monat, nk_monat, fs_rel_path, created_at, updated_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
        $stmt->bind_param(
          "siissiidis",
          $name, $projektId, $liegId, $addr, $veCode, $mieterId,
          $flaeche, $miete, $nk, $fsPath
        );
        $stmt->execute();
        $newId = $stmt->insert_id;
        log_action($mysqli, 'vermietungseinheit', $newId, 'create', ['name'=>$name]);
        $flash = "✅ Einheit angelegt.";
      }
    }
  } catch (Throwable $e) {
    $flash = "❌ ".$e->getMessage();
  }
}

/* ---------- GET: Edit/Delete ---------- */
$edit = null;
if (isset($_GET['edit'])) {
  $eid=(int)$_GET['edit'];
  $st=$mysqli->prepare("SELECT * FROM vermietungseinheiten WHERE id=?");
  $st->bind_param("i",$eid); $st->execute();
  $edit=$st->get_result()->fetch_assoc(); $st->close();
}
if (isset($_GET['delete'])) {
  $flt_projekt_id = isset($_GET['projekt_id']) && $_GET['projekt_id'] !== '' ? (int)$_GET['projekt_id'] : 0;
  $did=(int)$_GET['delete'];
  try {
    $st=$mysqli->prepare("DELETE FROM vermietungseinheiten WHERE id=?");
    $st->bind_param("i",$did); $st->execute();
    log_action($mysqli,'vermietungseinheit',$did,'delete');
    header("Location: ".page_url('vermietungseinheiten.php').($flt_projekt_id?"?projekt_id=".$flt_projekt_id:""));
    exit;
  } catch(Throwable $e) { $flash="❌ Einheit kann nicht gelöscht werden."; }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

/* ---------- Liste laden ---------- */
$where=[]; $types=''; $params=[];
if ($flt_projekt_id) { $where[]="projekt_id=?"; $types.='i'; $params[]=$flt_projekt_id; }
$sql="SELECT * FROM vermietungseinheiten ".($where?("WHERE ".implode(" AND ",$where)):"")." ORDER BY name ASC";
$st=$mysqli->prepare($sql);
if ($params) $st->bind_param($types, ...$params);
$st->execute();
$list=$st->get_result();

/* ---------- Projekt-Root & Scan (für Mapping) ---------- */
$projRoot = '';
if ($flt_projekt_id) {
  $row = $mysqli->query("SELECT fs_rel_path FROM projekte WHERE id=".(int)$flt_projekt_id)->fetch_assoc();
  $projRoot = trim((string)($row['fs_rel_path'] ?? ''));
}
$dirs = [];
if ($projRoot !== '' && is_dir($projRoot)) {
  $dirs = list_dirs($projRoot, 3); // alle Unterordner bis Tiefe 3
}
?>
<style>
  .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;align-items:start}
  .form-grid .col-2{grid-column:1/-1}
  .form-grid label{font-weight:600;color:#0f172a}
  .form-grid input[type="text"], .form-grid input[type="number"], .form-grid select, .form-grid textarea{
    width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-size:14px
  }
  .inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .table{width:100%;border-collapse:collapse}
  .table th,.table td{padding:8px 10px;border-bottom:1px solid #e5e7eb;vertical-align:top}
</style>

<div class="container">
  <header class="hero hero-blue" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Vermietungseinheiten</h1>
    <div class="inline">
      <form method="get" class="inline">
        <select name="projekt_id" onchange="this.form.submit()">
          <option value="">– Alle Projekte –</option>
          <?php foreach ($projekte as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $flt_projekt_id===(int)$p['id']?'selected':'' ?>>
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($flt_projekt_id): ?>
          <?= link_chip('Projekt öffnen', url_projekt($flt_projekt_id), '📁') ?>
        <?php endif; ?>
        <a class="btn btn-head" href="<?= h(page_url('vermietungseinheiten.php')) ?>">➕ Neue Einheit</a>
      </form>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c; margin-bottom:10px; padding:8px 10px; background:#f4fffa;">
      <?= h($flash) ?>
    </div>
  <?php endif; ?>

  <?php if ($flt_projekt_id): ?>
    <div class="card">
      <h2 style="margin-top:0;">Ordner-Mapping (Projekt)</h2>

      <form method="post" class="inline" style="margin-bottom:10px">
        <input type="hidden" name="save_fs_root" value="1">
        <input type="hidden" name="pid" value="<?= (int)$flt_projekt_id ?>">
        <label>Projekt-Root &nbsp;
          <input type="text" name="fs_root" style="min-width:460px"
                 placeholder="z. B. C:\Ordnerstruktur für Arbonerstrasse"
                 value="<?= h($projRoot) ?>">
        </label>
        <button class="btn" type="submit">💾 Speichern</button>
        <?php if ($projRoot): ?>
          <span class="muted">Status: <?= is_dir($projRoot)?'✔️ gefunden':'❌ nicht vorhanden' ?></span>
        <?php endif; ?>
      </form>

      <?php if ($projRoot && is_dir($projRoot)): ?>
        <form method="post">
          <input type="hidden" name="save_fs_map" value="1">
          <input type="hidden" name="pid" value="<?= (int)$flt_projekt_id ?>">

          <table class="table">
            <thead>
              <tr>
                <th>VE</th>
                <th>Mieter</th>
                <th>Vorschlag</th>
                <th>Ordner auswählen</th>
                <th>Aktuell gespeichert</th>
                <th>Link</th>
              </tr>
            </thead>
            <tbody>
            <?php
              // Verfügbare Ordnerliste (nur Verzeichnisse)
              $choices = array_map(fn($d)=>$d['full'], $dirs);
              sort($choices, SORT_NATURAL|SORT_FLAG_CASE);
              while($ve = $list->fetch_assoc()):
                if ((int)$ve['projekt_id'] !== (int)$flt_projekt_id) continue;
                $uid = (int)($ve['mieter_benutzer_id'] ?? 0);
                $mieterName = $uid ? ($mysqli->query("SELECT name FROM benutzer WHERE id={$uid}")->fetch_row()[0] ?? '') : '';
                $suggest = $dirs ? best_guess((string)$ve['name'], $dirs) : null;
                $saved   = trim((string)($ve['fs_rel_path'] ?? ''));
            ?>
              <tr>
                <td><strong><?= h($ve['name']) ?></strong></td>
                <td><?= $uid ? h($mieterName) : '—' ?></td>
                <td class="muted" style="max-width:320px;word-break:break-all"><?= $suggest ? h($suggest) : '—' ?></td>
                <td>
                  <select name="map[<?= (int)$ve['id'] ?>]" style="min-width:420px">
                    <option value="">— nicht zuordnen —</option>
                    <?php foreach ($choices as $full): ?>
                      <?php
                        $sel = '';
                        if ($saved && $saved === $full) $sel='selected';
                        elseif (!$saved && $suggest && $suggest === $full) $sel='selected';
                      ?>
                      <option value="<?= h($full) ?>" <?= $sel ?>><?= h($full) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="muted" style="max-width:320px;word-break:break-all"><?= $saved ? h($saved) : '—' ?></td>
                <td>
                  <?php if ($saved): ?>
                    <?= link_chip('🗂️ öffnen', url_fs($saved)) ?>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endwhile; $list->data_seek(0); // Liste zurücksetzen für Tabelle unten ?>
            </tbody>
          </table>

          <div class="inline" style="margin-top:8px">
            <button class="btn" type="submit">💾 Zuordnung speichern</button>
            <span class="muted">Tipp: Vorschläge werden automatisch vorgewählt – einfach prüfen & speichern.</span>
          </div>
        </form>
      <?php else: ?>
        <p class="muted">Lege oben den **Projekt-Root** fest (z. B. den von „Ordner-Vorlagen“ angelegten Ordner), damit die Ordner erkannt werden.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0;"><?= $edit ? "Einheit bearbeiten" : "Neue Einheit" ?></h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

      <div>
        <label>Name / Bezeichnung*</label>
        <input type="text" name="name" required value="<?= h($edit['name'] ?? '') ?>" placeholder="z.B. Whg 2.OG rechts">
      </div>

      <div>
        <label>Projekt</label>
        <select name="projekt_id">
          <option value="">– ohne –</option>
          <?php foreach ($projekte as $p): $pid=(int)$p['id']; ?>
            <option value="<?= $pid ?>" <?= (string)($edit['projekt_id'] ?? $flt_projekt_id) === (string)$pid ? 'selected':'' ?>>
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Liegenschaft (ID)</label>
        <input type="number" name="liegenschaft_id" value="<?= h($edit['liegenschaft_id'] ?? '') ?>" placeholder="optional">
      </div>

      <div>
        <label>Mieter (Benutzer)</label>
        <select name="mieter_benutzer_id">
          <option value="">– keiner –</option>
          <?php foreach ($benutzer as $u): $uid=(int)$u['id']; ?>
            <option value="<?= $uid ?>" <?= (string)($edit['mieter_benutzer_id'] ?? '')===(string)$uid ? 'selected':'' ?>>
              <?= h($u['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-2">
        <label>Adresse</label>
        <input type="text" name="adresse" value="<?= h($edit['adresse'] ?? '') ?>" placeholder="Straße Nr., PLZ Ort">
      </div>

      <div>
        <label>Einheits-Code</label>
        <input type="text" name="ve_code" value="<?= h($edit['ve_code'] ?? '') ?>" placeholder="intern, z.B. TOP-12">
      </div>

      <div>
        <label>Fläche (m²)</label>
        <input type="number" step="0.01" name="flaeche_m2" value="<?= h($edit['flaeche_m2'] ?? '') ?>">
      </div>

      <div>
        <label>Miete/Monat</label>
        <input type="number" step="0.01" name="miete_monat" value="<?= h($edit['miete_monat'] ?? '') ?>">
      </div>

      <div>
        <label>NK/Monat</label>
        <input type="number" step="0.01" name="nk_monat" value="<?= h($edit['nk_monat'] ?? '') ?>">
      </div>

      <div class="col-2">
        <label>Ordner (voller Pfad)</label>
        <input type="text" name="fs_rel_path" value="<?= h($edit['fs_rel_path'] ?? '') ?>" placeholder="z.B. C:\Ordnerstruktur …\Wohnungen\Whg 2.OG rechts">
        <small class="muted">Wird im Mapping oben automatisch gesetzt – hier nur manuell ändern.</small>
      </div>

      <div class="col-2">
        <button class="btn" type="submit">Speichern</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Einheiten-Liste <?= $flt_projekt_id ? ' – Projekt: '.h($projById[$flt_projekt_id] ?? ('#'.$flt_projekt_id)) : '' ?></h2>
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Projekt</th>
          <th>Mieter</th>
          <th>Adresse</th>
          <th>Code</th>
          <th>Miete/NK</th>
          <th>Links</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php while($r = $list->fetch_assoc()):
          $pid = (int)($r['projekt_id'] ?? 0);
          $uid = (int)($r['mieter_benutzer_id'] ?? 0);
          $miete = $r['miete_monat'] !== null ? (float)$r['miete_monat'] : null;
          $nk    = $r['nk_monat']    !== null ? (float)$r['nk_monat']    : null;
        ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= h($r['name']) ?></td>
            <td>
              <?php if ($pid): ?>
                <?= link_chip($projById[$pid] ?? ('#'.$pid), url_projekt($pid), '📁') ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?php if ($uid):
                $uname = $mysqli->query("SELECT name FROM benutzer WHERE id={$uid}")->fetch_row()[0] ?? '';
              ?>
                <?= link_chip($uname ?: ('#'.$uid), url_benutzer($uid), '👤') ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= h($r['adresse'] ?? '') ?></td>
            <td><?= h($r['ve_code'] ?? '') ?></td>
            <td>
              <?php if ($miete !== null || $nk !== null): ?>
                <?= $miete!==null ? number_format($miete,2,'.','’').' CHF' : '—' ?>
                /
                <?= $nk!==null ? number_format($nk,2,'.','’').' CHF' : '—' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="inline">
              <?= link_chip('💳 Konto', url_konto_tool(['ve_id'=>(int)$r['id']]), '') ?>
              <?php if (!empty($r['fs_rel_path'])): ?>
                <?= link_chip('🗂️ Ordner', url_fs($r['fs_rel_path']), '') ?>
              <?php endif; ?>
            </td>
            <td class="inline">
              <a class="btn btn-small" href="?edit=<?= (int)$r['id'] ?><?= $flt_projekt_id?'&projekt_id='.$flt_projekt_id:'' ?>">Bearbeiten</a>
              <a class="btn btn-danger btn-small" href="?delete=<?= (int)$r['id'] ?><?= $flt_projekt_id?'&projekt_id='.$flt_projekt_id:'' ?>" onclick="return confirm('Einheit wirklich löschen?')">Löschen</a>
            </td>
          </tr>
        <?php endwhile; $list->free(); ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
