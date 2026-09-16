<?php
/**
 * index_superadmin.php
 * Schweizer PropTech Control Center & Portfolio-Cockpit
 * Helvetic Immo Treuhand
 */
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/auth.php";
require_login();
require_role(['superadmin']);

$nav_mode = $_COOKIE['nav-layout'] ?? 'top';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  die("DB-Verbindung nicht verfügbar: \$mysqli ist nicht gesetzt (config.php).");
}

/* ==== CSRF ==== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* ==== Helpers ==== */
if (!function_exists('safe')) {
  function safe($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
if (!function_exists('h')) {
  function h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
if (!function_exists('chf')) {
  function chf($num): string {
    return 'CHF ' . number_format((float)$num, 2, '.', "'");
  }
}

/* ==== Portfolio-Kennzahlen & Live-Aggregation ==== */
$metrics = [
  'projekte'            => 0,
  'wohnungen'           => 0,
  'mieter_aktiv'        => 0,
  'leerstand'           => 0,
  'belegung_pct'        => 0.0,
  'miete_netto'         => 0.0,
  'miete_nk'            => 0.0,
  'miete_brutto'        => 0.0,
  'miete_jahr'          => 0.0,
  'pendenzen_offen'     => 0,
  'pendenzen_hoch'      => 0,
  'pendenzen_ueberfaellig' => 0,
  'fs_count'            => 0,
  'fs_last'             => '—',
  'benutzer_count'      => 0,
  'konto_count'         => 0,
  'last_import'         => null
];

// 1. Projekte & Einheiten
if ($r = $mysqli->query("SELECT COUNT(*) FROM projekte")) {
  $metrics['projekte'] = (int)$r->fetch_row()[0];
}
if ($r = $mysqli->query("SELECT COUNT(*) FROM wohnungen")) {
  $metrics['wohnungen'] = (int)$r->fetch_row()[0];
}

// 2. Mieter & Mieteinnahmen (Soll-Miete aktiv)
$mSql = "SELECT COUNT(*), 
                COALESCE(SUM(mietzins_netto), 0), 
                COALESCE(SUM(nk_akonto), 0), 
                COALESCE(SUM(COALESCE(mietzins_netto, 0) + COALESCE(nk_akonto, 0)), 0) 
         FROM wohnung_mieter 
         WHERE status = 'aktiv'";
if ($r = $mysqli->query($mSql)) {
  $mRow = $r->fetch_row();
  $metrics['mieter_aktiv'] = (int)$mRow[0];
  $metrics['miete_netto']  = (float)$mRow[1];
  $metrics['miete_nk']     = (float)$mRow[2];
  $metrics['miete_brutto'] = (float)$mRow[3];
  $metrics['miete_jahr']   = $metrics['miete_brutto'] * 12;
  $metrics['leerstand']    = max(0, $metrics['wohnungen'] - $metrics['mieter_aktiv']);
  $metrics['belegung_pct'] = $metrics['wohnungen'] > 0 ? round(($metrics['mieter_aktiv'] / $metrics['wohnungen']) * 100, 1) : 0;
}

// 3. Pendenzen-Status
$pSql = "SELECT COUNT(*),
                COALESCE(SUM(CASE WHEN wichtigkeit >= 4 THEN 1 ELSE 0 END), 0),
                COALESCE(SUM(CASE WHEN enddatum IS NOT NULL AND enddatum < CURDATE() THEN 1 ELSE 0 END), 0)
         FROM pendenzen 
         WHERE (status IS NULL OR status NOT IN ('erledigt', 'archiviert')) 
           AND deleted_at IS NULL";
if ($r = $mysqli->query($pSql)) {
  $pRow = $r->fetch_row();
  $metrics['pendenzen_offen']      = (int)$pRow[0];
  $metrics['pendenzen_hoch']       = (int)$pRow[1];
  $metrics['pendenzen_ueberfaellig'] = (int)$pRow[2];
}

// 4. Drive-Dateien
if ($r = $mysqli->query("SELECT COUNT(*), MAX(mtime) FROM fs_nodes")) {
  $fRow = $r->fetch_row();
  $metrics['fs_count'] = (int)$fRow[0];
  $metrics['fs_last']  = !empty($fRow[1]) ? (string)$fRow[1] : '—';
}

// 5. Benutzer & Buchungen
if ($r = $mysqli->query("SELECT COUNT(*) FROM benutzer")) {
  $metrics['benutzer_count'] = (int)$r->fetch_row()[0];
}
if ($r = $mysqli->query("SELECT COUNT(*) FROM liegenschafts_konto")) {
  $metrics['konto_count'] = (int)$r->fetch_row()[0];
}
if ($r = $mysqli->query("SELECT import_dateiname, MAX(buchungsdatum) as zeit FROM liegenschafts_konto WHERE import_dateiname IS NOT NULL AND import_dateiname != '' GROUP BY import_dateiname ORDER BY id DESC LIMIT 1")) {
  $metrics['last_import'] = $r->fetch_assoc();
}

/* ==== Liegenschaften-Portfolio (Kompakte Zusammenfassung) ==== */
$portfolioList = [];
$projSql = "SELECT p.id, p.name, p.nummer,
    COUNT(DISTINCT w.id) as cnt_units,
    (SELECT COUNT(*) FROM wohnung_mieter wm2 
     JOIN wohnungen w2 ON wm2.wohnung_id = w2.id 
     JOIN objekte o2 ON w2.objekt_id = o2.id 
     WHERE o2.projekt_id = p.id AND wm2.status = 'aktiv') as cnt_mieter,
    (SELECT COALESCE(SUM(COALESCE(wm2.mietzins_netto,0) + COALESCE(wm2.nk_akonto,0)), 0) FROM wohnung_mieter wm2 
     JOIN wohnungen w2 ON wm2.wohnung_id = w2.id 
     JOIN objekte o2 ON w2.objekt_id = o2.id 
     WHERE o2.projekt_id = p.id AND wm2.status = 'aktiv') as soll_brutto,
    (SELECT COUNT(*) FROM pendenzen pend 
     WHERE pend.projekt_id = p.id AND (pend.status IS NULL OR pend.status NOT IN ('erledigt','archiviert')) AND pend.deleted_at IS NULL) as cnt_pendenzen
    FROM projekte p
    LEFT JOIN objekte o ON o.projekt_id = p.id
    LEFT JOIN wohnungen w ON w.objekt_id = o.id
    GROUP BY p.id
    ORDER BY p.name ASC";
if ($res = $mysqli->query($projSql)) {
  while ($row = $res->fetch_assoc()) {
    $portfolioList[] = $row;
  }
}

/* ==== Dringende Pendenzen laden (Neueste & Prioritäre) ==== */
$recentTodos = [];
$todoSql = "
  SELECT p.*, 
         b.name AS zustaendig_name, 
         pr.name AS projekt_name, 
         o.name AS objekt_name, 
         w.name AS wohnung_name, 
         img.pfad AS cover_pfad
  FROM pendenzen p 
  LEFT JOIN benutzer b ON b.id = p.zustaendig_id 
  LEFT JOIN projekte pr ON pr.id = p.projekt_id 
  LEFT JOIN objekte o ON o.id = p.objekt_id
  LEFT JOIN wohnungen w ON w.id = p.wohnung_id
  LEFT JOIN (SELECT pendenz_id, pfad FROM pendenz_dateien WHERE typ='image' AND is_cover=1 GROUP BY pendenz_id) img ON img.pendenz_id = p.id
  WHERE (p.status IS NULL OR p.status NOT IN ('erledigt', 'archiviert')) AND p.deleted_at IS NULL
  ORDER BY p.wichtigkeit DESC, p.enddatum ASC, p.id DESC 
  LIMIT 50";
if ($res = $mysqli->query($todoSql)) {
  while ($r = $res->fetch_assoc()) {
    $recentTodos[] = $r;
  }
}

include __DIR__ . "/includes/header.php";
include __DIR__ . "/includes/nav_superadmin.php";
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= safe(asset_url('dashboard.css')) ?>?v=<?= time() ?>">

<style>
  /* PropTech High-End Dashboard Styles */
  .sdash-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0b1329 100%);
    position: relative;
    border-bottom: 1px solid rgba(255,255,255,0.08);
  }
  .sdash-hero__badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    color: #60a5fa;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }
  .sdash-hero__cta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 24px;
  }
  .sdash-hero__cta .sdash-icon {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
  }
  .sdash-hero__btn-voice {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: #fff;
    border: 0;
    font-weight: 700;
    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.4);
    animation: pulseVoice 2.5s infinite;
  }
  @keyframes pulseVoice {
    0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); }
    70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
    100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
  }

  /* Executive KPI Cards */
  .sdash-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    margin: -36px auto 32px;
    max-width: 1800px;
    padding: 0 32px;
    position: relative;
    z-index: 10;
  }
  .sdash-kpi-card {
    background: var(--sd-surface);
    border: 1px solid var(--sd-border);
    border-radius: var(--sd-radius);
    padding: 22px;
    box-shadow: var(--sd-shadow);
    transition: transform 0.2s, box-shadow 0.2s;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .sdash-kpi-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--sd-shadow-lg);
    border-color: #cbd5e1;
  }
  .sdash-kpi-card__top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
  }
  .sdash-kpi-card__title {
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--sd-text-muted);
  }
  .sdash-kpi-card__icon {
    font-size: 1.5rem;
  }
  .sdash-kpi-card__val {
    font-size: 1.85rem;
    font-weight: 800;
    color: var(--sd-text);
    line-height: 1.1;
    margin-bottom: 6px;
  }
  .sdash-kpi-card__sub {
    font-size: 0.8rem;
    color: var(--sd-text-muted);
  }
  .sdash-kpi-card__badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    margin-top: 8px;
  }
  .badge-success { background: #dcfce7; color: #166534; }
  .badge-warning { background: #fef3c7; color: #92400e; }
  .badge-danger  { background: #fee2e2; color: #991b1b; }
  .badge-info    { background: #e0f2fe; color: #075985; }

  /* Verwalter-Kernmodule (Hub Cards) */
  .sdash-hub-section {
    max-width: 1800px;
    margin: 0 auto 36px;
    padding: 0 32px;
  }
  .sdash-hub-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
  }
  .sdash-hub-card {
    background: var(--sd-surface);
    border: 1px solid var(--sd-border);
    border-radius: var(--sd-radius-lg);
    padding: 24px;
    box-shadow: var(--sd-shadow);
    transition: all 0.25s ease;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
  }
  .sdash-hub-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: var(--hub-accent, #3b82f6);
    opacity: 0.8;
  }
  .sdash-hub-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--sd-shadow-lg);
    border-color: var(--hub-accent, #3b82f6);
  }
  .sdash-hub-card__icon {
    font-size: 2rem;
    margin-bottom: 12px;
  }
  .sdash-hub-card__title {
    font-size: 1.15rem;
    font-weight: 800;
    margin-bottom: 6px;
    color: var(--sd-text);
  }
  .sdash-hub-card__desc {
    font-size: 0.85rem;
    color: var(--sd-text-muted);
    line-height: 1.5;
    flex-grow: 1;
    margin-bottom: 14px;
  }
  .sdash-hub-card__action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.825rem;
    font-weight: 700;
    color: var(--hub-accent, #3b82f6);
  }

  /* Portfolio Grid */
  .sdash-portfolio-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 20px;
  }
  .sdash-port-card {
    background: var(--sd-surface);
    border: 1px solid var(--sd-border);
    border-radius: var(--sd-radius);
    padding: 20px;
    box-shadow: var(--sd-shadow-sm);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }
  .sdash-port-card:hover {
    border-color: #94a3b8;
    box-shadow: var(--sd-shadow);
  }
  .sdash-port-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
  }
  .sdash-port-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--sd-text);
    margin: 0;
  }
  .sdash-port-stats {
    display: flex;
    gap: 16px;
    background: #f8fafc;
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 14px;
    font-size: 0.85rem;
  }
  .sdash-port-stat-item {
    display: flex;
    flex-direction: column;
  }
  .sdash-port-stat-label {
    font-size: 0.725rem;
    color: var(--sd-text-muted);
    text-transform: uppercase;
  }
  .sdash-port-stat-val {
    font-weight: 700;
    color: var(--sd-text);
  }
  .sdash-port-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .sdash-port-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 5px 10px;
    background: #f1f5f9;
    color: #334155;
    border-radius: 6px;
    text-decoration: none;
    transition: 0.15s;
  }
  .sdash-port-chip:hover {
    background: #e2e8f0;
    color: #0f172a;
  }
  .sdash-port-chip--primary {
    background: #e0f2fe;
    color: #0369a1;
  }
  .sdash-port-chip--primary:hover {
    background: #bae6fd;
    color: #0284c7;
  }

  /* Status & Priority Badges */
  .prio-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
  }
  .prio-5 { background: #fee2e2; color: #b91c1c; }
  .prio-4 { background: #ffedd5; color: #c2410c; }
  .prio-3 { background: #f1f5f9; color: #475569; }
  .prio-1 { background: #f8fafc; color: #94a3b8; }

  /* Gimi Voice Modal */
  .voice-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(8px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
  .voice-modal {
    background: #fff;
    border-radius: 20px;
    max-width: 580px;
    width: 100%;
    padding: 28px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    position: relative;
    border: 1px solid #e2e8f0;
  }
  .voice-pulse-btn {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: #ef4444;
    color: #fff;
    border: 0;
    font-size: 32px;
    cursor: pointer;
    margin: 16px auto;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    transition: 0.2s;
  }
  .voice-pulse-btn.listening {
    animation: voiceRings 1.5s infinite;
  }
  @keyframes voiceRings {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
    70% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(239, 68, 68, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
  }

  /* iPad & Tablet Optimierung (max-width: 1024px) */
  @media (max-width: 1024px) {
    .sdash-hero {
      padding: 36px 20px !important;
      margin-bottom: 24px !important;
      border-radius: 0 0 16px 16px !important;
    }
    .sdash-hero__text h1 {
      font-size: 2.1rem !important;
    }
    .sdash-kpi-grid {
      grid-template-columns: repeat(2, 1fr) !important;
      padding: 0 16px !important;
      gap: 14px !important;
      margin-top: -24px !important;
    }
    .sdash-hub-section {
      padding: 0 16px !important;
      margin-bottom: 24px !important;
    }
    .sdash-hub-grid {
      grid-template-columns: repeat(2, 1fr) !important;
      gap: 14px !important;
    }
    .sdash-portfolio-grid {
      grid-template-columns: repeat(2, 1fr) !important;
      gap: 14px !important;
    }
  }

  /* Mobile Smartphone Optimierung (max-width: 768px) */
  @media (max-width: 768px) {
    .sdash-wrap {
      padding-bottom: 80px !important;
    }
    .sdash-hero {
      padding: 20px 14px 16px !important;
      margin-bottom: 14px !important;
    }
    .sdash-hero__text h1 {
      font-size: 1.45rem !important;
      line-height: 1.2 !important;
      margin-bottom: 6px !important;
    }
    .sdash-hero__text p {
      font-size: 0.85rem !important;
      margin-bottom: 12px !important;
      line-height: 1.4 !important;
    }
    .sdash-hero__badge {
      font-size: 0.68rem !important;
      padding: 3px 8px !important;
      margin-bottom: 8px !important;
    }
    /* CTA Buttons: 2-Spalten-Grid statt Spalte */
    .sdash-hero__cta {
      display: grid !important;
      grid-template-columns: 1fr 1fr !important;
      width: 100% !important;
      gap: 8px !important;
      margin-top: 12px !important;
    }
    .sdash-hero__cta button,
    .sdash-hero__cta a {
      width: 100% !important;
      text-align: center !important;
      justify-content: center !important;
      padding: 10px 12px !important;
      font-size: 0.82rem !important;
      border-radius: 10px !important;
      box-sizing: border-box !important;
    }
    /* Voice-Button über volle Breite */
    .sdash-hero__cta .sdash-hero__btn-voice {
      grid-column: 1 / -1 !important;
    }
    .sdash-hero__cta .sdash-icon {
      width: 14px !important;
      height: 14px !important;
    }
    .sdash-kpi-grid {
      grid-template-columns: 1fr 1fr !important;
      padding: 0 12px !important;
      gap: 10px !important;
      margin-top: -12px !important;
    }
    .sdash-kpi-card__val {
      font-size: 1.4rem !important;
    }
    .sdash-kpi-card {
      padding: 14px !important;
    }
    .sdash-hub-section {
      padding: 0 12px !important;
      margin-bottom: 20px !important;
    }
    .sdash-hub-grid {
      grid-template-columns: 1fr !important;
      gap: 10px !important;
    }
    .sdash-hub-card {
      padding: 16px !important;
    }
    .sdash-portfolio-grid {
      grid-template-columns: 1fr !important;
      gap: 12px !important;
    }
    .sdash-port-card {
      padding: 14px !important;
    }
    .sdash-port-stats {
      flex-wrap: wrap !important;
      gap: 10px !important;
      padding: 8px 10px !important;
    }
    .voice-modal {
      padding: 20px 16px !important;
      border-radius: 16px !important;
      margin: 10px !important;
      max-height: 92vh !important;
      overflow-y: auto !important;
    }
    .voice-pulse-btn {
      width: 64px !important;
      height: 64px !important;
      font-size: 28px !important;
      margin: 12px auto !important;
    }
  }
</style>

<div class="sdash-wrap" id="sdash-root" 
  data-endpoint-batch="<?= safe(base_url('api/batch_update.php')) ?>"
  data-endpoint-upload="<?= safe(base_url('api/dashboard_upload.php')) ?>"
  data-endpoint-quick="<?= safe(base_url('api/quick_insert.php')) ?>" 
  data-endpoint-preview="<?= safe(base_url('api/pendenzen_preview.php')) ?>"
  data-endpoint-prefs="<?= safe(base_url('api/dashboard_prefs.php')) ?>"
  data-csrf="<?= safe($CSRF) ?>">

  <!-- HERO SECTION -->
  <header class="sdash-hero" role="region" aria-label="Dashboard Intro">
    <div class="sdash-hero__text">
      <div class="sdash-hero__badge">
        <span>🏛️ Helvetic Immo Treuhand</span>
        <span>•</span>
        <span>Swiss PropTech Control Center</span>
      </div>
      <h1>Liegenschafts-Cockpit</h1>
      <p>Willkommen, Nedim. Vollständige Übersicht über Dein Portfolio, Mieterspiegel, Finanzen, Google Drive und Pendenzen.</p>
      
      <div class="sdash-hero__cta">
        <button type="button" class="sdash-btn sdash-hero__btn-voice" onclick="openVoiceModal()">
          🎙️ Gimi Voice (Sprechen)
        </button>
        <a class="sdash-btn" href="<?= safe(page_url('pendenz_neu.php')) ?>">
          ➕ Neue Pendenz
        </a>
        <a class="sdash-btn sdash-btn--ghost" href="<?= safe(page_url('mieterspiegel.php')) ?>">
          📈 Mieterspiegel
        </a>
        <a class="sdash-btn sdash-btn--ghost" href="<?= safe(base_url('tools/liegenschaftsabrechnung/index.php')) ?>">
          📑 Abrechnung & Steuern
        </a>
        <a class="sdash-btn sdash-btn--ghost" href="<?= safe(page_url('files.php')) ?>">
          📁 Google Drive
        </a>
        <a class="sdash-btn sdash-btn--ghost" href="<?= safe(page_url('ai_assistant.php')) ?>">
          🤖 KI-Zentrale
        </a>
      </div>
    </div>
    <div class="sdash-hero__glow"></div>
  </header>

  <div class="sdash-banner" id="sdash-banner" role="alert"></div>

  <!-- EXECUTIVE KPI CARDS -->
  <section class="sdash-kpi-grid" aria-label="Executive Kennzahlen">
    <!-- Mietertrag -->
    <article class="sdash-kpi-card">
      <div class="sdash-kpi-card__top">
        <span class="sdash-kpi-card__title">Soll-Mietertrag / Monat</span>
        <span class="sdash-kpi-card__icon">💰</span>
      </div>
      <div class="sdash-kpi-card__val"><?= safe(chf($metrics['miete_brutto'])) ?></div>
      <div class="sdash-kpi-card__sub">
        Netto: <?= safe(chf($metrics['miete_netto'])) ?> · NK: <?= safe(chf($metrics['miete_nk'])) ?>
      </div>
      <div>
        <span class="sdash-kpi-card__badge badge-success">
          Hochrechnung Jahr: <?= safe(chf($metrics['miete_jahr'])) ?>
        </span>
      </div>
    </article>

    <!-- Bestand & Belegung -->
    <article class="sdash-kpi-card">
      <div class="sdash-kpi-card__top">
        <span class="sdash-kpi-card__title">Einheiten & Belegung</span>
        <span class="sdash-kpi-card__icon">🏘️</span>
      </div>
      <div class="sdash-kpi-card__val"><?= safe($metrics['wohnungen']) ?> Einheiten</div>
      <div class="sdash-kpi-card__sub">
        <?= safe($metrics['mieter_aktiv']) ?> belegt · <?= safe($metrics['leerstand']) ?> leerstehend
      </div>
      <div>
        <span class="sdash-kpi-card__badge <?= $metrics['belegung_pct'] >= 80 ? 'badge-success' : 'badge-warning' ?>">
          <?= safe($metrics['belegung_pct']) ?>% Belegungsquote
        </span>
      </div>
    </article>

    <!-- Liegenschaften -->
    <article class="sdash-kpi-card">
      <div class="sdash-kpi-card__top">
        <span class="sdash-kpi-card__title">Liegenschaften-Portfolio</span>
        <span class="sdash-kpi-card__icon">🏢</span>
      </div>
      <div class="sdash-kpi-card__val"><?= safe($metrics['projekte']) ?> Liegenschaften</div>
      <div class="sdash-kpi-card__sub">Alle Objekte aktiv verwaltet</div>
      <div>
        <span class="sdash-kpi-card__badge badge-info">100% Bereit & Strukturiert</span>
      </div>
    </article>

    <!-- Pendenzen -->
    <article class="sdash-kpi-card">
      <div class="sdash-kpi-card__top">
        <span class="sdash-kpi-card__title">Pendenzen & Aufgaben</span>
        <span class="sdash-kpi-card__icon">⚠️</span>
      </div>
      <div class="sdash-kpi-card__val"><?= safe($metrics['pendenzen_offen']) ?> Offen</div>
      <div class="sdash-kpi-card__sub">
        <?= safe($metrics['pendenzen_hoch']) ?> Dringend · <?= safe($metrics['pendenzen_ueberfaellig']) ?> Überfällig
      </div>
      <div>
        <span class="sdash-kpi-card__badge <?= $metrics['pendenzen_hoch'] > 0 ? 'badge-danger' : 'badge-success' ?>">
          <?= safe($metrics['pendenzen_hoch']) ?> hohe Priorität
        </span>
      </div>
    </article>

    <!-- Google Drive & Cloud -->
    <article class="sdash-kpi-card">
      <div class="sdash-kpi-card__top">
        <span class="sdash-kpi-card__title">Google Drive Ablage</span>
        <span class="sdash-kpi-card__icon">📁</span>
      </div>
      <div class="sdash-kpi-card__val"><?= safe($metrics['fs_count']) ?> Dateien</div>
      <div class="sdash-kpi-card__sub">Letzter Sync: <?= safe(substr($metrics['fs_last'], 0, 16)) ?></div>
      <div>
        <span class="sdash-kpi-card__badge badge-success">Drive-Mount Verbunden</span>
      </div>
    </article>
  </section>

  <!-- VERWALTER-HUB: DIE 5 KERNMODULE -->
  <section class="sdash-hub-section" aria-label="Kernmodule">
    <div style="margin-bottom: 16px;">
      <h2 style="font-size: 1.35rem; font-weight: 800; color: var(--sd-text); margin: 0 0 4px;">
        Verwalter-Kommandozentrale
      </h2>
      <p style="font-size: 0.9rem; color: var(--sd-text-muted); margin: 0;">
        Direkter Zugriff auf alle operativen Werkzeuge für Deine Liegenschaften
      </p>
    </div>

    <div class="sdash-hub-grid">
      <!-- 1. Mieterspiegel -->
      <a href="<?= safe(page_url('mieterspiegel.php')) ?>" class="sdash-hub-card" style="--hub-accent: #3b82f6;">
        <div class="sdash-hub-card__icon">📈</div>
        <div class="sdash-hub-card__title">Mieterspiegel & Mietzinse</div>
        <div class="sdash-hub-card__desc">
          Mieterstamm, Mieterwechsel, Mietzinsanpassung nach Schweizer Recht, CSV-Export und 1-Klick Drive-Sicherung.
        </div>
        <div class="sdash-hub-card__action">Mieterspiegel öffnen →</div>
      </a>

      <!-- 2. Liegenschaftsabrechnung -->
      <a href="<?= safe(base_url('tools/liegenschaftsabrechnung/index.php')) ?>" class="sdash-hub-card" style="--hub-accent: #10b981;">
        <div class="sdash-hub-card__icon">📑</div>
        <div class="sdash-hub-card__title">Liegenschaftsabrechnung & Steuern</div>
        <div class="sdash-hub-card__desc">
          Jahresabrechnung für Banken & Partner, Schweizer Steuerberechnung (10%/20% Pauschale vs. effektiv) und Bank-CSV-Import.
        </div>
        <div class="sdash-hub-card__action">Abrechnung öffnen →</div>
      </a>

      <!-- 3. Google Drive Explorer -->
      <a href="<?= safe(page_url('files.php')) ?>" class="sdash-hub-card" style="--hub-accent: #f59e0b;">
        <div class="sdash-hub-card__icon">📁</div>
        <div class="sdash-hub-card__title">Google Drive Explorer</div>
        <div class="sdash-hub-card__desc">
          Direkter Zugriff auf die Drive-Ablage, Verträge (04_Vertraege), Abnahmeprotokolle und 1-Klick Ordnersynchronisation.
        </div>
        <div class="sdash-hub-card__action">Drive Explorer starten →</div>
      </a>

      <!-- 4. Gimi Voice & KI-Assistent -->
      <a href="<?= safe(page_url('ai_assistant.php')) ?>" class="sdash-hub-card" style="--hub-accent: #8b5cf6;">
        <div class="sdash-hub-card__icon">🎙️</div>
        <div class="sdash-hub-card__title">Gimi Voice & KI-Zentrale</div>
        <div class="sdash-hub-card__desc">
          Mängel und Pendenzen direkt per Sprache diktieren, Mietrechtsauskünfte und automatisches NLP-Parsing.
        </div>
        <div class="sdash-hub-card__action">Gimi aufrufen →</div>
      </a>

      <!-- 5. Mietvertrag & Wohnungsabnahme -->
      <a href="<?= safe(page_url('abnahmen.php')) ?>" class="sdash-hub-card" style="--hub-accent: #ec4899;">
        <div class="sdash-hub-card__icon">📝</div>
        <div class="sdash-hub-card__title">Wohnungsabnahme & Verträge</div>
        <div class="sdash-hub-card__desc">
          Digitales 215-Punkte Abnahmeprotokoll mit Signatur, automatischer Mängelsync in Pendenzen und PDF-Vertragsgenerator.
        </div>
        <div class="sdash-hub-card__action">Abnahmen & Verträge →</div>
      </a>
    </div>
  </section>

  <!-- PORTFOLIO GRID: ALLE LIEGENSCHAFTEN AUF EINEN BLICK -->
  <section class="sdash-panel" style="margin-bottom: 32px;" role="region" aria-labelledby="h-portfolio">
    <header class="sdash-panel__header">
      <h3 class="sdash-panel__title" id="h-portfolio">
        🏢 Liegenschaften-Portfolio (<?= count($portfolioList) ?> Objekte)
      </h3>
      <a class="sdash-link" href="<?= safe(page_url('projekte.php')) ?>">Alle Liegenschaften verwalten →</a>
    </header>

    <div class="sdash-portfolio-grid">
      <?php foreach ($portfolioList as $proj): 
        $pid = (int)$proj['id'];
        $pName = $proj['name'];
        $units = (int)$proj['cnt_units'];
        $mieter = (int)$proj['cnt_mieter'];
        $brutto = (float)$proj['soll_brutto'];
        $pends = (int)$proj['cnt_pendenzen'];
      ?>
        <article class="sdash-port-card">
          <div class="sdash-port-header">
            <div>
              <span style="font-size:0.75rem; font-weight:800; color:#3b82f6; text-transform:uppercase;">ID <?= $pid ?></span>
              <h4 class="sdash-port-title"><?= safe($pName) ?></h4>
            </div>
            <?php if ($pends > 0): ?>
              <span class="sdash-kpi-card__badge badge-danger"><?= $pends ?> Pendenzen</span>
            <?php else: ?>
              <span class="sdash-kpi-card__badge badge-success">0 Pendenzen</span>
            <?php endif; ?>
          </div>

          <div class="sdash-port-stats">
            <div class="sdash-port-stat-item">
              <span class="sdash-port-stat-label">Einheiten</span>
              <span class="sdash-port-stat-val"><?= $units ?> (<?= $mieter ?> belegt)</span>
            </div>
            <div class="sdash-port-stat-item">
              <span class="sdash-port-stat-label">Soll-Miete / Mt.</span>
              <span class="sdash-port-stat-val"><?= safe(chf($brutto)) ?></span>
            </div>
          </div>

          <div class="sdash-port-actions">
            <a href="<?= safe(page_url('projekt_dashboard.php?id=' . $pid)) ?>" class="sdash-port-chip sdash-port-chip--primary" title="Dashboard">
              📊 Dashboard
            </a>
            <a href="<?= safe(page_url('mieterspiegel.php?projekt_id=' . $pid)) ?>" class="sdash-port-chip" title="Mieterspiegel">
              📈 Mieterspiegel
            </a>
            <a href="<?= safe(base_url('tools/liegenschaftsabrechnung/index.php?projekt_id=' . $pid)) ?>" class="sdash-port-chip" title="Abrechnung">
              📑 Abrechnung
            </a>
            <a href="<?= safe(page_url('files.php?project_id=' . $pid)) ?>" class="sdash-port-chip" title="Drive">
              📁 Drive
            </a>
            <a href="<?= safe(page_url('pendenzen.php?projekt_id=' . $pid)) ?>" class="sdash-port-chip" title="Pendenzen">
              📋 Pendenzen
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- PENDENZEN MANAGEMENT -->
  <section class="sdash-panel" style="margin-bottom: 32px;" role="region" aria-labelledby="h-pendenzen">
    <header class="sdash-panel__header">
      <h3 class="sdash-panel__title" id="h-pendenzen">
        📋 Dringende Pendenzen & Mängel
      </h3>
      <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <select id="lists-preset" name="lists-preset" class="sdash-select" aria-label="Filter wählen">
          <option value="offen" selected>Nur Offene</option>
          <option value="prio_hoch">Hohe Priorität</option>
          <option value="ueberfaellig">Überfällig</option>
          <option value="alle">Alle (Neueste)</option>
        </select>

        <div class="sdash-col-picker">
          <button class="sdash-btn sdash-btn--ghost" id="btn-col-picker" type="button" style="padding: 8px 12px; font-size: 0.8rem;">
            ⚙️ Spalten
          </button>
          <div class="sdash-col-menu" id="col-menu">
            <?php
            $allCols = [
              'col-id'      => 'ID',
              'col-img'     => 'Bild',
              'col-proj'    => 'Projekt',
              'col-obj'     => 'Objekt',
              'col-unit'    => 'Wohnung',
              'col-titel'   => 'Titel',
              'col-desc'    => 'Kurzbeschreibung',
              'col-resp'    => 'Verantwortlich',
              'col-status'  => 'Status',
              'col-prio'    => 'Priorität',
              'col-due'     => 'Fällig am',
              'col-created' => 'Erstellt am',
              'col-updated' => 'Geändert',
              'col-note'    => 'Notiz'
            ];
            foreach ($allCols as $cls => $lbl):
              $checked = !in_array($cls, ['col-desc', 'col-note', 'col-updated', 'col-obj', 'col-unit']) ? 'checked' : '';
            ?>
              <label class="sdash-col-item">
                <input type="checkbox" <?= $checked ?> data-col="<?= $cls ?>" class="js-col-toggle">
                <?= h($lbl) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <button id="btn-add-todo" class="sdash-btn" style="padding:8px 14px; font-size:0.85rem;" type="button">
          + Schnell-Eintrag
        </button>

        <a class="sdash-link" href="<?= safe(page_url('pendenzen.php')) ?>">Zur Gesamtliste →</a>
      </div>
    </header>

    <div class="sdash-table-wrap">
      <table id="tbl-todos" class="sdash-table" aria-describedby="h-pendenzen" data-table="pendenzen">
        <thead>
          <tr>
            <th class="col-id">ID</th>
            <th class="col-img">Bild</th>
            <th class="col-proj">Liegenschaft</th>
            <th class="col-obj">Objekt</th>
            <th class="col-unit">Wohnung</th>
            <th class="col-titel">Titel</th>
            <th class="col-desc">Beschreibung</th>
            <th class="col-resp">Verantwortlich</th>
            <th class="col-status">Status</th>
            <th class="col-prio">Priorität</th>
            <th class="col-due">Fällig am</th>
            <th class="col-created">Erstellt am</th>
            <th class="col-updated">Geändert</th>
            <th class="col-note">Notiz</th>
            <th class="reorder-column" data-no-sort></th>
          </tr>
        </thead>
        <tbody id="pendenzen-body">
          <?php if (empty($recentTodos)): ?>
            <tr><td colspan="15" style="text-align:center; padding:24px; color:#64748b;">Keine offenen Pendenzen vorhanden. Alles erledigt!</td></tr>
          <?php else: ?>
            <?php foreach ($recentTodos as $t):
              $tid = (int)$t['id'];
              $titel = h($t['titel'] ?? ($t['beschreibung'] ?? '—'));
              $stat = h($t['status'] ?? 'offen');
              $wichtigkeit = (int)($t['wichtigkeit'] ?? 3);
              $prioLabel = $wichtigkeit >= 5 ? 'Dringend' : ($wichtigkeit >= 4 ? 'Hoch' : ($wichtigkeit <= 1 ? 'Niedrig' : 'Normal'));
              $due = h($t['enddatum'] ?? '—');
              $created = h(substr((string)($t['erstellt_am'] ?? ''), 0, 10));
              $updated = h(substr((string)($t['geaendert_am'] ?? $t['aktualisiert_am'] ?? ''), 0, 10));
              $user = h($t['zustaendig_name'] ?? '—');
              $proj = h($t['projekt_name'] ?? '—');
              $obj = h($t['objekt_name'] ?? '—');
              $unit = h($t['wohnung_name'] ?? '—');
              $desc = h($t['kurzbeschreibung'] ?? '—');
              $note = h($t['notiz'] ?? '—');
              $img = $t['cover_pfad'] ?? '';
              $opts = ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'];
            ?>
              <tr data-id="<?= $tid ?>">
                <td class="col-id"><?= $tid ?></td>
                <td class="col-img">
                  <div class="sdash-thumb-container js-thumb-wrap">
                    <?php if ($img): ?>
                      <img src="<?= h($img) ?>" class="sdash-thumb js-lightbox-trigger" alt="Vorschau" loading="lazy" onclick="if(window.openSdashLightbox) openSdashLightbox(this.src)">
                    <?php else: ?>
                      <div class="sdash-thumb js-thumb-empty js-replace-trigger" onclick="this.parentElement.querySelector('.js-col-img-input').click()" style="display:flex;align-items:center;justify-content:center;font-size:10px;color:#ccc">
                        Kein Bild
                      </div>
                    <?php endif; ?>
                    <div class="sdash-replace-btn js-replace-trigger" title="Bild ändern" onclick="this.parentElement.querySelector('.js-col-img-input').click()"></div>
                    <a href="<?= safe(page_url('pendenz_show.php?id=' . $tid)) ?>" class="sdash-detail-btn" title="Details bearbeiten"></a>
                    <input type="file" class="js-col-img-input hidden" accept="image/*">
                  </div>
                </td>
                <td class="col-proj"><small><?= $proj ?></small></td>
                <td class="col-obj"><small><?= $obj ?></small></td>
                <td class="col-unit"><small><?= $unit ?></small></td>
                <td class="col-titel" contenteditable="true" class="sdash-edit" data-edit-field="titel">
                  <a href="<?= safe(page_url('pendenz_show.php?id=' . $tid)) ?>" style="color:inherit; font-weight:600; text-decoration:none;">
                    <?= $titel ?>
                  </a>
                </td>
                <td class="col-desc" contenteditable="true" class="sdash-edit" data-edit-field="kurzbeschreibung"><?= $desc ?></td>
                <td class="col-resp"><span class="sdash-badge"><?= $user ?></span></td>
                <td class="col-status">
                  <select class="sdash-select status-badge status-<?= $stat ?>" data-edit-field="status" onchange="this.className='sdash-select status-badge status-'+this.value">
                    <?php foreach ($opts as $o): ?>
                      <option value="<?= $o ?>" <?= $o === $stat ? 'selected' : '' ?>><?= $o ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="col-prio">
                  <span class="prio-badge prio-<?= $wichtigkeit ?>"><?= $prioLabel ?></span>
                </td>
                <td class="col-due" contenteditable="true" class="sdash-edit" data-edit-field="enddatum">
                  <small><?= $due ?></small>
                </td>
                <td class="col-created"><small><?= $created ?></small></td>
                <td class="col-updated"><small><?= $updated ?></small></td>
                <td class="col-note" contenteditable="true" class="sdash-edit" data-edit-field="notiz"><?= $note ?></td>
                <td class="reorder-handle">
                  <svg class="sdash-icon" viewBox="0 0 24 24" style="width:16px; height:16px"><path d="M7 15l5 5 5-5M7 9l5-5 5 5" /></svg>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="sdash-savebar">
        <button class="sdash-btn js-save-table" data-target="#tbl-todos" type="button">Änderungen Speichern</button>
        <span class="sdash-save-status" aria-live="polite"></span>
      </div>
    </div>
  </section>

</div>

<!-- VOLLBILD / LIGHTBOX MODAL -->
<div id="sdash-lightbox" class="sdash-lightbox" onclick="this.classList.remove('sdash-lightbox--show')">
  <img id="lightbox-img" src="" alt="Vollbild">
</div>

<!-- GIMI VOICE MODAL (SCHNELLE SPRACHERFASSUNG) -->
<div id="gimiVoiceModal" class="voice-modal-overlay">
  <div class="voice-modal" onclick="event.stopPropagation()">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <h3 style="margin:0; font-size:1.25rem; font-weight:800; display:flex; align-items:center; gap:8px;">
        <span>🎙️</span> Gimi Voice Assistant
      </h3>
      <button type="button" onclick="closeVoiceModal()" style="background:none; border:0; font-size:24px; cursor:pointer; color:#94a3b8; line-height:1;">✕</button>
    </div>

    <p style="font-size:0.875rem; color:#64748b; margin:0 0 14px; line-height:1.45;">
      Sprich einfach frei: z.B. <em>"Romanshorn Arbonerstrasse Wohnung 3 Wasserhahn tropft dringend bis Freitag"</em>
    </p>

    <!-- 1-Klick Smartphone Tastatur-Diktat Banner -->
    <div style="background:linear-gradient(135deg, #eff6ff, #dbeafe); border:1.5px solid #bfdbfe; border-radius:14px; padding:14px 16px; margin-bottom:14px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <div>
        <div style="font-weight:800; font-size:0.95rem; color:#1e40af; display:flex; align-items:center; gap:6px;">
          <span>📲</span> Tastatur-Diktat (Empfohlen)
        </div>
        <div style="font-size:0.8rem; color:#3b82f6; margin-top:3px; line-height:1.35;">
          Tippe ins Feld & drücke die <strong>🎙️-Taste deiner Tastatur</strong>. Funktioniert immer zu 100%!
        </div>
      </div>
      <button type="button" onclick="focusVoiceTextarea()" style="background:#2563eb; color:#fff; border:none; border-radius:10px; padding:8px 14px; font-size:0.85rem; font-weight:700; cursor:pointer; white-space:nowrap; box-shadow:0 3px 10px rgba(37,99,235,0.25);">
        ⌨️ Diktat starten
      </button>
    </div>

    <!-- Web-Mikrofon Button -->
    <div style="text-align:center; padding: 6px 0 10px;">
      <button type="button" id="voiceMicBtn" class="voice-pulse-btn" onclick="toggleVoiceRecording()" title="Tippe zum Sprechen">
        🎙️
      </button>
      <div id="voiceStatusText" style="font-size:0.85rem; font-weight:700; color:#3b82f6; min-height:22px; margin-top:8px;">
        👆 Oder tippe auf das Mikrofon, um direkt aufzunehmen
      </div>
    </div>

    <!-- Textfeld mit Live-Analyse -->
    <div style="margin-top:10px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
        <label for="voiceTranscriptInput" style="font-size:0.8rem; font-weight:700; color:#475569;">Eingesprochener Text:</label>
        <button type="button" onclick="clearVoiceText()" style="background:none; border:none; color:#64748b; font-size:0.75rem; cursor:pointer; text-decoration:underline;">Leeren</button>
      </div>
      <textarea id="voiceTranscriptInput" rows="3" oninput="onVoiceInputChanged(this.value)" style="width:100%; border:1.5px solid #cbd5e1; border-radius:12px; padding:12px; font-size:1rem; font-family:inherit; box-sizing:border-box; outline:none; transition:border-color 0.2s;" placeholder="Hier tippen oder per Tastatur-Mikrofon einsprechen..."></textarea>
    </div>

    <!-- Live Preview Badges -->
    <div id="voiceMatchPreview" style="margin-top:14px; padding:12px; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0; display:none;">
      <div style="font-size:0.8rem; font-weight:700; color:#334155; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
        <span>✨</span> Automatische Zuweisung durch Gimi:
      </div>
      <div style="display:flex; flex-wrap:wrap; gap:8px; font-size:0.8rem;">
        <span id="vBadgeProj" class="sdash-port-chip sdash-port-chip--primary">Liegenschaft: —</span>
        <span id="vBadgeUnit" class="sdash-port-chip">Wohnung: —</span>
        <span id="vBadgePrio" class="sdash-port-chip">Priorität: Normal</span>
        <span id="vBadgeDue" class="sdash-port-chip">Frist: —</span>
      </div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:18px;">
      <button type="button" class="sdash-btn sdash-btn--ghost" onclick="closeVoiceModal()">Abbrechen</button>
      <button type="button" id="btnSaveVoicePendenz" class="sdash-btn" style="background:#10b981; border:0;" onclick="saveVoicePendenz()" disabled>
        💾 Als Pendenz speichern
      </button>
    </div>
  </div>
</div>

<!-- SCRIPTS -->
<script src="<?= safe(asset_url('dashboard.js')) ?>?v=<?= time() ?>" defer></script>

<script>
let voiceRecognition = null;
let voiceMediaRecorder = null;
let voiceAudioChunks = [];
let voiceRecordingTimer = null;
let voiceRecordSeconds = 0;
let voiceMediaStream = null;
let voiceIsListening = false;
let lastParsedVoice = null;

let voiceInputDebounceTimer = null;

function openVoiceModal() {
  const modal = document.getElementById('gimiVoiceModal');
  if (modal) modal.style.display = 'flex';
  const status = document.getElementById('voiceStatusText');
  if (status) {
    status.style.color = '#3b82f6';
    status.textContent = '👆 Tippe auf das Mikrofon, um die Aufnahme zu starten';
  }
  const permHelp = document.getElementById('voicePermissionHelp');
  if (permHelp) permHelp.style.display = 'none';
  // Fokus auf Textarea für schnelles Smartphone-Diktat
  setTimeout(() => {
    const input = document.getElementById('voiceTranscriptInput');
    if (input && window.innerWidth <= 768) {
      input.focus();
    }
  }, 300);
}

function focusVoiceTextarea() {
  const input = document.getElementById('voiceTranscriptInput');
  if (input) {
    input.focus();
    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function clearVoiceText() {
  const input = document.getElementById('voiceTranscriptInput');
  if (input) {
    input.value = '';
    input.focus();
  }
  const preview = document.getElementById('voiceMatchPreview');
  if (preview) preview.style.display = 'none';
  const saveBtn = document.getElementById('btnSaveVoicePendenz');
  if (saveBtn) saveBtn.disabled = true;
  lastParsedVoice = null;
}

function onVoiceInputChanged(val) {
  clearTimeout(voiceInputDebounceTimer);
  const text = (val || '').trim();
  if (text.length < 3) {
    const preview = document.getElementById('voiceMatchPreview');
    if (preview) preview.style.display = 'none';
    const saveBtn = document.getElementById('btnSaveVoicePendenz');
    if (saveBtn) saveBtn.disabled = true;
    return;
  }
  voiceInputDebounceTimer = setTimeout(() => {
    parseVoiceInput(text);
  }, 500);
}

function closeVoiceModal() {
  stopVoiceRecording(false);
  const modal = document.getElementById('gimiVoiceModal');
  if (modal) modal.style.display = 'none';
}

// Explizite Anforderung der Mikrofonberechtigung für Safari / Chrome
async function promptMicrophonePermission() {
  const status = document.getElementById('voiceStatusText');
  const permHelp = document.getElementById('voicePermissionHelp');
  
  if (status) {
    status.style.color = '#3b82f6';
    status.textContent = '⏳ Frage Browser nach Mikrofon-Freigabe...';
  }

  if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      stream.getTracks().forEach(track => track.stop());
      if (permHelp) permHelp.style.display = 'none';
      if (status) {
        status.style.color = '#10b981';
        status.textContent = '✅ Mikrofon freigegeben! Tippe jetzt auf das Mikrofon zum Sprechen.';
      }
      return;
    } catch (err) {
      console.warn('getUserMedia error:', err);
      if (permHelp) permHelp.style.display = 'block';
      if (status) {
        status.style.color = '#dc2626';
        status.innerHTML = '⚠️ Mikrofonzugriff verweigert. Bitte in den Browser-Einstellungen erlauben (siehe unten).';
      }
      return;
    }
  }
}

async function startVoiceRecording() {
  const status = document.getElementById('voiceStatusText');
  const btn = document.getElementById('voiceMicBtn');
  const input = document.getElementById('voiceTranscriptInput');
  const permHelp = document.getElementById('voicePermissionHelp');

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    if (status) {
      status.style.color = '#dc2626';
      status.innerHTML = '⚠️ Mikrofon im Browser nicht unterstützt. Bitte Smartphone-Tastatur nutzen.';
    }
    if (permHelp) permHelp.style.display = 'block';
    return;
  }

  try {
    // 1. Mikrofon-Stream abrufen (funktioniert auf Safari & Chrome einwandfrei)
    voiceMediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    if (permHelp) permHelp.style.display = 'none';

    // 2. Audio Chunks aufnehmen mit Safari-kompatiblem MIME-Type
    voiceAudioChunks = [];
    let voiceRecordingMime = '';
    if (typeof MediaRecorder !== 'undefined') {
      try {
        let mrOpts = {};
        if (typeof MediaRecorder.isTypeSupported === 'function') {
          if (MediaRecorder.isTypeSupported('audio/mp4')) {
            mrOpts = { mimeType: 'audio/mp4' };
            voiceRecordingMime = 'audio/mp4';
          } else if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
            mrOpts = { mimeType: 'audio/webm;codecs=opus' };
            voiceRecordingMime = 'audio/webm;codecs=opus';
          } else if (MediaRecorder.isTypeSupported('audio/webm')) {
            mrOpts = { mimeType: 'audio/webm' };
            voiceRecordingMime = 'audio/webm';
          }
        }
        voiceMediaRecorder = new MediaRecorder(voiceMediaStream, mrOpts);
        voiceMediaRecorder.ondataavailable = (e) => {
          if (e.data && e.data.size > 0) voiceAudioChunks.push(e.data);
        };
        // Safari erfordert start() ohne timeslice Parameter, sonst wirft WebKit DOMException
        voiceMediaRecorder.start();
      } catch(mrErr) {
        console.warn('MediaRecorder error:', mrErr);
        voiceMediaRecorder = null;
      }
    }

    voiceIsListening = true;
    if (btn) btn.classList.add('listening');

    // 3. Timer & Live-Status
    voiceRecordSeconds = 0;
    if (status) {
      status.style.color = '#ef4444';
      status.innerHTML = '🔴 <strong>Ich höre zu (0:00)</strong><br><span style="font-size:0.8rem; font-weight:normal;">Sprich jetzt... Tippe auf das Mikrofon zum Beenden</span>';
    }

    clearInterval(voiceRecordingTimer);
    voiceRecordingTimer = setInterval(() => {
      voiceRecordSeconds++;
      const mins = Math.floor(voiceRecordSeconds / 60);
      const secs = (voiceRecordSeconds % 60).toString().padStart(2, '0');
      if (status && voiceIsListening) {
        status.innerHTML = `🔴 <strong>Ich höre zu (${mins}:${secs})</strong><br><span style="font-size:0.8rem; font-weight:normal;">Sprich jetzt... Tippe auf das Mikrofon zum Beenden</span>`;
      }
    }, 1000);

    // 4. Parallele Live-Spracherkennung nur auf Nicht-iOS (iOS WebKit deaktiviert Mikrofon bei doppeltem Zugriff)
    const isIOSDevice = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const SpeechRec = (!isIOSDevice && (window.SpeechRecognition || window.webkitSpeechRecognition)) ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null;
    if (SpeechRec) {
      try {
        voiceRecognition = new SpeechRec();
        voiceRecognition.lang = 'de-DE';
        voiceRecognition.interimResults = true;
        voiceRecognition.continuous = false;
        voiceRecognition.onresult = (e) => {
          let text = '';
          for (let i = 0; i < e.results.length; i++) {
            text += e.results[i][0].transcript;
          }
          if (input && text.trim()) input.value = text;
        };
        voiceRecognition.onerror = (e) => {
          console.warn('Live recognition note:', e.error);
        };
        voiceRecognition.start();
      } catch(e) {
        console.warn('SpeechRecognition fallback:', e);
      }
    }

  } catch (err) {
    console.warn('Mic start error:', err);
    if (permHelp) permHelp.style.display = 'block';
    if (status) {
      status.style.color = '#dc2626';
      status.innerHTML = '⚠️ Mikrofonzugriff nicht gestattet. Bitte im Browser erlauben oder Smartphone-Tastatur nutzen.';
    }
    stopVoiceRecording(false);
  }
}

async function stopVoiceRecording(processAudio = true) {
  if (!voiceIsListening) return;
  voiceIsListening = false;
  clearInterval(voiceRecordingTimer);

  const btn = document.getElementById('voiceMicBtn');
  if (btn) btn.classList.remove('listening');
  const status = document.getElementById('voiceStatusText');
  const input = document.getElementById('voiceTranscriptInput');

  if (voiceRecognition) {
    try { voiceRecognition.stop(); } catch(e) {}
    voiceRecognition = null;
  }

  const finalize = () => {
    if (voiceMediaStream) {
      voiceMediaStream.getTracks().forEach(t => t.stop());
      voiceMediaStream = null;
    }

    if (!processAudio) return;

    const currentText = input ? input.value.trim() : '';

    // Wenn wir bereits erkannten Live-Text haben, analysieren wir diesen direkt
    if (currentText.length > 2) {
      parseVoiceInput(currentText);
      return;
    }

    // Falls kein Text vorhanden, transkribieren wir das aufgenommene Audio über Gemini
    if (voiceAudioChunks.length > 0) {
      if (status) {
        status.style.color = '#3b82f6';
        status.innerHTML = '🧠 <strong>Gimi transkribiert & analysiert Audio...</strong>';
      }

      const resolvedMime = (typeof MediaRecorder !== 'undefined' && typeof MediaRecorder.isTypeSupported === 'function' && MediaRecorder.isTypeSupported('audio/mp4')) ? 'audio/mp4' : 'audio/webm';
      const audioBlob = new Blob(voiceAudioChunks, { type: resolvedMime });
      const reader = new FileReader();
      reader.onloadend = async () => {
        const base64data = reader.result;
        const apiUrl = (window.location.pathname.includes('/pages/') ? '../' : '') + 'api/voice_pendenz.php';
        try {
          const res = await fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              action: 'parse',
              audio_base64: base64data,
              audio_mime: audioBlob.type || resolvedMime
            })
          });
          const data = await res.json();
          if (data.ok && data.parsed) {
            lastParsedVoice = data.parsed;
            if (input) input.value = data.parsed.original_text || data.parsed.beschreibung || '';
            renderParsedVoiceBadges(data.parsed);
            if (status) {
              status.style.color = '#10b981';
              status.innerHTML = '✅ <strong>Analyse erfolgreich!</strong>';
            }
          } else {
            if (status) {
              status.style.color = '#dc2626';
              status.innerHTML = '⚠️ ' + (data.message || 'Kein Text erkannt. Bitte erneut aufnehmen oder tippen.');
            }
          }
        } catch(e) {
          console.error('Audio processing error:', e);
          if (status) {
            status.style.color = '#dc2626';
            status.textContent = '⚠️ Verbindungsfehler: ' + (e.message || 'Server nicht erreichbar');
          }
        }
      };
      reader.readAsDataURL(audioBlob);
    } else {
      if (status) {
        status.style.color = '#64748b';
        status.textContent = 'Aufnahme beendet. Tippe erneut auf das Mikrofon zum Sprechen.';
      }
    }
  };

  if (voiceMediaRecorder && voiceMediaRecorder.state !== 'inactive') {
    voiceMediaRecorder.onstop = () => {
      finalize();
    };
    try {
      voiceMediaRecorder.stop();
    } catch(e) {
      finalize();
    }
  } else {
    finalize();
  }
}

function toggleVoiceRecording() {
  if (voiceIsListening) {
    stopVoiceRecording(true);
  } else {
    startVoiceRecording();
  }
}

function renderParsedVoiceBadges(parsed) {
  const preview = document.getElementById('voiceMatchPreview');
  if (preview) preview.style.display = 'block';
  
  const vProj = document.getElementById('vBadgeProj');
  const vUnit = document.getElementById('vBadgeUnit');
  const vPrio = document.getElementById('vBadgePrio');
  const vDue  = document.getElementById('vBadgeDue');
  
  if (vProj) vProj.textContent = 'Liegenschaft: ' + (parsed.projekt_name || 'Keine Angabe');
  if (vUnit) vUnit.textContent = 'Wohnung: ' + (parsed.wohnung_name || 'Allgemein');
  if (vPrio) vPrio.textContent = 'Priorität: ' + (parsed.wichtigkeit_label || 'Normal');
  if (vDue)  vDue.textContent  = 'Frist: ' + (parsed.enddatum_label || parsed.enddatum || 'Keine Frist');

  const status = document.getElementById('voiceStatusText');
  if (status) {
    status.style.color = '#10b981';
    status.textContent = '✅ Analyse erfolgreich! Bereit zum Speichern.';
  }
  const saveBtn = document.getElementById('btnSaveVoicePendenz');
  if (saveBtn) saveBtn.disabled = false;
}

async function parseVoiceInput(text) {
  if (!text) return;
  const status = document.getElementById('voiceStatusText');
  if (status) {
    status.style.color = '#3b82f6';
    status.textContent = '🧠 Gimi analysiert Liegenschaft, Wohnung und Frist...';
  }

  try {
    const currentProjId = document.getElementById('projectSelector')?.value || new URLSearchParams(window.location.search).get('projekt_id') || '';
    const res = await fetch('<?= safe(base_url('api/voice_pendenz.php')) ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'parse', text: text, projekt_id: currentProjId })
    });
    const data = await res.json();
    if (data.ok && data.parsed) {
      lastParsedVoice = data.parsed;
      renderParsedVoiceBadges(data.parsed);
    } else {
      if (status) {
        status.style.color = '#d97706';
        status.textContent = '⚠️ Details unvollständig zugeordnet. Text kann trotzdem gespeichert werden.';
      }
      const saveBtn = document.getElementById('btnSaveVoicePendenz');
      if (saveBtn) saveBtn.disabled = false;
    }
  } catch(e) {
    console.error(e);
    if (status) {
      status.style.color = '#dc2626';
      status.textContent = 'Verbindungsfehler beim Verarbeiten.';
    }
  }
}

async function saveVoicePendenz() {
  const input = document.getElementById('voiceTranscriptInput');
  const text = input ? input.value.trim() : '';
  if (!text) return;

  const btn = document.getElementById('btnSaveVoicePendenz');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Speichere...';
  }

  try {
    const payload = {
      action: 'save',
      text: text,
      ...(lastParsedVoice || {})
    };

    const res = await fetch('<?= safe(base_url('api/voice_pendenz.php')) ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.ok && data.new_id) {
      alert("✅ Pendenz #" + data.new_id + " erfolgreich angelegt!");
      window.location.reload();
    } else {
      alert("Fehler beim Speichern: " + (data.message || data.error || 'Unbekannt'));
      if (btn) {
        btn.disabled = false;
        btn.textContent = '💾 Als Pendenz speichern';
      }
    }
  } catch(e) {
    console.error(e);
    alert("Netzwerkfehler beim Speichern.");
    if (btn) {
      btn.disabled = false;
      btn.textContent = '💾 Als Pendenz speichern';
    }
  }
}

// Live-Analyse bei manueller Tastatur-Eingabe oder Smartphone-Diktat
const tInput = document.getElementById('voiceTranscriptInput');
if (tInput) {
  tInput.addEventListener('input', function() {
    const val = this.value.trim();
    const saveBtn = document.getElementById('btnSaveVoicePendenz');
    if (saveBtn) saveBtn.disabled = val.length === 0;

    clearTimeout(voiceDebounce);
    if (val.length >= 6) {
      voiceDebounce = setTimeout(() => {
        parseVoiceInput(val);
      }, 750);
    }
  });
}
</script>

<?php include __DIR__ . "/includes/footer.php"; ?>