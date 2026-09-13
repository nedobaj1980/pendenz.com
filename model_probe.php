<?php
require_once __DIR__ . '/config.php';
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
// curl_close($ch); // Deprecated in PHP 8.x


$data = json_decode($res, true);
$models = [];
if (isset($data['models'])) {
    foreach ($data['models'] as $m) {
        if (in_array('generateContent', $m['supportedGenerationMethods'])) {
            $models[] = str_replace('models/', '', $m['name']);
        }
    }
}
echo "Available Models: " . implode(', ', $models);
?>
