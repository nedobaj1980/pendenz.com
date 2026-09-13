<?php
require_once 'config.php';
require_once 'app/modules/pendenzen/Service.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$s = new App\Modules\Pendenzen\Service($db);
try {
    $res = $s->searchResult(['is_superadmin'=>true, 'limit'=>10]);
    echo "OK: " . $res->num_rows . " rows found\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
