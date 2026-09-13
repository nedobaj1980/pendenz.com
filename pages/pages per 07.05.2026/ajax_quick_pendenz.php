<?php
/**
 * pages/ajax_quick_pendenz.php
 * Backend for the Quick Capture Component.
 */
header('Content-Type: application/json; charset=UTF-8');

// Disable error display for JSON response
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// --- UPLOAD HELPERS (Adapted from pendenz_neu.php) ---
function qp_tableExists(mysqli $db, string $table): bool {
    $res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($table)."'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}
function qp_ensureDir(string $path): bool {
    return is_dir($path) || (@mkdir($path, 0777, true) || is_dir($path));
}
function qp_slugify(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name) ?? 'datei';
    return trim($name, '._-') ?: 'datei';
}
function qp_existingBenutzerId(mysqli $db, ?int $id): ?int {
    if (!$id || $id <= 0) return null;
    $stmt = $db->prepare('SELECT id FROM benutzer WHERE id = ? LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function qp_handleUploads(mysqli $db, int $pendenzId, ?int $userId): array {
    $messages = [];
    if (!qp_tableExists($db, 'pendenz_dateien')) return $messages;

    $basePublic = 'uploads/pendenzen/' . $pendenzId;
    $baseFs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pendenzen' . DIRECTORY_SEPARATOR . $pendenzId;

    $configs = [
        'bilder' => ['typ' => 'image', 'dir' => 'bilder', 'max' => 15*1024*1024, 'ext' => ['jpg','jpeg','png','gif','webp'], 'mime' => 'image/'],
        'dokumente' => ['typ' => 'file', 'dir' => 'dokumente', 'max' => 15*1024*1024, 'ext' => ['pdf','doc','docx','xls','xlsx','txt','zip'], 'mime' => '']
    ];

    foreach ($configs as $field => $cfg) {
        if (empty($_FILES[$field]['name'])) continue;
        
        $files = $_FILES[$field];
        $count = is_array($files['name']) ? count($files['name']) : 1;

        $targetDirFs = $baseFs . DIRECTORY_SEPARATOR . $cfg['dir'];
        qp_ensureDir($targetDirFs);

        for ($i=0; $i<$count; $i++) {
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $err  = is_array($files['error']) ? $files['error'][$i] : $files['error'];
            $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
            $type = is_array($files['type']) ? $files['type'][$i] : $files['type'];

            if ($err !== UPLOAD_ERR_OK || empty($name)) continue;
            if ($size <= 0 || $size > $cfg['max']) continue;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $cfg['ext'])) continue;
            if ($cfg['mime'] && strpos($type, $cfg['mime']) !== 0) continue;

            $stored = 'up-' . bin2hex(random_bytes(4)) . '-' . qp_slugify(pathinfo($name, PATHINFO_FILENAME)) . '.' . $ext;
            $targetFile = $targetDirFs . DIRECTORY_SEPARATOR . $stored;
            if (move_uploaded_file($tmp, $targetFile)) {
                // Automatische Verkleinerung auf max 0.5 MB
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    smart_resize_image($targetFile);
                }
                
                $path = $basePublic . '/' . $cfg['dir'] . '/' . $stored;
                $vUid = qp_existingBenutzerId($db, $userId);
                $stmt = $db->prepare("INSERT INTO pendenz_dateien (pendenz_id, typ, pfad, mimetype, groesse, titel, hochgeladen_von) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssisi", $pendenzId, $cfg['typ'], $path, $type, $size, $name, $vUid);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    return $messages;
}


if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

$action = $_GET['action'] ?? '';

// ACTION: Get Initial Data
if ($action === 'get_init_data') {
    try {
        $data = [
            'success' => true,
            'arten' => [],
            'projekte' => [],
            'objekte' => [],
            'wohnungen' => [],
            'vermieter_kategorien' => [],
            'vermieter_subkategorien' => [],
            'mieter_kategorien' => [],
            'mieter_subkategorien' => [],
            'bkp_codes' => [],
            'bkp_kategorien' => [],
            'bkp_texte' => [],
            'users' => [],
            'defaultsByArt' => [],
            'userFirmaBkpMap' => []
        ];


        // Arten mit Defaults
        try {
            $res = $mysqli->query("SELECT * FROM pendenzen_arten ORDER BY sort_order, name");
            if ($res) while ($row = $res->fetch_assoc()) $data['arten'][] = $row;
        } catch (Throwable $e) {
            try {
                // Fallback for servers missing sort_order
                $res = $mysqli->query("SELECT * FROM pendenzen_arten ORDER BY name");
                if ($res) while ($row = $res->fetch_assoc()) $data['arten'][] = $row;
            } catch (Throwable $e2) { /* ignore */ }
        }

        // Art Empfänger Defaults
        try {
            $data['defaultsByArt'] = [];
            if (qp_tableExists($mysqli, 'pendenzen_art_empfaenger_defaults')) {
                $res = $mysqli->query("SELECT * FROM pendenzen_art_empfaenger_defaults ORDER BY pendenz_art_id");
                if ($res) {
                    $tmpDef = [];
                    while ($row = $res->fetch_assoc()) {
                        $aId = (int)$row['pendenz_art_id'];
                        if (!isset($tmpDef[$aId])) {
                            $tmpDef[$aId] = [];
                        }
                        $tmpDef[$aId][] = $row;
                    }
                    foreach ($tmpDef as $aId => $rows) {
                        $data['defaultsByArt'][(string)$aId] = $rows;
                    }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        // Projekte
        try {
            $res = $mysqli->query("SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $data['projekte'][] = $row;
        } catch (Throwable $e) { /* ignore */ }

        // Objekte
        try {
            $res = $mysqli->query("SELECT id, projekt_id, name FROM objekte ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $data['objekte'][] = $row;
        } catch (Throwable $e) { /* ignore */ }

        // Wohnungen
        try {
            $res = $mysqli->query("SELECT id, objekt_id, name FROM wohnungen ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $data['wohnungen'][] = $row;
        } catch (Throwable $e) { /* ignore */ }

        // Raeume
        try {
            $res = $mysqli->query("SELECT id, wohnung_id, name FROM raeume ORDER BY name");
            if ($res) while ($row = $res->fetch_assoc()) $data['raeume'][] = $row;
        } catch (Throwable $e) { /* ignore */ }

        // Vermieter Kategorien
        try {
            $res = $mysqli->query("SELECT id, name FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY sort_order, name");
            if ($res) while ($row = $res->fetch_assoc()) $data['vermieter_kategorien'][] = $row;
        } catch (Throwable $e) { 
            try {
                $res = $mysqli->query("SELECT id, name FROM pendenz_kategorien_vermieter ORDER BY name");
                if ($res) while ($row = $res->fetch_assoc()) $data['vermieter_kategorien'][] = $row;
            } catch (Throwable $e2) { /* ignore */ }
        }

        // Vermieter Subkategorien
        try {
            $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung FROM pendenz_subkategorien_vermieter WHERE aktiv = 1 ORDER BY sort_order, name");
            if ($res) while ($row = $res->fetch_assoc()) $data['vermieter_subkategorien'][] = $row;
        } catch (Throwable $e) { 
            try {
                $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung FROM pendenz_subkategorien_vermieter ORDER BY name");
                if ($res) while ($row = $res->fetch_assoc()) $data['vermieter_subkategorien'][] = $row;
            } catch (Throwable $e2) { /* ignore */ }
        }

        // Mieter Kategorien
        try {
            $res = $mysqli->query("SELECT id, name FROM pendenz_kategorien_mieter WHERE aktiv = 1 ORDER BY sort_order, name");
            if ($res) while ($row = $res->fetch_assoc()) $data['mieter_kategorien'][] = $row;
        } catch (Throwable $e) { 
            try {
                $res = $mysqli->query("SELECT id, name FROM pendenz_kategorien_mieter ORDER BY name");
                if ($res) while ($row = $res->fetch_assoc()) $data['mieter_kategorien'][] = $row;
            } catch (Throwable $e2) { /* ignore */ }
        }

        // Mieter Subkategorien
        try {
            $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung FROM pendenz_subkategorien_mieter WHERE aktiv = 1 ORDER BY sort_order, name");
            if ($res) while ($row = $res->fetch_assoc()) $data['mieter_subkategorien'][] = $row;
        } catch (Throwable $e) { 
            try {
                $res = $mysqli->query("SELECT id, kategorie_id, name, beschreibung FROM pendenz_subkategorien_mieter ORDER BY name");
                if ($res) while ($row = $res->fetch_assoc()) $data['mieter_subkategorien'][] = $row;
            } catch (Throwable $e2) { /* ignore */ }
        }

        // BKP Codes
        try {
            $res = $mysqli->query("SELECT id, code, bezeichnung FROM bkp_codes ORDER BY code");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $row['name'] = $row['code'] . ' ' . $row['bezeichnung'];
                    $data['bkp_codes'][] = $row;
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        // BKP Kategorien
        try {
            if (qp_tableExists($mysqli, 'bkp_kategorien')) {
                $res = $mysqli->query("SELECT id, bkp_id, name FROM bkp_kategorien ORDER BY name");
                if ($res) while ($row = $res->fetch_assoc()) $data['bkp_kategorien'][] = $row;
            }
        } catch (Throwable $e) { /* ignore */ }

        // BKP Texte
        try {
            if (qp_tableExists($mysqli, 'bkp_vorlagen_texte')) {
                $res = $mysqli->query("SELECT id, kategorie_id, text FROM bkp_vorlagen_texte ORDER BY id");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $row['name'] = $row['text'];
                        $data['bkp_texte'][] = $row;
                    }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        // Users
        try {
            $hasFirmaUser = qp_tableExists($mysqli, 'firma_user') && qp_tableExists($mysqli, 'firmen');
            $userSql = "SELECT b.id, b.name, b.email, b.rolle, ";
            if ($hasFirmaUser) {
                $userSql .= "COALESCE(f.name, b.firma_name, '') as firma_name FROM benutzer b LEFT JOIN firma_user fu ON fu.user_id = b.id AND fu.is_primary = 1 LEFT JOIN firmen f ON f.id = fu.firma_id";
            } else {
                $userSql .= "b.firma_name FROM benutzer b";
            }
            $userSql .= " ORDER BY b.name ASC";
            
            $res = $mysqli->query($userSql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $label = $row['name'] . (!empty($row['firma_name']) ? ' · ' . $row['firma_name'] : '');
                    $row['label'] = $label;
                    $data['users'][] = $row;
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        // userFirmaBkpMap for filtering BKPs based on user's company (exactly like pendenz_neu.php)
        try {
            $data['userFirmaBkpMap'] = [];
            $hasFirmaVorlagenMap = qp_tableExists($mysqli, 'firmen_vorlagen_map');
            
            if ((isset($hasFirmaUser) ? $hasFirmaUser : false) && $hasFirmaVorlagenMap) {
                $sql = "
                    SELECT fu.user_id, fvm.ref_id AS bkp_id
                    FROM firma_user fu
                    INNER JOIN firmen_vorlagen_map fvm ON fvm.firma_id = fu.firma_id AND fvm.welt = 'bkp'
                    WHERE fu.user_id IS NOT NULL
                    ORDER BY fu.user_id ASC, fvm.ref_id ASC
                ";
                $res = $mysqli->query($sql);
                
                if ($res) {
                    $tmpMap = [];
                    while ($row = $res->fetch_assoc()) {
                        $uid = (int) ($row['user_id'] ?? 0);
                        $bid = (int) ($row['bkp_id'] ?? 0);
                        if ($uid > 0 && $bid > 0) {
                            if (!isset($tmpMap[$uid])) $tmpMap[$uid] = [];
                            $tmpMap[$uid][$bid] = $bid;
                        }
                    }
                    
                    foreach ($tmpMap as $uid => $ids) {
                        $data['userFirmaBkpMap'][(string)$uid] = array_values($ids);
                    }
                }
            }
        } catch (Throwable $e) { /* ignore */ }

        echo json_encode($data);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ACTION: Save (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $currentUserId = $_SESSION['user_id'] ?? $_SESSION['benutzer_id'] ?? 0;
        
        $vorgangsart_id = (int)($_POST['vorgangsart_id'] ?? 0);
        if (!$vorgangsart_id) throw new Exception("Vorgangsart fehlt.");

        $projekt_id = (int)($_POST['projekt_id'] ?? 0) ?: null;
        $objekt_id = (int)($_POST['objekt_id'] ?? 0) ?: null;
        $wohnung_id = (int)($_POST['wohnung_id'] ?? 0) ?: null;
        $raum_id = (int)($_POST['raum_id'] ?? 0) ?: null;
        
        $bkp_id = (int)($_POST['bkp_id'] ?? 0) ?: null;
        $bkp_kat_id = (int)($_POST['bkp_kategorie_id'] ?? 0) ?: null;
        $bkp_txt_id = (int)($_POST['bkp_text_id'] ?? 0) ?: null;

        $m_kat_id = (int)($_POST['mieter_kategorie_id'] ?? 0);
        $m_sub_id = (int)($_POST['mieter_subkategorie_id'] ?? 0);

        $kategorie_id = (int)($_POST['vermieter_kategorie_id'] ?? 0);
        $subkategorie_id = (int)($_POST['vermieter_subkategorie_id'] ?? 0);

        
        $titel_manuell = trim($_POST['titel_manuell'] ?? $_POST['titel_manuell_m'] ?? $_POST['titel_manuell_v'] ?? $_POST['titel_manuell_bkp'] ?? '');
        $kurz_manuell = trim($_POST['kurzbeschreibung_manuell'] ?? $_POST['kurzbeschreibung_manuell_m'] ?? $_POST['kurzbeschreibung_manuell_v'] ?? $_POST['kurzbeschreibung_manuell_bkp'] ?? '');
        $beschr_manuell = trim($_POST['beschreibung_manuell'] ?? '');
        $notiz_manuell = trim($_POST['notiz_manuell'] ?? '');
        $wichtigkeit = (int)($_POST['wichtigkeit'] ?? 3);
        
        $status = $_POST['status'] ?? 'offen';
        $startdatum = !empty($_POST['startdatum']) ? $_POST['startdatum'] : date('Y-m-d');
        $enddatum = !empty($_POST['enddatum']) ? $_POST['enddatum'] : null;
        $uhrzeit = !empty($_POST['uhrzeit']) ? $_POST['uhrzeit'] : null;
        $dauer = (int)($_POST['dauer'] ?? 0);

        // Normalize Dates (Simple version for Quick Capture)
        if ($enddatum === null && $dauer > 0) {
            $enddatum = date('Y-m-d', strtotime("+$dauer days", strtotime($startdatum)));
        }

        // Logic to build final text (similar to pendenz_neu.php)
        $dynTitel = '';
        $dynKurz = '';
        $dynBeschr = '';

        if ($kategorie_id) {
            $res = $mysqli->query("SELECT name FROM pendenz_kategorien_vermieter WHERE id = $kategorie_id")->fetch_assoc();
            $dynTitel = $res['name'] ?? '';
        }
        if ($subkategorie_id) {
            $res = $mysqli->query("SELECT name, beschreibung FROM pendenz_subkategorien_vermieter WHERE id = $subkategorie_id")->fetch_assoc();
            $dynKurz = $res['name'] ?? '';
            $dynBeschr = $res['beschreibung'] ?? '';
        }

        if ($m_kat_id) {
            $res = $mysqli->query("SELECT name FROM pendenz_kategorien_mieter WHERE id = $m_kat_id")->fetch_assoc();
            $dynTitel = $res['name'] ?? $dynTitel;
        }
        if ($m_sub_id) {
            $res = $mysqli->query("SELECT name, beschreibung FROM pendenz_subkategorien_mieter WHERE id = $m_sub_id")->fetch_assoc();
            $dynKurz = $res['name'] ?? $dynKurz;
            $dynBeschr = $res['beschreibung'] ?? $dynBeschr;
        }

        if ($bkp_kat_id) {
            $res = $mysqli->query("SELECT name FROM bkp_kategorien WHERE id = $bkp_kat_id")->fetch_assoc();
            $dynTitel = $res['name'] ?? $dynTitel;
        }
        if ($bkp_txt_id) {
            $res = $mysqli->query("SELECT text FROM bkp_vorlagen_texte WHERE id = $bkp_txt_id");
            if ($res) {
                $row = $res->fetch_assoc();
                if ($row) {
                    $dynKurz = $row['text'] ?: $dynKurz;
                }
            }
        }


        $finalTitel = implode(', ', array_filter([$titel_manuell, $dynTitel]));
        $finalKurz = implode(', ', array_filter([$kurz_manuell, $dynKurz]));
        $finalBeschr = implode("\n\n", array_filter([$beschr_manuell, $dynBeschr]));

        if (empty($finalTitel)) throw new Exception("Titel fehlt.");

        // Options
        $send_now = isset($_POST['send_now']) ? 1 : 0;
        $confirmation_required = isset($_POST['confirmation_required']) ? 1 : 0;
        $external_can_view = isset($_POST['external_can_view']) ? 1 : 0;
        $external_can_upload = isset($_POST['external_can_upload']) ? 1 : 0;
        $public_enabled = isset($_POST['public_enabled']) ? 1 : 0;

        // Extra JSON
        $extra_json = json_encode([
            'vermieter_kategorie_id' => $kategorie_id,
            'vermieter_subkategorie_id' => $subkategorie_id,
            'mieter_kategorie_id' => $m_kat_id,
            'mieter_subkategorie_id' => $m_sub_id,
            'bkp_id' => $bkp_id,
            'bkp_kategorie_id' => $bkp_kat_id,
            'bkp_text_id' => $bkp_txt_id,
            'raum_id' => $raum_id,
            'source' => 'quick_capture'
        ]);

        // Zustaendig_id logic
        $zustaendig_id = (int)($_POST['zustaendig_id'] ?? $currentUserId) ?: null;

        // Check which optional columns actually exist in the online DB
        function qp_colExists(mysqli $db, string $table, string $col): bool {
            $t = $db->real_escape_string($table);
            $c = $db->real_escape_string($col);
            $r = $db->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
            return $r && $r->num_rows > 0;
        }

        // Build dynamic INSERT – only include columns that exist
        $cols   = ['projekt_id', 'objekt_id', 'wohnung_id', 'vorgangsart_id',
                   'bkp_id', 'titel', 'kurzbeschreibung', 'notiz',
                   'status', 'startdatum', 'enddatum', 'uhrzeit', 'dauer',
                   'send_now', 'confirmation_required', 'external_can_view',
                   'external_can_upload', 'public_enabled',
                   'erstellt_von', 'zustaendig_id', 'zustaendig_typ', 'extra_json', 'erstellt_am'];

        $vals   = [$projekt_id, $objekt_id, $wohnung_id, $vorgangsart_id,
                   $bkp_id, $finalTitel, $finalKurz, $notiz_manuell,
                   $status, $startdatum, $enddatum, $uhrzeit, $dauer,
                   $send_now, $confirmation_required, $external_can_view,
                   $external_can_upload, $public_enabled,
                   $currentUserId, $zustaendig_id, 'user', $extra_json, date('Y-m-d H:i:s')];

        // Optional columns – only add if they exist in DB
        $optional = [
            'raum_id'               => $raum_id,
            'wichtigkeit'           => $wichtigkeit,
            'langbeschreibung'      => $finalBeschr,
            'empfaenger_benutzer_id'=> $zustaendig_id,
            'ersteller_benutzer_id' => $currentUserId,
        ];
        foreach ($optional as $optCol => $optVal) {
            if (qp_colExists($mysqli, 'pendenzen', $optCol)) {
                $cols[] = $optCol;
                $vals[] = $optVal;
            }
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $colList      = implode(', ', $cols);
        $sql          = "INSERT INTO pendenzen ($colList) VALUES ($placeholders)";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception('Prepare: ' . $mysqli->error);

        // Build bind types dynamically
        $types = '';
        foreach ($vals as $v) {
            $types .= (is_int($v) || is_null($v)) ? 'i' : 's';
        }
        $stmt->bind_param($types, ...$vals);



        if (!$stmt->execute()) throw new Exception($stmt->error);

        $newId = $mysqli->insert_id;
        qp_handleUploads($mysqli, $newId, $currentUserId);

        echo json_encode(['success' => true, 'pendenz_id' => $newId]);


    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
