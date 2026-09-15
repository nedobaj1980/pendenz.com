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

function ai_json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    ai_json_response(['success' => false, 'error' => 'Sitzung abgelaufen. Bitte neu anmelden.'], 401);
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

    $keepKeys = ['projekt_id', 'id', 'wohnung_id', 'objekt_id', 'path'];
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

function ai_extract_query_param(string $contextUrl, string $paramName): string
{
    if ($contextUrl === '') {
        return '';
    }

    $query = parse_url($contextUrl, PHP_URL_QUERY);
    if (!$query) {
        return '';
    }

    parse_str($query, $queryParams);
    return (string)($queryParams[$paramName] ?? '');
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
    if (empty($context['projekt_id']) && $pid > 0) {
        $context['projekt_id'] = $pid;
    }
    $wId = (int)ai_extract_query_param($contextUrl, 'wohnung_id');
    if ($wId > 0 && empty($context['wohnung_id'])) {
        $context['wohnung_id'] = $wId;
    }
    $pPath = ai_extract_query_param($contextUrl, 'path');
    if ($pPath !== '' && empty($context['path'])) {
        $context['path'] = $pPath;
    }

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
            $actionPid = !empty($params['project_id']) ? (int)$params['project_id'] : ai_extract_project_id($contextUrl);
            if ($actionPid <= 0) {
                $actionPid = (int)($_SESSION['current_project_id'] ?? 1);
            }

            $titel = (string)$params['title'];
            $datum = !empty($params['due']) ? (string)$params['due'] : (!empty($params['date']) ? (string)$params['date'] : date('Y-m-d'));
            $wichtigkeit = !empty($params['priority']) ? (int)$params['priority'] : (!empty($params['prio']) ? (int)$params['prio'] : 3);
            $wohnungId = !empty($params['wohnung_id']) ? (int)$params['wohnung_id'] : null;
            $creatorId = null;
            if ($userId > 0) {
                $uChk = $mysqli->prepare("SELECT id FROM benutzer WHERE id = ? LIMIT 1");
                if ($uChk) {
                    $uChk->bind_param("i", $userId);
                    $uChk->execute();
                    $uRes = $uChk->get_result();
                    if ($uRes && $uRes->num_rows > 0) {
                        $creatorId = $userId;
                    }
                    $uChk->close();
                }
            }

            $pendenzStmt = $mysqli->prepare("INSERT INTO pendenzen (titel, projekt_id, wohnung_id, wichtigkeit, erstellt_von, status, enddatum) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($pendenzStmt) {
                $pendenzStmt->bind_param('siiiiss', $titel, $actionPid, $wohnungId, $wichtigkeit, $creatorId, $status, $datum);
                $pendenzStmt->execute();
                $newId = (int)$pendenzStmt->insert_id;
                $pendenzStmt->close();

                if ($newId > 0) {
                    $detailUrl = page_url('pendenz_show.php?id=' . $newId);
                    $actionResult = "\n\n<div class='ai-action-success' style='background:rgba(16,185,129,0.15); border:1px solid #10b981; border-radius:10px; padding:12px 16px; margin-top:12px; color:#fff;'><strong>✅ Pendenz #{$newId} erfasst:</strong> <em>" . htmlspecialchars($titel, ENT_QUOTES, 'UTF-8') . "</em><br><a href='{$detailUrl}' style='color:#6ee7b7; font-weight:700; text-decoration:underline;'>👉 Pendenz #{$newId} öffnen & bearbeiten</a></div>";
                    $answer = str_replace($matches[0], $actionResult, $answer);

                    // Auch in DB aktualisieren
                    $updMsg = $mysqli->prepare("UPDATE ai_messages SET content = ? WHERE chat_id = ? AND role = 'assistant' ORDER BY id DESC LIMIT 1");
                    if ($updMsg) {
                        $updMsg->bind_param('si', $answer, $chatId);
                        $updMsg->execute();
                        $updMsg->close();
                    }
                }
            }
        }
    }

    ai_json_response(['success' => true, 'answer' => $answer, 'chat_id' => $chatId, 'context_url' => $contextUrl]);
} catch (Throwable $e) {
    ai_json_response(['success' => false, 'error' => $e->getMessage()]);
}
