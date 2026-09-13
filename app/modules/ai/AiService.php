<?php
namespace App\Modules\AiAssistant;

use mysqli;
use Exception;

class AiService {
    private $db;
    private $apiKey;

    private $userId;

    public function __construct(mysqli $db, string $apiKey = '', int $userId = 0) {
        $this->db = $db;
        $this->apiKey = $apiKey;
        $this->userId = $userId;
    }

    /**
     * Sammelt alle relevanten Informationen für den KI-Kontext.
     */
    public function getSystemContext(string $currentPageUrl = ''): string {
        $context = "Du bist 'gimi', der proaktive KI-Co-Pilot von pendenz.com. Dein Ziel ist maximale Effizienz.\n";
        $context .= "TRAINING: KURZE SÄTZE ALS PENDENZEN ERKENNEN\n";
        $context .= "1. Wenn der Benutzer kurze Phrasen schreibt (z.B. 'Fenster kaputt', 'Müller anrufen', 'Heizung prüfen'), erkenne dies SOFORT als Aufgabe.\n";
        $context .= "2. Erstelle direkt den [ACTION:CREATE_PENDENZ|...] Tag am Ende deiner Antwort.\n";
        $context .= "3. Antworte extrem kurz und bestätigend, z.B.: 'Gerne, ich habe \"Fenster kaputt\" für heute notiert. [ACTION:CREATE_PENDENZ|title=Fenster kaputt|date=" . date('Y-m-d') . "]'\n";
        $context .= "4. Frage nur nach, wenn es absolut unklar ist. Wenn das Datum fehlt, setze es standardmäßig auf HEUTE.\n\n";
        
        $context .= "Hier sind die aktuellen System-Regeln und Wissensbausteine:\n";

        // Training-Daten aus der DB holen (Global + User-spezifisch + Seiten-spezifisch)
        $sql = "SELECT title, content, scope_url FROM ai_training WHERE is_active = 1 AND (user_id IS NULL";
        if ($this->userId > 0) $sql .= " OR user_id = " . (int)$this->userId;
        $sql .= ")";
        
        $res = $this->db->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $scope = $row['scope_url'];
                if (empty($scope) || $scope === '*' || ($currentPageUrl !== '' && strpos($currentPageUrl, $scope) !== false)) {
                    $context .= "- " . $row['title'] . ": " . $row['content'] . "\n";
                }
            }
        }

        return $context;
    }

    /**
     * Sendet eine Anfrage an Gemini mit System-Instruction und Historie.
     */
    public function queryGemini(string $prompt, array $pageContext = [], array $history = []): string {
        if (empty($this->apiKey)) {
            return $this->getMockResponse($prompt, $pageContext);
        }

        // Erweitertes Modell-Cycling, um Quoten-Limits zu umgehen
        $models = [
            "gemini-flash-latest", 
            "gemini-1.5-flash-latest", 
            "gemini-2.0-flash", 
            "gemini-pro-latest", 
            "gemini-flash-lite-latest"
        ]; 
        $lastResponse = '';
        $lastHttpCode = 0;
        $lastCurlErr = '';

        foreach ($models as $currentModel) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent?key=" . $this->apiKey;
            
            $currentPage = $pageContext['url'] ?? '';
            $systemContext = $this->getSystemContext($currentPage);

            // System-Kontext als erste Nachricht injizieren (Universal-Kompatibel)
            $contents = [];
            
            // Erste Nachricht bekommt das System-Briefing vorangestellt
            $isFirst = true;
            $slicedHistory = array_slice($history, -3);
            foreach ($slicedHistory as $msg) {
                $text = $msg['content'];
                if ($isFirst) {
                    $text = "System-Anweisung: " . $systemContext . "\n\n" . $text;
                    $isFirst = false;
                }
                $contents[] = [
                    "role" => ($msg['role'] === 'user' ? 'user' : 'model'),
                    "parts" => [["text" => $text]]
                ];
            }
            
            // Aktuelle Anfrage hinzufügen
            $finalPrompt = $prompt;
            if ($isFirst) {
                $finalPrompt = "System-Anweisung: " . $systemContext . "\n\n" . $prompt;
            }
            
            $contents[] = [
                "role" => "user",
                "parts" => [["text" => $finalPrompt]]
            ];

            $postData = [
                "contents" => $contents,
                "generationConfig" => [
                    "temperature" => 0.7,
                    "maxOutputTokens" => 1200,
                    "topP" => 0.8,
                    "topK" => 40
                ]
            ];

            $maxRetries = 4;
            $attempt = 0;

            while ($attempt < $maxRetries) {
                $attempt++;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                
                $lastResponse = curl_exec($ch);
                $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $lastCurlErr  = curl_error($ch);
                // curl_close($ch); // Deprecated since PHP 8.x


                // Bei 503 (Overloaded) oder 429 (Rate Limit) kurz warten und erneut versuchen
                if (in_array($lastHttpCode, [503, 429])) {
                    usleep(1000000 * $attempt); // Progressiv warten (1s, 2s, 3s...)
                    continue;
                }
                break;
            }

            // Wenn wir einen Erfolg (200) haben, verlassen wir die Model-Schleife
            if ($lastHttpCode === 200) break;
            
            // Falls 429 -> nächstes Modell probieren
            if ($lastHttpCode === 429) continue;
        }

        if ($lastHttpCode !== 200) {
            // Falls ultimativ gescheitert (Quota voll), nutze den Mock-Modus statt Fehlermeldung
            if ($lastHttpCode === 429 || $lastHttpCode === 503 || $lastHttpCode === 0) {
                return $this->getMockResponse($prompt, $pageContext, 'quota');
            }
            
            $msg = "Gemini API Error (Code $lastHttpCode)";
            if ($lastCurlErr) $msg .= " CURL Error: " . $lastCurlErr;
            if ($lastResponse) $msg .= " Response: " . $lastResponse;
            throw new Exception($msg);
        }

        $data = json_decode($lastResponse, true);
        $answer = $data['candidates'][0]['content']['parts'][0]['text'] ?? "Keine Antwort von gimi.";
        
        return $answer;
    }

    private function getMockResponse(string $prompt, array $pageContext, string $reason = 'no_key'): string {
        $title = $pageContext['title'] ?? 'dieser Seite';
        $prompt = strtolower($prompt);
        
        // Suche nach relevantem Wissen in der Training-DB
        $knowledge = [];
        $res = $this->db->query("SELECT title, content FROM ai_training WHERE is_active = 1");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                // Einfaches Keyword-Matching für den Offline-Modus
                $keywords = explode(' ', strtolower($row['title']));
                foreach ($keywords as $kw) {
                    if (strlen($kw) > 3 && strpos($prompt, $kw) !== false) {
                        $knowledge[] = "💡 **" . $row['title'] . "**: " . $row['content'];
                        break;
                    }
                }
            }
        }

        if ($reason === 'quota') {
            $status = "⚠️ **Hinweis:** Da Google aktuell überlastet ist, antworte ich im **lokalen Intelligenz-Modus**.";
        } else {
            $status = "👋 Hallo! Da aktuell noch kein Gemini-Key hinterlegt ist, antworte ich im **Simulationsmodus**.";
        }

        $response = $status . "\n\n";
        
        if (!empty($knowledge)) {
            $response .= "Ich habe in meinem lokalen Gedächtnis passende Informationen gefunden:\n\n";
            $response .= implode("\n\n", array_slice($knowledge, 0, 3));
            $response .= "\n\nWie kann ich dir sonst noch helfen?";
        } else {
            $response .= "Ich sehe, dass du dich auf **$title** befindest. Leider kann ich im lokalen Modus ohne aktive Cloud-Verbindung nur einfache Fragen beantworten oder dir Tipps zur aktuellen Seite geben.\n\n";
            $response .= "Was möchtest du als Nächstes tun?";
        }
        
        return $response;
    }
}
