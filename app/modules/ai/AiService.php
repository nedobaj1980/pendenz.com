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
        $context = "Du bist 'gimi', der hochintelligente Schweizer PropTech KI-Copilot von pendenz.com (Helvetic Immo Treuhand).\n";
        $context .= "Dein Eigentümer und Verwalter ist Nedim Bajramoski. Du unterstützt ihn vollkommen selbstständig, kompetent und vorausschauend.\n";
        $context .= "Du antwortest immer auf Deutsch, professionell, freundlich und präzise mit Schweizer Kontext (CHF, Schweizer Mietrecht, HEV-Praxis).\n";
        $context .= "Aktuelles Datum: " . date('d.m.Y') . " (" . date('l') . ").\n\n";

        // Portfolio-Kennzahlen live aggregieren
        $pCount = 0; $wCount = 0; $mCount = 0; $sollNetto = 0.0; $sollNk = 0.0; $pendCount = 0;
        
        $pRes = $this->db->query("SELECT COUNT(*) FROM projekte");
        if ($pRes) $pCount = (int)$pRes->fetch_row()[0];

        $wRes = $this->db->query("SELECT COUNT(*) FROM wohnungen");
        if ($wRes) $wCount = (int)$wRes->fetch_row()[0];

        $mRes = $this->db->query("SELECT COUNT(*), COALESCE(SUM(mietzins_netto),0), COALESCE(SUM(nk_akonto),0) FROM wohnung_mieter WHERE status = 'aktiv'");
        if ($mRes) {
            $mRow = $mRes->fetch_row();
            $mCount = (int)$mRow[0];
            $sollNetto = (float)$mRow[1];
            $sollNk = (float)$mRow[2];
        }
        $sollBrutto = $sollNetto + $sollNk;
        $leerstand = max(0, $wCount - $mCount);

        $pendRes = $this->db->query("SELECT COUNT(*) FROM pendenzen WHERE (status IS NULL OR status NOT IN ('erledigt','archiviert')) AND deleted_at IS NULL");
        if ($pendRes) $pendCount = (int)$pendRes->fetch_row()[0];

        $context .= "PORTFOLIO-KENNZAHLEN (LIVE):\n";
        $context .= "- Liegenschaften: {$pCount}\n";
        $context .= "- Einheiten total: {$wCount} (davon {$mCount} vermietet, {$leerstand} leerstehend/frei)\n";
        $context .= "- Monatlicher Soll-Mietertrag: CHF " . number_format($sollBrutto, 2, '.', "'") . " (Netto: CHF " . number_format($sollNetto, 2, '.', "'") . ", NK: CHF " . number_format($sollNk, 2, '.', "'") . ")\n";
        $context .= "- Jahres-Mietertrag: CHF " . number_format($sollBrutto * 12, 2, '.', "'") . "\n";
        $context .= "- Offene Pendenzen/Mängel: {$pendCount}\n\n";

        // Alle Liegenschaften auflisten
        $context .= "ALLE LIEGENSCHAFTEN (PROJEKTE):\n";
        $pList = $this->db->query("SELECT p.id, p.name, 
            COUNT(DISTINCT w.id) as units,
            (SELECT COUNT(*) FROM wohnung_mieter wm JOIN wohnungen w2 ON wm.wohnung_id = w2.id JOIN objekte o2 ON w2.objekt_id = o2.id WHERE o2.projekt_id = p.id AND wm.status = 'aktiv') as mieter
            FROM projekte p
            LEFT JOIN objekte o ON o.projekt_id = p.id
            LEFT JOIN wohnungen w ON w.objekt_id = o.id
            GROUP BY p.id ORDER BY p.id ASC");
        if ($pList) {
            while ($p = $pList->fetch_assoc()) {
                $context .= "- ID {$p['id']}: {$p['name']} ({$p['units']} Einheiten, {$p['mieter']} vermietet)\n";
            }
            $context .= "\n";
        }

        // Aktive Mieterübersicht (kompakt)
        $tList = $this->db->query("
            SELECT p.id as p_id, p.name as p_name, w.id as w_id, w.name as w_name, wm.mieter_name, wm.mietzins_netto, wm.nk_akonto, wm.startdatum
            FROM wohnung_mieter wm
            JOIN wohnungen w ON wm.wohnung_id = w.id
            JOIN objekte o ON w.objekt_id = o.id
            JOIN projekte p ON o.projekt_id = p.id
            WHERE wm.status = 'aktiv'
            ORDER BY p.id ASC, w.name ASC
        ");
        if ($tList && $tList->num_rows > 0) {
            $context .= "AKTIVE MIETER (AUSZUG):\n";
            while ($t = $tList->fetch_assoc()) {
                $brutto = (float)$t['mietzins_netto'] + (float)$t['nk_akonto'];
                $context .= "- Liegenschaft ID {$t['p_id']} ({$t['p_name']}) | {$t['w_name']}: {$t['mieter_name']} | CHF " . number_format($brutto, 2, '.', "'") . "/Mt. | seit {$t['startdatum']}\n";
            }
            $context .= "\n";
        }

        $context .= "SYSTEM-MODULE & DIREKT-LINKS:\n";
        $context .= "- Mieterspiegel: pages/mieterspiegel.php (oder mit ?projekt_id={id})\n";
        $context .= "- Liegenschaftsabrechnung & Steuern: tools/liegenschaftsabrechnung/index.php (oder ?projekt_id={id})\n";
        $context .= "- Google Drive Explorer: pages/files.php (oder ?projekt_id={id})\n";
        $context .= "- Pendenzen & Mängel: pages/pendenzen.php (oder ?projekt_id={id})\n";
        $context .= "- Wohnungsabnahmeprotokoll: pages/wohnungsabnahme_protokoll.php?wohnung_id={id}\n";
        $context .= "- Schweizer Mietvertrag-Generator: pages/vertrag_gen.php?wohnung_id={id}\n\n";

        $context .= "AUFGABEN-ERKENNUNG & AKTIONEN:\n";
        $context .= "Wenn der Benutzer eine Aufgabe, Reparatur, Mangel, Besichtigung oder To-Do erwähnt oder diktiert:\n";
        $context .= "1. Bestätige dies kurz und prägnant in 1 bis 2 Sätzen.\n";
        $context .= "2. Hänge als allerletzte Zeile IMMER und zwingend diesen Tag an:\n";
        $context .= "[ACTION:CREATE_PENDENZ|title=Prägnanter Titel|project_id=ID|wohnung_id=ID|due=YYYY-MM-DD|priority=5]\n";
        $context .= "(priority: 5=dringend/hoch, 3=normal, 1=niedrig; project_id=1-12 passend zur Liegenschaft).\n\n";

        // Training-Daten aus der DB holen
        $sql = "SELECT title, content, scope_url FROM ai_training WHERE is_active = 1 AND (user_id IS NULL";
        if ($this->userId > 0) $sql .= " OR user_id = " . (int)$this->userId;
        $sql .= ")";
        
        $res = $this->db->query($sql);
        if ($res && $res->num_rows > 0) {
            $context .= "INDIVIDUELLES SYSTEM-WISSEN:\n";
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

        // Neueste, intelligenteste Gemini-Modelle (Google AI Studio Free Tier)
        $models = [
            "gemini-3.6-flash",
            "gemini-3.5-flash",
            "gemini-2.5-flash-lite",
            "gemini-flash-latest",
            "gemini-pro-latest"
        ]; 
        $lastResponse = '';
        $lastHttpCode = 0;
        $lastCurlErr = '';

        foreach ($models as $currentModel) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent?key=" . $this->apiKey;
            
            $currentPage = $pageContext['url'] ?? '';
            $systemContext = $this->getSystemContext($currentPage);

            $contents = [];
            $slicedHistory = array_slice($history, -6);
            foreach ($slicedHistory as $msg) {
                $contents[] = [
                    "role" => ($msg['role'] === 'user' ? 'user' : 'model'),
                    "parts" => [["text" => (string)$msg['content']]]
                ];
            }
            
            // Aktuelle Anfrage hinzufügen
            $contents[] = [
                "role" => "user",
                "parts" => [["text" => $prompt]]
            ];

            $postData = [
                "system_instruction" => [
                    "parts" => [["text" => $systemContext]]
                ],
                "contents" => $contents,
                "generationConfig" => [
                    "temperature" => 0.6,
                    "maxOutputTokens" => 4096,
                    "topP" => 0.95
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
