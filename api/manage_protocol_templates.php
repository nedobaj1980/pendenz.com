<?php
// api/manage_protocol_templates.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// --- Self-Healing: Ensure tables exist ---
$mysqli->query("CREATE TABLE IF NOT EXISTS protokoll_typen (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, icon VARCHAR(50) DEFAULT '📝')");
$mysqli->query("CREATE TABLE IF NOT EXISTS protokoll_vorlagen (id INT AUTO_INCREMENT PRIMARY KEY, typ_id INT NOT NULL, name VARCHAR(255) NOT NULL, json_data TEXT, is_default TINYINT(1) DEFAULT 0)");
$mysqli->query("CREATE TABLE IF NOT EXISTS protokoll_formulare (id INT AUTO_INCREMENT PRIMARY KEY, vorlage_id INT NOT NULL, name VARCHAR(255) NOT NULL, json_data TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

// Ensure default types exist
$checkTypes = $mysqli->query("SELECT id FROM protokoll_typen LIMIT 1");
if ($checkTypes && $checkTypes->num_rows === 0) {
    $mysqli->query("INSERT INTO protokoll_typen (name, icon) VALUES ('Baustellenprotokoll', '🏗️'), ('Abnahmeprotokoll', '🔑'), ('Sitzungsprotokoll', '👥')");
}

// Ensure default templates exist
$checkTemplates = $mysqli->query("SELECT id FROM protokoll_vorlagen LIMIT 1");
if ($checkTemplates && $checkTemplates->num_rows === 0) {
    // 1. Baustellenprotokoll
    $bt_data = json_encode([
        'title' => 'Baustellenprotokoll',
        'subject' => 'Wöchentliche Baustellenbegehung',
        'list_title' => 'Baustellen-Pendenzen',
        'location' => 'Baubüro / Baustelle',
        'intro' => "Wetter: sonnig, ca. 18°C\nStand der Arbeiten: Rohbau abgeschlossen, Ausbau läuft.",
        'outro' => "Nächste Begehung: Nächsten Montag, 09:00 Uhr.",
        'participants' => []
    ], JSON_UNESCAPED_UNICODE);
    $esc_bt = $mysqli->real_escape_string($bt_data);
    $mysqli->query("INSERT INTO protokoll_vorlagen (typ_id, name, json_data) SELECT id, 'Wochenprotokoll Bau', '$esc_bt' FROM protokoll_typen WHERE name='Baustellenprotokoll' LIMIT 1");

    // 2. Abnahmeprotokoll
    $ab_data = json_encode([
        'title' => 'Abnahmeprotokoll',
        'subject' => 'Wohnungsabnahme / Schlüsselübergabe',
        'list_title' => 'Abnahmemängel',
        'location' => 'Vor Ort Objekt/Wohnung',
        'intro' => "Gegenstand der Abnahme ist die schlüsselfertige Übergabe der Wohneinheit gemäss Werkvertrag.",
        'outro' => "Der Besteller bestätigt den Erhalt sämtlicher Schlüssel gemäss Schliessplan.",
        'art159' => 'true',
        'participants' => []
    ], JSON_UNESCAPED_UNICODE);
    $esc_ab = $mysqli->real_escape_string($ab_data);
    $mysqli->query("INSERT INTO protokoll_vorlagen (typ_id, name, json_data) SELECT id, 'Wohnungsabnahme Standard', '$esc_ab' FROM protokoll_typen WHERE name='Abnahmeprotokoll' LIMIT 1");

    // 3. Sitzungsprotokoll
    $sz_data = json_encode([
        'title' => 'Sitzungsprotokoll',
        'subject' => 'Koordinationssitzung Planung',
        'list_title' => 'Beschlüsse & Pendenzen',
        'location' => 'Sitzungszimmer 1',
        'intro' => "Traktanden:\n1. Begrüssung\n2. Protokoll der letzten Sitzung\n3. Aktueller Projektstand\n4. Varia",
        'outro' => "Das Protokoll gilt als genehmigt, wenn bis in 5 Tagen kein schriftlicher Einspruch erfolgt.",
        'participants' => []
    ], JSON_UNESCAPED_UNICODE);
    $esc_sz = $mysqli->real_escape_string($sz_data);
    $mysqli->query("INSERT INTO protokoll_vorlagen (typ_id, name, json_data) SELECT id, 'Planersitzung', '$esc_sz' FROM protokoll_typen WHERE name='Sitzungsprotokoll' LIMIT 1");
}

// FIX: If broken entries already exist (from previous run), fix them
foreach(['protokoll_vorlagen', 'protokoll_formulare'] as $table) {
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00fc', 'ü') WHERE json_data LIKE '%u00fc%'");
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00dc', 'Ü') WHERE json_data LIKE '%u00dc%'");
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00e4', 'ä') WHERE json_data LIKE '%u00e4%'");
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00c4', 'Ä') WHERE json_data LIKE '%u00c4%'");
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00f6', 'ö') WHERE json_data LIKE '%u00f6%'");
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00d6', 'Ö') WHERE json_data LIKE '%u00d6%'");
    $mysqli->query("UPDATE $table SET json_data = REPLACE(json_data, 'u00df', 'ß') WHERE json_data LIKE '%u00df%'");
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $res = $mysqli->query("
        SELECT v.*, t.name as typ_name, t.icon as typ_icon 
        FROM protokoll_vorlagen v
        JOIN protokoll_typen t ON v.typ_id = t.id
        ORDER BY t.name, v.name
    ");
    $templates = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    
    // Also fetch forms
    $resF = $mysqli->query("SELECT * FROM protokoll_formulare ORDER BY created_at DESC");
    $forms = $resF ? $resF->fetch_all(MYSQLI_ASSOC) : [];
    
    echo json_encode(['success' => true, 'templates' => $templates, 'forms' => $forms]);
} 
elseif ($action === 'load') {
    $id = (int)($_GET['id'] ?? 0);
    $res = $mysqli->query("
        SELECT v.*, t.name as typ_name 
        FROM protokoll_vorlagen v
        JOIN protokoll_typen t ON v.typ_id = t.id
        WHERE v.id = $id
    ");
    $template = $res->fetch_assoc();
    echo json_encode(['success' => true, 'template' => $template]);
}
elseif ($action === 'load_form') {
    $id = (int)($_GET['id'] ?? 0);
    $res = $mysqli->query("
        SELECT f.*, t.name as typ_name 
        FROM protokoll_formulare f
        JOIN protokoll_vorlagen v ON f.vorlage_id = v.id
        JOIN protokoll_typen t ON v.typ_id = t.id
        WHERE f.id = $id
    ");
    $form = $res->fetch_assoc();
    echo json_encode(['success' => true, 'form' => $form]);
}
elseif ($action === 'save') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $name = $data['name'] ?? 'Unbenannt';
    $typ_id = (int)($data['typ_id'] ?? 1);
    $json = $data['json_data'] ?? '{}';
    
    $st = $mysqli->prepare("INSERT INTO protokoll_vorlagen (typ_id, name, json_data) VALUES (?, ?, ?)");
    $st->bind_param("iss", $typ_id, $name, $json);
    $st->execute();
    echo json_encode(['success' => true, 'id' => $mysqli->insert_id]);
    $st->close();
}
elseif ($action === 'save_form') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $name = $data['name'] ?? 'Neues Formular';
    $vorlage_id = (int)($data['vorlage_id'] ?? 0);
    $json = $data['json_data'] ?? '{}';
    $id = (int)($data['id'] ?? 0);

    if ($id > 0) {
        $st = $mysqli->prepare("UPDATE protokoll_formulare SET name = ?, json_data = ? WHERE id = ?");
        $st->bind_param("ssi", $name, $json, $id);
    } else {
        $st = $mysqli->prepare("INSERT INTO protokoll_formulare (vorlage_id, name, json_data) VALUES (?, ?, ?)");
        $st->bind_param("iss", $vorlage_id, $name, $json);
    }
    
    if ($st->execute()) {
        echo json_encode(['success' => true, 'id' => ($id > 0 ? $id : $mysqli->insert_id)]);
    } else {
        echo json_encode(['success' => false, 'error' => $mysqli->error]);
    }
    $st->close();
}
elseif ($action === 'update_template') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $id = (int)($data['id'] ?? 0);
    $name = $data['name'] ?? '';
    $json = $data['json_data'] ?? '{}';

    if ($id > 0) {
        $st = $mysqli->prepare("UPDATE protokoll_vorlagen SET name = ?, json_data = ? WHERE id = ?");
        $st->bind_param("ssi", $name, $json, $id);
        if ($st->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $mysqli->error]);
        }
        $st->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'No ID provided']);
    }
} 
elseif ($action === 'delete_template') {
    $id = (int)($_GET['id'] ?? 0);
    $mysqli->query("DELETE FROM protokoll_formulare WHERE vorlage_id = $id");
    $mysqli->query("DELETE FROM protokoll_vorlagen WHERE id = $id");
    echo json_encode(['success' => true]);
}
elseif ($action === 'delete_form') {
    $id = (int)($_GET['id'] ?? 0);
    $mysqli->query("DELETE FROM protokoll_formulare WHERE id = $id");
    echo json_encode(['success' => true]);
} 
elseif ($action === 'create_type') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $mysqli->real_escape_string($data['name'] ?? '');
    $icon = $mysqli->real_escape_string($data['icon'] ?? '📝');
    
    if ($name) {
        $mysqli->query("INSERT INTO protokoll_typen (name, icon) VALUES ('$name', '$icon')");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Name fehlt']);
    }
}
elseif ($action === 'rename') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $newName = $input['name'] ?? '';
    $type = $input['type'] ?? ''; // 'template' or 'form'
    
    if (!$id || !$newName || !in_array($type, ['template', 'form'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    $table = ($type === 'template') ? 'protokoll_vorlagen' : 'protokoll_formulare';
    $st = $mysqli->prepare("UPDATE $table SET name = ? WHERE id = ?");
    $st->bind_param("si", $newName, $id);
    
    if ($st->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $mysqli->error]);
    }
    $st->close();
}
elseif ($action === 'set_default') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $mysqli->query("UPDATE protokoll_vorlagen SET is_default = 0");
        $mysqli->query("UPDATE protokoll_vorlagen SET is_default = 1 WHERE id = $id");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No ID provided']);
    }
}
