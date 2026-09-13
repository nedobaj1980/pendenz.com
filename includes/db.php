<?php
function db(): mysqli {
    static $conn = null;
    if ($conn instanceof mysqli) return $conn;
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die('DB connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function db_exec(string $sql, string $types = '', array $params = []): bool {
    $stmt = db()->prepare($sql);
    if (!$stmt) { error_log(db()->error); return false; }
    if ($types !== '' && !empty($params)) { $stmt->bind_param($types, ...$params); }
    $ok = $stmt->execute();
    if (!$ok) { error_log($stmt->error); }
    $stmt->close();
    return $ok;
}

function db_all(string $sql, string $types = '', array $params = []): array {
    $stmt = db()->prepare($sql);
    if (!$stmt) { error_log(db()->error); return []; }
    if ($types !== '' && !empty($params)) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function db_one(string $sql, string $types = '', array $params = []): ?array {
    $rows = db_all($sql, $types, $params);
    return $rows[0] ?? null;
}

function db_last_id(): int {
    return db()->insert_id;
}
?>
