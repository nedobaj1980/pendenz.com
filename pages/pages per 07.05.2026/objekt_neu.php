<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$projekt_id = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
if ($projekt_id <= 0) {
    die("❌ Kein Projekt gewählt (projekt_id fehlt).");
}

// Projekt prüfen
$stmt = $mysqli->prepare("SELECT id, name FROM projekte WHERE id=?");
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$projekt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$projekt) {
    die("❌ Projekt #{$projekt_id} nicht gefunden.");
}

$flash = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '');
        $beschreibung = trim($_POST['beschreibung'] ?? '');

        if ($name === '') {
            throw new Exception("Name ist erforderlich.");
        }

        // Upload (Ordner: uploads/projects/objekte/{id_name})
        $upload = handle_upload('bild', 'objekte', $projekt_id, $name);
        $bild = $upload['pfad'] ?? null;

        $stmt = $mysqli->prepare("
            INSERT INTO objekte (projekt_id, name, bezeichnung, bild)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $projekt_id, $name, $beschreibung, $bild);
        $stmt->execute();
        $stmt->close();

        header("Location: projekt_dashboard.php?id=" . $projekt_id);
        exit;
    } catch (Exception $e) {
        $flash = "❌ Fehler: " . $e->getMessage();
    }
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="container" style="padding:16px;">
  <div class="card">
    <h1>Neues Objekt anlegen</h1>
    <p><strong>Projekt:</strong> <?= htmlspecialchars($projekt['name']) ?> (ID <?= (int)$projekt_id ?>)</p>

    <?php if ($flash): ?>
      <div class="msg-error"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid">
      <label>Name* <input type="text" name="name" required></label>
      <label>Beschreibung<textarea name="beschreibung" rows="3"></textarea></label>
      <label>Bild (optional)<input type="file" name="bild" accept="image/*"></label>
      <div class="col-2">
        <button type="submit" class="btn">Speichern</button>
        <a href="projekt_dashboard.php?id=<?= (int)$projekt_id ?>" class="btn btn-secondary">Abbrechen</a>
      </div>
    </form>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
