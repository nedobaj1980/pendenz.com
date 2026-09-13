<?php
require 'c:/xampp/htdocs/pendenz.com/config.php';
session_start();
$_SESSION['rolle'] = 'superadmin';
$_SESSION['user_id'] = 1;
$_SESSION['simulate_role'] = 'superadmin';
$_GET['action'] = 'get_init_data';

ob_start();
require 'c:/xampp/htdocs/pendenz.com/pages/ajax_quick_pendenz.php';
$json = ob_get_clean();
$data = json_decode($json, true);

if (!$data) {
    echo "Invalid JSON: " . substr($json, 0, 500);
} else {
    echo "userFirmaBkpMap: ";
    print_r($data['userFirmaBkpMap'] ?? 'MISSING');
}
