<?php
// api/ai_query.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/modules/ai/AiService.php';

use App\Modules\AiAssistant\AiService;

if (session_status() === PHP_SESSION_NONE) session_start();
require_login();

$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';
$context = $input['context'] ?? [];
$chatId = (int)($input['chat_id'] ?? 0);
$userId = current_user_id();

if ($prompt === 'GIMI_LOAD_CONTEXT') {
    $contextUrl = $context['url'] ?? '';
    if (empty($contextUrl)) { echo json_encode(['success' => true, 'messages' => []]); exit; }
    
    $cQuery = $mysqli->prepare("SELECT id FROM ai_chats WHERE user_id = ? AND context_url = ? ORDER BY created_at DESC LIMIT 1");
    $cQuery->bind_param("is", $userId, $contextUrl);
    $cQuery->execute();
    $chatId = $cQuery->get_result()->fetch_assoc()['id'] ?? 0;
    $cQuery->close();
    
    $messages = [];
    if ($chatId > 0) {
        $mRes = $mysqli->query("SELECT role, content FROM ai_messages WHERE chat_id = $chatId ORDER BY created_at ASC");
        while($mRow = $mRes->fetch_assoc()) $messages[] = $mRow;
    }
    echo json_encode(['success' => true, 'messages' => $messages, 'chat_id' => $chatId]);
    exit;
}

if (empty($prompt)) {
    echo json_encode(['success' => false, 'error' => 'Kein Prompt erhalten']);
    exit;
}

// 1. Kontext extrahieren
$contextUrl = $context['url'] ?? '';
$pid = 0;
if (!empty($contextUrl)) {
    parse_str(parse_url($contextUrl, PHP_URL_QUERY) ?? '', $queryParams);
    $pid = (int)($queryParams['projekt_id'] ?? 0);
}
if ($pid <= 0) $pid = (int)($_SESSION['current_project_id'] ?? 0);

// 1b. Chat-Sitzung automatisch finden oder erstellen (Kontext-basiert)
if ($chatId <= 0 && !empty($contextUrl)) {
    // Suchen nach einem bestehenden Chat für diese URL
    $cQuery = $mysqli->prepare("SELECT id FROM ai_chats WHERE user_id = ? AND context_url = ? ORDER BY created_at DESC LIMIT 1");
    $cQuery->bind_param("is", $userId, $contextUrl);
    $cQuery->execute();
    $cRes = $cQuery->get_result();
    if ($cRow = $cRes->fetch_assoc()) {
        $chatId = (int)$cRow['id'];
    }
    $cQuery->close();
}

if ($chatId <= 0) {
    // Neuen Chat erstellen mit Kontext-Bezug
    $title = mb_substr($prompt, 0, 30) . '...';
    $stmt = $mysqli->prepare("INSERT INTO ai_chats (title, user_id, context_url, projekt_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sisi", $title, $userId, $contextUrl, $pid);
    $stmt->execute();
    $chatId = $stmt->insert_id;
    $stmt->close();
} else {
    // Check ownership
    $check = $mysqli->query("SELECT id FROM ai_chats WHERE id = $chatId AND user_id = $userId");
    if ($check->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Chat nicht gefunden']);
        exit;
    }
}

// 2. Benutzer-Nachricht speichern
$stmt = $mysqli->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'user', ?)");
$stmt->bind_param("is", $chatId, $prompt);
$stmt->execute();
$stmt->close();

// 3. Historie laden für Kontinuität (letzte 5 Nachrichten)
$history = [];
if ($chatId > 0) {
    $hRes = $mysqli->query("SELECT role, content FROM ai_messages WHERE chat_id = $chatId ORDER BY created_at ASC LIMIT 10");
    if ($hRes) while($hRow = $hRes->fetch_assoc()) $history[] = $hRow;
}

// 4. KI-Anfrage
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

try {
    $ai = new AiService($mysqli, $apiKey, $userId);
    $answer = $ai->queryGemini($prompt, $context, $history);
    
    // 5. KI-Antwort speichern
    $stmt = $mysqli->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'assistant', ?)");
    $stmt->bind_param("is", $chatId, $answer);
    $stmt->execute();
    $stmt->close();

    // --- NEU: Aktion-Parsing ---
    $actionResult = null;
    if (preg_match('/\[ACTION:CREATE_PENDENZ\|(.*?)\]/s', $answer, $matches)) {
        $params = [];
        $parts = explode('|', $matches[1]);
        foreach ($parts as $p) {
            if (strpos($p, '=') !== false) {
                list($k, $v) = explode('=', $p, 2);
                $params[trim($k)] = trim($v);
            }
        }

        if (!empty($params['title'])) {
            // Projekt-ID aus URL extrahieren
            $pid = 0;
            if (!empty($context['url'])) {
                parse_str(parse_url($context['url'], PHP_URL_QUERY) ?? '', $queryParams);
                $pid = (int)($queryParams['projekt_id'] ?? 0);
            }
            if ($pid <= 0) $pid = (int)($_SESSION['current_project_id'] ?? 1); // Fallback

            $titel = $mysqli->real_escape_string($params['title']);
            $datum = !empty($params['date']) ? $mysqli->real_escape_string($params['date']) : date('Y-m-d');
            $status = 'offen';
            
            // Einfacher Insert (Minimal-Version)
            $mysqli->query("INSERT INTO pendenzen (titel, projekt_id, erstellt_von, status, enddatum) VALUES ('$titel', $pid, $userId, '$status', '$datum')");
            $newId = $mysqli->insert_id;
            
            if ($newId > 0) {
                $actionResult = "✅ Pendenz #$newId ('$titel') wurde von gimi erfolgreich gespeichert!";
                // Den Tag aus der Antwort für den Benutzer entfernen, falls gewünscht
                $answer = str_replace($matches[0], "\n\n" . $actionResult, $answer);
            }
        }
    }
    
    echo json_encode(['success' => true, 'answer' => $answer, 'chat_id' => $chatId]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
