<?php
// pages/firmen.php — Firmen-Vorlagen verwalten (keine Benutzer-Verknüpfung)
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php'; // best_image_url()
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

$currentRole   = $_SESSION['rolle'] ?? 'gast';
$canManage     = in_array($currentRole, ['admin','superadmin'], true);
if (!$canManage) { http_response_code(403); exit('Nur Admin/Superadmin.'); }
ensure_firmen_vorlagen_map($mysqli);

$PH_COMP = $PREFIX . 'assets/img/placeholder_company.png';

function load_firma(mysqli $db, int $id): ?array {
  $st = $db->prepare("SELECT id,name,adresse,ort,telefon,email,website,logo,bkp_id FROM firmen WHERE id=?");
  $st->bind_param("i",$id);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  $st->close();
  return $r ?: null;
}


function ensure_firmen_vorlagen_map(mysqli $db): void {
  $db->query("
    CREATE TABLE IF NOT EXISTS firmen_vorlagen_map (
      id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      firma_id INT NOT NULL,
      welt VARCHAR(30) NOT NULL,
      ref_id INT NOT NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_firma_welt_ref (firma_id, welt, ref_id),
      KEY idx_firma_welt (firma_id, welt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function firma_vorlagen_ids(mysqli $db, int $firmaId): array {
  ensure_firmen_vorlagen_map($db);
  $out = ['bkp' => [], 'mieter' => [], 'vermieter' => []];
  $st = $db->prepare("SELECT welt, ref_id FROM firmen_vorlagen_map WHERE firma_id=? ORDER BY welt, ref_id");
  if ($st) {
    $st->bind_param("i", $firmaId);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) {
      $welt = (string)($row['welt'] ?? '');
      if (isset($out[$welt])) $out[$welt][] = (int)$row['ref_id'];
    }
    $st->close();
  }
  return $out;
}

function save_firma_vorlagen_ids(mysqli $db, int $firmaId, string $welt, array $ids): void {
  ensure_firmen_vorlagen_map($db);
  $allowed = ['bkp', 'mieter', 'vermieter'];
  if (!in_array($welt, $allowed, true)) return;

  $db->query("DELETE FROM firmen_vorlagen_map WHERE firma_id=".(int)$firmaId." AND welt='".$db->real_escape_string($welt)."'");
  $clean = [];
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) $clean[$id] = $id;
  }
  if (!$clean) return;

  $st = $db->prepare("INSERT INTO firmen_vorlagen_map (firma_id, welt, ref_id) VALUES (?, ?, ?)");
  if ($st) {
    foreach ($clean as $id) {
      $st->bind_param("isi", $firmaId, $welt, $id);
      $st->execute();
    }
    $st->close();
  }
}

$flash = "";
$edit  = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
      $name    = trim($_POST['name'] ?? '');
      $adresse = trim($_POST['adresse'] ?? '');
      $ort     = trim($_POST['ort'] ?? '');
      $telefon = trim($_POST['telefon'] ?? '');
      $email   = trim($_POST['email'] ?? '');
      $website = trim($_POST['website'] ?? '');
      $logo    = trim($_POST['logo'] ?? ''); // URL optional
      $bkp_ids = array_map('intval', (array)($_POST['bkp_ids'] ?? []));
      $mieter_kategorie_ids = array_map('intval', (array)($_POST['mieter_kategorie_ids'] ?? []));
      $vermieter_kategorie_ids = array_map('intval', (array)($_POST['vermieter_kategorie_ids'] ?? []));
      $bkp_id  = !empty($bkp_ids) ? (int)reset($bkp_ids) : (($_POST['bkp_id'] ?? '') !== '' ? (int)$_POST['bkp_id'] : null);
      
      if ($name==='') throw new Exception("Name ist Pflichtfeld.");

      // 1) Insert
      $st = $mysqli->prepare("INSERT INTO firmen (name,adresse,ort,telefon,email,website,logo,bkp_id) VALUES (?,?,?,?,?,?,?,?)");
      $st->bind_param("sssssssi", $name,$adresse,$ort,$telefon,$email,$website,$logo,$bkp_id);
      $st->execute(); 
      $newId = (int)$mysqli->insert_id;
      $st->close();

      // 2) Datei-Upload oder Base64 (Paste)
      $finalLogo = $logo;
      $uploadError = false;

      $clipboardData = $_POST['pasted_image'] ?? '';
      if (!empty($clipboardData) && str_starts_with($clipboardData, 'data:image/')) {
          // Base64-Paste verarbeiten
          $parts = explode(',', $clipboardData);
          $data = base64_decode($parts[1]);
          $dir = __DIR__ . '/../uploads/firmen/';
          if (!is_dir($dir)) @mkdir($dir, 0775, true);
          $safe = 'firma_'.$newId.'_pasted_'.time().'.png';
          file_put_contents($dir . $safe, $data);
          $finalLogo = $PREFIX.'uploads/firmen/'.$safe;
          $uploadError = true; // Trigger update below
      } elseif (!empty($_FILES['logo_file']['tmp_name'])) {
        $dir = __DIR__ . '/../uploads/firmen/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ext  = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION) ?: 'png');
        $safe = 'firma_'.$newId.'_logo.'.$ext;
        $dest = $dir.$safe;
        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
          $finalLogo = $PREFIX.'uploads/firmen/'.$safe;
          $uploadError = true;
        }
      }

      if ($uploadError) {
          $up = $mysqli->prepare("UPDATE firmen SET logo=? WHERE id=?");
          $up->bind_param("si", $finalLogo, $newId);
          $up->execute(); $up->close();
      }

      save_firma_vorlagen_ids($mysqli, $newId, 'bkp', $bkp_ids);
      save_firma_vorlagen_ids($mysqli, $newId, 'mieter', $mieter_kategorie_ids);
      save_firma_vorlagen_ids($mysqli, $newId, 'vermieter', $vermieter_kategorie_ids);

      $flash = "✅ Firma wurde gespeichert.";
      $edit = null;

    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception("Ungültige ID.");

      $name    = trim($_POST['name'] ?? '');
      $adresse = trim($_POST['adresse'] ?? '');
      $ort     = trim($_POST['ort'] ?? '');
      $telefon = trim($_POST['telefon'] ?? '');
      $email   = trim($_POST['email'] ?? '');
      $website = trim($_POST['website'] ?? '');
      $logo    = trim($_POST['logo'] ?? ''); // URL optional
      $bkp_ids = array_map('intval', (array)($_POST['bkp_ids'] ?? []));
      $mieter_kategorie_ids = array_map('intval', (array)($_POST['mieter_kategorie_ids'] ?? []));
      $vermieter_kategorie_ids = array_map('intval', (array)($_POST['vermieter_kategorie_ids'] ?? []));
      $bkp_id  = !empty($bkp_ids) ? (int)reset($bkp_ids) : (($_POST['bkp_id'] ?? '') !== '' ? (int)$_POST['bkp_id'] : null);
      
      if ($name==='') throw new Exception("Name ist Pflichtfeld.");

      $finalLogo = $logo;
      $clipboardData = $_POST['pasted_image'] ?? '';
      
      if (!empty($clipboardData) && str_starts_with($clipboardData, 'data:image/')) {
          $parts = explode(',', $clipboardData);
          $data = base64_decode($parts[1]);
          $dir = __DIR__ . '/../uploads/firmen/';
          if (!is_dir($dir)) @mkdir($dir, 0775, true);
          $safe = 'firma_'.$id.'_pasted_'.time().'.png';
          file_put_contents($dir . $safe, $data);
          $finalLogo = $PREFIX.'uploads/firmen/'.$safe;
      } elseif (!empty($_FILES['logo_file']['tmp_name'])) {
        $dir = __DIR__ . '/../uploads/firmen/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ext  = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION) ?: 'png');
        $safe = 'firma_'.$id.'_logo.'.$ext;
        $dest = $dir.$safe;
        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
          $finalLogo = $PREFIX.'uploads/firmen/'.$safe;
        }
      }

      $st = $mysqli->prepare("UPDATE firmen SET name=?, adresse=?, ort=?, telefon=?, email=?, website=?, logo=?, bkp_id=? WHERE id=?");
      $st->bind_param("sssssssii", $name,$adresse,$ort,$telefon,$email,$website,$finalLogo,$bkp_id,$id);
      $st->execute(); $st->close();
      save_firma_vorlagen_ids($mysqli, $id, 'bkp', $bkp_ids);
      save_firma_vorlagen_ids($mysqli, $id, 'mieter', $mieter_kategorie_ids);
      save_firma_vorlagen_ids($mysqli, $id, 'vermieter', $vermieter_kategorie_ids);

      $flash = "✅ Firma aktualisiert.";

    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id<=0) throw new Exception("Ungültige ID.");
      $mysqli->query("DELETE FROM firmen_vorlagen_map WHERE firma_id=".(int)$id);
      $st = $mysqli->prepare("DELETE FROM firmen WHERE id=?");
      $st->bind_param("i",$id);
      $st->execute(); $st->close();
      $flash = "🗑️ Firma gelöscht.";
      $edit  = null;
    }

  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

// Liste laden
$list = [];
if ($res = $mysqli->query("SELECT f.* FROM firmen f ORDER BY f.name")) {
  while ($r = $res->fetch_assoc()) {
    $r['_vorlagen_ids'] = firma_vorlagen_ids($mysqli, (int)$r['id']);
    $list[] = $r;
  }
  $res->close();
}

// BKP Codes laden
$bkpList = [];
$bkpNames = [];
if ($res = $mysqli->query("SELECT id, code, bezeichnung FROM bkp_codes ORDER BY code")) {
    while ($r = $res->fetch_assoc()) {
      $bkpList[] = $r;
      $bkpNames[(int)$r['id']] = trim(($r['code'] ?? '') . ' ' . ($r['bezeichnung'] ?? ''));
    }
    $res->close();
}

$mieterKategorieList = [];
$mieterKategorieNames = [];
if ($res = $mysqli->query("SELECT id, name FROM pendenz_kategorien_mieter WHERE COALESCE(aktiv,1)=1 ORDER BY COALESCE(sortierung,0), name")) {
    while ($r = $res->fetch_assoc()) {
      $mieterKategorieList[] = $r;
      $mieterKategorieNames[(int)$r['id']] = (string)$r['name'];
    }
    $res->close();
}

$vermieterKategorieList = [];
$vermieterKategorieNames = [];
if ($res = $mysqli->query("SELECT id, name FROM pendenz_kategorien_vermieter WHERE COALESCE(aktiv,1)=1 ORDER BY COALESCE(sortierung,0), name")) {
    while ($r = $res->fetch_assoc()) {
      $vermieterKategorieList[] = $r;
      $vermieterKategorieNames[(int)$r['id']] = (string)$r['name'];
    }
    $res->close();
}

// Edit-Item
$editing = $edit ? load_firma($mysqli, (int)$edit) : null;
$editingVorlagen = $editing ? firma_vorlagen_ids($mysqli, (int)$editing['id']) : ['bkp'=>[], 'mieter'=>[], 'vermieter'=>[]];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
.container{max-width:1240px;margin:20px auto}
.card{background:#fff;border-radius:10px;padding:12px;margin:12px 0;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.grid{display:grid;grid-template-columns:220px 1fr;gap:10px;align-items:center}
.grid input{padding:8px;border:1px solid #e5e7eb;border-radius:6px;width:100%}
.grid a{word-break:break-all}
.btn{padding:8px 12px;border:0;border-radius:6px;background:#0a2a6e;color:#fff;cursor:pointer}
.btn:hover{background:#071c4a}
.btn-outline{padding:6px 10px;border:1px solid #0a2a6e;background:#fff;color:#0a2a6e;border-radius:6px;cursor:pointer}
.btn-danger{background:#b00020}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}
.logo-cell img{max-height:36px;border-radius:4px}
.row-actions{display:flex;gap:8px}
.header-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.pick-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.pick-row select{min-width:320px;max-width:100%;padding:8px;border:1px solid #e5e7eb;border-radius:6px}
.chip-box{display:flex;flex-wrap:wrap;gap:8px;min-height:44px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f8fafc}
.chip-box.is-empty{color:#94a3b8;font-size:12px;font-style:italic}
.assign-chip{display:inline-flex;align-items:center;gap:8px;background:#e0f2fe;color:#075985;border:1px solid #7dd3fc;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}
.assign-chip button{border:0;background:transparent;color:#075985;cursor:pointer;font-size:14px;line-height:1;padding:0}
.hidden-select{display:none}
</style>

<div class="container">
  <div class="card">
    <div class="header-actions">
      <h1 style="margin:0;">Firmen-Vorlagen</h1>
      <a href="<?= htmlspecialchars($PREFIX) ?>pages/benutzer.php" class="btn-outline">← Zur Benutzerverwaltung</a>
    </div>
    <p style="color:#555;margin:8px 0 0;">Diese Vorlagen sind <em>nicht</em> automatisch Benutzer – sie dienen nur dazu, bei der Benutzer-Registrierung schneller zu befüllen.</p>
  </div>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2><?= $editing ? 'Firma bearbeiten' : 'Neue Firma registrieren' ?></h2>
    <form method="post" autocomplete="on" enctype="multipart/form-data">
      <?= csrf_input() ?>
      <?php if($editing): ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php else: ?>
        <input type="hidden" name="action" value="create">
      <?php endif; ?>

      <div class="grid">
        <label for="name">Name*</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($editing['name'] ?? '') ?>" placeholder="Firmenname...">

        <label for="adresse">Adresse</label>
        <input type="text" id="adresse" name="adresse" value="<?= htmlspecialchars($editing['adresse'] ?? '') ?>" placeholder="Musterstrasse 123">

        <label for="ort">Ort</label>
        <input type="text" id="ort" name="ort" value="<?= htmlspecialchars($editing['ort'] ?? '') ?>" placeholder="8000 Zürich">

        <label for="telefon">Telefon</label>
        <input type="text" id="telefon" name="telefon" value="<?= htmlspecialchars($editing['telefon'] ?? '') ?>">

        <label for="email">E-Mail</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($editing['email'] ?? '') ?>">

        <label for="website">Website</label>
        <input type="url" id="website" name="website" placeholder="https://…" value="<?= htmlspecialchars($editing['website'] ?? '') ?>">

        <label for="bkp_picker">BKP / Unternehmer</label>
        <div>
          <div class="pick-row">
            <select id="bkp_picker">
              <option value="">— BKP wählen —</option>
              <?php foreach($bkpList as $b): ?>
                <option value="<?= (int)$b['id'] ?>" data-label="<?= htmlspecialchars(trim($b['code'] . ' ' . $b['bezeichnung']), ENT_QUOTES) ?>">
                  <?= htmlspecialchars($b['code'] . ' ' . $b['bezeichnung']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn-outline" id="add_bkp_btn">+ BKP hinzufügen</button>
          </div>
          <div id="bkp_chip_box" class="chip-box <?= empty($editingVorlagen['bkp']) ? 'is-empty' : '' ?>">
            <?php if (empty($editingVorlagen['bkp'])): ?>
              <span id="bkp_empty_hint">Noch keine BKP aktiv…</span>
            <?php endif; ?>
          </div>
          <select id="bkp_ids" name="bkp_ids[]" multiple class="hidden-select">
            <?php foreach($bkpList as $b): ?>
                <?php $sel = in_array((int)$b['id'], $editingVorlagen['bkp'] ?? [], true); ?>
                <option value="<?= (int)$b['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                    <?= htmlspecialchars($b['code'] . ' ' . $b['bezeichnung']) ?>
                </option>
            <?php endforeach; ?>
          </select>
          <small style="color:#64748b;">Hier siehst du separat, welche BKP aktuell für diese Firma aktiv sind.</small>
        </div>


        <label for="logo">Logo-URL (optional)</label>
        <div style="display:flex; flex-direction:column; gap:4px;">
            <input type="text" id="logo_url_input" name="logo" placeholder="/pendenz.com/uploads/firmen/logo.png oder https://…" value="<?= htmlspecialchars($editing['logo'] ?? '') ?>">
            <small style="color:#64748b;">Tipp: Bild auf Homepage kopieren & hier mit <b>Strg + V</b> einfügen!</small>
        </div>

        <label>Logo-Datei / Vorschau</label>
        <div style="display:flex;align-items:center;gap:15px; background:#f8fafc; padding:10px; border-radius:10px; border:2px dashed #e2e8f0;">
          <img id="logo_preview" src="<?= htmlspecialchars(best_image_url($editing['logo'] ?? null) ?: $PH_COMP) ?>" style="max-height:64px; border-radius:8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);" alt="Logo">
          <input type="file" name="logo_file" accept="image/*" id="logo_file_input">
          <input type="hidden" name="pasted_image" id="pasted_image_input">
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px;">
        <button class="btn" type="submit" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); padding: 12px 24px; font-weight: 700;"><?= $editing ? 'Änderungen speichern' : 'Firma anlegen' ?></button>
        <?php if($editing): ?>
          <a href="<?= htmlspecialchars($PREFIX) ?>pages/firmen.php" class="btn-outline">Abbrechen</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <script>
  // Paste Image Handler
  document.addEventListener('paste', function(e) {
      const items = (e.clipboardData || e.originalEvent.clipboardData).items;
      for (let i = 0; i < items.length; i++) {
          if (items[i].type.indexOf('image') !== -1) {
              const blob = items[i].getAsFile();
              const reader = new FileReader();
              reader.onload = function(event) {
                  const base64 = event.target.result;
                  document.getElementById('pasted_image_input').value = base64;
                  document.getElementById('logo_preview').src = base64;
                  
                  // Optisches Feedback
                  const previewDiv = document.getElementById('logo_preview').parentElement;
                  previewDiv.style.borderColor = '#10b981';
                  previewDiv.style.backgroundColor = '#ecfdf5';
                  
                  // Benachrichtigung
                  const msg = document.createElement('div');
                  msg.innerText = '📸 Bild aus Zwischenablage erkannt!';
                  msg.style = 'position:fixed; top:20px; right:20px; background:#10b981; color:white; padding:12px 24px; border-radius:12px; font-weight:700; box-shadow:0 10px 25px rgba(16,185,129,0.3); z-index:9999; animation: slideIn 0.3s ease-out;';
                  document.body.appendChild(msg);
                  setTimeout(() => { msg.style.opacity = '0'; setTimeout(() => msg.remove(), 500); }, 3000);
              };
              reader.readAsDataURL(blob);
          }
      }
  });

  // Animation CSS injecting
  const style = document.createElement('style');
  style.innerHTML = `
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    /* BKP Chip Styles */
    .bkp-chip {
      display: inline-flex; align-items: center; gap: 5px;
      background: linear-gradient(135deg, #e0f2fe, #bae6fd);
      color: #0369a1; border: 1px solid #7dd3fc;
      padding: 4px 10px 4px 12px; border-radius: 999px;
      font-size: 12px; font-weight: 700; cursor: default;
      animation: chipIn 0.15s ease-out;
      white-space: nowrap;
    }
    .bkp-chip .chip-remove {
      background: none; border: none; cursor: pointer;
      color: #0369a1; font-size: 14px; line-height: 1;
      padding: 0 2px; opacity: 0.7; transition: opacity 0.15s;
    }
    .bkp-chip .chip-remove:hover { opacity: 1; color: #dc2626; }
    @keyframes chipIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    #bkp_chips_firmen:empty::before {
      content: 'Noch keine Gewerke ausgewählt…';
      color: #94a3b8; font-size: 12px; font-style: italic;
      display: flex; align-items: center; height: 100%;
    }
  `;
  document.head.appendChild(style);

  // BKP Chip Widget
  (function() {
    const picker = document.getElementById('bkp_picker');
    const addBtn = document.getElementById('add_bkp_btn');
    const hiddenSelect = document.getElementById('bkp_ids');
    const chipBox = document.getElementById('bkp_chip_box');

    if (!picker || !addBtn || !hiddenSelect || !chipBox) return;

    function getSelectedValues() {
      return Array.from(hiddenSelect.options).filter(o => o.selected).map(o => String(o.value));
    }

    function setEmptyState(isEmpty) {
      chipBox.classList.toggle('is-empty', isEmpty);
      const existingHint = document.getElementById('bkp_empty_hint');
      if (isEmpty) {
        if (!existingHint) {
          const span = document.createElement('span');
          span.id = 'bkp_empty_hint';
          span.textContent = 'Noch keine BKP aktiv…';
          chipBox.appendChild(span);
        }
      } else if (existingHint) {
        existingHint.remove();
      }
    }

    function renderChips() {
      const values = getSelectedValues();
      chipBox.querySelectorAll('.assign-chip').forEach(el => el.remove());
      setEmptyState(values.length === 0);

      Array.from(hiddenSelect.options).forEach(option => {
        if (!option.selected) return;
        const chip = document.createElement('span');
        chip.className = 'assign-chip';
        chip.dataset.value = option.value;
        chip.innerHTML = '<span>' + option.text + '</span><button type="button" aria-label="Entfernen">×</button>';
        chip.querySelector('button').addEventListener('click', function() {
          option.selected = false;
          renderChips();
        });
        chipBox.appendChild(chip);
      });
    }

    function addCurrentBkp() {
      const value = picker.value;
      if (!value) return;
      const targetOption = Array.from(hiddenSelect.options).find(o => String(o.value) === String(value));
      if (targetOption) {
        targetOption.selected = true;
        renderChips();
      }
      picker.value = '';
    }

    addBtn.addEventListener('click', addCurrentBkp);
    picker.addEventListener('change', function() {
      if (picker.value) addCurrentBkp();
    });

    renderChips();
  })();

  </script>

  <div class="card">
    <h2>Alle Firmen</h2>
    <table class="table">
      <thead>
        <tr>
          <th>ID</th><th>Name</th><th>BKP</th><th>Adresse</th><th>E-Mail / Tel.</th><th>Website</th><th>Logo</th><th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($list as $f): 
          $logo = best_image_url($f['logo'] ?? null) ?: $PH_COMP; ?>
          <tr>
            <td><?= (int)$f['id'] ?></td>
            <td>
                <div style="font-weight:700; color:#1e293b;"><?= htmlspecialchars($f['name']) ?></div>
                <div style="font-size:11px; color:#64748b;">
                    <?= htmlspecialchars($f['adresse'] ?? '') ?><br>
                    <?= htmlspecialchars($f['ort'] ?? '') ?>
                </div>
            </td>
            <td>
                <?php
                  $fBkp = [];
                  foreach (($f['_vorlagen_ids']['bkp'] ?? []) as $idv) { if (isset($bkpNames[$idv])) $fBkp[] = $bkpNames[$idv]; }
                  $fMieter = [];
                  foreach (($f['_vorlagen_ids']['mieter'] ?? []) as $idv) { if (isset($mieterKategorieNames[$idv])) $fMieter[] = $mieterKategorieNames[$idv]; }
                  $fVermieter = [];
                  foreach (($f['_vorlagen_ids']['vermieter'] ?? []) as $idv) { if (isset($vermieterKategorieNames[$idv])) $fVermieter[] = $vermieterKategorieNames[$idv]; }
                ?>
                <div style="display:flex; flex-direction:column; gap:6px;">
                  <div><strong style="font-size:11px; color:#0f172a;">BKP:</strong> <span style="font-size:11px; color:#475569;"><?= htmlspecialchars($fBkp ? implode(', ', $fBkp) : '—') ?></span></div>
                </div>
            </td>
            <td><?= htmlspecialchars($f['adresse'] ?? '') ?></td>
            <td>
                <div style="font-size:12px;"><?= htmlspecialchars($f['email'] ?? '') ?></div>
                <div style="font-size:11px; color:#64748b;"><?= htmlspecialchars($f['telefon'] ?? '') ?></div>
            </td>
            <td><?php if (!empty($f['website'])): ?><a href="<?= htmlspecialchars($f['website']) ?>" target="_blank" rel="noopener" style="color:#0ea5e9; text-decoration:none; font-weight:600;">Link ↗</a><?php endif; ?></td>
            <td class="logo-cell"><img src="<?= htmlspecialchars($logo) ?>" alt="Logo" style="max-height:30px; border-radius:4px;"></td>
            <td class="row-actions">
              <a class="btn-outline" href="?edit=<?= (int)$f['id'] ?>" style="border-color:#e2e8f0; color:#475569;">Bearbeiten</a>
              <form method="post" onsubmit="return confirm('Diese Firma wirklich löschen?');" style="display:inline;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete" id="delete_action">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="submit" class="btn-danger" style="background:#fee2e2; color:#ef4444; border:none;">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (empty($list)): ?>
          <tr><td colspan="8" style="color:#777; text-align:center; padding:40px;">Noch keine Firmen erfasst.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
