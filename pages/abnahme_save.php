<?php
if (session_status() === PHP_SESSION_NONE)
    session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(__DIR__ . '/../logs/abnahme_debug.log', "[" . date('Y-m-d H:i:s') . "] ERROR [$errno] $errstr in $errfile:$errline\n", FILE_APPEND);
    return true;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        @file_put_contents(__DIR__ . '/../logs/abnahme_debug.log', "[" . date('Y-m-d H:i:s') . "] FATAL: {$error['message']} in {$error['file']}:{$error['line']}\n", FILE_APPEND);
    }
});

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/fs.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

header('Content-Type: application/json');

$pid = (int) ($_GET['projekt_id'] ?? 0);
$uid = (int) ($_GET['unit_id'] ?? 0);
$protocolId = (int) ($_GET['protocol_id'] ?? $_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

// Sofort-Upload eines einzelnen Fotos beim Auswählen (AJAX)
if ($action === 'upload_photo') {
    $basePath2 = dirname(__DIR__);
    $protocolDir2 = $basePath2 . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'protocols';
    if (!is_dir($protocolDir2)) @mkdir($protocolDir2, 0777, true);

    if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Ungültiger Dateityp']);
            exit;
        }
        $photoName = 'Foto_' . md5(uniqid('', true)) . '.' . $ext;
        $targetFile = $protocolDir2 . DIRECTORY_SEPARATOR . $photoName;
        if (@move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
            smart_resize_image($targetFile); // Automatische Verkleinerung auf max 0.5 MB
            echo json_encode(['success' => true, 'filename' => $photoName]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Speichern fehlgeschlagen']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Keine Datei empfangen']);
    }
    exit;
}

if ($action === 'delete' && $protocolId > 0) {
    $mysqli->query("DELETE FROM abnahme_protokolle WHERE id = $protocolId");
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'rename' && $protocolId > 0 && isset($_GET['name'])) {
    $newName = $_GET['name'];
    $res = $mysqli->query("SELECT daten_json FROM abnahme_protokolle WHERE id = $protocolId");
    if ($row = $res->fetch_assoc()) {
        $data = json_decode($row['daten_json'], true);
        $data['protokoll_bezeichnung'] = $newName;
        $json = $mysqli->real_escape_string(json_encode($data));
        $mysqli->query("UPDATE abnahme_protokolle SET daten_json = '$json' WHERE id = $protocolId");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Not found']);
    }
    exit;
}

$isDraft = isset($_POST['is_draft']) && $_POST['is_draft'] == '1';

$basePath = dirname(__DIR__);
$protocolDir = $basePath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'protocols';
if (!is_dir($protocolDir))
    @mkdir($protocolDir, 0777, true);

$logFile = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'abnahme_debug.log';

function logDebug($msg)
{
    global $logFile;
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

try {
    logDebug("START Save-Prozess für Projekt $pid, Unit $uid");
    logDebug("FILES debug: " . print_r($_FILES['photos']['name'] ?? 'no photos uploaded', true));
    logDebug("POST debug existing: " . print_r($_POST['existing_photos'] ?? 'no existing', true));
    $unit = $mysqli->query("SELECT w.*, p.name as p_name, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid")->fetch_assoc();
    if (!$unit)
        throw new Exception("Wohnung nicht gefunden.");

    $mieterName = ($_POST['mieter_id'] === 'custom') ? ($_POST['mieter_name_custom'] ?? 'N/A') : '';
    if (empty($mieterName) && !empty($_POST['mieter_id'])) {
        $mRes = $mysqli->query("SELECT mieter_name FROM wohnung_mieter WHERE id = " . (int) $_POST['mieter_id']);
        if ($mRow = $mRes->fetch_assoc())
            $mieterName = $mRow['mieter_name'];
    }

    $templatePath = $basePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'abnahme_template.html';
    $html = file_get_contents($templatePath);

    // Vorgangsarten Map (Taxonomie)
    $vorgangsarten = $mysqli->query("SELECT id, name as bezeichnung FROM pendenzen_arten")->fetch_all(MYSQLI_ASSOC);
    $vaMap = [];
    foreach ($vorgangsarten as $va) $vaMap[$va['id']] = $va['bezeichnung'];

    // Benutzer & Firma für Pendenzen
    $userId = $_SESSION['user_id'] ?? 0;
    $userRes = $mysqli->query("SELECT name, firma_name FROM benutzer WHERE id = $userId")->fetch_assoc();
    $userName = $userRes['name'] ?? 'Unbekannt';
    $firmaName = $userRes['firma_name'] ?? 'Baupartnerschaft AG';

    $replacements = [
        '[[REFERENZ_NR]]' => 'AB-' . $pid . '-' . $uid . '-' . date('Ymd'),
        '[[DATUM]]' => date('d.m.Y'),
        '[[PROJEKT_NAME]]' => $unit['p_name'],
        '[[OBJEKT_NAME]]' => $unit['obj_name'] ?? 'Hauptgebäude',
        '[[WOHNUNG_NAME]]' => $unit['name'],
        '[[ETAGE]]' => $unit['etage'] ?? '-',
        '[[MIETER_NAME]]' => $mieterName,
        '[[VERMIETER_FIRMA]]' => $firmaName,
        '[[VERWALTER_NAME]]' => $userName,
        '[[STROM_ZAEHLER]]' => $_POST['zaehler_strom'] ?? '-',
        '[[WASSER_ZAEHLER]]' => $_POST['zaehler_wasser'] ?? '-',
        '[[HEIZUNG_ZAEHLER]]' => $_POST['zaehler_heizung'] ?? '-',
        '[[SIGNATURE_VERMIETER]]' => !empty($_POST['signature_vermieter']) ? '<img src="' . $_POST['signature_vermieter'] . '" class="sig-img">' : '',
        '[[SIGNATURE_MIETER]]' => !empty($_POST['signature_mieter']) ? '<img src="' . $_POST['signature_mieter'] . '" class="sig-img">' : ''
    ];

    $rows = "";
    $savedPhotos = [];
    $statusData = $_POST['status'] ?? [];
    $titles = $_POST['title'] ?? [];
    $comments = $_POST['comment'] ?? [];
    $asPendenz = $_POST['as_pendenz'] ?? [];
    $responsibles = $_POST['responsible'] ?? [];
    $starts = $_POST['start_date'] ?? [];
    $durations = $_POST['duration'] ?? [];
    $kategorien = $_POST['kategorie'] ?? [];
    $subkategorien = $_POST['subkategorie'] ?? [];
    $roomLabels = $_POST['room_label'] ?? [];
    $itemLabels = $_POST['item_label'] ?? [];

    // Bestehende Protokolldaten einmal laden (für Foto-Vergleich)
    $oldData = [];
    if ($protocolId > 0) {
        $oldRes = $mysqli->query("SELECT daten_json FROM abnahme_protokolle WHERE id = $protocolId");
        if ($oldRes && $oldRow = $oldRes->fetch_assoc()) {
            $oldData = json_decode($oldRow['daten_json'], true) ?: [];
        }
    }

    $currentRoom = "";

    foreach ($statusData as $key => $statusArray) {
        $room = $roomLabels[$key] ?? $key;
        $origItem = $itemLabels[$key] ?? $key;

        if ($room !== $currentRoom) {
            $rows .= '<tr style="background-color: #f8fafc;"><td colspan="4" style="padding: 10px; font-weight: bold; border-bottom: 1px solid #e2e8f0;">' . htmlspecialchars($room) . '</td></tr>';
            $currentRoom = $room;
        }

        foreach ($statusArray as $idx => $statusId) {
            $item = $origItem;
            $userTitle = $titles[$key][$idx] ?? '';
            if (!empty($userTitle) && $userTitle !== $item) {
                $item .= ", " . $userTitle;
            }

            $comment = $comments[$key][$idx] ?? '';
            $statusName = ($statusId == "0" || empty($statusId)) ? 'OK' : ($vaMap[$statusId] ?? 'OK');

            // Foto-Handling
            $imgHtml = "";
            $photoName = "";
            $isNewPhoto = false;

            // Bilder kommen jetzt als bereits hochgeladene Dateinamen über existing_photos
            // (weil previewImage() sofort per AJAX hochlädt und den Dateinamen speichert)
            if (!empty($_POST['existing_photos'][$key][$idx])) {
                $photoName = $_POST['existing_photos'][$key][$idx];
                $savedPhotos[$key][$idx] = $photoName;

                // Prüfen ob es ein neues Bild ist (Vergleich mit gespeichertem Dateinamen)
                $oldPhotoName = $oldData['saved_photo_names'][$key][$idx] ?? '';
                logDebug("Foto-Check $key [$idx]: neu='$photoName', alt='$oldPhotoName'");
                if ($photoName !== $oldPhotoName) {
                    $isNewPhoto = true;
                    logDebug("  => NEUES BILD!");
                }
            } elseif (!empty($_FILES['photos']['name'][$key][$idx])) {
                // Fallback: klassischer File-Upload (falls Browser $_FILES doch befüllt)
                $ext = pathinfo($_FILES['photos']['name'][$key][$idx], PATHINFO_EXTENSION);
                $photoName = 'Abnahme_Foto_' . md5(uniqid('', true)) . '.' . $ext;
                $targetFile = $protocolDir . DIRECTORY_SEPARATOR . $photoName;
                if (@move_uploaded_file($_FILES['photos']['tmp_name'][$key][$idx], $targetFile)) {
                    smart_resize_image($targetFile);
                }
                $savedPhotos[$key][$idx] = $photoName;
                $isNewPhoto = true;
                logDebug("Fallback-Upload $key [$idx]: $photoName");
            }

            if ($photoName) {
                $fullImgPath = $protocolDir . DIRECTORY_SEPARATOR . $photoName;
                if (file_exists($fullImgPath)) {
                    $imgHtml = '<br><img src="data:image/jpg;base64,' . base64_encode(file_get_contents($fullImgPath)) . '" style="width: 150px; margin-top: 5px; border-radius: 4px;">';
                }
            }

            $rows .= '<tr>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9; width: 25%;">' . htmlspecialchars($origItem) . '</td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9; width: 10%; text-align:center;">' . htmlspecialchars($statusName) . '</td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9; width: 25%;">' . htmlspecialchars($userTitle) . '</td>
                <td style="padding: 8px; border-bottom: 1px solid #f1f5f9;">' . nl2br(htmlspecialchars($comment)) . $imgHtml . '</td>
            </tr>';
            
            if ($action === 'sync_pendenzen') {
                if (!empty($asPendenz[$key][$idx])) {
                    $pTitel = !empty($userTitle) ? $userTitle : $item;
                    $pErsteller = $userId;
                    $pZustaendig = !empty($responsibles[$key][$idx]) ? (int)$responsibles[$key][$idx] : null;
                    $pStart = !empty($starts[$key][$idx]) ? $starts[$key][$idx] : date('Y-m-d');
                    $pDauer = !empty($durations[$key][$idx]) ? (int)$durations[$key][$idx] : null;
                    $pEnde = !empty($_POST['end_date'][$key][$idx]) ? $_POST['end_date'][$key][$idx] : null;
                    $pPrio = !empty($_POST['priority'][$key][$idx]) ? (int)$_POST['priority'][$key][$idx] : null;
                    $pKat = !empty($kategorien[$key][$idx]) ? (int)$kategorien[$key][$idx] : null;
                    $vorgangsartId = (int)$statusId;
                    $bkpId = ($vorgangsartId == 3) ? $pKat : null;
                    $pRaumId = !empty($_POST['raum_id'][$key][$idx]) ? (int)$_POST['raum_id'][$key][$idx] : null;
                    $pFoto = $photoName ? 'uploads/protocols/' . $photoName : null;

                    $existingId = !empty($_POST['pendenz_id'][$key][$idx]) ? (int)$_POST['pendenz_id'][$key][$idx] : 0;
                    $targetPendenzId = 0;
                    
                    if ($existingId > 0) {
                        $stmtP = $mysqli->prepare("UPDATE pendenzen SET titel=?, kurzbeschreibung=?, vorgangsart_id=?, bkp_id=?, raum_id=?, zustaendig_id=?, startdatum=?, dauer=?, enddatum=?, wichtigkeit=?, geaendert_am=NOW() WHERE id=?");
                        $stmtP->bind_param("ssiiiisisii", $pTitel, $comment, $vorgangsartId, $bkpId, $pRaumId, $pZustaendig, $pStart, $pDauer, $pEnde, $pPrio, $existingId);
                        $stmtP->execute();
                        $targetPendenzId = $existingId;
                    } else {
                        $stmtP = $mysqli->prepare("INSERT INTO pendenzen (projekt_id, objekt_id, wohnung_id, titel, kurzbeschreibung, vorgangsart_id, bkp_id, raum_id, status, erstellt_von, zustaendig_id, startdatum, dauer, enddatum, wichtigkeit, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'offen', ?, ?, ?, ?, ?, ?, NOW())");
                        $objId = (int)$unit['objekt_id'];
                        $stmtP->bind_param("iiissiiiiisisi", $pid, $objId, $uid, $pTitel, $comment, $vorgangsartId, $bkpId, $pRaumId, $pErsteller, $pZustaendig, $pStart, $pDauer, $pEnde, $pPrio);
                        if ($stmtP->execute()) {
                            $targetPendenzId = $stmtP->insert_id;
                            $_POST['pendenz_id'][$key][$idx] = $targetPendenzId;
                        }
                    }

                    // RADIKAL: Wenn Bild vorhanden → immer überschreiben in pendenz_dateien
                    if ($targetPendenzId > 0 && $pFoto) {
                        // Alle alten Bilder aus Abnahme-Protokoll für diese Pendenz löschen
                        $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id = " . (int)$targetPendenzId . " AND pfad LIKE 'uploads/protocols/%'");

                        $mime = @mime_content_type($protocolDir . DIRECTORY_SEPARATOR . $photoName);
                        if (!$mime) $mime = 'image/jpeg';

                        $stmtA = $mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id, pfad, typ, mimetype, is_cover) VALUES (?, ?, 'image', ?, 1)");
                        if ($stmtA) {
                            $stmtA->bind_param("iss", $targetPendenzId, $pFoto, $mime);
                            $r = $stmtA->execute();
                            logDebug("Bild überschrieben für Pendenz $targetPendenzId: $pFoto — " . ($r ? 'OK' : $stmtA->error));
                        }
                    } elseif ($targetPendenzId > 0 && !$pFoto) {
                        // Kein Bild im Protokoll → Cover-Markierung entfernen (aber Bild behalten)
                        logDebug("Kein Bild für Pendenz $targetPendenzId — kein Update");
                    }
                }

            }
        }
    }

    // JSON erweitern um Foto-Dateinamen VOR dem action===sync_pendenzen
    $_POST['saved_photo_names'] = $savedPhotos;

    if ($action === 'sync_pendenzen') {
        if ($protocolId > 0) {
            // Bestehende Daten laden
            $existingRes = $mysqli->query("SELECT daten_json FROM abnahme_protokolle WHERE id = $protocolId");
            $existingData = [];
            if ($existingRes && $eRow = $existingRes->fetch_assoc()) {
                $existingData = json_decode($eRow['daten_json'], true) ?: [];
            }

            // Neue POST-Daten auf bestehende mergen
            $merged = array_merge($existingData, $_POST);

            // Unterschriften: NUR überschreiben wenn wirklich neu gesendet
            foreach (['signature_vermieter', 'signature_mieter'] as $sigKey) {
                if (empty($_POST[$sigKey]) && !empty($existingData[$sigKey])) {
                    $merged[$sigKey] = $existingData[$sigKey];
                }
            }

            // Fotonamen: bestehende mit neuen zusammenführen (neue haben Vorrang)
            if (!empty($existingData['saved_photo_names']) && is_array($existingData['saved_photo_names'])) {
                $mergedPhotos = $existingData['saved_photo_names'];
                foreach ($savedPhotos as $k => $arr) {
                    foreach ($arr as $i => $pn) {
                        if (!empty($pn)) {
                            $mergedPhotos[$k][$i] = $pn;
                        }
                    }
                }
                $merged['saved_photo_names'] = $mergedPhotos;
            } else {
                $merged['saved_photo_names'] = $savedPhotos;
            }

            $json = $mysqli->real_escape_string(json_encode($merged));
            $mysqli->query("UPDATE abnahme_protokolle SET daten_json = '$json' WHERE id = $protocolId");
        }
        echo json_encode(['success' => true, 'protocol_id' => $protocolId]);
        exit;
    }

    $replacements['[[CHECKLISTE_ROWS]]'] = $rows;

    // Schlüssel & PDF Generation wie gehabt...
    $keys = "<tr><td>Hausschlüssel</td><td style='text-align:center;'>" . ($_POST['keys_house'] ?? 0) . "</td></tr>";
    $keys .= "<tr><td>Briefkasten</td><td style='text-align:center;'>" . ($_POST['keys_mail'] ?? 0) . "</td></tr>";
    $keys .= "<tr><td>Keller / Sonstige</td><td style='text-align:center;'>" . ($_POST['keys_cellar'] ?? 0) . "</td></tr>";
    $replacements['[[SCHLUESSEL_ROWS]]'] = $keys;

    $filename = null;
    if (!$isDraft) {
        foreach ($replacements as $k => $val) {
            $html = str_replace($k, (string) $val, $html);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Abnahme_' . preg_replace('/[^a-zA-Z0-9]/', '_', $mieterName) . '_' . date('Ymd_His') . '.pdf';
        $pdfOutput = $dompdf->output();

        // 1. Lokale Vorschau-Kopie in uploads/protocols
        $basePath = dirname(__DIR__);
        $protocolDir = $basePath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'protocols';
        if (!is_dir($protocolDir)) @mkdir($protocolDir, 0777, true);
        file_put_contents($protocolDir . DIRECTORY_SEPARATOR . $filename, $pdfOutput);

        // 2. Automatischer Speicherort im Google Drive der Wohnung: 10_Mietsache/<Wohnung>/05_Abnahmen/
        if ($uid > 0) {
            $entityPath = fs_get_entity_path($mysqli, 'wohnung', $uid);
            if (!empty($entityPath['abs']) && is_dir($entityPath['abs'])) {
                $driveAbnahmenDir = $entityPath['abs'] . DIRECTORY_SEPARATOR . '05_Abnahmen';
                if (!is_dir($driveAbnahmenDir)) @mkdir($driveAbnahmenDir, 0777, true);
                
                $drivePdfFile = $driveAbnahmenDir . DIRECTORY_SEPARATOR . $filename;
                file_put_contents($drivePdfFile, $pdfOutput);

                // Im Dateimanager (fs_nodes) registrieren
                $driveRel = $entityPath['rel'] . '/05_Abnahmen/' . $filename;
                $parentRel = $entityPath['rel'] . '/05_Abnahmen';
                $sz = strlen($pdfOutput);
                $stN = $mysqli->prepare("INSERT INTO fs_nodes (project_id, rel_path, name, parent_rel_path, is_dir, size, mtime) VALUES (?, ?, ?, ?, 0, ?, NOW()) ON DUPLICATE KEY UPDATE size=VALUES(size), mtime=NOW()");
                if ($stN) {
                    $stN->bind_param("isssi", $pid, $driveRel, $filename, $parentRel, $sz);
                    $stN->execute();
                    $stN->close();
                }
            }
        }
    }

    $datenJson = json_encode($_POST);

    if ($protocolId > 0) {
        $stmtDoc = $mysqli->prepare("UPDATE abnahme_protokolle SET projekt_id = ?, wohnung_id = ?, mieter_id = ?, mieter_name_custom = ?, daten_json = ?, erstellt_von = ?, pdf_pfad = ? WHERE id = ?");
        $erstelltVon = (int) ($_SESSION['user_id'] ?? 0);
        $mieterId = (int) ($_POST['mieter_id'] ?? 0);
        $stmtDoc->bind_param("iiissisi", $pid, $uid, $mieterId, $mieterName, $datenJson, $erstelltVon, $filename, $protocolId);
    } else {
        $stmtDoc = $mysqli->prepare("INSERT INTO abnahme_protokolle (projekt_id, wohnung_id, mieter_id, mieter_name_custom, daten_json, erstellt_am, erstellt_von, pdf_pfad) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
        $erstelltVon = (int) ($_SESSION['user_id'] ?? 0);
        $mieterId = (int) ($_POST['mieter_id'] ?? 0);
        $stmtDoc->bind_param("iiissis", $pid, $uid, $mieterId, $mieterName, $datenJson, $erstelltVon, $filename);
    }
    $stmtDoc->execute();
    if ($protocolId <= 0) $protocolId = $mysqli->insert_id;

    logDebug("SUCCESS: Protokoll " . ($isDraft ? "entwurf gespeichert" : "erstellt: $filename") . " (ID: $protocolId)");
    echo json_encode(['success' => true, 'is_draft' => $isDraft, 'protocol_id' => $protocolId, 'pdf_url' => $filename ? '../uploads/protocols/' . $filename : null]);

} catch (Exception $e) {
    logDebug("ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
