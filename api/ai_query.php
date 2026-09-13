<?php
// api/ai_query.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/modules/ai/AiService.php';

use App\Modules\AiAssistant\AiService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
require_login();

function ai_json_response(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ai_normalize_context_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $scheme = $parts['scheme'] ?? '';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path = $parts['path'] ?? '';

    $keepKeys = ['projekt_id', 'id', 'wohnung_id', 'objekt_id'];
    $queryParams = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $parsedQuery);
        foreach ($keepKeys as $key) {
            if (!isset($parsedQuery[$key])) {
                continue;
            }

            $value = is_array($parsedQuery[$key]) ? reset($parsedQuery[$key]) : $parsedQuery[$key];
            $value = trim((string)$value);
            if ($value === '' || $value === '0') {
                continue;
            }
            $queryParams[$key] = $value;
        }
    }

    ksort($queryParams);
    $query = http_build_query($queryParams);

    $base = '';
    if ($scheme !== '' && $host !== '') {
        $base = $scheme . '://' . $host . $port;
    }

    return $base . $path . ($query !== '' ? '?' . $query : '');
}

function ai_extract_project_id(string $contextUrl): int
{
    if ($contextUrl === '') {
        return 0;
    }

    $query = parse_url($contextUrl, PHP_URL_QUERY);
    if (!$query) {
        return 0;
    }

    parse_str($query, $queryParams);
    return (int)($queryParams['projekt_id'] ?? 0);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}

$prompt = trim((string)($input['prompt'] ?? ''));
$context = is_array($input['context'] ?? null) ? $input['context'] : [];
$chatId = (int)($input['chat_id'] ?? 0);
$userId = current_user_id();
$rawContextUrl = (string)($context['url'] ?? '');
$contextUrl = ai_normalize_context_url($rawContextUrl);

if ($prompt === 'GIMI_LOAD_CONTEXT') {
    if ($contextUrl === '') {
        ai_json_response(['success' => true, 'messages' => [], 'chat_id' => 0]);
    }

    $chatId = 0;
    $cQuery = $mysqli->prepare("SELECT id FROM ai_chats WHERE user_id = ? AND context_url = ? ORDER BY created_at DESC LIMIT 1");
    if ($cQuery) {
        $cQuery->bind_param('is', $userId, $contextUrl);
        $cQuery->execute();
        $cRes = $cQuery->get_result();
        if ($cRes && ($row = $cRes->fetch_assoc())) {
            $chatId = (int)$row['id'];
        }
        $cQuery->close();
    }

    $messages = [];
    if ($chatId > 0) {
        $mStmt = $mysqli->prepare("SELECT role, content FROM ai_messages WHERE chat_id = ? ORDER BY created_at ASC, id ASC");
        if ($mStmt) {
            $mStmt->bind_param('i', $chatId);
            $mStmt->execute();
            $mRes = $mStmt->get_result();
            while ($mRes && ($mRow = $mRes->fetch_assoc())) {
                $messages[] = $mRow;
            }
            $mStmt->close();
        }
    }

    ai_json_response(['success' => true, 'messages' => $messages, 'chat_id' => $chatId]);
}

if ($prompt === '') {
    ai_json_response(['success' => false, 'error' => 'Kein Prompt erhalten']);
}

$pid = ai_extract_project_id($contextUrl);
if ($pid <= 0) {
    $pid = (int)($_SESSION['current_project_id'] ?? 0);
}

if ($chatId <= 0 && $contextUrl !== '') {
    $cQuery = $mysqli->prepare("SELECT id FROM ai_chats WHERE user_id = ? AND context_url = ? ORDER BY created_at DESC LIMIT 1");
    if ($cQuery) {
        $cQuery->bind_param('is', $userId, $contextUrl);
        $cQuery->execute();
        $cRes = $cQuery->get_result();
        if ($cRes && ($cRow = $cRes->fetch_assoc())) {
            $chatId = (int)$cRow['id'];
        }
        $cQuery->close();
    }
}

if ($chatId <= 0) {
    $title = mb_substr($prompt, 0, 60);
    if (mb_strlen($prompt) > 60) {
        $title .= '...';
    }

    $stmt = $mysqli->prepare("INSERT INTO ai_chats (title, user_id, context_url, projekt_id) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        ai_json_response(['success' => false, 'error' => 'Chat konnte nicht erstellt werden']);
    }
    $stmt->bind_param('sisi', $title, $userId, $contextUrl, $pid);
    $stmt->execute();
    $chatId = (int)$stmt->insert_id;
    $stmt->close();
} else {
    $check = $mysqli->prepare("SELECT id FROM ai_chats WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$check) {
        ai_json_response(['success' => false, 'error' => 'Chat-Prüfung fehlgeschlagen']);
    }
    $check->bind_param('ii', $chatId, $userId);
    $check->execute();
    $checkRes = $check->get_result();
    $exists = $checkRes && $checkRes->num_rows > 0;
    $check->close();

    if (!$exists) {
        ai_json_response(['success' => false, 'error' => 'Chat nicht gefunden']);
    }
}

$stmt = $mysqli->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'user', ?)");
if (!$stmt) {
    ai_json_response(['success' => false, 'error' => 'Nachricht konnte nicht gespeichert werden']);
}
$stmt->bind_param('is', $chatId, $prompt);
$stmt->execute();
$stmt->close();

$history = [];
$hStmt = $mysqli->prepare("SELECT role, content FROM (SELECT role, content, created_at, id FROM ai_messages WHERE chat_id = ? ORDER BY created_at DESC, id DESC LIMIT 10) recent_messages ORDER BY created_at ASC, id ASC");
if ($hStmt) {
    $hStmt->bind_param('i', $chatId);
    $hStmt->execute();
    $hRes = $hStmt->get_result();
    while ($hRes && ($hRow = $hRes->fetch_assoc())) {
        $history[] = $hRow;
    }
    $hStmt->close();
}

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

try {
    $context['url'] = $contextUrl;

    $ai = new AiService($mysqli, $apiKey, $userId);
    $answer = $ai->queryGemini($prompt, $context, $history);

    $stmt = $mysqli->prepare("INSERT INTO ai_messages (chat_id, role, content) VALUES (?, 'assistant', ?)");
    if (!$stmt) {
        ai_json_response(['success' => false, 'error' => 'Antwort konnte nicht gespeichert werden']);
    }
    $stmt->bind_param('is', $chatId, $answer);
    $stmt->execute();
    $stmt->close();

    if (preg_match('/\[ACTION:CREATE_PENDENZ\|(.*?)\]/s', $answer, $matches)) {
        $params = [];
        $parts = explode('|', $matches[1]);
        foreach ($parts as $part) {
            if (strpos($part, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $params[trim($k)] = trim($v);
        }

        if (!empty($params['title'])) {
            $actionPid = ai_extract_project_id($contextUrl);
            if ($actionPid <= 0) {
                $actionPid = (int)($_SESSION['current_project_id'] ?? 1);
            }

            $titel = (string)$params['title'];
            $datum = !empty($params['date']) ? (string)$params['date'] : date('Y-m-d');
            $status = 'offen';

            $pendenzStmt = $mysqli->prepare("INSERT INTO pendenzen (titel, projekt_id, erstellt_von, status, enddatum) VALUES (?, ?, ?, ?, ?)");
            if ($pendenzStmt) {
                $pendenzStmt->bind_param('siiss', $titel, $actionPid, $userId, $status, $datum);
                $pendenzStmt->execute();
                $newId = (int)$pendenzStmt->insert_id;
                $pendenzStmt->close();

                if ($newId > 0) {
                    $actionResult = "✅ Pendenz #{$newId} ('{$titel}') wurde von gimi erfolgreich gespeichert!";
                    $answer = str_replace($matches[0], "

" . $actionResult, $answer);
                }
            }
        }
    }

    ai_json_response(['success' => true, 'answer' => $answer, 'chat_id' => $chatId, 'context_url' => $contextUrl]);
} catch (Throwable $e) {
    ai_json_response(['success' => false, 'error' => $e->getMessage()]);
}
