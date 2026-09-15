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

$voiceApi = $root . '/api/voice_pendenz_impl.php';
$bootstrap = $root . '/api/_bootstrap.php';
$aiQuery = $root . '/api/ai_query.php';
$folderTemplates = $root . '/pages/ordner_vorlagen.php';

mustNotContain(
    $voiceApi,
    'CURLOPT_SSL_VERIFYPEER, false',
    'Gimi Voice darf die TLS-Zertifikatsprüfung nicht deaktivieren.'
);

mustNotContain(
    $voiceApi,
    'SELECT id FROM benutzer ORDER BY id ASC LIMIT 1',
    'Gimi Voice darf bei ungültiger Session nicht auf den ersten Benutzer ausweichen.'
);

mustContain(
    $voiceApi,
    'require_project_access_json',
    'Voice-Speichern muss vor dem INSERT die Projektberechtigung prüfen.'
);

mustContain(
    $voiceApi,
    'external_can_view, external_can_upload',
    'Voice-Speichern muss die Public-Freigaben explizit behandeln.'
);

mustContain(
    $bootstrap,
    "'error' => 'INTERNAL_ERROR'",
    'API-Exceptions dürfen keine internen Fehlermeldungen direkt ausgeben.'
);

mustContain(
    $aiQuery,
    'require_project_access_json',
    'Gimi Chat-Aktionen müssen Projektzugriff serverseitig prüfen.'
);

mustNotContain(
    $aiQuery,
    "current_project_id'] ?? 1",
    'Gimi Chat darf nicht still auf Projekt 1 zurückfallen.'
);

mustContain(
    $aiQuery,
    '$status = \'offen\';',
    'Gimi Chat muss den Status vor dem Pendenz-INSERT explizit setzen.'
);

mustContain(
    $folderTemplates,
    'includes/csrf.php',
    'Ordner-Muster benötigen CSRF-Schutz.'
);

mustContain(
    $folderTemplates,
    'csrf_validate',
    'Ordner-Muster müssen POST-Aktionen mit CSRF prüfen.'
);

if ($failures !== []) {
    fwrite(STDERR, "Static safety checks FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Static safety checks OK\n";
