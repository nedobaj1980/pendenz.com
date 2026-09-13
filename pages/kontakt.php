<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';

$sent   = false;
$errors = [];
$csrf   = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate_request()) {
    $errors[] = "Ungültiges Sicherheits-Token.";
  } else {
    // Hier könnte die E-Mail-Logik rein
    $sent = true;
  }
}

require_once __DIR__ . '/../includes/header.php';

// Navigation abhängig vom Login-Status
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../includes/nav_dispatch.php';
} else {
    require_once __DIR__ . '/../includes/nav_public.php';
}

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
?>


<style>
  :root{
    --bg:#f6f8fc; --card:#fff; --border:#e6eaf2; --ink:#0f172a; --muted:#475569;
    --brand:#1d4ed8; --ring:rgba(37,99,235,.25);
    --shadow:0 6px 18px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.04);
    --radius:18px;
  }
  html,body{background:var(--bg); color:var(--ink)}
  .wrap{max-width:1000px;margin:0 auto;padding:2rem 1rem 3rem}
  .hero{padding:3.2rem 1rem 1.2rem;border-bottom:1px solid var(--border);background:linear-gradient(180deg,#fff,#f8fafc)}
  .hero__inner{max-width:1000px;margin:0 auto}
  .eyebrow{display:inline-block;font-size:.82rem;letter-spacing:.12em;text-transform:uppercase;color:var(--brand);font-weight:800;background:#eef2ff;border:1px solid #e5e7eb;padding:.22rem .55rem;border-radius:999px}
  h1{font-size:clamp(1.8rem,3.6vw,2.6rem);margin:.5rem 0 0 0}
  .muted{color:var(--muted)}
  .grid{display:grid;gap:1rem}
  @media(min-width:860px){ .grid-2{grid-template-columns:1fr 1fr} }

  .card{background:rgba(255,255,255,.9);backdrop-filter:saturate(1.05) blur(6px);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.1rem}
  .contact-block ul{list-style:none;margin:0;padding:0}
  .contact-block li{margin:.35rem 0}
  .contact-block a{text-decoration:none}

  form .row{display:grid;gap:.75rem}
  @media(min-width:600px){ form .row-2{grid-template-columns:1fr 1fr} }

  label{font-weight:600;font-size:.95rem}
  input[type="text"],input[type="email"],textarea{
    width:100%; padding:.7rem .8rem; border:1px solid #dbe1ea; border-radius:12px; background:#fff;
    transition:border-color .2s ease, box-shadow .2s ease; font-size:1rem;
  }
  input:focus,textarea:focus{outline:none;border-color:#93c5fd; box-shadow:0 0 0 4px var(--ring)}
  textarea{min-height:160px; resize:vertical}
  .btn{
    display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1rem;border-radius:12px;border:1px solid #1e40af;
    background:linear-gradient(180deg,#1d4ed8,#1e3a8a);color:#fff;text-decoration:none;font-weight:600
  }
  .hp{position:absolute;left:-5000px;opacity:0;height:0;width:0;overflow:hidden}
  .alert{border-radius:12px;padding:.85rem 1rem;margin:.8rem 0;font-weight:600}
  .alert--ok{background:#ecfdf5;border:1px solid #34d399;color:#065f46}
  .alert--err{background:#fff1f2;border:1px solid #fda4af;color:#9f1239}
  .map{border:0;width:100%;height:280px;border-radius:12px;filter:grayscale(.1) contrast(1.05)}
</style>

<main>

  <section class="hero">
    <div class="hero__inner">
      <span class="eyebrow">Kontakt</span>
      <h1>Wir freuen uns auf deine Nachricht</h1>
      <p class="muted">Fragen zu pendenz.com, Projektanfrage oder Feedback – schreib uns einfach.</p>
    </div>
  </section>

  <div class="wrap">
    <?php if ($sent): ?>
      <div class="alert alert--ok">Danke! Deine Nachricht wurde erfasst. Wir melden uns kurzfristig.</div>
    <?php elseif ($errors): ?>
      <div class="alert alert--err">
        <?= h(implode(' ', $errors)) ?>
      </div>
    <?php endif; ?>

    <section class="grid grid-2">
      <!-- Formular -->
      <article class="card">
        <form method="post" action="">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <!-- Honeypot -->
          <div class="hp">
            <label>Website</label>
            <input type="text" name="website" autocomplete="off">
          </div>

          <div class="row row-2">
            <div>
              <label for="name">Name</label>
              <input id="name" type="text" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
            </div>
            <div>
              <label for="email">E-Mail</label>
              <input id="email" type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
            </div>
          </div>

          <div class="row">
            <div>
              <label for="subject">Betreff</label>
              <input id="subject" type="text" name="subject" value="<?= h($_POST['subject'] ?? '') ?>" required>
            </div>
          </div>

          <div class="row">
            <div>
              <label for="message">Nachricht</label>
              <textarea id="message" name="message" required><?= h($_POST['message'] ?? '') ?></textarea>
            </div>
          </div>

          <div style="margin-top:.5rem">
            <button class="btn" type="submit">Nachricht senden</button>
          </div>
        </form>
      </article>

      <!-- Kontaktinfos & Map -->
      <article class="card contact-block">
        <h3>Helvetic Immo AG</h3>
        <ul class="muted">
          <li><strong>Adresse:</strong> Arbonerstrasse 32a, 8590 Romanshorn</li>
          <li><strong>Telefon:</strong> <a href="tel:+41788931065">078&nbsp;/&nbsp;893&nbsp;10&nbsp;65</a></li>
          <li><strong>E-Mail:</strong> <a href="mailto:info@helvetic-immo.ch">info@helvetic-immo.ch</a></li>
          <li><strong>Website:</strong> <a href="https://www.helvetic-immo.ch" target="_blank" rel="noopener">www.helvetic-immo.ch</a></li>
          <li><strong>UID/MWST:</strong> CHE-371.109.362</li>
          <li><strong>Bank:</strong> Thurgauer Kantonalbank Romanshorn, IBAN: CH93 0078 4296 9181 5200 1</li>
        </ul>

        <div style="margin-top:1rem">
          <!-- Simple eingebettete Karte (kannst du später durch echte Maps ersetzen) -->
          <iframe
            class="map"
            src="https://www.openstreetmap.org/export/embed.html?bbox=9.356%2C47.565%2C9.373%2C47.576&layer=mapnik&marker=47.570%2C9.365"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade">
          </iframe>
        </div>
      </article>
    </section>
  </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
