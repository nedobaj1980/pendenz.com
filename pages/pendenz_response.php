<?php
// pages/pendenz_response.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$uid = (int)($_SESSION['user_id'] ?? 0);
$id = (int)($_POST['pendenz_id'] ?? 0);

if ($id <= 0) {
    header("Location: pendenzen.php");
    exit;
}

// Fetch pendenz and check if user is assignee
$res = $mysqli->query("SELECT * FROM pendenzen WHERE id = $id LIMIT 1");
$p = $res ? $res->fetch_assoc() : null;

if (!$p) {
    die("Pendenz nicht gefunden.");
}

// Security: Is the user allowed to respond?
// (Assignee or Admin)
$isAssignee = ((int)$p['zustaendig_id'] === $uid);
$isAdmin = is_admin();

if (!$isAssignee && !$isAdmin) {
    die("Keine Berechtigung für eine Rückmeldung.");
}

$newStatus = $_POST['new_status'] ?? $p['status'];
$message   = trim($_POST['message'] ?? '');

// 1. Update Status
if ($newStatus !== $p['status']) {
    $stmt = $mysqli->prepare("UPDATE pendenzen SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $newStatus, $id);
    $stmt->execute();
    $stmt->close();
}

// 2. Add History / Comment
// We use pendenz_history if it exists, otherwise we could store it in extra_json
// I'll check if pendenz_history exists via a quick check
$hasHistoryTable = false;
$check = $mysqli->query("SHOW TABLES LIKE 'pendenz_history'");
if ($check && $check->num_rows > 0) $hasHistoryTable = true;

if ($hasHistoryTable) {
    $stmt = $mysqli->prepare("INSERT INTO pendenz_history (pendenz_id, user_id, alt_status, neu_status, bemerkung) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $id, $uid, $p['status'], $newStatus, $message);
    $stmt->execute();
    $stmt->close();
}

// 3. Handle File Upload (Feedback Image)
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../uploads/pendenzen/' . $id . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileName = time() . '_' . basename($_FILES['attachment']['name']);
    $targetFile = $uploadDir . $fileName;
    $dbPath = 'uploads/pendenzen/' . $id . '/' . $fileName;

    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $targetFile)) {
        // Add to pendenz_dateien
        $stmt = $mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id, pfad, titel, typ, mimetype, groesse, erstellt_von) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $mimetype = $_FILES['attachment']['type'];
        $type = (strpos($mimetype, 'image') !== false) ? 'image' : 'file';
        $size = $_FILES['attachment']['size'];
        $title = "Rückmeldung: " . $_FILES['attachment']['name'];
        
        $stmt->bind_param("issssii", $id, $dbPath, $title, $type, $mimetype, $size, $uid);
        $stmt->execute();
        $stmt->close();
    }
}

// Redirect back with success message
header("Location: pendenz_show.php?id=$id&msg=success");
exit;
