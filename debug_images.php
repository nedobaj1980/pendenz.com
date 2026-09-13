<?php
require_once __DIR__ . '/api/bootstrap.php';
$res = $mysqli->query("SELECT pfad FROM pendenz_dateien ORDER BY id DESC LIMIT 5");
while($row = $res->fetch_assoc()) {
    echo "Path: " . $row['pfad'] . " - File exists: " . (file_exists(__DIR__ . '/' . $row['pfad']) ? 'YES' : 'NO') . "<br>";
}
?>
