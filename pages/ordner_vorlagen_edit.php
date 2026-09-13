<?php
// pages/ordner_vorlagen_edit.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

/* Login + Rolle */
if (empty($_SESSION['user_id'])) {
  $back = $_SERVER['REQUEST_URI'] ?? url('index_admin.php');
  header('Location: ' . url('login.php?back=' . rawurlencode($back))); exit;
}
$rank = ['gast'=>0,'benutzer'=>1,'projektleiter'=>2,'admin'=>3,'superadmin'=>4];
$role = $_SESSION['simulate_role'] ?? ($_SESSION['rolle'] ?? 'gast');
if (($rank[$role] ?? 0) < $rank['projektleiter']) { http_response_code(403); exit('Zugriff verweigert.'); }

$vid = (int)($_GET['id'] ?? 0);
$isNew = ($vid === 0);
$err = $ok = '';

/* Laden oder leere Defaults */
$tpl = ['name'=>'','beschreibung'=>'','version'=>1,'is_active'=>1,'created_at'=>date('Y-m-d H:i:s')];
$paths = [];

if (!$isNew) {
  $st = $mysqli->prepare("SELECT id,name,beschreibung,version,is_active,created_at FROM ordner_vorlagen WHERE id=?");
  $st->bind_param("i",$vid); $st->execute();
  $res = $st->get_result();
  if (!($tpl = $res->fetch_assoc())) { http_response_code(404); exit('Vorlage nicht gefunden.'); }
  $st->close();

  $qr = $mysqli->prepare("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id=? ORDER BY sort, rel_path");
  $qr->bind_param("i",$vid); $qr->execute();
  $rs = $qr->get_result();
  while ($r = $rs->fetch_assoc()) $paths[] = $r['rel_path'];
  $qr->close();
}

/* POST: Speichern */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  $name  = trim($_POST['name'] ?? '');
  $besch = trim($_POST['beschreibung'] ?? '');
  $active= isset($_POST['is_active']) ? 1 : 0;
  $list  = trim($_POST['paths'] ?? '');
  $bump  = isset($_POST['bump_version']); // Version erhöhen?

  if ($name === '') { $err = 'Name ist erforderlich.'; }
  else {
    $mysqli->begin_transaction();
    try {
      if ($isNew) {
        $st = $mysqli->prepare("INSERT INTO ordner_vorlagen (name,beschreibung,version,is_active) VALUES (?,?,1,?)");
        $st->bind_param("ssi",$name,$besch,$active); $st->execute();
        $vid = (int)$mysqli->insert_id; $st->close();
      } else {
        $newVersion = (int)$tpl['version'] + ($bump ? 1 : 0);
        $st = $mysqli->prepare("UPDATE ordner_vorlagen SET name=?, beschreibung=?, version=?, is_active=? WHERE id=?");
        $st->bind_param("ssiii",$name,$besch,$newVersion,$active,$vid); $st->execute(); $st->close();
        $tpl['version'] = $newVersion;
        // alte Nodes verwerfen – wir schreiben sie frisch
        $mysqli->query("DELETE FROM ordner_vorlagen_nodes WHERE vorlage_id=".$vid);
      }

      // Pfadliste verarbeiten
      $ins = $mysqli->prepare("INSERT INTO ordner_vorlagen_nodes (vorlage_id, rel_path, is_dir, sort) VALUES (?,?,1,?)");
      $sort = 0;
      $lines = preg_split('/\R/u', $list);
      foreach ($lines as $line) {
        $rel = trim($line);
        if ($rel === '' || $rel[0] === '#') continue;
        $rel = ltrim(str_replace('\\','/',$rel), '/');          // normalisieren
        $rel = preg_replace('~/{2,}~','/',$rel);               // doppelte / entfernen
        $parts = array_values(array_filter(explode('/',$rel))); // keine leeren Segmente
        if (!$parts) continue;
        $final = implode('/',$parts);
        $sort += 10;
        $ins->bind_param("isi",$vid,$final,$sort);
        $ins->execute();
      }
      $ins->close();

      $mysqli->commit();
      header('Location: '.url('pages/ordner_vorlagen.php?msg='.rawurlencode($isNew?'Vorlage angelegt.':'Vorlage gespeichert.'))); exit;
    } catch (Throwable $t) {
      $mysqli->rollback();
      $err = 'Fehler: '.$t->getMessage();
    }
  }
}

/* Header + Auto-Nav */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_auto.php';

/* Anzeige */
$pathsText = $paths ? implode("\n",$paths) :
"Ausschreibung und Ausmass
Ausschreibung und Ausmass/Aushub
Pläne
Verträge
Protokolle
Fotos";
?>
<div class="container" style="max-width:860px;margin:24px auto;">
  <h1><?= $isNew ? 'Neue Ordner-Vorlage' : 'Vorlage bearbeiten' ?></h1>

  <?php if ($err): ?><div style="border:1px solid #e99;background:#fee;padding:10px;border-radius:8px;"><?= e($err) ?></div><?php endif; ?>
  <?php if ($ok):  ?><div style="border:1px solid #9c9;background:#efe;padding:10px;border-radius:8px;"><?= e($ok)  ?></div><?php endif; ?>

  <form method="post">
    <?php csrf_field(); ?>
    <div style="display:grid;grid-template-columns:1fr 160px;gap:12px;">
      <div>
        <label>Name</label>
        <input name="name" value="<?= e($tpl['name']) ?>" required style="width:100%;padding:8px;margin:6px 0;">
      </div>
      <div>
        <label>Status</label><br>
        <label><input type="checkbox" name="is_active" value="1" <?= ((int)$tpl['is_active']===1?'checked':'') ?>> aktiv</label>
      </div>
    </div>

    <label>Beschreibung (optional)</label>
    <input name="beschreibung" value="<?= e((string)$tpl['beschreibung']) ?>" style="width:100%;padding:8px;margin:6px 0;">

    <label>Ordnerpfade (je Zeile, ohne führenden Slash)</label>
    <textarea name="paths" rows="12" style="width:100%;padding:10px;margin:6px 0;"><?= e($pathsText) ?></textarea>

    <?php if (!$isNew): ?>
      <div class="muted" style="margin:4px 0 10px;">Aktuelle Vorlagen-Version: <strong><?= (int)$tpl['version'] ?></strong></div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
      <button class="btn primary" type="submit">Speichern</button>
      <?php if (!$isNew): ?>
        <button class="btn outline" name="bump_version" value="1">Version +1 &amp; Speichern</button>
      <?php endif; ?>
      <a class="btn outline" href="<?= e(url('pages/ordner_vorlagen.php')) ?>">Zurück zur Liste</a>
    </div>

    <p class="muted" style="margin-top:10px;">
      ⚠️ Speichern/Aktualisieren von Vorlagen **löscht nichts in Projekten**. Beim Anwenden werden nur fehlende Ordner erzeugt (keine Löschungen).
    </p>
  </form>
</div>
