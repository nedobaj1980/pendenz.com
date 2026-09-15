<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * Lädt für Gimi Voice nur Daten aus Projekten, die der aktuelle Benutzer sehen darf.
 * Admin/Superadmin dürfen alle aktiven Projekte verwenden.
 *
 * @return array{projekte:array,objekte:array,wohnungen:array,raeume:array,arten:array,benutzer:array}
 */
function voice_context(mysqli $db, int $userId): array
{
    if (is_admin()) {
        $projekte = $db->query(
            "SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name ASC"
        )->fetch_all(MYSQLI_ASSOC);
    } else {
        $stmt = $db->prepare(
            "SELECT DISTINCT p.id, p.name
             FROM projekte p
             INNER JOIN projekt_mitglieder pm ON pm.projekt_id = p.id
             WHERE pm.benutzer_id = ? AND p.deleted_at IS NULL
             ORDER BY p.name ASC"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $projekte = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $projectIds = [];
    foreach ($projekte as $project) {
        $id = (int) ($project['id'] ?? 0);
        if ($id > 0) {
            $projectIds[] = $id;
        }
    }

    if ($projectIds === []) {
        return [
            'projekte' => [],
            'objekte' => [],
            'wohnungen' => [],
            'raeume' => [],
            'arten' => [],
            'benutzer' => []
        ];
    }

    $in = implode(',', array_map('intval', $projectIds));

    $objekte = $db->query(
        "SELECT id, projekt_id, name
         FROM objekte
         WHERE projekt_id IN ($in)
         ORDER BY name ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $wohnungen = $db->query(
        "SELECT w.id, w.objekt_id, w.name
         FROM wohnungen w
         INNER JOIN objekte o ON o.id = w.objekt_id
         WHERE o.projekt_id IN ($in)
         ORDER BY w.name ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $raeume = $db->query(
        "SELECT r.id, r.wohnung_id, r.name
         FROM raeume r
         INNER JOIN wohnungen w ON w.id = r.wohnung_id
         INNER JOIN objekte o ON o.id = w.objekt_id
         WHERE o.projekt_id IN ($in)
         ORDER BY r.name ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $arten = $db->query(
        "SELECT id, name FROM pendenzen_arten ORDER BY name ASC"
    )->fetch_all(MYSQLI_ASSOC);

    // Namen aller Benutzer nur für Admins in die Spracherkennung einbeziehen.
    $benutzer = [];
    if (is_admin()) {
        $benutzer = $db->query(
            "SELECT id, name FROM benutzer WHERE deleted_at IS NULL ORDER BY name ASC"
        )->fetch_all(MYSQLI_ASSOC);
    }

    return compact('projekte', 'objekte', 'wohnungen', 'raeume', 'arten', 'benutzer');
}

function voice_audio_mime(string $mime): ?string
{
    $mime = strtolower(trim(explode(';', $mime, 2)[0] ?? ''));

    return match ($mime) {
        'audio/mp4', 'audio/m4a', 'audio/aac', 'audio/x-m4a' => 'audio/mp4',
        'audio/webm', 'video/webm' => 'audio/webm',
        'audio/wav', 'audio/x-wav', 'audio/wave' => 'audio/wav',
        'audio/ogg', 'application/ogg' => 'audio/ogg',
        default => null,
    };
}

function voice_transcribe(string $audioBase64, string $audioMime): string
{
    if (!defined('GEMINI_API_KEY') || trim((string) GEMINI_API_KEY) === '') {
        return '';
    }

    if (preg_match('/^data:([^;]+);base64,(.*)$/s', $audioBase64, $matches)) {
        $audioMime = (string) $matches[1];
        $audioBase64 = (string) $matches[2];
    }

    // Base64-String begrenzen, damit grosse Requests den PHP-Prozess nicht überlasten.
    if ($audioBase64 === '' || strlen($audioBase64) > 12_000_000) {
        json_response([
            'ok' => false,
            'error' => 'AUDIO_TOO_LARGE',
            'message' => 'Die Aufnahme ist zu gross. Bitte kürzer diktieren.'
        ], 413);
    }

    $normalizedMime = voice_audio_mime($audioMime);
    if ($normalizedMime === null) {
        json_response([
            'ok' => false,
            'error' => 'UNSUPPORTED_AUDIO',
            'message' => 'Dieses Audioformat wird nicht unterstützt.'
        ], 415);
    }

    if (base64_decode($audioBase64, true) === false) {
        json_response([
            'ok' => false,
            'error' => 'INVALID_AUDIO',
            'message' => 'Die Aufnahme konnte nicht gelesen werden.'
        ], 400);
    }

    $models = [
        'gemini-3.1-flash-lite',
        'gemini-flash-latest',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3-flash-preview'
    ];

    foreach ($models as $model) {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($model),
            rawurlencode((string) GEMINI_API_KEY)
        );

        $payload = [
            'contents' => [[
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $normalizedMime,
                            'data' => $audioBase64
                        ]
                    ],
                    [
                        'text' => 'Transkribiere diese Audionachricht für eine Schweizer Liegenschaftsverwaltung wortgetreu auf Deutsch. Gib nur den transkribierten Text zurück.'
                    ]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 1024
            ]
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            error_log('[gimi-voice] Gemini transport error: ' . $curlError);
            continue;
        }

        if ($httpCode !== 200 || !is_string($response) || $response === '') {
            error_log('[gimi-voice] Gemini HTTP ' . $httpCode . ' for model ' . $model);
            continue;
        }

        $decoded = json_decode($response, true);
        $text = trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

/**
 * @param array<int,array<string,mixed>> $projekte
 * @param array<int,array<string,mixed>> $objekte
 * @param array<int,array<string,mixed>> $wohnungen
 * @param array<int,array<string,mixed>> $raeume
 * @param array<int,array<string,mixed>> $arten
 * @param array<int,array<string,mixed>> $benutzer
 * @return array<string,mixed>
 */
function voice_parse(
    string $text,
    array $projekte,
    array $objekte,
    array $wohnungen,
    array $raeume,
    array $arten,
    array $benutzer
): array {
    $parsed = [
        'projekt_id' => null,
        'projekt_name' => '',
        'objekt_id' => null,
        'objekt_name' => '',
        'wohnung_id' => null,
        'wohnung_name' => '',
        'raum_id' => null,
        'raum_name' => '',
        'vorgangsart_id' => null,
        'vorgangsart_name' => '',
        'zustaendig_id' => null,
        'zustaendig_name' => '',
        'wichtigkeit' => 3,
        'wichtigkeit_label' => 'Normal',
        'enddatum' => null,
        'enddatum_label' => '',
        'titel' => '',
        'beschreibung' => $text,
        'original_text' => $text
    ];

    $lower = mb_strtolower($text, 'UTF-8');

    foreach ($projekte as $project) {
        $name = trim((string) ($project['name'] ?? ''));
        $nameLower = mb_strtolower($name, 'UTF-8');
        if ($nameLower === '') {
            continue;
        }

        $matched = mb_strpos($lower, $nameLower) !== false;
        if (!$matched) {
            $tokens = preg_split('/[\s,_\-]+/u', $nameLower) ?: [];
            foreach ($tokens as $token) {
                if (mb_strlen($token, 'UTF-8') >= 4 && mb_strpos($lower, $token) !== false) {
                    $matched = true;
                    break;
                }
            }
        }

        if ($matched) {
            $parsed['projekt_id'] = (int) $project['id'];
            $parsed['projekt_name'] = $name;
            break;
        }
    }

    foreach ($objekte as $object) {
        $name = trim((string) ($object['name'] ?? ''));
        $nameLower = mb_strtolower($name, 'UTF-8');
        if ($nameLower !== '' && mb_strlen($nameLower, 'UTF-8') >= 3 && mb_strpos($lower, $nameLower) !== false) {
            $parsed['objekt_id'] = (int) $object['id'];
            $parsed['objekt_name'] = $name;
            $parsed['projekt_id'] = (int) $object['projekt_id'];
            break;
        }
    }

    if (preg_match('/(?:wohnung|whg|einheit|top)\s*([0-9a-zA-Z.\-_]+)/ui', $text, $matches)) {
        $number = mb_strtolower(trim((string) $matches[1]), 'UTF-8');
        $padded = is_numeric($number) ? sprintf('%02d', (int) $number) : $number;

        foreach ($wohnungen as $wohnung) {
            $nameLower = mb_strtolower((string) $wohnung['name'], 'UTF-8');
            $needles = [
                'wohnung ' . $number,
                'wohnung ' . $padded,
                'whg ' . $number,
                'whg ' . $padded,
                'top ' . $number,
                'einheit ' . $number
            ];
            foreach ($needles as $needle) {
                if (mb_strpos($nameLower, $needle) !== false) {
                    $parsed['wohnung_id'] = (int) $wohnung['id'];
                    $parsed['wohnung_name'] = (string) $wohnung['name'];
                    $parsed['objekt_id'] = (int) $wohnung['objekt_id'];
                    break 2;
                }
            }
        }
    }

    if (!$parsed['wohnung_id']) {
        foreach ($wohnungen as $wohnung) {
            $nameLower = mb_strtolower((string) $wohnung['name'], 'UTF-8');
            if (mb_strlen($nameLower, 'UTF-8') >= 3 && mb_strpos($lower, $nameLower) !== false) {
                $parsed['wohnung_id'] = (int) $wohnung['id'];
                $parsed['wohnung_name'] = (string) $wohnung['name'];
                $parsed['objekt_id'] = (int) $wohnung['objekt_id'];
                break;
            }
        }
    }

    if ($parsed['objekt_id']) {
        foreach ($objekte as $object) {
            if ((int) $object['id'] === (int) $parsed['objekt_id']) {
                $parsed['objekt_name'] = (string) $object['name'];
                $parsed['projekt_id'] = (int) $object['projekt_id'];
                break;
            }
        }
    }

    if ($parsed['projekt_id']) {
        foreach ($projekte as $project) {
            if ((int) $project['id'] === (int) $parsed['projekt_id']) {
                $parsed['projekt_name'] = (string) $project['name'];
                break;
            }
        }
    }

    $roomSynonyms = [
        'küche' => ['küche', 'kueche'],
        'bad' => ['bad', 'badezimmer', 'wc', 'toilette', 'dusche'],
        'wohnen' => ['wohnen', 'wohnzimmer', 'stube'],
        'schlafen' => ['schlafzimmer', 'elternzimmer', 'schlafen'],
        'zimmer' => ['kinderzimmer', 'zimmer'],
        'balkon' => ['balkon', 'terrasse', 'loggia', 'sitzplatz'],
        'korridor' => ['korridor', 'flur', 'gang', 'diele', 'eingang'],
        'keller' => ['keller', 'kellerabteil'],
        'estrich' => ['estrich', 'dachboden'],
        'waschküche' => ['waschküche', 'waschkueche', 'waschraum'],
        'garage' => ['garage', 'tiefgarage', 'einstellplatz', 'parkplatz']
    ];

    foreach ($raeume as $raum) {
        if ($parsed['wohnung_id'] && (int) $raum['wohnung_id'] !== (int) $parsed['wohnung_id']) {
            continue;
        }

        $roomName = mb_strtolower((string) $raum['name'], 'UTF-8');
        $matched = $roomName !== '' && mb_strpos($lower, $roomName) !== false;

        if (!$matched) {
            foreach ($roomSynonyms as $category => $synonyms) {
                if (mb_strpos($roomName, $category) === false) {
                    continue;
                }
                foreach ($synonyms as $synonym) {
                    if (preg_match('/\b' . preg_quote($synonym, '/') . '\b/ui', $lower)) {
                        $matched = true;
                        break 2;
                    }
                }
            }
        }

        if ($matched) {
            $parsed['raum_id'] = (int) $raum['id'];
            $parsed['raum_name'] = (string) $raum['name'];
            break;
        }
    }

    foreach ($benutzer as $person) {
        $name = trim((string) ($person['name'] ?? ''));
        if ($name !== '' && mb_strlen($name, 'UTF-8') >= 3 && mb_strpos($lower, mb_strtolower($name, 'UTF-8')) !== false) {
            $parsed['zustaendig_id'] = (int) $person['id'];
            $parsed['zustaendig_name'] = $name;
            break;
        }
    }

    if (preg_match('/\b(notfall|sofort|sehr dringend|akut|wasserschaden|brandgefahr|gefahr)\b/ui', $lower)) {
        $parsed['wichtigkeit'] = 5;
        $parsed['wichtigkeit_label'] = '🔥 Notfall / Höchste';
    } elseif (preg_match('/\b(dringend|eilig|schnell|prio|wichtig|hoch)\b/ui', $lower)) {
        $parsed['wichtigkeit'] = 4;
        $parsed['wichtigkeit_label'] = '⚠️ Dringend / Hoch';
    } elseif (preg_match('/\b(niedrig|zeitnah|keine eile|nachrangig|gering|später)\b/ui', $lower)) {
        $parsed['wichtigkeit'] = 2;
        $parsed['wichtigkeit_label'] = '⚪ Niedrig';
    }

    // Wichtig: "übermorgen" vor "morgen", sonst würde "übermorgen" als morgen erkannt.
    if (preg_match('/\bübermorgen\b/ui', $lower)) {
        $date = date('Y-m-d', strtotime('+2 days'));
        $parsed['enddatum'] = $date;
        $parsed['enddatum_label'] = 'Übermorgen (' . date('d.m.Y', strtotime($date)) . ')';
    } elseif (preg_match('/\bmorgen\b/ui', $lower)) {
        $date = date('Y-m-d', strtotime('+1 day'));
        $parsed['enddatum'] = $date;
        $parsed['enddatum_label'] = 'Morgen (' . date('d.m.Y', strtotime($date)) . ')';
    } elseif (preg_match('/\bheute\b/ui', $lower)) {
        $parsed['enddatum'] = date('Y-m-d');
        $parsed['enddatum_label'] = 'Heute (' . date('d.m.Y') . ')';
    } elseif (preg_match('/\bbis\s+(montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag)\b/ui', $lower, $matches)) {
        $weekday = mb_strtolower((string) $matches[1], 'UTF-8');
        $map = [
            'montag' => 'next monday',
            'dienstag' => 'next tuesday',
            'mittwoch' => 'next wednesday',
            'donnerstag' => 'next thursday',
            'freitag' => 'next friday',
            'samstag' => 'next saturday',
            'sonntag' => 'next sunday'
        ];
        $date = date('Y-m-d', strtotime($map[$weekday]));
        $parsed['enddatum'] = $date;
        $parsed['enddatum_label'] = ucfirst($weekday) . ' (' . date('d.m.Y', strtotime($date)) . ')';
    } elseif (preg_match('/\bbis\s+ende\s+woche\b/ui', $lower)) {
        $date = date('Y-m-d', strtotime('this week sunday'));
        $parsed['enddatum'] = $date;
        $parsed['enddatum_label'] = 'Ende Woche (' . date('d.m.Y', strtotime($date)) . ')';
    } elseif (preg_match('/\bbis\s+ende\s+monat\b/ui', $lower)) {
        $date = date('Y-m-t');
        $parsed['enddatum'] = $date;
        $parsed['enddatum_label'] = 'Ende Monat (' . date('d.m.Y', strtotime($date)) . ')';
    } elseif (preg_match('/\b(?:bis\s+)?(\d{1,2})\.(\d{1,2})\.?(\d{4})?\b/u', $lower, $matches)) {
        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = !empty($matches[3]) ? (int) $matches[3] : (int) date('Y');
        if (checkdate($month, $day, $year)) {
            $parsed['enddatum'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $parsed['enddatum_label'] = sprintf('%02d.%02d.%04d', $day, $month, $year);
        }
    }

    foreach ($arten as $art) {
        $name = trim((string) ($art['name'] ?? ''));
        if ($name !== '' && mb_strpos($lower, mb_strtolower($name, 'UTF-8')) !== false) {
            $parsed['vorgangsart_id'] = (int) $art['id'];
            $parsed['vorgangsart_name'] = $name;
            break;
        }
    }

    if (!$parsed['vorgangsart_id'] && preg_match('/\b(reparatur|defekt|tropft|kaputt|klemmt|rinnt|schaden|mangel)\b/ui', $lower)) {
        foreach ($arten as $art) {
            $nameLower = mb_strtolower((string) $art['name'], 'UTF-8');
            if (str_contains($nameLower, 'reparatur') || str_contains($nameLower, 'mangel') || str_contains($nameLower, 'schaden')) {
                $parsed['vorgangsart_id'] = (int) $art['id'];
                $parsed['vorgangsart_name'] = (string) $art['name'];
                break;
            }
        }
    }

    if (!$parsed['vorgangsart_id'] && $arten !== []) {
        $parsed['vorgangsart_id'] = (int) $arten[0]['id'];
        $parsed['vorgangsart_name'] = (string) $arten[0]['name'];
    }

    $title = trim((string) preg_replace('/\s+/u', ' ', $text));
    $title = preg_replace('/^(bitte\s+)?/ui', '', $title) ?? $title;
    if ($title === '') {
        $title = 'Voice-Pendenz';
    }
    if (mb_strlen($title, 'UTF-8') > 150) {
        $title = mb_substr($title, 0, 147, 'UTF-8') . '...';
    }
    $parsed['titel'] = mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8')
        . mb_substr($title, 1, null, 'UTF-8');

    return $parsed;
}

function voice_validate_relation(mysqli $db, string $kind, ?int $id, int $projectId): void
{
    if (!$id) {
        return;
    }

    $sql = match ($kind) {
        'objekt' => "SELECT 1 FROM objekte WHERE id = ? AND projekt_id = ? LIMIT 1",
        'wohnung' => "SELECT 1 FROM wohnungen w INNER JOIN objekte o ON o.id = w.objekt_id WHERE w.id = ? AND o.projekt_id = ? LIMIT 1",
        'raum' => "SELECT 1 FROM raeume r INNER JOIN wohnungen w ON w.id = r.wohnung_id INNER JOIN objekte o ON o.id = w.objekt_id WHERE r.id = ? AND o.projekt_id = ? LIMIT 1",
        default => throw new InvalidArgumentException('Unbekannte Relation')
    };

    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $id, $projectId);
    $stmt->execute();
    $valid = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();

    if (!$valid) {
        json_response([
            'ok' => false,
            'error' => 'INVALID_RELATION',
            'message' => 'Die gewählte Zuordnung gehört nicht zur ausgewählten Liegenschaft.'
        ], 422);
    }
}

api_try(function (): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        json_response([
            'ok' => false,
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => 'Nur POST ist erlaubt.'
        ], 405);
    }

    $db = db();
    $userId = require_login_json();

    $stmtUser = $db->prepare(
        "SELECT id FROM benutzer WHERE id = ? AND deleted_at IS NULL AND COALESCE(is_blocked, 0) = 0 LIMIT 1"
    );
    $stmtUser->bind_param('i', $userId);
    $stmtUser->execute();
    $validUser = (bool) $stmtUser->get_result()->fetch_row();
    $stmtUser->close();

    if (!$validUser) {
        json_response([
            'ok' => false,
            'error' => 'INVALID_SESSION',
            'message' => 'Benutzerkonto nicht verfügbar. Bitte neu einloggen.'
        ], 401);
    }

    $raw = file_get_contents('php://input');
    $json = json_decode((string) $raw, true);
    $data = is_array($json) ? $json : $_POST;

    $action = (string) ($data['action'] ?? 'parse');
    if (!in_array($action, ['parse', 'save', 'save_direct'], true)) {
        json_response(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 400);
    }

    $text = trim((string) ($data['text'] ?? ''));
    $audioBase64 = trim((string) ($data['audio_base64'] ?? ''));
    $audioMime = trim((string) ($data['audio_mime'] ?? 'audio/webm'));

    if ($text === '' && $audioBase64 !== '') {
        $text = voice_transcribe($audioBase64, $audioMime);
        if ($text === '') {
            json_response([
                'ok' => false,
                'error' => 'AUDIO_TRANSCRIPTION_FAILED',
                'message' => 'Die Aufnahme konnte nicht transkribiert werden. Bitte nochmals aufnehmen oder die Tastatur-Diktierfunktion verwenden.'
            ], 502);
        }
    }

    if ($text === '') {
        json_response([
            'ok' => false,
            'error' => 'EMPTY_TEXT',
            'message' => 'Kein gesprochener Text erkannt. Bitte erneut aufnehmen oder manuell tippen.'
        ], 400);
    }

    if (mb_strlen($text, 'UTF-8') > 5000) {
        json_response([
            'ok' => false,
            'error' => 'TEXT_TOO_LONG',
            'message' => 'Der Text ist zu lang. Bitte kürzer diktieren.'
        ], 413);
    }

    $context = voice_context($db, $userId);
    $parsed = voice_parse(
        $text,
        $context['projekte'],
        $context['objekte'],
        $context['wohnungen'],
        $context['raeume'],
        $context['arten'],
        $context['benutzer']
    );

    if ($action === 'parse') {
        json_response([
            'ok' => true,
            'text' => $text,
            'parsed' => $parsed
        ]);
    }

    $projectId = !empty($data['projekt_id']) ? (int) $data['projekt_id'] : (int) ($parsed['projekt_id'] ?? 0);
    $vorgangsartId = !empty($data['vorgangsart_id']) ? (int) $data['vorgangsart_id'] : (int) ($parsed['vorgangsart_id'] ?? 0);
    $objektId = !empty($data['objekt_id']) ? (int) $data['objekt_id'] : (int) ($parsed['objekt_id'] ?? 0);
    $wohnungId = !empty($data['wohnung_id']) ? (int) $data['wohnung_id'] : (int) ($parsed['wohnung_id'] ?? 0);
    $raumId = !empty($data['raum_id']) ? (int) $data['raum_id'] : (int) ($parsed['raum_id'] ?? 0);
    $zustaendigId = !empty($data['zustaendig_id']) ? (int) $data['zustaendig_id'] : (int) ($parsed['zustaendig_id'] ?? 0);

    require_project_access_json($db, $projectId);
    voice_validate_relation($db, 'objekt', $objektId ?: null, $projectId);
    voice_validate_relation($db, 'wohnung', $wohnungId ?: null, $projectId);
    voice_validate_relation($db, 'raum', $raumId ?: null, $projectId);

    $title = trim((string) ($data['titel'] ?? $parsed['titel']));
    $description = trim((string) ($data['beschreibung'] ?? $parsed['beschreibung']));
    $priority = !empty($data['wichtigkeit']) ? (int) $data['wichtigkeit'] : (int) $parsed['wichtigkeit'];
    $priority = max(1, min(5, $priority));
    $endDate = !empty($data['enddatum']) ? trim((string) $data['enddatum']) : (string) ($parsed['enddatum'] ?? '');
    $endDate = $endDate !== '' ? $endDate : null;

    if ($endDate !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if (!$date || $date->format('Y-m-d') !== $endDate) {
            json_response([
                'ok' => false,
                'error' => 'INVALID_DATE',
                'message' => 'Ungültiges Fälligkeitsdatum.'
            ], 422);
        }
    }

    if ($title === '') {
        $title = 'Voice-Pendenz vom ' . date('d.m.Y H:i');
    }
    if (mb_strlen($title, 'UTF-8') > 180) {
        $title = mb_substr($title, 0, 177, 'UTF-8') . '...';
    }

    if ($zustaendigId > 0) {
        $stmtAssignee = $db->prepare(
            "SELECT 1 FROM benutzer WHERE id = ? AND deleted_at IS NULL LIMIT 1"
        );
        $stmtAssignee->bind_param('i', $zustaendigId);
        $stmtAssignee->execute();
        $assigneeValid = (bool) $stmtAssignee->get_result()->fetch_row();
        $stmtAssignee->close();
        if (!$assigneeValid) {
            $zustaendigId = 0;
        }
    }

    $vorgangsartParam = $vorgangsartId > 0 ? $vorgangsartId : null;
    $objektParam = $objektId > 0 ? $objektId : null;
    $wohnungParam = $wohnungId > 0 ? $wohnungId : null;
    $raumParam = $raumId > 0 ? $raumId : null;
    $zustaendigParam = $zustaendigId > 0 ? $zustaendigId : null;

    $token = bin2hex(random_bytes(16));
    $extraJson = json_encode([
        'source' => 'voice_assistant',
        'spoken_text' => $text
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Voice-Erfassung bleibt intern; Public/Upload-Freigaben werden nicht automatisch aktiviert.
    $sql = "
        INSERT INTO pendenzen (
            mandant_id, projekt_id, vorgangsart_id, objekt_id, wohnung_id, raum_id,
            titel, kurzbeschreibung, beschreibung,
            status, wichtigkeit, startdatum, enddatum,
            send_now, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id,
            confirmation_required, confirmation_by, external_can_view, external_can_upload,
            public_enabled, public_token, extra_json, zustaendig_typ
        ) VALUES (
            0, ?, ?, ?, ?, ?, ?, ?, ?, 'offen', ?, CURDATE(), ?,
            0, ?, 'projekt', 1, ?, 0, 'assignee', 0, 0, 0, ?, ?, 'user'
        )
    ";

    $stmt = $db->prepare($sql);
    $stmt->bind_param(
        'iiiiisssisiiss',
        $projectId,
        $vorgangsartParam,
        $objektParam,
        $wohnungParam,
        $raumParam,
        $title,
        $title,
        $description,
        $priority,
        $endDate,
        $userId,
        $zustaendigParam,
        $token,
        $extraJson
    );
    $stmt->execute();
    $newId = (int) $stmt->insert_id;
    $stmt->close();

    if ($newId <= 0) {
        json_response([
            'ok' => false,
            'error' => 'DB_INSERT_FAILED',
            'message' => 'Speichern der Pendenz fehlgeschlagen.'
        ], 500);
    }

    json_response([
        'ok' => true,
        'id' => $newId,
        'message' => "Pendenz #{$newId} erfolgreich via Sprache erfasst!",
        'parsed' => $parsed
    ]);
});
