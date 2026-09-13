<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/functions.php";

// Öffentliche Registrierung deaktiviert
header("Location: login.php");
exit;

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name     = trim($_POST['name'] ?? "");
        $email    = trim($_POST['email'] ?? "");
        $passwort = $_POST['passwort'] ?? "";
        $rolle    = "benutzer"; // Standardrolle bei Registrierung

        if ($name === "" || $email === "" || $passwort === "") {
            throw new Exception("Bitte alle Felder ausfüllen.");
        }

        // prüfen ob E-Mail schon existiert
        $check = $mysqli->prepare("SELECT id FROM benutzer WHERE email=?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            throw new Exception("E-Mail ist bereits registriert.");
        }
        $check->close();

        // Passwort hashen
        $hash = password_hash($passwort, PASSWORD_BCRYPT);

        // Benutzer erstmal anlegen (ohne Bilder)
        $stmt = $mysqli->prepare("INSERT INTO benutzer (name, email, passwort, rolle) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $name, $email, $hash, $rolle);
        $stmt->execute();
        $newId = $mysqli->insert_id; // ID aus DB holen

        // Uploads jetzt in Ordner /uploads/benutzer/{id}_{name}/
        $profilbild = handle_upload("profilbild", null, $newId, $name);
        $firmenlogo = handle_upload("firmenlogo", null, $newId, $name);

        // Bildpfade speichern
        $stmt = $mysqli->prepare("UPDATE benutzer SET profilbild=?, firmenlogo=? WHERE id=?");
        $stmt->bind_param("ssi", $profilbild, $firmenlogo, $newId);
        $stmt->execute();

        $success = "✅ Registrierung erfolgreich. Du kannst dich jetzt einloggen.";
        header("Location: login.php");
        exit;

    } catch (Throwable $e) {
        $error = "❌ " . $e->getMessage();
    }
}

include __DIR__ . "/includes/header.php";
include __DIR__ . "/includes/nav_public.php";
?>
<main style="max-width:600px;margin:24px auto;padding:0 12px;">
  <div class="card" style="padding:25px;border-radius:10px;box-shadow:0 4px 10px rgba(0,0,0,0.1);">
    <h2 style="margin-bottom:20px;">Registrieren</h2>

    <?php if($error): ?>
      <div style="color:#b00020;margin-bottom:15px;border:1px solid #f3c2c2;background:#ffecec;padding:8px;border-radius:6px;">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if($success): ?>
      <div style="color:#006600;margin-bottom:15px;border:1px solid #a5d6a7;background:#e8f5e9;padding:8px;border-radius:6px;">
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <label>Name*<br>
        <input type="text" name="name" required style="width:100%;padding:10px;margin-bottom:12px;">
      </label>

      <label>E-Mail*<br>
        <input type="email" name="email" required style="width:100%;padding:10px;margin-bottom:12px;">
      </label>

      <label>Passwort*<br>
        <input type="password" name="passwort" required style="width:100%;padding:10px;margin-bottom:12px;">
      </label>

      <label style="display:block; margin-top:15px;">Profilbild<br>
        <input type="file" name="profilbild" accept="image/*" style="margin-bottom:12px;">
      </label>

      <label style="display:block; margin-top:10px; margin-bottom:15px;">Firmenlogo<br>
        <input type="file" name="firmenlogo" accept="image/*" style="margin-bottom:12px;">
      </label>

      <button type="submit" style="width:100%;padding:12px;background:#1abc9c;border:none;border-radius:6px;color:#fff;font-size:16px;font-weight:bold;cursor:pointer;">
        Registrieren
      </button>
    </form>

    <p style="margin-top:15px;font-size:14px;">
      Schon registriert? <a href="login.php">Zum Login</a>
    </p>
  </div>
</main>
<?php include __DIR__ . "/includes/footer.php"; ?>
