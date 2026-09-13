<?php
// C:\xampp\htdocs\pendenz.com\api\chat\messages.php
// Robuste Version ohne "SHOW COLUMNS LIKE ?" (nutzt INFORMATION_SCHEMA), LIMIT ohne Platzhalter,
// durchgehend JSON-Response (keine HTML/Redirects)

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'not_logged_in']);
  exit;
}

function jexit($data, int $code=200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/** Hole alle Spaltennamen einer Tabelle aus INFORMATION_SCHEMA (ohne Platzhalter-Nutzung in SHOW) */
function get_table_columns(mysqli $db, string $table): array {
  $escTable = $db->real_escape_string($table);
  $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escTable}'";
  $res = $db->query($sql);
  if (!$res) return [];
  $cols = [];
  while ($row = $res->fetch_assoc()) {
    $cols[strtolower($row['COLUMN_NAME'])] = $row['COLUMN_NAME'];
  }
  $res->close();
  return $cols;
}

/** Nimm erste gefundene Spalte aus Kandidatenliste auf Basis der vorhandenen Spalten */
function pick_column(array $available, array $candidates): ?string {
  foreach ($candidates as $c) {
    $k = strtolower($c);
    if (isset($available[$k])) return $available[$k];
  }
  return null;
}

$table = 'chat_messages';
$cols  = get_table_columns($mysqli, $table);

$colId      = pick_column($cols, ['id','message_id']);
$colRoom    = pick_column($cols, ['room_id','raum_id','channel_id','conversation_id']);
$colUser    = pick_column($cols, ['user_id','benutzer_id','sender_id','created_by','author_id','uid','user']);
$colBody    = pick_column($cols, ['body','message','text','content']);
$colCreated = pick_column($cols, ['created_at','created','timestamp','created_on','time']);

if (!$colId)      jexit(['ok'=>false,'error'=>'schema: missing message id column (id/message_id)'], 500);
if (!$colRoom)    jexit(['ok'=>false,'error'=>'schema: missing room column (room_id/raum_id/...)'], 500);
if (!$colUser)    jexit(['ok'=>false,'error'=>'schema: missing user column (user_id/sender_id/...)'], 500);
if (!$colBody)    jexit(['ok'=>false,'error'=>'schema: missing body column (body/message/...)'], 500);
if (!$colCreated) jexit(['ok'=>false,'error'=>'schema: missing created column (created_at/created/...)'], 500);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
  $roomId  = (int)($_GET['room_id'] ?? 0);
  $sinceId = (int)($_GET['since_id'] ?? 0);
  $limit   = (int)($_GET['limit'] ?? 100);
  if ($roomId <= 0) jexit(['ok'=>false,'error'=>'room_id required'], 400);
  if ($limit < 20)  $limit = 20;
  if ($limit > 200) $limit = 200;
  $limitSql = " LIMIT {$limit}";

  // Mitgliedschaft prüfen
  $st = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
  $st->bind_param("ii", $roomId, $uid);
  $st->execute();
  if (!$st->get_result()->fetch_row()) { $st->close(); jexit(['ok'=>false,'error'=>'forbidden'], 403); }
  $st->close();

  if ($sinceId > 0) {
    $sql = "SELECT m.`{$colId}`      AS id,
                   m.`{$colUser}`    AS user_id,
                   m.`{$colBody}`    AS body,
                   m.`{$colCreated}` AS created_at,
                   b.name            AS user_name
            FROM `{$table}` m
            LEFT JOIN benutzer b ON b.id = m.`{$colUser}`
            WHERE m.`{$colRoom}`=? AND m.`{$colId}`>?
            ORDER BY m.`{$colId}` ASC{$limitSql}";
    $st = $mysqli->prepare($sql);
    $st->bind_param("ii", $roomId, $sinceId);
  } else {
    $sql = "SELECT m.`{$colId}`      AS id,
                   m.`{$colUser}`    AS user_id,
                   m.`{$colBody}`    AS body,
                   m.`{$colCreated}` AS created_at,
                   b.name            AS user_name
            FROM `{$table}` m
            LEFT JOIN benutzer b ON b.id = m.`{$colUser}`
            WHERE m.`{$colRoom}`=?
            ORDER BY m.`{$colId}` DESC{$limitSql}";
    $st = $mysqli->prepare($sql);
    $st->bind_param("i", $roomId);
  }

  $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  if ($sinceId === 0) { $rows = array_reverse($rows); }
  jexit(['ok'=>true,'messages'=>$rows]);
}

if ($method === 'POST') {
  require_once __DIR__ . '/../../includes/csrf.php';
  if (!csrf_validate_request()) jexit(['ok'=>false,'error'=>'csrf'], 400);

  $roomId = (int)($_POST['room_id'] ?? 0);
  $text   = trim((string)($_POST['message'] ?? ''));
  if ($roomId<=0 || $text==='') jexit(['ok'=>false,'error'=>'room_id and message required'], 400);

  // Mitgliedschaft prüfen
  $st = $mysqli->prepare("SELECT 1 FROM chat_members WHERE room_id=? AND user_id=?");
  $st->bind_param("ii", $roomId, $uid);
  $st->execute();
  if (!$st->get_result()->fetch_row()) { $st->close(); jexit(['ok'=>false,'error'=>'forbidden'], 403); }
  $st->close();

  // Insert
  $sql = "INSERT INTO `{$table}` (`{$colRoom}`, `{$colUser}`, `{$colBody}`) VALUES (?,?,?)";
  $st  = $mysqli->prepare($sql);
  $st->bind_param("iis", $roomId, $uid, $text);
  $st->execute();
  $msgId = (int)$mysqli->insert_id;
  $st->close();

  // Room bump
  $st = $mysqli->prepare("UPDATE chat_rooms SET updated_at=NOW() WHERE id=?");
  $st->bind_param("i", $roomId);
  $st->execute();
  $st->close();

  // Rückgabe
  $sql = "SELECT m.`{$colId}`      AS id,
                 m.`{$colUser}`    AS user_id,
                 m.`{$colBody}`    AS body,
                 m.`{$colCreated}` AS created_at,
                 b.name            AS user_name
          FROM `{$table}` m
          LEFT JOIN benutzer b ON b.id = m.`{$colUser}`
          WHERE m.`{$colId}`=?";
  $st = $mysqli->prepare($sql);
  $st->bind_param("i", $msgId);
  $st->execute();
  $msg = $st->get_result()->fetch_assoc();
  $st->close();

  jexit(['ok'=>true,'message'=>$msg]);
}

jexit(['ok'=>false,'error'=>'method_not_allowed'], 405);
