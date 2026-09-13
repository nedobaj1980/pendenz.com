<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
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
    --brand:#1d4ed8; --brand-2:#2563eb; --ring:rgba(37,99,235,.25);
    --shadow:0 6px 18px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.04);
    --shadow-lg:0 18px 40px rgba(15,23,42,.10), 0 6px 16px rgba(15,23,42,.06);
    --radius:18px;
  }
  html,body{background:var(--bg); color:var(--ink)}
  main{isolation:isolate}

  /* HERO */
  .hero{
    position:relative; overflow:hidden;
    padding:5rem 1rem 2.5rem;
    background:
      radial-gradient(70rem 36rem at 70% -10%, #dbeafe 0, transparent 60%),
      linear-gradient(180deg,#fff,#f8fafc);
    border-bottom:1px solid var(--border);
  }
  .hero__inner{max-width:1100px;margin:0 auto;display:grid;gap:1.1rem}
  .eyebrow{display:inline-block;font-size:.82rem;letter-spacing:.12em;text-transform:uppercase;color:var(--brand);font-weight:800;background:#eef2ff;border:1px solid #e5e7eb;padding:.22rem .55rem;border-radius:999px}
  h1.title{
    font-size:clamp(1.9rem,3.8vw,2.8rem); line-height:1.12; margin:.35rem 0 0 0;
    background:linear-gradient(90deg,#0f172a 0%, #1d4ed8 60%, #0ea5e9 100%);
    -webkit-background-clip:text; background-clip:text; color:transparent;
  }
  .sub{color:var(--muted);max-width:75ch;font-size:1.05rem}

  /* LAYOUT */
  .wrap{max-width:1100px;margin:0 auto;padding:2rem 1rem 4rem}
  .grid{display:grid;gap:1rem}
  @media(min-width:820px){ .grid-3{grid-template-columns:repeat(3,1fr)} .grid-2{grid-template-columns:repeat(2,1fr)} }

  /* CARDS */
  .card{background:rgba(255,255,255,.86);backdrop-filter:saturate(1.1) blur(6px);border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem;box-shadow:var(--shadow);transition:box-shadow .2s ease, transform .18s ease, border-color .2s ease}
  .card:hover{box-shadow:var(--shadow-lg);transform:translateY(-2px);border-color:#d8deea}
  .card h3{margin:.2rem 0 .45rem 0;font-size:1.15rem}
  .muted{color:var(--muted)}
  .badge{
    display:inline-flex;align-items:center;gap:.4rem;
    padding:.32rem .55rem;border:1px solid #dbeafe;border-radius:999px;
    background:#f0f7ff;color:#1e3a8a;font-weight:600;font-size:.82rem
  }

  /* TIMELINE */
  .timeline{position:relative;padding-left:1rem}
  .timeline::before{content:"";position:absolute;left:.45rem;top:.3rem;bottom:.3rem;width:2px;background:#e5e7eb;border-radius:2px}
  .t-item{position:relative;padding-left:1.25rem;margin:.75rem 0}
  .t-item::before{
    content:"";position:absolute;left:-.05rem;top:.25rem;width:.7rem;height:.7rem;border-radius:999px;
    background:linear-gradient(180deg,#1d4ed8,#0ea5e9);box-shadow:0 0 0 4px #e0f2fe
  }

  /* PEOPLE */
  .person{display:flex;gap:.9rem;align-items:flex-start}
  .avatar{
    width:56px;height:56px;border-radius:14px;flex:0 0 56px;
    background:linear-gradient(135deg,#93c5fd,#1e3a8a);box-shadow:inset 0 0 0 3px rgba(255,255,255,.7)
  }
  .person h4{margin:.1rem 0 .2rem 0}
  .person p{margin:0}

  /* CTA */
  .cta{display:flex;gap:.75rem;flex-wrap:wrap}
  .btn{
    display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1rem;border-radius:12px;border:1px solid #1e40af;background:linear-gradient(180deg,#1d4ed8,#1e3a8a);color:#fff;text-decoration:none;font-weight:600
  }
  .btn--ghost{background:#fff;color:#1d4ed8;border-color:#93c5fd;box-shadow:0 2px 10px rgba(2,6,23,.05)}
</style>

<main>

  <!-- Hero -->
  <section class="hero">
    <div class="hero__inner">
      <span class="eyebrow">Über uns</span>
      <h1 class="title">Warum es pendenz.com gibt – und wohin wir wollen</h1>
      <p class="sub">
        pendenz.com ist aus der Praxis des <strong>Baumanagements</strong> entstanden. Auf Baustellen zählt
        Übersicht, Tempo und sauberes Nachführen – bei vielen Beteiligten, Fristen und Anhängen.
        Deshalb legen wir auf dieses Feld ein <strong>besonders grosses Augenmerk</strong>.
        Gleichzeitig bauen wir pendenz.com als <strong>All-Situations-Tool</strong>:
        ein flexibles System, das seinem Namen gerecht wird – vom Mehrfamilienhaus bis zur Privatorganisation.
      </p>
      <div class="cta">
        <a class="btn" href="<?= h(page_url('projekte.php')) ?>">Projekt anlegen</a>
        <a class="btn btn--ghost" href="<?= h(page_url('pendenzen.php')) ?>">Pendenzen ansehen</a>
      </div>
    </div>
  </section>

  <div class="wrap">

    <!-- Mission / Fokus -->
    <section class="grid grid-3">
      <article class="card">
        <h3>🏗️ Herkunft: Baumanagement</h3>
        <p class="muted">
          Aus der Baupraxis für die Baupraxis: klare Status, saubere Ablage, schnelle Abstimmungen
          und ein Ablauf, der auf echten Baustellenstress getestet wurde.
        </p>
        <span class="badge">Bauprojekte · MFH · GU/TU</span>
      </article>
      <article class="card">
        <h3>🧩 Anspruch: All-Situations-Tool</h3>
        <p class="muted">
          pendenz.com soll überall funktionieren: im Betrieb, beim Garagisten,
          in Vereinen – und privat. Flexibel, aber ohne Overhead.
        </p>
        <span class="badge">Projekte · Objekte · Pendenzen</span>
      </article>
      <article class="card">
        <h3>🛡️ Klarheit & Nachweis</h3>
        <p class="muted">
          Vom „gesendet“ bis „bestätigt“ – jeder Schritt nachvollziehbar.
          Anhänge, Fotos und Protokolle bleiben am richtigen Ort.
        </p>
        <span class="badge">Transparenz · Prüfschritt</span>
      </article>
    </section>

    <!-- Timeline: wie wir arbeiten -->
    <section style="margin-top:1.5rem" class="grid grid-2">
      <article class="card">
        <h3>So arbeiten wir</h3>
        <div class="timeline">
          <div class="t-item">
            <strong>1. Praxis vor Theorie</strong>
            <div class="muted">Wir lösen echte Engpässe auf der Baustelle und am Schreibtisch – nicht nur im Whiteboard.</div>
          </div>
          <div class="t-item">
            <strong>2. Wenige Klicks</strong>
            <div class="muted">Navigation wie auf einer Homepage. Inline-Bearbeitung statt Formular-Wüsten.</div>
          </div>
          <div class="t-item">
            <strong>3. Flexibel bleiben</strong>
            <div class="muted">Kern schlank, Besonderes in <strong>JSON-Zusatzdaten</strong> – indizierbar und auswertbar.</div>
          </div>
          <div class="t-item">
            <strong>4. Beweise sichern</strong>
            <div class="muted">Anhänge & Statusübergänge sind revisionsfreundlich dokumentiert.</div>
          </div>
        </div>
      </article>

      <!-- Mini-Team / Verantwortung -->
      <article class="card">
        <h3>Wer dahinter steht</h3>

        <div class="person">
          <div class="avatar" aria-hidden="true"></div>
          <div>
            <h4>Nedim Bajramovski</h4>
            <p class="muted">Architekt & Projektleitung. Fokus: Baumanagement, Prozesse, Nutzererlebnis.</p>
          </div>
        </div>

        <div class="person" style="margin-top:.9rem">
          <div class="avatar" aria-hidden="true" style="background:linear-gradient(135deg,#a7f3d0,#065f46)"></div>
          <div>
            <h4>Produkt & Technik</h4>
            <p class="muted">Struktur, Datenmodell und Umsetzung – damit pendenz.com schnell, klar und erweiterbar bleibt.</p>
          </div>
        </div>

      </article>
    </section>

    <!-- Werte -->
    <section style="margin-top:1.5rem" class="grid grid-3">
      <article class="card">
        <h3>🔁 Wiederverwendbare Vorlagen</h3>
        <p class="muted">Projekt- und objektbezogene Pendenz-Vorlagen sparen Zeit und verhindern Doppeleinträge.</p>
      </article>
      <article class="card">
        <h3>🔔 Erinnerungen</h3>
        <p class="muted">Automatische Hinweise, bevor Fristen kippen; optional zweite Stufe als Mahnung.</p>
      </article>
      <article class="card">
        <h3>📎 Saubere Ablage</h3>
        <p class="muted">Dateien, Bilder, Pläne und Notizen bleiben am Vorgang – schnell auffindbar.</p>
      </article>
    </section>

    <!-- CTA -->
    <section style="margin-top:1.75rem;text-align:center">
      <a class="btn" href="<?= h(url('register.php')) ?>">Jetzt kostenlos starten</a>
      <a class="btn btn--ghost" href="<?= h(page_url('idee.php')) ?>">Die Idee lesen</a>
    </section>

  </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
