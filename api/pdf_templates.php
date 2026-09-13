<?php
// api/pdf_templates.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

// 1. Sicherstellen, dass die Tabelle existiert (Self-Healing)
$mysqli->query("CREATE TABLE IF NOT EXISTS pdf_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    type ENUM('template', 'protocol') DEFAULT 'template',
    config_json MEDIUMTEXT,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Migration: falls 'type' Spalte fehlt
$check = $mysqli->query("SHOW COLUMNS FROM pdf_templates LIKE 'type'");
if ($check->num_rows === 0) {
    $mysqli->query("ALTER TABLE pdf_templates ADD COLUMN type ENUM('template', 'protocol') DEFAULT 'template' AFTER name");
}

// Migration: sicherstellen dass config_json MEDIUMTEXT ist (für Signaturen)
$checkJson = $mysqli->query("SHOW COLUMNS FROM pdf_templates LIKE 'config_json'");
if ($checkJson->num_rows > 0) {
    $row = $checkJson->fetch_assoc();
    if (stripos($row['Type'], 'mediumtext') === false && stripos($row['Type'], 'longtext') === false) {
        $mysqli->query("ALTER TABLE pdf_templates MODIFY config_json MEDIUMTEXT");
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $type = $_GET['type'] ?? 'template';
    $stmt = $mysqli->prepare("SELECT id, name, config_json FROM pdf_templates WHERE type = ? ORDER BY name ASC");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = $res->fetch_all(MYSQLI_ASSOC);

    // 2. Automatisches Seeding der Standard-Vorlagen (nur für templates)
    if ($type === 'template') {
        $checkExist = $mysqli->query("SELECT id FROM pdf_templates WHERE name = 'Baustellenprotokoll' AND type = 'template'");
        if ($checkExist->num_rows === 0) {
            $defaults = [
                [
                    'name' => 'Wohnungsabnahme',
                    'title' => 'Wohnungsabnahme',
                    'list_title' => 'Mangelliste Abnahme',
                    'subject' => 'Abnahme der Wohneinheit',
                    'art159' => true,
                    'show_werkdetails' => true,
                    'intro' => "Die Wohnung wurde gemeinsam besichtigt. Die nachfolgenden Mängel sind Bestandteil des Abnahmeprotokolls."
                ],
                [
                    'name' => 'Baustellenprotokoll',
                    'title' => 'Baustellenprotokoll',
                    'list_title' => 'Pendenzenliste / Journal',
                    'subject' => 'Wöchentliche Baukontrolle',
                    'art160' => false,
                    'show_werkdetails' => false,
                    'intro' => "Protokoll der aktuellen Bautätigkeit, Termine und festgestellten Abweichungen."
                ],
                [
                    'name' => 'Abnahme Unternehmer',
                    'title' => 'Werkabnahme Unternehmer',
                    'list_title' => 'Mängelverzeichnis',
                    'subject' => 'Abnahme gemäss SIA 118',
                    'art159' => true,
                    'show_werkdetails' => true,
                    'intro' => "Förmliche Werkabnahme der Leistungen. Beginn der Garantiefristen gemäss Vertrag."
                ],
                [
                    'name' => 'Sitzungsprotokoll',
                    'title' => 'Sitzungsprotokoll',
                    'list_title' => 'Beschluss- & Pendenzenliste',
                    'subject' => 'Koordinationssitzung',
                    'show_werkdetails' => false,
                    'intro' => "Besprechung der aktuellen Termine, Pendenzen und Koordination der Gewerke vor Ort."
                ]
            ];
            foreach ($defaults as $d) {
                // Nur einfügen wenn name noch nicht existiert
                $innerCheck = $mysqli->query("SELECT id FROM pdf_templates WHERE name = '" . $mysqli->real_escape_string($d['name']) . "' AND type = 'template'");
                if ($innerCheck->num_rows > 0) continue;

                $cfg = json_encode([
                    'title' => $d['title'],
                    'list_title' => $d['list_title'],
                    'subject' => $d['subject'],
                    'location' => 'Baustelle / Vor Ort',
                    'intro' => $d['intro'],
                    'outro' => 'Die festgestellten Mängel sind bis zum nächsten Termin bzw. gemäss Frist zu beheben.',
                    'art159' => $d['art159'] ?? false,
                    'art160' => $d['art160'] ?? false,
                    'art161' => false,
                    'show_werkdetails' => $d['show_werkdetails'],
                    'show_checkboxes' => ($d['art159'] ?? false) || ($d['art160'] ?? false)
                ]);
                $stmt = $mysqli->prepare("INSERT INTO pdf_templates (name, type, config_json) VALUES (?, 'template', ?)");
                $stmt->bind_param("ss", $d['name'], $cfg);
                $stmt->execute();
            }
        }
        $stmt = $mysqli->prepare("SELECT id, name, config_json FROM pdf_templates WHERE type = 'template' ORDER BY name ASC");
        $stmt->execute();
        $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    header('Content-Type: application/json');
    echo json_encode($list);
    exit;
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = $_POST['name'] ?? 'Unbenannt';
    $config = $_POST['config'] ?? '{}';
    $type = $_POST['type'] ?? 'template';

    if ($id > 0) {
        $stmt = $mysqli->prepare("UPDATE pdf_templates SET name = ?, config_json = ?, type = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $config, $type, $id);
    } else {
        $stmt = $mysqli->prepare("INSERT INTO pdf_templates (name, config_json, type) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $config, $type);
    }
    
    if ($stmt->execute()) {
        $newId = $id > 0 ? $id : $mysqli->insert_id;
        echo json_encode(['success' => true, 'id' => $newId]);
    } else {
        echo json_encode(['success' => false, 'error' => $mysqli->error]);
    }
    exit;
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $mysqli->query("DELETE FROM pdf_templates WHERE id = $id");
    echo json_encode(['success' => true]);
    exit;
}


