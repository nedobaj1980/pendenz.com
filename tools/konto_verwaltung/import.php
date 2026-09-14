<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('PENDENZ_SESSID');
    session_start();
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fs.php';

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

/**
 * Löst den Bankordner einer Liegenschaft auf (Google Drive bevorzugt)
 */
function get_project_bank_folder(mysqli $db, int $projectId): ?string {
    if ($projectId <= 0) return null;
    $root = project_root_path($db, $projectId);
    if (!$root || !is_dir($root)) return null;

    // Suche in Unterordnern (z.B. 01_MFH... / 06_Bank_Liegenschaftskonto)
    $subDirs = glob($root . DIRECTORY_SEPARATOR . '01_*', GLOB_ONLYDIR);
    if (!empty($subDirs)) {
        foreach ($subDirs as $sd) {
            $candidate = $sd . DIRECTORY_SEPARATOR . '06_Bank_Liegenschaftskonto';
            if (is_dir($candidate)) return $candidate;
            $bankSubs = glob($sd . DIRECTORY_SEPARATOR . '*Bank*', GLOB_ONLYDIR);
            if (!empty($bankSubs) && is_dir($bankSubs[0])) return $bankSubs[0];
        }
        $candidate = $subDirs[0] . DIRECTORY_SEPARATOR . '06_Bank_Liegenschaftskonto';
        @mkdir($candidate, 0777, true);
        if (is_dir($candidate)) return $candidate;
    }

    $candidate = $root . DIRECTORY_SEPARATOR . '06_Bank_Liegenschaftskonto';
    if (is_dir($candidate)) return $candidate;
    @mkdir($candidate, 0777, true);
    if (is_dir($candidate)) return $candidate;

    return $root;
}

function parseSwissDate($raw) {
    $raw = trim((string)$raw);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) {
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

$standardKategorien = [
    'Mietzinseinnahmen (Miete)',
    'Heiz- & Nebenkosten (Akonto / Pauschalen)',
    'Liegenschaftsunterhalt (Reparaturen)',
    'Liegenschaftsunterhalt (Service-Abonnemente)',
    'Liegenschaftsunterhalt (Material & Kleinreparaturen)',
    'Betriebskosten (Strom / EW)',
    'Betriebskosten (Wasser / Kehricht)',
    'Betriebskosten (Hauswartung)',
    'Betriebskosten (Abgaben & Kehricht)',
    'Versicherungen (Gebäudeversicherung)',
    'Steuern & Abgaben (Liegenschafts- & Gemeindesteuern)',
    'Hypothekarzinsen',
    'Eigentümer-Entnahmen / Überträge',
    'Verwaltungsaufwand & Bankspesen',
    'Mietkautionen / Rückzahlungen',
    'Andere / Undeklariert'
];

$msg = "";
$msgType = "success";
$savedFilePathDisplay = "";

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

// Aktives Projekt aus Session vorbelegen
$defaultProjId = (int)($_SESSION['current_project_id'] ?? 1);

// 3. Upload verarbeiten -> Vorschau
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $fileTmp   = $_FILES['csv_file']['tmp_name'] ?? '';
    $fileName  = $_FILES['csv_file']['name'] ?? '';
    $projektId = (int)($_POST['projekt_id'] ?? 0);
    $kontoId   = (int)($_POST['konto_id'] ?? 0);

    if (empty($fileTmp) || !is_uploaded_file($fileTmp)) {
        $msg = "❌ Fehler beim Hochladen der Datei.";
        $msgType = "danger";
    } else {
        // Inhalt lesen & Encoding sicherstellen (UTF-8, Windows-1252 / ISO-8859-1)
        $rawBytes = file_get_contents($fileTmp);
        if (!mb_check_encoding($rawBytes, 'UTF-8')) {
            $rawBytes = mb_convert_encoding($rawBytes, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        }

        // Zeilen trennen
        $lines = preg_split('/\r\n|\r|\n/', $rawBytes);

        // Delimiter ermitteln
        $delim = ';';
        foreach ($lines as $l) {
            if (trim($l) === '') continue;
            if (substr_count($l, "\t") > substr_count($l, ";")) $delim = "\t";
            elseif (substr_count($l, ",") > substr_count($l, ";")) $delim = ",";
            break;
        }

        $parsedTransactions = [];
        $detectedIban = '';
        $lastIdx = -1;

        // Dateinamen auf IBAN prüfen (z.B. CH0680808001916350814)
        if (preg_match('/CH[0-9]{19}/i', $fileName, $mIban)) {
            $detectedIban = strtoupper($mIban[0]);
        }

        $kontenByCleanIban = [];
        foreach ($kontenList as $k) {
            $clean = str_replace(' ', '', strtoupper($k['iban'] ?? ''));
            if ($clean !== '') {
                $kontenByCleanIban[$clean] = $k;
            }
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cols = str_getcsv($line, $delim);
            if (empty($cols)) continue;

            $c0 = trim((string)($cols[0] ?? ''));
            $c1 = trim((string)($cols[1] ?? ''));
            $c2 = trim((string)($cols[2] ?? ''));
            $c3 = trim((string)($cols[3] ?? ''));
            $c4 = trim((string)($cols[4] ?? ''));
            $c5 = trim((string)($cols[5] ?? ''));

            // Header-Zeile erkennen und überspringen
            if (stripos($c0, 'iban') !== false || stripos($c1, 'booked') !== false || stripos($c0, 'datum') !== false || stripos($c1, 'datum') !== false) {
                continue;
            }

            // Sub-Zeile (Detailzeile): Spalte 0 leer, Spalte 1 leer, Spalte 2 enthält Detailtext
            if ($c0 === '' && $c1 === '' && $c2 !== '') {
                if ($lastIdx >= 0) {
                    $parsedTransactions[$lastIdx]['detail_lines'][] = $c2;
                    $parsedTransactions[$lastIdx]['beschreibung'] .= ' | ' . $c2;
                }
                continue;
            }

            // Spalte A (IBAN) prüfen und Liegenschaft verifizieren
            $rowIban = trim($c0);
            $cleanRowIban = str_replace(' ', '', strtoupper($rowIban));
            $rowKontoId = 0;
            $rowProjektId = 0;
            $rowProjektName = '';
            $rowKontoName = '';
            $rowIbanStatus = 'unbekannt';

            if (preg_match('/CH[0-9]{19}/i', $cleanRowIban, $mIb)) {
                $matchedIban = strtoupper($mIb[0]);
                if (empty($detectedIban)) $detectedIban = $matchedIban;

                if (isset($kontenByCleanIban[$matchedIban])) {
                    $matchedKonto = $kontenByCleanIban[$matchedIban];
                    $rowKontoId = (int)$matchedKonto['id'];
                    $rowProjektId = (int)$matchedKonto['projekt_id'];
                    $rowProjektName = $matchedKonto['projekt_name'] ?? '';
                    $rowKontoName = $matchedKonto['name'] ?? '';
                    $rowIbanStatus = 'verifiziert';
                } else {
                    $rowIbanStatus = 'nicht_zugeordnet';
                }
            }

            $bDatum = parseSwissDate($c1 !== '' ? $c1 : $c0);
            if ($bDatum === null) {
                // Keine gültige Buchungszeile
                continue;
            }

            $betrag = parseSwissAmount($c3 !== '' ? $c3 : ($c2 !== '' ? $c2 : '0'));
            $mainText = $c2 !== '' ? $c2 : ($c1 !== '' ? $c1 : '');

            $lastIdx++;
            $parsedTransactions[$lastIdx] = [
                'iban' => $c0,
                'iban_clean' => $cleanRowIban,
                'iban_status' => $rowIbanStatus,
                'row_konto_id' => $rowKontoId,
                'row_projekt_id' => $rowProjektId,
                'row_projekt_name' => $rowProjektName,
                'row_konto_name' => $rowKontoName,
                'raw_date' => $c1,
                'datum' => $bDatum,
                'main_text' => $mainText,
                'beschreibung' => $mainText,
                'detail_lines' => [],
                'betrag' => $betrag,
                'balance' => $c4,
                'valuta' => $c5,
                'kategorie' => '',
                'assigned_entity' => ''
            ];
        }

        if (empty($parsedTransactions)) {
            $msg = "❌ Keine gültigen Buchungszeilen in der CSV-Datei gefunden.";
            $msgType = "danger";
        } else {
            // Automatische Zuordnung anhand erkannter IBAN
            if (!empty($detectedIban)) {
                foreach ($kontenList as $k) {
                    $cleanKontoIban = str_replace(' ', '', strtoupper($k['iban'] ?? ''));
                    if ($cleanKontoIban === $detectedIban) {
                        $kontoId = (int)$k['id'];
                        $projektId = (int)$k['projekt_id'];
                        $kontoNr = $k['iban'];
                        break;
                    }
                }
            }

            // Fallback: Konto -> Projekt
            if ($kontoId > 0 && $projektId <= 0) {
                foreach ($kontenList as $k) {
                    if ((int)$k['id'] === $kontoId && !empty($k['projekt_id'])) {
                        $projektId = (int)$k['projekt_id'];
                        break;
                    }
                }
            }

            // Fallback: Projekt -> Konto
            $kontoNr = '';
            if ($kontoId <= 0 && $projektId > 0) {
                foreach ($kontenList as $k) {
                    if ((int)$k['projekt_id'] === $projektId) {
                        $kontoId = (int)$k['id'];
                        $kontoNr = (string)($k['iban'] ?? '');
                        break;
                    }
                }
            } elseif ($kontoId > 0) {
                foreach ($kontenList as $k) {
                    if ((int)$k['id'] === $kontoId) {
                        $kontoNr = (string)($k['iban'] ?? '');
                        break;
                    }
                }
            }

            // DATEI IM PROJEKTORDNER ABLEGEN
            $savedPath = null;
            $savedFolderDisplay = null;
            $targetFileName = $fileName;

            // Lokales Backup-Verzeichnis
            $localDir = __DIR__ . '/../../uploads/bank_csv';
            if (!is_dir($localDir)) @mkdir($localDir, 0777, true);
            $localPath = $localDir . DIRECTORY_SEPARATOR . time() . '_' . $fileName;
            @file_put_contents($localPath, $rawBytes);

            // Projektordner auf Google Drive / Dateisystem auflösen
            if ($projektId > 0) {
                $projBankDir = get_project_bank_folder($mysqli, $projektId);
                if ($projBankDir && is_dir($projBankDir)) {
                    $destPath = $projBankDir . DIRECTORY_SEPARATOR . $fileName;
                    if (file_exists($destPath)) {
                        $pi = pathinfo($fileName);
                        $destPath = $projBankDir . DIRECTORY_SEPARATOR . $pi['filename'] . '_' . date('Ymd_His') . '.' . ($pi['extension'] ?? 'csv');
                    }
                    if (@file_put_contents($destPath, $rawBytes)) {
                        $savedPath = $destPath;
                        $savedFolderDisplay = $destPath;
                    }
                }
            }

            if (!$savedPath) {
                $savedPath = $localPath;
                $savedFolderDisplay = $localPath;
            }

            $_SESSION['csv_preview']     = $parsedTransactions;
            $_SESSION['csv_filename']    = $fileName;
            $_SESSION['csv_saved_path']  = $savedPath;
            $_SESSION['csv_folder_disp'] = $savedFolderDisplay;
            $_SESSION['csv_projekt']     = $projektId;
            $_SESSION['csv_konto_id']    = $kontoId;
            $_SESSION['csv_konto_nr']    = $kontoNr;
        }
    }
}

// 4. Import definitiv in Datenbank speichern
$importResultYears = [];
if (isset($_POST['confirm_import']) && isset($_SESSION['csv_preview'])) {
    $fileName    = $_SESSION['csv_filename'] ?? 'kontoauszug.csv';
    $savedPath   = $_SESSION['csv_saved_path'] ?? '';
    $previewData = $_SESSION['csv_preview'];
    $userId      = (int)($_SESSION['user_id'] ?? 0);
    $projektId   = (int)($_SESSION['csv_projekt'] ?? 0);
    $kontoId     = (int)($_SESSION['csv_konto_id'] ?? 0);
    $kontoNr     = trim($_SESSION['csv_konto_nr'] ?? '');

    $kategorien   = $_POST['kategorie'] ?? [];
    $assignments  = $_POST['assigned_entity'] ?? [];
    $rememberOpts = $_POST['remember_rule'] ?? [];

    $sql = "INSERT INTO liegenschafts_konto
            (konto_id, konto_nr, liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, kategorie, zahlungsart,
             mieter_id, wohnung_id, wohnung_label, import_dateiname, import_dateipfad, import_benutzer_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);

    $savedCount = 0;
    $assignedCount = 0;
    $detectedYears = [];
    $rulesLearned = 0;

    foreach ($previewData as $idx => $row) {
        $buchungsdatum = $row['datum'] ?? null;
        if ($buchungsdatum === null) continue;

        $y = (int)substr($buchungsdatum, 0, 4);
        if ($y > 0) $detectedYears[$y] = ($detectedYears[$y] ?? 0) + 1;

        $betrag = (float)($row['betrag'] ?? 0);
        $beschreibung = (string)($row['beschreibung'] ?? '');

        $kategorie = trim((string)($kategorien[$idx] ?? $row['kategorie'] ?? ''));
        $assignVal = trim((string)($assignments[$idx] ?? $row['assigned_entity'] ?? ''));

        $mieter_id = null;
        $wohnung_id = null;
        $wohnung_label = null;

        if ($assignVal !== '') {
            $parts = explode(':', $assignVal);
            if (count($parts) >= 3) {
                $mId = (int)$parts[1];
                $wId = (int)$parts[2];
                $wLbl = isset($parts[3]) ? rawurldecode($parts[3]) : null;

                if ($mId > 0) {
                    $chkU = $mysqli->query("SELECT id FROM benutzer WHERE id = $mId");
                    if ($chkU && $chkU->num_rows > 0) {
                        $mieter_id = $mId;
                    }
                }
                if ($wId > 0) $wohnung_id = $wId;
                if (!empty($wLbl)) $wohnung_label = $wLbl;

                $assignedCount++;
                if (empty($kategorie) && $betrag > 0) $kategorie = 'Mietzinseinnahmen (Miete)';
            }
        }

        $validUserId = null;
        if ($userId > 0) {
            $chkU = $mysqli->query("SELECT id FROM benutzer WHERE id = $userId");
            if ($chkU && $chkU->num_rows > 0) {
                $validUserId = $userId;
            }
        }

        // Spalte A (IBAN) bestimmt die exakte Liegenschaft und das Konto
        $targetPid = !empty($row['row_projekt_id']) ? (int)$row['row_projekt_id'] : $projektId;
        $targetKid = !empty($row['row_konto_id']) ? (int)$row['row_konto_id'] : ($kontoId > 0 ? $kontoId : null);
        $targetKontoNr = !empty($row['iban_clean']) ? $row['iban_clean'] : $kontoNr;

        $stmt->bind_param("isiisdsssiiissi",
            $targetKid, $targetKontoNr, $targetPid, $targetPid, $buchungsdatum, $betrag, $beschreibung,
            $kategorie, $zahlungsart, $mieter_id, $wohnung_id, $wohnung_label,
            $fileName, $savedPath, $validUserId
        );
        $stmt->execute();
        $savedCount++;

        // Dauer-Regel merken, wenn angehakt
        if (!empty($rememberOpts[$idx])) {
            $cleanPatt = trim(preg_replace('/^(Gutschrift|Zahlung|Übertrag|Dauerauftrag|Einkauf)\s+/i', '', $row['main_text'] ?? $beschreibung));
            if (mb_strlen($cleanPatt) >= 3) {
                $chkRule = $mysqli->prepare("SELECT id FROM konto_rules WHERE pattern = ? AND (liegenschaft_id = ? OR liegenschaft_id IS NULL)");
                $chkRule->bind_param("si", $cleanPatt, $targetPid);
                $chkRule->execute();
                if ($chkRule->get_result()->num_rows === 0) {
                    $insR = $mysqli->prepare("INSERT INTO konto_rules (pattern, set_kategorie, liegenschaft_id, wohnung_id, mieter_id, wohnung_label, aktiv, priority) VALUES (?, ?, ?, ?, ?, ?, 1, 15)");
                    $insR->bind_param("ssiiis", $cleanPatt, $kategorie, $targetPid, $wohnung_id, $mieter_id, $wohnung_label);
                    $insR->execute();
                    $insR->close();
                    $rulesLearned++;
                }
                $chkRule->close();
            }
        }
    }
    $stmt->close();

    $importResultYears = array_keys($detectedYears);
    rsort($importResultYears);

    $savedFolderMsg = $savedPath ? "<br>📁 <strong>Im Projektordner archiviert:</strong> <code>" . htmlspecialchars($savedPath) . "</code>" : "";
    $rulesMsg = $rulesLearned > 0 ? " &bull; 🧠 <strong>$rulesLearned Dauer-Regeln</strong> für künftige Jahre gelernt" : "";

    unset($_SESSION['csv_preview'], $_SESSION['csv_filename'], $_SESSION['csv_saved_path'], $_SESSION['csv_folder_disp'], $_SESSION['csv_projekt'], $_SESSION['csv_konto_id'], $_SESSION['csv_konto_nr']);

    $yearSummaryText = !empty($importResultYears) ? "über die Jahre " . implode(', ', $importResultYears) : "";
    $msg = "✅ <strong>$savedCount Buchungen</strong> $yearSummaryText erfolgreich importiert ($assignedCount Einheiten/Mieter zugeordnet$rulesMsg).$savedFolderMsg";
    $msgType = "success";
}

// 5. Verwerfen
if (isset($_POST['cancel_import'])) {
    unset($_SESSION['csv_preview'], $_SESSION['csv_filename'], $_SESSION['csv_saved_path'], $_SESSION['csv_folder_disp'], $_SESSION['csv_projekt'], $_SESSION['csv_konto_id'], $_SESSION['csv_konto_nr']);
    $msg = "ℹ️ Import verworfen – keine Daten gespeichert.";
    $msgType = "info";
}

// Wenn Vorschau aktiv: Mieter, Wohnungen & Regeln für die gewählte Liegenschaft laden
$tenantsList = [];
$unitsList = [];
$rulesList = [];
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

    // Gelernte Regeln laden
    $rRes = $mysqli->query("SELECT * FROM konto_rules WHERE aktiv = 1 AND (liegenschaft_id = $selPid OR liegenschaft_id IS NULL) ORDER BY priority DESC, id ASC");
    if ($rRes) while ($r = $rRes->fetch_assoc()) $rulesList[] = $r;
}
?>
<link rel="stylesheet" href="./style.css">

<div class="konto-container" style="max-width:1460px; margin:24px auto; padding:24px; background:#fff; border-radius:14px; box-shadow:0 4px 20px rgba(0,0,0,0.06); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
    <div>
      <h2 style="margin:0 0 6px 0; font-size:22px; color:#0f172a;">📥 Universal Bank-CSV Import &amp; Liegenschafts-Archiv</h2>
      <div style="font-size:14px; color:#64748b;">
        Unterstützt Auszüge <strong>mit Details</strong> und <strong>ohne Details</strong> &bull; Automatische Dateiablage im Projektordner &bull; IBAN-Erkennung
      </div>
    </div>
    <div style="display:flex; gap:10px;">
      <a href="index.php" class="btn" style="padding:9px 16px; text-decoration:none; background:#f1f5f9; border-radius:8px; color:#334155; font-weight:600; font-size:13px;">⬅️ Zurück zum Konto</a>
      <a href="../mietkontrolle/index.php" class="btn primary" style="padding:9px 16px; text-decoration:none; background:#10b981; color:#fff; border-radius:8px; font-weight:600; font-size:13px;">📊 Zur Mietkontrolle</a>
    </div>
  </div>

  <?php if (!empty($msg)): ?>
    <div style="padding:16px 20px; border-radius:10px; margin-bottom:24px; font-size:14px; line-height:1.6; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; <?= $msgType === 'danger' ? 'background:#fef2f2; border:1px solid #fecaca; color:#991b1b;' : ($msgType === 'info' ? 'background:#f0f9ff; border:1px solid #bae6fd; color:#0369a1;' : 'background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;') ?>">
      <div><?= $msg ?></div>
      <?php if (!empty($importResultYears)): ?>
        <div style="display:flex; gap:6px;">
          <?php foreach ($importResultYears as $yr): ?>
            <a href="index.php?jahr=<?= $yr ?>&projekt_id=<?= (int)($_SESSION['current_project_id'] ?? 1) ?>" style="padding:7px 14px; background:#16a34a; color:#fff; text-decoration:none; border-radius:6px; font-size:12.5px; font-weight:700;">
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
      <h3 style="margin:0 0 16px 0; font-size:17px; color:#1e293b; display:flex; align-items:center; gap:8px;">
        1. Liegenschaft, Bankkonto und CSV-Datei wählen
      </h3>

      <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:14px 18px; margin-bottom:20px; font-size:13px; color:#1e40af; line-height:1.6;">
        <strong>✨ Vollautomatische Formaterkennung:</strong><br>
        &bull; <strong>Mit Details &amp; Ohne Details:</strong> Detailzeilen der Bank (Zahlungsgrund, Adressen, Referenzen) werden automatisch erkannt, mit der Hauptbuchung verknüpft und gehen nicht verloren.<br>
        &bull; <strong>Automatische Projekt-Ablage:</strong> Die Original-CSV-Datei wird direkt in den Projektordner der Liegenschaft auf Google Drive (<code>06_Bank_Liegenschaftskonto</code>) abgelegt.<br>
        &bull; <strong>IBAN-Auto-Erkennung:</strong> Enthält die Datei oder der Dateiname die IBAN (z.B. <code>CH06 8080 8001 9163 5081 4</code>), wird die Liegenschaft und das Konto automatisch ermittelt.
      </div>

      <form method="post" enctype="multipart/form-data" action="" style="display:grid; gap:18px;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px;">
          <div>
            <label for="sel_projekt" style="font-weight:700; display:block; margin-bottom:6px; font-size:14px; color:#334155;">
              🏢 1. Liegenschaft / Projekt:
            </label>
            <select name="projekt_id" id="sel_projekt" style="padding:10px 12px; width:100%; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
              <option value="">-- Automatisch via IBAN in CSV ermitteln --</option>
              <?php foreach ($projekte as $p): ?>
                <option value="<?= $p['id'] ?>" <?= ((int)$p['id'] === $defaultProjId) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="sel_konto" style="font-weight:700; display:block; margin-bottom:6px; font-size:14px; color:#334155;">
              💳 2. Zugeordnetes Bankkonto (IBAN):
            </label>
            <select name="konto_id" id="sel_konto" style="padding:10px 12px; width:100%; border-radius:8px; border:1px solid #cbd5e1; font-size:14px; background:#fff;">
              <option value="">-- Automatisch via Liegenschaft / CSV --</option>
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
            📄 3. Bank-CSV-Kontoauszug auswählen (mit oder ohne Details):
          </label>
          <input type="file" name="csv_file" id="csv_file_input" accept=".csv,.txt" required style="padding:14px; border:2px dashed #94a3b8; border-radius:8px; width:100%; box-sizing:border-box; background:#fff; cursor:pointer;">
          <div style="font-size:12px; color:#64748b; margin-top:5px;">
            Unterstützt Raiffeisen, PostFinance, UBS, ZKB, Kantonalbanken &bull; Automatische Umlaute-Reparatur (Windows-1252 / UTF-8).
          </div>
        </div>

        <div style="margin-top:8px;">
          <button type="submit" style="padding:12px 24px; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; display:inline-flex; align-items:center; gap:8px;">
            📂 CSV hochladen, im Projektordner ablegen &amp; prüfen ➔
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
      $previewData = $_SESSION['csv_preview'];
      $previewByYear = [];
      $totalIn = 0.0;
      $totalOut = 0.0;
      $detailRowCount = 0;

      foreach ($previewData as $idx => $row) {
          $bDatum = $row['datum'] ?? null;
          $bBetrag = (float)($row['betrag'] ?? 0);
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
          if (!empty($row['detail_lines'])) {
              $detailRowCount++;
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
            IBAN: <span style="font-family:monospace; font-weight:700; color:#0f172a; background:#e2e8f0; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($activeIban ? chunk_split($activeIban, 4, ' ') : '—') ?></span>
          </div>
          <div style="font-size:12px; color:#059669; font-weight:600; margin-top:6px; display:flex; align-items:center; gap:6px;">
            📁 <strong>Im Projektordner archiviert:</strong> <code style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-size:11.5px;"><?= htmlspecialchars($_SESSION['csv_folder_disp'] ?? $_SESSION['csv_saved_path'] ?? '') ?></code>
          </div>
          <div style="font-size:12px; color:#64748b; margin-top:4px;">
            📄 Quelldatei: <strong><?= htmlspecialchars($_SESSION['csv_filename']) ?></strong> (<?= count($previewData) ?> Buchungen<?= $detailRowCount > 0 ? " &bull; $detailRowCount mit Details" : " &bull; Standard-Format" ?>)
          </div>
        </div>

        <div style="display:flex; gap:10px; align-items:center;">
          <button type="submit" name="confirm_import" style="padding:11px 24px; background:#10b981; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 2px 6px rgba(16,185,129,0.3);">
            ✅ Alle <?= count($previewData) ?> Buchungen importieren
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
              Buchungen werden exakt mit ihrem Buchungsdatum gespeichert und stehen danach in der Jahresansicht getrennt bereit.
            </div>
          </div>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button type="button" onclick="filterPreviewYear('all')" id="pill_all" class="yr-pill active" style="padding:6px 14px; border-radius:6px; font-size:12px; font-weight:700; border:none; cursor:pointer; background:#2563eb; color:#fff;">
              🌐 Alle Jahre (<?= count($previewData) ?>)
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
            <th style="padding:12px 14px; width:105px;">Datum</th>
            <th style="padding:12px 14px; text-align:right; width:110px;">Betrag (CHF)</th>
            <th style="padding:12px 14px; width:220px;">Spalte A (IBAN &amp; Liegenschaft)</th>
            <th style="padding:12px 14px;">Buchungstext &amp; Details</th>
            <th style="padding:12px 14px; min-width:280px;">Zuweisung &amp; Mieter</th>
            <th style="padding:12px 14px; width:220px;">Kategorie</th>
            <th style="padding:12px 14px; width:70px; text-align:center;">Merken</th>
          </tr>
        </thead>
        <tbody>
        <?php 
        $matchCount = 0;
        foreach ($previewData as $idx => $row): 
            $buchungsdatum = $row['datum'] ?? '';
            $betrag = (float)($row['betrag'] ?? 0);
            $fullText = $row['beschreibung'] ?? '';
            $mainText = $row['main_text'] ?? '';
            $details = $row['detail_lines'] ?? [];
            $rowYear = $buchungsdatum ? (int)substr($buchungsdatum, 0, 4) : 0;

            $matchedVal = '';
            $autoKat = ($betrag > 0) ? 'Mietzinseinnahmen (Miete)' : 'Betriebskosten';

            // 1. ZUERST: Gelernte Regeln prüfen
            if (!empty($rulesList)) {
                foreach ($rulesList as $rl) {
                    $patt = trim($rl['pattern']);
                    if ($patt !== '' && stripos($fullText, $patt) !== false) {
                        if (!empty($rl['set_kategorie'])) $autoKat = $rl['set_kategorie'];
                        if (!empty($rl['wohnung_id'])) {
                            $mBId = !empty($rl['mieter_id']) ? (int)$rl['mieter_id'] : 0;
                            $matchedVal = 'm:' . $mBId . ':' . (int)$rl['wohnung_id'] . ':' . rawurlencode($rl['wohnung_label'] ?? '');
                            $matchCount++;
                        }
                        break;
                    }
                }
            }

            // 2. WENN NOCH KEIN MATCH & BETRAG > 0: Mieter-Namen abgleichen
            if (empty($matchedVal) && $betrag > 0 && !empty($tenantsList)) {
                foreach ($tenantsList as $t) {
                    $mWords = preg_split('/[\s,\/&]+/', $t['mieter_name']);
                    foreach ($mWords as $mw) {
                        $mw = trim($mw);
                        if (mb_strlen($mw) >= 4 && stripos($fullText, $mw) !== false) {
                            $mBId = !empty($t['benutzer_id']) ? (int)$t['benutzer_id'] : 0;
                            $matchedVal = 'm:' . $mBId . ':' . (int)$t['wohnung_id'] . ':' . rawurlencode($t['wohnung_name']);
                            $autoKat = 'Mietzinseinnahmen (Miete)';
                            $matchCount++;
                            break 2;
                        }
                    }
                }
            }

            // 3. WENN AUSGABE & KEINE REGEL: Schweizer Heuristiken
            if (empty($matchedVal) && $betrag < 0) {
                if (stripos($fullText, 'EW ') !== false || stripos($fullText, 'Strom') !== false) {
                    $autoKat = 'Betriebskosten (Strom / EW)';
                } elseif (stripos($fullText, 'Gemeinde') !== false || stripos($fullText, 'Bauverwaltung') !== false || stripos($fullText, 'ARA') !== false) {
                    $autoKat = 'Betriebskosten (Wasser / Kehricht)';
                } elseif (stripos($fullText, 'Gebäudeversicherung') !== false || stripos($fullText, 'GVTG') !== false || stripos($fullText, 'Baloise') !== false) {
                    $autoKat = 'Versicherungen (Gebäudeversicherung)';
                } elseif (stripos($fullText, 'Hypothek') !== false) {
                    $autoKat = 'Hypothekarzinsen';
                } elseif (stripos($fullText, 'KONE') !== false || stripos($fullText, 'CTA') !== false || stripos($fullText, 'Lift') !== false) {
                    $autoKat = 'Liegenschaftsunterhalt (Service-Abonnemente)';
                } elseif (stripos($fullText, 'Elektro') !== false || stripos($fullText, 'Lechner') !== false || stripos($fullText, 'Brauchli') !== false) {
                    $autoKat = 'Liegenschaftsunterhalt (Reparaturen)';
                } elseif (stripos($fullText, 'Nedim') !== false || stripos($fullText, 'Nedjip') !== false) {
                    $autoKat = 'Eigentümer-Entnahmen / Überträge';
                } elseif (stripos($fullText, 'Steuer') !== false) {
                    $autoKat = 'Steuern & Abgaben (Liegenschafts- & Gemeindesteuern)';
                } elseif (stripos($fullText, 'Gebühr') !== false || stripos($fullText, 'Mastercard') !== false || stripos($fullText, 'Abschluss') !== false) {
                    $autoKat = 'Verwaltungsaufwand & Bankspesen';
                } elseif (stripos($fullText, 'Hausabwart') !== false || stripos($fullText, 'Muratoska') !== false) {
                    $autoKat = 'Betriebskosten (Hauswartung)';
                }
            }
        ?>
          <tr class="preview-row" data-year="<?= $rowYear ?>" style="border-bottom:1px solid #e2e8f0; <?= !empty($matchedVal) ? 'background:#ecfdf5;' : '' ?>">
            <td style="padding:10px 14px; white-space:nowrap; vertical-align:top;">
              <span style="background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:700; font-size:11px; margin-right:4px;">
                <?= $rowYear ?: '—' ?>
              </span>
              <?= htmlspecialchars($buchungsdatum ? date('d.m.Y', strtotime($buchungsdatum)) : '—') ?>
            </td>
            <td style="padding:10px 14px; text-align:right; font-weight:700; color:<?= $betrag >= 0 ? '#16a34a' : '#dc2626' ?>; vertical-align:top;">
              <?= number_format($betrag, 2, '.', "'") ?>
            </td>
            <td style="padding:10px 14px; vertical-align:top;">
              <?php if (!empty($row['iban_clean'])): ?>
                <div style="font-family:monospace; font-weight:700; font-size:11px; color:#0f172a; background:#f1f5f9; padding:2px 6px; border-radius:4px; display:inline-block;">
                  💳 <?= htmlspecialchars(chunk_split($row['iban_clean'], 4, ' ')) ?>
                </div>
                <?php if (!empty($row['row_projekt_name'])): ?>
                  <div style="font-size:11.5px; color:#166534; font-weight:700; margin-top:3px; display:flex; align-items:center; gap:4px;">
                    ✓ <?= htmlspecialchars($row['row_projekt_name']) ?>
                  </div>
                  <div style="font-size:10.5px; color:#64748b;"><?= htmlspecialchars($row['row_konto_name'] ?? '') ?></div>
                <?php else: ?>
                  <div style="font-size:11px; color:#b45309; font-weight:600; margin-top:3px;">
                    ⚠️ Nicht zugeordnet
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:#94a3b8; font-size:11px;">(Keine IBAN)</span>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px; max-width:420px; word-break:break-word; vertical-align:top;">
              <div style="font-weight:600; color:#0f172a; font-size:13px;">
                <?= htmlspecialchars($mainText) ?>
              </div>
              <?php if (!empty($details)): ?>
                <div style="margin-top:4px;">
                  <?php foreach ($details as $dLine): ?>
                    <div style="font-size:11.5px; color:#475569; background:#f8fafc; border-left:2px solid #3b82f6; padding:2px 6px; border-radius:3px; margin-top:2px;">
                      ↳ <?= htmlspecialchars($dLine) ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="padding:10px 14px; vertical-align:top;">
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
                  <optgroup label="Wohnungen / Einheiten">
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
            <td style="padding:10px 14px; vertical-align:top;">
              <input list="kategorie_list" name="kategorie[<?= $idx ?>]" value="<?= htmlspecialchars($autoKat) ?>" style="padding:7px; width:100%; box-sizing:border-box; border-radius:6px; border:1px solid #cbd5e1; font-size:13px;">
            </td>
            <td style="padding:10px 14px; text-align:center; vertical-align:top;">
              <input type="checkbox" name="remember_rule[<?= $idx ?>]" value="1" title="Dauer-Regel für künftige Jahre merken" style="width:16px; height:16px; cursor:pointer;" <?= !empty($matchedVal) ? 'checked' : '' ?>>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <datalist id="kategorie_list">
        <?php foreach ($standardKategorien as $sk): ?>
          <option value="<?= htmlspecialchars($sk) ?>">
        <?php endforeach; ?>
      </datalist>

      <!-- Bottom Action Bar -->
      <div style="padding:16px 20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="font-size:14px; color:#334155;">
          <strong>Automatische Mieter-Matches:</strong> <span style="color:#16a34a; font-weight:700;"><?= $matchCount ?></span> von <?= count($previewData) ?> Zeilen erkannt
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
