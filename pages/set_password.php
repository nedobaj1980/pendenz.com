<?php
// pages/set_password.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$token = $_GET['t'] ?? '';
$error = '';
$success = '';

if (!$token) {
    die("Ungültiger oder fehlender Token.");
}

$hash = hash('sha256', $token);

// Find user by token
$stmt = $mysqli->prepare("SELECT id, name, email FROM benutzer WHERE invite_token_hash = ? AND invite_expires > NOW() LIMIT 1");
$stmt->bind_param("s", $hash);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    die("Der Link ist ungültig oder abgelaufen.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw1 = $_POST['pw1'] ?? '';
    $pw2 = $_POST['pw2'] ?? '';

    if (strlen($pw1) < 8) {
        $error = "Das Passwort muss mindestens 8 Zeichen lang sein.";
    } elseif ($pw1 !== $pw2) {
        $error = "Die Passwörter stimmen nicht überein.";
    } else {
        $newHash = password_hash($pw1, PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("UPDATE benutzer SET passwort_hash = ?, invite_token_hash = NULL, invite_expires = NULL, invite_status = 'accepted' WHERE id = ?");
        $stmt->bind_param("si", $newHash, $user['id']);
        if ($stmt->execute()) {
            $success = "Passwort erfolgreich gesetzt! Sie können sich nun einloggen.";
        } else {
            $error = "Fehler beim Speichern: " . $mysqli->error;
        }
        $stmt->close();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width:500px; margin-top:50px;">
    <div class="card">
        <h2>Willkommen, <?= h($user['name']) ?></h2>
        <p>Bitte richten Sie Ihr Passwort ein, um auf Ihre Pendenzen zuzugreifen.</p>

        <?php if ($error): ?><div class="card" style="border-left:4px solid #ef4444; background:#fef2f2; color:#b91c1c;"><?= h($error) ?></div><?php endif; ?>
        <?php if ($success): ?>
            <div class="card" style="border-left:4px solid #10b981; background:#ecfdf5; color:#047857;">
                <?= h($success) ?>
                <br><br>
                <a href="../login.php" class="btn primary">Zum Login</a>
            </div>
        <?php else: ?>
            <form method="post">
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px;">Neues Passwort</label>
                    <input type="password" name="pw1" class="input" style="width:100%" required>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px;">Passwort bestätigen</label>
                    <input type="password" name="pw2" class="input" style="width:100%" required>
                </div>
                <button type="submit" class="btn primary" style="width:100%">Passwort speichern</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
