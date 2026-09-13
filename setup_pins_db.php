<?php
require_once __DIR__ . "/config.php";

$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_pins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pendenz_id INT NOT NULL,
    plan_id INT NOT NULL,
    x_pct FLOAT NOT NULL,
    y_pct FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY (pendenz_id),
    KEY (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if ($mysqli->error) {
    echo "Error: " . $mysqli->error;
} else {
    echo "Table created successfully.";
}
