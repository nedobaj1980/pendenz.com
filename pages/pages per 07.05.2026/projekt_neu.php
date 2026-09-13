<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
$mysqli = db();
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Formular abgeschickt?
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name         = $_POST['name'] ?? '';
    $beschreibung = $_POST['beschreibung'] ?? null;
    $status       = $_POST['status'] ?? 'geplant';
    $adresse      = $_POST['adresse'] ?? null;
    $nummer       = $_POST['nummer'] ?? null;
    $kategorie    = $_POST['kategorie'] ?? 'Sonstiges';

    // Bild-Upload vorbereiten
    $profilbild = null;
    if (!empty($_FILES['profilbild']['tmp_name'])) {
        $uploadDir = __DIR__ . '/../uploads/projects/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $filename = time() . '_' . basename($_FILES['profilbild']['name']);
        $target   = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['profilbild']['tmp_name'], $target)) {
            $profilbild = 'uploads/projects/' . $filename;
        }
    }

    // Insert ins Projekt-Table
    $root_path = $_POST['root_path'] ?? null;

    $stmt = $mysqli->prepare("
        INSERT INTO projekte (name, beschreibung, status, kategorie, adresse, bild, nummer, root_path) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssssss", $name, $beschreibung, $status, $kategorie, $adresse, $profilbild, $nummer, $root_path);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    // Automatische Ordnerstruktur anlegen
    if ($newId && !empty($root_path)) {
        require_once __DIR__ . '/../includes/fs.php';
        fs_ensure_project_structure($mysqli, $newId);
        
        // --- NEU: Standard-Objekt-Struktur in DB anlegen ---
        $standardObjs = [
            '10_Mietsache' => 'Wohngebäude / Mietsachen',
            '05_Allgemein' => 'Allgemein / Umgebung',
            '06_Tiefgarage' => 'Tiefgarage / Parkierung'
        ];
        foreach ($standardObjs as $oCode => $oName) {
            $stmtO = $mysqli->prepare("INSERT INTO objekte (projekt_id, name) VALUES (?, ?)");
            $stmtO->bind_param("is", $newId, $oCode); 
            $stmtO->execute();
            $stmtO->close();
        }
    }

    header("Location: projekt_dashboard.php?projekt_id=" . $newId);
    exit;
}

$title = "Neues Projekt starten | pendenz.com";
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="creation-wrapper">
    <div class="creation-container">
        <header class="creation-header">
            <div class="creation-icon">🏗️</div>
            <h1>Neues Projekt starten</h1>
            <p>Lege den Grundstein für dein nächstes großes Vorhaben.</p>
        </header>

        <form method="post" enctype="multipart/form-data" class="modern-form">
            <div class="form-row">
                <div class="form-group flex-2">
                    <label>Projektname <span class="required">*</span></label>
                    <input type="text" name="name" placeholder="z.B. Neubau Arbonerstrasse" required>
                </div>
                <div class="form-group">
                    <label>Projekt-Nr.</label>
                    <input type="text" name="nummer" placeholder="z.B. P2024-01">
                </div>
            </div>

            <div class="form-group">
                <label>Standort / Adresse</label>
                <input type="text" name="adresse" placeholder="Strasse, Hausnummer, PLZ Ort">
            </div>

            <div class="form-group">
                <label>Beschreibung</label>
                <textarea name="beschreibung" placeholder="Kurze Zusammenfassung des Projekts..."></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Initialer Status</label>
                    <div class="select-wrapper">
                        <select name="status">
                            <option value="geplant">🟡 Geplant</option>
                            <option value="aktiv" selected>🟢 Aktiv (In Arbeit)</option>
                            <option value="abgeschlossen">⚪️ Abgeschlossen</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Kategorie</label>
                    <div class="select-wrapper">
                        <select name="kategorie">
                            <option value="Grundstück">🏔️ Grundstück / Parzelle</option>
                            <option value="Neubau">🏗️ Neubau-Projekt</option>
                            <option value="Sanierung">🔧 Sanierung / Umbau</option>
                            <option value="Renditeobjekt">💰 Rendite / Bestand</option>
                            <option value="Entwicklung">📐 Entwicklungsobjekt</option>
                            <option value="Sonstiges">📦 Sonstiges</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="form-group" style="background:#f0f9ff; padding:15px; border-radius:15px; border:1px solid #bae6fd;">
                <label style="color:#0369a1;">📂 Lokaler Pfad (z.B. Google Drive)</label>
                <input type="text" name="root_path" placeholder="C:\Users\Nedim\Google Drive-Streaming\..." style="background:#fff; border-color:#7dd3fc;">
                <p style="font-size:11px; color:#0c4a6e; margin-top:5px;">💡 Das System erstellt hier automatisch die 10 Standard-Unterordner (Rechnungen, Versicherungen, etc.).</p>
            </div>

            <div class="form-group">
                <label>Projekt-Logo / Icon</label>
                <div class="file-upload-wrapper">
                    <input type="file" name="profilbild" id="profilbild" class="file-input">
                    <label for="profilbild" class="file-label">
                        <span class="icon">🖼️</span>
                        <span class="text">Bild für das Dashboard wählen...</span>
                    </label>
                </div>
            </div>

            <div class="form-footer">
                <a href="projekte.php" class="btn-cancel">Abbrechen</a>
                <button type="submit" class="btn-submit">Projekt anlegen ⚡</button>
            </div>
        </form>
    </div>
</div>

<style>
.creation-wrapper {
    padding: 60px 20px;
    display: flex;
    justify-content: center;
    min-height: calc(100vh - 80px);
    background: #f8fafc;
}

.creation-container {
    background: #fff;
    width: 100%;
    max-width: 700px;
    padding: 50px;
    border-radius: 30px;
    box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
}

.creation-header {
    text-align: center;
    margin-bottom: 40px;
}

.creation-icon {
    font-size: 48px;
    margin-bottom: 15px;
}

.creation-header h1 {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 10px;
    letter-spacing: -1px;
}

.creation-header p {
    color: #64748b;
    font-size: 15px;
}

.modern-form {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.form-row {
    display: flex;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
}

.flex-2 { flex: 2; }

.form-group label {
    font-size: 13px;
    font-weight: 700;
    color: #475569;
}

.required { color: #ef4444; }

.form-group input[type="text"], 
.form-group textarea, 
.form-group select {
    padding: 14px 18px;
    border: 2px solid #f1f5f9;
    border-radius: 12px;
    font-size: 15px;
    background: #f8fafc;
    transition: all 0.3s;
    outline: none;
}

.form-group input:focus, 
.form-group textarea:focus {
    border-color: #3b82f6;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.form-group textarea {
    height: 100px;
    resize: none;
}

.file-upload-wrapper {
    position: relative;
    height: 52px;
}

.file-input {
    width: 0.1px;
    height: 0.1px;
    opacity: 0;
    overflow: hidden;
    position: absolute;
    z-index: -1;
}

.file-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 18px;
    height: 100%;
    background: #f1f5f9;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    cursor: pointer;
    transition: 0.3s;
    color: #64748b;
    font-weight: 600;
    font-size: 14px;
}

.file-label:hover {
    background: #e2e8f0;
    border-color: #94a3b8;
}

.form-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 20px;
    margin-top: 20px;
    padding-top: 30px;
    border-top: 1px solid #f1f5f9;
}

.btn-cancel {
    color: #64748b;
    text-decoration: none;
    font-weight: 700;
    font-size: 14px;
    transition: 0.2s;
}

.btn-cancel:hover { color: #0f172a; }

.btn-submit {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #fff;
    padding: 16px 32px;
    border-radius: 15px;
    border: none;
    font-weight: 800;
    font-size: 16px;
    cursor: pointer;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.2);
    transition: 0.3s;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 30px rgba(37, 99, 235, 0.4);
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
