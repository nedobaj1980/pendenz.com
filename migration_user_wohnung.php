<?php
require_once "config.php";
$mysqli->query("ALTER TABLE benutzer ADD COLUMN IF NOT EXISTS wohnung_id INT(11) NULL AFTER position");
$mysqli->query("CREATE INDEX IF NOT EXISTS idx_benutzer_wohnung ON benutzer(wohnung_id)");
echo "Done.";
