<?php
require_once __DIR__ . '/_bootstrap.php';
api_try(function(){
  $db = db();
  json_response([
    'ok' => true,
    'user' => (is_logged_in() ? current_user_id() : null),
    'role' => (is_logged_in() ? current_role() : null),
    'db_server_info' => $db->server_info
  ]);
});
