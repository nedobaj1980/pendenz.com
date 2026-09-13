<?php
require_once __DIR__ . '/config.php';
$chat_id = 43;
$res = $mysqli->query("SELECT user_id, title FROM ai_chats WHERE id = $chat_id");
if ($row = $res->fetch_assoc()) {
    echo "Chat 43 belongs to User " . $row['user_id'] . " (Title: " . $row['title'] . ")\n";
} else {
    echo "Chat 43 NOT FOUND in database!\n";
}
?>
