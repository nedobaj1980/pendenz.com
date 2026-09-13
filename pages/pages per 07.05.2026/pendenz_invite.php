<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/url_helpers.php';

$PREFIX = site_prefix();
$flash  = "";
$linkRel = null;  // /pendenz.com/pages/pendenz_work.php?t=...
$linkAbs = null;  // http://localhost/pendenz.com/pages/pendenz_work.php?t=...

// Pendenzen-Auswahl
$pendenzen = $mysqli->query("SELECT id, titel, status FROM pendenzen ORDER BY erstellt_am DESC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_validate_or_throw($_POST['csrf'] ?? null);

    $pendenz_id = (int)($_POST['pendenz_id'] ?? 0);
    if ($pendenz_id <= 0) throw new Exception("Bitte eine Pendenz wählen.");

    $email = trim($_POST['email'] ?? '');

    // Rechte aus Formular
    $perm = [
      'view'       => true,                        // View erzwingen (immer an)
      'check_done' => isset($_POST['p_check']),
      'upload'     => isset($_POST['p_upload']),
      'comment'    => isset($_POST['p_comment']),
    ];

    // Ablaufdatum (optional)
    $expires_at = null;
    if (!empty($_POST['expires_days'])) {
      $days = max(1, (int)$_POST['expires_days']);
      $expires_at = (new DateTime("+$days days"))->format('Y-m-d H:i:s');
    }

    // Token generieren & speichern
    $token = bin2hex(random_bytes(24));
    $stmt  = $mysqli->prepare("INSERT INTO pendenz_tokens (pendenz_id, email, token, permissions, expires_at) VALUES (?,?,?,?,?)");
    $permJson = json_encode($perm, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("issss", $pendenz_id, $email, $token, $permJson, $expires_at);
    $stmt->execute();

    // Link bauen (RELATIV + ABSOLUT)
    $linkRel = page_url('pendenz_work.php') . '?t=' . urlencode($token);
    $linkAbs = absolute_url($linkRel);

    $flash = "✅ Link erzeugt.";
  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

/* ===== Layout / Nav ===== */
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>
<div class="container">
  <header class="hero hero-blue" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Pendenz: Einladungslink</h1>
    <a class="btn btn-head" href="pendenz_invite.php">Neu</a>
  </header>

  <?php if($flash): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Link erstellen</h2>
    <form method="post" class="form-grid" autocomplete="off">
      <?= csrf_input() ?>

      <label for="p_sel">Pendenz</label>
      <select name="pendenz_id" id="p_sel" required>
        <option value="">– wählen –</option>
        <?php while($p = $pendenzen->fetch_assoc()): ?>
          <option value="<?= (int)$p['id'] ?>">#<?= (int)$p['id'] ?> — <?= htmlspecialchars($p['titel']) ?> (<?= htmlspecialchars($p['status']) ?>)</option>
        <?php endwhile; ?>
      </select>

      <label for="p_mail">E-Mail (optional, Infozweck)</label>
      <input type="email" name="email" id="p_mail" placeholder="empfänger@domain.com">

      <div class="col-2"><h3>Rechte für den Link</h3></div>
      <label style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" checked disabled> <span>Einsehen (immer aktiv)</span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="p_check" checked> <span>Als erledigt bestätigen/abhaken</span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="p_upload"> <span>Datei/Beleg hochladen</span>
      </label>
      <label style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="p_comment"> <span>Kommentar hinterlassen</span>
      </label>

      <label for="exp">Ablauf (Tage, optional)</label>
      <input type="number" name="expires_days" id="exp" min="1" placeholder="z. B. 7">

      <div class="col-2"><button class="btn" type="submit">Einladungslink erstellen</button></div>
    </form>
  </div>

  <?php if($linkRel && $linkAbs): ?>
    <div class="card">
      <h2>Einladungslink</h2>
      <p><strong>Absolut (an andere senden):</strong></p>
      <p><input type="text" value="<?= htmlspecialchars($linkAbs) ?>" readonly style="width:100%;"></p>

      <p style="margin-top:10px;"><strong>Relativ (nur intern nützlich):</strong></p>
      <p><input type="text" value="<?= htmlspecialchars($linkRel) ?>" readonly style="width:100%;"></p>

      <p style="font-size:12px;color:#6b7280;">Hinweis: Wenn du lokal arbeitest, ist der absolute Link <code>http://localhost/...</code>. Für externe Empfänger muss deine Seite öffentlich erreichbar sein (z. B. via echte Domain) und in <code>config.php</code> <code>SITE_URL</code> gesetzt werden.</p>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
