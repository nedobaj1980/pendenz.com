<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

$uid  = (int)($_GET['uid'] ?? 0);
$hash = $_GET['h'] ?? '';

if ($uid > 0 && $hash) {
    $stmt = $mysqli->prepare("SELECT id, invite_status FROM benutzer WHERE id=? AND invite_token_hash=? LIMIT 1");
    $stmt->bind_param("is", $uid, $hash);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($u && $u['invite_status'] !== 'accepted') {
        $upd = $mysqli->prepare("UPDATE benutzer SET invite_status='opened', invite_last_opened_at=NOW() WHERE id=?");
        $upd->bind_param("i", $uid); @$upd->execute(); @$upd->close();

        $ev = $mysqli->prepare("INSERT INTO user_events (user_id, type) VALUES (?, 'invite_opened')");
        $ev->bind_param("i", $uid); @$ev->execute(); @$ev->close();
    }
}

// 1x1 PNG
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=');
