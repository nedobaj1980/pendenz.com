<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("❌ Kein Objekt gewählt (id fehlt).");
}

// Objekt prüfen
$stmt = $mysqli->prepare("SELECT * FROM objekte WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$objekt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$objekt) {
    die("❌ Objekt nicht gefunden.");
}

// Projekt holen (für Rücksprung und Ordnerstruktur)
$stmt = $mysqli->prepare("SELECT id, name FROM projekte WHERE id=?");
$stmt->bind_param("i", $objekt['projekt_id']);
$stmt->execute();
$projekt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$projekt) {
    die("❌ Zugehöriges Projekt nicht gefunden.");
}

$flash = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');
        $bild = $objekt['bild'];

        if ($name === '') {
            throw new Exception("Name ist erforderlich.");
        }

        // Neuer Upload (falls vorhanden)
        $upload = handle_upload('bild', 'objekte', $projekt['id'], $name);
        if (!empty($upload['pfad'])) {
            $bild = $upload['pfad'];
        }

        $stmt = $mysqli->prepare("UPDATE objekte SET name=?, bezeichnung=?, bild=? WHERE id=?");
        $stmt->bind_param("sssi", $name, $beschreibung, $bild, $id);
        $stmt->execute();
        $stmt->close();

        header("Location: projekt_dashboard.php?id=" . $projekt['id']);
        exit;
    } catch (Exception $e) {
        $flash = "❌ Fehler: " . $e->getMessage();
    }
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="container" style="padding:16px;">
  <div class="card">
    <h1>Objekt bearbeiten</h1>
    <p><strong>Projekt:</strong> <?= htmlspecialchars($projekt['name']) ?> (ID <?= (int)$projekt['id'] ?>)</p>

    <?php if ($flash): ?>
      <div class="msg-error"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid">
      <label>Name* 
        <input type="text" name="name" value="<?= htmlspecialchars($objekt['name']) ?>" required>
      </label>
      <label>Beschreibung
        <textarea name="beschreibung" rows="3"><?= htmlspecialchars($objekt['bezeichnung'] ?? '') ?></textarea>
      </label>
      <label>Bild (optional)
        <input type="file" name="bild" accept="image/*">
      </label>
      <?php if (!empty($objekt['bild'])): ?>
        <div>
          <img src="/<?= htmlspecialchars($objekt['bild']) ?>" style="max-width:200px;border:1px solid #ddd;margin-top:8px;">
          <p><small>Aktuelles Bild bleibt erhalten, wenn kein neues hochgeladen wird.</small></p>
        </div>
      <?php endif; ?>
      <div class="col-2">
        <button type="submit" class="btn">Speichern</button>
        <a href="projekt_dashboard.php?id=<?= (int)$projekt['id'] ?>" class="btn btn-secondary">Abbrechen</a>
      </div>
    </form>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
