<?php
// api/ai_chat_messages.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
require_login();

$chatId = (int)($_GET['chat_id'] ?? 0);
$userId = current_user_id();

if ($chatId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Keine Chat-ID']);
    exit;
}

// Check ownership
$check = $mysqli->query("SELECT id FROM ai_chats WHERE id = $chatId AND user_id = $userId");
if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Zugriff verweigert']);
    exit;
}

$messages = [];
$res = $mysqli->query("SELECT role, content FROM ai_messages WHERE chat_id = $chatId ORDER BY created_at ASC");
if ($res) while($row = $res->fetch_assoc()) $messages[] = $row;

echo json_encode(['success' => true, 'messages' => $messages]);
