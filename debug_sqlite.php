<?php
require_once __DIR__ . '/config.php';
$res = $mysqli->query("SELECT user_id, count(*) as cnt FROM ai_chats GROUP BY user_id");
echo "CHATS BY USER:\n";
while($row = $res->fetch_assoc()) {
    echo "User " . $row['user_id'] . ": " . $row['cnt'] . "\n";
}
$res = $mysqli->query("SELECT user_id, count(*) as cnt FROM ai_training GROUP BY user_id");
echo "\nTRAINING BY USER:\n";
while($row = $res->fetch_assoc()) {
    echo "User " . $row['user_id'] . ": " . $row['cnt'] . "\n";
}
?>
