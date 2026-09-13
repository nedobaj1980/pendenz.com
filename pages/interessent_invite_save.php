<?php
require_once '../config.php';
require_once '../includes/fs.php';

header('Content-Type: application/json');

$pid = (int)($_POST['projekt_id'] ?? 0);
$wid = (int)($_POST['wohnung_id'] ?? 0);
$vorname = trim($_POST['vorname'] ?? '');
$nachname = trim($_POST['nachname'] ?? '');
$email = trim($_POST['email'] ?? '');

if (!$pid || !$vorname || !$nachname || !$email) {
    echo json_encode(['success' => false, 'error' => 'Bitte füllen Sie alle Pflichtfelder aus (Name & Email).']);
    exit;
}

// 1. Benutzer suchen oder anlegen (deaktiviert)
$email_esc = $mysqli->real_escape_string($email);
$uQ = $mysqli->query("SELECT id FROM benutzer WHERE email = '$email_esc'");
if ($uRow = $uQ->fetch_assoc()) {
    $userId = $uRow['id'];
} else {
    $fullName = $mysqli->real_escape_string($vorname . " " . $nachname);
    $mysqli->query("INSERT INTO benutzer (name, vorname, nachname, email, rolle) VALUES ('$fullName', '" . $mysqli->real_escape_string($vorname) . "', '" . $mysqli->real_escape_string($nachname) . "', '$email_esc', 'gast')");
    $userId = $mysqli->insert_id;
}

// 2. Interessent-Eintrag mit Token
$token = bin2hex(random_bytes(16));
$stmt = $mysqli->prepare("INSERT INTO interessenten (wohnung_id, benutzer_id, vorname, nachname, email, token, status) VALUES (?, ?, ?, ?, ?, ?, 'neu')");
$stmt->bind_param("iissss", $wid, $userId, $vorname, $nachname, $email, $token);
$stmt->execute();

// 3. Ordner im Pool anlegen
$root = project_root_path($mysqli, $pid);
if ($root) {
    $poolDir = $root . DIRECTORY_SEPARATOR . '00_Pool' . DIRECTORY_SEPARATOR . 'Interessenten';
    if (!is_dir($poolDir)) @mkdir($poolDir, 0777, true);
    
    $cleanName = preg_replace('/[^a-zA-Z0-9]/', '_', $vorname . "_" . $nachname);
    $userDir = $poolDir . DIRECTORY_SEPARATOR . $cleanName . "_" . $userId;
    if (!is_dir($userDir)) @mkdir($userDir, 0777, true);
}

$inviteUrl = APP_URL_BASE . "/pages/interessenten_form_public.php?token=" . $token;

echo json_encode([
    'success' => true, 
    'invite_url' => $inviteUrl, 
    'message' => 'Interessent wurde als deaktivierter Benutzer angelegt und Ordner im Pool erstellt.'
]);
