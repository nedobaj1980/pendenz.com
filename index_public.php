<?php
/* --- Basis laden --- */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';   // stellt url(), page_url(), asset_url(), brand_url(), h(), site_prefix() bereit
require_once __DIR__ . '/includes/header.php';      // bindet CSS/JS korrekt ein

// Optional auth-Helpers (schaden nicht, falls vorhanden)
$auth_path = __DIR__ . '/includes/auth.php';
if (file_exists($auth_path)) {
    require_once $auth_path;
}

/* --- Navigation abhängig vom Login-Status --- */
if (!empty($_SESSION['user_id'])) {
    // Eingeloggt → Rollen-Dispatch (lädt passende Nav_* automatisch)
    require_once __DIR__ . '/includes/nav_dispatch.php';
} else {
    // Öffentlich / nicht eingeloggt
    require_once __DIR__ . '/includes/nav_public.php';
}
?>
<style>
  :root{
    --bg:#ffffff; --ink:#0f172a; --ink-strong:#0b1930; --muted:#475569; --subtle:#64748b; --line:#e2e8f0;
    --ocean:#0ea5e9; --ocean-600:#0891b2; --ocean-700:#0369a1; --ocean-soft:#e0f2fe; --ocean-tint:#f0fbff; --mint:#22c55e;
    --card:#ffffff; --card-br:#e8eef6; --radius:20px; --shadow:0 10px 40px rgba(2,6,23,.08);
    --glass: rgba(255, 255, 255, 0.7);
  }
  html,body{background:var(--bg);color:var(--ink); font-family: 'Inter', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased;}
  a{color:var(--ocean-700);text-decoration:none; transition: all 0.2s ease;}
  a:hover{color:var(--ocean); text-decoration:none}

  .hero{position:relative;padding:80px 16px 48px;border-bottom:1px solid var(--line);
    background:
      radial-gradient(1200px 600px at 80% -20%, rgba(14,165,233,.15) 0, transparent 60%),
      radial-gradient(900px 420px at 10% -10%, rgba(2,132,199,.12) 0, transparent 60%),
      linear-gradient(180deg,#ffffff,#f8fbff);
    overflow: hidden;
  }
  .hero::before {
    content: ''; position: absolute; top: -50%; left: -20%; width: 140%; height: 140%;
    background: radial-gradient(circle at center, rgba(14,165,233, 0.03) 0%, transparent 70%);
    pointer-events: none;
  }
  .wrap{max-width:1140px;margin:0 auto; position: relative; z-index: 1;}
  .eyebrow{display:inline-block;font-size:.78rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#0369a1;background:rgba(125,211,252,.2);border:1px solid rgba(125,211,252,.4);padding:.3rem .7rem;border-radius:999px; margin-bottom: 1rem;}
  .title{font-size:clamp(2.2rem, 5vw, 3.5rem);line-height:1.05;margin:0 0 .5rem 0;color:var(--ink-strong); font-weight: 800; letter-spacing: -0.02em;}
  .lead{font-size: 1.15rem; color:var(--muted);max-width:68ch; line-height: 1.6; margin-bottom: 1.5rem;}
  .cta{display:flex;gap:.8rem;flex-wrap:wrap;margin-top:1.2rem}
  .btn{display:inline-flex;align-items:center;gap:.6rem;padding:.85rem 1.5rem;border-radius:14px;border:none;background:linear-gradient(135deg,var(--ocean),var(--ocean-700));color:#fff;text-decoration:none;box-shadow: 0 4px 14px rgba(14, 165, 233, 0.4); font-weight: 600; transition: transform 0.2s ease, box-shadow 0.2s ease;}
  .btn:hover{transform: translateY(-2px); box-shadow: 0 6px 20px rgba(14, 165, 233, 0.5); filter: brightness(1.05);}
  .btn--ghost{background: #fff; color:var(--ocean-700); border: 1px solid #bfe8fb; box-shadow: 0 4px 12px rgba(0,0,0,0.03);}
  .btn--ghost:hover{background: var(--ocean-tint); border-color: var(--ocean); color: var(--ocean); transform: translateY(-2px);}

  section{padding:60px 16px}
  .grid{display:grid;gap:20px}
  @media(min-width:880px){.grid-3{grid-template-columns:repeat(3,1fr)}.grid-2{grid-template-columns:repeat(2,1fr)}.grid-4{grid-template-columns:repeat(4,1fr)}}
  .card{background:var(--card);border:1px solid var(--card-br);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow); transition: transform 0.3s ease, border-color 0.3s ease;}
  .card:hover{transform: translateY(-4px); border-color: var(--ocean-600);}
  .card h3{margin:0 0 .5rem 0;color:var(--ink-strong); font-size: 1.4rem; font-weight: 700;}
  .muted{color:var(--muted); line-height: 1.55;}
  .small{font-size:.92rem}
  .chip-row{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:2.5rem}
  .chip{display:inline-flex;align-items:center;gap:.5rem;padding:.45rem .8rem;border-radius:999px;border:1px solid #cfe9f8;background: rgba(255,255,255,0.8); backdrop-filter: blur(8px); color:#0b1930;font-size:.9rem; font-weight: 500; box-shadow: 0 2px 8px rgba(0,0,0,0.02);}

  .flow{padding:32px;background:linear-gradient(180deg,#ffffff,#f6fbff);border:1px solid var(--card-br);border-radius:24px;box-shadow:var(--shadow); margin-top: 2rem;}
  .flow svg{width:100%;height:auto;display:block}

  .table-card{overflow:auto; padding: 28px; border-top: 4px solid var(--ocean); position: relative; z-index: 10; transition: transform 0.3s ease, z-index 0.3s ease;}
  .table-card:hover{ z-index: 20; transform: translateY(-5px); }
  @media(min-width: 1000px) { .table-card { margin-right: -50px; } }
  table.clean{width:100%;border-collapse:separate;border-spacing:0;min-width:720px;border:1px solid var(--card-br);border-radius:18px;overflow:hidden; background: #fff;}
  table.clean thead th{text-align:left;background:#f8fbff;color:var(--muted);font-weight:700;padding:1.1rem 1rem;border-bottom:1px solid var(--card-br); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em;}
  table.clean tbody td{padding:1.1rem 1rem;border-bottom:1px solid #f1f5f9;color:var(--ink); font-size: 0.95rem; vertical-align: middle;}
  table.clean tbody tr:last-child td{border-bottom: none;}
  table.clean tbody tr:hover{background:#fcfdfe}
  
  .pill{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .8rem;border-radius:10px;font-size:.82rem;border:1px solid #e2e8f0;background:#f8fafc;color:var(--muted); font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.02);}
  .status-pill{ min-width: 90px; justify-content: center; border-radius: 999px; }
  .status-open{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
  .status-work{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
  .status-done{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
  .badge{display:inline-flex;gap:.35rem;align-items:center;padding:.3rem .7rem;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:10px;font-size:.8rem;color:#475569; font-weight: 600;}

  .plan{display:grid;grid-template-columns:1.2fr .8fr;gap:20px; margin-top: 2rem;}
  .plan .canvas{background:#fff;border:1px solid var(--card-br);border-radius:20px;padding:16px;box-shadow:var(--shadow)}
  .legend{background: rgba(255,255,255,0.8); backdrop-filter: blur(10px); border: 1px solid var(--card-br); border-radius:20px;padding:20px;box-shadow:var(--shadow)}
  .legend .row{display:flex;align-items:center;gap:.7rem;margin:.5rem 0}
  .dot{width:12px;height:12px;border-radius:999px; box-shadow: 0 0 0 3px rgba(255,255,255,1), 0 0 0 4px var(--line);}
  .dot.blue{background:#3b82f6}
  .dot.orange{background:#f59e0b}
  .dot.green{background:#22c55e}

  .ba{position:relative;border:1px solid var(--card-br);border-radius:16px;overflow:hidden;box-shadow:var(--shadow); height: 320px;}
  .ba .before, .ba .after{
    position: absolute; inset: 0; width: 100%; height: 100%;
    background-size: cover; background-position: center;
  }
  .ba .after{
    background-image: url('<?= h(asset_url('img/after_digital.png')) ?>');
    background-color: #ffffff;
    z-index: 1;
  }
  .ba .before{
    background-image: url('<?= h(asset_url('img/before_handwritten.png')) ?>');
    background-color: #f1f5f9;
    z-index: 2;
    clip-path: inset(0 50% 0 0);
  }
  .ba-label{
    position: absolute; bottom: 16px; padding: 8px 16px; border-radius: 12px;
    font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;
    backdrop-filter: blur(12px); z-index: 10; pointer-events: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    border: 1px solid rgba(255,255,255,0.2);
  }
  .ba-label--before{ left: 16px; background: rgba(15, 23, 42, 0.7); color: #fff; }
  .ba-label--after{ right: 16px; background: rgba(14, 165, 233, 0.85); color: #fff; }

  .ba-handle {
    position: absolute; top: 0; bottom: 0; left: 50%; width: 4px; background: #fff;
    transform: translateX(-50%); z-index: 10; pointer-events: none;
    box-shadow: 0 0 20px rgba(0,0,0,0.2);
  }
  .ba-handle::after {
    content: ''; position: absolute; top: 50%; left: 50%; width: 44px; height: 44px;
    background: #fff; border-radius: 999px;
    display: flex; align-items: center; justify-content: center;
    transform: translate(-50%, -50%);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%230ea5e9' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='15 18 9 12 15 6'%3E%3C/polyline%3E%3Cpolyline points='9 18 15 12 9 6'%3E%3C/polyline%3E%3C/svg%3E");
    background-position: center; background-repeat: no-repeat; background-size: 24px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
  }

  .ba input[type="range"]{
    position:absolute; inset:0; width:100%; height:100%; opacity:0; cursor:ew-resize; z-index: 50;
  }

  .plan-container{display:grid; gap:20px; margin-top: 2rem;}
  @media(min-width:1000px){ .plan-container{ grid-template-columns: 1fr 1fr; } }
  
  .preview-card{ background: #fff; border: 1px solid var(--card-br); border-radius: 20px; overflow: hidden; box-shadow: var(--shadow); display: flex; flex-direction: column; }
  .preview-header{ padding: 12px 20px; background: #f8fafc; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; }
  .preview-title{ font-size: 0.9rem; font-weight: 700; color: var(--ink-strong); margin: 0; }
  .preview-body{ padding: 16px; flex: 1; position: relative; min-height: 240px; }

  /* Gantt Lite Preview */
  .gantt-preview{ display: grid; grid-template-columns: 140px 1fr; gap: 0; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: #fff; height: 100%; }
  .gantt-list{ background: #f8fafc; border-right: 1px solid var(--line); padding: 8px 0; }
  .gantt-row-label{ height: 32px; padding: 0 10px; font-size: 11px; font-weight: 600; color: var(--muted); border-bottom: 1px solid var(--line); display: flex; align-items: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .gantt-grid{ background: #fff; position: relative; background-image: linear-gradient(90deg, #f1f5f9 1px, transparent 1px); background-size: 40px 100%; }
  .gantt-bar{ position: absolute; height: 18px; border-radius: 4px; background: var(--ocean); box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; align-items: center; padding: 0 8px; font-size: 9px; color: #fff; font-weight: 700; z-index: 2; transition: transform 0.2s; }
  .gantt-bar:hover{ transform: scale(1.02); }
  .gantt-bar--orange{ background: #f59e0b; }
  .gantt-bar--green{ background: #22c55e; }

  /* PDF Preview Card */
  .pdf-preview-card{ background: #fff; border: 1px solid var(--card-br); border-radius: 20px; overflow: hidden; box-shadow: var(--shadow); height: 100%; border-top: 4px solid #ef4444; position: relative; z-index: 5; transition: transform 0.3s ease, z-index 0.3s ease; }
  .pdf-preview-card:hover{ z-index: 20; transform: translateY(-5px); }
  .pdf-content{ padding: 20px; text-align: center; background: #f8fafc; height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; position: relative;}
  .pdf-wrapper{ position: relative; width: 85%; transition: transform 0.3s ease; }
  .pdf-image{ width: 100%; height: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border-radius: 4px; border: 1px solid #ddd; transform: rotate(-2deg); }
  .pdf-wrapper:hover{ transform: rotate(0) scale(1.05); }
  .pdf-wrapper:hover .pdf-image{ transform: rotate(0); }
  .export-btns{ display: flex; gap: 10px; margin-top: 25px; }
  .export-btn{ padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; background: #fff; color: var(--muted); cursor: pointer; transition: all 0.2s; }
  .export-btn:hover{ background: #f1f5f9; border-color: var(--ocean); color: var(--ocean); }

  @keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.4); opacity: 0.5; }
    100% { transform: scale(1); opacity: 1; }
  }
  .pin-pulse { animation: pulse 2s infinite ease-in-out; transform-origin: center; }
  
  .soft{background:linear-gradient(180deg,#ffffff,#f6fbff);border-top:1px solid var(--line);border-bottom:1px solid var(--line)}
  .cta-center{text-align:center;padding:60px 16px 100px; background: radial-gradient(circle at center, #f0fbff 0%, #fff 70%);}
  h2.section-title { font-size: 2rem; font-weight: 800; color: var(--ink-strong); margin-bottom: 0.5rem; letter-spacing: -0.01em; }

</style>

<main>
  <!-- HERO -->
  <header class="hero">
    <div class="wrap">
      <span class="eyebrow">Startklar in Minuten</span>
      <h1 class="title">Übersicht schaffen. Termine halten. Zusammenarbeit vereinfachen.</h1>
      <p class="lead">
        pendenz.com bringt Ordnung in Aufgaben, Absprachen und Fristen – von kleinen Teams bis zu Projekten mit vielen Beteiligten.
        Klare Listen, feste Zuständigkeiten und eine Ablage, die man wirklich wiederfindet.
      </p>
      <div class="cta">
        <a class="btn" href="<?= h(url('login.php')) ?>">Zum Login</a>
        <a class="btn btn--ghost" href="<?= h(page_url('ueber_uns.php')) ?>">Warum wir das bauen</a>
      </div>

      <div class="chip-row">
        <div class="chip">🔔 Erinnerungen ohne Extra-Tools</div>
        <div class="chip">📎 Dateien direkt an der Aufgabe</div>
        <div class="chip">✅ Nachvollziehbare Freigaben</div>
        <div class="chip">🏷️ Vorlagen pro Liste & Projekt</div>
      </div>
    </div>
  </header>

  <!-- Sofort-Nutzen -->
  <section>
    <div class="wrap grid grid-3">
      <article class="card">
        <h3>Weniger Nachfragen</h3>
        <p class="muted">Zuständigkeiten sind sichtbar, Statuswechsel nachvollziehbar. Wer dran ist, ist klar – ohne Mail-Pingpong.</p>
      </article>
      <article class="card">
        <h3>Saubere Ablage</h3>
        <p class="muted">Fotos, Pläne, Protokolle hängen direkt an der Pendenz. Suchen statt scrollen – und fertig.</p>
      </article>
      <article class="card">
        <h3>Termine im Blick</h3>
        <p class="muted">Fälligkeiten & Erinnerungen verhindern Ausrutscher. Du entscheidest, wie streng das System mahnt.</p>
      </article>
    </div>
  </section>

  <!-- SCHEMA -->
  <section class="soft">
    <div class="wrap">
      <h2 class="section-title">Wie pendenz.com funktioniert – auf einen Blick</h2>
      <p class="lead">Informationen kommen rein, werden sauber verarbeitet und nützlich ausgespielt. So bleibt der Überblick – ohne Tool-Zirkus.</p>

      <div class="flow" aria-label="Schematische Darstellung">
        <svg viewBox="0 0 1100 260" role="img">
          <defs>
            <linearGradient id="g1" x1="0" x2="1"><stop offset="0" stop-color="#bae6fd"/><stop offset="1" stop-color="#e0f2fe"/></linearGradient>
            <linearGradient id="g2" x1="0" x2="1"><stop offset="0" stop-color="#d9f99d"/><stop offset="1" stop-color="#ecfeff"/></linearGradient>
          </defs>
          <rect x="20" y="20" width="300" height="220" rx="14" fill="#ffffff" stroke="#cfe9f8"/>
          <text x="40" y="50" fill="#0b1930" font-weight="700" font-size="18">Inputs</text>
          <rect x="40" y="70"  width="240" height="34" rx="10" fill="url(#g1)" stroke="#cfe9f8"/><text x="55" y="92" fill="#0369a1" font-size="14">✉️ Mail-in Pendenz</text>
          <rect x="40" y="112" width="240" height="34" rx="10" fill="url(#g1)" stroke="#cfe9f8"/><text x="55" y="134" fill="#0369a1" font-size="14">🔗 Share/Quick-Capture</text>
          <rect x="40" y="154" width="240" height="34" rx="10" fill="url(#g1)" stroke="#cfe9f8"/><text x="55" y="176" fill="#0369a1" font-size="14">📱 QR am Objekt</text>
          <rect x="40" y="196" width="240" height="34" rx="10" fill="url(#g1)" stroke="#cfe9f8"/><text x="55" y="218" fill="#0369a1" font-size="14">🗂️ Vorlagen</text>
          <rect x="340" y="115" width="70" height="10" fill="#7dd3fc"/><polygon points="410,120 398,114 398,126" fill="#7dd3fc"/>
          <rect x="430" y="20" width="300" height="220" rx="14" fill="#ffffff" stroke="#cfe9f8"/>
          <text x="450" y="50" fill="#0b1930" font-weight="700" font-size="18">Engine</text>
          <rect x="450" y="70"  width="260" height="34" rx="10" fill="url(#g2)" stroke="#cfe9f8"/><text x="465" y="92" fill="#166534" font-size="14">⚙️ Statusfluss &amp; Audit-Trail</text>
          <rect x="450" y="112" width="260" height="34" rx="10" fill="url(#g2)" stroke="#cfe9f8"/><text x="465" y="134" fill="#166534" font-size="14">⏰ Erinnerungen &amp; Eskalation</text>
          <rect x="450" y="154" width="260" height="34" rx="10" fill="url(#g2)" stroke="#cfe9f8"/><text x="465" y="176" fill="#166534" font-size="14">🧩 Flexible Felder (JSON)</text>
          <rect x="450" y="196" width="260" height="34" rx="10" fill="url(#g2)" stroke="#cfe9f8"/><text x="465" y="218" fill="#166534" font-size="14">🧾 Checklisten &amp; Freigaben</text>
          <rect x="730" y="115" width="70" height="10" fill="#86efac"/><polygon points="800,120 788,114 788,126" fill="#86efac"/>
          <rect x="820" y="20" width="260" height="220" rx="14" fill="#ffffff" stroke="#cfe9f8"/>
          <text x="840" y="50" fill="#0b1930" font-weight="700" font-size="18">Outputs</text>
          <rect x="840" y="70"  width="220" height="34" rx="10" fill="#f8fdff" stroke="#cfe9f8"/><text x="855" y="92" fill="#0b1930" font-size="14">📣 Benachrichtigungen</text>
          <rect x="840" y="112" width="220" height="34" rx="10" fill="#f8fdff" stroke="#cfe9f8"/><text x="855" y="134" fill="#0b1930" font-size="14">🗓️ Kalender (iCal)</text>
          <rect x="840" y="154" width="220" height="34" rx="10" fill="#f8fdff" stroke="#cfe9f8"/><text x="855" y="176" fill="#0b1930" font-size="14">🔐 Smart-Links (Gast)</text>
          <rect x="840" y="196" width="220" height="34" rx="10" fill="#f8fdff" stroke="#cfe9f8"/><text x="855" y="218" fill="#0b1930" font-size="14">📄 PDF-Berichte</text>
        </svg>
      </div>
    </div>
  </section>

  <!-- Gestern vs Heute -->
  <section class="soft" style="padding: 80px 16px;">
    <div class="wrap" style="max-width: 900px;">
      <h2 class="section-title" style="text-align: center;">Vom Chaos zur Struktur</h2>
      <p class="lead" style="text-align: center; margin-bottom: 3rem;">Der Wechsel vom Zettel-Chaos zur strukturierten digitalen Liste spart Stunden an Nacharbeit.</p>
      
      <div class="ba" id="ba" style="height: 400px; border-radius: 24px;">
        <div class="before"></div>
        <div class="after" id="after"></div>
        <div class="ba-handle" id="handle"></div>
        <span class="ba-label ba-label--before">Gestern (Altmodisch)</span>
        <span class="ba-label ba-label--after">Heute (Effizient)</span>
        <input id="slider" type="range" min="0" max="100" value="50" aria-label="Vor/Nach Schieber">
      </div>
    </div>
  </section>

  <!-- Live-Vorschau & Berichte -->
  <section style="padding: 80px 16px;">
    <div class="wrap">
      <h2 class="section-title">Die digitale Zentrale</h2>
      <p class="lead">Alle Infos an einem Ort – und mit einem Klick dort, wo sie gebraucht werden.</p>
      
      <div class="grid grid-2" style="margin-top: 3rem; align-items: stretch;">
        <article class="card table-card">
          <div style="margin-bottom: 20px;">
            <h4 class="preview-title" style="font-size: 1.2rem; display: flex; align-items: center; gap: 10px;">
              <img src="<?= h(asset_url('img/logo_pendenz_official.png')) ?>" style="width: 30px; height: 30px; object-fit: contain;">
              Live-Datenbank
            </h4>
            <p class="muted small">Deine Pendenzen, sortiert und gefiltert nach deinen Wünschen.</p>
          </div>
          <div style="overflow-x: auto;">
            <table class="clean" role="table" aria-label="Musterliste Pendenzen">
              <thead>
                <tr>
                  <th>Aufgabe</th>
                  <th>Ort</th>
                  <th>Status</th>
                  <th>Wer?</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Kücheninsel Montage</td>
                  <td><strong>Küche</strong></td>
                  <td><span class="pill status-pill status-work">in Arbeit</span></td>
                  <td>Meier Schreiner</td>
                </tr>
                <tr>
                  <td>TV-Anschluss prüfen</td>
                  <td><strong>Wohnzimmer</strong></td>
                  <td><span class="pill status-pill status-open">offen</span></td>
                  <td>Keller Elektro</td>
                </tr>
                <tr>
                  <td>Fenster einstellen</td>
                  <td><strong>Schlafzimmer</strong></td>
                  <td><span class="pill status-pill status-done">erledigt</span></td>
                  <td>Team Fenster</td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="pdf-preview-card">
          <div class="preview-header">
            <h4 class="preview-title" style="display: flex; align-items: center; gap: 8px;">
              <img src="<?= h(asset_url('img/logo_pendenz_official.png')) ?>" style="width: 24px; height: 24px; object-fit: contain;">
              Baujournal & Berichte
            </h4>
            <span class="pill" style="font-size: 0.7rem; padding: 2px 8px; background: #fee2e2; color: #b91c1c; border-color: #fecaca;">Automatisierung</span>
          </div>
          <div class="pdf-content">
            <div class="pdf-wrapper">
              <img src="<?= h(asset_url('img/pdf_report_preview.png')) ?>" class="pdf-image" alt="Baujournal Vorschau">
            </div>
            
            <div class="export-btns">
              <button class="export-btn">📩 Email versenden</button>
              <button class="export-btn">📕 PDF exportieren</button>
              <button class="export-btn">🔗 Share-Link</button>
            </div>
            <p class="muted small" style="margin-top: 20px;">Versende professionelle Berichte direkt aus der App an Partner und Kunden.</p>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- Projekt-Steuerung -->
  <section class="soft">
    <div class="wrap">
      <h2 class="section-title">Alles im Griff: Der Ort & Die Zeit</h2>
      <p class="lead">Wir beantworten die zwei wichtigsten Fragen in jedem Projekt: **Wo** muss etwas getan werden und **Wann** findet es statt?</p>
      
      <div class="plan-container">
        <!-- Plan-Pinning Preview -->
        <article class="preview-card">
          <div class="preview-header">
            <h4 class="preview-title">📍 DER ORT (Räumliche Planung)</h4>
            <span class="pill" style="font-size: 0.7rem; padding: 2px 8px; background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe;">WO?</span>
          </div>
          <div class="preview-body" style="padding: 0;">
            <div style="position: relative; height: 300px; background: #f1f5f9;">
              <svg viewBox="0 0 640 360" role="img" aria-label="Plan mit Pins" style="width:100%; height:100%; display:block;">
                <image href="<?= h(asset_url('img/floor_plan_preview.png')) ?>" x="0" y="0" width="640" height="360" preserveAspectRatio="xMidYMid slice" opacity="0.8" />
                <rect x="0" y="0" width="640" height="360" fill="rgba(255,255,255,0.1)" />
                
                <g class="pin-pulse"><circle cx="150" cy="120" r="10" fill="#3b82f6" stroke="#fff" stroke-width="2"/></g>
                <text x="165" y="125" fill="#0b1930" font-size="14" font-weight="800" style="paint-order: stroke; stroke: #fff; stroke-width: 3px;">Küche</text>
                
                <g class="pin-pulse" style="animation-delay: 0.5s;"><circle cx="320" cy="230" r="10" fill="#f59e0b" stroke="#fff" stroke-width="2"/></g>
                <text x="335" y="235" fill="#0b1930" font-size="14" font-weight="800" style="paint-order: stroke; stroke: #fff; stroke-width: 3px;">Wohnzimmer</text>
                
                <g class="pin-pulse" style="animation-delay: 1s;"><circle cx="500" cy="90" r="10" fill="#22c55e" stroke="#fff" stroke-width="2"/></g>
                <text x="515" y="95" fill="#0b1930" font-size="14" font-weight="800" style="paint-order: stroke; stroke: #fff; stroke-width: 3px;">Schlafzimmer</text>
              </svg>
            </div>
          </div>
        </article>

        <!-- Terminprogramm Preview -->
        <article class="preview-card">
          <div class="preview-header">
            <h4 class="preview-title">📅 DIE ZEIT (Terminplan)</h4>
            <span class="pill" style="font-size: 0.7rem; padding: 2px 8px; background: #ecfdf5; color: #166534; border-color: #bbf7d0;">WANN?</span>
          </div>
          <div class="preview-body">
            <p class="muted small" style="margin-bottom: 12px;">Fristen und Abhängigkeiten im Gantt-Chart steuern. Termine einhalten, bevor es brennt.</p>
            <div class="gantt-preview">
              <div class="gantt-list">
                <div class="gantt-row-label">Aushub & Boden</div>
                <div class="gantt-row-label">Rohbau EG</div>
                <div class="gantt-row-label">Dachstuhl</div>
                <div class="gantt-row-label">Innenausbau</div>
                <div class="gantt-row-label">Abnahme</div>
              </div>
              <div class="gantt-grid">
                <div class="gantt-bar" style="top: 7px; left: 10px; width: 80px;">Phase 1</div>
                <div class="gantt-bar gantt-bar--orange" style="top: 39px; left: 90px; width: 120px;">Bauphase</div>
                <div class="gantt-bar" style="top: 71px; left: 210px; width: 60px;">Deckung</div>
                <div class="gantt-bar gantt-bar--green" style="top: 103px; left: 150px; width: 180px;">Ausbau</div>
                <div class="gantt-bar" style="top: 135px; left: 330px; width: 40px;">Final</div>
              </div>
            </div>
            <div style="margin-top: 12px; display: flex; gap: 8px;">
               <span class="badge">Meilensteine</span>
               <span class="badge">Kritischer Pfad</span>
               <span class="badge">Abhängigkeiten</span>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- Abschluss-CTA -->
  <section class="cta-center">
    <div class="wrap">
      <h3 style="margin:.2rem 0 .6rem 0; color:var(--ink-strong)">Lust auf Übersicht statt Aufwand?</h3>
      <div class="cta" style="justify-content:center">
        <a class="btn" href="<?= h(url('login.php')) ?>">Login</a>
        <a class="btn btn--ghost" href="<?= h(page_url('kontakt.php')) ?>">Fragen? Schreib uns</a>
      </div>
    </div>
  </section>
</main>

<script>
  // Vor/Nach Slider Logik
  (function(){
    const slider = document.getElementById('slider');
    const before = document.querySelector('.ba .before');
    const handle = document.getElementById('handle');
    if(!slider || !before || !handle) return;
    
    const update = () => {
      const pct = Math.max(0, Math.min(100, parseInt(slider.value, 10) || 0));
      const clipValue = `inset(0 ${100 - pct}% 0 0)`;
      before.style.webkitClipPath = clipValue;
      before.style.clipPath = clipValue;
      handle.style.left = pct + '%';
    };
    
    slider.addEventListener('input', update);
    slider.addEventListener('change', update);
    update();
  })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
