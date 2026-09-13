<?php
// includes/vorgang_taxonomy.php
// Taxonomie für Vorgangsarten (Pendenz, Abnahme, Mahnung, etc.)

if (!function_exists('h')) {
    function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function vorgang_taxonomy_slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/** Schema Absicherung für Vorgangsarten */
function vorgang_taxonomy_ensure_tables(mysqli $db): void {
    // 1) pendenzen_arten Tabelle (Taxonomie)
    $db->query("CREATE TABLE IF NOT EXISTS pendenzen_arten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(100) NOT NULL,
        name VARCHAR(100) NOT NULL,
        icon VARCHAR(50) DEFAULT NULL,
        color VARCHAR(20) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 100,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_a_slug (slug),
        UNIQUE KEY uq_a_name (name),
        KEY idx_a_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2) pendenzen_arten_projekte Tabelle (Projekt/Objekt-Beschränkung)
    $db->query("CREATE TABLE IF NOT EXISTS pendenzen_arten_projekte (
        vorgangsart_id INT NOT NULL,
        projekt_id INT NOT NULL,
        objekt_id INT NULL,
        KEY idx_vap_vorg (vorgangsart_id),
        KEY idx_vap_proj (projekt_id),
        KEY idx_vap_obj (objekt_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!v_col_exists($db, 'pendenzen_arten_projekte', 'objekt_id')) {
        $db->query("ALTER TABLE pendenzen_arten_projekte ADD COLUMN objekt_id INT NULL AFTER projekt_id");
        $db->query("ALTER TABLE pendenzen_arten_projekte ADD INDEX idx_vap_obj (objekt_id)");
    }

    // 3) pendenzen_arten erweitern um fehlende Spalten
    if (!v_col_exists($db, 'pendenzen_arten', 'slug')) {
        $db->query("ALTER TABLE pendenzen_arten ADD COLUMN slug VARCHAR(100) NOT NULL AFTER id");
    }
    if (!v_col_exists($db, 'pendenzen_arten', 'icon')) {
        $db->query("ALTER TABLE pendenzen_arten ADD COLUMN icon VARCHAR(50) DEFAULT NULL AFTER name");
    }
    if (!v_col_exists($db, 'pendenzen_arten', 'color')) {
        $db->query("ALTER TABLE pendenzen_arten ADD COLUMN color VARCHAR(20) DEFAULT NULL AFTER icon");
    }
    if (!v_col_exists($db, 'pendenzen_arten', 'allowed_roles')) {
        $db->query("ALTER TABLE pendenzen_arten ADD COLUMN allowed_roles TEXT NULL");
    }
    if (!v_col_exists($db, 'pendenzen_arten', 'allowed_business_types')) {
        $db->query("ALTER TABLE pendenzen_arten ADD COLUMN allowed_business_types TEXT NULL");
    }
    if (!v_col_exists($db, 'pendenzen_arten', 'is_active')) {
        $db->query("ALTER TABLE pendenzen_arten ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
    }

    // 4) pendenzen Tabelle um vorgangsart_id erweitern
    if (!v_col_exists($db, 'pendenzen', 'vorgangsart_id')) {
        $db->query("ALTER TABLE pendenzen ADD COLUMN vorgangsart_id INT NULL AFTER projekt_id");
        $db->query("ALTER TABLE pendenzen ADD INDEX idx_vorgangsart (vorgangsart_id)");
    }

    // Default Daten befüllen falls leer
    $count = (int)$db->query("SELECT COUNT(*) FROM pendenzen_arten")->fetch_row()[0];
    if ($count === 0) {
        $defaults = [
            ['Pendenz', '📝', '#4a90e2'],
            ['Infomeldung', 'ℹ️', '#5cacee'],
            ['Mahnung', '⚠️', '#f5a623'],
            ['Abmahnung', '🚫', '#d0021b'],
            ['Abnahme', '⚙️', '#7ed321'],
            ['Wohnungsabnahme', '🏠', '#9013fe'],
            ['Wohnungsübergabe', '🔑', '#f8e71c'],
            ['Unternehmerabnahme', '🏗️', '#50e3c2']
        ];
        $st = $db->prepare("INSERT INTO pendenzen_arten (name, slug, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)");
        $i = 10;
        foreach ($defaults as $d) {
            $slug = vorgang_taxonomy_slugify($d[0]);
            $st->bind_param('ssssi', $d[0], $slug, $d[1], $d[2], $i);
            $st->execute();
            $i += 10;
        }
        $st->close();
    }
}

function v_col_exists(mysqli $db, string $table, string $col): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $db->prepare($sql);
    $st->bind_param('ss', $table, $col);
    $st->execute();
    $res = $st->get_result();
    $ok = $res && $res->num_rows > 0;
    $st->close();
    return $ok;
}

/** Alle Vorgangsarten holen */
function vorgangsarten_all(mysqli $db): array {
    vorgang_taxonomy_ensure_tables($db);
    $out = [];
    $rs = $db->query("SELECT * FROM pendenzen_arten ORDER BY sort_order ASC, name ASC");
    if ($rs) {
        while ($r = $rs->fetch_assoc()) $out[] = $r;
        $rs->close();
    }
    return $out;
}
