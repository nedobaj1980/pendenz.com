<?php
require_once '../includes/header.php';
require_once '../includes/nav_dispatch.php';

// Nur Superadmin darf hier rein
if (($_SESSION['rolle'] ?? '') !== 'superadmin') {
    exit('Zugriff verweigert.');
}

// Migration / Setup falls Tabelle fehlt
$mysqli->query("CREATE TABLE IF NOT EXISTS `system_settings` (
  `s_key` VARCHAR(50) PRIMARY KEY,
  `s_value` TEXT
)");

// Defaults sicherstellen
$defaults = [
    'max_image_size_kb' => '500',
    'max_image_dimension' => '1600'
];
foreach ($defaults as $k => $v) {
    $mysqli->query("INSERT IGNORE INTO `system_settings` (`s_key`, `s_value`) VALUES ('$k', '$v')");
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $mysqli->prepare("UPDATE system_settings SET s_value = ? WHERE s_key = ?");
        $stmt->bind_param("ss", $val, $key);
        $stmt->execute();
    }
    $message = '<div class="alert alert-success">Einstellungen gespeichert!</div>';
}

// Einstellungen laden
$settings = [];
$res = $mysqli->query("SELECT * FROM system_settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['s_key']] = $row['s_value'];
}
?>

<main class="content-wrapper">
    <div class="container-fluid py-5">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card shadow-lg border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-primary text-white p-4 border-0">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-white bg-opacity-25 rounded-3 p-2">
                                <span style="font-size: 24px;">⚙️</span>
                            </div>
                            <div>
                                <h2 class="h4 mb-0 fw-bold">System-Einstellungen</h2>
                                <p class="mb-0 opacity-75 small">Zentrale Konfiguration für Medien & Uploads</p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-5 bg-light">
                        <?= $message ?>
                        
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="section-title mb-4">
                                <h3 class="h6 text-primary text-uppercase fw-bold ls-wider mb-3">Bilder & Speicheroptimierung</h3>
                                <div class="bg-primary opacity-10" style="height: 2px; width: 50px;"></div>
                            </div>

                            <div class="row g-4 mb-5">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark small">Max. Dateigröße</label>
                                    <div class="input-group shadow-sm">
                                        <input type="number" name="settings[max_image_size_kb]" class="form-control border-0 bg-white p-3" 
                                               value="<?= h($settings['max_image_size_kb'] ?? '500') ?>" required>
                                        <span class="input-group-text border-0 bg-white fw-bold text-muted">KB</span>
                                    </div>
                                    <div class="form-text mt-2 small opacity-75">Empfehlung: 500 KB für optimale Ladezeiten.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-dark small">Max. Dimension</label>
                                    <div class="input-group shadow-sm">
                                        <input type="number" name="settings[max_image_dimension]" class="form-control border-0 bg-white p-3" 
                                               value="<?= h($settings['max_image_dimension'] ?? '1600') ?>" required>
                                        <span class="input-group-text border-0 bg-white fw-bold text-muted">px</span>
                                    </div>
                                    <div class="form-text mt-2 small opacity-75">Die längste Seite des Bildes in Pixeln.</div>
                                </div>
                            </div>

                            <div class="alert alert-info border-0 shadow-sm bg-white d-flex align-items-start gap-3 p-4 mb-5 rounded-4">
                                <div class="text-primary mt-1">💡</div>
                                <div class="small text-muted">
                                    <strong class="text-dark d-block mb-1">Automatische Optimierung aktiv</strong>
                                    Bilder werden beim Hochladen automatisch auf diese Werte skaliert und komprimiert. Dies spart Speicherplatz und beschleunigt den Seitenaufbau auf mobilen Geräten.
                                </div>
                            </div>

                            <div class="d-grid pt-3">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold py-3 rounded-3 shadow-sm hover-lift">
                                    Konfiguration speichern
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
.content-wrapper {
    min-height: calc(100vh - 60px);
    transition: all 0.3s ease;
}
.ls-wider { letter-spacing: 0.05em; }
.hover-lift {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.1) !important;
}
.form-control:focus {
    box-shadow: none;
    background-color: #fff;
}
</style>

<?php require_once '../includes/footer.php'; ?>
