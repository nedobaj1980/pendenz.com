<?php
try {
    $dsn = "mysql:host=127.0.0.1;dbname=pendenz_com;charset=utf8mb4";
    $pdo = new PDO($dsn, "root", "");
    
    $tables = ['projekte', 'objekte', 'wohnungen', 'benutzer', 'bkp_codes', 'person_type', 'person_status'];
    foreach ($tables as $t) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $t");
        if ($stmt) {
            echo "Table $t: " . $stmt->fetchColumn() . " rows\n";
        } else {
            echo "Table $t: Not found or error\n";
        }
    }
} catch (Exception $e) {
    echo "PDO Error: " . $e->getMessage();
}
?>
