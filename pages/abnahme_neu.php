<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$current_pid = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));

// Daten laden
$projekte = [];
$res = $mysqli->query("SELECT id, name, nummer FROM projekte ORDER BY name");
if ($res) while($r = $res->fetch_assoc()) $projekte[] = $r;

$wohnungen = [];
if ($current_pid > 0) {
    $res = $mysqli->query("SELECT w.id, w.name FROM wohnungen w JOIN objekte o ON o.id = w.objekt_id WHERE o.projekt_id = $current_pid ORDER BY w.name");
    if ($res) while($r = $res->fetch_assoc()) $wohnungen[] = $r;
}

$unternehmer = [];
$res = $mysqli->query("SELECT id, name FROM benutzer WHERE rolle IN ('admin', 'partner') ORDER BY name");
if ($res) while($r = $res->fetch_assoc()) $unternehmer[] = $r;

// Speichern Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p_id = (int)$_POST['projekt_id'];
    $w_id = (int)$_POST['wohneinheit_id'];
    $u_id = (int)$_POST['unternehmer_id'];
    $typ  = $_POST['abnahme_typ'];
    $date = $_POST['datum'];
    $note = $_POST['bemerkungen'];

    $stmt = $mysqli->prepare("INSERT INTO abnahmen (projekt_id, wohneinheit_id, unternehmer_id, abnahme_typ, datum, bemerkungen) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiisss", $p_id, $w_id, $u_id, $typ, $date, $note);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    header("Location: abnahme_edit.php?id=" . $newId);
    exit;
}

$title = "Neue Abnahme planen | pendenz.com";
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="creation-wrapper">
    <div class="creation-container">
        <header class="creation-header">
            <div class="creation-icon">📜</div>
            <h1>Abnahme protokollieren</h1>
            <p>Wähle das Objekt und den Unternehmer aus, um die Abnahme zu starten.</p>
        </header>

        <form method="post" class="modern-form">
            <div class="form-group">
                <label>Projekt <span class="required">*</span></label>
                <div class="select-wrapper">
                    <select name="projekt_id" id="projekt_id" required onchange="location.href='?projekt_id='+this.value">
                        <option value="">-- Projekt wählen --</option>
                        <?php foreach($projekte as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($current_pid == $p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($p['name'] ?? '')) ?> (<?= htmlspecialchars((string)($p['nummer'] ?? 'N/A')) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Einheit / Wohnung</label>
                    <div class="select-wrapper">
                        <select name="wohneinheit_id">
                            <option value="0">-- Gesamtes Projekt / Allgemein --</option>
                            <?php foreach($wohnungen as $w): ?>
                                <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Abnahme-Datum</label>
                    <input type="date" name="datum" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label>Unternehmer / Zuständiger Partner</label>
                <div class="select-wrapper">
                    <select name="unternehmer_id" required>
                        <option value="">-- Unternehmer wählen --</option>
                        <?php foreach($unternehmer as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Art der Abnahme</label>
                <div class="select-wrapper">
                    <select name="abnahme_typ">
                        <option value="Vorabnahme">🛠️ Vorabnahme</option>
                        <option value="Bauabnahme" selected>🏗️ Bauabnahme / Unternehmerabnahme</option>
                        <option value="Mietabnahme">🔑 Mietabnahme / Übergabe</option>
                        <option value="Garantie">🛡️ Garantieprüfung</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Zusätzliche Bemerkungen</label>
                <textarea name="bemerkungen" placeholder="Wetterbedingungen, Teilnehmer, Besonderheiten..."></textarea>
            </div>

            <div class="form-footer">
                <a href="abnahmen.php" class="btn-cancel">Abbrechen</a>
                <button type="submit" class="btn-submit">Abnahme starten & Punkte erfassen ⚡</button>
            </div>
        </form>
    </div>
</div>

<style>
.creation-wrapper { padding: 40px 20px; display: flex; justify-content: center; background: #f8fafc; min-height: 100vh; }
.creation-container { background: #fff; width: 100%; max-width: 650px; padding: 40px; border-radius: 25px; box-shadow: 0 20px 50px rgba(0,0,0,0.05); }
.creation-header { text-align: center; margin-bottom: 30px; }
.creation-icon { font-size: 40px; }
.creation-header h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 10px 0; }
.creation-header p { color: #64748b; font-size: 14px; }

.modern-form { display: flex; flex-direction: column; gap: 20px; }
.form-row { display: flex; gap: 15px; }
.form-group { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 12px; font-weight: 700; color: #475569; }
.required { color: #ef4444; }

input, select, textarea { 
    padding: 12px 15px; border: 2px solid #f1f5f9; border-radius: 10px; 
    font-size: 14px; outline: none; transition: 0.2s; background: #f8fafc;
}
input:focus, select:focus, textarea:focus { border-color: #3b82f6; background: #fff; }
textarea { height: 80px; resize: none; }

.form-footer { display: flex; justify-content: flex-end; align-items: center; gap: 20px; margin-top: 10px; pt: 20px; border-top: 1px solid #f1f5f9; padding-top:20px; }
.btn-cancel { text-decoration: none; color: #64748b; font-size: 13px; font-weight: 700; }
.btn-submit { 
    background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; padding: 14px 24px; 
    border-radius: 12px; border: 0; font-weight: 800; cursor: pointer; transition: 0.3s;
}
.btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(37, 99, 235, 0.3); }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
