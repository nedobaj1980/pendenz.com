<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/functions.php';

// ----- Layout: Header + EINHEITLICHE Navigation -----
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/nav_dispatch.php';

$role = $_SESSION['rolle'] ?? '';
if (!in_array($role, ['superadmin', 'admin'])) {
    die("Zugriff verweigert: Nur Admin/Superadmin hat Zugriff auf dieses Tool.");
}

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die("DB-Verbindung nicht verfügbar: \$mysqli ist nicht gesetzt.");
}

function parseSwissDate($raw) {
    $raw = trim((string)$raw);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

function parseSwissAmount($raw) {
    $raw = str_replace(["'", " ", "CHF", "EUR", "\xc2\xa0"], '', trim((string)$raw));
    if (strpos($raw, ',') !== false && strpos($raw, '.') === false) {
        $raw = str_replace(',', '.', $raw);
    } elseif (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
        $raw = str_replace(',', '', $raw);
    }
    return (float)$raw;
}

$msg = "";
$dataPreview = [];

// Projekte laden
$projekte = [];
if ($res = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC")) {
    while ($r = $res->fetch_assoc()) $projekte[] = $r;
    $res->free();
}

// Upload -> Vorschau
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $fileTmp   = $_FILES['csv_file']['tmp_name'];
    $fileName  = $_FILES['csv_file']['name'];
    $projektId = (int)($_POST['projekt_id'] ?? 0);

    if ($projektId <= 0) die("Kein Projekt ausgewählt!");

    if (is_uploaded_file($fileTmp)) {
        if (($handle = fopen($fileTmp, "r")) !== FALSE) {
            // Delimiter erkennen
            $firstLine = fgets($handle);
            rewind($handle);
            $delim = ";";
            if (substr_count($firstLine, "\t") > substr_count($firstLine, ";")) $delim = "\t";
            elseif (substr_count($firstLine, ",") > substr_count($firstLine, ";")) $delim = ",";

            $header = fgetcsv($handle, 100000, $delim);
            while (($data = fgetcsv($handle, 100000, $delim)) !== FALSE) {
                if (empty($data) || (count($data) === 1 && empty($data[0]))) continue;
                $dataPreview[] = $data;
            }
            fclose($handle);
        }
        $_SESSION['csv_preview']  = $dataPreview;
        $_SESSION['csv_filename'] = $fileName;
        $_SESSION['csv_projekt']  = $projektId;
    }
}

// Speichern
if (isset($_POST['confirm_import']) && isset($_SESSION['csv_preview'])) {
    $fileName    = $_SESSION['csv_filename'];
    $previewData = $_SESSION['csv_preview'];
    $userId      = (int)($_SESSION['user_id'] ?? 0);
    $projektId   = (int)($_SESSION['csv_projekt'] ?? 0);

    $kategorien  = $_POST['kategorie'] ?? [];
    $assignments = $_POST['assigned_entity'] ?? [];

    $sql = "INSERT INTO liegenschafts_konto
            (liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, kategorie, zahlungsart,
             mieter_id, wohnung_id, wohnung_label, import_dateiname, import_benutzer_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);

    $savedCount = 0;
    $assignedCount = 0;

    foreach ($previewData as $idx => $row) {
        $buchungsdatum = parseSwissDate($row[1] ?? $row[0] ?? '');
        if ($buchungsdatum === null) continue;

        $betrag = parseSwissAmount($row[3] ?? $row[2] ?? '0');
        $beschreibung = trim((string)($row[2] ?? $row[1] ?? ''));

        $kategorie = trim((string)($kategorien[$idx] ?? ''));
        $assignVal = trim((string)($assignments[$idx] ?? ''));

        $mieter_id = null;
        $wohnung_id = null;
        $wohnung_label = null;

        if ($assignVal !== '') {
            $parts = explode(':', $assignVal);
            if (count($parts) === 3) {
                $mId = (int)$parts[1];
                $wId = (int)$parts[2];
                if ($mId > 0) $mieter_id = $mId;
                if ($wId > 0) $wohnung_id = $wId;
                $assignedCount++;
                if (empty($kategorie) && $betrag > 0) $kategorie = 'Miete';
            }
        }

        $zahlungsart = 'Überweisung';

        $stmt->bind_param("iisdsssiiisi",
            $projektId, $projektId, $buchungsdatum, $betrag, $beschreibung,
            $kategorie, $zahlungsart, $mieter_id, $wohnung_id, $wohnung_label,
            $fileName, $userId
        );
        $stmt->execute();
        $savedCount++;
    }
    $stmt->close();

    unset($_SESSION['csv_preview'], $_SESSION['csv_filename'], $_SESSION['csv_projekt']);
    $msg = "✅ $savedCount Buchungen erfolgreich importiert ($assignedCount Mieter/Einheiten zugewiesen).";
}

// Verwerfen
if (isset($_POST['cancel_import'])) {
    unset($_SESSION['csv_preview'], $_SESSION['csv_filename'], $_SESSION['csv_projekt']);
    $msg = "Import verworfen – keine Daten gespeichert.";
}

// Wenn Vorschau aktiv: Mieter & Wohnungen für das Projekt laden
$tenantsList = [];
$unitsList = [];
if (isset($_SESSION['csv_preview'])) {
    $selPid = (int)($_SESSION['csv_projekt'] ?? 0);
    $resT = $mysqli->query("
        SELECT wm.id as mieter_id, wm.mieter_name, wm.wohnung_id, w.name as wohnung_name, o.name as objekt_name
        FROM wohnung_mieter wm
        JOIN wohnungen w ON wm.wohnung_id = w.id
        JOIN objekte o ON w.objekt_id = o.id
        WHERE o.projekt_id = $selPid OR $selPid = 0
        ORDER BY w.name, wm.mieter_name
    ");
    if ($resT) while ($t = $resT->fetch_assoc()) $tenantsList[] = $t;

    $resU = $mysqli->query("
        SELECT w.id as wohnung_id, w.name as wohnung_name, o.name as objekt_name
        FROM wohnungen w
        JOIN objekte o ON w.objekt_id = o.id
        WHERE o.projekt_id = $selPid OR $selPid = 0
        ORDER BY w.name
    ");
    if ($resU) while ($u = $resU->fetch_assoc()) $unitsList[] = $u;
}
?>
<link rel="stylesheet" href="./style.css">

<div class="konto-container" style="max-width:1400px; margin:20px auto; padding:20px; background:#fff; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,0.06);">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h2 style="margin:0;">💳 Konto-Verwaltung – Bank-CSV Import & Mieter-Abgleich</h2>
    <div style="display:flex; gap:10px;">
      <a href="index.php" class="btn" style="padding:8px 14px; text-decoration:none; background:#f1f5f9; border-radius:8px; color:#334155;">⬅️ Zurück zum Konto</a>
      <a href="../mietkontrolle/index.php" class="btn primary" style="padding:8px 14px; text-decoration:none; background:#10b981; color:#fff; border-radius:8px;">📊 Zur Mietkontrolle</a>
    </div>
  </div>

  <?php if (!empty($msg)): ?>
    <div style="padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; color:#166534; margin-bottom:20px;">
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>

  <?php if (!isset($_SESSION['csv_preview'])): ?>
    <form method="post" enctype="multipart/form-data" action="" style="background:#f8fafc; padding:24px; border-radius:8px; border:1px solid #e2e8f0;">
      <div style="margin-bottom:16px;">
        <label style="font-weight:600; display:block; margin-bottom:6px;">1. Projekt / Liegenschaft auswählen:</label>
        <select name="projekt_id" required style="padding:10px; width:100%; max-width:500px; border-radius:6px; border:1px solid #cbd5e1;">
          <option value="">-- Bitte wählen --</option>
          <?php foreach ($projekte as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:20px;">
        <label style="font-weight:600; display:block; margin-bottom:6px;">2. Bank-CSV-Kontoauszug auswählen (z.B. Raiffeisen, PostFinance, UBS):</label>
        <input type="file" name="csv_file" accept=".csv,.txt" required style="padding:10px; border:1px dashed #94a3b8; border-radius:6px; width:100%; max-width:500px; background:#fff;">
      </div>

      <button type="submit" style="padding:10px 20px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
        📂 CSV laden & automatischen Mieter-Abgleich starten
      </button>
    </form>
  <?php else: ?>
    <form method="post">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <h3 style="margin:0;">Vorschau & Mieter-Zuweisung: <?= htmlspecialchars($_SESSION['csv_filename']) ?></h3>
        <div>
          <button type="submit" name="confirm_import" style="padding:10px 20px; background:#10b981; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
            ✅ <?= count($_SESSION['csv_preview']) ?> Buchungen importieren
          </button>
          <button type="submit" name="cancel_import" style="padding:10px 16px; background:#ef4444; color:#fff; border:none; border-radius:8px; cursor:pointer; margin-left:8px;">
            ❌ Abbrechen
          </button>
        </div>
      </div>

      <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px;">
        <thead>
          <tr style="background:#0f172a; color:#fff; text-align:left;">
            <th style="padding:10px;">Datum</th>
            <th style="padding:10px; text-align:right;">Betrag (CHF)</th>
            <th style="padding:10px;">Beschreibung / Buchungstext</th>
            <th style="padding:10px; min-width:260px;">Mieter / Wohnung Zuweisung</th>
            <th style="padding:10px; width:140px;">Kategorie</th>
          </tr>
        </thead>
        <tbody>
        <?php 
        $matchCount = 0;
        foreach ($_SESSION['csv_preview'] as $idx => $row): 
            $buchungsdatum = parseSwissDate($row[1] ?? $row[0] ?? '');
            $betrag = parseSwissAmount($row[3] ?? $row[2] ?? '0');
            $beschreibung = trim((string)($row[2] ?? $row[1] ?? ''));

            // Intelligentes Matching
            $matchedVal = '';
            $autoKat = ($betrag > 0) ? 'Miete' : 'Aufwand';

            if ($betrag > 0 && !empty($tenantsList)) {
                foreach ($tenantsList as $t) {
                    $mWords = preg_split('/[\s,\/&]+/', $t['mieter_name']);
                    foreach ($mWords as $mw) {
                        $mw = trim($mw);
                        if (mb_strlen($mw) >= 4 && stripos($beschreibung, $mw) !== false) {
                            $matchedVal = 'm:' . $t['mieter_id'] . ':' . $t['wohnung_id'];
                            $matchCount++;
                            break 2;
                        }
                    }
                }
            }
        ?>
          <tr style="border-bottom:1px solid #e2e8f0; <?= !empty($matchedVal) ? 'background:#ecfdf5;' : '' ?>">
            <td style="padding:10px;"><?= htmlspecialchars($buchungsdatum ?: '—') ?></td>
            <td style="padding:10px; text-align:right; font-weight:600; color:<?= $betrag >= 0 ? '#16a34a' : '#dc2626' ?>;">
              <?= number_format($betrag, 2, '.', "'") ?>
            </td>
            <td style="padding:10px; max-width:380px; word-break:break-word;">
              <?= htmlspecialchars($beschreibung) ?>
            </td>
            <td style="padding:10px;">
              <select name="assigned_entity[<?= $idx ?>]" style="width:100%; padding:6px; border-radius:6px; border:1px solid <?= !empty($matchedVal) ? '#10b981' : '#cbd5e1' ?>; background:#fff;">
                <option value="">-- Keine Zuweisung --</option>
                <?php if (!empty($tenantsList)): ?>
                  <optgroup label="Mieter (Automatische Mietkontrolle)">
                    <?php foreach ($tenantsList as $t): 
                        $val = 'm:' . $t['mieter_id'] . ':' . $t['wohnung_id'];
                        $sel = ($matchedVal === $val) ? 'selected' : '';
                    ?>
                      <option value="<?= $val ?>" <?= $sel ?>>
                        👤 <?= htmlspecialchars($t['wohnung_name']) ?>: <?= htmlspecialchars($t['mieter_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <?php if (!empty($unitsList)): ?>
                  <optgroup label="Wohnungen (ohne Mieter)">
                    <?php foreach ($unitsList as $u): 
                        $val = 'w:0:' . $u['wohnung_id'];
                        $sel = ($matchedVal === $val) ? 'selected' : '';
                    ?>
                      <option value="<?= $val ?>" <?= $sel ?>>
                        🏢 <?= htmlspecialchars($u['wohnung_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              </select>
            </td>
            <td style="padding:10px;">
              <input type="text" name="kategorie[<?= $idx ?>]" value="<?= htmlspecialchars(!empty($matchedVal) ? 'Miete' : $autoKat) ?>" style="padding:6px; width:100%; border-radius:6px; border:1px solid #cbd5e1;">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div style="padding:16px; background:#f8fafc; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
        <div>
          <strong>Gefundene Mieter-Matches:</strong> <?= $matchCount ?> von <?= count($_SESSION['csv_preview']) ?> Zeilen
        </div>
        <div>
          <button type="submit" name="confirm_import" style="padding:10px 24px; background:#10b981; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
            ✅ Buchungen jetzt speichern
          </button>
          <button type="submit" name="cancel_import" style="padding:10px 16px; background:#ef4444; color:#fff; border:none; border-radius:8px; cursor:pointer; margin-left:8px;">
            ❌ Verwerfen
          </button>
        </div>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

