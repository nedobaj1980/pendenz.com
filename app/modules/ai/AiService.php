<?php
declare(strict_types=1);

namespace App\Modules\AiAssistant;

use mysqli;
use RuntimeException;
use Throwable;

class AiService
{
    private mysqli $db;
    private string $apiKey;
    private int $userId;

    public function __construct(mysqli $db, string $apiKey = '', int $userId = 0)
    {
        $this->db = $db;
        $this->apiKey = trim($apiKey);
        $this->userId = $userId;
    }

    /**
     * Ermittelt alle Projekt-IDs, die der aktuelle Benutzer tatsächlich sehen darf.
     * Damit werden weder Portfolio-Daten noch Mieterdaten anderer Projekte an Gemini gesendet.
     *
     * @return int[]
     */
    private function accessibleProjectIds(): array
    {
        if ($this->userId <= 0) {
            return [];
        }

        $ids = [];
        $role = '';
        $businessType = '';
        $wohnungId = 0;

        $stmt = $this->db->prepare(
            'SELECT rolle, business_type, wohnung_id
             FROM benutzer
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            return [];
        }

        $role = (string) ($user['rolle'] ?? '');
        $businessType = (string) ($user['business_type'] ?? '');
        $wohnungId = (int) ($user['wohnung_id'] ?? 0);

        if (in_array($role, ['admin', 'superadmin'], true)) {
            $result = $this->db->query(
                'SELECT id FROM projekte WHERE deleted_at IS NULL ORDER BY id ASC'
            );
            while ($row = $result->fetch_assoc()) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            return array_values(array_unique($ids));
        }

        $stmt = $this->db->prepare(
            'SELECT DISTINCT p.id
             FROM projekte p
             INNER JOIN projekt_mitglieder pm ON pm.projekt_id = p.id
             WHERE pm.benutzer_id = ? AND p.deleted_at IS NULL'
        );
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $stmt->close();

        // Handwerker können zusätzlich über unternehmer_projekte einem Projekt zugewiesen sein.
        if ($businessType === 'handwerker') {
            try {
                $stmt = $this->db->prepare(
                    'SELECT DISTINCT projekt_id
                     FROM unternehmer_projekte
                     WHERE benutzer_id = ?'
                );
                $stmt->bind_param('i', $this->userId);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $id = (int) ($row['projekt_id'] ?? 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                $stmt->close();
            } catch (Throwable $e) {
                error_log('[gimi] unternehmer_projekte nicht verfügbar: ' . $e->getMessage());
            }
        }

        // Mieter werden auf das Projekt ihrer eigenen Wohnung begrenzt.
        if ($businessType === 'mieter' && $wohnungId > 0) {
            $stmt = $this->db->prepare(
                'SELECT DISTINCT o.projekt_id
                 FROM wohnungen w
                 INNER JOIN objekte o ON o.id = w.objekt_id
                 WHERE w.id = ?
                 LIMIT 1'
            );
            $stmt->bind_param('i', $wohnungId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $id = (int) ($row['projekt_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        return $ids;
    }

    /**
     * @param int[] $allowedProjectIds
     */
    private function projectAllowed(int $projectId, array $allowedProjectIds): bool
    {
        return $projectId > 0 && in_array($projectId, $allowedProjectIds, true);
    }

    /**
     * Sammelt nur Informationen, die der aktuelle Benutzer sehen darf.
     */
    public function getSystemContext(string $currentPageUrl = '', array $pageContext = []): string
    {
        $context = "Du bist 'gimi', der Schweizer PropTech KI-Copilot von pendenz.com.\n";
        $context .= "Du unterstützt bei Pendenzen, Liegenschaften, Mietverwaltung und Projektorganisation.\n";
        $context .= "Antworte auf Deutsch, professionell, freundlich und präzise. Verwende Schweizer Kontext und CHF.\n";
        $context .= 'Aktuelles Datum: ' . date('d.m.Y') . ".\n\n";

        $urlParams = [];
        $urlQuery = parse_url($currentPageUrl, PHP_URL_QUERY);
        if (is_string($urlQuery) && $urlQuery !== '') {
            parse_str($urlQuery, $urlParams);
        }

        $allowedProjectIds = $this->accessibleProjectIds();
        $projectIdSql = $allowedProjectIds !== []
            ? implode(',', array_map('intval', $allowedProjectIds))
            : '0';

        $activeProjectId = (int) ($pageContext['projekt_id'] ?? $urlParams['projekt_id'] ?? 0);
        if (!$this->projectAllowed($activeProjectId, $allowedProjectIds)) {
            $activeProjectId = 0;
        }

        $activeWohnungId = (int) ($pageContext['wohnung_id'] ?? $urlParams['wohnung_id'] ?? 0);
        $activePath = trim((string) ($pageContext['path'] ?? $urlParams['path'] ?? ''));

        if ($activeWohnungId > 0) {
            $stmt = $this->db->prepare(
                "SELECT o.projekt_id
                 FROM wohnungen w
                 INNER JOIN objekte o ON o.id = w.objekt_id
                 WHERE w.id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('i', $activeWohnungId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $wohnungProjectId = (int) ($row['projekt_id'] ?? 0);
            if (!$this->projectAllowed($wohnungProjectId, $allowedProjectIds)) {
                $activeWohnungId = 0;
            } elseif ($activeProjectId <= 0) {
                $activeProjectId = $wohnungProjectId;
            }
        }

        if ($activeProjectId > 0) {
            $stmt = $this->db->prepare(
                'SELECT id, name, storage_type
                 FROM projekte
                 WHERE id = ? AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->bind_param('i', $activeProjectId);
            $stmt->execute();
            $project = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($project) {
                $context .= ">>> AKTUELLES PROJEKT: #{$activeProjectId} - {$project['name']} <<<\n";
                $context .= '- Speicher: ' . ((string) ($project['storage_type'] ?? '') ?: 'Standard') . "\n";

                $stmt = $this->db->prepare(
                    "SELECT w.id AS w_id, w.name AS w_name, w.zimmer, w.flaeche,
                            wm.mieter_name, wm.mietzins_netto, wm.nk_akonto, wm.startdatum
                     FROM wohnungen w
                     INNER JOIN objekte o ON o.id = w.objekt_id
                     LEFT JOIN wohnung_mieter wm
                       ON wm.wohnung_id = w.id AND wm.status = 'aktiv'
                     WHERE o.projekt_id = ?
                     ORDER BY w.name ASC"
                );
                $stmt->bind_param('i', $activeProjectId);
                $stmt->execute();
                $result = $stmt->get_result();
                $context .= "EINHEITEN & AKTIVE MIETER DIESER LIEGENSCHAFT:\n";
                while ($unit = $result->fetch_assoc()) {
                    $brutto = (float) ($unit['mietzins_netto'] ?? 0)
                        + (float) ($unit['nk_akonto'] ?? 0);
                    $unitName = (string) ($unit['w_name'] ?? 'Einheit');
                    $zimmer = (string) ($unit['zimmer'] ?? '');
                    $flaeche = (string) ($unit['flaeche'] ?? '');
                    if (!empty($unit['mieter_name'])) {
                        $context .= "- {$unitName} ({$zimmer} Zi, {$flaeche} m²): "
                            . (string) $unit['mieter_name']
                            . ' | CHF ' . number_format($brutto, 2, '.', "'")
                            . "/Mt. | seit " . (string) ($unit['startdatum'] ?? '') . "\n";
                    } else {
                        $context .= "- {$unitName} ({$zimmer} Zi, {$flaeche} m²): [FREI / LEERSTEHEND]\n";
                    }
                }
                $stmt->close();

                // Nur bereits indexierte Dateinamen aus dem berechtigten Projekt verwenden.
                // Absolute Server-/Drive-Pfade werden bewusst nie an das externe Modell gesendet.
                if ($activePath !== '' && !str_contains($activePath, '..')) {
                    $normalizedPath = trim(str_replace('\\', '/', $activePath), '/');
                    $context .= "\nAKTUELL GEÖFFNETER DATEIORDNER:\n";
                    $context .= "- Relativer Pfad: {$normalizedPath}\n";

                    try {
                        $stmt = $this->db->prepare(
                            'SELECT name, is_dir, size
                             FROM fs_nodes
                             WHERE project_id = ? AND parent_rel_path = ?
                             ORDER BY is_dir DESC, name ASC
                             LIMIT 35'
                        );
                        $stmt->bind_param('is', $activeProjectId, $normalizedPath);
                        $stmt->execute();
                        $files = $stmt->get_result();
                        while ($file = $files->fetch_assoc()) {
                            $type = !empty($file['is_dir']) ? '[ORDNER]' : '[DATEI]';
                            $context .= '  * ' . $type . ' ' . (string) $file['name'] . "\n";
                        }
                        $stmt->close();
                    } catch (Throwable $e) {
                        error_log('[gimi] fs_nodes Kontext nicht verfügbar: ' . $e->getMessage());
                    }
                }

                $stmt = $this->db->prepare(
                    "SELECT id, titel, wichtigkeit, enddatum, status
                     FROM pendenzen
                     WHERE projekt_id = ?
                       AND deleted_at IS NULL
                       AND (status IS NULL OR status NOT IN ('erledigt','archiviert'))
                     ORDER BY wichtigkeit DESC, id DESC
                     LIMIT 8"
                );
                $stmt->bind_param('i', $activeProjectId);
                $stmt->execute();
                $pendenzen = $stmt->get_result();
                if ($pendenzen->num_rows > 0) {
                    $context .= "\nOFFENE PENDENZEN DIESER LIEGENSCHAFT:\n";
                    while ($pendenz = $pendenzen->fetch_assoc()) {
                        $context .= '- #' . (int) $pendenz['id'] . ': '
                            . (string) $pendenz['titel']
                            . ' (Prio ' . (int) ($pendenz['wichtigkeit'] ?? 0)
                            . ', Fällig: ' . (string) ($pendenz['enddatum'] ?? '-') . ")\n";
                    }
                }
                $stmt->close();
                $context .= "\n";
            }
        }

        if ($activeWohnungId > 0) {
            $stmt = $this->db->prepare(
                "SELECT w.id, w.name, w.zimmer, w.flaeche,
                        p.id AS p_id, p.name AS p_name,
                        wm.mieter_name, wm.mietzins_netto, wm.nk_akonto, wm.startdatum
                 FROM wohnungen w
                 INNER JOIN objekte o ON o.id = w.objekt_id
                 INNER JOIN projekte p ON p.id = o.projekt_id
                 LEFT JOIN wohnung_mieter wm
                   ON wm.wohnung_id = w.id AND wm.status = 'aktiv'
                 WHERE w.id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('i', $activeWohnungId);
            $stmt->execute();
            $wohnung = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($wohnung && $this->projectAllowed((int) $wohnung['p_id'], $allowedProjectIds)) {
                $context .= ">>> AKTUELLE WOHNUNG: {$wohnung['name']} ({$wohnung['p_name']}) <<<\n";
                $context .= '- Typ: ' . (string) ($wohnung['zimmer'] ?? '')
                    . ' Zimmer, Fläche: ' . (string) ($wohnung['flaeche'] ?? '') . " m²\n";
                if (!empty($wohnung['mieter_name'])) {
                    $context .= '- Aktiver Mieter: ' . (string) $wohnung['mieter_name']
                        . ' | Netto CHF ' . (string) ($wohnung['mietzins_netto'] ?? 0)
                        . ' + NK CHF ' . (string) ($wohnung['nk_akonto'] ?? 0)
                        . ' | seit ' . (string) ($wohnung['startdatum'] ?? '') . "\n\n";
                } else {
                    $context .= "- Mieter-Status: FREI / LEERSTAND\n\n";
                }
            }
        }

        if ($allowedProjectIds !== []) {
            $pCount = count($allowedProjectIds);
            $wCount = 0;
            $mCount = 0;
            $sollNetto = 0.0;
            $sollNk = 0.0;
            $pendCount = 0;

            $result = $this->db->query(
                "SELECT COUNT(*)
                 FROM wohnungen w
                 INNER JOIN objekte o ON o.id = w.objekt_id
                 WHERE o.projekt_id IN ({$projectIdSql})"
            );
            if ($result) {
                $wCount = (int) $result->fetch_row()[0];
            }

            $result = $this->db->query(
                "SELECT COUNT(*), COALESCE(SUM(wm.mietzins_netto), 0), COALESCE(SUM(wm.nk_akonto), 0)
                 FROM wohnung_mieter wm
                 INNER JOIN wohnungen w ON w.id = wm.wohnung_id
                 INNER JOIN objekte o ON o.id = w.objekt_id
                 WHERE wm.status = 'aktiv' AND o.projekt_id IN ({$projectIdSql})"
            );
            if ($result) {
                $row = $result->fetch_row();
                $mCount = (int) ($row[0] ?? 0);
                $sollNetto = (float) ($row[1] ?? 0);
                $sollNk = (float) ($row[2] ?? 0);
            }

            $result = $this->db->query(
                "SELECT COUNT(*)
                 FROM pendenzen
                 WHERE projekt_id IN ({$projectIdSql})
                   AND deleted_at IS NULL
                   AND (status IS NULL OR status NOT IN ('erledigt','archiviert'))"
            );
            if ($result) {
                $pendCount = (int) $result->fetch_row()[0];
            }

            $brutto = $sollNetto + $sollNk;
            $leerstand = max(0, $wCount - $mCount);

            $context .= "BERECHTIGTE PORTFOLIO-ÜBERSICHT:\n";
            $context .= "- Liegenschaften: {$pCount}\n";
            $context .= "- Einheiten: {$wCount} ({$mCount} vermietet, {$leerstand} rechnerisch frei)\n";
            $context .= '- Monatlicher Soll-Mietertrag: CHF '
                . number_format($brutto, 2, '.', "'") . "\n";
            $context .= '- Offene Pendenzen: ' . $pendCount . "\n\n";

            $context .= "BERECHTIGTE LIEGENSCHAFTEN:\n";
            $result = $this->db->query(
                "SELECT p.id, p.name, COUNT(DISTINCT w.id) AS units
                 FROM projekte p
                 LEFT JOIN objekte o ON o.projekt_id = p.id
                 LEFT JOIN wohnungen w ON w.objekt_id = o.id
                 WHERE p.id IN ({$projectIdSql})
                 GROUP BY p.id, p.name
                 ORDER BY p.name ASC"
            );
            while ($project = $result->fetch_assoc()) {
                $context .= '- ID ' . (int) $project['id'] . ': '
                    . (string) $project['name']
                    . ' (' . (int) $project['units'] . " Einheiten)\n";
            }
            $context .= "\n";
        } else {
            $context .= "Für diesen Benutzer ist aktuell keine Liegenschaft im KI-Kontext freigegeben.\n\n";
        }

        $context .= "SYSTEM-MODULE & DIREKT-LINKS:\n";
        $context .= "- Mieterspiegel: pages/mieterspiegel.php?projekt_id={id}\n";
        $context .= "- Liegenschaftsabrechnung: tools/liegenschaftsabrechnung/index.php?projekt_id={id}\n";
        $context .= "- Dateien: pages/files.php?projekt_id={id}\n";
        $context .= "- Pendenzen: pages/pendenzen.php?projekt_id={id}\n";
        $context .= "- Wohnungsabnahme: pages/wohnungsabnahme_protokoll.php?wohnung_id={id}\n";
        $context .= "- Mietvertrag: pages/vertrag_gen.php?wohnung_id={id}\n\n";

        $context .= "AUFGABEN-ERKENNUNG:\n";
        if ($activeProjectId > 0) {
            $context .= "Wenn der Benutzer klar eine neue Aufgabe, Reparatur, einen Mangel, eine Besichtigung oder ein To-Do erfassen will, hänge als letzte Zeile genau einen Aktions-Tag an:\n";
            $context .= "[ACTION:CREATE_PENDENZ|title=Prägnanter Titel|project_id={$activeProjectId}|wohnung_id={$activeWohnungId}|due=YYYY-MM-DD|priority=3]\n";
            $context .= "priority: 5=sehr dringend, 3=normal, 1=niedrig. Verwende keine andere project_id als {$activeProjectId}.\n\n";
        } else {
            $context .= "Es ist keine Liegenschaft aktiv. Erzeuge KEINEN ACTION:CREATE_PENDENZ-Tag, sondern bitte den Benutzer, zuerst die gewünschte Liegenschaft zu öffnen oder anzugeben.\n\n";
        }

        // Globales Systemwissen plus Wissen des aktuellen Benutzers.
        if ($this->userId > 0) {
            $stmt = $this->db->prepare(
                'SELECT title, content, scope_url
                 FROM ai_training
                 WHERE is_active = 1 AND (user_id IS NULL OR user_id = ?)'
            );
            $stmt->bind_param('i', $this->userId);
        } else {
            $stmt = $this->db->prepare(
                'SELECT title, content, scope_url
                 FROM ai_training
                 WHERE is_active = 1 AND user_id IS NULL'
            );
        }

        $stmt->execute();
        $training = $stmt->get_result();
        if ($training->num_rows > 0) {
            $context .= "INDIVIDUELLES SYSTEM-WISSEN:\n";
            while ($row = $training->fetch_assoc()) {
                $scope = trim((string) ($row['scope_url'] ?? ''));
                if ($scope === '' || $scope === '*' || ($currentPageUrl !== '' && str_contains($currentPageUrl, $scope))) {
                    $context .= '- ' . (string) $row['title'] . ': ' . (string) $row['content'] . "\n";
                }
            }
        }
        $stmt->close();

        return $context;
    }

    /**
     * Sendet eine Anfrage an Gemini mit System-Instruktion und Chat-Historie.
     */
    public function queryGemini(string $prompt, array $pageContext = [], array $history = []): string
    {
        if ($this->apiKey === '') {
            return $this->getMockResponse($prompt, $pageContext);
        }

        $models = [
            'gemini-3.1-flash-lite',
            'gemini-flash-latest',
            'gemini-3.6-flash',
            'gemini-3.5-flash',
            'gemini-3-flash-preview'
        ];

        $currentPage = (string) ($pageContext['url'] ?? '');
        $systemContext = $this->getSystemContext($currentPage, $pageContext);

        $contents = [];
        foreach (array_slice($history, -6) as $message) {
            $contents[] = [
                'role' => (($message['role'] ?? '') === 'user' ? 'user' : 'model'),
                'parts' => [['text' => (string) ($message['content'] ?? '')]]
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $prompt]]
        ];

        $postData = [
            'system_instruction' => [
                'parts' => [['text' => $systemContext]]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 4096,
                'topP' => 0.95
            ]
        ];

        $jsonPayload = json_encode(
            $postData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($jsonPayload)) {
            throw new RuntimeException('Gimi-Anfrage konnte nicht serialisiert werden.');
        }

        $lastResponse = '';
        $lastHttpCode = 0;
        $lastCurlError = '';

        foreach ($models as $model) {
            $url = sprintf(
                'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
                rawurlencode($model),
                rawurlencode($this->apiKey)
            );

            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
            ]);

            $response = curl_exec($ch);
            $lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastCurlError = curl_error($ch);
            curl_close($ch);

            $lastResponse = is_string($response) ? $response : '';
            if ($lastHttpCode === 200 && $lastResponse !== '') {
                break;
            }

            if ($lastCurlError !== '') {
                error_log('[gimi] Gemini transport error: ' . $lastCurlError);
            }
        }

        if ($lastHttpCode !== 200) {
            if (in_array($lastHttpCode, [0, 429, 503], true)) {
                return $this->getMockResponse($prompt, $pageContext, 'quota');
            }

            error_log('[gimi] Gemini HTTP error: ' . $lastHttpCode);
            throw new RuntimeException('Gimi konnte den KI-Dienst momentan nicht erreichen.');
        }

        $data = json_decode($lastResponse, true);
        $answer = trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));

        return $answer !== '' ? $answer : 'Keine Antwort von gimi.';
    }

    private function getMockResponse(string $prompt, array $pageContext, string $reason = 'no_key'): string
    {
        $title = (string) ($pageContext['title'] ?? 'dieser Seite');
        $promptLower = mb_strtolower($prompt, 'UTF-8');
        $knowledge = [];

        if ($this->userId > 0) {
            $stmt = $this->db->prepare(
                'SELECT title, content
                 FROM ai_training
                 WHERE is_active = 1 AND (user_id IS NULL OR user_id = ?)'
            );
            $stmt->bind_param('i', $this->userId);
        } else {
            $stmt = $this->db->prepare(
                'SELECT title, content
                 FROM ai_training
                 WHERE is_active = 1 AND user_id IS NULL'
            );
        }

        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $keywords = preg_split('/\s+/u', mb_strtolower((string) $row['title'], 'UTF-8')) ?: [];
            foreach ($keywords as $keyword) {
                if (mb_strlen($keyword, 'UTF-8') > 3 && mb_strpos($promptLower, $keyword) !== false) {
                    $knowledge[] = '💡 **' . (string) $row['title'] . '**: ' . (string) $row['content'];
                    break;
                }
            }
        }
        $stmt->close();

        $status = $reason === 'quota'
            ? '⚠️ **Hinweis:** Der Cloud-KI-Dienst ist momentan nicht verfügbar. Ich antworte im lokalen Modus.'
            : '👋 Hallo! Aktuell ist kein Cloud-KI-Schlüssel aktiv. Ich antworte im lokalen Modus.';

        $response = $status . "\n\n";
        if ($knowledge !== []) {
            $response .= "Passende lokale Informationen:\n\n";
            $response .= implode("\n\n", array_slice($knowledge, 0, 3));
            return $response;
        }

        $response .= "Du befindest dich auf **{$title}**. Im lokalen Modus kann ich einfache Hinweise zur aktuellen Seite und gespeichertes Wissen verwenden.";
        return $response;
    }
}
