<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$objekt_id = isset($_GET['objekt_id']) ? (int)$_GET['objekt_id'] : 0;
if ($objekt_id <= 0) die("❌ Kein Objekt gewählt.");

// Objekt prüfen
$stmt = $mysqli->prepare("SELECT id, name, projekt_id FROM objekte WHERE id=?");
$stmt->bind_param("i", $objekt_id);
$stmt->execute();
$objekt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$objekt) die("❌ Objekt nicht gefunden.");

$projectId = (int)$objekt['projekt_id'];
require_once __DIR__ . '/../includes/fs.php'; // Für Ordner-Erstellung


$flash = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name        = trim($_POST['name'] ?? '');
        $etage       = trim($_POST['etage'] ?? '');
        $flaeche     = $_POST['flaeche'] ?: null;
        $zimmer      = $_POST['zimmer'] ?: null;         // 2.5 etc.
        $badezimmer  = $_POST['badezimmer'] ?: null;
        $balkon      = isset($_POST['balkon']) ? 1 : 0;
        $wintergarten= isset($_POST['wintergarten']) ? 1 : 0;
        $letzte_ren  = $_POST['letzter_renovation'] ?: null;
        $zustand     = $_POST['gesamtzustand'] ?: null;
        $typ_id      = (int)($_POST['typ_id'] ?? 0) ?: null;

        if ($name === '') throw new Exception("Name ist erforderlich.");

        // Erst anlegen ohne Bilder/Dokumente
        $stmt = $mysqli->prepare("
            INSERT INTO wohnungen (objekt_id, name, etage, flaeche, zimmer, badezimmer, balkon, wintergarten, letzter_renovation, gesamtzustand, typ_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            "issddiiissi",
            $objekt_id, $name, $etage, $flaeche, $zimmer, $badezimmer, $balkon, $wintergarten, $letzte_ren, $zustand, $typ_id
        );
        $stmt->execute();
        $wid = $stmt->insert_id;
        $stmt->close();

        // Bilder hochladen (mehrere)
        if (!empty($_FILES['bilder']['name'][0])) {
            foreach ($_FILES['bilder']['name'] as $i => $fn) {
                if ($_FILES['bilder']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp = [
                        'name'     => $_FILES['bilder']['name'][$i],
                        'type'     => $_FILES['bilder']['type'][$i],
                        'tmp_name' => $_FILES['bilder']['tmp_name'][$i],
                        'error'    => $_FILES['bilder']['error'][$i],
                        'size'     => $_FILES['bilder']['size'][$i]
                    ];
                    $_FILES['__single'] = $tmp;
                    $upload = handle_upload('__single', "wohnungen", $wid, $name, ['image/jpeg','image/png'], 4_000_000, 1600, 1200);
                    if ($upload) {
                        $pfad = $upload['pfad'];
                        $stmt = $mysqli->prepare("INSERT INTO wohnung_bilder (wohnung_id, pfad) VALUES (?,?)");
                        $stmt->bind_param("is", $wid, $pfad);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }

        // Dokumente hochladen (mehrere)
        if (!empty($_FILES['dokumente']['name'][0])) {
            foreach ($_FILES['dokumente']['name'] as $i => $fn) {
                if ($_FILES['dokumente']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmp = [
                        'name'     => $_FILES['dokumente']['name'][$i],
                        'type'     => $_FILES['dokumente']['type'][$i],
                        'tmp_name' => $_FILES['dokumente']['tmp_name'][$i],
                        'error'    => $_FILES['dokumente']['error'][$i],
                        'size'     => $_FILES['dokumente']['size'][$i]
                    ];
                    $_FILES['__single'] = $tmp;
                    $upload = handle_upload('__single', "wohnung_doks", $wid, $name, ['application/pdf','image/jpeg','image/png'], 8_000_000);
                    if ($upload) {
                        $pfad = $upload['pfad'];
                        $stmt = $mysqli->prepare("INSERT INTO wohnung_dokumente (wohnung_id, pfad) VALUES (?,?)");
                        $stmt->bind_param("is", $wid, $pfad);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
        }

        // --- AUTOMATISCHE ORDNER-STRUKTUR (Total Integration) ---
        $root = project_root_path($mysqli, $projectId);
        if ($root && is_dir($root)) {
            $uPath = 'Wohnungen/' . $name;
            $unitAbs = fs_abs_from_rel($root, $uPath);
            if ($unitAbs) {
                // 1. Hauptordner der Wohnung
                if (!is_dir($unitAbs)) @mkdir($unitAbs, 0777, true);
                
                // 2. Standard-Vorlage anwenden (Liegenschafts-Standard ID 5)
                $tplRes = $mysqli->query("SELECT id FROM ordner_vorlagen WHERE name='Liegenschafts-Standard' LIMIT 1");
                $tplId = ($row = $tplRes->fetch_assoc()) ? (int)$row['id'] : 0;
                
                if ($tplId > 0) {
                    $nodes = $mysqli->query("SELECT rel_path FROM ordner_vorlagen_nodes WHERE vorlage_id=$tplId");
                    while($n = $nodes->fetch_assoc()) {
                        $subPath = $uPath . '/' . $n['rel_path'];
                        $subAbs = fs_abs_from_rel($root, $subPath);
                        if ($subAbs && !is_dir($subAbs)) @mkdir($subAbs, 0777, true);
                    }
                    // Re-Scan
                    fs_scan_project($mysqli, $projectId, 0);
                }
            }
        }

        header("Location: wohnungen_liste.php?projekt_id=" . $projectId . "&success=1");
        exit;

    } catch (Exception $e) {
        $flash = "❌ Fehler: " . $e->getMessage();
    }
}
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="container" style="padding:16px;">
  <div class="card">
    <h1>Neue Wohnung anlegen</h1>
    <p><strong>Objekt:</strong> <?= htmlspecialchars($objekt['name']) ?> (ID <?= $objekt_id ?>)</p>

    <?php if ($flash): ?>
      <div class="msg-error"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid">

      <label>Name* <input type="text" name="name" required placeholder="z.B. Garten, Gemeinschaftsraum, Veloraum..."></label>
      <label>Einheit-Typ
        <select name="typ_id">
          <option value="0">🏠 Standard (Wohnung)</option>
          <?php 
            $typenRes = $mysqli->query("SELECT id, name, icon FROM einheit_typen ORDER BY name");
            while($t = $typenRes->fetch_assoc()): ?>
              <option value="<?= $t['id'] ?>"><?= $t['icon'] ?> <?= h($t['name']) ?></option>
            <?php endwhile; ?>
        </select>
      </label>
      <label>Etage <input type="text" name="etage"></label>
      <label>Fläche (m²) <input type="number" step="0.01" name="flaeche"></label>

      <label>Zimmer (inkl. halbe, z.B. 2.5) <input type="number" step="0.5" name="zimmer"></label>
      <label>Badezimmer <input type="number" step="0.5" name="badezimmer"></label>

      <label><input type="checkbox" name="balkon" value="1"> Balkon</label>
      <label><input type="checkbox" name="wintergarten" value="1"> Wintergarten</label>

      <label>Letzte Renovation <input type="date" name="letzter_renovation"></label>
      <label>Gesamtzustand
        <select name="gesamtzustand">
          <option value="">-</option>
          <option value="neu">Neu</option>
          <option value="gut">Gut</option>
          <option value="renovationsbedürftig">Renovationsbedürftig</option>
          <option value="sanierungsbedürftig">Sanierungsbedürftig</option>
        </select>
      </label>

      <h3>Bilder</h3>
      <input type="file" name="bilder[]" multiple accept="image/*">

      <h3>Dokumente</h3>
      <input type="file" name="dokumente[]" multiple accept=".pdf,.jpg,.png">

      <div class="col-2">
        <button type="submit" class="btn">Speichern</button>
        <a href="objekt_edit.php?id=<?= $objekt_id ?>" class="btn btn-secondary">Abbrechen</a>
      </div>
    </form>
  </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
