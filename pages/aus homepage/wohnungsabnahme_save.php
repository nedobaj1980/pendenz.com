<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Error logging setup
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    @file_put_contents(__DIR__ . '/../logs/wohnungsabnahme_debug.log', "[" . date('Y-m-d H:i:s') . "] ERROR [$errno] $errstr in $errfile:$errline\n", FILE_APPEND);
    return true;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        @file_put_contents(__DIR__ . '/../logs/wohnungsabnahme_debug.log', "[" . date('Y-m-d H:i:s') . "] FATAL: {$error['message']} in {$error['file']}:{$error['line']}\n", FILE_APPEND);
    }
});

use Dompdf\Dompdf;
use Dompdf\Options;

header('Content-Type: application/json');

$pid = (int) ($_GET['projekt_id'] ?? 0);
$uid = (int) ($_GET['unit_id'] ?? 0);
$protocolId = (int) ($_GET['protocol_id'] ?? $_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$basePath = dirname(__DIR__);
$protocolDir = $basePath . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'protocols';
if (!is_dir($protocolDir)) {
    @mkdir($protocolDir, 0777, true);
}

function logDebug($msg) {
    global $basePath;
    $logFile = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'wohnungsabnahme_debug.log';
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

function getSignatureHtml($sigStr) {
    if (empty($sigStr)) {
        return '';
    }
    if (strpos($sigStr, 'data:') !== 0) {
        $sigStr = 'data:image/png;base64,' . $sigStr;
    }
    return '<img src="' . $sigStr . '" class="sig-img">';
}

function findBestMatchingRoom($mysqli, $uid, $protocolRoomName) {
    if (empty($protocolRoomName)) {
        return null;
    }
    
    $protocolRoomNameLower = mb_strtolower($protocolRoomName, 'UTF-8');
    
    // 1. Try exact match
    $stmt = $mysqli->prepare("SELECT id FROM raeume WHERE wohnung_id = ? AND LOWER(name) = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("is", $uid, $protocolRoomNameLower);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return (int)$row['id'];
        }
        $stmt->close();
    }
    
    // 2. Fetch all rooms for this apartment to perform a smart keyword match
    $rooms = [];
    $res = $mysqli->query("SELECT id, name FROM raeume WHERE wohnung_id = " . (int)$uid);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rooms[] = [
                'id' => (int)$row['id'],
                'name' => mb_strtolower($row['name'], 'UTF-8')
            ];
        }
    }
    
    if (empty($rooms)) {
        return null;
    }
    
    // Define keyword mappings
    $mappings = [
        'küche' => ['küch', 'ess', 'wohn'],
        'bad/dusche/wc' => ['bad', 'dusch', 'wc', 'nass', 'toilette'],
        'separater nassraum' => ['bad', 'dusch', 'wc', 'nass', 'toilette'],
        'korridor' => ['entr', 'korr', 'gang', 'diele', 'vorplatz', 'flur'],
        'wohnzimmer' => ['wohn', 'ess'],
        'schlafzimmer' => ['zimmer', 'schlaf', 'eltern', 'kind', 'gast', 'büro'],
        'zimmer 1' => ['zimmer', 'schlaf', 'eltern', 'kind', 'gast', 'büro'],
        'zimmer 2' => ['zimmer', 'schlaf', 'eltern', 'kind', 'gast', 'büro'],
        'zimmer 3' => ['zimmer', 'schlaf', 'eltern', 'kind', 'gast', 'büro'],
        'bastelraum' => ['bastel', 'zimmer', 'kell'],
        'waschküche' => ['wasch', 'kell'],
        'diverses' => ['kell', 'estrich', 'abstell', 'garag', 'balk', 'terr', 'sitz', 'gart']
    ];
    
    // Get search keywords for the current protocol room
    $keywords = $mappings[$protocolRoomNameLower] ?? [$protocolRoomNameLower];
    
    // Try to find a room name that contains any of these keywords
    foreach ($rooms as $room) {
        foreach ($keywords as $kw) {
            if (mb_strpos($room['name'], $kw) !== false) {
                return $room['id'];
            }
        }
    }
    
    // Fallback: Try reverse matching
    foreach ($rooms as $room) {
        if (mb_strpos($protocolRoomNameLower, $room['name']) !== false || mb_strpos($room['name'], $protocolRoomNameLower) !== false) {
            return $room['id'];
        }
    }
    
    return null;
}

// Proactive check of the protocol directory
logDebug("Request Action: $action | PID: $pid | UID: $uid | ID: $protocolId");

// AJAX single photo upload handler
if ($action === 'upload_photo') {
    if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Ungültiger Dateityp (nur JPG, PNG, GIF, WEBP)']);
            exit;
        }
        $photoName = 'Abnahme_Wohnung_Foto_' . md5(uniqid('', true)) . '.' . $ext;
        $targetFile = $protocolDir . DIRECTORY_SEPARATOR . $photoName;
        if (@move_uploaded_file($_FILES['photo']['tmp_name'], $targetFile)) {
            smart_resize_image($targetFile); // Automatically compress to max 0.5 MB
            echo json_encode(['success' => true, 'filename' => $photoName]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Datei konnte nicht verschoben werden']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Keine Datei übertragen']);
    }
    exit;
}

// AJAX delete protocol
if ($action === 'delete' && $protocolId > 0) {
    $mysqli->query("DELETE FROM abnahme_protokolle WHERE id = $protocolId");
    echo json_encode(['success' => true]);
    exit;
}

// AJAX rename protocol
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
        echo json_encode(['success' => false, 'error' => 'Protokoll nicht gefunden']);
    }
    exit;
}

// AJAX sync Pendenzen (tasks) into main task list
if ($action === 'sync_pendenzen') {
    try {
        $defects = $_POST['defects'] ?? [];
        $userId = $_SESSION['user_id'] ?? 0;
        
        $unit = $mysqli->query("SELECT w.*, o.projekt_id, o.id as obj_id FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE w.id = $uid")->fetch_assoc();
        if (!$unit) {
            throw new Exception("Wohnung nicht gefunden.");
        }
        
        $pendenzenSynced = [];
        
        foreach ($defects as $posId => $def) {
            if (empty($def['as_pendenz'])) continue;
            
            $pTitel = "Pos " . $posId . ": " . ($def['room'] ?? '') . " - " . ($def['item'] ?? '');
            $comment = $def['beschreibung'] ?? '';
            $pErsteller = $userId;
            $pZustaendig = !empty($def['responsible']) ? (int)$def['responsible'] : null;
            $pStart = date('Y-m-d');
            $pEnde = !empty($def['bis_datum']) ? $def['bis_datum'] : null;
            
            $pDauer = 0;
            if (!empty($pEnde)) {
                $dateStart = new DateTime($pStart);
                $dateEnd = new DateTime($pEnde);
                if ($dateEnd >= $dateStart) {
                    $diff = $dateStart->diff($dateEnd);
                    $pDauer = (int)$diff->days;
                }
            }
            
            $pPrio = !empty($def['priority']) ? (int)$def['priority'] : 3;
            $vorgangsartId = 3; // Default 'Mahnung / Mangel / Pendenzen' depending on taxonomy
            $pRaumId = null; // Can find or default
            $photoName = $def['photo'] ?? '';
            $pFoto = $photoName ? 'uploads/protocols/' . $photoName : null;
            
            // Check if there is an existing room matching by name
            $roomName = $def['room'] ?? '';
            $pRaumId = null;
            if (!empty($roomName)) {
                $pRaumId = findBestMatchingRoom($mysqli, $uid, $roomName);
            }
            // Look up or assign default
            $existingId = !empty($def['pendenz_id']) ? (int)$def['pendenz_id'] : 0;
            $targetPendenzId = 0;
            
            if ($existingId > 0) {
                // Update existing task
                $stmtP = $mysqli->prepare("UPDATE pendenzen SET titel=?, kurzbeschreibung=?, zustaendig_id=?, enddatum=?, wichtigkeit=?, raum_id=?, dauer=?, geaendert_am=NOW() WHERE id=?");
                $stmtP->bind_param("ssisiiii", $pTitel, $comment, $pZustaendig, $pEnde, $pPrio, $pRaumId, $pDauer, $existingId);
                $stmtP->execute();
                $targetPendenzId = $existingId;
            } else {
                // Create new task
                $stmtP = $mysqli->prepare("INSERT INTO pendenzen (projekt_id, objekt_id, wohnung_id, titel, kurzbeschreibung, vorgangsart_id, status, erstellt_von, zustaendig_id, startdatum, enddatum, wichtigkeit, raum_id, dauer, created_at) VALUES (?, ?, ?, ?, ?, ?, 'offen', ?, ?, ?, ?, ?, ?, ?, NOW())");
                $objId = (int)$unit['obj_id'];
                $stmtP->bind_param("iiissiiissiii", $pid, $objId, $uid, $pTitel, $comment, $vorgangsartId, $pErsteller, $pZustaendig, $pStart, $pEnde, $pPrio, $pRaumId, $pDauer);
                if ($stmtP->execute()) {
                    $targetPendenzId = $stmtP->insert_id;
                    $defects[$posId]['pendenz_id'] = $targetPendenzId;
                }
            }
            
            // Photo linking
            if ($targetPendenzId > 0 && $pFoto) {
                $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id = $targetPendenzId AND pfad LIKE 'uploads/protocols/%'");
                $mime = @mime_content_type($protocolDir . DIRECTORY_SEPARATOR . $photoName) ?: 'image/jpeg';
                $stmtA = $mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id, pfad, typ, mimetype, is_cover) VALUES (?, ?, 'image', ?, 1)");
                if ($stmtA) {
                    $stmtA->bind_param("iss", $targetPendenzId, $pFoto, $mime);
                    $stmtA->execute();
                }
            }
            
            $pendenzenSynced[$posId] = $targetPendenzId;
        }
        
        // Save current form post with mapped pendenz IDs
        if ($protocolId > 0) {
            $existingRes = $mysqli->query("SELECT daten_json FROM abnahme_protokolle WHERE id = $protocolId");
            if ($existingRes && $eRow = $existingRes->fetch_assoc()) {
                $existingData = json_decode($eRow['daten_json'], true) ?: [];
                
                // Update defects in saved post data
                $postCopy = $_POST;
                $postCopy['defects'] = $defects;
                $merged = array_merge($existingData, $postCopy);
                
                $json = $mysqli->real_escape_string(json_encode($merged));
                $mysqli->query("UPDATE abnahme_protokolle SET daten_json = '$json' WHERE id = $protocolId");
            }
        }
        
        echo json_encode(['success' => true, 'synced_ids' => $pendenzenSynced]);
    } catch (Throwable $e) {
        logDebug("ERROR in Sync Pendenzen: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Master Save Handler
try {
    $isDraft = isset($_POST['is_draft']) && $_POST['is_draft'] == '1';
    
    $unit = $mysqli->query("SELECT w.*, p.name as p_name, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid")->fetch_assoc();
    if (!$unit) {
        throw new Exception("Wohnung nicht gefunden.");
    }
    
    // Resolve tenant name
    $mieterName = '';
    if ($_POST['mieter_id'] === 'custom') {
        $mieterName = $_POST['mieter_name_custom'] ?? 'N/A';
    } else {
        $mId = (int)$_POST['mieter_id'];
        if ($mId > 0) {
            $mRes = $mysqli->query("SELECT mieter_name FROM wohnung_mieter WHERE id = $mId");
            if ($mRow = $mRes->fetch_assoc()) {
                $mieterName = $mRow['mieter_name'];
            }
        }
    }
    
    $userId = $_SESSION['user_id'] ?? 0;
    $userRes = $mysqli->query("SELECT name, firma_name FROM benutzer WHERE id = $userId")->fetch_assoc();
    $userName = $userRes['name'] ?? 'Unbekannt';
    $firmaName = $userRes['firma_name'] ?? 'Baupartnerschaft AG';
    
    $filename = null;
    
    if (!$isDraft) {
        // Compile template
        $templatePath = $basePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'wohnungsabnahme_template.html';
        if (!file_exists($templatePath)) {
            throw new Exception("Protokoll-Template fehlt. Gesuchter Pfad: " . $templatePath . " (Aktuelles Verzeichnis: " . __DIR__ . ")");
        }
        $html = file_get_contents($templatePath);
        
        $checkedList = $_POST['checkboxes'] ?? [];
        $paintWände = $_POST['paint_wände'] ?? [];
        $paintHolz = $_POST['paint_holz'] ?? [];
        $paintBoden = $_POST['paint_boden'] ?? [];
        $defects = $_POST['defects'] ?? [];

        // Definition der 215 Positionen gruppiert nach Räumen
        $pdf_rooms = [
            'Küche' => [
                'fresh_paint_options' => true,
                'items' => [
                    1 => 'Boden', 2 => 'Wände', 3 => 'Decke', 4 => 'Schränke oben', 5 => 'Schränke unten', 
                    6 => 'Plättli', 7 => 'Türen', 8 => 'Schloss/Schlüssel', 9 => 'Fenster DV/IV', 10 => 'Rollläden', 
                    11 => 'Gurten/Kurbeln', 12 => 'Vorhangbrett/-schienen', 13 => 'Heizkörper/-ventil', 
                    14 => 'Backofen', '14a' => 'Backofenzubehör', 15 => 'Blech', 16 => 'Grill', 17 => 'Rost', 
                    18 => 'Herd', 19 => 'Dunstabzugshaube', 20 => 'Schüttstein/Chromstahl', 21 => 'Batterie', 
                    22 => 'Kühlschrank/Tiefkühler', 23 => 'Elektr./Schalter/Stecker', 24 => 'Geschirrspüler', 
                    25 => 'Zusatz 25', 26 => 'Zusatz 26', 27 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Bad/Dusche/WC' => [
                'fresh_paint_options' => true,
                'items' => [
                    28 => 'Boden', 29 => 'Plättli', 30 => 'Wände', 31 => 'Decke', 32 => 'Türen', 
                    33 => 'Schloss/Schlüssel', 34 => 'Fenster', 35 => 'Lüftung', 36 => 'Rollläden', 
                    37 => 'Gurten/Kurbeln', 38 => 'Elektr./Schalter/Stecker', 39 => 'Wanne/Dusche', 
                    40 => 'Batterie', 41 => 'Brause/Schlauch', 42 => 'Badetuchstange', 43 => 'Seifenhalter/Schale', 
                    44 => 'Klosett / Spülkasten / WC-Brille', 45 => 'Papierhalter', 46 => 'Lavabo', 
                    47 => 'Batterie', 48 => 'Spiegel/-kasten', 49 => 'Zusatz 49', 50 => 'Tablare', 
                    51 => 'Wandschränke', 52 => 'Heizkörper/-ventil', 53 => 'Zusatz 53', 54 => 'Zusatz 54', 
                    55 => 'Zusatz 55', 56 => 'Zusatz 56', 57 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Separater Nassraum' => [
                'fresh_paint_options' => true,
                'items' => [
                    58 => 'Boden', 59 => 'Plättli', 60 => 'Wände', 61 => 'Decke', 62 => 'Türen', 
                    63 => 'Fenster', 64 => 'Klosett (Spülkasten / WC-Brille)', 65 => 'Papierhalter', 
                    66 => 'Lavabo', 67 => 'Wanne/Dusche', 68 => 'Batterie', 69 => 'Brause/Schlauch', 
                    70 => 'Badetuchstange', 71 => 'Seifenhalter/Schale', 72 => 'Spiegel/-kasten', 
                    73 => 'Glashalter/Glas', 74 => 'Zusatz 74', 75 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Korridor' => [
                'fresh_paint_options' => true,
                'items' => [
                    76 => 'Boden', 77 => 'Wände', 78 => 'Decke', 79 => 'Eingangstüre', 80 => 'Türen', 
                    81 => 'Fenster', 82 => 'Wandschränke', 83 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Wohnzimmer' => [
                'fresh_paint_options' => true,
                'items' => [
                    84 => 'Boden', 85 => 'Wände', 86 => 'Decke', 87 => 'Türe', 88 => 'Schloss/Schlüssel', 
                    89 => 'Fenstertüren', 90 => 'Fenster DV/IV', 91 => 'Simse', 92 => 'Vorhangbrett', 
                    93 => 'Rollläden', 94 => 'Gurten/Kurbel', 95 => 'Elektr./Schalter/Stecker', 
                    96 => 'TV-/Telefonanschluss', 97 => 'Heizkörper/-ventil', 98 => 'Wandschränke', 
                    99 => 'Balkon/Sitzplatz', 100 => 'Sonnenstoren', 101 => 'Gurten/Kurbeln', 
                    102 => 'Zusatz 102', 103 => 'Zusatz 103', 104 => 'Zusatz 104', 
                    105 => 'Zusatz 105', 106 => 'Zusatz 106', 107 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Schlafzimmer' => [
                'fresh_paint_options' => true,
                'items' => [
                    108 => 'Boden', 109 => 'Wände', 110 => 'Decke', 111 => 'Türe', 112 => 'Schloss/Schlüssel', 
                    113 => 'Fenstertüren', 114 => 'Fenster DV/IV', 115 => 'Simse', 116 => 'Rollläden', 
                    117 => 'Gurten/Kurbeln', 118 => 'Elektr./Schalter/Stecker', 119 => 'Wandschränke', 
                    120 => 'Heizkörper/-ventil', 121 => 'Zusatz 121', 122 => 'Zusatz 122', 
                    123 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Zimmer 1' => [
                'fresh_paint_options' => true,
                'items' => [
                    124 => 'Boden', 125 => 'Wände', 126 => 'Decke', 127 => 'Türe', 128 => 'Schloss/Schlüssel', 
                    129 => 'Fenstertüren', 130 => 'Fenster DV/IV', 131 => 'Simse', 132 => 'Rollläden', 
                    133 => 'Gurten/Kurbeln', 134 => 'Elektr./Schalter/Stecker', 135 => 'Wandschränke', 
                    136 => 'Heizkörper/-ventil', 137 => 'Zusatz 137', 138 => 'Zusatz 138', 
                    139 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Zimmer 2' => [
                'fresh_paint_options' => true,
                'items' => [
                    140 => 'Boden', 141 => 'Wände', 142 => 'Decke', 143 => 'Türe', 144 => 'Schloss/Schlüssel', 
                    145 => 'Fenstertüren', 146 => 'Fenster DV/IV', 147 => 'Simse', 148 => 'Rollläden', 
                    149 => 'Gurten/Kurbeln', 150 => 'Elektr./Schalter/Stecker', 151 => 'Wandschränke', 
                    152 => 'Heizkörper/-ventil', 153 => 'Zusatz 153', 154 => 'Zusatz 154', 
                    155 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Zimmer 3' => [
                'fresh_paint_options' => true,
                'items' => [
                    156 => 'Boden', 157 => 'Wände', 158 => 'Decke', 159 => 'Türe', 160 => 'Schloss/Schlüssel', 
                    161 => 'Fenstertüren', 162 => 'Fenster DV/IV', 163 => 'Simse', 164 => 'Rollläden', 
                    165 => 'Gurten/Kurbeln', 166 => 'Elektr./Schalter/Stecker', 167 => 'Wandschränke', 
                    168 => 'Heizkörper/-ventil', 169 => 'Zusatz 169', 170 => 'Zusatz 170', 
                    171 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Bastelraum' => [
                'fresh_paint_options' => true,
                'items' => [
                    172 => 'Boden', 173 => 'Wände', 174 => 'Decke', 175 => 'Türe', 176 => 'Schloss/Schlüssel', 
                    177 => 'Fenster DV/IV', 178 => 'Simse', 179 => 'Rollläden', 180 => 'Gurten/Kurbeln', 
                    181 => 'Elektr./Schalter/Stecker', 182 => 'Wandschränke', 183 => 'Heizkörper/-ventil', 
                    184 => 'Zusatz 184', 185 => 'Zusatz 185', 186 => 'Zusatz 186', 187 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Waschküche' => [
                'fresh_paint_options' => true,
                'items' => [
                    188 => 'Boden', 189 => 'Wände', 190 => 'Decke', 191 => 'Türe', 192 => 'Schloss/Schlüssel', 
                    193 => 'Fenster', 194 => 'Lüftung', 195 => 'Schlüssel (Anzahl)'
                ]
            ],
            'Diverses' => [
                'fresh_paint_options' => false,
                'items' => [
                    196 => 'Sonnenstoren (Balkon)', 197 => 'Gurten (Balkon)', 198 => 'Hurde (Balkon)', 
                    199 => 'Fenster (Balkon)', 200 => 'Schlüssel (Balkon, Anzahl)', 201 => 'Garage/Abstellplatz', 
                    202 => 'Garage Schlüssel (Anzahl)', 203 => 'Keller', 204 => 'Estrich', 
                    205 => 'Keller/Estrich Schlüssel (Anzahl)', 206 => 'Brief-/Milchkasten Schlüssel (Anzahl)', 
                    207 => 'Küche (Übrige Schlüssel)', 208 => 'Bad/Dusche/WC (Übrige Schlüssel)', 
                    209 => 'Separates WC (Übrige Schlüssel)', 210 => 'Korridor (Übrige Schlüssel)', 
                    211 => 'Haustür Schlüssel (Anzahl)', 212 => 'Wohn.-Tür Schlüssel (Anzahl)', 
                    213 => 'Weiteres 1', 214 => 'Weiteres 2', 215 => 'Weiteres 3'
                ]
            ]
        ];

        if (!function_exists('getChecklistColumnHtml')) {
            function getChecklistColumnHtml($roomName, $roomInfo, $checkedList, $paintWände, $paintHolz, $paintBoden, $defects = []) {
                global $protocolDir;
                $items = $roomInfo['items'];
                $roomItems = array_values($items);
                $itemKeys = array_keys($items);
                $totalItems = count($roomItems);
                $rowCount = ceil($totalItems / 4);
                
                $html = '<div class="room-block"><table class="compact-room-table">';
                $html .= '<tr><th colspan="4" class="room-title-cell" style="padding: 3px 5px;">';
                if ($roomInfo['fresh_paint_options']) {
                    $wChecked = !empty($paintWände[$roomName]) ? '<span style="font-family: DejaVu Sans; font-size: 7.5pt; vertical-align: middle;">&#9746;</span>' : '<span style="font-family: DejaVu Sans; font-size: 7.5pt; vertical-align: middle;">&#9744;</span>';
                    $hChecked = !empty($paintHolz[$roomName]) ? '<span style="font-family: DejaVu Sans; font-size: 7.5pt; vertical-align: middle;">&#9746;</span>' : '<span style="font-family: DejaVu Sans; font-size: 7.5pt; vertical-align: middle;">&#9744;</span>';
                    $bChecked = !empty($paintBoden[$roomName]) ? '<span style="font-family: DejaVu Sans; font-size: 7.5pt; vertical-align: middle;">&#9746;</span>' : '<span style="font-family: DejaVu Sans; font-size: 7.5pt; vertical-align: middle;">&#9744;</span>';
                    
                    $html .= '<table style="width: 100%; border-collapse: collapse; background: transparent !important; border: none !important; margin: 0; padding: 0;">';
                    $html .= '<tr>';
                    $html .= '<td style="border: none !important; background: transparent !important; background-color: transparent !important; padding: 0; color: white !important; font-weight: bold; font-size: 7.5pt; text-align: left; text-transform: uppercase;">' . htmlspecialchars($roomName) . '</td>';
                    $html .= '<td style="border: none !important; background: transparent !important; background-color: transparent !important; padding: 0; color: white !important; font-weight: normal; font-size: 6.8pt; text-align: right; text-transform: none; vertical-align: middle;">';
                    $html .= 'Beim Einzug frisch gestrichen: ' . $wChecked . ' Wände &nbsp;&nbsp;' . $hChecked . ' Holzwerk &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Bodenbelag: neu &nbsp;' . $bChecked;
                    $html .= '</td>';
                    $html .= '</tr>';
                    $html .= '</table>';
                } else {
                    $html .= '<span class="room-title-text" style="font-weight: bold; font-size: 7.5pt; text-transform: uppercase;">' . htmlspecialchars($roomName) . '</span>';
                }
                $html .= '</th></tr>';
                
                for ($r = 0; $r < $rowCount; $r++) {
                    $html .= '<tr>';
                    for ($c = 0; $c < 4; $c++) {
                        $index = $r + $c * $rowCount;
                        if ($index < $totalItems) {
                            $itemNum = $itemKeys[$index];
                            $itemName = $roomItems[$index];
                            $isChecked = in_array((string)$itemNum, $checkedList);
                            $box = $isChecked 
                                ? '<span class="checkbox-defect" style="font-family: DejaVu Sans; font-size: 8pt; vertical-align: middle;">&#9746;</span>' 
                                : '<span class="checkbox-ok" style="font-family: DejaVu Sans; font-size: 8pt; color: #4a5568; vertical-align: middle;">&#9744;</span>';
                            
                            $cellClass = $isChecked ? 'cell-defect' : 'cell-ok';
                            
                            // Special layout adjustments for Küche (indented children and Backofenzubehör unnumbered)
                            $paddingStyle = '';
                            $numberPrefix = '<span class="item-num" style="font-weight: bold; color: #718096; margin-right: 2px;">' . htmlspecialchars((string)$itemNum) . '.</span> ';
                            
                            if ($roomName === 'Küche') {
                                if ((string)$itemNum === '14a') {
                                    $numberPrefix = '';
                                    $itemName = 'Backofenzubehör:';
                                } elseif (in_array((int)$itemNum, [15, 16, 17])) {
                                    $paddingStyle = ' style="padding-left: 12px;"';
                                }
                            }
                            
                            $html .= '<td width="25%" class="' . $cellClass . '"' . $paddingStyle . '>';
                            $html .= $box . ' ';
                            $html .= $numberPrefix;
                            $html .= '<span class="item-name" style="vertical-align: middle;">' . htmlspecialchars($itemName) . '</span>';
                            $html .= '</td>';
                        } else {
                            $html .= '<td width="25%">&nbsp;</td>';
                        }
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';

                // Render room-specific defects table under the room checklist
                $roomDefectsHtml = "";
                $roomDefects = [];
                foreach ($items as $posId => $itemName) {
                    $posIdStr = (string)$posId;
                    $isChecked = in_array($posIdStr, $checkedList);
                    $def = $defects[$posId] ?? $defects[$posIdStr] ?? null;
                    $hasDescription = $def && !empty(trim($def['beschreibung'] ?? ''));
                    
                    if ($isChecked || $hasDescription) {
                        $roomDefects[$posIdStr] = [
                            'item' => $itemName,
                            'beschreibung' => $def['beschreibung'] ?? '',
                            'behebung_durch' => $def['behebung_durch'] ?? 'vermieter',
                            'bis_datum' => $def['bis_datum'] ?? '',
                            'photo' => $def['photo'] ?? ''
                        ];
                    }
                }

                if (!empty($roomDefects)) {
                    $roomDefectsHtml .= '<table class="defect-table" style="width: 100%; border-collapse: collapse; margin-top: 4px; margin-bottom: 8px; font-size: 6.8pt; line-height: 1.2;">';
                    $roomDefectsHtml .= '<thead>';
                    $roomDefectsHtml .= '<tr style="background-color: #edf2f7; color: #2d3748;">';
                    $roomDefectsHtml .= '<th width="10%" style="font-size: 6.8pt; font-weight: bold; border: 1px solid #cbd5e0; padding: 3px; text-align: center;">Pos. Nr.</th>';
                    $roomDefectsHtml .= '<th width="20%" style="font-size: 6.8pt; font-weight: bold; border: 1px solid #cbd5e0; padding: 3px; text-align: left;">Bauteil</th>';
                    $roomDefectsHtml .= '<th width="15%" style="font-size: 6.8pt; font-weight: bold; border: 1px solid #cbd5e0; padding: 3px; text-align: center;">Behebung durch</th>';
                    $roomDefectsHtml .= '<th width="12%" style="font-size: 6.8pt; font-weight: bold; border: 1px solid #cbd5e0; padding: 3px; text-align: center;">Bis zum</th>';
                    $roomDefectsHtml .= '<th style="font-size: 6.8pt; font-weight: bold; border: 1px solid #cbd5e0; padding: 3px; text-align: left;">Bemerkungen / Mängelbeschrieb / Kosten</th>';
                    $roomDefectsHtml .= '</tr>';
                    $roomDefectsHtml .= '</thead>';
                    $roomDefectsHtml .= '<tbody>';
                    
                    foreach ($roomDefects as $posIdStr => $def) {
                        $itemName = $def['item'];
                        $desc = $def['beschreibung'];
                        $responsibleType = $def['behebung_durch'];
                        $respBadge = ($responsibleType === 'mieter') 
                            ? '<span class="badge badge-mieter" style="font-size: 5.8pt; padding: 0.5px 2px;">MieterIn</span>' 
                            : '<span class="badge badge-vermieter" style="font-size: 5.8pt; padding: 0.5px 2px;">VermieterIn</span>';
                        $dueDate = !empty($def['bis_datum']) ? date('d.m.Y', strtotime($def['bis_datum'])) : '-';
                        
                        // Photo rendering in PDF
                        $photoHtml = "";
                        if (!empty($def['photo'])) {
                            $imgFile = $protocolDir . DIRECTORY_SEPARATOR . $def['photo'];
                            if (file_exists($imgFile)) {
                                $photoHtml = '<br><img src="data:image/jpg;base64,' . base64_encode(file_get_contents($imgFile)) . '" class="photo-thumbnail" style="width: 80px; height: 60px; margin-top: 3px;">';
                            }
                        }
                        
                        // Formatting Küche's 14a correctly
                        $posLabel = 'Pos ' . $posIdStr;
                        if ($roomName === 'Küche' && $posIdStr === '14a') {
                            $posLabel = 'Pos 14a';
                            $itemName = 'Backofenzubehör:';
                        }
                        
                        $roomDefectsHtml .= '<tr style="background-color: #fff5f5;">';
                        $roomDefectsHtml .= '<td style="font-weight: bold; text-align: center; border: 1px solid #cbd5e0; padding: 3px; color: #c53030;">' . htmlspecialchars($posLabel) . '</td>';
                        $roomDefectsHtml .= '<td style="border: 1px solid #cbd5e0; padding: 3px; font-weight: bold; color: #2d3748;">' . htmlspecialchars($itemName) . '</td>';
                        $roomDefectsHtml .= '<td style="text-align: center; border: 1px solid #cbd5e0; padding: 3px;">' . $respBadge . '</td>';
                        $roomDefectsHtml .= '<td style="text-align: center; border: 1px solid #cbd5e0; padding: 3px; color: #4a5568;">' . htmlspecialchars($dueDate) . '</td>';
                        $roomDefectsHtml .= '<td style="border: 1px solid #cbd5e0; padding: 3px; font-size: 6.8pt; color: #2d3748;">' . nl2br(htmlspecialchars($desc)) . $photoHtml . '</td>';
                        $roomDefectsHtml .= '</tr>';
                    }
                    
                    $roomDefectsHtml .= '</tbody>';
                    $roomDefectsHtml .= '</table>';
                }

                return $html . $roomDefectsHtml . '</div>';
            }
        }

        // Generate checklist continuously for all rooms in a loop
        $checklistHtml = '';
        foreach ($pdf_rooms as $roomName => $roomInfo) {
            $checklistHtml .= getChecklistColumnHtml($roomName, $roomInfo, $checkedList, $paintWände, $paintHolz, $paintBoden, $defects);
        }

        // Determine version of the protocol for this apartment
        $version = 1;
        if ($protocolId > 0) {
            $stmtVer = $mysqli->prepare("SELECT COUNT(*) as cnt FROM abnahme_protokolle WHERE wohnung_id = ? AND daten_json LIKE '%wohnungsabnahme_protokoll%' AND id <= ?");
            if ($stmtVer) {
                $stmtVer->bind_param("ii", $uid, $protocolId);
                $stmtVer->execute();
                $resVer = $stmtVer->get_result();
                if ($rowVer = $resVer->fetch_assoc()) {
                    $version = (int)$rowVer['cnt'];
                }
                $stmtVer->close();
            }
            if ($version === 0) {
                $version = 1;
            }
        } else {
            $stmtVer = $mysqli->prepare("SELECT COUNT(*) as cnt FROM abnahme_protokolle WHERE wohnung_id = ? AND daten_json LIKE '%wohnungsabnahme_protokoll%'");
            if ($stmtVer) {
                $stmtVer->bind_param("i", $uid);
                $stmtVer->execute();
                $resVer = $stmtVer->get_result();
                if ($rowVer = $resVer->fetch_assoc()) {
                    $version = (int)$rowVer['cnt'] + 1;
                }
                $stmtVer->close();
            }
        }

        // Build replacements list
        $replacements = [
            '[[REFERENZ_NR]]' => 'BAN-' . $version . '-' . date('Ymd'),
            '[[DATUM]]' => date('d.m.Y, H:i') . ' Uhr',
            '[[PROJEKT_NAME]]' => $unit['p_name'],
            '[[WOHNUNG_NAME]]' => $unit['name'],
            '[[ETAGE]]' => $unit['etage'] ?? 'EG',
            '[[LETZTE_ABNAHME]]' => $_POST['letzte_abnahme'] ?? 'Keine Angabe',
            '[[MIETER_NAME]]' => $mieterName,
            '[[MIETER_VERTRETER]]' => $_POST['mieter_vertreter'] ?? '-',
            '[[VERMIETER_FIRMA]]' => $firmaName,
            '[[VERWALTER_NAME]]' => $userName,
            '[[STROM_1]]' => $_POST['zaehler_strom_1'] ?: '0.00',
            '[[STROM_2]]' => $_POST['zaehler_strom_2'] ?: '0.00',
            '[[WASSER]]' => $_POST['zaehler_wasser'] ?: '0.00',
            '[[WARMWASSER]]' => $_POST['zaehler_warmwasser'] ?: '0.00',
            '[[GAS]]' => $_POST['zaehler_gas'] ?: '0.00',
            '[[HEIZOEL]]' => $_POST['zaehler_heizoel'] ?: '0.00',
            '[[SCHLUESSEL_HAUSTUER]]' => (int)$_POST['keys_house'],
            '[[SCHLUESSEL_WOHNUNGSTUER]]' => (int)$_POST['keys_apartment'],
            '[[SCHLUESSEL_BRIEFKASTEN]]' => (int)$_POST['keys_mail'],
            '[[SCHLUESSEL_KELLER]]' => (int)$_POST['keys_cellar'],
            '[[WEITERE_BEMERKUNGEN]]' => nl2br(htmlspecialchars($_POST['weitere_bemerkungen'] ?? 'Keine')),
            '[[NEBENKOSTEN_BIS]]' => $_POST['nebenkosten_bis'] ?: '-',
            '[[SIGNATURE_VERMIETER]]' => getSignatureHtml($_POST['signature_vermieter'] ?? ''),
            '[[SIGNATURE_MIETER]]' => getSignatureHtml($_POST['signature_mieter'] ?? ''),
            '[[SIGNATURE_EXPERTE]]' => getSignatureHtml($_POST['signature_experte'] ?? ''),
            '[[COMPACT_CHECKLIST]]' => $checklistHtml,
            '[[COMPACT_CHECKLIST_PAGE_1]]' => '',
            '[[COMPACT_CHECKLIST_PAGE_2]]' => '',
            '[[COMPACT_CHECKLIST_PAGE_3]]' => ''
        ];
        
        // Build defect rows HTML (Empty because defects are now listed under each room checklist)
        $defectRowsHtml = "";
        $replacements['[[DEFECT_ROWS]]'] = $defectRowsHtml;
        
        // Replace templates markers
        foreach ($replacements as $key => $val) {
            $html = str_replace($key, (string)$val, $html);
        }
        
        // Setup Dompdf and render PDF
        $storage = $basePath . DIRECTORY_SEPARATOR . 'storage';
        $tmpDir  = $storage . DIRECTORY_SEPARATOR . 'tmp';
        $fontDir = $storage . DIRECTORY_SEPARATOR . 'fonts';
        @mkdir($tmpDir, 0777, true);
        @mkdir($fontDir, 0777, true);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', $basePath);
        $options->set('tempDir', $tmpDir);
        $options->set('fontDir', $fontDir);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        $filename = 'Wohnungsabnahme_' . preg_replace('/[^a-zA-Z0-9]/', '_', $mieterName) . '_' . date('Ymd_His') . '.pdf';
        file_put_contents($protocolDir . DIRECTORY_SEPARATOR . $filename, $dompdf->output());
    }
    
    // Convert post fields to JSON and inject specific protocol type flag
    $postData = $_POST;
    $postData['protokoll_typ'] = 'wohnungsabnahme_protokoll';
    $datenJson = json_encode($postData);
    
    $erstelltVon = (int)($_SESSION['user_id'] ?? 0);
    $mieterId = (int)($_POST['mieter_id'] ?? 0);
    
    if ($protocolId > 0) {
        // Update existing record
        $stmtDoc = $mysqli->prepare("UPDATE abnahme_protokolle SET projekt_id = ?, wohnung_id = ?, mieter_id = ?, mieter_name_custom = ?, daten_json = ?, erstellt_von = ?, pdf_pfad = ? WHERE id = ?");
        $stmtDoc->bind_param("iiissisi", $pid, $uid, $mieterId, $mieterName, $datenJson, $erstelltVon, $filename, $protocolId);
    } else {
        // Create new record
        $stmtDoc = $mysqli->prepare("INSERT INTO abnahme_protokolle (projekt_id, wohnung_id, mieter_id, mieter_name_custom, daten_json, erstellt_am, erstellt_von, pdf_pfad) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
        $stmtDoc->bind_param("iiissis", $pid, $uid, $mieterId, $mieterName, $datenJson, $erstelltVon, $filename);
    }
    
    if ($stmtDoc->execute()) {
        if ($protocolId <= 0) {
            $protocolId = $mysqli->insert_id;
        }
        logDebug("SUCCESS: Wohnungsabnahme Protokoll saved (ID: $protocolId)");
        echo json_encode([
            'success' => true,
            'is_draft' => $isDraft,
            'protocol_id' => $protocolId,
            'pdf_url' => $filename ? '../uploads/protocols/' . $filename : null
        ]);
    } else {
        throw new Exception($stmtDoc->error);
    }
    
} catch (Throwable $e) {
    logDebug("ERROR in Save Handler: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
