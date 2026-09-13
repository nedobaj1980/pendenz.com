<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("SELECT * FROM pdf_templates");
echo "<pre>";
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $name = $mysqli->real_escape_string($row['name']);
        $cfg = $mysqli->real_escape_string($row['config_json']);
        $def = (int)$row['is_default'];
        echo "INSERT INTO pdf_templates (name, config_json, is_default) VALUES ('$name', '$cfg', $def);\n";
    }
} else {
    echo "Keine Vorlagen gefunden.";
}
echo "</pre>";
?>
