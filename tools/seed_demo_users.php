<?php
// tools/seed_demo_users.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

// ---- kleine Helfer (Einladung) ----
function app_base_url(): string {
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base   = rtrim(site_prefix(), '/');
    return $scheme.'://'.$host.$base;
}
function invite_create(mysqli $db, int $userId, int $days = 7): array {
    $token   = bin2hex(random_bytes(32));
    $expires = (new DateTime("+{$days} days"))->format('Y-m-d H:i:s');
    $st = $db->prepare("INSERT INTO user_invites (user_id, token, expires_at) VALUES (?,?,?)");
    $st->bind_param("iss", $userId, $token, $expires);
    $st->execute(); $st->close();
    return ['token'=>$token, 'expires_at'=>$expires];
}
function invite_url(string $token): string {
    return app_base_url() . '/pages/accept_invite.php?token=' . urlencode($token);
}
function upsert_user(mysqli $db, string $name, string $email, string $rolle): int {
    // gibt user-id zurück
    $st = $db->prepare("SELECT id FROM benutzer WHERE email=? LIMIT 1");
    $st->bind_param("s", $email); $st->execute();
    $row = $st->get_result()->fetch_assoc(); $st->close();

    if ($row) {
        $id = (int)$row['id'];
        $st = $db->prepare("UPDATE benutzer SET name=?, rolle=? WHERE id=?");
        $st->bind_param("ssi", $name, $rolle, $id);
        $st->execute(); $st->close();
        return $id;
    }
    $sql = "INSERT INTO benutzer (name,email,rolle,profile_vis,profile_updated_at) VALUES (?,?,?,?,NOW())";
    $vis = json_encode(['name'=>'public','email'=>'private'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $st = $db->prepare($sql);
    $st->bind_param("ssss", $name, $email, $rolle, $vis);
    $st->execute(); $id = (int)$db->insert_id; $st->close();
    return $id;
}
function ensure_table_invites(mysqli $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS user_invites (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          token VARCHAR(128) NOT NULL UNIQUE,
          expires_at DATETIME NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (user_id) REFERENCES benutzer(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// ---- Ausführung ----
header('Content-Type: text/html; charset=utf-8');

try {
    ensure_table_invites($mysqli);

    $adminId = upsert_user($mysqli, 'Admin Demo', 'admin@pendenz.local', 'admin');
    $userId  = upsert_user($mysqli, 'Max Beispiel', 'user@pendenz.local',  'benutzer');

    $invA = invite_create($mysqli, $adminId, 14);
    $invB = invite_create($mysqli, $userId,  14);

    echo "<h2>Test-Profile angelegt/aktualisiert</h2>";
    echo "<ul>";
    echo "<li>Admin: admin@pendenz.local – Einladung: <a href='".htmlspecialchars(invite_url($invA['token']))."' target='_blank'>Link</a></li>";
    echo "<li>Benutzer: user@pendenz.local – Einladung: <a href='".htmlspecialchars(invite_url($invB['token']))."' target='_blank'>Link</a></li>";
    echo "</ul>";
    echo "<p>Nach Klick auf den Link kannst du je ein Passwort setzen und dich einloggen.</p>";
} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>Fehler: ".htmlspecialchars($e->getMessage())."</pre>";
}
