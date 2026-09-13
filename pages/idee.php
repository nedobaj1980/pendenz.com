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


<!-- Zusatz-Styles für die Politur -->
<style>
  :root{
    --bg: #f6f8fc;
    --card: #ffffff;
    --border: #e6eaf2;
    --ink: #0f172a;
    --muted: #475569;
    --brand: #1d4ed8;
    --brand-2: #2563eb;
    --ring: rgba(37,99,235,.25);
    --shadow: 0 6px 18px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.04);
    --shadow-lg: 0 18px 40px rgba(15,23,42,.10), 0 6px 16px rgba(15,23,42,.06);
    --radius: 18px;
  }
  html,body{background:var(--bg); color:var(--ink)}
  main{isolation:isolate}

  /* HERO */
  .idea-hero{
    position:relative; overflow:hidden;
    padding:5.5rem 1rem 3.5rem;
    background:
      radial-gradient(80rem 40rem at 70% -10%, #dbeafe 0, transparent 60%),
      linear-gradient(180deg,#fff, #f8fafc);
    border-bottom:1px solid var(--border);
  }
  .idea-hero::after{
    content:""; position:absolute; inset:auto -20% -40% -20%; height:60%;
    background:
      radial-gradient(60rem 30rem at 15% 110%, rgba(29,78,216,.10) 10%, transparent 60%),
      radial-gradient(50rem 26rem at 85% 120%, rgba(59,130,246,.10) 15%, transparent 60%);
    filter: blur(30px);
    pointer-events:none;
  }
  .idea-hero__inner{max-width:1100px;margin:0 auto;display:grid;gap:1.1rem}
  .idea-eyebrow{
    display:inline-block; font-size:.82rem; letter-spacing:.12em; text-transform:uppercase;
    color:var(--brand); font-weight:800; background:#eef2ff; border:1px solid #e5e7eb;
    padding:.22rem .55rem; border-radius:999px;
  }
  .idea-title{
    font-size:clamp(1.9rem,3.8vw,2.8rem); line-height:1.12; margin:.35rem 0 0 0;
    background:linear-gradient(90deg,#0f172a 0%, #1d4ed8 60%, #0ea5e9 100%);
    -webkit-background-clip:text; background-clip:text; color:transparent;
  }
  .idea-sub{color:var(--muted);max-width:70ch; font-size:1.05rem}

  /* Buttons */
  .idea-cta{display:flex;gap:.8rem;flex-wrap:wrap;margin-top:.4rem}
  .btn{
    display:inline-flex; align-items:center; gap:.55rem;
    padding:.7rem 1rem; border-radius:12px; border:1px solid #1e40af;
    background:linear-gradient(180deg, #1d4ed8, #1e3a8a);
    color:#fff; text-decoration:none; font-weight:600;
    box-shadow: var(--shadow); transform: translateZ(0);
    transition: box-shadow .2s ease, transform .18s ease, filter .2s ease;
  }
  .btn:hover{ box-shadow: var(--shadow-lg); transform: translateY(-1px) }
  .btn--ghost{
    background:#fff; color:var(--brand); border-color:#93c5fd;
    box-shadow:0 2px 10px rgba(2,6,23,.05);
  }
  .btn--ghost:hover{ filter: brightness(1.03) }

  /* LAYOUT & CARDS */
  .idea-wrap{max-width:1100px;margin:0 auto;padding:2rem 1rem 4rem}
  .grid{display:grid;gap:1rem}
  @media(min-width:780px){ .grid-3{grid-template-columns:repeat(3,1fr)} .grid-2{grid-template-columns:repeat(2,1fr)} }

  .card{
    background: rgba(255,255,255,.82);
    backdrop-filter: saturate(1.1) blur(6px);
    border:1px solid var(--border); border-radius:var(--radius);
    padding:1.05rem; box-shadow: var(--shadow);
    transition: box-shadow .2s ease, transform .18s ease, border-color .2s ease;
  }
  .card:hover{ box-shadow: var(--shadow-lg); transform: translateY(-2px); border-color:#d8deea }
  .card h3{margin:.15rem 0 .4rem 0; font-size:1.1rem}

  .muted{color:var(--muted)}
  .kbd{
    font-family:ui-monospace,Menlo,Monaco,"Liberation Mono","Courier New",monospace;
    background:#f1f5f9;border:1px solid #e2e8f0;border-radius:.5rem;padding:.1rem .4rem
  }

  /* TIMELINE */
  .timeline{position:relative;padding-left:1rem}
  .timeline::before{content:"";position:absolute;left:.45rem;top:.3rem;bottom:.3rem;width:2px;background:#e5e7eb;border-radius:2px}
  .t-item{position:relative;padding-left:1.25rem;margin:.75rem 0}
  .t-item::before{
    content:"";position:absolute;left:-.05rem;top:.25rem;width:.7rem;height:.7rem;border-radius:999px;
    background:linear-gradient(180deg,#1d4ed8,#0ea5e9);box-shadow:0 0 0 4px #e0f2fe
  }

  /* BADGES */
  .badges{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.6rem}
  .badge{
    display:inline-flex;align-items:center;gap:.4rem;
    padding:.35rem .55rem;border:1px solid #dbeafe;border-radius:999px;
    background:#f0f7ff;color:#1e3a8a;font-weight:600;font-size:.85rem
  }

  /* NOTE / FAQ */
  .note{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:.9rem;padding:.85rem}
  .faq dt{font-weight:700;margin-top:.75rem}
  .faq dd{margin:0 0 .75rem 0;color:#475569}
</style>

<main>

  <!-- Hero -->
  <section class="idea-hero">
    <div class="idea-hero__inner">
      <span class="idea-eyebrow">Die Idee hinter pendenz.com</span>
      <h1 class="idea-title">Aus „später“ wird „erledigt“ – mit System, Tempo und Überblick.</h1>
      <p class="idea-sub">
        pendenz.com ist dein schlankes Arbeitsbrett für Projekte mit vielen Beteiligten:
        vom Einfamilienhaus bis zum Mehrfamilienhaus, vom Garagisten bis zum Privatbudget.
        Ohne Tool-Zirkus, ohne Klick-Labyrinth – dafür mit klaren Status, smarten Vorlagen
        und einer flexiblen Datenstruktur, die sich deinem Alltag anpasst.
      </p>

      <!-- hübsche Badges -->
      <div class="badges">
        <span class="badge">⚡ Schnell starten</span>
        <span class="badge">🧩 Flexible Daten</span>
        <span class="badge">🔔 Erinnerungen</span>
        <span class="badge">📎 Anhänge &amp; Pläne</span>
      </div>

      <div class="idea-cta">
        <a class="btn" href="<?= h(url('index_public.php')) ?>">Jetzt entdecken</a>
        <a class="btn btn--ghost" href="<?= h(page_url('projekte.php')) ?>">Projekte ansehen</a>
      </div>
    </div>
  </section>

  <div class="idea-wrap">

    <!-- Problem -> Lösung -->
    <section class="grid grid-3">
      <article class="card">
        <h3>🧭 Das Problem</h3>
        <p class="muted">
          Aufgaben und E-Mails verstreuen sich über Ordner, Tabellen und Köpfe.
          Deadlines rutschen, Zuständigkeiten sind unklar, und jeder arbeitet „in seiner Datei“.
        </p>
      </article>
      <article class="card">
        <h3>🛠️ Die Lösung</h3>
        <p class="muted">
          Ein zentrales System für Pendenzen – <strong>pro Projekt</strong> und <strong>pro Objekt</strong> – mit klaren Status,
          Vorlagen, Erinnerungen und sauberer Ablage. Alles bleibt nachvollziehbar.
        </p>
      </article>
      <article class="card">
        <h3>✨ Das Besondere</h3>
        <p class="muted">
          Eine <strong>flexible JSON-Spalte</strong> für Zusatzdaten macht die Haupttabelle schlank und trotzdem erweiterbar.
          Heute Bauprojekt, morgen Werkstatt-Serviceplan – ohne Datenchaos.
        </p>
      </article>
    </section>

    <!-- Prinzipien -->
    <section style="margin-top:1.25rem" class="grid grid-3">
      <article class="card">
        <h3>Einfach</h3>
        <p class="muted">Wenige Klicks, klare Navigation, keine Pop-ups. Alles in einem Fenster wie auf einer Homepage.</p>
      </article>
      <article class="card">
        <h3>Flexibel</h3>
        <p class="muted">Projekte können komplex sein (mehrere MFH, mehrere Häuser, Kategorien je Branche). Die Struktur passt sich an.</p>
      </article>
      <article class="card">
        <h3>Schnell</h3>
        <p class="muted">Inline-Bearbeitung, Drag-&amp;-Drop-Sortierung, Sammel-Speichern. Keine Wartezeiten, kein „Seite neu laden“.</p>
      </article>
    </section>

    <!-- So funktioniert's -->
    <section style="margin-top:1.75rem" class="grid grid-2">
      <article class="card">
        <h3>So funktioniert’s – in 3 Ebenen</h3>
        <div class="timeline">
          <div class="t-item">
            <strong>1. Projekt</strong>
            <div class="muted">Z. B. „Kreuzlingerstrasse“ oder „VW-Service“. Hier definierst du Grunddaten, Kategorien und Vorlagen.</div>
          </div>
          <div class="t-item">
            <strong>2. Objekte</strong>
            <div class="muted">Einfamilienhäuser, Wohnungen, Fahrzeuge, Räume … was immer zum Projekt gehört.</div>
          </div>
          <div class="t-item">
            <strong>3. Pendenzen</strong>
            <div class="muted">Konkrete Aufgaben mit Status <span class="kbd">gesendet → angesehen → in Bearbeitung → erledigt → bestätigt</span>.</div>
          </div>
        </div>
      </article>
      <article class="card">
        <h3>Rollen &amp; Kommunikation</h3>
        <ul class="muted">
          <li><strong>Superadmin</strong> – Konfiguration, Vorlagen, Benutzer &amp; Rechte.</li>
          <li><strong>Admin</strong> – Projekte steuern, prüfen &amp; bestätigen.</li>
          <li><strong>Benutzer</strong> – Aufgaben empfangen/erledigen; auch privat nutzbar.</li>
        </ul>
        <p class="muted">
          Kommunikation läuft über die eigene E-Mail-Adresse des Admins/Users –
          kein Spam, alle Nachweise im System hinterlegt.
        </p>
      </article>
    </section>

    <!-- Features -->
    <section style="margin-top:1.75rem" class="grid grid-3">
      <article class="card">
        <h3>Vorlagen pro Projekt &amp; Objekt</h3>
        <p class="muted">Wiederkehrende Pendenzen entstehen automatisch mit passenden Feldern. Kein Copy-Paste.</p>
      </article>
      <article class="card">
        <h3>Flexible Daten (JSON)</h3>
        <p class="muted">Spezialfelder je Branche? Einfach als JSON hinterlegen – sauber indizierbar, später auswertbar.</p>
      </article>
      <article class="card">
        <h3>Erinnerungen &amp; Mahnungen</h3>
        <p class="muted">Wenn Fristen kippen, erinnert das System automatisch; optional zweite Stufe als Mahnung.</p>
      </article>
      <article class="card">
        <h3>Offline-fähig gedacht</h3>
        <p class="muted">Daten können lokal gepflegt und später synchronisiert werden – ideal auf der Baustelle.</p>
      </article>
      <article class="card">
        <h3>Transparenz</h3>
        <p class="muted">Status-Übergänge sind nachvollziehbar: Unternehmer meldet „erledigt“, Admin prüft und bestätigt.</p>
      </article>
      <article class="card">
        <h3>Saubere Ablage</h3>
        <p class="muted">Anhänge, Fotos, Protokolle – alles beim Projekt/Objekt/Pendenz gespeichert und wiederauffindbar.</p>
      </article>
    </section>

    <!-- Roadmap -->
    <section style="margin-top:1.75rem" class="card">
      <h3>Roadmap (Auszug)</h3>
      <ul class="muted">
        <li><strong>Widget-Werkstatt:</strong> Baue dir visuelle Widgets und passe bei Bedarf den Code an.</li>
        <li><strong>Mindmap-Übersicht:</strong> Projekte, Objekte, Pendenzen und Beziehungen visuell verstehen.</li>
        <li><strong>App-Modus:</strong> Gleiche Oberfläche als Desktop-App &amp; optional online verfügbar.</li>
        <li><strong>Berichte &amp; PDF:</strong> Druckfertige Übersichten mit Logo &amp; Kopfzeile, inklusive Layer-Optionen.</li>
      </ul>
      <p class="note muted" style="margin-top:.5rem">
        Ziel: möglichst wenige Klicks, maximale Klarheit – für private und geschäftliche Nutzung.
      </p>
    </section>

    <!-- CTA -->
    <section style="margin-top:1.75rem;text-align:center">
      <a class="btn" href="<?= h(page_url('projekte.php')) ?>">Projekt anlegen</a>
      <a class="btn btn--ghost" href <?= '="' . h(page_url('pendenzen.php')) . '"' ?>>Pendenzen ansehen</a>
    </section>

  </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
