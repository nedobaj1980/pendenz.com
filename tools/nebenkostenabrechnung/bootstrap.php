<?php
declare(strict_types=1);
function nk_bootstrap(mysqli $db): void {
    foreach (['008_nebenkostenabrechnung.sql','009_finanzgruppen.sql'] as $migration) {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/' . $migration);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', (string)$sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $db->query($statement);
        }
    }
}
