<?php
require_once 'config.php';
$res = $mysqli->query("SELECT projekt_id, COUNT(*) as cnt FROM pendenzen GROUP BY projekt_id");
while($row = $res->fetch_assoc()) {
    echo "Project ID: " . $row['projekt_id'] . " -> " . $row['cnt'] . " pendenzen\n";
}
