<?php
require 'config.php';
require 'app/core/autoload.php';
try {
    $srv = new App\Modules\Pendenzen\Service($mysqli);
    $rs = $srv->searchResult(['projekt_id'=>4]);
    echo "Rows: " . $rs->num_rows . "\n";
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
