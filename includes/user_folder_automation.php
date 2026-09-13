<?php
/**
 * user_folder_automation.php
 * Logic for automatic user folder creation and apartment-based migration.
 */

require_once __DIR__ . '/fs.php';

/**
 * Creates or syncs a user's physical folder based on their current status.
 */
function user_folder_sync(mysqli $db, int $userId): array {
    try {
        // 1. Get User Data
        $res = $db->query("SELECT id, name, wohnung_id, folder_path FROM benutzer WHERE id = $userId");
        $user = $res->fetch_assoc();
        if (!$user) return ['ok' => false, 'msg' => 'Benutzer nicht gefunden.'];

        $uName = trim($user['name']);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $uName);
        $folderName = $userId . "_" . $safeName;

        // Base directory for unlinked users
        $baseRel = "storage/files/00_Mieter_Interessenten";
        $baseAbs = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $baseRel);
        if (!is_dir($baseAbs)) @mkdir($baseAbs, 0777, true);

        $currentPath = $user['folder_path'];
        $wohnungId = (int)$user['wohnung_id'];

        // 2. Determine Target Path
        $targetRel = "";
        if ($wohnungId > 0) {
            // Linked to an apartment
            $resW = $db->query("
                SELECT w.name as w_name, o.projekt_id, o.name as o_name 
                FROM wohnungen w 
                JOIN objekte o ON o.id = w.objekt_id 
                WHERE w.id = $wohnungId
            ");
            $w = $resW->fetch_assoc();
            if ($w) {
                $pId = (int)$w['projekt_id'];
                $rootPath = project_root_path($db, $pId);
                if ($rootPath) {
                    // Typical path: [ProjectRoot]/Wohnungen/[Wohnung]/01_Mieter/[UserFolder]
                    $aptRel = "Wohnungen/" . $w['w_name'] . "/01_Mieter";
                    $targetAbsBase = fs_abs_from_rel($rootPath, $aptRel);
                    if ($targetAbsBase) {
                        if (!is_dir($targetAbsBase)) @mkdir($targetAbsBase, 0777, true);
                        $targetRel = $aptRel . "/" . $folderName;
                        $targetAbs = $targetAbsBase . DIRECTORY_SEPARATOR . $folderName;
                    }
                }
            }
        }

        if ($targetRel === "") {
            // Unlinked or fallback
            $targetRel = $baseRel . "/" . $folderName;
            $targetAbs = $baseAbs . DIRECTORY_SEPARATOR . $folderName;
        }

        // 3. Move or Create
        $root = realpath(__DIR__ . '/..');
        $oldAbs = $currentPath ? $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $currentPath) : null;
        
        if ($oldAbs && is_dir($oldAbs) && $oldAbs !== $targetAbs) {
            // MOVE content
            @mkdir(dirname($targetAbs), 0777, true);
            if (@rename($oldAbs, $targetAbs)) {
                $db->query("UPDATE benutzer SET folder_path = '" . $db->real_escape_string($targetRel) . "' WHERE id = $userId");
                return ['ok' => true, 'msg' => "Ordner verschoben nach $targetRel"];
            } else {
                return ['ok' => false, 'msg' => "Fehler beim Verschieben des Ordners von $oldAbs nach $targetAbs"];
            }
        } elseif (!is_dir($targetAbs)) {
            // CREATE fresh
            @mkdir($targetAbs, 0777, true);
            $db->query("UPDATE benutzer SET folder_path = '" . $db->real_escape_string($targetRel) . "' WHERE id = $userId");
            return ['ok' => true, 'msg' => "Ordner neu erstellt in $targetRel"];
        } else {
            // Already there
            if ($currentPath !== $targetRel) {
                $db->query("UPDATE benutzer SET folder_path = '" . $db->real_escape_string($targetRel) . "' WHERE id = $userId");
            }
            return ['ok' => true, 'msg' => "Ordner bereits vorhanden."];
        }

    } catch (Exception $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Handles Vormieter logic: Moves the OLD tenant folder to 99_Vormieter 
 * when a new one takes their place.
 */
function user_folder_handle_eviction(mysqli $db, int $wohnungId, int $newMieterId) {
    // Find who was there before (User who had this wohnung_id and is NOT the new one)
    $res = $db->query("SELECT id, name, folder_path FROM benutzer WHERE wohnung_id = $wohnungId AND id != $newMieterId");
    while($oldUser = $res->fetch_assoc()) {
        $oldUserId = (int)$oldUser['id'];
        $oldPath = $oldUser['folder_path'];
        
        if ($oldPath && strpos($oldPath, '01_Mieter') !== false) {
            // Target: 99_Vormieter
            $newPath = str_replace('01_Mieter', '99_Vormieter', $oldPath);
            $root = realpath(__DIR__ . '/..');
            $absOld = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldPath);
            $absNew = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newPath);
            
            @mkdir(dirname($absNew), 0777, true);
            if (@rename($absOld, $absNew)) {
                $db->query("UPDATE benutzer SET folder_path = '" . $db->real_escape_string($newPath) . "', wohnung_id = 0 WHERE id = $oldUserId");
            }
        }
    }
}
