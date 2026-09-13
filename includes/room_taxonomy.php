<?php
/**
 * includes/room_taxonomy.php
 * Zentrales Management für Räume pro Wohnung.
 */

function raum_taxonomy_ensure_tables(mysqli $db) {
    // 1. Tabelle für Räume
    $db->query("CREATE TABLE IF NOT EXISTS `raeume` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `wohnung_id` INT NOT NULL,
        `name` VARCHAR(100) NOT NULL,
        `raum_typ_id` INT NULL,
        `sort_order` INT DEFAULT 0,
        `extra_json` TEXT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (`wohnung_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 2. Tabelle für Raum-Vorlagen
    $db->query("CREATE TABLE IF NOT EXISTS `raum_vorlagen` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `icon` VARCHAR(50) NULL,
        `default_sort` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sicherstellen, dass die Spalten utf8mb4 sind (Upgrade falls nötig)
    $db->query("ALTER TABLE raum_vorlagen CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->query("ALTER TABLE raeume CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->query("ALTER TABLE raeume MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Standard-Vorlagen initialisieren
    $res = $db->query("SELECT COUNT(*) FROM raum_vorlagen");
    if ($res && (int)$res->fetch_row()[0] === 0) {
        $standards = [
            ['name' => 'Wohnen/Essen/Küche', 'icon' => '🛋️🍳'],
            ['name' => 'Wohnen/Essen', 'icon' => '🛋️🍽️'],
            ['name' => 'Entrée', 'icon' => '🚪'],
            ['name' => 'Küche', 'icon' => '🍳'],
            ['name' => 'Essen', 'icon' => '🍽️'],
            ['name' => 'Wohnen', 'icon' => '🛋️'],
            ['name' => 'Elternzimmer', 'icon' => '🛌'],
            ['name' => 'Zimmer', 'icon' => '🛏️'],
            ['name' => 'Elternbad', 'icon' => '🛁'],
            ['name' => 'Nasszelle (Bad/WC)', 'icon' => '🛁'],
            ['name' => 'Gästebad / WC', 'icon' => '🚿'],
            ['name' => 'Nasszelle (Dusche/WC)', 'icon' => '🚿'],
            ['name' => 'Waschen / Technik', 'icon' => '🧺'],
            ['name' => 'Korridor / Gang', 'icon' => '🚪'],
            ['name' => 'Reduit', 'icon' => '📦'],
            ['name' => 'Balkon', 'icon' => '🏙️'],
            ['name' => 'Terrasse', 'icon' => '🌴'],
            ['name' => 'Keller', 'icon' => '🔒'],
            ['name' => 'Estrich', 'icon' => '🕸️']
        ];
        $st = $db->prepare("INSERT INTO raum_vorlagen (name, icon, default_sort) VALUES (?, ?, ?)");
        foreach ($standards as $i => $s) {
            $sort = ($i + 1) * 10;
            $st->bind_param("ssi", $s['name'], $s['icon'], $sort);
            $st->execute();
        }
    }
}

function raeume_get_by_wohnung(mysqli $db, int $wid) {
    $res = $db->query("SELECT * FROM raeume WHERE wohnung_id = $wid ORDER BY sort_order, id");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function raum_vorlagen_all(mysqli $db) {
    $res = $db->query("SELECT * FROM raum_vorlagen ORDER BY default_sort, name");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
