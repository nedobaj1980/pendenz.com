<?php
require 'config.php';
require 'app/core/autoload.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$srv = new App\Modules\Pendenzen\Service($db);
$rs = $srv->searchResult(['projekt_id'=>4]);
if ($rs) {
    echo "Rows: " . $rs->num_rows . "\n";
} else {
    echo "Error: " . $db->error . "\n";
}
