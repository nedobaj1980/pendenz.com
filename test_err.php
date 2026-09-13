<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$data = json_encode(['id'=>10, 'field'=>'vorgaenger_id', 'value'=>'11ea']);
$opt = [
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $data,
        'ignore_errors' => true
    ]
];
$ctx = stream_context_create($opt);
echo file_get_contents('http://localhost/pendenz.com/api/pendenzen_inline_save.php', false, $ctx);
