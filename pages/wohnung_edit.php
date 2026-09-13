<?php
/**
 * pages/wohnung_edit.php
 * Vollansicht zur Bearbeitung einer Wohneinheit.
 * Inklusive zentraler Raum-Verwaltung.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
require_role(['admin', 'superadmin']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("❌ Ungültige ID.");

// --- AUTO-MIGRATION: Mietzins-Historie-Tabelle anlegen ---
$mysqli->query("
    CREATE TABLE IF NOT EXISTS wohnung_mietzins_historie (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wohnung_id INT NOT NULL,
        gilt_ab DATE NOT NULL,
        mietzins_netto DECIMAL(10,2) NOT NULL,
        mietzins_nk DECIMAL(10,2) NOT NULL,
        typ VARCHAR(50) NOT NULL, -- 'soll', 'ist', 'erhoehung', 'reduktion', 'anpassung'
        bemerkung TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// --- AUTO-MIGRATION: Fehlende Spalten in wohnungen-Tabelle anlegen ---
$required_columns = [
    'etage' => "VARCHAR(50) NULL DEFAULT NULL",
    'zimmer' => "DECIMAL(3,1) NULL DEFAULT NULL",
    'badezimmer' => "DECIMAL(3,1) NULL DEFAULT NULL",
    'balkon' => "TINYINT(1) DEFAULT 0",
    'wintergarten' => "TINYINT(1) DEFAULT 0",
    'terrasse' => "TINYINT(1) DEFAULT 0",
    'garten' => "TINYINT(1) DEFAULT 0",
    'gartensitzplatz' => "TINYINT(1) DEFAULT 0",
    'spielplatz' => "TINYINT(1) DEFAULT 0",
    'gemeinschaftsraum' => "TINYINT(1) DEFAULT 0",
    'letzter_renovation' => "DATE NULL DEFAULT NULL",
    'gesamtzustand' => "VARCHAR(100) NULL DEFAULT NULL",
    'flaeche' => "DECIMAL(10,2) NULL DEFAULT NULL",
    'baujahr' => "INT(11) DEFAULT 0",
    'heizungsart' => "VARCHAR(100) NULL DEFAULT NULL",
    'bodenbelag' => "VARCHAR(100) NULL DEFAULT NULL",
    'kueche_details' => "TEXT NULL DEFAULT NULL",
    'bad_details' => "TEXT NULL DEFAULT NULL",
    'lift' => "TINYINT(1) DEFAULT 0",
    'barrierefrei' => "TINYINT(1) DEFAULT 0",
    'haustiere_erlaubt' => "TINYINT(1) DEFAULT 0",
    'waschmaschine' => "VARCHAR(100) NULL DEFAULT NULL",
    'keller_vorhanden' => "TINYINT(1) DEFAULT 0",
    'parkplatz' => "VARCHAR(100) NULL DEFAULT NULL",
    'minergie' => "TINYINT(1) DEFAULT 0",
    'glasfaser' => "TINYINT(1) DEFAULT 0",
    'besonnerung' => "VARCHAR(255) NULL DEFAULT NULL",
    'aussicht' => "VARCHAR(255) NULL DEFAULT NULL",
    'laermpegel' => "VARCHAR(255) NULL DEFAULT NULL",
    'mietzins_netto_soll' => "DECIMAL(10,2) DEFAULT 0.00",
    'mietzins_nk_soll' => "DECIMAL(10,2) DEFAULT 0.00",
    'ausstattung_details' => "TEXT NULL DEFAULT NULL",
    'available_from' => "VARCHAR(100) NULL DEFAULT NULL",
    'typ_id' => "INT(11) NULL DEFAULT 1",
    'marketing_titel' => "VARCHAR(255) NULL DEFAULT NULL",
    'marketing_beschreibung' => "TEXT NULL DEFAULT NULL",
    'marketing_highlight1' => "VARCHAR(150) NULL DEFAULT NULL",
    'marketing_highlight2' => "VARCHAR(150) NULL DEFAULT NULL",
    'marketing_highlight3' => "VARCHAR(150) NULL DEFAULT NULL",
    'marketing_status' => "VARCHAR(50) DEFAULT 'entwurf'",
    'link_homegate' => "VARCHAR(500) NULL DEFAULT NULL",
    'link_immoscout' => "VARCHAR(500) NULL DEFAULT NULL",
    'link_flatfox' => "VARCHAR(500) NULL DEFAULT NULL",
    'link_comparis' => "VARCHAR(500) NULL DEFAULT NULL"
];

$existing_cols = [];
$col_res = $mysqli->query("SHOW COLUMNS FROM wohnungen");
if ($col_res) {
    while ($col_row = $col_res->fetch_assoc()) {
        $existing_cols[strtolower($col_row['Field'])] = true;
    }
}

foreach ($required_columns as $col_name => $definition) {
    if (!isset($existing_cols[strtolower($col_name)])) {
        $mysqli->query("ALTER TABLE wohnungen ADD COLUMN `$col_name` $definition");
    }
}

// --- AUTO-MIGRATION: Fehlende Spalten in wohnung_mieter-Tabelle anlegen ---
$required_mieter_columns = [
    'mieter_name' => "VARCHAR(255) NULL DEFAULT NULL",
    'mietzins_netto' => "DECIMAL(10,2) DEFAULT 0.00",
    'nk_akonto' => "DECIMAL(10,2) DEFAULT 0.00",
    'status' => "VARCHAR(50) DEFAULT 'aktiv'"
];

$existing_mieter_cols = [];
$col_res_m = $mysqli->query("SHOW COLUMNS FROM wohnung_mieter");
if ($col_res_m) {
    while ($col_row = $col_res_m->fetch_assoc()) {
        $existing_mieter_cols[strtolower($col_row['Field'])] = true;
    }
}

foreach ($required_mieter_columns as $col_name => $definition) {
    if (!isset($existing_mieter_cols[strtolower($col_name)])) {
        $mysqli->query("ALTER TABLE wohnung_mieter ADD COLUMN `$col_name` $definition");
    }
}

// --- AUTO-MIGRATION: Fehlende Spalten in wohnung_bilder-Tabelle anlegen ---
$required_bilder_columns = [
    'titel' => "VARCHAR(255) NULL DEFAULT NULL",
    'sort_order' => "INT(11) DEFAULT 0"
];
$existing_bilder_cols = [];
$col_res_b = $mysqli->query("SHOW COLUMNS FROM wohnung_bilder");
if ($col_res_b) {
    while ($col_row = $col_res_b->fetch_assoc()) {
        $existing_bilder_cols[strtolower($col_row['Field'])] = true;
    }
}
foreach ($required_bilder_columns as $col_name => $definition) {
    if (!isset($existing_bilder_cols[strtolower($col_name)])) {
        $mysqli->query("ALTER TABLE wohnung_bilder ADD COLUMN `$col_name` $definition");
    }
}

// --- AUTO-MIGRATION: Fehlende Spalten in wohnung_dokumente-Tabelle anlegen ---
$required_dok_columns = [
    'kategorie' => "VARCHAR(100) DEFAULT 'sonstiges'"
];
$existing_dok_cols = [];
$col_res_d = $mysqli->query("SHOW COLUMNS FROM wohnung_dokumente");
if ($col_res_d) {
    while ($col_row = $col_res_d->fetch_assoc()) {
        $existing_dok_cols[strtolower($col_row['Field'])] = true;
    }
}
foreach ($required_dok_columns as $col_name => $definition) {
    if (!isset($existing_dok_cols[strtolower($col_name)])) {
        $mysqli->query("ALTER TABLE wohnung_dokumente ADD COLUMN `$col_name` $definition");
    }
}

// Wohnung laden (mit JOIN für Projekt-Zugehörigkeit)
$res = $mysqli->query("
    SELECT w.*, o.projekt_id 
    FROM wohnungen w 
    LEFT JOIN objekte o ON o.id = w.objekt_id 
    WHERE w.id = $id
");
$wohnung = $res->fetch_assoc();
if (!$wohnung) die("❌ Wohnung nicht gefunden.");

$wohnungBilder = [];
$bRes = $mysqli->query("SELECT * FROM wohnung_bilder WHERE wohnung_id = $id ORDER BY is_cover DESC, sort_order ASC, id ASC");
if ($bRes) {
    while ($b = $bRes->fetch_assoc()) {
        $wohnungBilder[] = $b;
    }
}

$wohnungDokumente = [];
$dRes = $mysqli->query("SELECT * FROM wohnung_dokumente WHERE wohnung_id = $id ORDER BY id ASC");
if ($dRes) {
    while ($d = $dRes->fetch_assoc()) {
        $wohnungDokumente[] = $d;
    }
}

// Geschwister-Wohnungen laden für Navigation
$objekt_id = (int)$wohnung['objekt_id'];
$siblings = [];
$prev_id = null;
$next_id = null;

if ($objekt_id > 0) {
    // Projekt-ID finden
    $pRow = $mysqli->query("SELECT projekt_id FROM objekte WHERE id = $objekt_id")->fetch_assoc();
    $pid = (int)($pRow['projekt_id'] ?? 0);
    
    if ($pid > 0) {
        $sib_res = $mysqli->query("
            SELECT w.id, w.name 
            FROM wohnungen w 
            JOIN objekte o ON o.id = w.objekt_id 
            WHERE o.projekt_id = $pid 
            ORDER BY w.name ASC
        ");
        while($s = $sib_res->fetch_assoc()) {
            $siblings[] = $s;
        }
        
        // Prev/Next finden
        for($i=0; $i < count($siblings); $i++) {
            if ($siblings[$i]['id'] == $id) {
                if ($i > 0) $prev_id = $siblings[$i-1]['id'];
                if ($i < count($siblings)-1) $next_id = $siblings[$i+1]['id'];
                break;
            }
        }
    }
}

// --- ALL USERS & ACTIVE TENANT DATA ---
$allUsers = [];
$usrRes = $mysqli->query("SELECT id, name FROM benutzer ORDER BY name ASC");
if ($usrRes) {
    while ($usr = $usrRes->fetch_assoc()) {
        $allUsers[] = $usr;
    }
}

$active_mieter = null;
$mRes = $mysqli->query("
    SELECT wm.*, b.name as benutzer_name
    FROM wohnung_mieter wm
    LEFT JOIN benutzer b ON wm.benutzer_id = b.id
    WHERE wm.wohnung_id = $id AND wm.status = 'aktiv'
    LIMIT 1
");
if ($mRes && $mRes->num_rows > 0) {
    $active_mieter = $mRes->fetch_assoc();
}

$mietzinsHistory = [];
$historyRes = $mysqli->query("SELECT * FROM wohnung_mietzins_historie WHERE wohnung_id = $id ORDER BY gilt_ab DESC, id DESC");
if ($historyRes) {
    while ($h = $historyRes->fetch_assoc()) {
        $mietzinsHistory[] = $h;
    }
}

// --- ACTION: Verlaufseintrag löschen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_history_entry') {
    try {
        $hid = (int)($_POST['history_id'] ?? 0);
        if ($hid > 0) {
            $mysqli->query("DELETE FROM wohnung_mietzins_historie WHERE id = $hid AND wohnung_id = $id");
        }
        header("Location: wohnung_edit.php?id=$id&success=1#finanzen");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- ACTION: Medien Upload (Bilder / Dokumente / URLs) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_media') {
    try {
        $response = ['success' => false, 'message' => ''];
        
        // 1) URL-Upload (z.B. Google Photos Drag & Drop)
        if (!empty($_POST['media_url'])) {
            $url = trim($_POST['media_url']);
            
            // Image downloaden
            $ctx = stream_context_create([
                "http" => [
                    "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
                ]
            ]);
            $imgData = @file_get_contents($url, false, $ctx);
            if ($imgData === false) {
                throw new Exception("Bild konnte von URL nicht geladen werden.");
            }
            
            // Extension & Name bestimmen
            $ext = 'jpg';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($imgData);
                if ($mime === 'image/png') $ext = 'png';
                elseif ($mime === 'image/gif') $ext = 'gif';
                elseif ($mime === 'image/webp') $ext = 'webp';
            } else {
                $imgInfo = @getimagesizefromstring($imgData);
                if ($imgInfo && isset($imgInfo['mime'])) {
                    $mime = $imgInfo['mime'];
                    if ($mime === 'image/png') $ext = 'png';
                    elseif ($mime === 'image/gif') $ext = 'gif';
                    elseif ($mime === 'image/webp') $ext = 'webp';
                } else {
                    $pathExt = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                    if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $ext = ($pathExt === 'jpeg') ? 'jpg' : $pathExt;
                    }
                }
            }
            
            $dir = __DIR__ . '/../uploads/wohnungen/bilder';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            
            $filename = 'url_' . uniqid() . '.' . $ext;
            $filepath = $dir . '/' . $filename;
            file_put_contents($filepath, $imgData);
            
            $dbPath = 'uploads/wohnungen/bilder/' . $filename;
            
            // In DB speichern
            // Prüfen ob bereits Bilder da sind (für is_cover)
            $checkC = $mysqli->query("SELECT id FROM wohnung_bilder WHERE wohnung_id = $id LIMIT 1");
            $is_cover = ($checkC && $checkC->num_rows === 0) ? 1 : 0;
            
            $stmtM = $mysqli->prepare("INSERT INTO wohnung_bilder (wohnung_id, pfad, is_cover) VALUES (?, ?, ?)");
            $stmtM->bind_param("isi", $id, $dbPath, $is_cover);
            $stmtM->execute();
            $stmtM->close();
            
            $response = ['success' => true, 'message' => 'Bild von URL erfolgreich hochgeladen.', 'path' => base_url($dbPath)];
        } 
        // 2) Normaler File-Upload
        elseif (!empty($_FILES['media_file'])) {
            $file = $_FILES['media_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Upload-Fehler: " . $file['error']);
            }
            
            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
            $isDoc = in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'], true);
            
            if (!$isImage && !$isDoc) {
                throw new Exception("Ungültiges Dateiformat. Erlaubt sind Bilder und Office-Dokumente/PDFs.");
            }
            
            if ($isImage) {
                $dir = __DIR__ . '/../uploads/wohnungen/bilder';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                
                $filename = 'img_' . uniqid() . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $dir . '/' . $filename);
                $dbPath = 'uploads/wohnungen/bilder/' . $filename;
                
                $checkC = $mysqli->query("SELECT id FROM wohnung_bilder WHERE wohnung_id = $id LIMIT 1");
                $is_cover = ($checkC && $checkC->num_rows === 0) ? 1 : 0;
                
                $stmtM = $mysqli->prepare("INSERT INTO wohnung_bilder (wohnung_id, pfad, is_cover) VALUES (?, ?, ?)");
                $stmtM->bind_param("isi", $id, $dbPath, $is_cover);
                $stmtM->execute();
                $stmtM->close();
            } else {
                $dir = __DIR__ . '/../uploads/wohnungen/dokumente';
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                
                $filename = 'doc_' . uniqid() . '.' . $ext;
                move_uploaded_file($file['tmp_name'], $dir . '/' . $filename);
                $dbPath = 'uploads/wohnungen/dokumente/' . $filename;
                
                $stmtM = $mysqli->prepare("INSERT INTO wohnung_dokumente (wohnung_id, name, pfad, typ) VALUES (?, ?, ?, ?)");
                $stmtM->bind_param("isss", $id, $origName, $dbPath, $ext);
                $stmtM->execute();
                $stmtM->close();
            }
            
            $response = ['success' => true, 'message' => 'Datei erfolgreich hochgeladen.'];
        }
        
        // JSON-Response für AJAX senden
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode($response);
            exit;
        }
        header("Location: wohnung_edit.php?id=$id&success=1#medien");
        exit;
    } catch (Throwable $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $error = $e->getMessage();
    }
}

// --- ACTION: Medien löschen oder Coverbild festlegen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['delete_image', 'delete_doc', 'set_cover'])) {
    try {
        $mid = (int)($_POST['media_id'] ?? 0);
        if ($mid <= 0) throw new Exception("Ungültige Medien-ID.");
        
        if ($_POST['action'] === 'delete_image') {
            // Pfad holen zum Löschen der Datei
            $res = $mysqli->query("SELECT pfad, is_cover FROM wohnung_bilder WHERE id = $mid AND wohnung_id = $id");
            if ($row = $res->fetch_assoc()) {
                $file = __DIR__ . '/../' . $row['pfad'];
                if (file_exists($file)) @unlink($file);
                
                $mysqli->query("DELETE FROM wohnung_bilder WHERE id = $mid");
                
                // Falls Coverbild gelöscht wurde, anderes Bild zum Cover machen
                if ($row['is_cover']) {
                    $next = $mysqli->query("SELECT id FROM wohnung_bilder WHERE wohnung_id = $id LIMIT 1");
                    if ($nextRow = $next->fetch_assoc()) {
                        $nextId = $nextRow['id'];
                        $mysqli->query("UPDATE wohnung_bilder SET is_cover = 1 WHERE id = $nextId");
                    }
                }
            }
        } elseif ($_POST['action'] === 'delete_doc') {
            $res = $mysqli->query("SELECT pfad FROM wohnung_dokumente WHERE id = $mid AND wohnung_id = $id");
            if ($row = $res->fetch_assoc()) {
                $file = __DIR__ . '/../' . $row['pfad'];
                if (file_exists($file)) @unlink($file);
                $mysqli->query("DELETE FROM wohnung_dokumente WHERE id = $mid");
            }
        } elseif ($_POST['action'] === 'set_cover') {
            $mysqli->query("UPDATE wohnung_bilder SET is_cover = 0 WHERE wohnung_id = $id");
            $mysqli->query("UPDATE wohnung_bilder SET is_cover = 1 WHERE id = $mid AND wohnung_id = $id");
        }
        
        header("Location: wohnung_edit.php?id=$id&success=1#medien");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}// --- ACTION: Comparis Inserat importieren ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scrape_comparis') {
    // Start output buffering to capture any warnings/notices and keep JSON clean
    ob_start();
    header('Content-Type: application/json');
    @ini_set('display_errors', '0');
    @error_reporting(0);
    try {
        $url = trim($_POST['comparis_url'] ?? '');
        $html = trim($_POST['comparis_html'] ?? '');
        $is_base64 = (int)($_POST['is_base64'] ?? 0);
        
        if ($is_base64) {
            if (!empty($url)) {
                $decoded_url = base64_decode($url);
                if ($decoded_url !== false) {
                    $url = $decoded_url;
                }
            }
            if (!empty($html)) {
                $html = base64_decode($html);
            }
        }
        
        if (empty($url)) throw new Exception("Bitte geben Sie eine gültige Comparis-URL an.");
        if (strpos($url, 'comparis.ch') === false) throw new Exception("Die URL ist keine gültige Comparis-Adresse.");
        
        $method_used = "";
        $errors = [];

        if (!empty($html)) {
            $method_used = "manual_paste";
        } else {
            // Robustes, 3-stufiges Scraping per Fallback-Chain (um 403 Cloudflare Blocks zuverlässig zu umgehen)
            // Methode 1: System curl (Bester Bypass für Cloudflare per OS-Level TLS Fingerprint)
            $disabled_functions = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
            
            $cmd = "";
            if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                // Windows
                $curlPath = 'C:\\Windows\\System32\\curl.exe';
                if (!file_exists($curlPath) && file_exists('C:\\Windows\\Sysnative\\curl.exe')) {
                    $curlPath = 'C:\\Windows\\Sysnative\\curl.exe';
                }
                $cmd = escapeshellarg(file_exists($curlPath) ? $curlPath : 'curl') . ' -s -L -k -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36" -H "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7" -H "Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7" -H "Accept-Encoding: identity" ' . escapeshellarg($url);
            } else {
                // Linux / Unix (Produktionsserver)
                $cmd = 'curl -s -L -k -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36" -H "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7" -H "Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7" -H "Accept-Encoding: identity" ' . escapeshellarg($url);
            }

            $res = null;
            $exec_used = "";
            if (function_exists('shell_exec') && !in_array('shell_exec', $disabled_functions, true)) {
                $res = @shell_exec($cmd);
                $exec_used = "shell_exec";
            } elseif (function_exists('exec') && !in_array('exec', $disabled_functions, true)) {
                $output = [];
                $retval = 0;
                @exec($cmd, $output, $retval);
                if ($retval === 0 || !empty($output)) {
                    $res = implode("\n", $output);
                }
                $exec_used = "exec";
            } elseif (function_exists('system') && !in_array('system', $disabled_functions, true)) {
                ob_start();
                @system($cmd);
                $res = ob_get_clean();
                $exec_used = "system";
            } elseif (function_exists('passthru') && !in_array('passthru', $disabled_functions, true)) {
                ob_start();
                @passthru($cmd);
                $res = ob_get_clean();
                $exec_used = "passthru";
            }

            if ($res !== null && strlen($res) > 2000 && strpos($res, '403 Forbidden') === false && strpos($res, 'Cloudflare') === false && strpos($res, 'Just a moment') === false) {
                $html = $res;
                $method_used = "system_curl_" . (strncasecmp(PHP_OS, 'WIN', 3) === 0 ? 'win' : 'linux') . "_" . $exec_used;
            } else {
                $errors[] = "System curl über " . ($exec_used ?: 'keine Ausführungsfunktion verfügbar') . " fehlgeschlagen (" . strlen((string)$res) . " Bytes erhalten)";
            }

            // Methode 2: PHP cURL mit echten Browser-Headern (1. Fallback)
            if (empty($html)) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($ch, CURLOPT_ENCODING, ''); // Sends correct Accept-Encoding header and decodes content automatically
                
                if (defined('CURL_HTTP_VERSION_2_0')) {
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
                }

                $headers = [
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36",
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
                    "Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7",
                    "Accept-Encoding: gzip, deflate, br",
                    "Cache-Control: max-age=0",
                    "Connection: keep-alive",
                    "Upgrade-Insecure-Requests: 1",
                    "Sec-Ch-Ua: \"Not_A Brand\";v=\"8\", \"Chromium\";v=\"125\", \"Google Chrome\";v=\"125\"",
                    "Sec-Ch-Ua-Mobile: ?0",
                    "Sec-Ch-Ua-Platform: \"Windows\"",
                    "Sec-Fetch-Dest: document",
                    "Sec-Fetch-Mode: navigate",
                    "Sec-Fetch-Site: none",
                    "Sec-Fetch-User: ?1"
                ];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);

                if ($res !== false && $httpCode === 200 && strlen($res) > 2000 && strpos($res, '403 Forbidden') === false && strpos($res, 'Cloudflare') === false && strpos($res, 'Just a moment') === false) {
                    $html = $res;
                    $method_used = "php_curl";
                } else {
                    $errors[] = "PHP cURL fehlgeschlagen (Code: $httpCode, Fehler: " . ($err ?: 'Cloudflare / 403 Block') . ")";
                }
            }

            // Methode 3: file_get_contents mit echten Browser-Headern (2. Fallback)
            if (empty($html)) {
                $ctx = @stream_context_create([
                    "http" => [
                        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36\r\n" .
                                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\r\n" .
                                    "Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7\r\n" .
                                    "Accept-Encoding: identity\r\n",
                        "timeout" => 15,
                        "ignore_errors" => true
                    ],
                    "ssl" => [
                        "verify_peer" => false,
                        "verify_peer_name" => false
                    ]
                ]);
                $res = @file_get_contents($url, false, $ctx);
                if ($res !== false && strlen($res) > 2000 && strpos($res, '403 Forbidden') === false && strpos($res, 'Cloudflare') === false && strpos($res, 'Just a moment') === false) {
                    $html = $res;
                    $method_used = "file_get_contents";
                } else {
                    $errors[] = "file_get_contents blockiert (" . strlen((string)$res) . " Bytes erhalten)";
                }
            }

            if (empty($html)) {
                $diag = "OS: " . PHP_OS . " | SAPI: " . php_sapi_name() . " | shell_exec: " . (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions')))) ? 'enabled' : 'disabled');
                throw new Exception("Die Seite konnte von Comparis nicht geladen werden (Cloudflare blockiert). Details: " . implode(" | ", $errors) . " | Diag: " . $diag);
            }
        }

        // --- Parsing Strategy ---
        $parsed_successfully = false;
        $marketing_titel = "";
        $marketing_beschreibung = "";
        $soll_netto = 0.0;
        $discovered_images = [];

        // Extraktion von strukturierten Attributen
        $balkon = 0;
        $wintergarten = 0;
        $terrasse = 0;
        $garten = 0;
        $gartensitzplatz = 0;
        $spielplatz = 0;
        $gemeinschaftsraum = 0;
        $lift = 0;
        $keller_vorhanden = 0;
        $haustiere_erlaubt = 0;
        $minergie = 0;
        $glasfaser = 0;
        $zimmer = null;
        $flaeche = null;
        $baujahr = 0;
        $etage = null;

        // Versuche __NEXT_DATA__ JSON zu parsen (extrem stabil und strukturiert)
        if (preg_match('/<script id="__NEXT_DATA__" type="application\/json">(.*?)<\/script>/s', $html, $mNext)) {
            $nextData = json_decode($mNext[1], true);
            $ad = $nextData['props']['pageProps']['ad'] ?? null;
            if ($ad) {
                $marketing_titel = trim($ad['Title'] ?? '');
                $marketing_beschreibung = trim($ad['Remarks'] ?? '');
                if (isset($ad['Price'])) {
                    $soll_netto = (float)$ad['Price'];
                }
                if (!empty($ad['ImageUrls']) && is_array($ad['ImageUrls'])) {
                    foreach ($ad['ImageUrls'] as $imgUrl) {
                        if (!empty($imgUrl) && is_string($imgUrl)) {
                            $discovered_images[] = trim($imgUrl);
                        }
                    }
                }

                // Extrahierte Werte belegen
                if (isset($ad['NumRooms'])) $zimmer = (float)$ad['NumRooms'];
                if (isset($ad['Area'])) $flaeche = (float)$ad['Area'];
                if (isset($ad['ConstructionYear'])) $baujahr = (int)$ad['ConstructionYear'];
                if (isset($ad['Floor'])) $etage = (string)$ad['Floor'];

                // Features auslesen
                $features = $ad['Features'] ?? [];
                foreach ($features as $f) {
                    $key = $f['Key'] ?? '';
                    $val = (int)($f['Value'] ?? 0);
                    if ($key === 'HasTerraces' && $val) $terrasse = 1;
                    if ($key === 'HasBalkons' && $val) $balkon = 1;
                    if ($key === 'HasLift' && $val) $lift = 1;
                    if ($key === 'HasCellar' && $val) $keller_vorhanden = 1;
                }
                
                // Attribute auslesen
                $attributes = $ad['Attributes'] ?? [];
                foreach ($attributes as $a) {
                    $key = $a['Key'] ?? '';
                    $val = (int)($a['Value'] ?? 0);
                    if (($key === 'IsChildFriendly' || $key === 'IsChildfriendly') && $val) $spielplatz = 1;
                }

                if (!empty($marketing_titel) || !empty($marketing_beschreibung)) {
                    $parsed_successfully = true;
                }
            }
        }

        // Falls JSON-Parsing fehlschlägt, verwende Fallback-Regex
        if (!$parsed_successfully) {
            // 1) Titel auslesen
            if (preg_match('/<meta[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
                $marketing_titel = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                $marketing_titel = trim(str_ireplace(' - comparis.ch', '', $marketing_titel));
            } elseif (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
                $marketing_titel = trim(str_ireplace(' - comparis.ch', '', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')));
            }

            // 2) Beschreibung auslesen
            if (preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
                $marketing_beschreibung = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                $marketing_beschreibung = trim(preg_replace('/\s+/', ' ', $marketing_beschreibung));
            }

            // 3) Soll-Mietzins suchen und parsen
            if (preg_match('/(?:CHF|Miete|Preis)\s*([0-9\'’`.,\s]+)/i', $marketing_beschreibung, $mPrice)) {
                $p = preg_replace('/[^\d]/', '', $mPrice[1]);
                if (!empty($p)) $soll_netto = (float)$p;
            }

            // 4) Bilder auslesen
            if (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $mImg)) {
                $discovered_images[] = html_entity_decode($mImg[1], ENT_QUOTES, 'UTF-8');
            }

            // Suche nach allen Bild-URLs in der Seite
            preg_match_all('/https?:\/\/[^"\']+\.(?:jpg|jpeg|png|webp)/i', $html, $mAllImgs);
            if (!empty($mAllImgs[0])) {
                foreach ($mAllImgs[0] as $imgUrl) {
                    if (strpos($imgUrl, 'comparis') !== false || strpos($imgUrl, 'cloudfront') !== false || strpos($imgUrl, 'immomig') !== false) {
                        if (strpos($imgUrl, 'logo') === false && strpos($imgUrl, 'icon') === false && strpos($imgUrl, 'avatar') === false && strpos($imgUrl, 'marker') === false && strpos($imgUrl, 'favicon') === false) {
                            $discovered_images[] = $imgUrl;
                        }
                    }
                }
            }
            $discovered_images = array_unique($discovered_images);
        }

        // Intelligenter Text-Scan für zusätzliche Ausstattungsmerkmale (Sowohl bei JSON als auch bei Regex)
        $descLower = mb_strtolower($marketing_beschreibung);
        
        if (strpos($descLower, 'balkon') !== false) $balkon = 1;
        if (strpos($descLower, 'wintergarten') !== false) $wintergarten = 1;
        if (strpos($descLower, 'terrasse') !== false) $terrasse = 1;
        if (strpos($descLower, 'gartensitzplatz') !== false) {
            $gartensitzplatz = 1;
        } elseif (strpos($descLower, 'garten') !== false) {
            $garten = 1;
        }
        if (strpos($descLower, 'spielplatz') !== false) $spielplatz = 1;
        if (strpos($descLower, 'gemeinschaftsraum') !== false) $gemeinschaftsraum = 1;
        if (strpos($descLower, 'lift') !== false) $lift = 1;
        if (strpos($descLower, 'keller') !== false) $keller_vorhanden = 1;
        if (strpos($descLower, 'haustier') !== false || strpos($descLower, 'hunde') !== false || strpos($descLower, 'katzen') !== false) $haustiere_erlaubt = 1;
        if (strpos($descLower, 'minergie') !== false) $minergie = 1;
        if (strpos($descLower, 'glasfaser') !== false) $glasfaser = 1;

        // Update database with scraped marketing text and parsed features
        $stmt = $mysqli->prepare("UPDATE wohnungen SET 
            marketing_titel = ?, 
            marketing_beschreibung = ?, 
            link_comparis = ?,
            balkon = ?,
            wintergarten = ?,
            terrasse = ?,
            garten = ?,
            gartensitzplatz = ?,
            spielplatz = ?,
            gemeinschaftsraum = ?,
            lift = ?,
            keller_vorhanden = ?,
            haustiere_erlaubt = ?,
            minergie = ?,
            glasfaser = ?,
            zimmer = COALESCE(?, zimmer),
            flaeche = COALESCE(?, flaeche),
            baujahr = IF(baujahr IS NULL OR baujahr = 0, ?, baujahr),
            etage = COALESCE(?, etage),
            mietzins_netto_soll = IF(mietzins_netto_soll IS NULL OR mietzins_netto_soll = 0, ?, mietzins_netto_soll)
            WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Datenbank-Fehler beim Update-Prepare: " . $mysqli->error);
        }
        
        $stmt->bind_param("sssiiiiiiiiiiiiddisdi", 
            $marketing_titel, 
            $marketing_beschreibung, 
            $url,
            $balkon,
            $wintergarten,
            $terrasse,
            $garten,
            $gartensitzplatz,
            $spielplatz,
            $gemeinschaftsraum,
            $lift,
            $keller_vorhanden,
            $haustiere_erlaubt,
            $minergie,
            $glasfaser,
            $zimmer,
            $flaeche,
            $baujahr,
            $etage,
            $soll_netto,
            $id
        );
        $stmt->execute();
        $stmt->close();

        // Bilder herunterladen und importieren (max. 12 Bilder)
        $dir = __DIR__ . '/../uploads/wohnungen/bilder';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $downloaded = 0;
        foreach ($discovered_images as $imgUrl) {
            if ($downloaded >= 12) break;

            $ctx = stream_context_create([
                "http" => [
                    "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36\r\n"
                ],
                "ssl" => [
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ]
            ]);
            $imgData = @file_get_contents($imgUrl, false, $ctx);
            if ($imgData !== false && strlen($imgData) > 5000) {
                $ext = 'jpg';
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->buffer($imgData);
                    if ($mime === 'image/png') $ext = 'png';
                    elseif ($mime === 'image/gif') $ext = 'gif';
                    elseif ($mime === 'image/webp') $ext = 'webp';
                } else {
                    $imgInfo = @getimagesizefromstring($imgData);
                    if ($imgInfo && isset($imgInfo['mime'])) {
                        $mime = $imgInfo['mime'];
                        if ($mime === 'image/png') $ext = 'png';
                        elseif ($mime === 'image/gif') $ext = 'gif';
                        elseif ($mime === 'image/webp') $ext = 'webp';
                    } else {
                        $pathExt = strtolower(pathinfo(parse_url($imgUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                        if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                            $ext = ($pathExt === 'jpeg') ? 'jpg' : $pathExt;
                        }
                    }
                }

                $filename = 'comparis_' . uniqid() . '.' . $ext;
                $filepath = $dir . '/' . $filename;
                file_put_contents($filepath, $imgData);

                $dbPath = 'uploads/wohnungen/bilder/' . $filename;

                $checkC = $mysqli->query("SELECT id FROM wohnung_bilder WHERE wohnung_id = $id LIMIT 1");
                $is_cover = ($checkC && $checkC->num_rows === 0) ? 1 : 0;

                $imgTitel = "Comparis Bild " . ($downloaded + 1);

                $stmtM = $mysqli->prepare("INSERT INTO wohnung_bilder (wohnung_id, pfad, is_cover, titel, sort_order) VALUES (?, ?, ?, ?, ?)");
                if (!$stmtM) {
                    throw new Exception("Datenbank-Fehler beim Bild-Import-Prepare: " . $mysqli->error);
                }
                $stmtM->bind_param("isisi", $id, $dbPath, $is_cover, $imgTitel, $downloaded);
                $stmtM->execute();
                $stmtM->close();

                $downloaded++;
            }
        }

        // Clean buffer before echoing JSON to ensure there is no warning prepended
        if (ob_get_length()) {
            ob_clean();
        }

        echo json_encode([
            'success' => true,
            'message' => "Daten & $downloaded Bilder erfolgreich importiert!",
            'titel' => $marketing_titel,
            'beschreibung' => $marketing_beschreibung,
            'bilder_anzahl' => $downloaded
        ]);
        exit;
    } catch (Throwable $e) {
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// --- ACTION: Marketing Daten speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_marketing') {
    try {
        $marketing_titel = trim($_POST['marketing_titel'] ?? '');
        $marketing_beschreibung = trim($_POST['marketing_beschreibung'] ?? '');
        $marketing_highlight1 = trim($_POST['marketing_highlight1'] ?? '');
        $marketing_highlight2 = trim($_POST['marketing_highlight2'] ?? '');
        $marketing_highlight3 = trim($_POST['marketing_highlight3'] ?? '');
        $marketing_status = trim($_POST['marketing_status'] ?? 'entwurf');
        $link_homegate = trim($_POST['link_homegate'] ?? '');
        $link_immoscout = trim($_POST['link_immoscout'] ?? '');
        $link_flatfox = trim($_POST['link_flatfox'] ?? '');
        $link_comparis = trim($_POST['link_comparis'] ?? '');

        $stmt = $mysqli->prepare("UPDATE wohnungen SET 
            marketing_titel = ?, 
            marketing_beschreibung = ?, 
            marketing_highlight1 = ?, 
            marketing_highlight2 = ?, 
            marketing_highlight3 = ?, 
            marketing_status = ?, 
            link_homegate = ?, 
            link_immoscout = ?, 
            link_flatfox = ?, 
            link_comparis = ? 
            WHERE id = ?");
        $stmt->bind_param("ssssssssssi", 
            $marketing_titel, 
            $marketing_beschreibung, 
            $marketing_highlight1, 
            $marketing_highlight2, 
            $marketing_highlight3, 
            $marketing_status, 
            $link_homegate, 
            $link_immoscout, 
            $link_flatfox, 
            $link_comparis, 
            $id
        );
        $stmt->execute();
        $stmt->close();

        header("Location: wohnung_edit.php?id=$id&success=1#medien");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// --- ACTION: Medien Meta-Daten (Titel, Kategorie, Sortierung) über AJAX/POST updaten ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_media_meta') {
    try {
        $type = $_POST['meta_type'] ?? ''; // 'image' or 'document'
        $mid = (int)($_POST['media_id'] ?? 0);
        if ($mid <= 0) throw new Exception("Ungültige Medien-ID.");

        if ($type === 'image') {
            $titel = trim($_POST['titel'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            $stmt = $mysqli->prepare("UPDATE wohnung_bilder SET titel = ?, sort_order = ? WHERE id = ? AND wohnung_id = ?");
            $stmt->bind_param("siii", $titel, $sort, $mid, $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($type === 'document') {
            $titel = trim($_POST['titel'] ?? '');
            $kat = trim($_POST['kategorie'] ?? 'sonstiges');
            $stmt = $mysqli->prepare("UPDATE wohnung_dokumente SET name = ?, kategorie = ? WHERE id = ? AND wohnung_id = ?");
            $stmt->bind_param("ssii", $titel, $kat, $mid, $id);
            $stmt->execute();
            $stmt->close();
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => true]);
            exit;
        }
        header("Location: wohnung_edit.php?id=$id&success=1#medien");
        exit;
    } catch (Throwable $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $error = $e->getMessage();
    }
}

// --- SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_all') {
    try {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new Exception("Bezeichnung ist erforderlich.");

        // SQL Update vorbereiten
        $updates = [
            "name = ?", "etage = ?", "flaeche = ?", "zimmer = ?", "badezimmer = ?",
            "letzter_renovation = ?", "gesamtzustand = ?", 
            "balkon = ?", "wintergarten = ?", "terrasse = ?",
            "garten = ?", "gartensitzplatz = ?", "spielplatz = ?", "gemeinschaftsraum = ?",
            "baujahr = ?", "heizungsart = ?", "bodenbelag = ?", "kueche_details = ?", "bad_details = ?",
            "lift = ?", "barrierefrei = ?", "haustiere_erlaubt = ?", "waschmaschine = ?", "keller_vorhanden = ?",
            "parkplatz = ?", "minergie = ?", "glasfaser = ?", "besonnerung = ?", "aussicht = ?", "laermpegel = ?",
            "ausstattung_details = ?", "available_from = ?", "typ_id = ?"
        ];
        
        $renov = ($_POST['letzter_renovation'] ?? '') ?: null;
        $params = [
            $name, 
            $_POST['etage'] ?? '', 
            (float)($_POST['flaeche'] ?? 0), 
            (float)($_POST['zimmer'] ?? 0), 
            (float)($_POST['badezimmer'] ?? 0),
            $renov, 
            $_POST['gesamtzustand'] ?? '', 
            isset($_POST['balkon'])?1:0, 
            isset($_POST['wintergarten'])?1:0, 
            isset($_POST['terrasse'])?1:0,
            isset($_POST['garten'])?1:0,
            isset($_POST['gartensitzplatz'])?1:0,
            isset($_POST['spielplatz'])?1:0,
            isset($_POST['gemeinschaftsraum'])?1:0,
            (int)($_POST['baujahr'] ?? 0), 
            $_POST['heizungsart'] ?? '', 
            $_POST['bodenbelag'] ?? '', 
            $_POST['kueche_details'] ?? '', 
            $_POST['bad_details'] ?? '',
            isset($_POST['lift'])?1:0, 
            isset($_POST['barrierefrei'])?1:0, 
            isset($_POST['haustiere_erlaubt'])?1:0, 
            $_POST['waschmaschine'] ?? '', 
            isset($_POST['keller_vorhanden'])?1:0,
            $_POST['parkplatz'] ?? '', 
            isset($_POST['minergie'])?1:0, 
            isset($_POST['glasfaser'])?1:0, 
            $_POST['besonnerung'] ?? '', 
            $_POST['aussicht'] ?? '', 
            $_POST['laermpegel'] ?? '',
            $_POST['ausstattung_details'] ?? '', 
            $_POST['available_from'] ?? '',
            (int)($_POST['typ_id'] ?? 0) ?: null
        ];

        $sql = "UPDATE wohnungen SET " . implode(", ", $updates) . " WHERE id = $id";
        $stmt = $mysqli->prepare($sql);
        $types = "ssdddssiiiiiiiissssiiisisiisssssi"; 
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        // Mieter & Vertragsdaten (Ist) speichern
        $mieter_id = (int)($_POST['mieter_benutzer_id'] ?? 0);
        $netto_ist = (float)($_POST['mietzins_netto_ist'] ?? 0);
        $nk_ist = (float)($_POST['mietzins_nk_ist'] ?? 0);
        $beginn = $_POST['einzug_datum'] ?? date('Y-m-d');
        $action_mieter = $_POST['action_mieter'] ?? ''; // 'auszug' or ''

        if ($action_mieter === 'auszug' || $mieter_id === 0) {
            // Aktuellen Mieter in Historie verschieben
            $mysqli->query("UPDATE wohnung_mieter SET status = 'historisch', enddatum = CURRENT_DATE WHERE wohnung_id = $id AND status = 'aktiv'");
        } else {
            // Hat die Wohnung bereits einen aktiven Mieter?
            $checkActive = $mysqli->query("SELECT id, benutzer_id FROM wohnung_mieter WHERE wohnung_id = $id AND status = 'aktiv' LIMIT 1");
            if ($checkActive && $checkActive->num_rows > 0) {
                $activeRow = $checkActive->fetch_assoc();
                $activeWmid = $activeRow['id'];
                $oldBenutzerId = $activeRow['benutzer_id'];
                
                if ((int)$oldBenutzerId === $mieter_id) {
                    // Gleicher Mieter -> einfach Werte updaten
                    $stmtM = $mysqli->prepare("UPDATE wohnung_mieter SET mietzins_netto = ?, nk_akonto = ?, startdatum = ? WHERE id = ?");
                    $stmtM->bind_param("ddsi", $netto_ist, $nk_ist, $beginn, $activeWmid);
                    $stmtM->execute();
                    $stmtM->close();
                } else {
                    // Anderer Mieter -> alten Mieter historisch machen
                    $mysqli->query("UPDATE wohnung_mieter SET status = 'historisch', enddatum = '$beginn' WHERE id = $activeWmid");
                    
                    // Neuen Mieter anlegen
                    $uNameRes = $mysqli->query("SELECT name FROM benutzer WHERE id = $mieter_id");
                    $userName = $uNameRes->fetch_assoc()['name'] ?? "Mieter_$mieter_id";
                    
                    $qi = $mysqli->prepare("INSERT INTO wohnung_mieter (wohnung_id, benutzer_id, mieter_name, mietzins_netto, nk_akonto, rolle, startdatum, status) 
                                            VALUES (?, ?, ?, ?, ?, 'mieter', ?, 'aktiv')");
                    $qi->bind_param("iisdds", $id, $mieter_id, $userName, $netto_ist, $nk_ist, $beginn);
                    $qi->execute();
                    $qi->close();
                    
                    // Benutzer-Phasen Update
                    $mysqli->query("UPDATE benutzer SET mieter_phase = 'mieter' WHERE id = $mieter_id");
                }
            } else {
                // Kein aktiver Mieter bisher -> neu anlegen
                $uNameRes = $mysqli->query("SELECT name FROM benutzer WHERE id = $mieter_id");
                $userName = $uNameRes->fetch_assoc()['name'] ?? "Mieter_$mieter_id";
                
                $qi = $mysqli->prepare("INSERT INTO wohnung_mieter (wohnung_id, benutzer_id, mieter_name, mietzins_netto, nk_akonto, rolle, startdatum, status) 
                                        VALUES (?, ?, ?, ?, ?, 'mieter', ?, 'aktiv')");
                $qi->bind_param("iisdds", $id, $mieter_id, $userName, $netto_ist, $nk_ist, $beginn);
                $qi->execute();
                $qi->close();
                
                // Benutzer-Phasen Update
                $mysqli->query("UPDATE benutzer SET mieter_phase = 'mieter' WHERE id = $mieter_id");
            }
        }

        // Neuen Mietzins-Historie-Eintrag hinzufügen falls angegeben
        $h_gilt_ab = $_POST['h_gilt_ab'] ?? '';
        $h_netto = $_POST['h_netto'] ?? '';
        $h_nk = $_POST['h_nk'] ?? '';
        $h_typ = $_POST['h_typ'] ?? 'soll';
        $h_bemerkung = trim($_POST['h_bemerkung'] ?? '');

        if ($h_gilt_ab !== '' && $h_netto !== '') {
            $h_netto_val = (float)$h_netto;
            $h_nk_val = (float)$h_nk;
            $stmtH = $mysqli->prepare("INSERT INTO wohnung_mietzins_historie (wohnung_id, gilt_ab, mietzins_netto, mietzins_nk, typ, bemerkung) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtH->bind_param("isddss", $id, $h_gilt_ab, $h_netto_val, $h_nk_val, $h_typ, $h_bemerkung);
            $stmtH->execute();
            $stmtH->close();

            // Automatische Synchronisation mit den Hauptfeldern (Soll oder Ist)
            if (in_array($h_typ, ['soll', 'erhoehung', 'reduktion', 'anpassung'], true)) {
                // Update Soll-Mietzins in wohnungen
                $stmtU = $mysqli->prepare("UPDATE wohnungen SET mietzins_netto_soll = ?, mietzins_nk_soll = ? WHERE id = ?");
                $stmtU->bind_param("ddi", $h_netto_val, $h_nk_val, $id);
                $stmtU->execute();
                $stmtU->close();
                // Werte für die aktuelle Anzeige überschreiben
                $wohnung['mietzins_netto_soll'] = $h_netto_val;
                $wohnung['mietzins_nk_soll'] = $h_nk_val;
            } elseif ($h_typ === 'ist') {
                // Update Ist-Mietzins in wohnung_mieter (falls aktiver Mieter da)
                $stmtU = $mysqli->prepare("UPDATE wohnung_mieter SET mietzins_netto = ?, nk_akonto = ? WHERE wohnung_id = ? AND status = 'aktiv'");
                $stmtU->bind_param("ddi", $h_netto_val, $h_nk_val, $id);
                $stmtU->execute();
                $stmtU->close();
            }
        }
        
        header("Location: wohnung_edit.php?id=$id&success=1" . (isset($_POST['_hash']) ? "#".$_POST['_hash'] : ""));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = "Wohnung bearbeiten: " . h($wohnung['name']);
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
:root {
    --primary: #3b82f6;
    --primary-dark: #2563eb;
    --slate-50: #f8fafc;
    --slate-100: #f1f5f9;
    --slate-200: #e2e8f0;
    --slate-300: #cbd5e1;
    --slate-400: #94a3b8;
    --slate-500: #64748b;
    --slate-600: #475569;
    --slate-700: #334155;
    --slate-800: #1e293b;
    --slate-900: #0f172a;
    --glass: rgba(255, 255, 255, 0.85);
    --shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
}

body {
    background-color: #f1f5f9;
    background-image: radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.05) 0px, transparent 50%), radial-gradient(at 100% 0%, rgba(59, 130, 246, 0.05) 0px, transparent 50%);
    min-height: 100vh;
}

.page-header {
    margin-bottom: 30px;
    padding: 20px 0;
}

.premium-title {
    font-size: 32px;
    font-weight: 900;
    color: var(--slate-900);
    letter-spacing: -1px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.btn-back {
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    background: white;
    border: 1.5px solid var(--slate-200);
    border-radius: 12px;
    color: var(--slate-600);
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
    margin-bottom: 15px;
    transition: 0.2s;
}

.btn-back:hover {
    border-color: var(--primary);
    color: var(--primary);
    transform: translateX(-3px);
}

.glass-card {
    background: var(--glass);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 30px;
    box-shadow: var(--shadow);
    overflow: hidden;
}

.main-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    min-height: 700px;
}

.sidebar-nav {
    background: rgba(241, 245, 249, 0.5);
    border-right: 1px solid var(--slate-200);
    padding: 30px 15px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-item {
    padding: 14px 20px;
    border-radius: 14px;
    color: var(--slate-600);
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    transition: 0.2s;
    display: flex;
    align-items: center;
    gap: 12px;
}

.nav-item:hover {
    background: rgba(59, 130, 246, 0.08);
    color: var(--primary);
}

.nav-item.active {
    background: var(--primary);
    color: white;
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
}

.content-panel {
    padding: 40px;
    position: relative;
}

.form-section {
    display: none;
    animation: fadeIn 0.3s ease-out;
}

.form-section.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.section-title {
    font-size: 20px;
    font-weight: 800;
    color: var(--slate-800);
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.input-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.input-group label {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: var(--slate-500);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.form-control {
    width: 100%;
    padding: 14px 18px;
    background: white;
    border: 2px solid var(--slate-200);
    border-radius: 14px;
    font-size: 15px;
    font-weight: 600;
    color: var(--slate-800);
    transition: 0.2s;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
}

.textarea-custom {
    min-height: 120px;
    resize: vertical;
}

.checkbox-tile-group {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
}

.checkbox-tile {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: white;
    border: 2px solid var(--slate-200);
    border-radius: 12px;
    cursor: pointer;
    transition: 0.2s;
    font-weight: 700;
    font-size: 13px;
    color: var(--slate-600);
}

.checkbox-tile:hover {
    border-color: var(--primary);
}

.checkbox-tile input {
    width: 18px;
    height: 18px;
    accent-color: var(--primary);
}

.checkbox-tile:has(input:checked) {
    background: rgba(59, 130, 246, 0.05);
    border-color: var(--primary);
    color: var(--primary);
}

.badge {
    background: var(--slate-200);
    color: var(--slate-700);
    padding: 4px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
}

.btn-blue {
    background: var(--primary);
    color: white;
    border: none;
    transition: 0.2s;
}

.btn-blue:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(59, 130, 246, 0.3);
}

.btn-text-danger {
    background: none;
    border: none;
    color: #ef4444;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
}

    /* Navigation Top Bar */
    .unit-nav-bar {
        background: white;
        padding: 15px 30px;
        border-bottom: 2px solid var(--slate-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    .nav-group { display: flex; align-items: center; gap: 15px; }
    .nav-btn {
        padding: 10px 20px;
        background: var(--slate-50);
        border: 1.5px solid var(--slate-200);
        border-radius: 12px;
        color: var(--slate-600);
        font-weight: 700;
        text-decoration: none;
        font-size: 13px;
        transition: 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .nav-btn:hover { background: white; border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
    .nav-btn.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }
    
    .unit-selector {
        padding: 10px 40px 10px 20px;
        border-radius: 12px;
        border: 2px solid var(--primary);
        font-weight: 800;
        color: var(--primary);
        background: white url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%233b82f6' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C2.185 5.355 2.401 5 2.736 5h9.528c.335 0 .551.355.285.658l-4.796 5.482a.5.5 0 0 1-.726 0z'/%3E%3C/svg%3E") no-repeat right 15px center;
        appearance: none;
        cursor: pointer;
        font-size: 15px;
        min-width: 220px;
        text-align: center;
    }

    /* Premium Medien & Marketing Expansion Styles */
    .marketing-layout {
        display: grid;
        grid-template-columns: 1.7fr 1fr;
        gap: 30px;
        align-items: start;
    }
    @media (max-width: 1024px) {
        .marketing-layout {
            grid-template-columns: 1fr;
        }
    }
    .media-card {
        background: #ffffff;
        border: 1.5px solid var(--slate-200);
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -1px rgba(0,0,0,0.01);
        margin-bottom: 25px;
        transition: box-shadow 0.2s;
    }
    .media-card:hover {
        box-shadow: 0 10px 15px -3px rgba(0,0,0,0.04), 0 4px 6px -2px rgba(0,0,0,0.02);
    }
    .media-card-title {
        font-size: 16px;
        font-weight: 800;
        color: var(--slate-800);
        margin: 0 0 20px 0;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1.5px solid var(--slate-100);
        padding-bottom: 12px;
    }
    .marketing-input {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid var(--slate-200);
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        color: var(--slate-800);
        background: var(--slate-50);
        transition: all 0.2s;
        box-sizing: border-box;
    }
    .marketing-input:focus {
        outline: none;
        border-color: var(--primary);
        background: white;
        box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
    }
    .marketing-label {
        font-size: 12px;
        font-weight: 800;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: block;
        margin-bottom: 8px;
    }
    .portal-link-group {
        display: grid;
        grid-template-columns: auto 1fr;
        align-items: center;
        border: 1.5px solid var(--slate-200);
        border-radius: 12px;
        background: var(--slate-50);
        overflow: hidden;
        margin-bottom: 15px;
    }
    .portal-link-label {
        padding: 12px 16px;
        font-size: 12px;
        font-weight: 800;
        color: white;
        background: var(--slate-700);
        min-width: 90px;
        text-align: center;
    }
    .portal-link-group.homegate .portal-link-label { background: #e05e00; }
    .portal-link-group.immoscout .portal-link-label { background: #002f6c; }
    .portal-link-group.flatfox .portal-link-label { background: #00b0a8; }
    .portal-link-group.comparis .portal-link-label { background: #f59e0b; }
    
    .portal-link-group input {
        border: none;
        background: transparent;
        padding: 12px 16px;
        font-size: 13px;
        color: var(--slate-800);
        width: 100%;
        box-sizing: border-box;
    }
    .portal-link-group input:focus {
        outline: none;
    }
    
    /* Inserat-Vorschau Card */
    .preview-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        border: 2px solid var(--slate-200);
        border-radius: 24px;
        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05), 0 10px 10px -5px rgba(0,0,0,0.02);
        overflow: hidden;
        position: sticky;
        top: 20px;
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s;
    }
    .preview-card:hover {
        transform: translateY(-5px) scale(1.01);
        box-shadow: 0 25px 30px -5px rgba(59, 130, 246, 0.1), 0 15px 15px -5px rgba(0,0,0,0.04);
        border-color: var(--primary);
    }
    .preview-img-wrap {
        position: relative;
        padding-top: 56.25%;
        background: var(--slate-100);
        overflow: hidden;
    }
    .preview-img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }
    .preview-card:hover .preview-img {
        transform: scale(1.05);
    }
    .preview-status {
        position: absolute;
        top: 15px;
        right: 15px;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        color: white;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .preview-status.entwurf { background: #eab308; }
    .preview-status.aktiv { background: #22c55e; }
    .preview-status.pausiert { background: #64748b; }

    .preview-badge-netto {
        position: absolute;
        bottom: 15px;
        left: 15px;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(8px);
        color: white;
        padding: 6px 14px;
        border-radius: 10px;
        font-size: 14px;
        font-weight: 800;
        border: 1px solid rgba(255,255,255,0.2);
    }
    
    .preview-content {
        padding: 24px;
    }
    .preview-title-text {
        font-size: 18px;
        font-weight: 800;
        color: var(--slate-800);
        margin: 0 0 10px 0;
        line-height: 1.3;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 46px;
    }
    .preview-meta-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        text-align: center;
        border-top: 1.5px solid var(--slate-100);
        border-bottom: 1.5px solid var(--slate-100);
        padding: 12px 0;
        margin-bottom: 15px;
    }
    .preview-meta-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .preview-meta-val {
        font-size: 15px;
        font-weight: 800;
        color: var(--slate-800);
    }
    .preview-meta-lbl {
        font-size: 10px;
        font-weight: 800;
        color: var(--slate-400);
        text-transform: uppercase;
    }
    
    .preview-desc {
        font-size: 13px;
        color: var(--slate-500);
        line-height: 1.5;
        margin: 0 0 15px 0;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 58px;
    }
    .preview-highlights-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 10px;
    }
    .preview-highlight-pill {
        background: rgba(59, 130, 246, 0.08);
        color: var(--primary);
        font-size: 11px;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 8px;
        border: 1px solid rgba(59, 130, 246, 0.15);
    }
    
    /* Inline Meta Inputs for Gallery items */
    .media-meta-input {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid var(--slate-200);
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        background: var(--slate-50);
        color: var(--slate-800);
        box-sizing: border-box;
        margin-top: 8px;
        transition: 0.15s;
    }
    .media-meta-input:focus {
        outline: none;
        border-color: var(--primary);
        background: white;
    }
</style>

<!-- Navigation Bar Top -->
<div class="unit-nav-bar">
    <div class="nav-group">
        <a href="mieterspiegel.php?projekt_id=<?= (int)($wohnung['projekt_id'] ?? 0) ?>" class="nav-btn" style="background: var(--slate-600); color: white; border: none;">
            ⬅ Zurück zum Mieterspiegel
        </a>
    </div>

    <div class="nav-group">
        <a href="<?= $prev_id ? "?id=$prev_id" : "#" ?>" class="nav-btn <?= !$prev_id ? 'disabled' : '' ?>">
            ⬅ Vorherige
        </a>

        <div style="position: relative;">
            <select class="unit-selector" onchange="window.location.href='?id=' + this.value">
                <?php foreach($siblings as $sib): ?>
                    <option value="<?= $sib['id'] ?>" <?= $sib['id'] == $id ? 'selected' : '' ?>>
                        🏠 <?= h($sib['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <a href="<?= $next_id ? "?id=$next_id" : "#" ?>" class="nav-btn <?= !$next_id ? 'disabled' : '' ?>">
            Nächste ➡
        </a>
    </div>

    <div class="nav-group">
        <button type="button" onclick="document.getElementById('masterForm').submit()" class="btn btn-blue" style="margin:0; border-radius:12px; height:45px; padding: 0 25px; font-weight: 800;">
            💾 Speichern
        </button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">✅ Änderungen erfolgreich gespeichert.</div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger" style="background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; padding:15px; border-radius:12px; margin-bottom:20px; font-weight:700; max-width:1200px; margin-left:auto; margin-right:auto;">
        ❌ Fehler beim Speichern: <?= h($error) ?>
    </div>
<?php endif; ?>

<div class="edit-container glass-card">
    <div class="main-grid">
        <aside class="sidebar-nav">
            <div class="nav-item active" onclick="showSection('basis', this)">💎 Basisdaten</div>
            <div class="nav-item" onclick="showSection('ausstattung', this)">🛋️ Ausstattung & Zustand</div>
            <div class="nav-item" onclick="showSection('finanzen', this)">💰 Finanzen & Miete</div>
            <div class="nav-item" onclick="showSection('technik', this)">⚙️ Technik & Gebäude</div>
            <div class="nav-item" onclick="showSection('umgebung', this)">🌳 Umgebung & Lage</div>
            <div class="nav-item" id="nav-item-rooms" onclick="showSection('rooms', this)" style="display:flex; justify-content:space-between; align-items:center;">
                <span>🚪 Räume</span>
                <span id="sidebar-room-summary" style="font-size:12px; opacity:0.7; font-weight:400; letter-spacing:1px;"></span>
            </div>
            <div class="nav-item" onclick="showSection('medien', this)">📸 Medien & Marketing</div>
        </aside>

        <form method="POST" id="masterForm">
            <input type="hidden" name="action" value="save_all">
            <input type="hidden" name="_hash" id="form_hash_field" value="basis">
            
            <div class="content-panel">
                
                <!-- BASISDATEN -->
                <div id="section-basis" class="form-section active">
                    <h3 class="section-title">💎 Basis-Informationen</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Bezeichnung*</label>
                            <input type="text" name="name" class="form-control" value="<?= h($wohnung['name']) ?>" required placeholder="z.B. Garten, Gemeinschaftsraum...">
                        </div>
                        <div class="input-group">
                            <label>Einheit-Typ</label>
                            <select name="typ_id" class="form-control">
                                <option value="0" <?= (int)$wohnung['typ_id']==0 ? 'selected':'' ?>>🏠 Standard (Wohnung)</option>
                                <?php 
                                  $typenRes = $mysqli->query("SELECT id, name, icon FROM einheit_typen ORDER BY name");
                                  while($t = $typenRes->fetch_assoc()): ?>
                                    <option value="<?= $t['id'] ?>" <?= (int)$wohnung['typ_id']==$t['id'] ? 'selected':'' ?>><?= $t['icon'] ?> <?= h($t['name']) ?></option>
                                  <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Etage / Stockwerk</label>
                            <input type="text" name="etage" class="form-control" value="<?= h($wohnung['etage']) ?>" placeholder="z.B. Attika, 2. OG rechts">
                        </div>
                        <div class="input-group">
                            <label>Anzahl Zimmer</label>
                            <input type="number" step="0.5" name="zimmer" class="form-control" value="<?= (float)$wohnung['zimmer'] ?>">
                        </div>
                        <div class="input-group">
                            <label>Fläche (m²)</label>
                            <input type="number" step="0.1" name="flaeche" class="form-control" value="<?= (float)$wohnung['flaeche'] ?>">
                        </div>
                        <div class="input-group">
                            <label>Anzahl Badezimmer</label>
                            <input type="number" step="0.5" name="badezimmer" class="form-control" value="<?= (float)$wohnung['badezimmer'] ?>">
                        </div>
                        <div class="input-group">
                            <label>Baujahr</label>
                            <input type="number" name="baujahr" class="form-control" value="<?= (int)$wohnung['baujahr'] ?>" placeholder="z.B. 2024">
                        </div>
                    </div>
                </div>

                <!-- AUSSTATTUNG -->
                <div id="section-ausstattung" class="form-section">
                    <h3 class="section-title">🛋️ Ausstattung & Zustand</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Gesamtzustand</label>
                            <select name="gesamtzustand" class="form-control">
                                <option value="neu" <?= $wohnung['gesamtzustand'] == 'neu' ? 'selected':'' ?>>✨ Neu / Erstbezug</option>
                                <option value="renoviert" <?= $wohnung['gesamtzustand'] == 'renoviert' ? 'selected':'' ?>>🔨 Frisch renoviert</option>
                                <option value="gut" <?= $wohnung['gesamtzustand'] == 'gut' ? 'selected':'' ?>>👍 Gut / Unterhalten</option>
                                <option value="getragen" <?= $wohnung['gesamtzustand'] == 'getragen' ? 'selected':'' ?>>🧥 Getragen / Gebraucht</option>
                                <option value="sanierungsbedürftig" <?= $wohnung['gesamtzustand'] == 'sanierungsbedürftig' ? 'selected':'' ?>>🚨 Sanierungsbedürftig</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Letzte Renovation (Datum)</label>
                            <input type="date" name="letzter_renovation" class="form-control" value="<?= h($wohnung['letzter_renovation']) ?>">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Merkmale & Aussenbereiche</label>
                        <div class="checkbox-tile-group">
                            <label class="checkbox-tile">
                                <input type="checkbox" name="balkon" <?= $wohnung['balkon'] ? 'checked':'' ?>>
                                <span>🏙️ Balkon</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="terrasse" <?= $wohnung['terrasse'] ? 'checked':'' ?>>
                                <span>🌴 Terrasse</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="wintergarten" <?= $wohnung['wintergarten'] ? 'checked':'' ?>>
                                <span>❄️ Wintergarten</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="garten" <?= $wohnung['garten'] ? 'checked':'' ?>>
                                <span>🌳 Garten</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="gartensitzplatz" <?= $wohnung['gartensitzplatz'] ? 'checked':'' ?>>
                                <span>🏡 Gartensitzplatz</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="spielplatz" <?= $wohnung['spielplatz'] ? 'checked':'' ?>>
                                <span>🛝 Spielplatz</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="gemeinschaftsraum" <?= $wohnung['gemeinschaftsraum'] ? 'checked':'' ?>>
                                <span>👥 Gemeinschaftsraum</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="keller_vorhanden" <?= $wohnung['keller_vorhanden'] ? 'checked':'' ?>>
                                <span>📦 Kellerabteil</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="lift" <?= $wohnung['lift'] ? 'checked':'' ?>>
                                <span>🛗 Liftzugang</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="barrierefrei" <?= $wohnung['barrierefrei'] ? 'checked':'' ?>>
                                <span>♿ Barrierefrei</span>
                            </label>
                        </div>
                    </div>

                    <div class="input-group" style="margin-top:20px;">
                        <label>Detaillierte Ausstattungs-Beschreibung</label>
                        <textarea name="ausstattung_details" class="form-control textarea-custom" placeholder="Details zu Bodenbelägen, Küche, Bad etc..."><?= h($wohnung['ausstattung_details']) ?></textarea>
                    </div>
                </div>

                <!-- FINANZEN -->
                <div id="section-finanzen" class="form-section">
                    <h3 class="section-title">💰 Finanzen & Miete</h3>
                    <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 30px; margin-bottom: 30px; align-items: stretch;">
                        <!-- Linke Seite: Aktueller Soll-Mietzins (schreibgeschützt, premium) -->
                        <div style="background: linear-gradient(135deg, #fdf4ff 0%, #fae8ff 100%); border: 1.5px solid #f5d0fe; border-radius: 20px; padding: 22px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                            <div>
                                <h4 style="margin: 0 0 15px 0; font-size: 13px; font-weight: 800; color: #86198f; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px;">
                                    <span>📊</span> Aktueller Soll-Mietzins (Planung)
                                </h4>
                                <div style="display: flex; gap: 15px; align-items: center;">
                                    <div>
                                        <div style="font-size: 10px; font-weight: 800; color: #a21caf; text-transform: uppercase; letter-spacing: 0.5px;">Netto</div>
                                        <div style="font-size: 22px; font-weight: 900; color: #701a75;"><?= number_format((float)$wohnung['mietzins_netto_soll'], 2, '.', "'") ?>.-</div>
                                    </div>
                                    <div style="font-size: 22px; color: #f5d0fe; font-weight: 300; margin-top: 12px;">+</div>
                                    <div>
                                        <div style="font-size: 10px; font-weight: 800; color: #a21caf; text-transform: uppercase; letter-spacing: 0.5px;">Nebenkosten</div>
                                        <div style="font-size: 22px; font-weight: 900; color: #701a75;"><?= number_format((float)$wohnung['mietzins_nk_soll'], 2, '.', "'") ?>.-</div>
                                    </div>
                                    <div style="font-size: 22px; color: #f5d0fe; font-weight: 300; margin-top: 12px;">=</div>
                                    <div>
                                        <div style="font-size: 10px; font-weight: 800; color: #c026d3; text-transform: uppercase; letter-spacing: 0.5px;">Brutto (Soll)</div>
                                        <div style="font-size: 22px; font-weight: 900; color: #86198f;"><?= number_format((float)$wohnung['mietzins_netto_soll'] + (float)$wohnung['mietzins_nk_soll'], 2, '.', "'") ?>.-</div>
                                    </div>
                                </div>
                            </div>
                            <div style="font-size: 11px; color: #86198f; font-weight: 700; margin-top: 15px; border-top: 1px dashed #f5d0fe; padding-top: 10px; display: flex; align-items: center; gap: 6px;">
                                <span>💡</span> <span>Wird vollautomatisch über die <b>Mietzins-Entwicklung</b> unten gesteuert.</span>
                            </div>
                        </div>

                        <!-- Rechte Seite: Parkplatz & Verfügbarkeit (editierbar) -->
                        <div style="display: flex; flex-direction: column; gap: 15px; justify-content: center;">
                            <div class="input-group">
                                <label>Parkplatz / Einstellplatz</label>
                                <input type="text" name="parkplatz" class="form-control" value="<?= h($wohnung['parkplatz']) ?>" placeholder="z.B. Nr. 12 oder 'inkl.'">
                            </div>
                            <div class="input-group">
                                <label>Verfügbar ab</label>
                                <?php 
                                $avail_date = '';
                                if (!empty($wohnung['available_from'])) {
                                    $time = strtotime($wohnung['available_from']);
                                    if ($time !== false) {
                                        $avail_date = date('Y-m-d', $time);
                                    } else {
                                        $avail_date = $wohnung['available_from'];
                                    }
                                }
                                ?>
                                <input type="date" name="available_from" class="form-control" value="<?= h($avail_date) ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Vertrags- & Mieterdaten (Ist) -->
                    <div style="margin-top:40px; padding:25px; background:#f8fafc; border:2px solid #e2e8f0; border-radius:24px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.05);">
                        <h4 style="margin:0 0 20px 0; font-size:16px; font-weight:800; color:#1e293b; display:flex; align-items:center; gap:8px;">
                            <span>👤</span> Aktiver Mieter & Vertragsdaten (Ist-Zustand)
                        </h4>
                        
                        <div class="input-grid" style="margin-bottom:0;">
                            <div class="input-group">
                                <label>Aktueller Mieter</label>
                                <select name="mieter_benutzer_id" class="form-control" style="font-family:inherit;">
                                    <option value="0">— Kein aktiver Mieter (leerstehend) —</option>
                                    <?php foreach ($allUsers as $usr): ?>
                                        <option value="<?= (int)$usr['id'] ?>" <?= ($active_mieter && (int)$active_mieter['benutzer_id'] === (int)$usr['id']) ? 'selected' : '' ?>>
                                            <?= h($usr['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="input-group">
                                <label>Mietzins Netto (Ist) - CHF</label>
                                <input type="number" step="0.05" name="mietzins_netto_ist" class="form-control" value="<?= $active_mieter ? (float)$active_mieter['mietzins_netto'] : '' ?>" placeholder="0.00">
                            </div>
                            
                            <div class="input-group">
                                <label>Nebenkosten Akonto (Ist) - CHF</label>
                                <input type="number" step="0.05" name="mietzins_nk_ist" class="form-control" value="<?= $active_mieter ? (float)$active_mieter['nk_akonto'] : '' ?>" placeholder="0.00">
                            </div>
                            
                            <div class="input-group">
                                <label>Einzugsdatum / Vertragsbeginn</label>
                                <input type="date" name="einzug_datum" class="form-control" value="<?= $active_mieter ? h($active_mieter['startdatum']) : date('Y-m-d') ?>">
                            </div>
                        </div>

                        <?php if ($active_mieter): ?>
                            <div style="margin-top:20px; padding-top:15px; border-top:1.5px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                                <div style="font-size:12px; color:#64748b; font-weight:700;">
                                    ℹ️ Aktiver Vertrag seit: <?= date('d.m.Y', strtotime($active_mieter['startdatum'])) ?>
                                </div>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:700; color:#ef4444;">
                                    <input type="checkbox" name="action_mieter" value="auszug" style="width:18px; height:18px;">
                                    <span>📦 Mieter zieht aus (in Historie verschieben)</span>
                                </label>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Mietzins-Historie & Abrechnungs-Archiv (Verlauf) -->
                    <div style="margin-top:40px; padding:25px; background:#faf5ff; border:2px solid #e9d5ff; border-radius:24px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.03);">
                        <h4 style="margin:0 0 20px 0; font-size:16px; font-weight:800; color:#581c87; display:flex; align-items:center; gap:8px;">
                            <span>📈</span> Mietzins-Entwicklung & Abrechnungs-Archiv
                        </h4>
                        
                        <!-- Formular zum Hinzufügen einer Mietzinsänderung -->
                        <div style="background:white; border:1px solid #e9d5ff; padding:20px; border-radius:18px; margin-bottom:25px;">
                            <h5 style="margin:0 0 15px 0; font-size:13px; font-weight:800; color:#6b21a8; text-transform:uppercase; letter-spacing:0.5px;">📈 Neue Mietzins-Dokumentation erfassen</h5>
                            <div class="input-grid" style="margin-bottom:0; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:15px;">
                                <div class="input-group">
                                    <label>Gilt ab*</label>
                                    <input type="date" name="h_gilt_ab" class="form-control" style="padding:10px 14px;">
                                </div>
                                <div class="input-group">
                                    <label>Netto (CHF)*</label>
                                    <input type="number" step="0.05" name="h_netto" class="form-control" style="padding:10px 14px;" placeholder="0.00">
                                </div>
                                <div class="input-group">
                                    <label>Nebenkosten (CHF)</label>
                                    <input type="number" step="0.05" name="h_nk" class="form-control" style="padding:10px 14px;" placeholder="0.00" value="0.00">
                                </div>
                                <div class="input-group">
                                    <label>Typ der Anpassung</label>
                                    <select name="h_typ" class="form-control" style="padding:10px 14px; font-family:inherit;">
                                        <option value="soll" selected>Planung (Soll)</option>
                                        <option value="ist">Aktiver Vertrag (Ist)</option>
                                        <option value="erhoehung">📈 Erhöhung</option>
                                        <option value="reduktion">📉 Reduktion</option>
                                        <option value="anpassung">🔄 Anpassung</option>
                                    </select>
                                </div>
                            </div>
                            <div class="input-group" style="margin-top:15px;">
                                <label>Grund / Bemerkung (z.B. Referenzzinssatz-Erhöhung)</label>
                                <input type="text" name="h_bemerkung" class="form-control" style="padding:10px 14px;" placeholder="z.B. Erhöhung wegen Referenzzinssatz-Anpassung auf 1.75%">
                            </div>
                            <div style="font-size:11px; color:#6b21a8; margin-top:8px; font-weight:600;">
                                💡 Beim Speichern wird der neue Wert automatisch in die Soll-Planung (oder den Ist-Vertrag) übernommen!
                            </div>
                        </div>

                        <!-- Tabelle mit dem Verlauf -->
                        <h5 style="margin:0 0 10px 0; font-size:12px; font-weight:800; color:#581c87; text-transform:uppercase;">📜 Bisheriger Verlauf (Historie)</h5>
                        <?php if (empty($mietzinsHistory)): ?>
                            <div style="padding:20px; text-align:center; color:#a21caf; font-style:italic; background:rgba(245,243,255,0.5); border:1px dashed #d8b4fe; border-radius:18px; font-size:13px;">
                                Noch keine Mietzinsänderungen oder -dokumentationen archiviert.
                            </div>
                        <?php else: ?>
                            <div style="overflow-x:auto; background:white; border-radius:18px; border:1px solid #e9d5ff;">
                                <table style="width:100%; border-collapse:collapse; font-size:13px; text-align:left;">
                                    <thead>
                                        <tr style="background:#faf5ff; border-bottom:1px solid #e9d5ff;">
                                            <th style="padding:12px 16px; font-weight:800; color:#581c87;">Gilt ab</th>
                                            <th style="padding:12px 16px; font-weight:800; color:#581c87;">Netto</th>
                                            <th style="padding:12px 16px; font-weight:800; color:#581c87;">NK</th>
                                            <th style="padding:12px 16px; font-weight:800; color:#581c87;">Brutto</th>
                                            <th style="padding:12px 16px; font-weight:800; color:#581c87;">Typ</th>
                                            <th style="padding:12px 16px; font-weight:800; color:#581c87;">Bemerkung / Grund</th>
                                            <th style="padding:12px 16px; text-align:center; font-weight:800; color:#581c87; width:60px;">Aktion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($mietzinsHistory as $item): 
                                            $brutto = (float)$item['mietzins_netto'] + (float)$item['mietzins_nk'];
                                            $typLabel = [
                                                'soll' => 'Planung (Soll)',
                                                'ist' => 'Vertrag (Ist)',
                                                'erhoehung' => '📈 Erhöhung',
                                                'reduktion' => '📉 Reduktion',
                                                'anpassung' => '🔄 Anpassung'
                                            ][$item['typ']] ?? $item['typ'];
                                            
                                            $badgeColor = [
                                                'soll' => 'background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;',
                                                'ist' => 'background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0;',
                                                'erhoehung' => 'background:#fdf2f8; color:#9d174d; border:1px solid #fbcfe8;',
                                                'reduktion' => 'background:#fff7ed; color:#9a3412; border:1px solid #fed7aa;',
                                                'anpassung' => 'background:#faf5ff; color:#581c87; border:1px solid #e9d5ff;'
                                            ][$item['typ']] ?? 'background:#f1f5f9; color:#475569;';
                                        ?>
                                            <tr style="border-bottom:1px solid #f3e8ff; transition:0.2s;" onmouseenter="this.style.background='#faf5ff'" onmouseleave="this.style.background='none'">
                                                <td style="padding:12px 16px; font-weight:700; color:#1e293b;"><?= date('d.m.Y', strtotime($item['gilt_ab'])) ?></td>
                                                <td style="padding:12px 16px; font-weight:700; color:#0f172a;"><?= number_format($item['mietzins_netto'], 2, '.', "'") ?>.-</td>
                                                <td style="padding:12px 16px; color:#475569;"><?= number_format($item['mietzins_nk'], 2, '.', "'") ?>.-</td>
                                                <td style="padding:12px 16px; font-weight:800; color:#1e293b;"><?= number_format($brutto, 2, '.', "'") ?>.-</td>
                                                <td style="padding:12px 16px;">
                                                    <span style="display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:800; <?= $badgeColor ?>"><?= $typLabel ?></span>
                                                </td>
                                                <td style="padding:12px 16px; color:#64748b; font-weight:600;"><?= h($item['bemerkung']) ?: '<span style="color:#cbd5e1; font-style:italic;">Keine Bemerkung</span>' ?></td>
                                                <td style="padding:12px 16px; text-align:center;">
                                                    <button type="button" class="btn-text-danger" style="color:#ef4444; border:none; background:none; cursor:pointer; font-weight:800; font-size:16px;" title="Eintrag löschen" 
                                                            onclick="if(confirm('Diesen Eintrag wirklich aus dem Archiv löschen?')) { 
                                                                const f = document.createElement('form'); 
                                                                f.method = 'POST'; 
                                                                f.action = window.location.href; 
                                                                f.innerHTML = '<input type=\'hidden\' name=\'action\' value=\'delete_history_entry\'><input type=\'hidden\' name=\'history_id\' value=\'<?= $item['id'] ?>\'>'; 
                                                                document.body.appendChild(f); 
                                                                f.submit(); 
                                                            }">🗑️</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TECHNIK -->
                <div id="section-technik" class="form-section">
                    <h3 class="section-title">⚙️ Technik & Gebäude</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Heizungsart</label>
                            <input type="text" name="heizungsart" class="form-control" value="<?= h($wohnung['heizungsart']) ?>" placeholder="z.B. Erdwärme, Bodenheizung">
                        </div>
                        <div class="input-group">
                            <label>Bodenbeläge</label>
                            <input type="text" name="bodenbelag" class="form-control" value="<?= h($wohnung['bodenbelag']) ?>" placeholder="z.B. Parkett Eiche, Feinsteinzeug">
                        </div>
                        <div class="input-group">
                            <label>Waschmöglichkeit</label>
                            <input type="text" name="waschmaschine" class="form-control" value="<?= h($wohnung['waschmaschine']) ?>" placeholder="z.B. Eigener Waschturm in WHG">
                        </div>
                    </div>
                    <div class="input-group">
                        <label>Gebäude-Features</label>
                        <div class="checkbox-tile-group">
                            <label class="checkbox-tile">
                                <input type="checkbox" name="minergie" <?= $wohnung['minergie'] ? 'checked':'' ?>>
                                <span>🌱 Minergie</span>
                            </label>
                            <label class="checkbox-tile">
                                <input type="checkbox" name="glasfaser" <?= $wohnung['glasfaser'] ? 'checked':'' ?>>
                                <span>⚡ Glasfaser / FTTH</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- UMGEBUNG -->
                <div id="section-umgebung" class="form-section">
                    <h3 class="section-title">🌳 Umgebung & Lage</h3>
                    <div class="input-grid">
                        <div class="input-group">
                            <label>Besonnerung</label>
                            <input type="text" name="besonnerung" class="form-control" value="<?= h($wohnung['besonnerung']) ?>" placeholder="z.B. Süd-West, sehr sonnig">
                        </div>
                        <div class="input-group">
                            <label>Aussicht</label>
                            <input type="text" name="aussicht" class="form-control" value="<?= h($wohnung['aussicht']) ?>" placeholder="z.B. Bergsicht, Seesicht">
                        </div>
                        <div class="input-group">
                            <label>Lärmpegel</label>
                            <input type="text" name="laermpegel" class="form-control" value="<?= h($wohnung['laermpegel']) ?>" placeholder="z.B. sehr ruhig, Sackgasse">
                        </div>
                    </div>
                </div>

                <div class="actions" style="margin-top:20px; padding-bottom:20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <button type="submit" class="btn btn-blue" style="padding:15px 40px; border-radius:15px; font-weight:700;">💾 Alle Basisdaten speichern</button>
                    <div style="font-size:12px; color:#64748b;">ID: #<?= $id ?></div>
                </div>
            </form>

            <div id="section-rooms" class="form-section">
                <div style="display:flex; align-items:center; gap:15px; margin-bottom:25px; border-bottom:2px solid #f1f5f9; padding-bottom:15px;">
                    <div style="background:var(--primary); color:white; width:45px; height:45px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px; box-shadow:0 10px 15px -3px rgba(59,130,246,0.3);">➕</div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#1e293b;">Räume hinzufügen</h3>
                        <p style="margin:0; font-size:13px; color:#64748b;">Klicken Sie auf ein Symbol, um es der Wohnung zuzuordnen.</p>
                    </div>
                </div>
                
                <div style="background:linear-gradient(135deg, rgba(59,130,246,0.03) 0%, rgba(59,130,246,0.08) 100%); border:1.5px solid rgba(59,130,246,0.1); padding:30px; border-radius:30px; margin-bottom:40px; position:relative;">
                    
                    <div id="room_master_pool" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:15px; margin-bottom:25px;">
                        <!-- Vorlagen werden hier geladen -->
                    </div>

                    <!-- MASTER TEMPLATE MANAGER -->
                    <div id="master_manager_ui" style="display:none; padding:25px; background:white; border:2px solid var(--primary); border-radius:24px; margin-bottom:30px; box-shadow:0 20px 40px rgba(59,130,246,0.15); z-index:100; position:relative;">
                        <h4 style="margin:0 0 20px 0; font-size:14px; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; gap:10px;">
                            <span style="background:var(--primary); color:white; width:24px; height:24px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:14px;">🛠️</span>
                            Symbol-Bibliothek verwalten
                        </h4>
                        
                        <div style="display:flex; gap:20px; align-items:flex-start; margin-bottom:25px;">
                            <div style="width:100px;">
                                <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:6px; text-transform:uppercase;">Icon</label>
                                <input type="text" id="new_master_icon" placeholder="🚗" style="width:100%; padding:15px; border:2px solid #f1f5f9; border-radius:14px; font-size:24px; text-align:center; background:#f8fafc;">
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:10px; font-weight:800; color:#94a3b8; display:block; margin-bottom:6px; text-transform:uppercase;">Name der neuen Vorlage</label>
                                <input type="text" id="new_master_name" placeholder="z.B. Hobbyraum, Weinkeller..." style="width:100%; padding:15px; border:2px solid #f1f5f9; border-radius:14px; font-size:15px; font-weight:600; background:#f8fafc;">
                            </div>
                            <div style="padding-top:21px;">
                                <button type="button" onclick="event.preventDefault(); addMasterTemplate();" class="btn btn-blue" style="height:55px; padding:0 30px; border-radius:14px; font-weight:700; box-shadow:0 8px 20px rgba(59,130,246,0.2);">Speichern</button>
                            </div>
                        </div>

                        <div id="quick_emojis_wrap" style="border-top:1.5px solid #f1f5f9; padding-top:20px;">
                             <div id="quick_emojis" style="display:flex; flex-direction:column; gap:15px; background:#f8fafc; padding:20px; border-radius:20px; border:1.5px solid #f1f5f9; max-height:250px; overflow-y:scroll; scrollbar-width:thin;">
                                <?php 
                                $emojiPalette = [
                                    'Wohnen' => ['🛋️','🛏️','🛌','🪑','🖼️','🧺','🧹','🕯️','🧸','🧱'],
                                    'Technik' => ['🔧','🔨','🛠️','📶','🔌','🔋','🌡️','💡','🔥','⚙️','📟'],
                                    'Außen' => ['🌳','🌴','🚲','🚗','🏍️','🅿️','🌅','🌻','🪴','🛹','🚜','⛲','⛱️'],
                                    'Bad' => ['🛁','🚿','🛀','🚽','🧼','🧻','💧','🧴','🪠'],
                                    'Lager' => ['🔒','🔑','📦','🧥','🕷️','🕸️','🪜','🪵','🍷','🍶']
                                ];
                                foreach($emojiPalette as $cat => $icons): ?>
                                    <div style="font-size:10px; font-weight:900; color:#94a3b8; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                        <?= $cat ?> <div style="flex:1; height:1px; background:#e2e8f0;"></div>
                                    </div>
                                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                                        <?php foreach($icons as $qi): ?>
                                            <span style="cursor:pointer; font-size:20px; width:40px; height:40px; display:flex; align-items:center; justify-content:center; background:white; border:1.5px solid #fff; border-radius:10px; box-shadow:0 2px 5px rgba(0,0,0,0.03); transition:0.2s;" 
                                                  onmouseenter="this.style.transform='scale(1.2)'" onmouseleave="this.style.transform='scale(1)'"
                                                  onclick="document.getElementById('new_master_icon').value = '<?= $qi ?>'"><?= $qi ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                             </div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid rgba(59,130,246,0.1); pt-20; margin-top:20px; padding-top:20px;">
                        <button type="button" id="btn_toggle_manage" onclick="toggleMasterEdit()" style="background:white; border:1.5px solid #e2e8f0; color:#64748b; padding:8px 15px; border-radius:10px; font-weight:800; font-size:11px; cursor:pointer; text-transform:uppercase; transition:0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05);">⚙️ Symbole verwalten</button>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="text" id="new_room_name" placeholder="Eigener Raumname..." style="width:200px; padding:10px 15px; border-radius:12px; border:1.5px solid #e2e8f0; font-size:13px;">
                            <button type="button" class="btn btn-blue" onclick="addRoomManual()" style="height:38px; padding:0 20px; border-radius:10px; font-weight:700; font-size:13px;">Hinzufügen</button>
                        </div>
                    </div>
                </div>

                <div style="display:flex; align-items:center; gap:15px; margin-bottom:25px; margin-top:50px; border-bottom:2px solid #f1f5f9; padding-bottom:15px;">
                    <div style="background:#475569; color:white; width:45px; height:45px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1);">🏠</div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#1e293b;">Bestehende Räume dieser Wohnung</h3>
                        <p style="margin:0; font-size:13px; color:#64748b;">Verwalten und sortieren Sie die bereits zugeordneten Räume.</p>
                    </div>
                </div>

                <div id="dash_rooms_list" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
                    <!-- Aktuelle Räume werden hier geladen -->
                </div>
            </div>

            <!-- EINHEIT LÖSCHEN (Eigener Bereich) -->
            <div style="margin-top:50px; padding:30px; background:#fff1f2; border:1px solid #fecaca; border-radius:24px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h4 style="margin:0; color:#991b1b; font-weight:800;">Gefahrenzone</h4>
                    <p style="margin:0; font-size:12px; color:#b91c1c;">Vorsicht: Das Löschen der Einheit kann nicht rückgängig gemacht werden.</p>
                </div>
                <div id="del_wrap_<?= $id ?>">
                    <button type="button" class="btn btn-danger" onclick="confirmDelete(<?= $id ?>)" style="padding:10px 25px; border-radius:12px; font-weight:700;">Einheit löschen</button>
                </div>
                <div id="del_act_<?= $id ?>" style="display:none; align-items:center; gap:10px;">
                    <span style="font-size:14px; color:#ef4444; font-weight:800;">Wirklich löschen?</span>
                    <button type="button" class="btn btn-danger" onclick="executeDelete(<?= $id ?>)">JA, weg damit</button>
                    <button type="button" class="btn btn-outline" onclick="cancelDelete(<?= $id ?>)">Abbrechen</button>
                </div>
            </div>
    </div>
</div>
        </form>
        
        <!-- MEDIEN & MARKETING -->
        <div id="section-medien" class="form-section">
            <h3 class="section-title">📸 Medien & Marketing</h3>
            
            <div class="marketing-layout">
                
                <!-- LINKER BEREICH: UPLOAD & MEDIEN-LISTE -->
                <div>
                    <!-- Drag & Drop Upload Zone -->
                    <div id="media-dropzone" style="border: 3px dashed var(--primary); background: rgba(59, 130, 246, 0.03); padding: 40px 20px; border-radius: 24px; text-align: center; cursor: pointer; transition: 0.2s; position: relative; margin-bottom: 30px;">
                        <input type="file" id="media-file-input" multiple style="display: none;">
                        <span style="font-size: 50px; display: block; margin-bottom: 15px;">📥</span>
                        <h4 style="margin: 0 0 8px 0; font-size: 16px; font-weight: 800; color: var(--slate-800);">Bilder oder Dokumente hierher ziehen</h4>
                        <p style="margin: 0; font-size: 13px; color: var(--slate-500); font-weight: 600;">
                            PC-Dateien (JPG, PNG, PDF, Word) & <b style="color:var(--primary);">Google-Fotos per Drag & Drop direkt aus einem anderen Browser-Tab!</b>
                        </p>
                        <button type="button" class="btn btn-outline" onclick="document.getElementById('media-file-input').click()" style="margin-top: 15px; font-size: 12px; padding: 8px 18px; border-radius: 10px; background: white; font-weight: 800;">💾 Datei vom PC auswählen</button>
                    </div>

                    <!-- Progress Indicator -->
                    <div id="upload-progress-wrap" style="display: none; background: #eff6ff; border: 1.5px solid #bfdbfe; padding: 20px; border-radius: 18px; margin-bottom: 30px; align-items: center; gap: 15px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.02);">
                        <div style="font-size: 24px;">⌛</div>
                        <div style="flex: 1;">
                            <div style="font-size: 13px; font-weight: 800; color: #1e40af; margin-bottom: 8px;">Dateien werden hochgeladen...</div>
                            <div style="background: #dbeafe; height: 10px; border-radius: 6px; overflow: hidden; border: 1px solid #bfdbfe;">
                                <div id="upload-progress-bar" style="background: var(--primary); width: 0%; height: 100%; transition: 0.1s; border-radius: 6px;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Bildergalerie -->
                    <div class="media-card">
                        <h4 class="media-card-title">
                            <span>🖼️</span> Bildergalerie & Bildtitel (wird autom. gespeichert)
                        </h4>
                        <?php if (empty($wohnungBilder)): ?>
                            <div style="padding: 40px; text-align: center; color: var(--slate-400); border: 2px dashed var(--slate-200); border-radius: 20px; font-size: 14px; font-style: italic; background: rgba(248,250,252,0.5);">
                                Noch keine Bilder hochgeladen.
                            </div>
                        <?php else: ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px;">
                                <?php foreach ($wohnungBilder as $img): ?>
                                    <div class="glass-card" style="border-radius: 18px; border: 1.5px solid var(--slate-200); overflow: hidden; display: flex; flex-direction: column; transition: 0.2s;" onmouseenter="this.style.transform='translateY(-3px)'" onmouseleave="this.style.transform='translateY(0)'">
                                        <div style="position: relative; padding-top: 66%; background: var(--slate-100);">
                                            <img src="<?= base_url($img['pfad']) ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;">
                                            <?php if ($img['is_cover']): ?>
                                                <span style="position: absolute; top: 10px; left: 10px; background: #eab308; color: white; padding: 4px 10px; border-radius: 8px; font-size: 10px; font-weight: 800; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 4px;">👑 Hauptbild</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="padding: 12px; display: flex; flex-direction: column; gap: 8px; background: white; border-top: 1px solid var(--slate-100);">
                                            <!-- Titel / Label -->
                                            <div>
                                                <span style="font-size: 9px; font-weight: 800; color: var(--slate-400); text-transform: uppercase;">Bildunterschrift / Raum</span>
                                                <input type="text" class="media-meta-input img-titel-field" data-id="<?= $img['id'] ?>" placeholder="z.B. Wohnzimmer" value="<?= h($img['titel'] ?? '') ?>" onchange="saveMediaMeta(<?= $img['id'] ?>, 'image', this)">
                                            </div>
                                            
                                            <!-- Sortierung -->
                                            <div>
                                                <span style="font-size: 9px; font-weight: 800; color: var(--slate-400); text-transform: uppercase;">Reihenfolge</span>
                                                <input type="number" class="media-meta-input img-sort-field" data-id="<?= $img['id'] ?>" value="<?= (int)($img['sort_order'] ?? 0) ?>" min="0" max="999" onchange="saveMediaMeta(<?= $img['id'] ?>, 'image', this)" style="width: 70px;">
                                            </div>

                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; border-top: 1px solid var(--slate-100); padding-top: 8px;">
                                                <?php if (!$img['is_cover']): ?>
                                                    <button type="button" class="btn-text-danger" style="color: #3b82f6; text-decoration: none; font-size: 11px; cursor: pointer; font-weight: 800;" 
                                                            onclick="triggerMediaAction('set_cover', <?= $img['id'] ?>)">👑 Hauptbild</button>
                                                <?php else: ?>
                                                    <span style="font-size: 11px; color: #eab308; font-weight: 800;">Aktiv</span>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn-text-danger" style="font-size: 13px; text-decoration: none;" 
                                                        onclick="if(confirm('Möchten Sie dieses Bild wirklich löschen?')) triggerMediaAction('delete_image', <?= $img['id'] ?>)" title="Bild löschen">🗑️</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Dokumente & Pläne -->
                    <div class="media-card">
                        <h4 class="media-card-title">
                            <span>📄</span> Dokumente & Pläne (wird autom. gespeichert)
                        </h4>
                        <?php if (empty($wohnungDokumente)): ?>
                            <div style="padding: 30px; text-align: center; color: var(--slate-400); border: 2px dashed var(--slate-200); border-radius: 20px; font-size: 14px; font-style: italic; background: rgba(248,250,252,0.5);">
                                Noch keine Dokumente hochgeladen.
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 12px; background: white; border-radius: 12px; padding: 5px;">
                                <?php foreach ($wohnungDokumente as $doc): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border: 1.5px solid var(--slate-150); border-radius: 12px; transition: 0.2s;" onmouseenter="this.style.background='var(--slate-50)'" onmouseleave="this.style.background='none'">
                                        <div style="display: flex; align-items: center; gap: 12px; flex: 1; max-width: 80%;">
                                            <span style="font-size: 24px;">📄</span>
                                            <div style="display: flex; flex-direction: column; gap: 4px; flex: 1;">
                                                <!-- Friendly Name Input -->
                                                <input type="text" class="media-meta-input doc-titel-field" data-id="<?= $doc['id'] ?>" value="<?= h($doc['name']) ?>" onchange="saveMediaMeta(<?= $doc['id'] ?>, 'document', this)" style="font-size: 13px; font-weight: 700; width: 95%;">
                                                
                                                <div style="display: flex; align-items: center; gap: 10px; margin-top: 4px;">
                                                    <!-- Kategorie Selector -->
                                                    <select class="media-meta-input doc-kategorie-field" data-id="<?= $doc['id'] ?>" onchange="saveMediaMeta(<?= $doc['id'] ?>, 'document', this)" style="width: auto; padding: 3px 6px; font-size: 10px; text-transform: uppercase; font-weight: 800; color: var(--slate-600); margin:0;">
                                                        <option value="sonstiges" <?= ($doc['kategorie'] ?? 'sonstiges') === 'sonstiges' ? 'selected' : '' ?>>Sonstiges</option>
                                                        <option value="grundriss" <?= ($doc['kategorie'] ?? '') === 'grundriss' ? 'selected' : '' ?>>📐 Grundriss</option>
                                                        <option value="mietvertrag" <?= ($doc['kategorie'] ?? '') === 'mietvertrag' ? 'selected' : '' ?>>📝 Mietvertrag</option>
                                                        <option value="nebenkosten" <?= ($doc['kategorie'] ?? '') === 'nebenkosten' ? 'selected' : '' ?>>📊 Nebenkosten</option>
                                                        <option value="flyer" <?= ($doc['kategorie'] ?? '') === 'flyer' ? 'selected' : '' ?>>📰 Flyer / Broschüre</option>
                                                    </select>
                                                    
                                                    <span style="font-size: 10px; color: var(--slate-400); font-weight: 800;">•</span>
                                                    <a href="<?= base_url($doc['pfad']) ?>" target="_blank" style="font-size: 11px; font-weight: 700; color: var(--primary); text-decoration: none; hover: underline;">Herunterladen</a>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn-text-danger" style="font-size: 16px; text-decoration: none;" 
                                                onclick="if(confirm('Möchten Sie dieses Dokument wirklich löschen?')) triggerMediaAction('delete_doc', <?= $doc['id'] ?>)">🗑️</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RECHTER BEREICH: MARKETING DETAILS & LIVE PREVIEW -->
                <div>
                    <!-- Marketing form -->
                    <div class="media-card" style="border: 2px solid var(--primary-light); background: rgba(59, 130, 246, 0.01);">
                        <h4 class="media-card-title" style="color: var(--primary);">
                            <span>📣</span> Marketing ausschreiben
                        </h4>
                        
                        <form method="POST" action="wohnung_edit.php?id=<?= $id ?>">
                            <input type="hidden" name="action" value="save_marketing">
                            <input type="hidden" name="_hash" value="medien">
                            
                            <!-- Status -->
                            <div style="margin-bottom: 20px;">
                                <label class="marketing-label">Ausschreibungs-Status</label>
                                <select class="marketing-input" name="marketing_status" id="marketing_status" onchange="updateLivePreview()" style="font-weight: 700;">
                                    <option value="entwurf" <?= ($wohnung['marketing_status'] ?? 'entwurf') === 'entwurf' ? 'selected' : '' ?>>🟡 Entwurf / Vorbereitung</option>
                                    <option value="aktiv" <?= ($wohnung['marketing_status'] ?? '') === 'aktiv' ? 'selected' : '' ?>>🟢 Aktiv ausgeschrieben</option>
                                    <option value="pausiert" <?= ($wohnung['marketing_status'] ?? '') === 'pausiert' ? 'selected' : '' ?>>⚫ Pausiert / Rented</option>
                                </select>
                            </div>

                            <!-- Marketing Titel -->
                            <div style="margin-bottom: 20px;">
                                <label class="marketing-label">Titel für Inserat</label>
                                <input type="text" class="marketing-input" name="marketing_titel" id="marketing_titel" placeholder="z.B. Sonnige 3.5 Zimmerwohnung im Grünen" value="<?= h($wohnung['marketing_titel'] ?? '') ?>" oninput="updateLivePreview()">
                            </div>

                            <!-- Marketing Beschreibung -->
                            <div style="margin-bottom: 20px;">
                                <label class="marketing-label">Werbetext / Beschreibung</label>
                                <textarea class="marketing-input" name="marketing_beschreibung" id="marketing_beschreibung" placeholder="Beschreiben Sie die Wohnqualität, Lage, Ausstattung..." rows="6" oninput="updateLivePreview()"><?= h($wohnung['marketing_beschreibung'] ?? '') ?></textarea>
                            </div>

                            <!-- Highlights -->
                            <div style="margin-bottom: 20px;">
                                <label class="marketing-label">Top 3 Besonderheiten (Highlights)</label>
                                <input type="text" class="marketing-input" style="margin-bottom: 8px;" name="marketing_highlight1" id="marketing_highlight1" placeholder="Highlight 1 (z.B. Eigener Waschturm)" value="<?= h($wohnung['marketing_highlight1'] ?? '') ?>" oninput="updateLivePreview()">
                                <input type="text" class="marketing-input" style="margin-bottom: 8px;" name="marketing_highlight2" id="marketing_highlight2" placeholder="Highlight 2 (z.B. Grosser Gartensitzplatz)" value="<?= h($wohnung['marketing_highlight2'] ?? '') ?>" oninput="updateLivePreview()">
                                <input type="text" class="marketing-input" name="marketing_highlight3" id="marketing_highlight3" placeholder="Highlight 3 (z.B. Cheminée im Wohnzimmer)" value="<?= h($wohnung['marketing_highlight3'] ?? '') ?>" oninput="updateLivePreview()">
                            </div>

                            <!-- Portal Links -->
                            <div style="margin-bottom: 25px; border-top: 1.5px solid var(--slate-100); padding-top: 20px;">
                                <label class="marketing-label">Inserat Links auf Portalen</label>
                                
                                <div class="portal-link-group homegate">
                                    <span class="portal-link-label">Homegate</span>
                                    <input type="url" name="link_homegate" placeholder="Link zur Homegate-Anzeige..." value="<?= h($wohnung['link_homegate'] ?? '') ?>">
                                </div>
                                
                                <div class="portal-link-group immoscout">
                                    <span class="portal-link-label">ImmoScout</span>
                                    <input type="url" name="link_immoscout" placeholder="Link zur ImmoScout-Anzeige..." value="<?= h($wohnung['link_immoscout'] ?? '') ?>">
                                </div>
                                
                                <div class="portal-link-group flatfox">
                                    <span class="portal-link-label">Flatfox</span>
                                    <input type="url" name="link_flatfox" placeholder="Link zur Flatfox-Anzeige..." value="<?= h($wohnung['link_flatfox'] ?? '') ?>">
                                </div>
                                
                                <div class="portal-link-group comparis">
                                    <span class="portal-link-label">Comparis</span>
                                    <input type="url" name="link_comparis" id="link_comparis_input" placeholder="Link zur Comparis-Anzeige..." value="<?= h($wohnung['link_comparis'] ?? '') ?>">
                                </div>
                                
                                <div style="margin-top: -10px; margin-bottom: 25px; display: flex; justify-content: flex-end; flex-direction: column; align-items: flex-end; gap: 8px;">
                                    <button type="button" id="btn_comparis_scrape" onclick="scrapeComparis()" style="display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 800; color: #d97706; background: #fffbeb; border: 1.5px solid #fde68a; padding: 8px 16px; border-radius: 10px; cursor: pointer; transition: 0.2s;" onmouseenter="this.style.background='#fef3c7'" onmouseleave="this.style.background='#fffbeb'">
                                        <span id="btn_comparis_loader" style="display: none;">⌛</span>
                                        <span id="btn_comparis_icon">📥</span> 
                                        <span id="btn_comparis_text">Daten & Bilder von Comparis importieren</span>
                                    </button>
                                    
                                    <div style="font-size: 10px; font-weight: 700;">
                                        <a href="#" onclick="document.getElementById('comparis_manual_paste_section').style.display = document.getElementById('comparis_manual_paste_section').style.display === 'none' ? 'block' : 'none'; return false;" style="color: #64748b; text-decoration: underline;">Probleme beim automatischen Import?</a>
                                    </div>
                                </div>

                                <div id="comparis_manual_paste_section" style="display: none; margin-bottom: 25px; padding: 20px; background: #fffbeb; border: 1.5px dashed #f59e0b; border-radius: 18px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.02);">
                                    <label style="font-size: 12px; font-weight: 800; color: #b45309; display: block; margin-bottom: 8px;">Comparis-Quellcode manuell importieren:</label>
                                    <p style="margin: 0 0 12px 0; font-size: 11px; color: #d97706; line-height: 1.4;">
                                        1. Drücken Sie auf der Comparis-Detailseite <b>Strg + U</b> (Quellcode anzeigen).<br>
                                        2. Kopieren Sie den gesamten Text (<b>Strg + A</b>, dann <b>Strg + C</b>).<br>
                                        3. Fügen Sie ihn unten ein und klicken Sie auf Importieren. Die Bilder werden vom CDN geladen!
                                    </p>
                                    <textarea id="comparis_html_source" class="marketing-input" rows="5" placeholder="Hier den gesamten HTML-Quellcode einfügen..." style="font-family: monospace; font-size: 11px; margin-bottom: 12px; background: white; border: 1.5px solid #fde68a;"></textarea>
                                    <button type="button" id="btn_comparis_manual_import" onclick="scrapeComparis(true)" style="display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 800; color: white; background: #f59e0b; border: none; padding: 10px 20px; border-radius: 12px; cursor: pointer; transition: 0.2s;" onmouseenter="this.style.background='#d97706'" onmouseleave="this.style.background='#f59e0b'">
                                        <span>🔮</span>
                                        <span>Quellcode importieren</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" class="btn btn-primary" style="width:100%; border-radius: 12px; padding: 14px 20px; font-weight:800; background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%); border:none; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);">
                                💾 Marketingdaten speichern
                            </button>
                        </form>
                    </div>

                    <!-- LIVE PORTAL PREVIEW CARD -->
                    <div style="margin-top: 35px;">
                        <h4 class="marketing-label" style="text-align: center; margin-bottom: 12px;">🔴 Live Inserat-Vorschau</h4>
                        <div class="preview-card">
                            <div class="preview-img-wrap">
                                <?php
                                $coverImg = null;
                                foreach($wohnungBilder as $img) {
                                    if($img['is_cover']) { $coverImg = $img['pfad']; break; }
                                }
                                if(!$coverImg && !empty($wohnungBilder)) { $coverImg = $wohnungBilder[0]['pfad']; }
                                ?>
                                <img src="<?= $coverImg ? base_url($coverImg) : 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22800%22 height=%22450%22 style=%22background:%23cbd5e1%22><text x=%2250%25%22 y=%2250%25%22 fill=%22%23475569%22 font-family=%22sans-serif%22 font-weight=%22800%22 font-size=%2222%22 text-anchor=%22middle%22 dy=%22.3em%22>Kein Bild vorhanden</text></svg>' ?>" class="preview-img" id="preview_card_img">
                                <span class="preview-status entwurf" id="preview_card_status">Entwurf</span>
                                
                                <div class="preview-badge-netto">
                                    CHF <span id="preview_card_netto"><?= number_format((float)($wohnung['mietzins_netto_soll'] ?? 0), 0, '.', '\'') ?></span>.— / Mo
                                </div>
                            </div>
                            <div class="preview-content">
                                <h3 class="preview-title-text" id="preview_card_title">Noch kein Titel definiert</h3>
                                
                                <div class="preview-meta-grid">
                                    <div class="preview-meta-item">
                                        <span class="preview-meta-val" id="preview_meta_zimmer"><?= (float)($wohnung['zimmer'] ?? 0) ?></span>
                                        <span class="preview-meta-lbl">Zimmer</span>
                                    </div>
                                    <div class="preview-meta-item">
                                        <span class="preview-meta-val" id="preview_meta_flaeche"><?= (float)($wohnung['flaeche'] ?? 0) ?> m²</span>
                                        <span class="preview-meta-lbl">Fläche</span>
                                    </div>
                                    <div class="preview-meta-item">
                                        <span class="preview-meta-val" id="preview_meta_etage"><?= h($wohnung['etage'] ?: 'EG') ?></span>
                                        <span class="preview-meta-lbl">Etage</span>
                                    </div>
                                </div>
                                
                                <p class="preview-desc" id="preview_card_desc">Geben Sie eine ansprechende Beschreibung ein...</p>
                                
                                <div class="preview-highlights-list" id="preview_card_highlights">
                                    <span class="preview-highlight-pill" id="preview_pill_1">Highlight 1</span>
                                    <span class="preview-highlight-pill" id="preview_pill_2">Highlight 2</span>
                                    <span class="preview-highlight-pill" id="preview_pill_3">Highlight 3</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
/**
 * Tab-Navigation mit Hash-Routing
 */
function showSection(id, el) {
    if(!id) id = 'basis';
    
    // UI Update
    document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    
    const section = document.getElementById('section-' + id);
    if(section) {
        section.classList.add('active');
        // Falls el nicht übergeben wurde (z.B. durch Hash-Wechsel), suchen wir den passenden Nav-Item
        if(!el) {
            el = Array.from(document.querySelectorAll('.nav-item')).find(item => item.getAttribute('onclick')?.includes(`'${id}'`));
        }
        if(el) el.classList.add('active');
        
        // URL Hash aktualisieren & Hidden Field im Formular für Rücksprung
        if(window.location.hash !== '#' + id) {
            history.pushState(null, null, '#' + id);
        }
        document.getElementById('form_hash_field').value = id;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

// Reagieren auf Hash-Änderungen (Back-Button etc.)
window.addEventListener('popstate', () => {
    const hash = window.location.hash.replace('#', '') || 'basis';
    showSection(hash);
});

// Initialer Check beim Laden
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '') || 'basis';
    showSection(hash);
});


/**
 * Raum-Management Logik
 */
const currentWohnungId = <?= (int)$id ?>;

let isMasterEditMode = false;

function toggleMasterEdit() {
    isMasterEditMode = !isMasterEditMode;
    const ui = document.getElementById('master_manager_ui');
    const btn = document.getElementById('btn_toggle_manage');
    if(ui) ui.style.display = isMasterEditMode ? 'block' : 'none';
    if(btn) btn.innerText = isMasterEditMode ? '✅ Verwaltung beenden' : '⚙️ Symbole verwalten';
    loadRooms(); // Refresh to show/hide delete buttons on tiles
}

function addMasterTemplate() {
    const icon = document.getElementById('new_master_icon').value.trim();
    const name = document.getElementById('new_master_name').value.trim();
    if(!name) return alert("Bitte geben Sie einen Namen für den Raum ein.");
    
    console.log("RoomManager: Adding master template", {name, icon});
    
    const fd = new FormData();
    fd.append('action', 'add_template');
    fd.append('name', name);
    fd.append('icon', icon || '📦');
    
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            console.log("RoomManager: Master template added successfully");
            document.getElementById('new_master_icon').value = '';
            document.getElementById('new_master_name').value = '';
            loadRooms();
            // Automatisch Verwaltung schließen für sauberes Ergebnis
            // toggleMasterEdit(); 
        } else {
            alert("Fehler beim Speichern: " + (res.error || 'Unbekannter Fehler'));
        }
    })
    .catch(err => {
        console.error("RoomManager Error:", err);
        alert("Netzwerk-Fehler: " + err.message);
    });
}

function deleteMasterTemplate(tid) {
    console.log("RoomManager: deleteMasterTemplate DIRECT START", tid);
    // window.confirm entfernt zum Testen
    
    const fd = new FormData();
    fd.append('action', 'delete_template');
    fd.append('t_id', tid);
    
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(text => {
        console.log("RoomManager: RAW RESPONSE:", text);
        try {
            const res = JSON.parse(text);
            if(res.success) {
                console.log("RoomManager: Master template deleted successfully");
                loadRooms();
            } else {
                alert("Fehler vom Server: " + (res.error || "Unbekannt"));
            }
        } catch(e) {
            console.error("RoomManager: JSON Parse Error", e);
            alert("Kritischer Fehler: Server antwortet mit Text statt Daten. Siehe Konsole.");
        }
    })
    .catch(err => {
        console.error("RoomManager: Fetch Error", err);
        alert("Netzwerk-Fehler: " + err.message);
    });
}

function deleteRoom(rid) {
    console.log("RoomManager: deleteRoom DIRECT START for ID:", rid);
    // window.confirm entfernt fuer Testlauf
    
    const fd = new FormData();
    fd.append('action', 'delete_room');
    fd.append('room_id', rid);
    
    fetch('ajax_unit_rooms.php', { method: 'POST', body: fd })
    .then(r => r.text())
    .then(text => {
        console.log("RoomManager: deleteRoom RESPONSE:", text);
        try {
            const res = JSON.parse(text);
            if(res.success) {
                console.log("RoomManager: Room deleted successfully");
                loadRooms();
            } else {
                alert("Fehler beim Löschen: " + (res.error || "Unbekannt"));
            }
        } catch(e) {
            console.error("RoomManager: JSON Parse Error", e, text);
            alert("Fehler: Server antwortet nicht im richtigen Format.");
        }
    })
    .catch(err => alert("Netzwerk-Fehler: " + err.message));
}

function loadRooms() {
    const list = document.getElementById('dash_rooms_list');
    const pool = document.getElementById('room_master_pool');
    const summary = document.getElementById('sidebar-room-summary');
    if(!list || !pool) return;
    
    list.innerHTML = '<div style="color:#94a3b8; font-style:italic; padding:20px;">⌛ Lade Raum-Struktur...</div>';
    
    fetch('ajax_unit_rooms.php?wohnung_id=' + currentWohnungId + '&_nc=' + Date.now())
    .then(r => r.json())
    .then(res => {
        if(!res.success) throw new Error(res.error || 'API Fehler');
        
        // Sicherheitshalber beides prüfen (data-Objekt oder Root)
        let data = res.data || res; 
        if(!data.rooms && res.rooms) data = res; 

        // 1. Schnellauswahl
        pool.innerHTML = '';
        if(data.master_data) {
            data.master_data.forEach(m => {
                const wrapper = document.createElement('div');
                wrapper.style.position = 'relative';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline';
                btn.style.cssText = 'display:flex; flex-direction:column; align-items:center; gap:8px; padding:18px 12px; width:100%; min-height:90px; border-radius:18px; border:1.5px solid #e2e8f0; background:white; cursor:pointer; font-family:inherit; transition:0.2s;';
                btn.innerHTML = `<span style="font-size:32px; pointer-events:none;">${m.icon || '📦'}</span><span style="font-size:11px; font-weight:800; color:#1e293b; pointer-events:none;">${m.name}</span>`;
                
                wrapper.appendChild(btn); // Erst den Knopf

                if(!isMasterEditMode) {
                    btn.onclick = (e) => { e.preventDefault(); addRoom(m.name); };
                } else {
                    btn.style.opacity = '0.3';
                    btn.style.cursor = 'default';
                    btn.style.pointerEvents = 'none';
                    btn.onclick = null; // Sicherstellen, dass er nichts tut

                    // Das rote X kommt ZULETZT, damit es GANZ OBEN liegt
                    const del = document.createElement('div');
                    del.style.cssText = 'position:absolute; top:-12px; right:-12px; background:#ef4444; color:white; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:22px; cursor:pointer !important; z-index:9999; border:3px solid white; font-weight:bold; box-shadow:0 5px 15px rgba(220,38,38,0.5); transition:0.2s; pointer-events: auto !important;';
                    del.innerHTML = '&times;';
                    
                    del.onmouseenter = () => { del.style.transform = 'scale(1.15)'; del.style.background = '#dc2626'; };
                    del.onmouseleave = () => { del.style.transform = 'scale(1)'; del.style.background = '#ef4444'; };
                    
                    del.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log("RoomManager: Delete template ID:", m.id);
                        deleteMasterTemplate(m.id);
                    });
                    wrapper.appendChild(del);
                }
                pool.appendChild(wrapper);
            });
        }

        // 2. Liste
        list.innerHTML = '';
        let icons = '';
        if(!data.rooms || data.rooms.length === 0) {
            list.innerHTML = '<div style="grid-column:1/-1; padding:50px; text-align:center; color:#94a3b8; border:2px dashed #e2e8f0; border-radius:24px;">Noch keine Räume definiert.</div>';
        } else {
            data.rooms.forEach(r => {
                const m = data.master_data.find(master => master.name === r.name || r.name.startsWith(master.name));
                const ic = m ? m.icon : '▫️';
                icons += ic;

                const div = document.createElement('div');
                div.style.cssText = 'background:#fff; border:1.5px solid #e2e8f0; padding:18px; border-radius:20px; display:flex; justify-content:space-between; align-items:center; transition:0.2s;';
                
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.style.cssText = 'display:flex; align-items:center; justify-content:center; width:45px; height:45px; border-radius:12px; border:2px solid #fecaca; background:#fff; color:#ef4444; cursor:pointer !important; transition:0.2s; position:relative; z-index:100;';
                deleteBtn.innerHTML = '<span style="font-size:20px; pointer-events:none;">🗑️</span>';
                deleteBtn.onmouseenter = () => { deleteBtn.style.background = '#ef4444'; deleteBtn.style.color = '#fff'; };
                deleteBtn.onmouseleave = () => { deleteBtn.style.background = '#fff'; deleteBtn.style.color = '#ef4444'; };
                deleteBtn.onclick = function(e) {
                    e.preventDefault(); e.stopPropagation();
                    deleteRoom(r.id);
                };

                div.innerHTML = `
                    <div style="display:flex; align-items:center; gap:12px;">
                        <span style="font-size:24px;">${ic}</span>
                        <span style="font-weight:700; color:#0f172a; font-size:16px;">${r.name}</span>
                    </div>
                `;
                div.appendChild(deleteBtn);
                list.appendChild(div);
            });
        }
        if(summary) summary.innerText = icons;
    })
    .catch(err => {
        list.innerHTML = `<div style="color:#ef4444; padding:20px;">⚠️ Ladefehler: ${err.message}</div>`;
    });
}

function addRoom(name) {
    console.log("RoomManager: Attempting to add", name);
    const fd = new FormData();
    fd.append('action', 'add_room');
    fd.append('w_id', currentWohnungId);
    fd.append('room_name', name);
    
    fetch('ajax_unit_rooms.php', { 
        method: 'POST', 
        body: fd 
    })
    .then(async r => {
        if(!r.ok) throw new Error("Netzwerk-Antwort war nicht OK (" + r.status + ")");
        return r.json();
    })
    .then(res => {
        if(res.success) {
            console.log("RoomManager: Successfully added", name);
            loadRooms();
        } else {
            console.error("RoomManager API Error:", res.error);
            alert("Fehler beim Speichern: " + res.error);
        }
    })
    .catch(err => {
        console.error("RoomManager Fetch Error:", err);
        alert("Klick erkannt, aber Server meldet Fehler: " + err.message);
    });
}

function addRoomManual() {
    const val = document.getElementById('new_room_name').value.trim();
    if(val) {
        addRoom(val);
        document.getElementById('new_room_name').value = '';
    }
}

/**
 * Lösch-Aktionen (Einheit)
 */
function confirmDelete(id) {
    document.getElementById('del_wrap_' + id).style.display = 'none';
    document.getElementById('del_act_' + id).style.display = 'inline-flex';
}
function cancelDelete(id) {
    document.getElementById('del_wrap_' + id).style.display = 'inline-flex';
    document.getElementById('del_act_' + id).style.display = 'none';
}
function executeDelete(wid) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = '<input type="hidden" name="action" value="delete_unit_force">';
    document.body.appendChild(f);
    f.submit();
}

/**
 * Medien- und Dateiverwaltung (Drag & Drop, Google-Photos, PC-Upload)
 */
const dropZone = document.getElementById('media-dropzone');
const fileInput = document.getElementById('media-file-input');

if (dropZone) {
    // Drag and drop event listeners
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => e.preventDefault(), false);
    });

    dropZone.addEventListener('dragenter', () => {
        dropZone.style.background = 'rgba(59, 130, 246, 0.08)';
        dropZone.style.borderColor = 'var(--primary-dark)';
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.style.background = 'rgba(59, 130, 246, 0.03)';
        dropZone.style.borderColor = 'var(--primary)';
    });

    dropZone.addEventListener('drop', async (e) => {
        dropZone.style.background = 'rgba(59, 130, 246, 0.03)';
        dropZone.style.borderColor = 'var(--primary)';

        // Weg 1: Der Nutzer zieht echte Dateien (z.B. vom PC)
        if (e.dataTransfer.files.length > 0) {
            handleMediaUploads(e.dataTransfer.files);
        } 
        // Weg 2: Der Nutzer zieht ein Bild aus Google Fotos (Browser-Tab)
        else {
            // Holt die Bild-URL oder HTML-Daten aus dem Drag-Event
            const htmlData = e.dataTransfer.getData('text/html');
            const textData = e.dataTransfer.getData('text');
            
            let imageUrl = '';
            
            // Falls es HTML ist (z.B. eine Img-Tag aus einem Tab)
            if (htmlData) {
                const doc = new DOMParser().parseFromString(htmlData, 'text/html');
                const img = doc.querySelector('img');
                if (img && img.src) {
                    imageUrl = img.src;
                }
            }
            
            // Falls es eine reine Text-URL ist
            if (!imageUrl && textData && textData.startsWith('http')) {
                imageUrl = textData;
            }

            if (imageUrl) {
                handleUrlUpload(imageUrl);
            } else {
                alert("Dieses Element konnte nicht als Bild identifiziert werden. Bitte ziehen Sie ein echtes Bild.");
            }
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) {
            handleMediaUploads(fileInput.files);
        }
    });
}

function handleMediaUploads(files) {
    const progressWrap = document.getElementById('upload-progress-wrap');
    const progressBar = document.getElementById('upload-progress-bar');
    
    if(progressWrap) progressWrap.style.display = 'flex';
    
    let uploadedCount = 0;
    const totalFiles = files.length;
    
    Array.from(files).forEach((file, index) => {
        const fd = new FormData();
        fd.append('action', 'upload_media');
        fd.append('media_file', file);
        
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                if(progressBar) progressBar.style.width = percent + '%';
            }
        };
        
        xhr.onload = () => {
            uploadedCount++;
            if (uploadedCount === totalFiles) {
                window.location.hash = '#medien';
                window.location.reload();
            }
        };
        
        xhr.onerror = () => {
            alert("Fehler beim Hochladen von: " + file.name);
            if(progressWrap) progressWrap.style.display = 'none';
        };
        
        xhr.send(fd);
    });
}

function handleUrlUpload(url) {
    const progressWrap = document.getElementById('upload-progress-wrap');
    const progressBar = document.getElementById('upload-progress-bar');
    
    if(progressWrap) progressWrap.style.display = 'flex';
    if(progressBar) progressBar.style.width = '50%'; // Simple fake progress
    
    const fd = new FormData();
    fd.append('action', 'upload_media');
    fd.append('media_url', url);
    
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            window.location.hash = '#medien';
            window.location.reload();
        } else {
            alert("Fehler beim Laden von Google-Fotos: " + res.message);
            if(progressWrap) progressWrap.style.display = 'none';
        }
    })
    .catch(err => {
        alert("Netzwerkfehler beim Laden von Google-Fotos: " + err.message);
        if(progressWrap) progressWrap.style.display = 'none';
    });
}

function triggerMediaAction(actionName, mediaId) {
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = window.location.href;
    f.innerHTML = `<input type="hidden" name="action" value="${actionName}"><input type="hidden" name="media_id" value="${mediaId}">`;
    document.body.appendChild(f);
    f.submit();
}

/**
 * Real-time Live Inserat-Vorschau
 */
function updateLivePreview() {
    const statusSelect = document.getElementById('marketing_status');
    const titelInput = document.getElementById('marketing_titel');
    const descTextarea = document.getElementById('marketing_beschreibung');
    const h1Input = document.getElementById('marketing_highlight1');
    const h2Input = document.getElementById('marketing_highlight2');
    const h3Input = document.getElementById('marketing_highlight3');

    const pStatus = document.getElementById('preview_card_status');
    const pTitle = document.getElementById('preview_card_title');
    const pDesc = document.getElementById('preview_card_desc');
    const pPill1 = document.getElementById('preview_pill_1');
    const pPill2 = document.getElementById('preview_pill_2');
    const pPill3 = document.getElementById('preview_pill_3');

    // 1) Status
    if (statusSelect && pStatus) {
        const val = statusSelect.value;
        pStatus.className = 'preview-status ' + val;
        if (val === 'entwurf') pStatus.innerText = 'Entwurf';
        else if (val === 'aktiv') pStatus.innerText = 'Aktiv';
        else if (val === 'pausiert') pStatus.innerText = 'Pausiert';
    }

    // 2) Title
    if (titelInput && pTitle) {
        pTitle.innerText = titelInput.value.trim() || 'Noch kein Titel definiert';
    }

    // 3) Description
    if (descTextarea && pDesc) {
        pDesc.innerText = descTextarea.value.trim() || 'Geben Sie eine ansprechende Beschreibung ein...';
    }

    // 4) Highlights
    if (pPill1) {
        const val = h1Input ? h1Input.value.trim() : '';
        if (val) {
            pPill1.innerText = val;
            pPill1.style.display = 'inline-block';
        } else {
            pPill1.style.display = 'none';
        }
    }
    if (pPill2) {
        const val = h2Input ? h2Input.value.trim() : '';
        if (val) {
            pPill2.innerText = val;
            pPill2.style.display = 'inline-block';
        } else {
            pPill2.style.display = 'none';
        }
    }
    if (pPill3) {
        const val = h3Input ? h3Input.value.trim() : '';
        if (val) {
            pPill3.innerText = val;
            pPill3.style.display = 'inline-block';
        } else {
            pPill3.style.display = 'none';
        }
    }
}

/**
 * Automatisches Speichern von Bildunterschriften, Sortierung & Dokumentkategorien via AJAX
 */
function saveMediaMeta(mediaId, type, el) {
    const fd = new FormData();
    fd.append('action', 'update_media_meta');
    fd.append('meta_type', type);
    fd.append('media_id', mediaId);

    if (type === 'image') {
        const card = el.closest('.glass-card');
        const titel = card.querySelector('.img-titel-field').value;
        const sort = card.querySelector('.img-sort-field').value;
        fd.append('titel', titel);
        fd.append('sort_order', sort);
    } else if (type === 'document') {
        const container = el.closest('div');
        const titel = container.querySelector('.doc-titel-field').value;
        const kat = container.querySelector('.doc-kategorie-field').value;
        fd.append('titel', titel);
        fd.append('kategorie', kat);
    }

    const originalBorder = el.style.borderColor;
    el.style.borderColor = 'var(--primary)';

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            el.style.borderColor = '#22c55e';
            el.style.background = '#f0fdf4';
            setTimeout(() => {
                el.style.borderColor = originalBorder;
                el.style.background = '';
            }, 1000);
        } else {
            el.style.borderColor = '#ef4444';
            console.error("Fehler beim Speichern der Metadaten:", res.message);
        }
    })
    .catch(err => {
        el.style.borderColor = '#ef4444';
        console.error("Netzwerkfehler beim Speichern der Metadaten:", err);
    });
}

/**
 * Importiert Inserat-Daten (Titel, Beschreibung, Bilder) von Comparis via AJAX
 */
function scrapeComparis(isManual = false) {
    const urlInput = document.getElementById('link_comparis_input');
    const url = urlInput ? urlInput.value.trim() : '';

    if (!url) {
        alert("Bitte geben Sie eine gültige Comparis-URL in das Feld ein.");
        return;
    }
    if (!url.includes('comparis.ch')) {
        alert("Die eingegebene Adresse ist keine gültige Comparis-Domain.");
        return;
    }

    const fd = new FormData();
    fd.append('action', 'scrape_comparis');
    
    // Always use base64 encoding to prevent ModSecurity WAF blocks on URL/HTML parameters
    const encodedUrl = btoa(unescape(encodeURIComponent(url)));
    fd.append('comparis_url', encodedUrl);
    fd.append('is_base64', '1');

    let btn, loader, icon, text;

    if (isManual) {
        const htmlInput = document.getElementById('comparis_html_source');
        const html = htmlInput ? htmlInput.value.trim() : '';
        if (!html) {
            alert("Bitte fügen Sie den HTML-Quellcode in das Textfeld ein.");
            return;
        }
        // Base64-Codierung, um ModSecurity WAF-Blockaden beim POSTen von HTML-Tags zu umgehen
        const encodedHtml = btoa(unescape(encodeURIComponent(html)));
        fd.append('comparis_html', encodedHtml);

        btn = document.getElementById('btn_comparis_manual_import');
        if (btn) {
            btn.style.pointerEvents = 'none';
            btn.innerText = 'Wird importiert... ⌛';
        }
    } else {
        btn = document.getElementById('btn_comparis_scrape');
        loader = document.getElementById('btn_comparis_loader');
        icon = document.getElementById('btn_comparis_icon');
        text = document.getElementById('btn_comparis_text');

        // UI in Lade-Zustand versetzen
        if (btn) btn.style.pointerEvents = 'none';
        if (loader) loader.style.display = 'inline-block';
        if (icon) icon.style.display = 'none';
        if (text) text.innerText = 'Daten werden gesaugt... Bitte warten';
    }

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => {
        const ct = r.headers.get("content-type");
        if (ct && ct.includes("application/json")) {
            return r.json();
        } else {
            return r.text().then(text => {
                throw new Error("Der Server hat HTML/Text geliefert statt JSON. Details:\n" + text.substring(0, 800));
            });
        }
    })
    .then(res => {
        if (res.success) {
            if (isManual && btn) {
                btn.innerText = 'Erfolgreich! Geladen...';
            } else if (text) {
                text.innerText = 'Erfolgreich! Seite wird neu geladen...';
            }
            setTimeout(() => {
                window.location.hash = '#medien';
                window.location.reload();
            }, 1000);
        } else {
            alert("Fehler beim Importieren: " + res.message);
            if (isManual) {
                if (btn) {
                    btn.style.pointerEvents = 'auto';
                    btn.innerText = 'Quellcode importieren';
                }
            } else {
                if (btn) btn.style.pointerEvents = 'auto';
                if (loader) loader.style.display = 'none';
                if (icon) icon.style.display = 'inline-block';
                if (text) text.innerText = 'Daten & Bilder von Comparis importieren';
            }
        }
    })
    .catch(err => {
        alert("Netzwerkfehler: " + err.message);
        if (isManual) {
            if (btn) {
                btn.style.pointerEvents = 'auto';
                btn.innerText = 'Quellcode importieren';
            }
        } else {
            if (btn) btn.style.pointerEvents = 'auto';
            if (loader) loader.style.display = 'none';
            if (icon) icon.style.display = 'inline-block';
            if (text) text.innerText = 'Daten & Bilder von Comparis importieren';
        }
    });
}

// Initial laden & Preview rendern
loadRooms();
updateLivePreview();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
