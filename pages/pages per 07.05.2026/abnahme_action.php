<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Keine ID angegeben.']);
    exit;
}

try {
    if ($action === 'delete') {
        // PDF Pfad holen um Datei auch zu löschen
        $res = $mysqli->query("SELECT pdf_pfad FROM abnahme_protokolle WHERE id = $id");
        if ($row = $res->fetch_assoc()) {
            $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'protocols' . DIRECTORY_SEPARATOR . $row['pdf_pfad'];
            if (file_exists($filePath)) @unlink($filePath);
        }
        $mysqli->query("DELETE FROM abnahme_protokolle WHERE id = $id");
        echo json_encode(['success' => true]);
    } 
    elseif ($action === 'rename') {
        $newName = $_POST['new_name'] ?? '';
        if (empty($newName)) throw new Exception("Name darf nicht leer sein.");
        
        $stmt = $mysqli->prepare("UPDATE abnahme_protokolle SET mieter_name_custom = ? WHERE id = ?");
        $stmt->bind_param("si", $newName, $id);
        $stmt->execute();
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
