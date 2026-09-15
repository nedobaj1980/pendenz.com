<?php
declare(strict_types=1);

// api/ai_query.php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../app/modules/ai/AiService.php';

use App\Modules\AiAssistant\AiService;

function ai_normalize_context_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return '';
    }

    $path = (string) ($parts['path'] ?? '');
    $keepKeys = ['projekt_id', 'id', 'wohnung_id', 'objekt_id', 'path'];
    $queryParams = [];

    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $parsedQuery);
        foreach ($keepKeys as $key) {
            if (!isset($parsedQuery[$key])) {
                continue;
            }

            $value = is_array($parsedQuery[$key]) ? reset($parsedQuery[$key]) : $parsedQuery[$key];
            $value = trim((string) $value);
            if ($value !== '' && $value !== '0') {
                $queryParams[$key] = $value;
            }
        }
    }

    ksort($queryParams);
    $query = http_build_query($queryParams);

    // Nur App-Pfad und relevante Queryparameter speichern; Host/Scheme werden nicht benötigt.
    return $path . ($query !== '' ? '?' . $query : '');
}

function ai_extract_query_param(string $contextUrl, string $paramName): string
{
    if ($contextUrl === '') {
        return '';
    }

    $query = parse_url($contextUrl, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return '';
    }

    parse_str($query, $queryParams);
    $value = $queryParams[$paramName] ?? '';
    return is_array($value) ? '' : (string) $value;
}

function ai_extract_project_id(string $contextUrl): int
{
    return (int) ai_extract_query_param($contextUrl, 'projekt_id');
}

function ai_validate_wohnung(mysqli $db, ?int $wohnungId, int $projectId): ?int
{
    if (!$wohnungId || $wohnungId <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT 1
         FROM wohnungen w
         INNER JOIN objekte o ON o.id = w.objekt_id
         WHERE w.id = ? AND o.projekt_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $wohnungId, $projectId);
    $stmt->execute();
    $valid = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    return $valid ? $wohnungId : null;
}

api_try(function (): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_response([
            'success' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => 'Nur POST ist erlaubt.'
        ], 405);
    }

    $db = db();
    $userId = require_login_json();

    $rawInput = file_get_contents('php://input');
    $input = json_decode((string) $rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }

    $prompt = trim((string) ($input['prompt'] ?? ''));
    $context = is_array($input['context'] ?? null) ? $input['context'] : [];
    $chatId = (int) ($input['chat_id'] ?? 0);
    $contextUrl = ai_normalize_context_url((string) ($context['url'] ?? ''));

    $pid = (int) ($context['projekt_id'] ?? 0);
    if ($pid <= 0) {
        $pid = ai_extract_project_id($contextUrl);
    }
    if ($pid <= 0) {
        $pid = (int) ($_SESSION['current_project_id'] ?? 0);
    }

    if ($pid > 0) {
        require_project_access_json($db, $pid);
    }

    if ($prompt === 'GIMI_LOAD_CONTEXT') {
        if ($contextUrl === '') {
            json_response(['success' => true, 'messages' => [], 'chat_id' => 0]);
        }

        $loadChatId = 0;
        $stmt = $db->prepare(
            "SELECT id, projekt_id
             FROM ai_chats
             WHERE user_id = ? AND context_url = ?
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->bind_param('is', $userId, $contextUrl);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $loadChatId = (int) $row['id'];
            $chatProjectId = (int) ($row['projekt_id'] ?? 0);
            if ($chatProjectId > 0) {
                require_project_access_json($db, $chatProjectId);
            }
        }

        $messages = [];
        if ($loadChatId > 0) {
            $stmt = $db->prepare(
                "SELECT role, content
                 FROM ai_messages
                 WHERE chat_id = ?
                 ORDER BY created_at ASC, id ASC"
            );
            $stmt->bind_param('i', $loadChatId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
            $stmt->close();
        }

        json_response([
            'success' => true,
            'messages' => $messages,
            'chat_id' => $loadChatId
        ]);
    }

    if ($prompt === '') {
        json_response(['success' => false, 'error' => 'Kein Prompt erhalten.'], 400);
    }
    if (mb_strlen($prompt, 'UTF-8') > 10_000) {
        json_response([
            'success' => false,
            'error' => 'PROMPT_TOO_LONG',
            'message' => 'Die Nachricht ist zu lang.'
        ], 413);
    }

    if ($chatId <= 0 && $contextUrl !== '') {
        $stmt = $db->prepare(
            "SELECT id, projekt_id
             FROM ai_chats
             WHERE user_id = ? AND context_url = ?
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->bind_param('is', $userId, $contextUrl);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $chatId = (int) $row['id'];
            $chatProjectId = (int) ($row['projekt_id'] ?? 0);
            if ($chatProjectId > 0) {
                require_project_access_json($db, $chatProjectId);
                if ($pid <= 0) {
                    $pid = $chatProjectId;
                }
            }
        }
    }

    if ($chatId <= 0) {
        $title = mb_substr($prompt, 0, 60, 'UTF-8');
        if (mb_strlen($prompt, 'UTF-8') > 60) {
            $title .= '...';
        }

        $stmt = $db->prepare(
            "INSERT INTO ai_chats (title, user_id, context_url, projekt_id)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('sisi', $title, $userId, $contextUrl, $pid);
        $stmt->execute();
        $chatId = (int) $stmt->insert_id;
        $stmt->close();
    } else {
        $stmt = $db->prepare(
            "SELECT id, projekt_id
             FROM ai_chats
             WHERE id = ? AND user_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('ii', $chatId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            json_response(['success' => false, 'error' => 'Chat nicht gefunden.'], 404);
        }

        $chatProjectId = (int) ($row['projekt_id'] ?? 0);
        if ($chatProjectId > 0) {
            require_project_access_json($db, $chatProjectId);
            if ($pid <= 0) {
                $pid = $chatProjectId;
            }
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO ai_messages (chat_id, role, content)
         VALUES (?, 'user', ?)"
    );
    $stmt->bind_param('is', $chatId, $prompt);
    $stmt->execute();
    $stmt->close();

    $history = [];
    $stmt = $db->prepare(
        "SELECT role, content
         FROM (
             SELECT role, content, created_at, id
             FROM ai_messages
             WHERE chat_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 10
         ) recent_messages
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();

    $context['url'] = $contextUrl;
    if ($pid > 0) {
        $context['projekt_id'] = $pid;
    }

    $wohnungIdFromUrl = (int) ai_extract_query_param($contextUrl, 'wohnung_id');
    if ($wohnungIdFromUrl > 0 && empty($context['wohnung_id'])) {
        $context['wohnung_id'] = $wohnungIdFromUrl;
    }

    $pathFromUrl = ai_extract_query_param($contextUrl, 'path');
    if ($pathFromUrl !== '' && empty($context['path'])) {
        $context['path'] = $pathFromUrl;
    }

    $apiKey = defined('GEMINI_API_KEY') ? (string) GEMINI_API_KEY : '';
    $ai = new AiService($db, $apiKey, $userId);
    $answer = $ai->queryGemini($prompt, $context, $history);

    $stmt = $db->prepare(
        "INSERT INTO ai_messages (chat_id, role, content)
         VALUES (?, 'assistant', ?)"
    );
    $stmt->bind_param('is', $chatId, $answer);
    $stmt->execute();
    $assistantMessageId = (int) $stmt->insert_id;
    $stmt->close();

    if (preg_match('/\[ACTION:CREATE_PENDENZ\|(.*?)\]/s', $answer, $matches)) {
        $params = [];
        foreach (explode('|', $matches[1]) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $part, 2);
            $params[trim($key)] = trim($value);
        }

        if (!empty($params['title'])) {
            // Ein Modell darf niemals selbstständig in ein anderes Projekt wechseln.
            // Ohne echten Seiten-/Session-Kontext wird daher keine Pendenz angelegt.
            $actionPid = $pid;

            if ($actionPid <= 0) {
                $replacement = "\n\n<div class='ai-action-warning'><strong>⚠️ Keine Liegenschaft ausgewählt.</strong> Öffne zuerst die gewünschte Liegenschaft oder Pendenzliste; dann kann Gimi die Aufgabe sicher erfassen.</div>";
                $answer = str_replace($matches[0], $replacement, $answer);
            } else {
                require_project_access_json($db, $actionPid);

                $title = trim((string) $params['title']);
                if ($title === '') {
                    $title = 'Gimi-Pendenz';
                }
                if (mb_strlen($title, 'UTF-8') > 180) {
                    $title = mb_substr($title, 0, 177, 'UTF-8') . '...';
                }

                $date = trim((string) ($params['due'] ?? $params['date'] ?? date('Y-m-d')));
                $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
                    $date = date('Y-m-d');
                }

                $priority = (int) ($params['priority'] ?? $params['prio'] ?? 3);
                $priority = max(1, min(5, $priority));

                $requestedWohnungId = !empty($params['wohnung_id'])
                    ? (int) $params['wohnung_id']
                    : (int) ($context['wohnung_id'] ?? 0);
                $wohnungId = ai_validate_wohnung(
                    $db,
                    $requestedWohnungId > 0 ? $requestedWohnungId : null,
                    $actionPid
                );

                $status = 'offen';
                $stmt = $db->prepare(
                    "INSERT INTO pendenzen
                        (titel, projekt_id, wohnung_id, wichtigkeit, erstellt_von, status, enddatum)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    'siiiiss',
                    $title,
                    $actionPid,
                    $wohnungId,
                    $priority,
                    $userId,
                    $status,
                    $date
                );
                $stmt->execute();
                $newId = (int) $stmt->insert_id;
                $stmt->close();

                if ($newId > 0) {
                    $detailUrl = page_url('pendenz_show.php?id=' . $newId);
                    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
                    $safeUrl = htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8');
                    $replacement = "\n\n<div class='ai-action-success' style='background:rgba(16,185,129,0.15); border:1px solid #10b981; border-radius:10px; padding:12px 16px; margin-top:12px; color:#fff;'><strong>✅ Pendenz #{$newId} erfasst:</strong> <em>{$safeTitle}</em><br><a href='{$safeUrl}' style='color:#6ee7b7; font-weight:700; text-decoration:underline;'>👉 Pendenz #{$newId} öffnen &amp; bearbeiten</a></div>";
                    $answer = str_replace($matches[0], $replacement, $answer);
                }
            }

            if ($assistantMessageId > 0) {
                $stmt = $db->prepare(
                    "UPDATE ai_messages
                     SET content = ?
                     WHERE id = ? AND chat_id = ? AND role = 'assistant'
                     LIMIT 1"
                );
                $stmt->bind_param('sii', $answer, $assistantMessageId, $chatId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    json_response([
        'success' => true,
        'answer' => $answer,
        'chat_id' => $chatId,
        'context_url' => $contextUrl
    ]);
});
