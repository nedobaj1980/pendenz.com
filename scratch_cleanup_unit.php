<?php
require_once 'config.php';
$id = 14;
$mysqli->query("DELETE FROM wohnung_mieter WHERE wohnung_id = $id");
$mysqli->query("DELETE FROM miet_interessenten WHERE wohnung_id = $id");
$mysqli->query("DELETE FROM interessenten WHERE wohnung_id = $id");
$mysqli->query("DELETE FROM wohnungen WHERE id = $id");
echo "✅ Wohnung 14 erfolgreich gelöscht.\n";
?>
