<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "🔍 OpenAI API-Key Test\n";
echo "-----------------------\n\n";

// Prüfen, ob Key gesetzt ist
if (empty($OPENAI_API_KEY) || strpos($OPENAI_API_KEY, "sk-") !== 0) {
    echo "❌ Kein gültiger API-Key in config.php gesetzt.\n";
    exit;
}

$url = "https://api.openai.com/v1/chat/completions";

$postData = [
    "model" => "gpt-4o-mini",
    "messages" => [
        ["role" => "user", "content" => "Sag nur 'OK'"]
    ],
    "temperature" => 0.0
];

$opts = [
    "http" => [
        "method"  => "POST",
        "header"  => "Content-Type: application/json\r\n" .
                     "Authorization: Bearer " . $OPENAI_API_KEY . "\r\n",
        "content" => json_encode($postData),
        "ignore_errors" => true
    ]
];

$context = stream_context_create($opts);
$result  = @file_get_contents($url, false, $context);

if ($result === false) {
    $err = error_get_last();
    echo "❌ Request fehlgeschlagen:\n";
    print_r($err);
    exit;
}

$json = json_decode($result, true);

if (isset($json['error'])) {
    echo "❌ OpenAI API-Error:\n";
    print_r($json['error']);
    exit;
}

$content = $json['choices'][0]['message']['content'] ?? null;

if ($content) {
    echo "✅ Verbindung erfolgreich!\n";
    echo "Antwort: " . $content . "\n";
} else {
    echo "⚠️ Antwort unvollständig:\n";
    print_r($json);
}
