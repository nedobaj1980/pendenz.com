<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function mustNotContain(string $file, string $needle, string $message): void
{
    global $failures;
    $content = file_get_contents($file);
    if ($content === false) {
        $failures[] = "Kann Datei nicht lesen: {$file}";
        return;
    }
    if (str_contains($content, $needle)) {
        $failures[] = $message;
    }
}

function mustContain(string $file, string $needle, string $message): void
{
    global $failures;
    $content = file_get_contents($file);
    if ($content === false) {
        $failures[] = "Kann Datei nicht lesen: {$file}";
        return;
    }
    if (!str_contains($content, $needle)) {
        $failures[] = $message;
    }
}

$voiceApi = $root . '/api/voice_pendenz.php';

mustNotContain(
    $voiceApi,
    'CURLOPT_SSL_VERIFYPEER, false',
    'voice_pendenz.php darf die TLS-Zertifikatsprüfung nicht deaktivieren.'
);

mustNotContain(
    $voiceApi,
    'SELECT id FROM benutzer ORDER BY id ASC LIMIT 1',
    'voice_pendenz.php darf bei ungültiger Session nicht auf den ersten Benutzer ausweichen.'
);

mustContain(
    $voiceApi,
    'require_project_access',
    'Voice-Speichern muss vor dem INSERT die Projektberechtigung prüfen.'
);

if ($failures !== []) {
    fwrite(STDERR, "Static safety checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Static safety checks OK\n";
