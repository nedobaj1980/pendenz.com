<?php
declare(strict_types=1);
function nk_bootstrap(mysqli $db): void {
    $sql = file_get_contents(__DIR__ . '/../../database/migrations/008_nebenkostenabrechnung.sql');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string)$sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $db->query($statement);
    }
}
