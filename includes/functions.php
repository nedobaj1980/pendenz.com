<?php
// includes/functions.php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

/** HTML-Escape */
if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
/** Alias für Code-Stellen, die e() nutzen */
if (!function_exists('e')) {
    function e($s): string { return h($s); }
}

/** Stabiler Präfix (Subfolder-sicher) */
if (!function_exists('site_prefix')) {
    function site_prefix(): string {
        $sn = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($sn === '/pendenz.com' || $sn === '/pendenz.com/index.php' || strpos($sn, '/pendenz.com/') === 0) {
            return '/pendenz.com/';   // mit trailing slash
        }
        return '/';                   // Root mit trailing slash
    }
}

/** Basis-URL (wird von manchen Navs erwartet) */
if (!function_exists('base_url')) {
    function base_url(string $path = ''): string {
        $base = rtrim(site_prefix(), '/');          // '/pendenz.com' oder ''
        if ($path === '') {
            return ($base === '') ? '/' : $base;    // '/' oder '/pendenz.com'
        }
        return $base . '/' . ltrim($path, '/');     // '/pendenz.com/foo' oder '/foo'
    }
}

/** URL-Helper */
if (!function_exists('url')) {
    function url(string $file): string { return site_prefix() . ltrim($file, '/'); }
}
if (!function_exists('page_url')) {
    function page_url(string $file): string { return site_prefix() . 'pages/' . ltrim($file, '/'); }
}
if (!function_exists('asset_url')) {
    function asset_url(string $path): string { return site_prefix() . 'assets/' . ltrim($path, '/'); }
}
if (!function_exists('brand_url')) {
    function brand_url(string $file): string {
        // Logos liegen unter /assets/brand/
        return asset_url('brand/' . ltrim($file, '/'));
    }
}

/** Dateiname säubern */
if (!function_exists('safe_name')) {
    function safe_name(string $name): string {
        $name = preg_replace('/[^a-zA-Z0-9._-]/u', '_', $name);
        return trim($name, '_');
    }
}

/** System-Einstellung laden */
if (!function_exists('get_system_setting')) {
    function get_system_setting(string $key, $default = null) {
        global $mysqli;
        static $cache = [];
        if (isset($cache[$key])) return $cache[$key];
        
        try {
            $stmt = $mysqli->prepare("SELECT s_value FROM system_settings WHERE s_key = ?");
            if ($stmt) {
                $stmt->bind_param("s", $key);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    return $cache[$key] = $row['s_value'];
                }
            }
        } catch (Throwable $e) {}
        return $default;
    }
}

/** Pfad (URL-ähnlich) → absoluter Dateipfad */
if (!function_exists('abs_path_from')) {
    function abs_path_from(string $path): string {
        $prefix = rtrim(site_prefix(), '/'); // z.B. /pendenz.com
        $root   = realpath(__DIR__ . '/..'); // Projektwurzel
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return ''; // extern, keine Prüfung möglich
        }
        if (strpos($path, $prefix . '/') === 0) {
            // /pendenz.com/uploads/… → /…/project/uploads/…
            $rel = substr($path, strlen($prefix) + 1);
            return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }
        if (strpos($path, '/') === 0) {
            // /uploads/… (ohne Präfix) → /…/project/uploads/…
            $rel = ltrim($path, '/');
            return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }
        // relativ: uploads/…
        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}

/** Datei vorhanden? (URL-ähnlicher Pfad) */
if (!function_exists('file_exists_url')) {
    function file_exists_url(?string $path): bool {
        if (!$path) return false;
        $abs = abs_path_from($path);
        return $abs !== '' && is_file($abs);
    }
}

/** Beste Bild-URL oder Platzhalter */
if (!function_exists('best_image_url')) {
    function best_image_url(?string $path, ?string $placeholder = null): string {
        $placeholder = $placeholder ?? brand_url('logo-mark.png');
        if (!$path) return $placeholder;

        // Externe URL: direkt zurück
        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            return $path;
        }

        // Existiert die Datei physisch?
        if (file_exists_url($path)) {
            // Pfad normalisieren → URL
            if ($path[0] === '/') {
                // bereits absolut (evtl. ohne Präfix)
                if (strpos($path, site_prefix()) === 0) return $path;
                return url(ltrim($path, '/'));
            }
            // relativ (uploads/…)
            return url($path);
        }

        return $placeholder;
    }
}

/**
 * Intelligentes Skalieren und Komprimieren, um Ziel-Dateigröße (z.B. 0.5 MB) zu erreichen.
 * @param string $file Absoluter Pfad zur Datei
 * @param int $targetSizeKB Zielgröße in KB (Default 500)
 * @param int $maxDimension Maximale Breite oder Höhe (Default 1600)
 */
if (!function_exists('smart_resize_image')) {
    function smart_resize_image(string $file, ?int $targetSizeKB = null, ?int $maxDimension = null): void {
        if (!file_exists($file)) return;
        if (!function_exists('imagecreatetruecolor')) return;

        // Settings laden falls nicht übergeben
        if ($targetSizeKB === null) $targetSizeKB = (int)get_system_setting('max_image_size_kb', 500);
        if ($maxDimension === null) $maxDimension = (int)get_system_setting('max_image_dimension', 1600);
        if ($targetSizeKB <= 0) $targetSizeKB = 500;
        if ($maxDimension <= 0) $maxDimension = 1600;

        $filesize = filesize($file) / 1024; // in KB
        [$width, $height, $type] = @getimagesize($file);
        
        // Wenn bereits klein genug und Dimensionen passen -> fertig
        if ($filesize <= $targetSizeKB && $width <= $maxDimension && $height <= $maxDimension) {
            return;
        }

        if (!$width || !$height) return;

        // Quelldaten laden
        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file); break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($file); break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($file); break;
            default: return;
        }
        if (!$src) return;

        // Schritt 1: Dimensionen anpassen (auf maxDimension)
        $ratio = $width / $height;
        $newW = $width;
        $newH = $height;
        if ($width > $maxDimension || $height > $maxDimension) {
            if ($width > $height) {
                $newW = $maxDimension;
                $newH = (int)round($newW / $ratio);
            } else {
                $newH = $maxDimension;
                $newW = (int)round($newH * $ratio);
            }
        }

        // Erste Verkleinerung
        $dst = imagecreatetruecolor($newW, $newH);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        
        // Schritt 2: Speichern und Größe prüfen
        $saveAsJpeg = ($type === IMAGETYPE_JPEG || ($type === IMAGETYPE_PNG && $filesize > $targetSizeKB));
        $initialSize = $filesize;
        
        if ($saveAsJpeg) {
            $quality = 95; // Starten wir höher
            $pass = 0;
            do {
                imagejpeg($dst, $file, $quality);
                clearstatcache(true, $file);
                $currentSize = filesize($file) / 1024;
                
                if ($currentSize <= $targetSizeKB) break;
                
                if ($quality > 30) {
                    $quality -= 10; // Kleinere Schritte
                } else {
                    // Wenn Qualität am Limit (30) und immer noch zu groß -> Dimensionen verringern
                    $newW = (int)($newW * 0.85);
                    $newH = (int)($newH * 0.85);
                    $tmp = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($tmp, $dst, 0, 0, 0, 0, $newW, $newH, imagesx($dst), imagesy($dst));
                    imagedestroy($dst);
                    $dst = $tmp;
                    $quality = 80; // Reset Qualität für neue Dimension
                }
                $pass++;
            } while ($pass < 15 && ($newW > 400));
        } elseif ($type === IMAGETYPE_WEBP) {
            imagewebp($dst, $file, 85);
        } elseif ($type === IMAGETYPE_PNG) {
            imagepng($dst, $file, 8);
        } else {
            imagegif($dst, $file);
        }
        
        clearstatcache(true, $file);
        $finalSize = filesize($file) / 1024;
        @file_put_contents(__DIR__ . '/../logs/image_resize.log', date('Y-m-d H:i:s') . " | File: " . basename($file) . " | Initial: " . round($initialSize, 1) . "KB | Final: " . round($finalSize, 1) . "KB | Target: " . $targetSizeKB . "KB\n", FILE_APPEND);
        
        imagedestroy($dst);
        imagedestroy($src);
    }
}

/** Bild skalieren (legacy, nutzt nun smart_resize_image falls gerufen) */
if (!function_exists('resize_image')) {
    function resize_image(string $file, ?int $maxW, ?int $maxH): void {
        smart_resize_image($file, 500, $maxW ?: 1600);
    }
}

/**
 * Upload-Helper (Signatur passend zu deinem benutzer.php-Aufruf)
 * handle_upload(field, oldPath, userId, displayName, allowTypes, maxBytes, resizeMaxW, resizeMaxH)
 * Rückgabe: relativer Pfad (string) ODER null
 */
if (!function_exists('handle_upload')) {
    function handle_upload(
        string $field,
        ?string $oldPath,
        int $userId,
        string $displayName,
        array $allowTypes = ['image/jpeg','image/png','image/gif'],
        int $maxBytes = 10_000_000,
        ?int $resizeMaxW = null,
        ?int $resizeMaxH = null
    ): ?string {
        if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null; // kein neues File
        }

        $f = $_FILES[$field];
        if ($f['size'] > $maxBytes) throw new Exception("Datei zu groß.");
        $mime = mime_content_type($f['tmp_name']);
        if (!in_array($mime, $allowTypes, true)) throw new Exception("Ungültiger Dateityp: {$mime}");

        // Zielordner pro Benutzer
        $destDirRel = 'uploads/benutzer/' . max(1, $userId);
        $absDir = __DIR__ . '/../' . $destDirRel;
        if (!is_dir($absDir)) @mkdir($absDir, 0777, true);

        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $base = safe_name($displayName !== '' ? $displayName : pathinfo($f['name'], PATHINFO_FILENAME));
        $filename = uniqid('up_', true) . '_' . $base . ($ext ? ".{$ext}" : '');
        $absPath  = $absDir . '/' . $filename;

        if (!move_uploaded_file($f['tmp_name'], $absPath)) {
            throw new Exception("Upload fehlgeschlagen.");
        }

        if (($resizeMaxW || $resizeMaxH) && in_array($mime, ['image/jpeg','image/png','image/gif'], true)) {
            resize_image($absPath, $resizeMaxW, $resizeMaxH);
        }

        // Optional: altes File löschen (wenn im selben Projektpfad)
        if ($oldPath && file_exists_url($oldPath)) {
            $oldAbs = abs_path_from($oldPath);
            if ($oldAbs && is_file($oldAbs)) @unlink($oldAbs);
        }

        // Rückgabe: relativer Pfad für DB (uploads/benutzer/{id}/file.ext)
        return $destDirRel . '/' . $filename;
    }
}
/** DB Helpers */
if (!function_exists('table_exists')) {
    function table_exists(mysqli $db, string $name): bool {
        $name = $db->real_escape_string($name);
        $res = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$name}'");
        return ($res && $res->num_rows > 0);
    }
}
if (!function_exists('column_exists')) {
    function column_exists(mysqli $db, string $table, string $col): bool {
        $t = $db->real_escape_string($table);
        $c = $db->real_escape_string($col);
        $res = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='{$t}' AND column_name='{$c}'");
        return ($res && $res->num_rows > 0);
    }
}

/** Einladungs-Token erzeugen */
if (!function_exists('generate_invite_token')) {
    function generate_invite_token(mysqli $db, int $user_id): string {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));
        
        $stmt = $db->prepare("UPDATE benutzer SET invite_token_hash=?, invite_expires=?, invite_status='pending' WHERE id=?");
        $stmt->bind_param("ssi", $hash, $expires, $user_id);
        $stmt->execute();
        $stmt->close();
        
        return $token;
    }
}
/** E-Mail Link erzeugen */
if (!function_exists('get_invite_link')) {
    function get_invite_link(string $token): string {
        $host = (is_https_request() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        return $host . rtrim(site_prefix(), '/') . '/pages/set_password.php?t=' . rawurlencode($token);
    }
}

/** Benutzer inkl. primärer Firma laden */
if (!function_exists('get_user_with_company')) {
    function get_user_with_company(mysqli $mysqli, int $id) {
        if (!$id) return null;
        $sql = "SELECT b.*, 
                       f.name as f_name, f.email as f_email, f.telefon as f_telefon, 
                       f.adresse as f_adresse, f.ort as f_ort, f.logo as f_logo, f.website as f_website
                FROM benutzer b
                LEFT JOIN firma_user fu ON b.id = fu.user_id AND fu.is_primary = 1
                LEFT JOIN firmen f ON fu.firma_id = f.id
                WHERE b.id = " . (int)$id . " LIMIT 1";
        $res = $mysqli->query($sql);
        $u = $res ? $res->fetch_assoc() : null;
        if ($u) {
            // Mapping für einheitlichen Zugriff
            if (!empty($u['f_name']))    $u['firma_name'] = $u['f_name'];
            if (!empty($u['f_email']))   $u['firma_email'] = $u['f_email'];
            if (!empty($u['f_telefon'])) $u['firma_telefon'] = $u['f_telefon'];
            if (!empty($u['f_ort']))     $u['firma_ort'] = $u['f_ort']; 
            if (!empty($u['f_adresse'])) {
                $u['firma_strasse'] = $u['f_adresse'];
                $u['firma_adresse_full'] = trim($u['f_adresse'] . ', ' . ($u['f_ort'] ?? ''), ', ');
            }
            if (!empty($u['f_logo']))    $u['firmenlogo'] = $u['f_logo'];
            
            // Kompatibilität mit firmenname (legacy)
            $u['firmenname'] = $u['firma_name'] ?? ($u['firmenname'] ?? '');
            
            // Hardcode Fix für Helvetic immo AG falls nötig (als Fallback)
            if (empty($u['firmenname']) && (strpos($u['email']??'', 'admin@pendenz.com') !== false || strpos($u['email']??'', 'helvetic') !== false)) {
                $u['firmenname'] = 'Helvetic immo AG';
            }
        }
        return $u;
    }
}

/** Joins strings with a separator, ensuring unique non-empty values. (Template + Manual logic) */
if (!function_exists('joinUnique')) {
    function joinUnique(array $values, string $separator = ', '): string {
        $clean = [];
        foreach ($values as $v) {
            $s = trim((string)($v ?? ''));
            if ($s !== '') {
                // Case-insensitive check for uniqueness
                $lower = mb_strtolower($s);
                $exists = false;
                foreach($clean as $c) {
                    if (mb_strtolower($c) === $lower) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) $clean[] = $s;
            }
        }
        return implode($separator, $clean);
    }
}

/** Joins text blocks with double newlines. */
if (!function_exists('joinTextBlocks')) {
    function joinTextBlocks(array $blocks): string {
        $clean = array_filter(array_map('trim', array_map('strval', $blocks)));
        return implode("\n\n", $clean);
    }
}
/**
 * Holt einen einzelnen Datensatz aus der Datenbank.
 * @param string $sql SQL-Query (ggf. mit ?)
 * @param string $types Parameter-Typen (z.B. "si")
 * @param array $params Parameter-Werte
 * @return array|null Der Datensatz als assoziatives Array oder null
 */
if (!function_exists('db_one')) {
    function db_one(string $sql, string $types = '', array $params = []): ?array {
        global $mysqli;
        if (!$mysqli) return null;
        
        try {
            if (empty($params)) {
                $res = $mysqli->query($sql);
                return ($res && $res instanceof mysqli_result) ? $res->fetch_assoc() : null;
            }
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) return null;
            
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            $data = ($res && $res instanceof mysqli_result) ? $res->fetch_assoc() : null;
            $stmt->close();
            return $data;
        } catch (Throwable $e) {
            error_log("db_one error: " . $e->getMessage());
            return null;
        }
    }
}
if (!function_exists('ensure_projekt_plaene_tables')) {
    /** Stellt sicher, dass die Tabellen für Pläne und Zonen existieren */
    function ensure_projekt_plaene_tables(mysqli $mysqli): void {
        if (!table_exists($mysqli, 'projekt_plaene')) {
            $mysqli->query("CREATE TABLE IF NOT EXISTS `projekt_plaene` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `projekt_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `datei_pfad` VARCHAR(500) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`projekt_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!table_exists($mysqli, 'plan_zonen')) {
            $mysqli->query("CREATE TABLE IF NOT EXISTS `plan_zonen` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `plan_id` INT NOT NULL,
                `wohnung_id` INT NOT NULL,
                `x_pct` DECIMAL(10,4) NOT NULL,
                `y_pct` DECIMAL(10,4) NOT NULL,
                `width_pct` DECIMAL(10,4) NOT NULL,
                `height_pct` DECIMAL(10,4) NOT NULL,
                INDEX (`plan_id`),
                INDEX (`wohnung_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // Spalten in pendenzen sicherstellen (für Pin-Verortung)
        $cols = [
            'plan_id' => 'INT DEFAULT NULL',
            'pin_x'   => 'DECIMAL(10,4) DEFAULT NULL',
            'pin_y'   => 'DECIMAL(10,4) DEFAULT NULL'
        ];
        foreach ($cols as $col => $def) {
            if (!column_exists($mysqli, 'pendenzen', $col)) {
                $mysqli->query("ALTER TABLE `pendenzen` ADD COLUMN `{$col}` {$def}");
            }
        }
    }
}

if (!function_exists('ensure_modern_schema')) {
    /** Stellt sicher, dass moderne Felder wie vorlagen_welt und empfaenger_typ existieren */
    function ensure_modern_schema(mysqli $mysqli): void {
        $tables = [
            'pendenzen' => [
                'vorlagen_welt' => "VARCHAR(50) DEFAULT '' AFTER vorgangsart_id",
                'empfaenger_typ' => "VARCHAR(50) DEFAULT '' AFTER vorlagen_welt",
            ],
            'pendenzen_arten' => [
                'vorlagen_welt' => "VARCHAR(50) DEFAULT ''",
                'empfaenger_typ' => "VARCHAR(50) DEFAULT ''",
            ]
        ];
        foreach ($tables as $table => $cols) {
            if (!table_exists($mysqli, $table)) continue;
            foreach ($cols as $col => $def) {
                if (!column_exists($mysqli, $table, $col)) {
                    $mysqli->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
                }
            }
        }
    }
}

// Selbst-Reparatur Trigger
if (isset($mysqli)) {
    ensure_projekt_plaene_tables($mysqli);
    ensure_modern_schema($mysqli);
}
