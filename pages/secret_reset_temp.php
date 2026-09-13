<?php
// pages/secret_reset_temp.php
require_once __DIR__ . '/../config.php';

$tables = [
    'pendenzen', 'pendenz_dateien', 'pendenz_kommentare', 'pendenz_acl', 'pendenz_ordner', 'pendenz_anhaenge',
    'wohnungen', 'objekte', 'projekte', 'finanzen_konto', 'fs_nodes',
    'mietverhaeltnisse', 'mieter', 'mietvertraege',
    'chat_messages', 'chat_rooms', 'chat_members', 'chat_message_recipients', 'chat_attachments',
    'documents', 'files', 'folders', 'ordner', 'anhaenge', 'comments', 'notifications'
];

$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
foreach ($tables as $t) {
    $mysqli->query("TRUNCATE TABLE `$t`") || $mysqli->query("DELETE FROM `$t`") || die($mysqli->error);
}
$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
echo "DONE_RESET";
