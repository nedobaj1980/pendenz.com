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
    public function getSystemContext(string $currentPageUrl = '', array $pageContext = []): string {
        $context = "Du bist 'gimi', der hochintelligente Schweizer PropTech KI-Copilot von pendenz.com (Helvetic Immo Treuhand).\n";
        $context .= "Dein Eigentümer und Verwalter ist Nedim Bajramoski. Du unterstützt ihn vollkommen selbstständig, kompetent und vorausschauend.\n";
        $context .= "Du antwortest immer auf Deutsch, professionell, freundlich und präzise mit Schweizer Kontext (CHF, Schweizer Mietrecht gemäss OR Art. 253 ff., HEV-Praxis, Nebenkosten VMWG).\n";
        $context .= "Aktuelles Datum: " . date('d.m.Y') . " (" . date('l') . ").\n\n";

        // URL Parameter extrahieren
        $urlQuery = parse_url($currentPageUrl, PHP_URL_QUERY);
        $urlParams = [];
        if ($urlQuery) {
            parse_str($urlQuery, $urlParams);
        }

        $activeProjectId = (int)($pageContext['projekt_id'] ?? $urlParams['projekt_id'] ?? 0);
        $activeWohnungId = (int)($pageContext['wohnung_id'] ?? $urlParams['wohnung_id'] ?? 0);
        $activePath = (string)($pageContext['path'] ?? $urlParams['path'] ?? '');

        // 1. Spezifischer Kontext wenn ein Projekt aktiv ist
        if ($activeProjectId > 0) {
            $pStmt = $this->db->prepare("SELECT id, name, root_path FROM projekte WHERE id = ?");
            if ($pStmt) {
                $pStmt->bind_param("i", $activeProjectId);
                $pStmt->execute();
                $pRow = $pStmt->get_result()->fetch_assoc();
                $pStmt->close();

                if ($pRow) {
                    $context .= ">>> AKTUELLES PROJEKT IM FOKUS: ID {$activeProjectId} - {$pRow['name']} <<<\n";
                    $context .= "- Drive Root: " . ($pRow['root_path'] ?: 'Standard') . "\n";

                    // Einheiten & Mieter dieser Liegenschaft
                    $uStmt = $this->db->prepare("
                        SELECT w.id as w_id, w.name as w_name, w.zimmer, w.flaeche,
                               wm.mieter_name, wm.mietzins_netto, wm.nk_akonto, wm.startdatum
                        FROM wohnungen w
                        JOIN objekte o ON w.objekt_id = o.id
                        LEFT JOIN wohnung_mieter wm ON wm.wohnung_id = w.id AND wm.status = 'aktiv'
                        WHERE o.projekt_id = ?
                        ORDER BY w.name ASC
                    ");
                    if ($uStmt) {
                        $uStmt->bind_param("i", $activeProjectId);
                        $uStmt->execute();
                        $uRes = $uStmt->get_result();
                        $context .= "EINHEITEN & MIETER DIESER LIEGENSCHAFT:\n";
                        while ($u = $uRes->fetch_assoc()) {
                            $brutto = (float)$u['mietzins_netto'] + (float)$u['nk_akonto'];
                            if (!empty($u['mieter_name'])) {
                                $context .= "- {$u['w_name']} ({$u['zimmer']} Zi, {$u['flaeche']}m²): {$u['mieter_name']} | CHF " . number_format($brutto, 2, '.', "'") . "/Mt. (Netto: {$u['mietzins_netto']}, NK: {$u['nk_akonto']}) | seit {$u['startdatum']}\n";
                            } else {
                                $context .= "- {$u['w_name']} ({$u['zimmer']} Zi, {$u['flaeche']}m²): [FREI / LEERSTEHEND]\n";
                            }
                        }
                        $uStmt->close();
                    }

                    // Falls im Google Drive Explorer (files.php) mit Ordnerpfad
                    if ($activePath !== '') {
                        $normPath = trim(str_replace('\\', '/', $activePath), '/');
                        $context .= "\nAKTUELL GEÖFFNETER GOOGLE DRIVE ORDNER:\n";
                        $context .= "- Relativer Pfad: {$normPath}\n";

                        // Dateien aus fs_nodes
                        $fStmt = $this->db->prepare("SELECT name, is_dir, size FROM fs_nodes WHERE project_id = ? AND parent_rel_path = ? ORDER BY is_dir DESC, name ASC LIMIT 35");
                        $foundNodes = false;
                        if ($fStmt) {
                            $fStmt->bind_param("is", $activeProjectId, $normPath);
                            $fStmt->execute();
                            $fRes = $fStmt->get_result();
                            if ($fRes && $fRes->num_rows > 0) {
                                $foundNodes = true;
                                $context .= "Dateien und Ordner an dieser Position:\n";
                                while ($fn = $fRes->fetch_assoc()) {
                                    $t = $fn['is_dir'] ? '[ORDNER]' : '[DATEI]';
                                    $sz = $fn['is_dir'] ? '' : ' (' . round((int)$fn['size'] / 1024, 1) . ' KB)';
                                    $context .= "  * {$t} {$fn['name']}{$sz}\n";
                                }
                            }
                            $fStmt->close();
                        }

                        // Falls fs_nodes leer, physisch prüfen
                        if (!$foundNodes && function_exists('project_root_path')) {
                            $pRoot = project_root_path($this->db, $activeProjectId);
                            if ($pRoot) {
                                $fullD = $pRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normPath);
                                if (is_dir($fullD)) {
                                    $scan = @scandir($fullD);
                                    if ($scan) {
                                        $context .= "Dateien auf Google Drive:\n";
                                        foreach ($scan as $sf) {
                                            if ($sf === '.' || $sf === '..') continue;
                                            $isD = is_dir($fullD . DIRECTORY_SEPARATOR . $sf);
                                            $context .= "  * " . ($isD ? '[ORDNER] ' : '[DATEI] ') . $sf . "\n";
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // Offene Pendenzen für diese Liegenschaft
                    $pendProj = $this->db->query("SELECT id, titel, wichtigkeit, enddatum, status FROM pendenzen WHERE projekt_id = {$activeProjectId} AND deleted_at IS NULL AND (status IS NULL OR status NOT IN ('erledigt','archiviert')) ORDER BY wichtigkeit DESC, id DESC LIMIT 8");
                    if ($pendProj && $pendProj->num_rows > 0) {
                        $context .= "\nOFFENE PENDENZEN FÜR DIESE LIEGENSCHAFT:\n";
                        while ($pp = $pendProj->fetch_assoc()) {
                            $context .= "- Pendenz #{$pp['id']}: {$pp['titel']} (Prio {$pp['wichtigkeit']}, Fällig: {$pp['enddatum']})\n";
                        }
                    }
                    $context .= "\n";
                }
            }
        }

        // 2. Spezifischer Kontext wenn eine Wohnung aktiv ist
        if ($activeWohnungId > 0) {
            $wStmt = $this->db->prepare("
                SELECT w.id, w.name, w.zimmer, w.flaeche, o.name as objekt_name, p.id as p_id, p.name as p_name,
                       wm.mieter_name, wm.mietzins_netto, wm.nk_akonto, wm.startdatum
                FROM wohnungen w
                JOIN objekte o ON w.objekt_id = o.id
                JOIN projekte p ON o.projekt_id = p.id
                LEFT JOIN wohnung_mieter wm ON wm.wohnung_id = w.id AND wm.status = 'aktiv'
                WHERE w.id = ?
            ");
            if ($wStmt) {
                $wStmt->bind_param("i", $activeWohnungId);
                $wStmt->execute();
                $wRow = $wStmt->get_result()->fetch_assoc();
                $wStmt->close();
                if ($wRow) {
                    $context .= ">>> AKTUELLE WOHNUNG IM FOKUS: {$wRow['name']} ({$wRow['p_name']}) <<<\n";
                    $context .= "- Typ: {$wRow['zimmer']} Zimmer, Fläche: {$wRow['flaeche']}m²\n";
                    if (!empty($wRow['mieter_name'])) {
                        $context .= "- Aktiver Mieter: {$wRow['mieter_name']} | Netto CHF {$wRow['mietzins_netto']} + NK CHF {$wRow['nk_akonto']} | seit {$wRow['startdatum']}\n";
                    } else {
                        $context .= "- Mieter-Status: FREI / LEERSTAND\n";
                    }
                    $context .= "\n";
                }
            }
        }

        // 3. Portfolio-Kennzahlen live aggregieren
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

        $context .= "PORTFOLIO-GESAMTÜBERSICHT (LIVE):\n";
        $context .= "- Liegenschaften: {$pCount}\n";
        $context .= "- Einheiten total: {$wCount} (davon {$mCount} vermietet, {$leerstand} leerstehend)\n";
        $context .= "- Monatlicher Soll-Mietertrag: CHF " . number_format($sollBrutto, 2, '.', "'") . " (Netto: CHF " . number_format($sollNetto, 2, '.', "'") . ", NK: CHF " . number_format($sollNk, 2, '.', "'") . ")\n";
        $context .= "- Jahres-Sollertrag: CHF " . number_format($sollBrutto * 12, 2, '.', "'") . "\n";
        $context .= "- Offene Pendenzen total: {$pendCount}\n\n";

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

        $context .= "SYSTEM-MODULE & DIREKT-LINKS:\n";
        $context .= "- Mieterspiegel: pages/mieterspiegel.php?projekt_id={id}\n";
        $context .= "- Liegenschaftsabrechnung & Steuern: tools/liegenschaftsabrechnung/index.php?projekt_id={id}\n";
        $context .= "- Google Drive Explorer: pages/files.php?projekt_id={id}\n";
        $context .= "- Pendenzen & Mängel: pages/pendenzen.php?projekt_id={id}\n";
        $context .= "- Wohnungsabnahmeprotokoll: pages/wohnungsabnahme_protokoll.php?wohnung_id={id}\n";
        $context .= "- Schweizer Mietvertrag-Generator: pages/vertrag_gen.php?wohnung_id={id}\n\n";

        $context .= "AUFGABEN-ERKENNUNG & AKTIONEN:\n";
        $context .= "SEITENASSISTENT: Nutze den übergebenen Seiten-, Tabellen- und Filterkontext. Bei Fragen zu einer sichtbaren Tabelle nenne konkrete Zeilen und Werte. Bei Navigationswünschen antworte mit einem direkten Link aus den SYSTEM-MODUL-LINKS. Erfinde keine IDs oder Zahlen.\n";
        $context .= "SCHREIBREGEL: Erstelle oder ändere niemals produktive Daten direkt. Wenn eine Pendenz gewünscht wird, gib zuerst eine kurze Vorschau aus und verwende danach den CREATE_PENDENZ-Tag; das System fordert vor dem Speichern eine Bestätigung an.\n";
        $context .= "Wenn der Benutzer eine Aufgabe, Reparatur, Mangel, Besichtigung oder To-Do erwähnt oder diktiert:\n";
        $context .= "1. Bestätige dies kurz, positiv und prägnant.\n";
        $context .= "2. Hänge als allerletzte Zeile IMMER diesen Tag an:\n";
        $targetPid = $activeProjectId > 0 ? $activeProjectId : 1;
        $context .= "[ACTION:CREATE_PENDENZ|title=Prägnanter Titel|subject=Kurzer Betreff|long=Ausführliche Beschreibung|project_id={$targetPid}|wohnung_id={$activeWohnungId}|due=YYYY-MM-DD|priority=5]\n";
        $context .= "(priority: 5=dringend/hoch, 3=normal, 1=niedrig).\n\n";

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

        // Höchstintelligente, schnelle & 100% kostenlose Modelle (Google AI Studio Free Tier)
        $models = [
            "gemini-3.1-flash-lite", // Ultraschnell (~1-2s), hochintelligent, 100% free
            "gemini-flash-latest",   // Offizielles Google Flash Produktionsmodell
            "gemini-3.6-flash",      // Deep Reasoning Flash
            "gemini-3.5-flash",      // Zuverlässiger Fallback
            "gemini-3-flash-preview"
        ]; 
        $lastResponse = '';
        $lastHttpCode = 0;
        $lastCurlErr = '';

        $currentPage = $pageContext['url'] ?? '';
        $systemContext = $this->getSystemContext($currentPage, $pageContext);

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

        $jsonPayload = json_encode($postData);

        foreach ($models as $currentModel) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$currentModel}:generateContent?key=" . $this->apiKey;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            
            $lastResponse = curl_exec($ch);
            $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastCurlErr  = curl_error($ch);

            // Wenn Erfolg (200), sofort aussteigen!
            if ($lastHttpCode === 200 && !empty($lastResponse)) {
                break;
            }
            
            // Bei 503 (Overloaded) oder 429 (Rate Limit) oder 404 sofort zum nächsten Modell springen!
            continue;
        }

        if ($lastHttpCode !== 200) {
            // Falls ultimativ gescheitert (Quota voll), nutze den lokalen Intelligenz-Modus
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
