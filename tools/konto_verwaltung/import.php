<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PENDENZ_SESSID');
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/functions.php';

// ----- Layout: Header + Navigation -----
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
$msgType = "success";
$dataPreview = [];

// 1. Projekte (Liegenschaften) laden
$projekte = [];
if ($res = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC")) {
    while ($r = $res->fetch_assoc()) $projekte[] = $r;
    $res->free();
}

// 2. Bankkonten (kv_konten) laden
$kontenList = [];
$kRes = $mysqli->query("SELECT k.id, k.name, k.iban, k.bank, k.projekt_id, p.name as projekt_name 
                        FROM kv_konten k 
                        LEFT JOIN projekte p ON k.projekt_id = p.id 
                        ORDER BY p.name ASC, k.name ASC");
if ($kRes) while ($k = $kRes->fetch_assoc()) $kontenList[] = $k;

// 3. Upload verarbeiten -> Vorschau
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $fileTmp   = $_FILES['csv_file']['tmp_name'] ?? '';
    $fileName  = $_FILES['csv_file']['name'] ?? '';
    $projektId = (int)($_POST['projekt_id'] ?? 0);
    $kontoId   = (int)($_POST['konto_id'] ?? 0);

    // Automatische IBAN-Erkennung aus Dateinamen (z.B. CH0680808001916350814)
    if (preg_match('/CH[0-9]{19}/i', $fileName, $mIban)) {
        $detectedIban = strtoupper($mIban[0]);
        foreach ($kontenList as $k) {
            $cleanKontoIban = str_replace(' ', '', strtoupper($k['iban'] ?? ''));
            if ($cleanKontoIban === $detectedIban) {
                if ($kontoId <= 0) $kontoId = (int)$k['id'];
                if ($projektId <= 0) $projektId = (int)$k['projekt_id'];
                break;
            }
        }
    }

    // Wenn Konto gewählt aber kein Projekt, Projekt ableiten
    if ($kontoId > 0 && $projektId <= 0) {
        foreach ($kontenList as $k) {
            if ((int)$k['id'] === $kontoId && !empty($k['projekt_id'])) {
                $projektId = (int)$k['projekt_id'];
                break;
            }
        }
    }

    // Wenn Projekt gewählt aber kein Konto, passendes Liegenschafts-Bankkonto wählen
    $kontoNr = '';
    if ($kontoId <= 0 && $projektId > 0) {
        foreach ($kontenList as $k) {
            if ((int)$k['projekt_id'] === $projektId) {
                $kontoId = (int)$k['id'];
                $kontoNr = (string)($k['iban'] ?? '');
                break;
            }
        }
    } else {
        foreach ($kontenList as $k) {
            if ((int)$k['id'] === $kontoId) {
                $kontoNr = (string)($k['iban'] ?? '');
                break;
            }
        }
    }

    if ($projektId <= 0 && empty($projekte)) {
        $msg = "❌ Fehler: Bitte wähle eine Liegenschaft aus.";
        $msgType = "danger";
    } elseif (empty($fileTmp) || !is_uploaded_file($fileTmp)) {
        $msg = "❌ Fehler beim Hochladen der Datei.";
        $msgType = "danger";
    } else {
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

        if (empty($dataPreview)) {
            $msg = "❌ Keine gültigen Buchungszeilen in der CSV-Datei gefunden.";
            $msgType = "danger";
        } else {
            $_SESSION['csv_preview']  = $dataPreview;
            $_SESSION['csv_filename'] = $fileName;
            $_SESSION['csv_projekt']  = $projektId;
            $_SESSION['csv_konto_id'] = $kontoId;
            $_SESSION['csv_konto_nr'] = $kontoNr;
        }
    }
}

// 4. Import definitiv in Datenbank speichern
$importResultYears = [];
if (isset($_POST['confirm_import']) && isset($_SESSION['csv_preview'])) {
    $fileName    = $_SESSION['csv_filename'];
    $previewData = $_SESSION['csv_preview'];
    $userId      = (int)($_SESSION['user_id'] ?? 0);
    $projektId   = (int)($_SESSION['csv_projekt'] ?? 0);
    $kontoId     = (int)($_SESSION['csv_konto_id'] ?? 0);
    $kontoNr     = trim($_SESSION['csv_konto_nr'] ?? '');

    $kategorien  = $_POST['kategorie'] ?? [];
    $assignments = $_POST['assigned_entity'] ?? [];

    $sql = "INSERT INTO liegenschafts_konto
            (konto_id, konto_nr, liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, kategorie, zahlungsart,
             mieter_id, wohnung_id, wohnung_label, import_dateiname, import_benutzer_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);

    $savedCount = 0;
    $assignedCount = 0;
    $detectedYears = [];

    foreach ($previewData as $idx => $row) {
        $buchungsdatum = parseSwissDate($row[1] ?? $row[0] ?? '');
        if ($buchungsdatum === null) continue;

        $y = (int)substr($buchungsdatum, 0, 4);
        if ($y > 0) $detectedYears[$y] = ($detectedYears[$y] ?? 0) + 1;

        $betrag = parseSwissAmount($row[3] ?? $row[2] ?? '0');
        $beschreibung = trim((string)($row[2] ?? $row[1] ?? ''));

        $kategorie = trim((string)($kategorien[$idx] ?? ''));
        $assignVal = trim((string)($assignments[$idx] ?? ''));

        $mieter_id = null;
        $wohnung_id = null;
        $wohnung_label = null;

        if ($assignVal !== '') {
            $parts = explode(':', $assignVal);
            // Format: m:benutzer_id:wohnung_id:label oder w:0:wohnung_id:label
            if (count($parts) >= 3) {
                $mId = (int)$parts[1];
                $wId = (int)$parts[2];
                $wLbl = isset($parts[3]) ? rawurldecode($parts[3]) : null;

                if ($mId > 0) {
                    // Validieren, ob benutzer_id in benutzer existiert (FK-Sicherheit)
                    $chkU = $mysqli->query("SELECT id FROM benutzer WHERE id = $mId");
                    if ($chkU && $chkU->num_rows > 0) {
                        $mieter_id = $mId;
                    }
                }
                if ($wId > 0) $wohnung_id = $wId;
                if (!empty($wLbl)) $wohnung_label = $wLbl;

                $assignedCount++;
                if (empty($kategorie) && $betrag > 0) $kategorie = 'Miete';
            }
        }

        $zahlungsart = 'Überweisung';
        $kIdVal = $kontoId > 0 ? $kontoId : null;

        $stmt->bind_param("isiisdsssiiisi",
            $kIdVal, $kontoNr, $projektId, $projektId, $buchungsdatum, $betrag, $beschreibung,
            $kategorie, $zahlungsart, $mieter_id, $wohnung_id, $wohnung_label,
            $fileName, $userId
        );
        $stmt->execute();
        $savedCount++;
    }
    $stmt->close();

    $importResultYears = array_keys($detectedYears);
    rsort($importResultYears);

    unset($_SESSION['csv_preview'], $_SESSION['csv_filename'], $_SESSION['csv_projekt'], $_SESSION['csv_konto_id'], $_SESSION['csv_konto_nr']);

    $yearSummaryText = !empty($importResultYears) ? "über die Jahre " . implode(', ', $importResultYears) : "";
    $msg = "✅ $savedCount Buchungen $yearSummaryText erfolgreich importiert ($assignedCount Einheiten/Mieter zugeordnet).";
    $msgType = "success";
}

// 5. Verwerfen
if (isset($_POST['cancel_import'])) {
    unset($_SESSION['csv_preview'], $_SESSION['csv_filename'], $_SESSION['csv_projekt'], $_SESSION['csv_konto_id'], $_SESSION['csv_konto_nr']);
    $msg = "ℹ️ Import verworfen – keine Daten gespeichert.";
    $msgType = "info";
}

// Wenn Vorschau aktiv: Mieter & Wohnungen für die gewählte Liegenschaft laden
$tenantsList = [];
$unitsList = [];
$activeProjName = "Unbekannte Liegenschaft";
$activeKontoName = "Standard-Konto";
$activeIban = "";
$activeBank = "";

if (isset($_SESSION['csv_preview'])) {
    $selPid = (int)($_SESSION['csv_projekt'] ?? 0);
    $selKid = (int)($_SESSION['csv_konto_id'] ?? 0);

    foreach ($projekte as $p) {
        if ((int)$p['id'] === $selPid) {
            $activeProjName = $p['name'];
            break;
        }
    }
    foreach ($kontenList as $k) {
        if ((int)$k['id'] === $selKid) {
            $activeKontoName = $k['name'];
            $activeIban = $k['iban'] ?? '';
            $activeBank = $k['bank'] ?? '';
            break;
        }
    }

    $resT = $mysqli->query("
        SELECT wm.id as wm_id, wm.benutzer_id, wm.mieter_name, wm.wohnung_id, w.name as wohnung_name, o.name as objekt_name
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

<div class="konto-container" style="max-width:1420px; margin:24px auto; padding:24px; background:#fff; border-radius:14px; box-shadow:0 4px 20px rgba(0,0,0,0.06); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="margin:0 0 6px 0; font-size:22px; color:#0f172a;">📥 Bank-CSV Import &amp; Mieter-Abgleich</h2>
      <div style="font-size:14px; color:#64748b;">
        Dedicated Bankkonto je Liegenschaft &bull; Mehrjährige Auszüge &bull; Automatische Jahres-Zuordnung
      </div>
    </div>
    <div style="display:flex; gap:10px;">
      <a href="index.php" class="btn" style="padding:9px 16px; text-decoration:none; background:#f1f5f9; border-radius:8px; color:#334155; font-weight:600; font-size:13px;">⬅️ Zurück zum Konto</a>
      <a href="../mietkontrolle/index.php" class="btn primary" style="padding:9px 16px; text-decoration:none; background:#10b981; color:#fff; border-radius:8px; font-weight:600; font-size:13px;">📊 Zur Mietkontrolle</a>
    </div>
  </div>

  <?php if (!empty($msg)): ?>
    <div style="padding:16px; border-radius:10px; margin-bottom:24px; font-weight:600; display:flex; justify-content:space-between; align-items:center; <?= $msgType === 'danger' ? 'background:#fef2f2; border:1px solid #fecaca; color:#991b1b;' : ($msgType === 'info' ? 'background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1;' : 'background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;') ?>">
      <div><?= htmlspecialchars($msg) ?></div>
      <?php if (!empty($importResultYears)): ?>
        <div style="display:flex; gap:6px;">
          <?php foreach ($importResultYears as $yr): ?>
            <a href="index.php?jahr=<?= $yr ?>" style="padding:6px 12px; background:#16a34a; color:#fff; text-decoration:none; border-radius:6px; font-size:12px; font-weight:700;">
              Zum Konto <?= $yr ?> ➔
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!isset($_SESSION['csv_preview'])): ?>
    <!-- Schritt 1: Datei & Liegenschaft / Konto auswählen -->
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:24px; margin-bottom:24px;">
      <h3 style="margin:0 0 16px 0; font-size:17px; color:#1e293b;">1. Liegenschaft, Bankkonto und CSV-Datei wählen</h3>

      <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 16px; margin-bottom:20px; font-size:13px; color:#1e40af; line-height:1.6;">
        <strong>💡 Pro Liegenschaft ein eigenes Bankkonto:</strong><br>
        Jede Liegenschaft wird in der Buchhaltung über ihr eigenes Bankkonto geführt. Wähle unten die Liegenschaft aus – das zugehörige Bankkonto wird automatisch selektiert.<br>
        <strong>📅 Mehrjährige Auszüge:</strong> Wenn dein Bankauszug mehrere Jahre umfasst (z.B. von 2022 bis 2024), werden alle Buchungen mit ihrem jeweiligen Buchungsdatum importiert und können danach in der Konto-Verwaltung sowie der Mietkontrolle <strong>sauber pro Jahr</strong> angezeigt und bearbeitet werden.
      </div>

      <form method="post" enctype="multipart/form-data" action="" style="display:grid; gap:18px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px;">
          <div>
            <label for="sel_projekt" style="font-weight:700; display:block; margin-bottom:6px; font-size:14px; color:#334155;">
              🏢 1. Liegenschaft / Projekt auswählen:
            </label>
            <select name="projekt_id" id="sel_projekt" required style="padding:10px 12px; width:100%; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
              <option value="">-- Bitte Liegenschaft wählen --</option>
              <?php foreach ($projekte as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="sel_konto" style="font-weight:700; display:block; margin-bottom:6px; font-size:14px; color:#334155;">
              💳 2. Zugeordnetes Bankkonto (IBAN):
            </label>
            <select name="konto_id" id="sel_konto" style="padding:10px 12px; width:100%; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
              <option value="">-- Automatisch via Liegenschaft --</option>
              <?php foreach ($kontenList as $k): ?>
                <option value="<?= $k['id'] ?>" data-projekt="<?= $k['projekt_id'] ?>" data-iban="<?= htmlspecialchars($k['iban'] ?? '') ?>">
                  <?= htmlspecialchars($k['name']) ?> (<?= htmlspecialchars($k['bank'] ?: 'Bank') ?> – <?= htmlspecialchars($k['iban'] ?: 'Keine IBAN') ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div>
          <label style="font-weight:700; display:block; margin-bottom:6px; font-size:14px; color:#334155;">
            📄 3. Bank-CSV-Kontoauszug auswählen (Raiffeisen, PostFinance, UBS, ZKB, etc.):
          </label>
          <input type="file" name="csv_file" id="csv_file_input" accept=".csv,.txt" required style="padding:12px; border:2px dashed #94a3b8; border-radius:8px; width:100%; box-sizing:border-box; background:#fff; cursor:pointer;">
          <div style="font-size:12px; color:#64748b; margin-top:4px;">
            Enthält der Dateiname die IBAN (z.B. <code>CH0680808001916350814...csv</code>), erkennt das System Liegenschaft und Konto sofort automatisch.
          </div>
        </div>

        <div style="margin-top:8px;">
          <button type="submit" style="padding:12px 24px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
            📂 CSV laden &amp; Mieter-Abgleich starten ➔
          </button>
        </div>
      </form>
    </div>

    <script>
      const selProj = document.getElementById('sel_projekt');
      const selKonto = document.getElementById('sel_konto');
      const fileInput = document.getElementById('csv_file_input');

      // Auto-Sync Liegenschaft -> Bankkonto
      selProj.addEventListener('change', function() {
        const pId = this.value;
        if (!pId) return;
        for (let i = 0; i < selKonto.options.length; i++) {
          const opt = selKonto.options[i];
          if (opt.getAttribute('data-projekt') === pId) {
            selKonto.selectedIndex = i;
            break;
          }
        }
      });

      // Auto-Sync Bankkonto -> Liegenschaft
      selKonto.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const pId = opt.getAttribute('data-projekt');
        if (pId) {
          selProj.value = pId;
        }
      });

      // Dateinamen auf IBAN prüfen beim Auswählen
      fileInput.addEventListener('change', function() {
        const fname = this.files && this.files[0] ? this.files[0].name : '';
        const match = fname.match(/CH[0-9]{19}/i);
        if (match) {
          const cleanIban = match[0].toUpperCase();
          for (let i = 0; i < selKonto.options.length; i++) {
            const opt = selKonto.options[i];
            const optIban = (opt.getAttribute('data-iban') || '').replace(/\s+/g, '').toUpperCase();
            if (optIban === cleanIban) {
              selKonto.selectedIndex = i;
              const pId = opt.getAttribute('data-projekt');
              if (pId) selProj.value = pId;
              break;
            }
          }
        }
      });
    </script>

  <?php else: ?>
    <!-- Schritt 2: Vorschau & Intelligenter Jahres- und Mieter-Abgleich -->
    <?php
      // 1. Jahres-Analyse der Vorschau-Daten
      $previewByYear = [];
      $totalIn = 0.0;
      $totalOut = 0.0;
      foreach ($_SESSION['csv_preview'] as $idx => $row) {
          $bDatum = parseSwissDate($row[1] ?? $row[0] ?? '');
          $bBetrag = parseSwissAmount($row[3] ?? $row[2] ?? '0');
          $bYear = $bDatum ? (int)substr($bDatum, 0, 4) : 0;
          if (!isset($previewByYear[$bYear])) {
              $previewByYear[$bYear] = ['count' => 0, 'in' => 0.0, 'out' => 0.0];
          }
          $previewByYear[$bYear]['count']++;
          if ($bBetrag > 0) {
              $previewByYear[$bYear]['in'] += $bBetrag;
              $totalIn += $bBetrag;
          } else {
              $previewByYear[$bYear]['out'] += $bBetrag;
              $totalOut += $bBetrag;
          }
      }
      krsort($previewByYear);
    ?>

    <form method="post">
      <!-- Kontext Banner -->
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:5px solid #2563eb; border-radius:10px; padding:16px 20px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
          <div style="font-size:12px; font-weight:700; color:#2563eb; text-transform:uppercase;">Ausgewählte Liegenschaft &amp; Bankkonto:</div>
          <div style="font-size:17px; font-weight:800; color:#0f172a; margin-top:2px;">
            🏠 <?= htmlspecialchars($activeProjName) ?>
          </div>
          <div style="font-size:13px; color:#475569; margin-top:4px;">
            💳 <strong><?= htmlspecialchars($activeKontoName) ?></strong> 
            <?= !empty($activeBank) ? '(' . htmlspecialchars($activeBank) . ')' : '' ?> &bull; 
            IBAN: <span style="font-family:monospace; font-weight:700; color:#0f172a;"><?= htmlspecialchars($activeIban ? chunk_split($activeIban, 4, ' ') : '—') ?></span>
          </div>
          <div style="font-size:12px; color:#64748b; margin-top:2px;">
            📄 Quelldatei: <strong><?= htmlspecialchars($_SESSION['csv_filename']) ?></strong> (<?= count($_SESSION['csv_preview']) ?> Buchungszeilen)
          </div>
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
          <button type="submit" name="confirm_import" style="padding:11px 24px; background:#10b981; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 2px 6px rgba(16,185,129,0.3);">
            ✅ Alle <?= count($_SESSION['csv_preview']) ?> Buchungen importieren
          </button>
          <button type="submit" name="cancel_import" style="padding:11px 16px; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:8px; font-weight:600; cursor:pointer;">
            ❌ Abbrechen
          </button>
        </div>
      </div>

      <!-- Jahres-Aufschlüsselung Kacheln -->
      <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,0.03);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
          <div>
            <h4 style="margin:0; font-size:15px; color:#0f172a; display:flex; align-items:center; gap:6px;">
              📅 Erkannte Buchungsjahre im Auszug:
            </h4>
            <div style="font-size:12px; color:#64748b; margin-top:2px;">
              Buchungen werden exakt mit ihrem Buchungsdatum gespeichert und stehen danach in der Jahresansicht pro Jahr getrennt bereit.
            </div>
          </div>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button type="button" onclick="filterPreviewYear('all')" id="pill_all" class="yr-pill active" style="padding:6px 14px; border-radius:6px; font-size:12px; font-weight:700; border:none; cursor:pointer; background:#2563eb; color:#fff;">
              🌐 Alle Jahre (<?= count($_SESSION['csv_preview']) ?>)
            </button>
            <?php foreach ($previewByYear as $yr => $data): ?>
              <button type="button" onclick="filterPreviewYear('<?= $yr ?>')" id="pill_<?= $yr ?>" class="yr-pill" style="padding:6px 14px; border-radius:6px; font-size:12px; font-weight:700; border:1px solid #cbd5e1; cursor:pointer; background:#f8fafc; color:#334155;">
                📅 Jahr <?= $yr ?: 'Ohne Datum' ?> (<?= $data['count'] ?>)
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
          <?php foreach ($previewByYear as $yr => $data): ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px;">
              <div style="font-size:12px; font-weight:700; color:#475569;">Jahr <?= $yr ?: 'Unbekannt' ?>:</div>
              <div style="font-size:18px; font-weight:800; color:#0f172a; margin:2px 0;">
                <?= $data['count'] ?> <span style="font-size:12px; font-weight:500; color:#64748b;">Buchungen</span>
              </div>
              <div style="font-size:12px; display:flex; justify-content:space-between; color:#16a34a; font-weight:600;">
                <span>Eingänge:</span>
                <span>+CHF <?= number_format($data['in'], 2, '.', "'") ?></span>
              </div>
              <div style="font-size:12px; display:flex; justify-content:space-between; color:#dc2626; font-weight:600;">
                <span>Ausgänge:</span>
                <span>CHF <?= number_format($data['out'], 2, '.', "'") ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Vorschau Tabelle -->
      <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
        <thead>
          <tr style="background:#0f172a; color:#fff; text-align:left;">
            <th style="padding:12px 14px; width:120px;">Jahr / Datum</th>
            <th style="padding:12px 14px; text-align:right; width:130px;">Betrag (CHF)</th>
            <th style="padding:12px 14px;">Beschreibung / Buchungstext</th>
            <th style="padding:12px 14px; min-width:320px;">Mieter &amp; Einheiten Zuweisung</th>
            <th style="padding:12px 14px; width:140px;">Kategorie</th>
          </tr>
        </thead>
        <tbody>
        <?php 
        $matchCount = 0;
        foreach ($_SESSION['csv_preview'] as $idx => $row): 
            $buchungsdatum = parseSwissDate($row[1] ?? $row[0] ?? '');
            $betrag = parseSwissAmount($row[3] ?? $row[2] ?? '0');
            $beschreibung = trim((string)($row[2] ?? $row[1] ?? ''));
            $rowYear = $buchungsdatum ? (int)substr($buchungsdatum, 0, 4) : 0;

            // Intelligentes Matching nach Mieternamen
            $matchedVal = '';
            $autoKat = ($betrag > 0) ? 'Miete' : 'Aufwand';

            if ($betrag > 0 && !empty($tenantsList)) {
                foreach ($tenantsList as $t) {
                    $mWords = preg_split('/[\s,\/&]+/', $t['mieter_name']);
                    foreach ($mWords as $mw) {
                        $mw = trim($mw);
                        if (mb_strlen($mw) >= 4 && stripos($beschreibung, $mw) !== false) {
                            $mBId = !empty($t['benutzer_id']) ? (int)$t['benutzer_id'] : 0;
                            $matchedVal = 'm:' . $mBId . ':' . (int)$t['wohnung_id'] . ':' . rawurlencode($t['wohnung_name']);
                            $matchCount++;
                            break 2;
                        }
                    }
                }
            }
        ?>
          <tr class="preview-row" data-year="<?= $rowYear ?>" style="border-bottom:1px solid #e2e8f0; <?= !empty($matchedVal) ? 'background:#ecfdf5;' : '' ?>">
            <td style="padding:10px 14px; white-space:nowrap;">
              <span style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:700; font-size:11px; margin-right:4px;">
                <?= $rowYear ?: '—' ?>
              </span>
              <?= htmlspecialchars($buchungsdatum ? date('d.m.Y', strtotime($buchungsdatum)) : '—') ?>
            </td>
            <td style="padding:10px 14px; text-align:right; font-weight:700; color:<?= $betrag >= 0 ? '#16a34a' : '#dc2626' ?>;">
              <?= number_format($betrag, 2, '.', "'") ?>
            </td>
            <td style="padding:10px 14px; max-width:380px; word-break:break-word; color:#1e293b;">
              <?= htmlspecialchars($beschreibung) ?>
            </td>
            <td style="padding:10px 14px;">
              <select name="assigned_entity[<?= $idx ?>]" style="width:100%; padding:7px; border-radius:6px; border:1px solid <?= !empty($matchedVal) ? '#10b981' : '#cbd5e1' ?>; background:#fff; font-size:13px;">
                <option value="">-- Keine Zuweisung --</option>
                <?php if (!empty($tenantsList)): ?>
                  <optgroup label="Mieter (Automatische Mietkontrolle)">
                    <?php foreach ($tenantsList as $t): 
                        $mBId = !empty($t['benutzer_id']) ? (int)$t['benutzer_id'] : 0;
                        $val = 'm:' . $mBId . ':' . (int)$t['wohnung_id'] . ':' . rawurlencode($t['wohnung_name']);
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
                        $val = 'w:0:' . (int)$u['wohnung_id'] . ':' . rawurlencode($u['wohnung_name']);
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
            <td style="padding:10px 14px;">
              <input type="text" name="kategorie[<?= $idx ?>]" value="<?= htmlspecialchars(!empty($matchedVal) ? 'Miete' : $autoKat) ?>" style="padding:7px; width:100%; box-sizing:border-box; border-radius:6px; border:1px solid #cbd5e1; font-size:13px;">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Bottom Action Bar -->
      <div style="padding:16px 20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="font-size:14px; color:#334155;">
          <strong>Automatische Mieter-Matches:</strong> <span style="color:#16a34a; font-weight:700;"><?= $matchCount ?></span> von <?= count($_SESSION['csv_preview']) ?> Zeilen erkannt
        </div>
        <div style="display:flex; gap:10px;">
          <button type="submit" name="confirm_import" style="padding:11px 26px; background:#10b981; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer;">
            ✅ Buchungen jetzt speichern
          </button>
          <button type="submit" name="cancel_import" style="padding:11px 18px; background:#ef4444; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
            ❌ Verwerfen
          </button>
        </div>
      </div>
    </form>

    <script>
      function filterPreviewYear(yr) {
        const rows = document.querySelectorAll('.preview-row');
        rows.forEach(r => {
          if (yr === 'all' || r.getAttribute('data-year') === yr) {
            r.style.display = '';
          } else {
            r.style.display = 'none';
          }
        });

        // Pill-Buttons stylen
        document.querySelectorAll('.yr-pill').forEach(btn => {
          btn.style.background = '#f8fafc';
          btn.style.color = '#334155';
          btn.style.border = '1px solid #cbd5e1';
        });
        const activeBtn = document.getElementById('pill_' + yr);
        if (activeBtn) {
          activeBtn.style.background = '#2563eb';
          activeBtn.style.color = '#fff';
          activeBtn.style.border = 'none';
        }
      }
    </script>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
