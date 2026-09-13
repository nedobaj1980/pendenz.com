<?php
// pages/projekte.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();
require_once __DIR__ . '/../includes/functions.php'; // site_prefix(), url(), page_url(), best_image_url(), handle_upload(), db(), ...
require_once __DIR__ . '/../includes/links.php';
chips_style_once();
require_once __DIR__ . '/../includes/user_ui.php';

/* ========= Helpers ========= */
if (!function_exists('h')) {
  function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
$mysqli = $mysqli ?? db();

function table_exists(mysqli $db, string $name): bool {
  $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
  if (!$stmt = $db->prepare($sql)) return false;
  $stmt->bind_param("s", $name);
  $stmt->execute();
  $res = $stmt->get_result();
  $exists = (bool)($res && $res->fetch_row());
  $stmt->close();
  return $exists;
}

/** Konto-Modul minimal installieren (kv_*) */
function install_kv_schema(mysqli $db): void {
  $sqls = [
    // Konten
    "CREATE TABLE IF NOT EXISTS kv_konten (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      iban VARCHAR(34) DEFAULT NULL,
      bank VARCHAR(120) DEFAULT NULL,
      waehrung CHAR(3) DEFAULT 'CHF',
      liegenschaft_id INT NULL,
      projekt_id INT NULL,
      fs_rel_path VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT NOW(),
      updated_at DATETIME NOT NULL DEFAULT NOW(),
      UNIQUE KEY uniq_iban (iban),
      INDEX idx_k_proj (projekt_id),
      INDEX idx_k_lieg (liegenschaft_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Import-Batches (optional – hilfreich für CSV-Imports)
    "CREATE TABLE IF NOT EXISTS kv_import_batches (
      id INT AUTO_INCREMENT PRIMARY KEY,
      konto_id INT NULL,
      filename VARCHAR(255) NOT NULL,
      imported_at DATETIME NOT NULL,
      row_count INT NOT NULL DEFAULT 0,
      file_hash CHAR(64) NOT NULL,
      FOREIGN KEY (konto_id) REFERENCES kv_konten(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Buchungen
    "CREATE TABLE IF NOT EXISTS kv_buchungen (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      konto_id INT NULL,
      buchungstag DATE NULL,
      valutadatum DATE NULL,
      betrag DECIMAL(12,2) NOT NULL,
      waehrung CHAR(3) DEFAULT 'CHF',
      text_raw TEXT NULL,
      gegenkonto_name VARCHAR(255) NULL,
      gegenkonto_iban VARCHAR(34) NULL,
      referenz VARCHAR(140) NULL,
      zweck TEXT NULL,
      import_batch_id INT NULL,
      matched_benutzer_id INT NULL,
      matched_ve_id INT NULL,
      matched_liegenschaft_id INT NULL,
      match_score INT NULL,
      kategorie VARCHAR(40) DEFAULT NULL,
      status ENUM('offen','zugeordnet','gesplittet','ignoriert') DEFAULT 'offen',
      created_at DATETIME NOT NULL DEFAULT NOW(),
      updated_at DATETIME NOT NULL DEFAULT NOW(),
      UNIQUE KEY uniq_guard (konto_id, buchungstag, betrag, referenz(40), gegenkonto_iban),
      INDEX (referenz),
      INDEX (gegenkonto_iban),
      INDEX (matched_benutzer_id),
      FOREIGN KEY (konto_id) REFERENCES kv_konten(id) ON DELETE SET NULL,
      FOREIGN KEY (import_batch_id) REFERENCES kv_import_batches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
  ];
  foreach ($sqls as $sql) { $db->query($sql); }
}

/* ===== Bildpfad-Helper ===== */
if (!function_exists('proj_image_url')) {
  function proj_image_url(?string $dbVal): ?string {
    if (!$dbVal) return null;
    $v = ltrim($dbVal, '/');
    if (!str_starts_with($v, 'uploads/')) $v = 'uploads/' . $v;
    return site_prefix() . $v;
  }
}
if (!function_exists('proj_image_exists')) {
  function proj_image_exists(?string $dbVal): bool {
    if (!$dbVal) return false;
    $v = ltrim($dbVal, '/');
    if (!str_starts_with($v, 'uploads/')) $v = 'uploads/' . $v;
    return is_file(__DIR__ . '/../' . $v);
  }
}

/* ===== Logik ===== */
$flash = "";

/* --- Aktionen für Konto-Verwaltung (pro Projekt) --- */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'install_kv' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    install_kv_schema($mysqli);
    $flash = "✅ Konto-Modul (kv_konten/kv_buchungen) installiert.";
    if (isset($_POST['projekt_id'])) $_GET['edit'] = (int)$_POST['projekt_id'];
  } catch (Throwable $e) {
    $flash = "❌ Installation fehlgeschlagen: " . $e->getMessage();
  }
}

if ($action === 'konto_add' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $pid = (int)($_POST['projekt_id'] ?? 0);
    $kname = trim($_POST['konto_name'] ?? '');
    $kiban = trim($_POST['konto_iban'] ?? '');
    if (!$pid) throw new Exception('Projekt-ID fehlt.');
    if ($kname === '') throw new Exception('Kontoname ist erforderlich.');

    if (!table_exists($mysqli,'kv_konten')) install_kv_schema($mysqli);

    $stmt = $mysqli->prepare("INSERT INTO kv_konten (name,iban,bank,waehrung,liegenschaft_id,projekt_id,fs_rel_path,created_at,updated_at)
                              VALUES (?,?,NULL,'CHF',NULL,?,NULL,NOW(),NOW())");
    $stmt->bind_param("ssi",$kname,$kiban,$pid);
    $stmt->execute();
    $flash = "✅ Konto verknüpft.";
    $_GET['edit'] = $pid; // nach Speichern im selben Projekt bleiben
  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

if ($action === 'konto_unlink' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $pid = (int)($_POST['projekt_id'] ?? 0);
    $kid = (int)($_POST['konto_id'] ?? 0);
    if (!$pid || !$kid) throw new Exception('Angaben unvollständig.');
    if (!table_exists($mysqli,'kv_konten')) throw new Exception('Konto-Modul ist nicht installiert.');

    $stmt = $mysqli->prepare("UPDATE kv_konten SET projekt_id=NULL, updated_at=NOW() WHERE id=? AND projekt_id=?");
    $stmt->bind_param("ii",$kid,$pid);
    $stmt->execute();
    $flash = "ℹ️ Konto vom Projekt gelöst (nicht gelöscht).";
    $_GET['edit'] = $pid;
  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

/* --- Projekt speichern --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action,['konto_add','konto_unlink','install_kv'],true)) {
  try {
    $id           = (int)($_POST['id'] ?? 0);
    $name         = trim($_POST['name'] ?? "");
    $adresse      = trim($_POST['adresse'] ?? "");
    $beschreibung = trim($_POST['beschreibung'] ?? "");
    $startdatum   = $_POST['startdatum'] ?: null;
    $enddatum     = $_POST['enddatum'] ?: null;
    $status       = $_POST['status'] ?? 'geplant';

    if ($name === "") throw new Exception("Projektname ist erforderlich.");

    if ($id > 0) {
      // --- Update ---
      $res = $mysqli->prepare("SELECT bild FROM projekte WHERE id=?");
      $res->bind_param("i", $id);
      $res->execute();
      $row = $res->get_result()->fetch_assoc();
      $res->close();

      $upload = handle_upload('bild','projekte',$id,$name,
        ['image/jpeg','image/png','image/gif'], 4_000_000, 1600, 1200);
      $newBild = $upload['pfad'] ?? null;

      $profile_id   = (int)($_POST['pendenzen_profile_id'] ?? 0);
      if ($profile_id <= 0) $profile_id = null;

      if ($newBild) {
        $stmt = $mysqli->prepare("UPDATE projekte SET name=?, beschreibung=?, adresse=?, startdatum=?, enddatum=?, status=?, bild=?, pendenzen_profile_id=? WHERE id=?");
        $stmt->bind_param("sssssssii",$name,$beschreibung,$adresse,$startdatum,$enddatum,$status,$newBild,$profile_id,$id);
      } else {
        $stmt = $mysqli->prepare("UPDATE projekte SET name=?, beschreibung=?, adresse=?, startdatum=?, enddatum=?, status=?, pendenzen_profile_id=? WHERE id=?");
        $stmt->bind_param("ssssssii",$name,$beschreibung,$adresse,$startdatum,$enddatum,$status,$profile_id,$id);
      }
      $stmt->execute();
      log_action($mysqli,'projekt',$id,'update',['name'=>$name,'status'=>$status]);
      $flash = "✅ Projekt aktualisiert.";

    } else {
      // --- Neues Projekt ---
      $stmt = $mysqli->prepare("INSERT INTO projekte (name,beschreibung,adresse,startdatum,enddatum,status) VALUES (?,?,?,?,?,?)");
      $stmt->bind_param("ssssss",$name,$beschreibung,$adresse,$startdatum,$enddatum,$status);
      $stmt->execute();
      $newId = $stmt->insert_id;

      $upload = handle_upload('bild','projekte',$newId,$name,
        ['image/jpeg','image/png','image/gif'], 4_000_000, 1600, 1200);
      $newBild = $upload['pfad'] ?? null;
      if ($newBild) {
        $stmt2 = $mysqli->prepare("UPDATE projekte SET bild=? WHERE id=?");
        $stmt2->bind_param("si",$newBild,$newId);
        $stmt2->execute();
      }

      $actor = current_user_id();
      if ($actor) {
        $m = $mysqli->prepare("INSERT IGNORE INTO projekt_mitglieder (projekt_id,benutzer_id,rolle,hinzugefuegt_von) VALUES (?,?, 'owner', ?)");
        $m->bind_param("iii",$newId,$actor,$actor);
        $m->execute();
      }

      log_action($mysqli,'projekt',$newId,'create',['name'=>$name]);
      $flash = "✅ Projekt erfolgreich angelegt.";
    }

  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

// Edit/Delete
$editProject = null;
if (isset($_GET['edit'])) {
  $eid = (int)$_GET['edit'];
  $res = $mysqli->prepare("SELECT * FROM projekte WHERE id=?");
  $res->bind_param("i",$eid);
  $res->execute();
  $editProject = $res->get_result()->fetch_assoc();
  $res->close();
}
if (isset($_GET['delete'])) {
  $did = (int)$_GET['delete'];
  try {
    $stmt = $mysqli->prepare("DELETE FROM projekte WHERE id=?");
    $stmt->bind_param("i",$did);
    $stmt->execute();
    log_action($mysqli,'projekt',$did,'delete');
    header("Location: projekte.php"); exit;
  } catch (Throwable $ex) { $flash = "❌ Projekt kann nicht gelöscht werden (hat evtl. Pendenzen)."; }
}

/* ===== AB HIER ERST AUSGABE (Header/Nav etc.) ===== */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

// Liste
$projekte = $mysqli->query("SELECT * FROM projekte ORDER BY erstellt_am DESC");

// Mitglieder falls edit
$mitglieder = null;
if ($editProject) {
  $pid = (int)$editProject['id'];
  $q = $mysqli->prepare("
    SELECT pm.id as pm_id, pm.rolle, b.id as uid, b.name, b.email
    FROM projekt_mitglieder pm
    JOIN benutzer b ON b.id=pm.benutzer_id
    WHERE pm.projekt_id=?
    ORDER BY b.name ASC
  ");
  $q->bind_param("i",$pid);
  $q->execute();
  $mitglieder = $q->get_result();
}

/* --- Daten für Konto-Widget (nur wenn Edit + Tabellen vorhanden) --- */
$konten = [];
$buchungen_recent = [];
$ytd_sums = ['in'=>0.0,'out'=>0.0];
if ($editProject && table_exists($mysqli,'kv_konten')) {
  $pid = (int)$editProject['id'];

  // Konten des Projekts
  $qk = $mysqli->prepare("
    SELECT k.id, k.name, k.iban,
           (SELECT COUNT(*) FROM kv_buchungen b WHERE b.konto_id=k.id) AS cnt,
           (SELECT SUM(betrag) FROM kv_buchungen b WHERE b.konto_id=k.id AND b.betrag>=0 AND YEAR(buchungstag)=YEAR(CURDATE())) AS ytd_in,
           (SELECT SUM(-betrag) FROM kv_buchungen b WHERE b.konto_id=k.id AND b.betrag<0  AND YEAR(buchungstag)=YEAR(CURDATE())) AS ytd_out
    FROM kv_konten k
    WHERE k.projekt_id=?
    ORDER BY k.name
  ");
  $qk->bind_param("i",$pid);
  $qk->execute();
  $konten = $qk->get_result()->fetch_all(MYSQLI_ASSOC);

  // Letzte Bewegungen (wenn kv_buchungen existiert)
  if (table_exists($mysqli,'kv_buchungen')) {
    $qb = $mysqli->prepare("
      SELECT b.id, b.buchungstag, b.betrag, b.waehrung, b.text_raw, b.gegenkonto_name, b.referenz,
             k.name AS konto_name
      FROM kv_buchungen b
      JOIN kv_konten k ON k.id=b.konto_id
      WHERE k.projekt_id=?
      ORDER BY COALESCE(b.buchungstag,'1900-01-01') DESC, b.id DESC
      LIMIT 20
    ");
    $qb->bind_param("i",$pid);
    $qb->execute();
    $buchungen_recent = $qb->get_result()->fetch_all(MYSQLI_ASSOC);
  }

  // YTD Sum aller Projekt-Konten
  if ($konten) {
    $in = $out = 0.0;
    foreach ($konten as $k) {
      $in  += (float)($k['ytd_in']  ?? 0);
      $out += (float)($k['ytd_out'] ?? 0);
    }
    $ytd_sums = ['in'=>$in, 'out'=>$out];
  }
}
?>
<style>
  .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; align-items: start; }
  .form-grid .col-2 { grid-column: 1 / -1; }
  .form-grid label { font-weight:600; color:#0f172a; }
  .form-grid input[type="text"], .form-grid input[type="date"], .form-grid select, .form-grid textarea {
    width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; background:#fff;
  }
  .form-grid small.hint { color:#64748b; display:block; margin-top:4px; }
  .inline { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .thumb { max-height:60px; border-radius:6px; border:1px solid #e5e7eb; }

  .kv-grid{display:grid;gap:12px}
  .kv-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .kv-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:12px}
  .kv-muted{color:#64748b}
  .money{font-variant-numeric:tabular-nums}
  .pos{color:#0a7b4f}
  .neg{color:#b12246}
  .btn-link{padding:6px 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;text-decoration:none}
  .btn-danger{border-color:#f43f5e;background:#fff0f3;color:#b91c1c}
  .table-kv{width:100%;border-collapse:collapse}
  .table-kv th,.table-kv td{padding:8px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top}
</style>

<div class="container" id="proj-root" data-prefix="<?= h($PREFIX) ?>">
  <header class="hero hero-blue" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;"><?= $editProject ? "Projekt bearbeiten" : "Projekte" ?></h1>
    <a class="btn btn-head" href="projekte.php">➕ Neues Projekt</a>
  </header>

  <?php if($flash): ?><div class="card" style="border-left:4px solid #1abc9c;"><?= h($flash) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
      <h2 style="margin:0;"><?= $editProject ? "Projekt bearbeiten" : "Neues Projekt" ?></h2>
      <?php if($editProject): ?>
        <a href="mieterspiegel.php?projekt_id=<?= $pid ?>" class="btn" style="background:#3b82f6; color:white; font-size:12px; font-weight:700;">📈 Zum Mieterspiegel</a>
      <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data" class="form-grid" id="proj-form" autocomplete="on">
      <input type="hidden" name="id" value="<?= (int)($editProject['id'] ?? 0) ?>">

      <div>
        <label for="proj_name">Projektname*</label>
        <input type="text" name="name" id="proj_name" placeholder="z. B. 2 MFH Arbonerstrasse"
               value="<?= h($editProject['name'] ?? "") ?>" required>
      </div>

      <div>
        <label for="proj_status">Status</label>
        <select name="status" id="proj_status">
          <?php
            $statusOpts = ['geplant'=>'Geplant','aktiv'=>'Aktiv','abgeschlossen'=>'Abgeschlossen'];
            $cur = $editProject['status'] ?? 'geplant';
            foreach($statusOpts as $v=>$lbl): ?>
            <option value="<?= h($v) ?>" <?= $v===$cur ? 'selected':''; ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="proj_adresse">Adresse</label>
        <input type="text" name="adresse" id="proj_adresse" placeholder="Straße Nr., PLZ Ort"
               value="<?= h($editProject['adresse'] ?? "") ?>">
      </div>

      <div>
        <label for="pendenzen_profile_id">Standard Pendenzen-Profil</label>
        <select name="pendenzen_profile_id" id="pendenzen_profile_id">
          <option value="">— Standard —</option>
          <?php 
          if (table_exists($mysqli, 'pendenz_export_profiles')):
            $profs = $mysqli->query("SELECT id, name FROM pendenz_export_profiles ORDER BY name");
            while($p = $profs->fetch_assoc()): ?>
              <option value="<?= (int)$p['id'] ?>" <?= (int)($editProject['pendenzen_profile_id']??0)===(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
            <?php endwhile;
          endif; ?>
        </select>
        <small class="hint">Legt fest, welche Spalten standardmäßig für dieses Projekt angezeigt werden.</small>
      </div>

      <div class="inline">
        <div style="flex:1 1 50%;">
          <label for="proj_start">Startdatum</label>
          <input type="date" name="startdatum" id="proj_start" value="<?= h($editProject['startdatum'] ?? "") ?>">
        </div>
        <div style="flex:1 1 50%;">
          <label for="proj_ende">Geplantes Enddatum</label>
          <input type="date" name="enddatum" id="proj_ende" value="<?= h($editProject['enddatum'] ?? "") ?>">
        </div>
      </div>

      <div class="col-2">
        <label for="proj_beschreibung">Beschreibung</label>
        <textarea name="beschreibung" id="proj_beschreibung" rows="3"
                  placeholder="Kurzbeschreibung, z. B. Bewirtschaftungsphase"><?= h($editProject['beschreibung'] ?? "") ?></textarea>
      </div>

      <div class="col-2">
        <label for="proj_bild">Bild (optional)</label>
        <input type="file" name="bild" id="proj_bild" accept="image/*">
        <?php if(!empty($editProject['bild']) && proj_image_exists($editProject['bild'])): ?>
          <div class="inline" style="margin-top:6px;">
            <img src="<?= h(proj_image_url($editProject['bild'])) ?>" class="thumb" alt="">
            <small class="hint">Aktuelles Bild bleibt, wenn kein neues gewählt wird.</small>
          </div>
        <?php else: ?>
          <small class="hint">PNG/JPG, max. ~4 MB, wird bei Bedarf skaliert.</small>
        <?php endif; ?>
      </div>

      <div class="col-2" style="margin-top:4px;">
        <button class="btn" type="submit">Speichern</button>
      </div>
    </form>
  </div>

  <?php if ($editProject): ?>
    <div class="card">
      <h2 style="margin-top:0;">Konto-Verwaltung (Projekt)</h2>

      <?php if (!table_exists($mysqli,'kv_konten')): ?>
        <div class="kv-card">
          <p><strong>Hinweis:</strong> Das Konto-Modul ist noch nicht installiert.
            <a class="btn-link" href="<?= h($PREFIX) ?>tools/konto_verwaltung/index.php">💳 Konto-Verwaltung öffnen</a>
            oder hier direkt installieren:</p>
          <form method="post" class="kv-row" style="margin-top:6px">
            <input type="hidden" name="action" value="install_kv">
            <input type="hidden" name="projekt_id" value="<?= (int)$editProject['id'] ?>">
            <button class="btn">⚙️ Konto-Modul installieren</button>
          </form>
        </div>
      <?php else: ?>
        <div class="kv-grid">

          <!-- Konto hinzufügen / verknüpfen -->
          <div class="kv-card">
            <div class="kv-row" style="justify-content:space-between;">
              <div><strong>Projektkonto hinzufügen</strong><div class="kv-muted">Name und optional IBAN – wird direkt mit diesem Projekt verknüpft.</div></div>
              <a class="btn-link" href="<?= h($PREFIX) ?>tools/konto_verwaltung/index.php?projekt_id=<?= (int)$editProject['id'] ?>">Zum Konto-Tool</a>
            </div>
            <form method="post" class="kv-row" style="margin-top:8px">
              <input type="hidden" name="action" value="konto_add">
              <input type="hidden" name="projekt_id" value="<?= (int)$editProject['id'] ?>">
              <input type="text" name="konto_name" placeholder="z. B. Liegenschafts-Konto Romanshorn" required>
              <input type="text" name="konto_iban" placeholder="IBAN (optional)">
              <button class="btn">Anlegen & verknüpfen</button>
            </form>
          </div>

          <!-- Verknüpfte Konten -->
          <?php
          // Lade Konten/Buchungen falls noch nicht oben passiert
          ?>
          <div class="kv-card">
            <strong>Verknüpfte Konten</strong>
            <?php
              // (Re-Use: $konten,$ytd_sums sind oben befüllt)
            ?>
            <?php if (!$konten): ?>
              <div class="kv-muted" style="margin-top:6px">Noch keine Konten verknüpft.</div>
            <?php else: ?>
              <table class="table-kv" style="margin-top:8px">
                <thead><tr><th>Name</th><th>IBAN</th><th style="text-align:right">Buchungen</th><th style="text-align:right">YTD Ein</th><th style="text-align:right">YTD Aus</th><th>Aktionen</th></tr></thead>
                <tbody>
                <?php foreach ($konten as $k): ?>
                  <tr>
                    <td><?= h($k['name']) ?></td>
                    <td><?= h($k['iban'] ?: '—') ?></td>
                    <td style="text-align:right"><?= (int)($k['cnt'] ?? 0) ?></td>
                    <td style="text-align:right" class="money pos"><?= number_format((float)($k['ytd_in'] ?? 0),2,'.','’') ?> CHF</td>
                    <td style="text-align:right" class="money neg"><?= number_format((float)($k['ytd_out'] ?? 0),2,'.','’') ?> CHF</td>
                    <td class="inline">
                      <a class="btn-link" href="<?= h($PREFIX) ?>tools/konto_verwaltung/index.php?projekt_id=<?= (int)$editProject['id'] ?>" title="Zum Konto-Tool">Öffnen</a>
                      <form method="post" onsubmit="return confirm('Konto nur vom Projekt lösen? (Buchungen bleiben erhalten)')">
                        <input type="hidden" name="action" value="konto_unlink">
                        <input type="hidden" name="projekt_id" value="<?= (int)$editProject['id'] ?>">
                        <input type="hidden" name="konto_id" value="<?= (int)$k['id'] ?>">
                        <button class="btn btn-danger" type="submit">Lösen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>

              <div class="kv-row" style="justify-content:flex-end;margin-top:8px">
                <div><strong>Summe YTD:</strong>
                  <span class="money pos">+<?= number_format((float)$ytd_sums['in'],2,'.','’') ?> CHF</span>
                  &nbsp;/&nbsp;
                  <span class="money neg">−<?= number_format((float)$ytd_sums['out'],2,'.','’') ?> CHF</span>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <!-- Letzte Bewegungen -->
          <div class="kv-card">
            <strong>Letzte 20 Bewegungen</strong>
            <?php if (!$buchungen_recent): ?>
              <div class="kv-muted" style="margin-top:6px">Keine Buchungen vorhanden.</div>
            <?php else: ?>
              <table class="table-kv" style="margin-top:8px">
                <thead><tr><th>Datum</th><th>Konto</th><th>Text / Zahler</th><th style="text-align:right">Betrag</th></tr></thead>
                <tbody>
                <?php foreach ($buchungen_recent as $b):
                  $d = $b['buchungstag'] ? date('d.m.Y', strtotime($b['buchungstag'])) : '—';
                  $amt = (float)$b['betrag'];
                ?>
                  <tr>
                    <td><?= h($d) ?></td>
                    <td><?= h($b['konto_name'] ?? '') ?></td>
                    <td>
                      <div><strong><?= h($b['gegenkonto_name'] ?: '—') ?></strong></div>
                      <div class="kv-muted"><?= h($b['text_raw'] ?: ($b['referenz'] ? 'REF '.$b['referenz'] : '')) ?></div>
                    </td>
                    <td style="text-align:right" class="money <?= $amt>=0 ? 'pos':'neg' ?>">
                      <?= number_format($amt,2,'.','’') . ' ' . h($b['waehrung'] ?: 'CHF') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Projektliste</h2>
    <table class="table" id="proj-list">
      <thead>
        <tr><th>Bild</th><th>Name</th><th>Adresse</th><th>Start</th><th>Ende</th><th>Status</th><th>Aktionen</th></tr>
      </thead>
      <tbody>
      <?php while($p = $projekte->fetch_assoc()): ?>
        <?php
          $imgUrl = proj_image_url($p['bild'] ?? null);
          $hasImg = ($p['bild'] ?? null) ? proj_image_exists($p['bild']) : false;

          $name    = h($p['name']    ?? '');
          $adresse = h($p['adresse'] ?? '');

          $startRaw = $p['startdatum'] ?? '';
          $start = ($startRaw && preg_match('/^\d{4}-\d{2}-\d{2}/',$startRaw)) ? date('d.m.Y', strtotime($startRaw)) : '';
          $endRaw = $p['enddatum'] ?? '';
          $end   = ($endRaw && preg_match('/^\d{4}-\d{2}-\d{2}/',$endRaw)) ? date('d.m.Y', strtotime($endRaw)) : '';

          $status = h($p['status'] ?? '');
          $pid    = (int)($p['id'] ?? 0);
        ?>
        <tr>
          <td><?php if ($hasImg && $imgUrl): ?><img src="<?= h($imgUrl) ?>" class="thumb" alt=""><?php endif; ?></td>
          <td><?= $name ?></td>
          <td><?= $adresse ?></td>
          <td><?= h($start) ?></td>
          <td><?= h($end) ?></td>
          <td><?= $status ?></td>
          <td class="inline">
            <a class="btn btn-small" href="?edit=<?= $pid ?>">Bearbeiten</a>
            <a class="btn btn-small" href="projekt_dashboard.php?id=<?= $pid ?>">📊 Dashboard</a>
            <?= link_chip('Konten', site_prefix().'tools/konto_verwaltung/index.php?projekt_id='.$pid, '💳') ?>
            <?= link_chip('Wohnungen', site_prefix().'pages/wohnungen_liste.php?projekt_id='.$pid, '🏢') ?>
            <?= link_chip('SmartTable', site_prefix().'pages/pendenzen_settings.php?projekt_id='.$pid, '⚙️') ?>
            <?= link_chip('Mieterspiegel', site_prefix().'pages/mieterspiegel.php?projekt_id='.$pid, '📈') ?>
            <?php if (!empty($p['fs_rel_path'])): ?>
              <?= link_chip('Ordner', site_prefix().'pages/fs_browser.php?path='.urlencode($p['fs_rel_path']), '🗂️') ?>
            <?php endif; ?>
            <a class="btn btn-danger btn-small" href="?delete=<?= $pid ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
// Letzte 20 Nachrichten aus allen Räumen des Users
$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $mysqli->prepare("
  SELECT m.id, m.created_at, LEFT(m.message_text, 300) AS preview,
         r.id AS room_id, r.name AS room_name, r.room_type, r.project_id,
         u.name AS sender_name
  FROM chat_messages m
  JOIN chat_rooms r ON r.id=m.room_id
  JOIN chat_members me ON me.room_id=r.id AND me.user_id=?
  JOIN benutzer u ON u.id=m.sender_id
  ORDER BY m.id DESC
  LIMIT 20
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="card">
  <h2>Letzte 20 Chat-Nachrichten (alle Räume)</h2>
  <?php if (!$recent): ?>
    <p class="muted">Noch keine Nachrichten.</p>
  <?php else: ?>
    <ul>
      <?php foreach ($recent as $row):
        $link = page_url('chat.php') . '?room_id='.(int)$row['room_id'] . ($row['project_id'] ? '&projekt_id='.(int)$row['project_id'] : '');
      ?>
        <li>
          <a href="<?= h($link) ?>">
            <strong><?= h($row['room_name'] ?: ($row['room_type'].' #'.$row['room_id'])) ?></strong>
          </a>
          &nbsp;–&nbsp; <em><?= h($row['sender_name']) ?></em> :
          <?= h($row['preview']) ?>
          <span class="muted"> (<?= h($row['created_at']) ?>)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<script defer src="<?= h($PREFIX) ?>assets/js/projekte.js"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
