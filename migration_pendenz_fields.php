<?php
require_once "config.php";

$fields = [
    ['field_key' => 'stromzaehler', 'label' => 'Stromzähler', 'type' => 'text'],
    ['field_key' => 'wasserzaehler', 'label' => 'Wasserzähler', 'type' => 'text'],
    ['field_key' => 'schluesseluebergabe', 'label' => 'Schlüsselübergabe', 'type' => 'date'],
    ['field_key' => 'zustand_reinigung', 'label' => 'Zustand Reinigung', 'type' => 'select', 'options' => '["Sehr gut", "Gut", "Genügend", "Mangelhaft"]'],
    ['field_key' => 'mieter_feedback', 'label' => 'Mieter Feedback', 'type' => 'text']
];

foreach ($fields as $f) {
    $st = $mysqli->prepare("INSERT IGNORE INTO pendenz_field_defs (field_key, label, type, options_json) VALUES (?, ?, ?, ?)");
    $opts = isset($f['options']) ? $f['options'] : null;
    $st->bind_param("ssss", $f['field_key'], $f['label'], $f['type'], $opts);
    $st->execute();
}

echo "Done.";
