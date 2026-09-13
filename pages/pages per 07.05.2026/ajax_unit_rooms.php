<?php
/**
 * pages/ajax_unit_rooms.php
 * Mit integriertem Keller-Befreiungs-Mechanismus.
 */
header('Content-Type: application/json; charset=UTF-8');

error_reporting(0);
ini_set('display_errors', 0);
if (ob_get_level()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/room_taxonomy.php';

$mysqli->set_charset("utf8mb4");

if (!is_logged_in()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Nicht eingeloggt']);
    exit;
}

raum_taxonomy_ensure_tables($mysqli);

// --- KELLER BEFREIUNG (Spezial-Eingriff) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $checkKeller = $mysqli->query("SELECT id FROM raum_vorlagen WHERE name = 'Keller'");
    if($checkKeller && $checkKeller->num_rows == 0) {
        // Wenn kein Keller da ist, legen wir ihn jetzt EINMAL sauber an
        $mysqli->query("INSERT INTO raum_vorlagen (name, icon, default_sort) VALUES ('Keller', '🔒', 180)");
    }
}

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // VORLAGE SPEICHERN
    if ($action === 'add_template') {
        $n = trim($_POST['name'] ?? '');
        $i = trim($_POST['icon'] ?? '📦');
        if (!empty($n)) {
            $mysqli->query("DELETE FROM raum_vorlagen WHERE name = '".$mysqli->real_escape_string($n)."'");
            $st = $mysqli->prepare("INSERT INTO raum_vorlagen (name, icon) VALUES (?, ?)");
            $st->bind_param("ss", $n, $i);
            if($st->execute()) $response['success'] = true;
            else $response['error'] = $mysqli->error;
        }
    }

    // VORLAGE LÖSCHEN
    if ($action === 'delete_template') {
        $tid = (int)($_POST['t_id'] ?? 0);
        if ($tid > 0 && $mysqli->query("DELETE FROM raum_vorlagen WHERE id = $tid")) {
            $response['success'] = true;
        }
    }

    // RAUM HINZUFÜGEN
    if ($action === 'add_room') {
        $wid = (int)($_POST['w_id'] ?? 0);
        $name = trim($_POST['room_name'] ?? '');
        if ($wid > 0 && !empty($name)) {
            $st = $mysqli->prepare("INSERT INTO raeume (wohnung_id, name) VALUES (?, ?)");
            $st->bind_param("is", $wid, $name);
            if($st->execute()) $response['success'] = true;
        }
    }

    // RAUM LÖSCHEN
    if ($action === 'delete_room') {
        $rid = (int)($_POST['room_id'] ?? 0);
        if ($rid > 0 && $mysqli->query("DELETE FROM raeume WHERE id = $rid")) {
            $response['success'] = true;
        }
    }
} else {
    // GET (Laden)
    $wid = (int)($_GET['wohnung_id'] ?? 0);
    $response = [
        'success' => true,
        'data' => [
            'rooms' => ($wid > 0) ? raeume_get_by_wohnung($mysqli, $wid) : [],
            'master_data' => raum_vorlagen_all($mysqli)
        ]
    ];
}

if (ob_get_level()) ob_end_clean();
echo json_encode($response);
exit;
