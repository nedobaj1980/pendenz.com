<?php 
require 'config.php'; 
// require 'app/core/autoload.php'; // Falls vorhanden
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); 
if (class_exists('App\Modules\Pendenzen\Service')) {
    $service = new App\Modules\Pendenzen\Service($db); 
    $res = $service->searchResult(['projekt_id' => 4]); 
    echo $res ? $res->num_rows : $db->error; 
} else {
    echo "Service class not found.";
}
?>
