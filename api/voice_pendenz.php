<?php
// api/voice_pendenz.php
// Intelligente Voice-Erfassung für Pendenzen (Gimi Voice Assistant)
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

api_try(function() {
    $db = db();
    
    // Auth-Check
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Bitte einloggen.'], 401);
    }
    
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $uCheck = $db->query("SELECT id FROM benutzer WHERE id = $userId LIMIT 1")->fetch_assoc();
    if (!$uCheck) {
        $uRow = $db->query("SELECT id FROM benutzer ORDER BY id ASC LIMIT 1")->fetch_assoc();
        $userId = $uRow ? (int)$uRow['id'] : null;
    }
    
    // Input lesen (JSON oder POST)
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?: $_POST;
    
    $action = $data['action'] ?? $_GET['action'] ?? 'parse';
    $text   = trim((string)($data['text'] ?? ''));
    $audioBase64 = (string)($data['audio_base64'] ?? '');
    $audioMime   = (string)($data['audio_mime'] ?? 'audio/webm');
    
    // Falls Audio übergeben wurde, aber kein Text vorhanden ist: Transkribiere per Gemini Flash
    if ($text === '' && !empty($audioBase64)) {
        $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        if (!empty($apiKey)) {
            // Bereinige MIME und Base64-Präfix falls vorhanden
            if (preg_match('/^data:([^;]+);base64,(.*)$/', $audioBase64, $m)) {
                $audioMime = $m[1];
                $audioBase64 = $m[2];
            }
            // iOS Safari liefert oft audio/mp4 oder audio/wav oder audio/aac
            if (str_contains($audioMime, 'mp4') || str_contains($audioMime, 'm4a') || str_contains($audioMime, 'aac')) {
                $audioMime = 'audio/mp4';
            } elseif (str_contains($audioMime, 'wav')) {
                $audioMime = 'audio/wav';
            } elseif (str_contains($audioMime, 'ogg')) {
                $audioMime = 'audio/ogg';
            } else {
                $audioMime = 'audio/webm';
            }

            $models = ['gemini-flash-latest', 'gemini-3.1-flash-lite', 'gemini-3.5-flash'];
            foreach ($models as $mName) {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$mName}:generateContent?key=" . $apiKey;
                $payload = [
                    "contents" => [[
                        "parts" => [
                            [
                                "inlineData" => [
                                    "mimeType" => $audioMime,
                                    "data" => $audioBase64
                                ]
                            ],
                            [
                                "text" => "Transkribiere diese Audionachricht für die Liegenschaftsverwaltung wortgetreu auf Deutsch. Gib NUR den transkribierten Text zurück, ohne Anführungszeichen oder Erklärungen."
                            ]
                        ]
                    ]],
                    "generationConfig" => [
                        "temperature" => 0.1,
                        "maxOutputTokens" => 1024
                    ]
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 12);
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code === 200 && $res) {
                    $j = json_decode($res, true);
                    $t = trim($j['candidates'][0]['content']['parts'][0]['text'] ?? '');
                    if ($t !== '') {
                        $text = $t;
                        break;
                    }
                }
            }
        }
    }

    if ($text === '' && $action !== 'save_direct') {
        json_response(['ok' => false, 'error' => 'EMPTY_TEXT', 'message' => 'Kein gesprochener Text erkannt. Bitte erneut aufnehmen oder manuell tippen.'], 400);
    }
    
    // 1. Stammdaten für KI/NLP Matcher abrufen
    $projekte = $db->query("SELECT id, name FROM projekte ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $objekte  = $db->query("SELECT id, projekt_id, name FROM objekte ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $wohnungen = $db->query("SELECT id, objekt_id, name FROM wohnungen ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $raeume    = $db->query("SELECT id, wohnung_id, name FROM raeume ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $arten     = $db->query("SELECT id, name FROM pendenzen_arten ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $benutzer  = $db->query("SELECT id, name FROM benutzer WHERE deleted_at IS NULL ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    
    // 2. Intelligenter NLP-Parser für Schweizer Liegenschaftssprache
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
    
    $lowerText = mb_strtolower($text, 'UTF-8');
    
    $contextProjektId = (int)($data['projekt_id'] ?? $_GET['projekt_id'] ?? $_SESSION['current_project_id'] ?? 0);

    // Map objekte to projekt_id
    $projByObj = [];
    foreach ($objekte as $o) {
        $projByObj[(int)$o['id']] = (int)$o['projekt_id'];
    }

    // A) Intelligente Projekt-Erkennung mit Relevanz-Scoring & Kontext-Priorisierung
    $bestProj = null;
    $bestScore = 0;

    foreach ($projekte as $p) {
        $pid = (int)$p['id'];
        $pNameLower = mb_strtolower($p['name'], 'UTF-8');
        $score = 0;

        $tokens = preg_split('/[\s,_\-]+/', $pNameLower);
        foreach ($tokens as $t) {
            $t = trim($t);
            if (mb_strlen($t) < 3 || is_numeric($t)) continue;
            if (mb_strpos($lowerText, $t) !== false) {
                // Generische Ortsnamen erhalten Basispunkte, spezifische Strassennamen hohe Priorität
                if (in_array($t, ['romanshorn', 'schweiz', 'thurgau', 'st.gallen', 'appenzell', 'sg', 'tg'])) {
                    $score += 2;
                } else {
                    $score += 10; // z.B. "arbonerstrasse", "kreuzlingerstrasse", "nesslau", "marbach"
                }
            }
        }

        // Exakter Volltext-Treffer im Projektnamen
        if (mb_strpos($lowerText, $pNameLower) !== false) {
            $score += 25;
        }

        // Falls aktives Projekt im Kontext und Treffer vorliegt, Kontext bevorzugen
        if ($contextProjektId > 0 && $pid === $contextProjektId) {
            $score += 5;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestProj = $p;
        }
    }

    // Fallback auf Kontext-Projekt, falls im Text kein anderes Projekt genannt wurde
    if (!$bestProj && $contextProjektId > 0) {
        foreach ($projekte as $p) {
            if ((int)$p['id'] === $contextProjektId) {
                $bestProj = $p;
                break;
            }
        }
    }

    if ($bestProj) {
        $parsed['projekt_id'] = (int)$bestProj['id'];
        $parsed['projekt_name'] = $bestProj['name'];
    }
    
    // B) Wohnungs-Erkennung (Priorisiert Einheiten des erkannten Projekts)
    $detectedProjId = $parsed['projekt_id'];

    if (preg_match('/(?:wohnung|whg|einheit|top)\s*([0-9a-zA-Z\.\-_]+)/ui', $text, $matches)) {
        $foundNum = trim($matches[1]);
        $padNum = is_numeric($foundNum) ? sprintf('%02d', (int)$foundNum) : $foundNum;
        
        // Durchlauf 1: Zuerst gezielt im erkannten Projekt suchen
        foreach ($wohnungen as $w) {
            $wProjId = $projByObj[(int)$w['objekt_id']] ?? 0;
            if ($detectedProjId && $wProjId !== $detectedProjId) continue;

            $wNameLower = mb_strtolower($w['name'], 'UTF-8');
            if (
                mb_strpos($wNameLower, 'wohnung ' . mb_strtolower($foundNum, 'UTF-8')) !== false ||
                mb_strpos($wNameLower, 'wohnung ' . mb_strtolower($padNum, 'UTF-8')) !== false ||
                mb_strpos($wNameLower, 'whg ' . mb_strtolower($foundNum, 'UTF-8')) !== false ||
                mb_strpos($wNameLower, 'whg ' . mb_strtolower($padNum, 'UTF-8')) !== false ||
                preg_match('/\b' . preg_quote($foundNum, '/') . '\b/i', $w['name'])
            ) {
                $parsed['wohnung_id'] = (int)$w['id'];
                $parsed['wohnung_name'] = $w['name'];
                $parsed['objekt_id'] = (int)$w['objekt_id'];
                break;
            }
        }
        
        // Durchlauf 2: Falls nicht im Projekt gefunden, über alle Einheiten suchen
        if (!$parsed['wohnung_id']) {
            foreach ($wohnungen as $w) {
                $wNameLower = mb_strtolower($w['name'], 'UTF-8');
                if (
                    mb_strpos($wNameLower, 'wohnung ' . mb_strtolower($foundNum, 'UTF-8')) !== false ||
                    mb_strpos($wNameLower, 'wohnung ' . mb_strtolower($padNum, 'UTF-8')) !== false ||
                    mb_strpos($wNameLower, 'whg ' . mb_strtolower($foundNum, 'UTF-8')) !== false ||
                    mb_strpos($wNameLower, 'whg ' . mb_strtolower($padNum, 'UTF-8')) !== false
                ) {
                    $parsed['wohnung_id'] = (int)$w['id'];
                    $parsed['wohnung_name'] = $w['name'];
                    $parsed['objekt_id'] = (int)$w['objekt_id'];
                    // Wenn Projekt noch nicht gesetzt war, jetzt vom Objekt ableiten
                    if (!$parsed['projekt_id'] && isset($projByObj[(int)$w['objekt_id']])) {
                        $parsed['projekt_id'] = $projByObj[(int)$w['objekt_id']];
                        foreach ($projekte as $p) {
                            if ((int)$p['id'] === $parsed['projekt_id']) {
                                $parsed['projekt_name'] = $p['name'];
                                break;
                            }
                        }
                    }
                    break;
                }
            }
        }

        // Fallback auf reine ID falls kein Namensmatch
        if (!$parsed['wohnung_id']) {
            foreach ($wohnungen as $w) {
                if ((string)$w['id'] === $foundNum) {
                    $parsed['wohnung_id'] = (int)$w['id'];
                    $parsed['wohnung_name'] = $w['name'];
                    $parsed['objekt_id'] = (int)$w['objekt_id'];
                    break;
                }
            }
        }
    }
    
    // Falls noch keine Wohnung, gegen alle Wohnungsnamen matchen
    if (!$parsed['wohnung_id']) {
        foreach ($wohnungen as $w) {
            $wProjId = $projByObj[(int)$w['objekt_id']] ?? 0;
            if ($detectedProjId && $wProjId !== $detectedProjId) continue;

            $wNameLower = mb_strtolower($w['name'], 'UTF-8');
            if (mb_strlen($wNameLower) >= 3 && mb_strpos($lowerText, $wNameLower) !== false) {
                $parsed['wohnung_id'] = (int)$w['id'];
                $parsed['wohnung_name'] = $w['name'];
                $parsed['objekt_id'] = (int)$w['objekt_id'];
                break;
            }
        }
    }
    
    // C) Raum-Erkennung
    $roomKeywords = [
        'küche' => ['küche', 'kueche', 'kitchen'],
        'bad' => ['bad', 'badezimmer', 'wc', 'toilette', 'dusche', 'bade-zimmer'],
        'wohnen' => ['wohnen', 'wohnzimmer', 'stube', 'salon'],
        'schlafen' => ['schlafzimmer', 'elternschlafzimmer', 'elternzimmer', 'schlafen'],
        'zimmer' => ['kinderzimmer', 'zimmer'],
        'balkon' => ['balkon', 'terrasse', 'loggia', 'sitzplatz'],
        'korridor' => ['korridor', 'flur', 'gang', 'diele', 'eingang', 'entrée', 'entree'],
        'keller' => ['keller', 'kellerabteil'],
        'estrich' => ['estrich', 'dachboden'],
        'waschküche' => ['waschküche', 'waschkueche', 'waschraum'],
        'garage' => ['garage', 'tiefgarage', 'einstellplatz', 'parkplatz']
    ];
    
    // 1. Zuerst Räume der gematchten Wohnung prüfen
    foreach ($raeume as $r) {
        $rNameLower = mb_strtolower($r['name'], 'UTF-8');
        if ($parsed['wohnung_id'] && (int)$r['wohnung_id'] !== $parsed['wohnung_id']) {
            continue;
        }
        
        $matchedRoom = false;
        if (mb_strpos($lowerText, $rNameLower) !== false) {
            $matchedRoom = true;
        } else {
            foreach ($roomKeywords as $category => $synonyms) {
                if (mb_strpos($rNameLower, $category) !== false) {
                    foreach ($synonyms as $syn) {
                        if (preg_match('/\b' . preg_quote($syn, '/') . '\b/ui', $lowerText)) {
                            $matchedRoom = true;
                            break 2;
                        }
                    }
                }
            }
        }
        
        if ($matchedRoom) {
            $parsed['raum_id'] = (int)$r['id'];
            $parsed['raum_name'] = $r['name'];
            break;
        }
    }
    
    // 2. Falls noch kein Raum und keine Wohnung, global suchen
    if (!$parsed['raum_id'] && !$parsed['wohnung_id']) {
        foreach ($raeume as $r) {
            $rNameLower = mb_strtolower($r['name'], 'UTF-8');
            $matchedRoom = false;
            if (mb_strpos($lowerText, $rNameLower) !== false) {
                $matchedRoom = true;
            } else {
                foreach ($roomKeywords as $category => $synonyms) {
                    if (mb_strpos($rNameLower, $category) !== false) {
                        foreach ($synonyms as $syn) {
                            if (preg_match('/\b' . preg_quote($syn, '/') . '\b/ui', $lowerText)) {
                                $matchedRoom = true;
                                break 2;
                            }
                        }
                    }
                }
            }
            if ($matchedRoom) {
                $parsed['raum_id'] = (int)$r['id'];
                $parsed['raum_name'] = $r['name'];
                if (!empty($r['wohnung_id'])) {
                    $parsed['wohnung_id'] = (int)$r['wohnung_id'];
                    foreach ($wohnungen as $w) {
                        if ((int)$w['id'] === (int)$r['wohnung_id']) {
                            $parsed['wohnung_name'] = $w['name'];
                            $parsed['objekt_id'] = (int)$w['objekt_id'];
                            break;
                        }
                    }
                }
                break;
            }
        }
    }
    
    // Falls Objekt aus Wohnung bekannt, Projekt auflösen falls noch nicht gesetzt
    if ($parsed['objekt_id'] && !$parsed['projekt_id']) {
        foreach ($objekte as $o) {
            if ((int)$o['id'] === $parsed['objekt_id']) {
                $parsed['objekt_name'] = $o['name'];
                $parsed['projekt_id'] = (int)$o['projekt_id'];
                foreach ($projekte as $p) {
                    if ((int)$p['id'] === (int)$o['projekt_id']) {
                        $parsed['projekt_name'] = $p['name'];
                        break;
                    }
                }
                break;
            }
        }
    } elseif ($parsed['objekt_id']) {
        foreach ($objekte as $o) {
            if ((int)$o['id'] === $parsed['objekt_id']) {
                $parsed['objekt_name'] = $o['name'];
                break;
            }
        }
    }
    
    // D) Wichtigkeit / Priorität
    if (preg_match('/\b(notfall|sofort|sehr dringend|akut|wasserschaden|brandgefahr|gefahr)\b/ui', $lowerText)) {
        $parsed['wichtigkeit'] = 5;
        $parsed['wichtigkeit_label'] = '🔥 Notfall / Höchste';
    } elseif (preg_match('/\b(dringend|eilig|schnell|prio|wichtig|hoch)\b/ui', $lowerText)) {
        $parsed['wichtigkeit'] = 4;
        $parsed['wichtigkeit_label'] = '⚠️ Dringend / Hoch';
    } elseif (preg_match('/\b(niedrig|zeitnah|keine eile|nachrangig|gering|später)\b/ui', $lowerText)) {
        $parsed['wichtigkeit'] = 2;
        $parsed['wichtigkeit_label'] = '⚪ Niedrig';
    } else {
        $parsed['wichtigkeit'] = 3;
        $parsed['wichtigkeit_label'] = 'Normal';
    }
    
    // E) Frist / Datum
    if (preg_match('/\bheute\b/ui', $lowerText)) {
        $parsed['enddatum'] = date('Y-m-d');
        $parsed['enddatum_label'] = 'Heute (' . date('d.m.Y') . ')';
    } elseif (preg_match('/\bmorgen\b/ui', $lowerText)) {
        $parsed['enddatum'] = date('Y-m-d', strtotime('+1 day'));
        $parsed['enddatum_label'] = 'Morgen (' . date('d.m.Y', strtotime('+1 day')) . ')';
    } elseif (preg_match('/\bübermorgen\b/ui', $lowerText)) {
        $parsed['enddatum'] = date('Y-m-d', strtotime('+2 days'));
        $parsed['enddatum_label'] = 'Übermorgen (' . date('d.m.Y', strtotime('+2 days')) . ')';
    } elseif (preg_match('/\bbis\s+(freitag|montag|dienstag|mittwoch|donnerstag|samstag|sonntag)\b/ui', $lowerText, $dm)) {
        $weekday = strtolower($dm[1]);
        $map = [
            'montag' => 'next monday',
            'dienstag' => 'next tuesday',
            'mittwoch' => 'next wednesday',
            'donnerstag' => 'next thursday',
            'freitag' => 'next friday',
            'samstag' => 'next saturday',
            'sonntag' => 'next sunday'
        ];
        $targetDay = $map[$weekday] ?? '+3 days';
        $d = date('Y-m-d', strtotime($targetDay));
        $parsed['enddatum'] = $d;
        $parsed['enddatum_label'] = ucfirst($weekday) . ' (' . date('d.m.Y', strtotime($d)) . ')';
    } elseif (preg_match('/\bbis\s+ende\s+woche\b/ui', $lowerText)) {
        $d = date('Y-m-d', strtotime('this week sunday'));
        $parsed['enddatum'] = $d;
        $parsed['enddatum_label'] = 'Ende Woche (' . date('d.m.Y', strtotime($d)) . ')';
    } elseif (preg_match('/\bbis\s+ende\s+monat\b/ui', $lowerText)) {
        $d = date('Y-m-t');
        $parsed['enddatum'] = $d;
        $parsed['enddatum_label'] = 'Ende Monat (' . date('d.m.Y', strtotime($d)) . ')';
    } elseif (preg_match('/\b(?:bis\s+)?(\d{1,2})\.(\d{1,2})\.?(\d{4})?\b/u', $lowerText, $dm)) {
        $day = (int)$dm[1];
        $month = (int)$dm[2];
        $year = !empty($dm[3]) ? (int)$dm[3] : (int)date('Y');
        $parsed['enddatum'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $parsed['enddatum_label'] = sprintf('%02d.%02d.%04d', $day, $month, $year);
    }
    
    // F) Vorgangsart
    foreach ($arten as $art) {
        $aLower = mb_strtolower($art['name'], 'UTF-8');
        if (mb_strpos($lowerText, $aLower) !== false) {
            $parsed['vorgangsart_id'] = (int)$art['id'];
            $parsed['vorgangsart_name'] = $art['name'];
            break;
        }
    }
    if (!$parsed['vorgangsart_id']) {
        if (preg_match('/\b(reparatur|defekt|tropft|kaputt|klemmt|rinnt|schaden|mangel)\b/ui', $lowerText)) {
            // Finde Mangel oder Reparatur
            foreach ($arten as $art) {
                $aLower = mb_strtolower($art['name'], 'UTF-8');
                if (str_contains($aLower, 'reparatur') || str_contains($aLower, 'mangel') || str_contains($aLower, 'schaden')) {
                    $parsed['vorgangsart_id'] = (int)$art['id'];
                    $parsed['vorgangsart_name'] = $art['name'];
                    break;
                }
            }
        }
    }
    // Standard falls keine gefunden
    if (!$parsed['vorgangsart_id'] && !empty($arten)) {
        $parsed['vorgangsart_id'] = (int)$arten[0]['id'];
        $parsed['vorgangsart_name'] = $arten[0]['name'];
    }
    
    // G) Titel-Generierung: Bereinige den Text um Standard-Füllwörter
    $cleanTitle = $text;
    $cleanTitle = preg_replace('/^(bitte\s+)?(in\s+)?([0-9a-zA-Z\.\-_]+\s+)?(wohnung\s+[0-9a-zA-Z\.\-_]+\s+)?(im\s+[a-zA-ZäöüÄÖÜ]+\s+)?/ui', '', $cleanTitle);
    $cleanTitle = preg_replace('/\b(dringend|sofort|bis\s+[a-zA-Z0-9\.\s]+|bitte|erledigen)\b/ui', '', $cleanTitle);
    $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle), " ,.:;-");
    
    if (mb_strlen($cleanTitle) > 5) {
        $parsed['titel'] = mb_strtoupper(mb_substr($cleanTitle, 0, 1)) . mb_substr($cleanTitle, 1);
    } else {
        $parsed['titel'] = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
    // Titel auf max 150 Zeichen kürzen
    if (mb_strlen($parsed['titel']) > 150) {
        $parsed['titel'] = mb_substr($parsed['titel'], 0, 147) . '...';
    }
    
    // 3. Aktion ausführen
    if ($action === 'parse') {
        json_response([
            'ok' => true,
            'text' => $text,
            'parsed' => $parsed
        ]);
    }
    
    if ($action === 'save' || $action === 'save_direct') {
        // Falls Daten im Request überschrieben wurden (z.B. nach Benutzer-Korrektur)
        $pId = !empty($data['projekt_id']) ? (int)$data['projekt_id'] : $parsed['projekt_id'];
        $vId = !empty($data['vorgangsart_id']) ? (int)$data['vorgangsart_id'] : $parsed['vorgangsart_id'];
        $oId = !empty($data['objekt_id']) ? (int)$data['objekt_id'] : $parsed['objekt_id'];
        $wId = !empty($data['wohnung_id']) ? (int)$data['wohnung_id'] : $parsed['wohnung_id'];
        $rId = !empty($data['raum_id']) ? (int)$data['raum_id'] : $parsed['raum_id'];
        $finalTitel = trim((string)($data['titel'] ?? $parsed['titel']));
        $finalDesc  = trim((string)($data['beschreibung'] ?? $parsed['beschreibung']));
        $wichtig    = !empty($data['wichtigkeit']) ? (int)$data['wichtigkeit'] : $parsed['wichtigkeit'];
        $enddatum   = !empty($data['enddatum']) ? $data['enddatum'] : $parsed['enddatum'];
        $zId        = !empty($data['zustaendig_id']) ? (int)$data['zustaendig_id'] : $parsed['zustaendig_id'];
        
        if ($finalTitel === '') {
            $finalTitel = 'Voice-Pendenz vom ' . date('d.m.Y H:i');
        }
        
        $newToken = bin2hex(random_bytes(16));
        $extraJson = json_encode(['source' => 'voice_assistant', 'spoken_text' => $text], JSON_UNESCAPED_UNICODE);
        
        $sqlInsert = "
            INSERT INTO pendenzen (
                mandant_id, projekt_id, vorgangsart_id, objekt_id, wohnung_id, raum_id,
                titel, kurzbeschreibung, beschreibung,
                status, wichtigkeit, startdatum, enddatum,
                send_now, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id,
                confirmation_required, confirmation_by, external_can_view, external_can_upload,
                public_enabled, public_token, extra_json, zustaendig_typ
            ) VALUES (
                0, ?, ?, ?, ?, ?, ?, ?, ?, 'offen', ?, CURDATE(), ?, 0, ?, 'projekt', 1, ?, 0, 'assignee', 1, 1, 1, ?, ?, 'user'
            )
        ";
        
        $stmt = $db->prepare($sqlInsert);
        $stmt->bind_param(
            'iiiiisssisisss',
            $pId,
            $vId,
            $oId,
            $wId,
            $rId,
            $finalTitel,
            $finalTitel,
            $finalDesc,
            $wichtig,
            $enddatum,
            $userId,
            $zId,
            $newToken,
            $extraJson
        );
        
        $ok = $stmt->execute();
        $newId = $ok ? (int)$stmt->insert_id : 0;
        $stmt->close();
        
        if (!$ok || $newId === 0) {
            json_response(['ok' => false, 'error' => 'DB_INSERT_FAILED', 'message' => 'Speichern der Pendenz fehlgeschlagen.'], 500);
        }
        
        json_response([
            'ok' => true,
            'id' => $newId,
            'message' => "Pendenz #{$newId} erfolgreich via Sprache erfasst!",
            'parsed' => $parsed
        ]);
    }
    
    json_response(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 400);
});
