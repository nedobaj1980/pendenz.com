<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/pendenz_domain.php';

// Normal users should be able to edit their own tasks or tasks in their projects
// The can_edit_pendenz check will be done inside

api_try(function () {
    $db = db();
    $in = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $id    = (int)($in['id'] ?? 0);
    $field = $in['field'] ?? '';
    $value = $in['value'] ?? '';
    $updates = $in['updates'] ?? []; // NEU: Unterstützung für mehrere Felder

    if (!$id || (!$field && empty($updates))) {
        json_response(['ok' => false, 'error' => 'Missing params'], 400);
    }

    // Permission check
    if (!is_logged_in()) {
        json_response(['ok' => false, 'error' => 'UNAUTHENTICATED'], 401);
    }

    $res = $db->query("SELECT * FROM pendenzen WHERE id=$id");
    $pendenz = $res ? $res->fetch_assoc() : null;
    
    if (!$pendenz) {
        json_response(['ok' => false, 'error' => 'Task not found'], 404);
    }

    $userId = (int)current_user_id();
    if (!is_admin() && !can_edit_pendenz($db, $pendenz, $userId)) {
        json_response(['ok' => false, 'error' => 'FORBIDDEN', 'message' => 'Keine Bearbeitungsberechtigung für diese Pendenz'], 403);
    }
    
    // Collect fields to update
    $toUpdate = $updates ?: [$field => $value];
    $db_updates = []; $extra_updates = [];

    $mapping = [
        'prio' => 'wichtigkeit',
        'beschreibung' => 'langbeschreibung',
        'content' => 'langbeschreibung'
    ];

    $db_fields = [
        'titel', 'kurzbeschreibung', 'langbeschreibung', 'notiz', 
        'startdatum', 'enddatum', 'uhrzeit', 'tageszeit', 
        'status', 'wichtigkeit', 'zustaendig_id', 'projekt_id',
        'objekt_id', 'wohnung_id', 'ordner_id', 'fs_rel_path', 'vorgaenger_id', 'dauer',
        'is_protocol', 'protocol_type', 'vorgangsart_id', 'raum_id'
    ];

    $extra_fields = [
        'kategorie_id', 'subkategorie_id', 'unterkategorie_id',
        'confirmation_required', 'public_enabled', 'external_can_view', 'external_can_upload'
    ];

    foreach ($toUpdate as $f => $v) {
        if (isset($mapping[$f])) $f = $mapping[$f];
        if (in_array($f, $db_fields)) {
            // Sanitize dates
            if (strpos($f, 'datum') !== false && !empty($v)) {
                $ts = strtotime($v);
                if ($ts !== false) $v = date('Y-m-d', $ts);
            }
            $db_updates[$f] = $v;
        } elseif (in_array($f, $extra_fields)) {
            $extra_updates[$f] = $v;
        }
    }

    if (isset($db_updates['status'])) $db_updates['status'] = pendenz_normalize_status((string)$db_updates['status']);

    // Enforce the project → object → apartment → room hierarchy on every inline edit.
    $candidateProject = array_key_exists('projekt_id', $db_updates) ? (int)$db_updates['projekt_id'] : (int)($pendenz['projekt_id'] ?? 0);
    $candidateObject  = array_key_exists('objekt_id', $db_updates) ? (int)$db_updates['objekt_id'] : (int)($pendenz['objekt_id'] ?? 0);
    $candidateUnit    = array_key_exists('wohnung_id', $db_updates) ? (int)$db_updates['wohnung_id'] : (int)($pendenz['wohnung_id'] ?? 0);
    $candidateRoom    = array_key_exists('raum_id', $db_updates) ? (int)$db_updates['raum_id'] : (int)($pendenz['raum_id'] ?? 0);
    if ($candidateObject || $candidateUnit || $candidateRoom) {
        $loc = ['projekt_id'=>$candidateProject, 'objekt_id'=>$candidateObject, 'wohnung_id'=>$candidateUnit, 'raum_id'=>$candidateRoom,
                'objekt_projekt_id'=>null, 'wohnung_objekt_id'=>null, 'raum_wohnung_id'=>null];
        if ($candidateObject) { $r=$db->query('SELECT projekt_id FROM objekte WHERE id='.$candidateObject)->fetch_assoc(); $loc['objekt_projekt_id']=$r['projekt_id']??null; }
        if ($candidateUnit) { $r=$db->query('SELECT objekt_id FROM wohnungen WHERE id='.$candidateUnit)->fetch_assoc(); $loc['wohnung_objekt_id']=$r['objekt_id']??null; }
        if ($candidateRoom) { $r=$db->query('SELECT wohnung_id FROM raeume WHERE id='.$candidateRoom)->fetch_assoc(); $loc['raum_wohnung_id']=$r['wohnung_id']??null; }
        if (!pendenz_location_is_consistent($loc)) json_response(['ok'=>false,'error'=>'INVALID_LOCATION','message'=>'Projekt, Objekt, Wohnung und Raum müssen zusammengehören'], 422);
    }

    // --- MS PROJECT AUTO-RECALCULATION ENGINE ---
    if (isset($db_updates['dauer']) || isset($db_updates['vorgaenger_id']) || isset($db_updates['startdatum']) || isset($db_updates['enddatum'])) {
        $c_dauer = isset($db_updates['dauer']) ? $db_updates['dauer'] : ($pendenz['dauer'] ?? '');
        $c_vorg  = isset($db_updates['vorgaenger_id']) ? $db_updates['vorgaenger_id'] : ($pendenz['vorgaenger_id'] ?? '');
        $c_start = isset($db_updates['startdatum']) ? $db_updates['startdatum'] : ($pendenz['startdatum'] ?? null);
        $c_end   = isset($db_updates['enddatum']) ? $db_updates['enddatum'] : ($pendenz['enddatum'] ?? null);

        // 0. Auto-Reverse Formula Calculation: If user dragged dates manually, update the offset in their Vorgänger string!
        if (!isset($db_updates['vorgaenger_id']) && (isset($db_updates['startdatum']) || isset($db_updates['enddatum'])) && !empty($c_vorg)) {
            $v_chunks = array_filter(explode(',', strtolower(str_replace(' ', '', $c_vorg))));
            $new_vorg_parts = [];
            
            foreach ($v_chunks as $chunk) {
                if (preg_match('/^([0-9]+)(ea|ee|aa|ae)?([+-]?[0-9]+)?/', $chunk, $m)) {
                    $v_id = (int)$m[1];
                    $v_type = !empty($m[2]) ? $m[2] : 'ea';
                    
                    $v_res = $db->query("SELECT startdatum, enddatum FROM pendenzen WHERE id = $v_id");
                    if ($v_res && ($v_row = $v_res->fetch_assoc())) {
                        $p_s = $v_row['startdatum'] ?: date('Y-m-d');
                        $p_e = $v_row['enddatum'] ?: $p_s;
                        
                        $base_date = ($v_type === 'ea' || $v_type === 'ee') ? $p_e : $p_s;
                        $target_date = ($v_type === 'ea' || $v_type === 'aa') ? $c_start : $c_end;
                        
                        if ($base_date && $base_date !== '0000-00-00' && $target_date && $target_date !== '0000-00-00') {
                            $diff_days = round((strtotime($target_date) - strtotime($base_date)) / 86400);
                            $type_str = !empty($m[2]) ? strtolower($m[2]) : 'ea';
                            
                            $suffix = '';
                            if ($diff_days != 0) {
                                $suffix = ($diff_days > 0 ? '+' : '') . $diff_days;
                            }
                            
                            $new_vorg_parts[] = $v_id . $type_str . $suffix;
                        } else {
                            $new_vorg_parts[] = strtolower($chunk);
                        }
                    } else {
                        $new_vorg_parts[] = strtoupper($chunk);
                    }
                } else {
                    $new_vorg_parts[] = strtoupper($chunk);
                }
            }
            
            if (!empty($new_vorg_parts)) {
                $c_vorg = implode(', ', $new_vorg_parts);
                $db_updates['vorgaenger_id'] = $c_vorg;
            }
        }

        // 1. Process Vorgänger (only if explicitly edited)
        if (isset($db_updates['vorgaenger_id']) && !empty($c_vorg)) {
            $v_chunks = array_filter(explode(',', strtolower(str_replace(' ', '', $c_vorg))));
            
            $d_val = 0;
            if (!empty($c_dauer)) {
                $d_val = (int)preg_replace('/[^0-9]/', '', $c_dauer);
            }
            
            $formatted_vorg_parts = [];
            
            foreach ($v_chunks as $chunk) {
                // Parse e.g. "15", "15ea", "15ea-2", "15aa+3", "15ee", "13ea-1tag", "11ea2"
                if (preg_match('/^([0-9]+)(ea|ee|aa|ae)?([+-]?[0-9]+)?/', $chunk, $m)) {
                    $v_id = (int)$m[1];
                    $v_type = !empty($m[2]) ? $m[2] : 'ea';
                    $v_offset = !empty($m[3]) ? (int)$m[3] : 0;
                    
                    $p_res = $db->query("SELECT startdatum, enddatum FROM pendenzen WHERE id = $v_id");
                    if ($p_res && ($p_row = $p_res->fetch_assoc())) {
                        $p_s = $p_row['startdatum'] ?: date('Y-m-d');
                        $p_e = $p_row['enddatum'] ?: $p_s;
                        
                        $base_date = ($v_type === 'ea' || $v_type === 'ee') ? $p_e : $p_s;
                        if ($base_date && $base_date !== '0000-00-00' && $base_date !== '0000-00-00 00:00:00') {
                            $computed = date('Y-m-d', strtotime($base_date . ($v_offset >= 0 ? " +$v_offset" : " $v_offset") . " days"));
                            if ($v_type === 'ea' || $v_type === 'aa') {
                                $c_start = $computed;
                                $db_updates['startdatum'] = $c_start;
                                if ($d_val > 0) {
                                    $c_end = date('Y-m-d', strtotime($c_start . " + {$d_val} days"));
                                    $db_updates['enddatum'] = $c_end;
                                }
                            } else {
                                $c_end = $computed;
                                $db_updates['enddatum'] = $c_end;
                                if ($d_val > 0) {
                                    $c_start = date('Y-m-d', strtotime($c_end . " - {$d_val} days"));
                                    $db_updates['startdatum'] = $c_start;
                                }
                            }
                        }
                    }
                    
                    $type_str = !empty($m[2]) ? strtolower($m[2]) : 'ea';
                    $suffix = '';
                    if ($v_offset != 0) {
                        $suffix = ($v_offset > 0 ? '+' : '') . $v_offset;
                    }
                    $formatted_vorg_parts[] = $v_id . $type_str . $suffix;
                    
                    break;
                }
            }
            
            if (!empty($formatted_vorg_parts)) {
                $db_updates['vorgaenger_id'] = implode(', ', $formatted_vorg_parts);
            }
        }
        
        $d_val = 0;
        if (!empty($c_dauer)) {
            $d_val = (int)preg_replace('/[^0-9]/', '', $c_dauer);
        }
        
        // Backward Calculation
        if (empty($c_start) && !empty($c_end) && $d_val > 0) {
            $c_start = date('Y-m-d', strtotime($c_end . " - {$d_val} days"));
            $db_updates['startdatum'] = $c_start;
        }

        // Forward Calculation
        if ($d_val > 0 && (empty($c_end) || isset($db_updates['startdatum']) || isset($db_updates['dauer']) || isset($db_updates['vorgaenger_id']))) {
            if ($c_start) {
                // Determine precision start point
                $c_end = date('Y-m-d', strtotime($c_start . " + {$d_val} days"));
                $db_updates['enddatum'] = $c_end;
            }
        }
    }

    if (!empty($db_updates)) {
        $set = []; $vars = []; $types = '';
        foreach ($db_updates as $f => $v) { $set[]="`$f`=?"; $vars[]=$v; $types.='s'; }
        $sql = "UPDATE pendenzen SET ".implode(', ', $set).", geaendert_am=NOW() WHERE id=?";
        $vars[]=$id; $types.='i';
        $st = $db->prepare($sql); $st->bind_param($types, ...$vars); $st->execute(); $st->close();
        
        // --- THE DOMINO EFFECT (CASCADING WATERFALL ENGINE) ---
        // Wenn sich das Datum geändert hat, müssen alle Sklaven (Nachfolger) automatisch mitverschoben werden!
        if (isset($db_updates['startdatum']) || isset($db_updates['enddatum']) || isset($db_updates['dauer'])) {
            $cascade_updates = function($db, $parent_id, &$visited = []) use (&$cascade_updates) {
                if (in_array($parent_id, $visited)) return; // Prevent circular dependency infinite loops
                $visited[] = $parent_id;
                
                $res = $db->query("SELECT id, startdatum, enddatum, dauer, vorgaenger_id FROM pendenzen WHERE vorgaenger_id LIKE '%{$parent_id}%'");
                if (!$res) return;
                
                while ($row = $res->fetch_assoc()) {
                    $c_id = $row['id'];
                    $c_vorg = $row['vorgaenger_id'];
                    $c_dauer = $row['dauer'];
                    $c_start = $row['startdatum'];
                    $c_end = $row['enddatum'];
                    
                    $d_val = 0;
                    if (!empty($c_dauer)) {
                        $d_val = (int)preg_replace('/[^0-9]/', '', $c_dauer);
                    }
                    
                    $updated = false;
                    $v_chunks = array_filter(explode(',', strtolower(str_replace(' ', '', $c_vorg))));
                    
                    foreach ($v_chunks as $chunk) {
                        if (preg_match('/^([0-9]+)(ea|ee|aa|ae)?([+-]?[0-9]+)?/', $chunk, $m)) {
                            $v_id = (int)$m[1];
                            if ($v_id !== $parent_id) continue;
                            
                            $v_type = !empty($m[2]) ? $m[2] : 'ea';
                            $v_offset = !empty($m[3]) ? (int)$m[3] : 0;
                            
                            $p_res = $db->query("SELECT startdatum, enddatum FROM pendenzen WHERE id = $v_id");
                            if ($p_res && ($p_row = $p_res->fetch_assoc())) {
                                $p_s = $p_row['startdatum'] ?: date('Y-m-d');
                                $p_e = $p_row['enddatum'] ?: $p_s;
                                
                                $base_date = ($v_type === 'ea' || $v_type === 'ee') ? $p_e : $p_s;
                                if ($base_date && $base_date !== '0000-00-00' && $base_date !== '0000-00-00 00:00:00') {
                                    $computed = date('Y-m-d', strtotime($base_date . ($v_offset >= 0 ? " +$v_offset" : " $v_offset") . " days"));
                                    
                                    $new_start = $c_start;
                                    $new_end = $c_end;
                                    
                                    if ($v_type === 'ea' || $v_type === 'aa') {
                                        $new_start = $computed;
                                        if ($d_val > 0) $new_end = date('Y-m-d', strtotime($new_start . " + {$d_val} days"));
                                    } else {
                                        $new_end = $computed;
                                        if ($d_val > 0) $new_start = date('Y-m-d', strtotime($new_end . " - {$d_val} days"));
                                    }
                                    
                                    if ($new_start !== $c_start || $new_end !== $c_end) {
                                        $db->query("UPDATE pendenzen SET startdatum = '{$new_start}', enddatum = '{$new_end}', geaendert_am=NOW() WHERE id = {$c_id}");
                                        $updated = true;
                                    }
                                }
                            }
                            break; 
                        }
                    }
                    
                    // RECURSIVE DOMINO EFFECT
                    if ($updated) {
                        $cascade_updates($db, $c_id, $visited);
                    }
                }
            };
            
            $visited = [];
            $cascade_updates($db, $id, $visited);
        }
    }

    if (!empty($extra_updates)) {
        $extra = json_decode($pendenz['extra_json'] ?? '[]', true) ?: [];
        foreach ($extra_updates as $f => $v) $extra[$f] = $v;
        $jx = json_encode($extra, JSON_UNESCAPED_UNICODE);
        
        $st = $db->prepare("UPDATE pendenzen SET extra_json=?, geaendert_am=NOW() WHERE id=?");
        $st->bind_param("si", $jx, $id); $st->execute(); $st->close();
    }

    json_response(['ok' => true, 'updates' => $db_updates]);
});
