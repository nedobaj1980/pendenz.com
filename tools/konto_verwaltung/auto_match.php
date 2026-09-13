<?php
// tools/konto_verwaltung/auto_match.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

function run_auto_match(mysqli $mysqli, int $pid = 0): array {
    // 1. Alle Mieter und Einheiten laden
    $sqlTenants = "
        SELECT wm.id as wm_id, wm.benutzer_id, wm.kontakt_id, wm.wohnung_id, wm.mieter_name, wm.mietzins_netto, wm.nk_akonto,
               w.name as wohnung_name, o.id as objekt_id, o.projekt_id,
               b.name as benutzer_name,
               k.vorname, k.nachname
        FROM wohnung_mieter wm
        JOIN wohnungen w ON wm.wohnung_id = w.id
        JOIN objekte o ON w.objekt_id = o.id
        LEFT JOIN benutzer b ON wm.benutzer_id = b.id
        LEFT JOIN kontakte k ON wm.kontakt_id = k.id
    ";
    if ($pid > 0) {
        $sqlTenants .= " WHERE o.projekt_id = $pid";
    }
    $resT = $mysqli->query($sqlTenants);
    $tenants = [];
    if ($resT) {
        while ($t = $resT->fetch_assoc()) {
            // Sammle alle Namensvarianten
            $names = [];
            if (!empty($t['mieter_name'])) $names[] = $t['mieter_name'];
            if (!empty($t['benutzer_name'])) $names[] = $t['benutzer_name'];
            $kName = trim(($t['vorname'] ?? '') . ' ' . ($t['nachname'] ?? ''));
            if ($kName !== '') $names[] = $kName;
            $t['search_names'] = array_unique($names);
            $tenants[] = $t;
        }
    }

    // 2. Alle Wohnungen laden
    $sqlUnits = "
        SELECT w.id as wohnung_id, w.name as wohnung_name, o.projekt_id
        FROM wohnungen w
        JOIN objekte o ON w.objekt_id = o.id
    ";
    if ($pid > 0) {
        $sqlUnits .= " WHERE o.projekt_id = $pid";
    }
    $resU = $mysqli->query($sqlUnits);
    $units = [];
    if ($resU) {
        while ($u = $resU->fetch_assoc()) {
            $units[] = $u;
        }
    }

    // 3. Noch unzugeordnete Buchungen holen (sowohl Gutschriften als auch Buchungen ohne wohnung_id)
    $sqlK = "SELECT id, liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, wohnung_id, mieter_id 
             FROM liegenschafts_konto 
             WHERE wohnung_id IS NULL OR wohnung_id = 0";
    if ($pid > 0) {
        $sqlK .= " AND (liegenschaft_id = $pid OR projekt_id = $pid)";
    }
    $resK = $mysqli->query($sqlK);

    $matchedCount = 0;
    $matches = [];

    $updateStmt = $mysqli->prepare("
        UPDATE liegenschafts_konto 
        SET mieter_id = ?, wohnung_id = ?, wohnung_label = ?, kategorie = IF(kategorie IS NULL OR kategorie='', 'Miete', kategorie)
        WHERE id = ?
    ");

    if ($resK) {
        while ($row = $resK->fetch_assoc()) {
            $desc = (string)$row['beschreibung'];
            $kid = (int)$row['id'];
            $bestMatch = null;

            // 1. Zuerst Mieter-Namen abgleichen
            foreach ($tenants as $t) {
                foreach ($t['search_names'] as $sName) {
                    $sName = trim($sName);
                    if ($sName === '') continue;

                    // Ganzer Name direkt enthalten?
                    if (stripos($desc, $sName) !== false) {
                        $bestMatch = $t;
                        break 2;
                    }

                    // Einzelne Wörter prüfen (z. B. Nachname >= 3 Zeichen)
                    $words = preg_split('/[\s,\/&.\-]+/', $sName);
                    foreach ($words as $w) {
                        $w = trim($w);
                        // Stoppwörter ignorieren
                        if (in_array(mb_strtolower($w), ['und', 'der', 'die', 'das', 'von', 'den', 'vom', 'mit'])) continue;
                        if (mb_strlen($w) >= 4 && stripos($desc, $w) !== false) {
                            $bestMatch = $t;
                            break 3;
                        }
                    }
                }
            }

            // 2. Fallback: Wohnungsname abgleichen
            if (!$bestMatch) {
                foreach ($units as $u) {
                    $wName = trim((string)$u['wohnung_name']);
                    if ($wName === '' || mb_strlen($wName) < 3) continue;

                    if (stripos($desc, $wName) !== false) {
                        $bestMatch = [
                            'benutzer_id' => null,
                            'wohnung_id' => $u['wohnung_id'],
                            'wohnung_name' => $u['wohnung_name'],
                            'search_names' => []
                        ];
                        break;
                    }
                }
            }

            if ($bestMatch) {
                $mId = !empty($bestMatch['benutzer_id']) ? (int)$bestMatch['benutzer_id'] : null;
                if ($mId > 0) {
                    // Validieren gegen benutzer table wegen foreign key
                    $chk = $mysqli->query("SELECT id FROM benutzer WHERE id = $mId");
                    if (!$chk || $chk->num_rows === 0) $mId = null;
                }

                $wId = !empty($bestMatch['wohnung_id']) ? (int)$bestMatch['wohnung_id'] : null;
                $wLbl = $bestMatch['wohnung_name'] ?? '';

                $updateStmt->bind_param("iisi", $mId, $wId, $wLbl, $kid);
                $updateStmt->execute();
                $matchedCount++;
                $matches[] = [
                    'konto_id' => $kid,
                    'datum' => $row['buchungsdatum'],
                    'betrag' => $row['betrag'],
                    'text' => $desc,
                    'mieter' => !empty($bestMatch['search_names']) ? implode(', ', $bestMatch['search_names']) : 'Wohnungs-Match',
                    'wohnung' => $wLbl
                ];
            }
        }
    }
    $updateStmt->close();

    return [
        'success' => true,
        'total_checked' => $resK ? $resK->num_rows : 0,
        'matched_count' => $matchedCount,
        'matches' => $matches
    ];
}

// Direkter HTTP- oder CLI-Aufruf
if (!debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)) {
    if (php_sapi_name() !== 'cli') {
        require_login();
    }
    header('Content-Type: application/json; charset=utf-8');
    $pid = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_POST['projekt_id'] ?? 0);
    $res = run_auto_match($mysqli, $pid);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
