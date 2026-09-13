<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'pendenz_com');
$mysqli->query("ALTER TABLE wohnungen ADD COLUMN IF NOT EXISTS mietzins_netto_soll DECIMAL(10,2) DEFAULT 0 AFTER flaeche");
$mysqli->query("ALTER TABLE wohnungen ADD COLUMN IF NOT EXISTS mietzins_nk_soll DECIMAL(10,2) DEFAULT 0 AFTER mietzins_netto_soll");
$mysqli->query("ALTER TABLE wohnungen ADD COLUMN IF NOT EXISTS ausstattung_details LONGTEXT AFTER wintergarten");
$mysqli->query("ALTER TABLE wohnungen ADD COLUMN IF NOT EXISTS inserat_id VARCHAR(100) AFTER is_published");
$mysqli->query("ALTER TABLE wohnungen ADD COLUMN IF NOT EXISTS grundriss_pfad VARCHAR(500) AFTER folder_name");

// Table rename check for interessenten
$res = $mysqli->query("SHOW TABLES LIKE 'miet_interessenten'");
if ($res->num_rows > 0) {
    // Already exists as miet_interessenten, everything fine.
} else {
    // Maybe it's just 'interessenten'?
    $res2 = $mysqli->query("SHOW TABLES LIKE 'interessenten'");
    if ($res2->num_rows > 0) {
        // We use 'interessenten' in our code then.
    }
}
echo "Migration done.";
