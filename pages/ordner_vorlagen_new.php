<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
$err = $ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $name = trim($_POST['name'] ?? '');
  $beschr = trim($_POST['beschreibung'] ?? '');
  $paths = trim($_POST['paths'] ?? '');
  if ($name === '' || $paths === '') {
    $err = 'Name und Pfade sind erforderlich.';
  } else {
    $mysqli->begin_transaction();
    try {
      $st = $mysqli->prepare("INSERT INTO ordner_vorlagen (name,beschreibung,version,is_active) VALUES (?,?,1,1)");
      $st->bind_param("ss",$name,$beschr); $st->execute();
      $vid = (int)$mysqli->insert_id; $st->close();

      $ins = $mysqli->prepare("INSERT INTO ordner_vorlagen_nodes (vorlage_id,rel_path,is_dir,sort) VALUES (?,?,1,?)");
      $sort = 0;
      foreach (preg_split('/\R/u', $paths) as $line) {
        $rel = trim($line);
        if ($rel === '' || $rel[0] === '#') continue;
        $rel = ltrim(str_replace('\\','/',$rel), '/');
        $sort += 10;
        $ins->bind_param("isi", $vid, $rel, $sort);
        $ins->execute();
      }
      $ins->close();
      $mysqli->commit();
      header('Location: '.url('pages/ordner_vorlagen.php?applied=0'));
      exit;
    } catch (Throwable $t) {
      $mysqli->rollback();
      $err = 'Fehler: '.$t->getMessage();
    }
  }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_auto.php';
?>
<div class="container" style="max-width:900px;margin:24px auto;">
  <h1>Neue Ordner-Vorlage</h1>
  <?php if ($err): ?><div style="border:1px solid #e99;background:#fee;padding:8px;border-radius:6px;"><?= e($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div style="border:1px solid #9c9;background:#efe;padding:8px;border-radius:6px;"><?= e($ok) ?></div><?php endif; ?>

  <form method="post">
    <?php csrf_field(); ?>
    <label>Name</label>
    <input name="name" style="width:100%;padding:8px;margin:6px 0;" required>

    <label>Beschreibung (optional)</label>
    <input name="beschreibung" style="width:100%;padding:8px;margin:6px 0;">

    <label>Ordnerpfade (je Zeile, ohne führenden Slash)</label>
    <textarea name="paths" rows="10" style="width:100%;padding:8px;margin:6px 0;" placeholder="Ausschreibung und Ausmass
Ausschreibung und Ausmass/Aushub
Pläne
Verträge
Protokolle
Fotos"></textarea>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
      <button class="btn" type="submit">Vorlage speichern</button>
      <a class="btn" href="<?= e(url('pages/ordner_vorlagen.php')) ?>">Zurück zur Liste</a>
    </div>
  </form>
</div>
