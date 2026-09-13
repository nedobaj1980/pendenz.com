<?php
// api/ai_chat_messages.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
require_login();

$chatId = (int)($_GET['chat_id'] ?? 0);
$userId = current_user_id();

if ($chatId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Keine Chat-ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$check = $mysqli->prepare("SELECT id FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1");
if (!$check) {
    echo json_encode(['success' => false, 'error' => 'Chat-Prüfung fehlgeschlagen'], JSON_UNESCAPED_UNICODE);
    exit;
}

$check->bind_param('ii', $chatId, $userId);
$check->execute();
$checkRes = $check->get_result();
$exists = $checkRes && $checkRes->num_rows > 0;
$check->close();

if (!$exists) {
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert'], JSON_UNESCAPED_UNICODE);
    exit;
}

$messages = [];
$stmt = $mysqli->prepare("SELECT role, content FROM ai_messages WHERE chat_id = ? ORDER BY created_at ASC, id ASC");
if ($stmt) {
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $messages[] = $row;
    }
    $stmt->close();
}

echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_UNICODE);
