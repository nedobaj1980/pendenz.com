<?php
$host   = '127.0.0.1';
$user   = 'root';
$pass   = '';
$dbname = 'pendenz_com';
$mysqli = new mysqli($host, $user, $pass, $dbname);

$sql = "CREATE TABLE IF NOT EXISTS fs_folder_meta (
    id INT AUTO_INCREMENT PRIMARY KEY, 
    project_id INT NOT NULL, 
    rel_path VARCHAR(500) NOT NULL, 
    icon VARCHAR(50), 
    UNIQUE KEY (project_id, rel_path)
) ENGINE=InnoDB";

if ($mysqli->query($sql)) {
    echo "SUCCESS: Table fs_folder_meta ready.";
} else {
    echo "ERROR: " . $mysqli->error;
}
