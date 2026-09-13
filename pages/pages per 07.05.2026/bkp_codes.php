<?php
// pages/bkp_codes.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$currentRole = current_role();
$canEdit = in_array($currentRole, ['admin', 'superadmin']);

// 1. Selbstreparatur-Logik (VOR jeglicher HTML-Ausgabe)
if ($canEdit && isset($_POST['action']) && $_POST['action'] === 'repair_schema') {
    $sqls = [
        "ALTER TABLE bkp_codes ADD COLUMN titel VARCHAR(255) NULL AFTER bezeichnung",
        "ALTER TABLE bkp_codes ADD COLUMN beschreibung TEXT NULL AFTER titel",
        "ALTER TABLE bkp_vorlagen_texte ADD COLUMN titel VARCHAR(255) NULL AFTER kategorie_id",
        "ALTER TABLE bkp_vorlagen_texte ADD COLUMN beschreibung TEXT NULL AFTER titel"
    ];
    foreach ($sqls as $sql) {
        try {
            $mysqli->query($sql);
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) {
                 $_SESSION['repair_err'] = $e->getMessage();
            }
        }
    }
    $_SESSION['flash_msg'] = "✅ Datenbank-Schema Reparatur abgeschlossen.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$flash = '';
if (isset($_SESSION['flash_msg'])) {
    $flash = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
if (isset($_SESSION['repair_err'])) {
    $flash .= ($flash ? "<br>" : "") . "❌ Letzter Fehler: " . $_SESSION['repair_err'];
    unset($_SESSION['repair_err']);
}

// 2. CRUD Logik
try {
    if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            $success = false;
            $msg = "";
            
            // Debug Log in php_errors.log
            error_log("BKP ACTION: " . ($_POST['action']??'none') . " - ID: " . ($_POST['id']??'none'));

            if ($_POST['action'] === 'add') {
                $code = trim($_POST['code']);
                $bez = trim($_POST['bezeichnung']);
                $titel = trim($_POST['titel'] ?? '');
                $beschr = trim($_POST['beschreibung'] ?? '');
                if ($code && $bez) {
                    $stmt = $mysqli->prepare("INSERT INTO bkp_codes (code, bezeichnung, titel, beschreibung) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('ssss', $code, $bez, $titel, $beschr);
                    $stmt->execute();
                    $msg = "✅ BKP Code #" . $mysqli->insert_id . " hinzugefügt.";
                    $success = true;
                }
            } elseif ($_POST['action'] === 'delete') {
                $id = (int)$_POST['id'];
                $stmt = $mysqli->prepare("DELETE FROM bkp_codes WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $msg = "🗑️ BKP Code #" . $id . " gelöscht.";
                    $success = true;
                } else {
                    throw new Exception("Löschen fehlgeschlagen: BKP Code #$id nicht gefunden.");
                }
            } elseif ($_POST['action'] === 'add_category') {
                $bkp_id = (int)$_POST['bkp_id'];
                $name = trim($_POST['name']);
                if ($bkp_id && $name) {
                    $stmt = $mysqli->prepare("INSERT INTO bkp_kategorien (bkp_id, name) VALUES (?, ?)");
                    $stmt->bind_param('is', $bkp_id, $name);
                    if (!$stmt->execute()) {
                        throw new Exception($stmt->error);
                    }
                    $msg = "✅ Kategorie #" . $mysqli->insert_id . " hinzugefügt.";
                    $success = true;
                }
            } elseif ($_POST['action'] === 'delete_category') {
                $id = (int)$_POST['id'];
                $stmt = $mysqli->prepare("DELETE FROM bkp_kategorien WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $msg = "🗑️ Kategorie #" . $id . " gelöscht.";
                    $success = true;
                } else {
                    throw new Exception("Löschen fehlgeschlagen: Kategorie #$id nicht gefunden.");
                }
            } elseif ($_POST['action'] === 'add_template') {
                $kat_id = (int)$_POST['kategorie_id'];
                $titel = trim($_POST['titel']);
                $beschr = trim($_POST['beschreibung']);
                if ($kat_id && ($titel || $beschr)) {
                    $stmt = $mysqli->prepare("INSERT INTO bkp_vorlagen_texte (kategorie_id, titel, beschreibung, text) VALUES (?, ?, ?, ?)");
                    $fallbackText = $titel . ($beschr ? ": " . $beschr : "");
                    $stmt->bind_param('isss', $kat_id, $titel, $beschr, $fallbackText);
                    if (!$stmt->execute()) {
                        throw new Exception($stmt->error);
                    }
                    $msg = "✅ Vorlage #" . $mysqli->insert_id . " hinzugefügt.";
                    $success = true;
                }
            } elseif ($_POST['action'] === 'delete_template') {
                $id = (int)$_POST['id'];
                $stmt = $mysqli->prepare("DELETE FROM bkp_vorlagen_texte WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                
                if ($stmt->affected_rows > 0) {
                    $msg = "🗑️ Vorlagentext #" . $id . " gelöscht.";
                    $success = true;
                } else {
                    throw new Exception("Löschen fehlgeschlagen: Kein Eintrag mit ID $id gefunden.");
                }
            } elseif ($_POST['action'] === 'edit') {
                $id = (int)$_POST['id'];
                $code = trim($_POST['code']);
                $bez = trim($_POST['bezeichnung']);
                $titel = trim($_POST['titel'] ?? '');
                $beschr = trim($_POST['beschreibung'] ?? '');
                if ($id && $code && $bez) {
                    $stmt = $mysqli->prepare("UPDATE bkp_codes SET code=?, bezeichnung=?, titel=?, beschreibung=? WHERE id=?");
                    $stmt->bind_param('ssssi', $code, $bez, $titel, $beschr, $id);
                    $stmt->execute();
                    $msg = "💾 BKP Code #" . $id . " aktualisiert.";
                    $success = true;
                }
            } elseif ($_POST['action'] === 'edit_category') {
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                if ($id && $name) {
                    $stmt = $mysqli->prepare("UPDATE bkp_kategorien SET name=? WHERE id=?");
                    $stmt->bind_param('si', $name, $id);
                    $stmt->execute();
                    $msg = "💾 Kategorie #" . $id . " aktualisiert.";
                    $success = true;
                }
            } elseif ($_POST['action'] === 'edit_template') {
                $id = (int)$_POST['id'];
                $beschr = trim($_POST['beschreibung']);
                if ($id && $beschr) {
                    $stmt = $mysqli->prepare("UPDATE bkp_vorlagen_texte SET titel='', beschreibung=?, text=? WHERE id=?");
                    $stmt->bind_param('ssi', $beschr, $beschr, $id);
                    $stmt->execute();
                    $msg = "💾 Vorlage #" . $id . " aktualisiert.";
                    $success = true;
                }
            }

            if ($success) {
                $_SESSION['flash_msg'] = $msg;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
    if (str_contains($err, 'Unknown column')) {
        $_SESSION['flash_msg'] = "⚠️ <strong>Datenbank-Struktur veraltet:</strong> $err<br><br>
                  <form method='post' style='display:inline;'>
                    <input type='hidden' name='action' value='repair_schema'>
                    <button type='submit' class='btn-premium' style='background:#f59e0b; border:none; cursor:pointer;'>Jetzt automatisch reparieren</button>
                  </form>";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $flash = "⚠️ Fehler: " . $err;
    }
}

// 3. Daten laden
try {
    $res = $mysqli->query("SELECT * FROM bkp_codes ORDER BY code ASC");
    $bkps = $res->fetch_all(MYSQLI_ASSOC);

    $resKat = $mysqli->query("SELECT * FROM bkp_kategorien ORDER BY name ASC");
    $katsMap = [];
    while($row = $resKat->fetch_assoc()) $katsMap[$row['bkp_id']][] = $row;

    $resText = $mysqli->query("SELECT * FROM bkp_vorlagen_texte ORDER BY id ASC");
    $textsMap = [];
    while($row = $resText->fetch_assoc()) $textsMap[$row['kategorie_id']][] = $row;
} catch (Throwable $e) {
    $err = $e->getMessage();
    if (str_contains($err, 'Unknown column')) {
        $flash = "⚠️ <strong>Datenbank-Struktur veraltet:</strong> $err<br><br>
                  <form method='post' style='display:inline;'>
                    <input type='hidden' name='action' value='repair_schema'>
                    <button type='submit' class='btn-premium' style='background:#f59e0b; border:none; cursor:pointer;'>Jetzt automatisch reparieren</button>
                  </form>";
    } else {
        $flash = "⚠️ Fehler beim Laden: " . $err;
    }
    $bkps = $katsMap = $textsMap = [];
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
:root {
    --primary: #4f46e5;
    --primary-hover: #4338ca;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --slate-50: #f8fafc;
    --slate-100: #f1f5f9;
    --slate-200: #e2e8f0;
    --slate-600: #475569;
    --slate-700: #334155;
    --slate-800: #1e293b;
}

.premium-container {
    font-family: 'Inter', -apple-system, sans-serif;
    color: var(--slate-800);
}

.input-modern {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--slate-200);
    border-radius: 10px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
}

.input-modern:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
}

.btn-modern {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
}

.btn-primary-modern {
    background: var(--primary);
    color: white;
}

.btn-primary-modern:hover {
    background: var(--primary-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
}

.btn-icon-danger:hover {
    background: #fee2e2 !important;
    color: var(--danger) !important;
    opacity: 1 !important;
}

.btn-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    color: var(--slate-400);
    padding: 0;
    pointer-events: auto !important;
}

.card-modern {
    background: white;
    border-radius: 16px;
    border: 1px solid var(--slate-200);
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    margin-bottom: 16px;
    overflow: hidden;
}

.bkp-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    cursor: pointer;
}

.bkp-header:hover {
    background: var(--slate-50);
}

.bkp-badge {
    background: var(--slate-100);
    color: var(--slate-600);
    font-weight: 700;
    font-size: 14px;
    padding: 8px 12px;
    border-radius: 10px;
    min-width: 50px;
    text-align: center;
}

.kat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 24px;
    padding: 24px;
    background: var(--slate-50);
    border-top: 1px solid var(--slate-200);
}

.tpl-card {
    background: white;
    border-radius: 14px;
    padding: 16px;
    border: 1px solid var(--slate-200);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);
}

.tpl-list-item {
    padding: 10px 12px;
    background: var(--slate-50);
    border-radius: 10px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: start;
    border: 1px solid transparent;
}

.tpl-list-item:hover {
    background: white;
    border-color: var(--slate-200);
    box-shadow: 0 2px 4px rgba(0,0,0,0.03);
}

.sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0;
}
</style>

<div class="premium-container" style="padding:40px 20px; max-width:1200px; margin:0 auto;">
    <div style="margin-bottom:40px;">
        <h1 style="margin:0; font-size:32px; font-weight:800; letter-spacing:-0.03em;">🏗️ BKP Codes & Vorlagen</h1>
        <p style="color:var(--slate-600); margin-top:8px;">Verwalten Sie Ihre Bau-Gattungen und standardisierten Mängeltexte.</p>
    </div>

    <?php if($flash): ?>
        <div style="background:#fffbeb; color:#92400e; padding:16px 20px; border-radius:14px; margin-bottom:30px; border:1px solid #fef3c7; line-height:1.6; display:flex; align-items:center; gap:12px;">
            <span style="font-size:20px;">ℹ️</span>
            <div><?= $flash ?></div>
        </div>
    <?php endif; ?>

    <?php if($canEdit): ?>
    <div class="card-modern" style="padding:24px; margin-bottom:40px; border:2px solid var(--slate-200);">
        <h3 style="margin-top:0; margin-bottom:20px; font-size:18px; font-weight:700;">Neuen BKP Code hinzufügen</h3>
        <form method="post" style="display:grid; grid-template-columns: 120px 1fr 1fr 1fr auto; gap:16px; align-items:end;">
            <input type="hidden" name="action" value="add">
            <div>
                <label for="new_code" style="display:block; font-size:12px; font-weight:700; margin-bottom:8px; color:var(--slate-600);">Code</label>
                <input type="text" id="new_code" name="code" placeholder="271" class="input-modern" required>
            </div>
            <div>
                <label for="new_bez" style="display:block; font-size:12px; font-weight:700; margin-bottom:8px; color:var(--slate-600);">Bezeichnung</label>
                <input type="text" id="new_bez" name="bezeichnung" placeholder="Gipserarbeiten" class="input-modern" required>
            </div>
            <div>
                <label for="new_titel" style="display:block; font-size:12px; font-weight:700; margin-bottom:8px; color:var(--slate-600);">Titel (kurz)</label>
                <input type="text" id="new_titel" name="titel" placeholder="Anzeige-Titel" class="input-modern">
            </div>
            <div>
                <label for="new_desc" style="display:block; font-size:12px; font-weight:700; margin-bottom:8px; color:var(--slate-600);">Info</label>
                <input type="text" id="new_desc" name="beschreibung" placeholder="Optionale Details..." class="input-modern">
            </div>
            <button type="submit" class="btn-modern btn-primary-modern" style="height:44px;">Hinzufügen</button>
        </form>
    </div>
    <?php endif; ?>

    <div id="bkp-accordion">
        <?php foreach($bkps as $b): ?>
            <div class="card-modern" id="bkp-<?= $b['id'] ?>">
                <details <?= (isset($_SESSION['open_bkp']) && $_SESSION['open_bkp'] == $b['id']) ? 'open' : '' ?> class="bkp-details" data-id="<?= $b['id'] ?>">
                    <summary class="bkp-header">
                        <div class="bkp-badge"><?= htmlspecialchars($b['code']) ?></div>
                        <div style="flex:1;">
                            <div style="font-weight:700; font-size:17px;"><?= htmlspecialchars($b['bezeichnung']) ?></div>
                            <?php if(!empty($b['titel'])): ?>
                                <div style="color:var(--slate-600); font-size:13px; margin-top:2px;"><?= htmlspecialchars($b['titel']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <?php if($canEdit): ?>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="btn-icon" title="Bearbeiten" onclick="event.stopPropagation(); toggleEdit('bkp_<?= $b['id'] ?>')">✏️</button>
                                <button type="button" class="btn-icon btn-icon-danger" title="BKP löschen" onclick="event.stopPropagation(); confirmDelete('delete', <?= $b['id'] ?>, 'BKP Code wirklich löschen?')">🗑️</button>
                            </div>
                            <?php endif; ?>
                            <span class="chevron" style="color:var(--slate-400); font-size:12px;">Öffnen ▼</span>
                        </div>
                    </summary>
                    
                    <!-- Inline Edit Form for BKP Code -->
                    <div id="edit_bkp_<?= $b['id'] ?>" style="display:none; padding:20px; background:var(--slate-50); border-bottom:1px solid var(--slate-200);">
                        <form method="post" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:10px;">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <div>
                                <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px; color:var(--slate-500);">Code</label>
                                <input type="text" name="code" value="<?= htmlspecialchars($b['code']) ?>" class="input-modern" style="height:38px;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px; color:var(--slate-500);">Bezeichnung</label>
                                <input type="text" name="bezeichnung" value="<?= htmlspecialchars($b['bezeichnung']) ?>" class="input-modern" style="height:38px;">
                            </div>
                            <div>
                                <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px; color:var(--slate-500);">Titel (kurz)</label>
                                <input type="text" name="titel" value="<?= htmlspecialchars($b['titel'] ?? '') ?>" class="input-modern" style="height:38px;">
                            </div>
                            <div style="grid-column: 1 / -1;">
                                <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px; color:var(--slate-500);">Info zur Gattung</label>
                                <textarea name="beschreibung" class="input-modern" style="height:80px;"><?= htmlspecialchars($b['beschreibung'] ?? '') ?></textarea>
                            </div>
                            <div style="grid-column: 1 / -1; display:flex; gap:10px; margin-top:10px;">
                                <button type="submit" class="btn-modern btn-primary-modern" style="height:38px; padding:0 24px;">Speichern</button>
                                <button type="button" class="btn-modern" style="height:38px; padding:0 24px; background:#e2e8f0; border:none;" onclick="toggleEdit('bkp_<?= $b['id'] ?>')">Abbrechen</button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="kat-grid">
                        <?php if(!empty($b['beschreibung'])): ?>
                            <div style="grid-column: 1 / -1; background:white; padding:16px; border-radius:12px; border:1px solid var(--slate-200); font-size:14px; line-height:1.6; color:var(--slate-700);">
                                <strong style="display:block; margin-bottom:4px; color:var(--slate-800);">Info zur Gattung:</strong>
                                <?= nl2br(htmlspecialchars($b['beschreibung'])) ?>
                            </div>
                        <?php endif; ?>

                        <?php 
                        $categories = $katsMap[$b['id']] ?? [];
                        foreach($categories as $kt): 
                        ?>
                            <div class="tpl-card">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--slate-100);">
                                    <h4 style="margin:0; font-size:15px; font-weight:800; color:var(--slate-800);"><?= htmlspecialchars($kt['name']) ?></h4>
                                    <?php if($canEdit): ?>
                                    <div style="display:flex; gap:4px;">
                                        <button type="button" class="btn-icon" style="width:28px; height:28px; font-size:12px;" onclick="toggleEdit('cat_<?= $kt['id'] ?>')">✏️</button>
                                        <button type="button" class="btn-icon btn-icon-danger" style="width:28px; height:28px; font-size:14px;" title="Kategorie löschen" onclick="event.stopPropagation(); confirmDelete('delete_category', <?= $kt['id'] ?>, 'Kategorie wirklich löschen? Alle Vorlagen darin gehen verloren.')">🗑️</button>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Edit Category Form -->
                                <div id="edit_cat_<?= $kt['id'] ?>" style="display:none; margin-bottom:16px; padding:12px; background:var(--slate-50); border-radius:10px; border:1px solid var(--primary);">
                                    <form method="post" style="display:flex; gap:8px;">
                                        <input type="hidden" name="action" value="edit_category">
                                        <input type="hidden" name="id" value="<?= $kt['id'] ?>">
                                        <input type="text" name="name" value="<?= htmlspecialchars($kt['name']) ?>" class="input-modern" style="height:34px; font-size:13px;">
                                        <button type="submit" class="btn-icon" style="background:var(--primary); color:white; width:34px; height:34px;">💾</button>
                                        <button type="button" class="btn-icon" style="width:34px; height:34px;" onclick="toggleEdit('cat_<?= $kt['id'] ?>')">✕</button>
                                    </form>
                                </div>

                                <div style="margin-bottom:20px;">
                                    <?php 
                                    $templates = $textsMap[$kt['id']] ?? [];
                                    foreach($templates as $tpl): 
                                    ?>
                                        <div class="tpl-list-item">
                                            <div style="flex:1; padding-right:10px;">
                                                <div style="font-weight:700; font-size:13px;"><?= htmlspecialchars($tpl['titel'] ?? '') ?></div>
                                                <div style="font-size:12px; color:var(--slate-600); margin-top:3px; line-height:1.5;"><?= htmlspecialchars($tpl['beschreibung'] ?? $tpl['text']) ?></div>
                                            </div>
                                            <?php if($canEdit): ?>
                                            <div style="display:flex; gap:4px; align-items:start;">
                                                <button type="button" class="btn-icon" style="width:28px; height:28px; font-size:12px;" onclick="toggleEdit('tpl_<?= $tpl['id'] ?>')">✏️</button>
                                                <button type="button" class="btn-icon btn-icon-danger" style="width:28px; height:28px; font-size:14px;" title="Vorlage löschen" onclick="event.stopPropagation(); confirmDelete('delete_template', <?= $tpl['id'] ?>, 'Vorlage wirklich löschen?')">🗑️</button>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Edit Template Form -->
                                        <div id="edit_tpl_<?= $tpl['id'] ?>" style="display:none; margin-bottom:12px; padding:12px; background:white; border:1px solid var(--primary); border-radius:10px;">
                                            <form method="post">
                                                <input type="hidden" name="action" value="edit_template">
                                                <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                                                <div style="margin-bottom:8px;">
                                                    <label style="display:block; font-size:11px; font-weight:700; margin-bottom:4px; color:var(--slate-500);">Kurz. Beschreibung</label>
                                                    <textarea name="beschreibung" class="input-modern" style="height:80px; font-size:13px;"><?= htmlspecialchars($tpl['beschreibung'] ?? $tpl['text']) ?></textarea>
                                                </div>
                                                <div style="display:flex; gap:8px;">
                                                    <button type="submit" class="btn-modern btn-primary-modern" style="height:32px; padding:0 16px; font-size:12px;">Speichern</button>
                                                    <button type="button" class="btn-modern" style="height:32px; padding:0 16px; font-size:12px; background:#e2e8f0; border:none;" onclick="toggleEdit('tpl_<?= $tpl['id'] ?>')">Abbrechen</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if($canEdit): ?>
                                <form method="post" style="background:var(--slate-50); padding:16px; border-radius:12px; border:1px solid var(--slate-200);">
                                    <input type="hidden" name="action" value="add_template">
                                    <input type="hidden" name="kategorie_id" value="<?= $kt['id'] ?>">
                                    
                                    <label for="tpl_titel_<?= $kt['id'] ?>" class="sr-only">Titel</label>
                                    <input type="text" id="tpl_titel_<?= $kt['id'] ?>" name="titel" placeholder="Titel (kurz, z.B. Riss)" class="input-modern" style="font-size:13px; margin-bottom:10px;" required>
                                    
                                    <label for="tpl_desc_<?= $kt['id'] ?>" class="sr-only">Detail-Text</label>
                                    <textarea id="tpl_desc_<?= $kt['id'] ?>" name="beschreibung" placeholder="Detail-Text (vollständig)..." class="input-modern" style="font-size:13px; min-height:70px; margin-bottom:12px;"></textarea>
                                    
                                    <button type="submit" class="btn-modern btn-primary-modern" style="width:100%; height:38px; font-size:13px;">Vorlage speichern</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if($canEdit): ?>
                        <div style="background:white; border:2px dashed var(--slate-200); border-radius:14px; padding:24px; display:flex; flex-direction:column; justify-content:center; align-items:center;">
                            <form method="post" style="width:100%; text-align:center;">
                                <input type="hidden" name="action" value="add_category">
                                <input type="hidden" name="bkp_id" value="<?= $b['id'] ?>">
                                <div style="font-size:13px; font-weight:700; color:var(--slate-600); margin-bottom:12px;">Neue Kategorie hinzufügen</div>
                                <input type="text" name="name" placeholder="Name (z.B. Decke)" class="input-modern" style="margin-bottom:12px;" required>
                                <button type="submit" class="btn-modern btn-primary-modern" style="width:100%; height:40px;">Hinzufügen</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Scroll-Position wiederherstellen
    const scrollPos = sessionStorage.getItem('bkp_scroll_pos');
    if (scrollPos) {
        window.scrollTo(0, parseInt(scrollPos));
        sessionStorage.removeItem('bkp_scroll_pos');
    }

    // Akkordeon-Status wiederherstellen
    const openId = sessionStorage.getItem('bkp_open_id');
    if (openId) {
        const details = document.querySelector(`.bkp-details[data-id="${openId}"]`);
        if (details) details.open = true;
        sessionStorage.removeItem('bkp_open_id');
    }

    // Beim Abschicken Status speichern
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            sessionStorage.setItem('bkp_scroll_pos', window.scrollY);
            
            // Finde heraus, welches Akkordeon gerade offen ist
            const openDetails = this.closest('.bkp-details');
            if (openDetails) {
                sessionStorage.setItem('bkp_open_id', openDetails.getAttribute('data-id'));
            }
        });
    });

    // Manuelle Status-Speicherung beim Öffnen eines Akkordeons (optional)
    document.querySelectorAll('.bkp-details').forEach(details => {
        details.addEventListener('toggle', function() {
            if (this.open) {
                // Schließe andere, falls gewünscht (optional)
                document.querySelectorAll('.bkp-details').forEach(d => {
                    if (d !== this) d.open = false;
                });
            }
        });
    });
});

function toggleEdit(id) {
    const el = document.getElementById('edit_' + id);
    if (el) {
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
        if (el.style.display === 'block') {
            const firstInput = el.querySelector('input[type="text"], textarea');
            if (firstInput) firstInput.focus();
        }
    }
}

function confirmDelete(action, id, message) {
    if (confirm(message)) {
        // Erstelle dynamisch ein Formular und sende es ab
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.name = 'action';
        actionInput.value = action;
        
        const idInput = document.createElement('input');
        idInput.name = 'id';
        idInput.value = id;
        
        form.appendChild(actionInput);
        form.appendChild(idInput);
        document.body.appendChild(form);
        
        // Speichere Scroll-Position
        sessionStorage.setItem('bkp_scroll_pos', window.scrollY);
        
        form.submit();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
