<?php
// Datei: c:\xampp\htdocs\pendenz.com\pages\projekt_plaene.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
// Nur Admins / Superadmins
if (!is_admin()) {
    die("Keine Berechtigung.");
}

$projekt_id = (int)($_GET['projekt_id'] ?? 0);
ensure_projekt_plaene_tables($mysqli);
if (!$projekt_id) {
    die("Keine Projekt-ID angegeben.");
}

// Projekt-Name laden
$proj = db_one("SELECT name FROM projekte WHERE id = ?", "i", [$projekt_id]);
if (!$proj) die("Projekt nicht gefunden.");

$msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'ok') $msg = "<div style='padding:10px; background:#ecfdf5; color:#10b981; border-radius:8px; margin-bottom:12px;'>Plan erfolgreich hochgeladen!</div>";
    if ($_GET['msg'] === 'deleted') $msg = "<div style='padding:10px; background:#ecfdf5; color:#10b981; border-radius:8px; margin-bottom:12px;'>Plan gelöscht.</div>";
}

// Datei-Upload verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['plan_file'])) {
    $file = $_FILES['plan_file'];
    $name = trim($_POST['plan_name'] ?? 'Neuer Plan');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'pdf'])) {
            $uploadDir = __DIR__ . '/../uploads/plaene/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $baseName = uniqid('plan_');
            $filename = $baseName . '.' . ($ext === 'pdf' ? 'png' : $ext);
            $destination = $uploadDir . $filename;
            
            $success = false;
            
            if ($ext === 'pdf') {
                $tempPdf = $uploadDir . $baseName . '.pdf';
                if (move_uploaded_file($file['tmp_name'], $tempPdf)) {
                    // Try Ghostscript (Linux/Unix/Windows)
                    $gsCommands = ['gs', 'gswin64c', 'gswin32c'];
                    foreach ($gsCommands as $cmd) {
                        $fullCmd = $cmd . ' -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile="' . $destination . '" "' . $tempPdf . '"';
                        @exec($fullCmd, $output, $returnVar);
                        if (file_exists($destination)) {
                            $success = true;
                            break;
                        }
                    }
                    
                    // Fallback: Imagick
                    if (!$success && class_exists('Imagick')) {
                        try {
                            $im = new Imagick();
                            if (method_exists($im, 'setResolution')) {
                                $im->setResolution(150, 150);
                            }
                            $im->readImage($tempPdf . '[0]'); // First page
                            if (method_exists($im, 'setImageFormat')) {
                                $im->setImageFormat('png');
                            }

                            $im->writeImage($destination);
                            $success = true;
                        } catch (Throwable $e) {}
                    }

                    if (!$success) {
                        $msg = "<div style='padding:10px; background:#fee2e2; color:#ef4444; border-radius:8px; margin-bottom:12px;'>Fehler bei der PDF-Konvertierung. Bitte lade den Plan stattdessen als JPG oder PNG hoch, falls der Fehler bestehen bleibt.</div>";
                    }
                    @unlink($tempPdf);
                }
            } else {
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $success = true;
                }
            }
            
            if ($success) {
                $db_path = 'uploads/plaene/' . $filename;
                $stmt = $mysqli->prepare("INSERT INTO projekt_plaene (projekt_id, name, datei_pfad) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $projekt_id, $name, $db_path);
                $stmt->execute();
                $stmt->close();
                
                // Redirect to avoid double post on refresh
                header("Location: " . page_url("projekt_plaene.php?projekt_id=$projekt_id&msg=ok"));
                exit;
            }
        } else {
            $msg = "<div style='padding:10px; background:#fee2e2; color:#ef4444; border-radius:8px; margin-bottom:12px;'>Nur PNG, JPG und PDF erlaubt.</div>";
        }
    }
}

// Plan löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_plan_id'])) {
    $del_id = (int)$_POST['delete_plan_id'];
    $row = db_one("SELECT datei_pfad FROM projekt_plaene WHERE id = ? AND projekt_id = ?", "ii", [$del_id, $projekt_id]);
    if ($row) {
        $fullPath = __DIR__ . '/../' . ltrim((string)$row['datei_pfad'], '/');
        if (is_file($fullPath)) @unlink($fullPath);
        
        $stmt = $mysqli->prepare("DELETE FROM projekt_plaene WHERE id = ? AND projekt_id = ?");
        $stmt->bind_param("ii", $del_id, $projekt_id);
        $stmt->execute();
        $stmt->close();
        
        $mysqli->query("DELETE FROM plan_zonen WHERE plan_id = $del_id");
        
        // Redirect to avoid double post on refresh
        header("Location: " . page_url("projekt_plaene.php?projekt_id=$projekt_id&msg=deleted"));
        exit;
    }
}

// Pläne laden
$plaene = [];
$stmt = $mysqli->prepare("SELECT * FROM projekt_plaene WHERE projekt_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) {
    $plaene[] = $row;
}
$stmt->close();

$PAGE_TITLE = "Pläne & Grundrisse: " . h($proj['name']);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
.shell { display: grid; grid-template-columns: 280px 1fr; gap: 16px; max-width: 1300px; margin: 24px auto; }
.aside { border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; padding: 10px; }
.main { min-width: 0; }
.card { border: 1px solid #e5e7eb; border-radius: 10px; background: #fff; overflow: hidden; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); }
.card-header { background: #f8fafc; padding: 12px 16px; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
.card-body { padding: 16px; }
.btn-primary { background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-primary:hover { background: #2563eb; }
.btn-outline { border: 1px solid #cbd5e1; background: white; color: #475569; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-outline:hover { background: #f1f5f9; }
.btn-danger { background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; width: 100%; text-align: center; font-size: 13px; font-weight: 600; margin-top: 8px; }
.btn-danger:hover { background: #dc2626; }
.form-control { width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
.plan-card { display: flex; flex-direction: column; height: 100%; }
.plan-img-container { height: 200px; background: #f1f5f9; overflow: hidden; display: flex; align-items: center; justify-content: center; }
.plan-img { width: 100%; height: 100%; object-fit: contain; }
</style>

<main class="shell">
    <aside class="aside">
        <div style="padding:10px;">
            <h4 style="margin:0 0 10px 0; color:#475569;">Projekt Navigation</h4>
            <a href="<?= page_url('projekt_dashboard.php?id=' . $projekt_id) ?>" class="btn-outline" style="width:100%; text-align:center; display:block; margin-bottom:8px;">🏠 Zurück zum Dashboard</a>
            <a href="<?= page_url('pendenzen.php?projekt_id=' . $projekt_id) ?>" class="btn-outline" style="width:100%; text-align:center; display:block;">📋 Pendenzen</a>
        </div>
    </aside>

    <section class="main">
        <div class="d-flex justify-content-between align-items-center mb-4" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="margin:0;">📍 Pläne & Grundrisse: <?= h($proj['name']) ?></h2>
        </div>

        <?= $msg ?>

        <div class="card">
            <div class="card-header">Neuen Plan hochladen</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px;">
                        <label style="display:block; margin-bottom:6px; font-weight:500; font-size:14px;">Name des Plans (z.B. Erdgeschoss)</label>
                        <input type="text" name="plan_name" class="form-control" required placeholder="z.B. Attikawohnung">
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label style="display:block; margin-bottom:6px; font-weight:500; font-size:14px;">Datei (PDF, JPG, PNG)</label>
                        <input type="file" name="plan_file" class="form-control" accept="image/png, image/jpeg, application/pdf" required>
                    </div>
                    <div>
                        <button type="submit" class="btn-primary">📤 Hochladen & Verarbeiten</button>
                    </div>
                </form>
                <p style="margin:8px 0 0; font-size:12px; color:#64748b;">PDF-Pläne werden automatisch in Bilder umgewandelt. Am besten lädst du ein einzelnes PDF-Blatt hoch.</p>
            </div>
        </div>

        <h3 style="margin:30px 0 15px;">Vorhandene Pläne</h3>
        <div class="cards-grid">
            <?php if (empty($plaene)): ?>
                <div style="grid-column: 1 / -1; padding:30px; text-align:center; background:#f8fafc; border-radius:10px; border:2px dashed #cbd5e1; color:#64748b;">
                    Noch keine Pläne hochgeladen.
                </div>
            <?php else: ?>
                <?php foreach ($plaene as $p): ?>
                    <div class="card plan-card">
                        <div class="plan-img-container">
                            <img src="<?= url($p['datei_pfad']) ?>" class="plan-img">
                        </div>
                        <div class="card-body" style="display:flex; flex-direction:column; flex:1;">
                            <h4 style="margin:0 0 12px 0; font-size:16px;"><?= h($p['name']) ?></h4>
                            <div style="margin-top:auto;">
                                <a href="<?= page_url('plan_zonen_edit.php?plan_id=' . $p['id']) ?>" class="btn-primary" style="display:block; text-align:center; width:100%;">🎯 Wohnungs-Zonen einrichten</a>
                                <form method="post" onsubmit="return confirm('Plan wirklich löschen? Alle Zonen darauf gehen verloren.');" style="margin:0;">
                                    <input type="hidden" name="delete_plan_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn-danger">🗑️ Plan löschen</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
