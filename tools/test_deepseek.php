<?php
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

// Prüfen ob Key gesetzt
if (empty($DEEPSEEK_API_KEY)) {
    die("❌ Kein DeepSeek API-Key in config.php gefunden!");
}

$url = "https://api.deepseek.com/v1/chat/completions";

$messages = [
    ["role" => "system", "content" => "Du bist ein Test-Assistent für pendenz.com."],
    ["role" => "user", "content" => "Sag mir bitte nur 'Hallo von DeepSeek'."]
];

$postData = [
    "model" => "deepseek-chat",
    "messages" => $messages,
    "temperature" => 0.2
];

$opts = [
    "http" => [
        "method"  => "POST",
        "header"  => "Content-Type: application/json\r\n" .
                     "Authorization: Bearer " . $DEEPSEEK_API_KEY . "\r\n",
        "content" => json_encode($postData),
        "ignore_errors" => true
    ]
];

$context = stream_context_create($opts);
$result  = @file_get_contents($url, false, $context);

if ($result === false) {
    $error = error_get_last();
    die("❌ Request fehlgeschlagen: " . print_r($error, true));
}

$json = json_decode($result, true);

if (isset($json['error'])) {
    die("❌ DeepSeek API-Error:\n" . print_r($json['error'], true));
}

$content = $json['choices'][0]['message']['content'] ?? null;

if (!$content) {
    die("❌ Keine Antwort erhalten:\n" . $result);
}

echo "✅ DeepSeek funktioniert!\n";
echo "Antwort: " . $content . "\n";
