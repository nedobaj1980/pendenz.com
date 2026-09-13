<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$flash = "";

// === Neues oder bestehendes Projekt speichern ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            // Update
            $stmt = $mysqli->prepare("UPDATE projekte 
                SET name=?, beschreibung=?, adresse=?, startdatum=?, enddatum=?, status=? 
                WHERE id=?");
            $stmt->bind_param("ssssssi", $name, $beschreibung, $adresse, $startdatum, $enddatum, $status, $id);
            $stmt->execute();
            $stmt->close();

            // Bild hochladen (wenn vorhanden)
            $upload = handle_upload('bild', 'projekte', $id, $name,
                ['image/jpeg','image/png','image/gif'], 4_000_000, 1600, 1200);
            if ($upload) {
                $stmt = $mysqli->prepare("UPDATE projekte SET bild=? WHERE id=?");
                $stmt->bind_param("si", $upload['pfad'], $id);
                $stmt->execute();
                $stmt->close();
            }

            $flash = "✅ Projekt aktualisiert.";
        } else {
            // Neues Projekt anlegen (erst ohne Bild, um ID zu haben)
            $stmt = $mysqli->prepare("INSERT INTO projekte (name,beschreibung,adresse,startdatum,enddatum,status) 
                VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("ssssss", $name, $beschreibung, $adresse, $startdatum, $enddatum, $status);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();

            // Bild hochladen
            $upload = handle_upload('bild', 'projekte', $newId, $name,
                ['image/jpeg','image/png','image/gif'], 4_000_000, 1600, 1200);
            if ($upload) {
                $stmt = $mysqli->prepare("UPDATE projekte SET bild=? WHERE id=?");
                $stmt->bind_param("si", $upload['pfad'], $newId);
                $stmt->execute();
                $stmt->close();
            }

            $flash = "✅ Projekt erfolgreich angelegt.";
        }
    } catch (Throwable $e) {
        $flash = "❌ Fehler: " . $e->getMessage();
    }
}

// === Editiermodus? ===
$editProject = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $res = $mysqli->prepare("SELECT * FROM projekte WHERE id=?");
    $res->bind_param("i", $eid);
    $res->execute();
    $editProject = $res->get_result()->fetch_assoc();
    $res->close();
}

// === Projekt löschen ===
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    try {
        $stmt = $mysqli->prepare("DELETE FROM projekte WHERE id=?");
        $stmt->bind_param("i", $did);
        $stmt->execute();
        $stmt->close();
        header("Location: projekte.php");
        exit;
    } catch (Throwable $ex) {
        $flash = "❌ Projekt kann nicht gelöscht werden (evtl. hat es abhängige Daten).";
    }
}

// === Liste aller Projekte ===
$projekte = $mysqli->query("SELECT * FROM projekte ORDER BY erstellt_am DESC");

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Projekte</h1>

  <?php if ($flash): ?>
    <div class="card" style="margin:10px 0;"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2><?= $editProject ? "Projekt bearbeiten" : "Neues Projekt anlegen" ?></h2>

    <form method="post" enctype="multipart/form-data" class="form-grid">
      <input type="hidden" name="id" value="<?= (int)($editProject['id'] ?? 0) ?>">

      <label>Projektname*</label>
      <input type="text" name="name" value="<?= htmlspecialchars($editProject['name'] ?? "") ?>" required>

      <label>Adresse</label>
      <input type="text" name="adresse" value="<?= htmlspecialchars($editProject['adresse'] ?? "") ?>">

      <label>Startdatum</label>
      <input type="date" name="startdatum" value="<?= htmlspecialchars($editProject['startdatum'] ?? "") ?>">

      <label>Enddatum</label>
      <input type="date" name="enddatum" value="<?= htmlspecialchars($editProject['enddatum'] ?? "") ?>">

      <label>Status</label>
      <select name="status">
        <?php
          $statusOpts = ['geplant'=>'Geplant','aktiv'=>'Aktiv','abgeschlossen'=>'Abgeschlossen'];
          $cur = $editProject['status'] ?? 'geplant';
          foreach($statusOpts as $v=>$lbl):
        ?>
          <option value="<?= $v ?>" <?= $v===$cur ? 'selected':''; ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>

      <label>Beschreibung</label>
      <textarea name="beschreibung" rows="3"><?= htmlspecialchars($editProject['beschreibung'] ?? "") ?></textarea>

      <label>Bild</label>
      <input type="file" name="bild" accept="image/*">
      <?php if (!empty($editProject['bild'])): ?>
        <div style="margin-top:5px;">
          <img src="<?= file_url($editProject['bild']) ?>" style="max-height:100px;border:1px solid #ccc;">
        </div>
      <?php endif; ?>

      <div class="col-2">
        <button type="submit" class="btn">Speichern</button>
        <a href="projekte.php" class="btn btn-secondary">Abbrechen</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Projektliste</h2>
    <table class="table">
      <thead><tr><th>Bild</th><th>Name</th><th>Adresse</th><th>Status</th><th>Aktionen</th></tr></thead>
      <tbody>
        <?php while($p = $projekte->fetch_assoc()): ?>
          <tr>
            <td><?php if (!empty($p['bild'])): ?><img src="<?= file_url($p['bild']) ?>" style="max-height:60px;"><?php endif; ?></td>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['adresse']) ?></td>
            <td><?= htmlspecialchars($p['status']) ?></td>
            <td>
              <a href="projekte.php?edit=<?= (int)$p['id'] ?>" class="btn btn-small">✏ Bearbeiten</a>
              <a href="projekt_dashboard.php?id=<?= (int)$p['id'] ?>" class="btn btn-small">📊 Dashboard</a>
              <a href="projekte.php?delete=<?= (int)$p['id'] ?>" class="btn btn-danger btn-small">🗑 Löschen</a>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
