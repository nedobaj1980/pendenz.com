<?php
// tools/konto_verwaltung/auto_match.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';

function run_auto_match(mysqli $mysqli, int $pid = 0): array {
    // 0. Gelernte Regeln aus konto_rules laden
    $sqlRules = "SELECT id, pattern, is_regex, liegenschaft_id, wohnung_id, mieter_id, wohnung_label, set_kategorie, set_zahlungsart 
                 FROM konto_rules 
                 WHERE aktiv = 1";
    if ($pid > 0) {
        $sqlRules .= " AND (liegenschaft_id = $pid OR liegenschaft_id IS NULL OR liegenschaft_id = 0)";
    }
    $sqlRules .= " ORDER BY priority ASC, id DESC";
    $rulesRes = $mysqli->query($sqlRules);
    $rules = [];
    if ($rulesRes) {
        while ($r = $rulesRes->fetch_assoc()) {
            if (!empty(trim($r['pattern']))) {
                $rules[] = $r;
            }
        }
    }

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

    // 3. Unzugeordnete Buchungen laden
    $sqlK = "SELECT id, liegenschaft_id, projekt_id, buchungsdatum, betrag, beschreibung, wohnung_id, mieter_id, kategorie 
             FROM liegenschafts_konto 
             WHERE (wohnung_id IS NULL OR wohnung_id = 0 OR kategorie IS NULL OR kategorie = '')";
    if ($pid > 0) {
        $sqlK .= " AND (liegenschaft_id = $pid OR projekt_id = $pid)";
    }
    $resK = $mysqli->query($sqlK);

    $matchedCount = 0;
    $matches = [];

    $updateStmt = $mysqli->prepare("
        UPDATE liegenschafts_konto 
        SET mieter_id = ?, wohnung_id = ?, wohnung_label = ?, kategorie = ?
        WHERE id = ?
    ");

    if ($resK) {
        while ($row = $resK->fetch_assoc()) {
            $desc = (string)$row['beschreibung'];
            $kid = (int)$row['id'];
            $betrag = (float)$row['betrag'];
            $currKat = trim((string)$row['kategorie']);
            $bestMatch = null;
            $setCat = $currKat;

            // SCHRITT A: GELERNTEN REGELN (Dauer-Regeln für Mehrfachzahler & Kategorien)
            foreach ($rules as $rule) {
                $pat = trim($rule['pattern']);
                $matched = false;
                if (!empty($rule['is_regex'])) {
                    $matched = @preg_match('/' . str_replace('/', '\/', $pat) . '/i', $desc) === 1;
                } else {
                    $matched = (stripos($desc, $pat) !== false);
                }

                if ($matched) {
                    $bestMatch = [
                        'benutzer_id' => !empty($rule['mieter_id']) ? (int)$rule['mieter_id'] : null,
                        'wohnung_id' => !empty($rule['wohnung_id']) ? (int)$rule['wohnung_id'] : null,
                        'wohnung_name' => $rule['wohnung_label'] ?: '',
                        'search_names' => ['Regel: ' . $pat]
                    ];
                    if (!empty($rule['set_kategorie'])) {
                        $setCat = $rule['set_kategorie'];
                    }
                    break;
                }
            }

            // SCHRITT B: MIETER-NAMEN ABGLEICHEN (falls keine Regel getroffen)
            if (!$bestMatch && $betrag > 0) {
                foreach ($tenants as $t) {
                    foreach ($t['search_names'] as $sName) {
                        $sName = trim($sName);
                        if ($sName === '') continue;

                        if (stripos($desc, $sName) !== false) {
                            $bestMatch = $t;
                            if (empty($setCat)) $setCat = 'Miete';
                            break 2;
                        }

                        $words = preg_split('/[\s,\/&.\-]+/', $sName);
                        foreach ($words as $w) {
                            $w = trim($w);
                            if (in_array(mb_strtolower($w), ['und', 'der', 'die', 'das', 'von', 'den', 'vom', 'mit', 'inh'])) continue;
                            if (mb_strlen($w) >= 4 && stripos($desc, $w) !== false) {
                                $bestMatch = $t;
                                if (empty($setCat)) $setCat = 'Miete';
                                break 3;
                            }
                        }
                    }
                }
            }

            // SCHRITT C: WOHNUNGSNAME ABGLEICHEN
            if (!$bestMatch && $betrag > 0) {
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
                        if (empty($setCat)) $setCat = 'Miete';
                        break;
                    }
                }
            }

            // SCHRITT D: STANDARD-KATEGORISIERUNG FÜR AUSGABEN & SPEZIALFÄLLE
            if (empty($setCat)) {
                $lDesc = mb_strtolower($desc);
                if (str_contains($lDesc, 'kaution') || str_contains($lDesc, 'depot')) {
                    $setCat = 'Kaution';
                } elseif (str_contains($lDesc, 'rückzahlung') || str_contains($lDesc, 'rückvergütung') || str_contains($lDesc, 'doppelzahlung')) {
                    $setCat = 'Rückzahlung / Korrektur';
                } elseif (str_contains($lDesc, 'gebühr') || str_contains($lDesc, 'spesen') || str_contains($lDesc, 'abschluss') || str_contains($lDesc, 'hypothek') || str_contains($lDesc, 'zins')) {
                    $setCat = 'Hypothek / Bank';
                } elseif (str_contains($lDesc, 'steuer')) {
                    $setCat = 'Steuern';
                } elseif (str_contains($lDesc, 'versicherung') || str_contains($lDesc, 'allianz') || str_contains($lDesc, 'mobiliar') || str_contains($lDesc, 'axa') || str_contains($lDesc, 'helvetia') || str_contains($lDesc, 'suva')) {
                    $setCat = 'Versicherung';
                } elseif (str_contains($lDesc, 'ew ') || str_contains($lDesc, 'energie') || str_contains($lDesc, 'strom') || str_contains($lDesc, 'wasser') || str_contains($lDesc, 'abwasser') || str_contains($lDesc, 'kehricht') || str_contains($lDesc, 'kaminfeger')) {
                    $setCat = 'Nebenkosten';
                } elseif (str_contains($lDesc, 'pv') || str_contains($lDesc, 'solarmarkt') || str_contains($lDesc, 'photovoltaik')) {
                    $setCat = 'Investitionen';
                } elseif (str_contains($lDesc, 'auszahlung') || str_contains($lDesc, 'privat') || str_contains($lDesc, 'eigentümer') || (str_contains($lDesc, 'bajramoski') && $betrag < 0)) {
                    $setCat = 'Auszahlung Eigentümer';
                } elseif ($betrag < 0) {
                    $setCat = 'Unterhalt & Reparaturen';
                } elseif ($betrag > 0) {
                    $setCat = 'Miete';
                }
            }

            // Wenn wir einen Match oder eine Kategorie haben, schreiben!
            if ($bestMatch || ($setCat !== '' && $setCat !== $currKat)) {
                $mId = !empty($bestMatch['benutzer_id']) ? (int)$bestMatch['benutzer_id'] : (!empty($row['mieter_id']) ? (int)$row['mieter_id'] : null);
                if ($mId > 0) {
                    $chk = $mysqli->query("SELECT id FROM benutzer WHERE id = $mId");
                    if (!$chk || $chk->num_rows === 0) $mId = null;
                }

                $wId = !empty($bestMatch['wohnung_id']) ? (int)$bestMatch['wohnung_id'] : (!empty($row['wohnung_id']) ? (int)$row['wohnung_id'] : null);
                $wLbl = !empty($bestMatch['wohnung_name']) ? $bestMatch['wohnung_name'] : '';

                if (empty($setCat)) $setCat = ($betrag > 0 ? 'Miete' : 'Unterhalt & Reparaturen');

                $updateStmt->bind_param("iissi", $mId, $wId, $wLbl, $setCat, $kid);
                $updateStmt->execute();
                $matchedCount++;
                $matches[] = [
                    'konto_id' => $kid,
                    'datum' => $row['buchungsdatum'],
                    'betrag' => $row['betrag'],
                    'text' => $desc,
                    'kategorie' => $setCat,
                    'mieter' => !empty($bestMatch['search_names']) ? implode(', ', $bestMatch['search_names']) : '—',
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
