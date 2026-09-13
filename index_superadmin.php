<?php
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/includes/auth.php";
require_login();
require_role(['superadmin']);

// Fix: Navigation Layout sofort erkennen um Springen zu vermeiden
$nav_mode = $_COOKIE['nav-layout'] ?? 'top';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  die("DB-Verbindung nicht verfügbar: \$mysqli ist nicht gesetzt (config.php).");
}

/* ==== CSRF ==== */
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

/* ==== Helpers (idempotent) ==== */
if (!function_exists('table_exists')) {
  function table_exists(mysqli $db, string $name): bool
  {
    try {
      $stmt = $db->prepare("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
      $stmt->bind_param("s", $name);
      $stmt->execute();
      $r = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      return (int) ($r['c'] ?? 0) > 0;
    } catch (Throwable $e) {
      return false;
    }
  }
}
if (!function_exists('col_exists')) {
  function col_exists(mysqli $db, string $table, string $col): bool
  {
    try {
      $stmt = $db->prepare("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name=? AND column_name=?");
      $stmt->bind_param("ss", $table, $col);
      $stmt->execute();
      $r = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      return (int) ($r['c'] ?? 0) > 0;
    } catch (Throwable $e) {
      return false;
    }
  }
}
if (!function_exists('pick_col')) {
  function pick_col(mysqli $db, string $table, array $cands): ?string
  {
    foreach ($cands as $c)
      if (col_exists($db, $table, $c))
        return $c;
    return null;
  }
}
if (!function_exists('count_rows')) {
  function count_rows(mysqli $db, string $table): int
  {
    if (!table_exists($db, $table))
      return 0;
    try {
      $res = $db->query("SELECT COUNT(*) AS c FROM `{$table}`");
      $n = (int) ($res->fetch_assoc()['c'] ?? 0);
      $res->free();
      return $n;
    } catch (Throwable $e) {
      return 0;
    }
  }
}
if (!function_exists('first_existing_col')) {
  function first_existing_col(mysqli $db, string $table, array $cands): string
  {
    foreach ($cands as $c)
      if (col_exists($db, $table, $c))
        return $c;
    return 'id';
  }
}
if (!function_exists('fetch_recent')) {
  function fetch_recent(mysqli $db, string $table, int $limit = 8): array
  {
    if (!table_exists($db, $table))
      return [];
    try {
      $orderCol = first_existing_col($db, $table, ['sort_index', 'aktualisiert_am', 'updated_at', 'erstellt_am', 'created_at', 'import_timestamp', 'id']);
      $stmt = $db->prepare("SELECT * FROM `{$table}` ORDER BY `{$orderCol}` DESC LIMIT ?");
      $stmt->bind_param("i", $limit);
      $stmt->execute();
      $res = $stmt->get_result();
      $rows = [];
      while ($r = $res->fetch_assoc())
        $rows[] = $r;
      $stmt->close();
      return $rows;
    } catch (Throwable $e) {
      return [];
    }
  }
}
if (!function_exists('safe')) {
  function safe($v)
  {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

/* ==== Zahlen & Daten ==== */
$nav_mode = $_SESSION['nav_mode'] ?? 'side';
$cntBenutzer = count_rows($mysqli, 'benutzer');
$cntProjekte = count_rows($mysqli, 'projekte');
$cntPendenzen = count_rows($mysqli, 'pendenzen');
$tblPendenzenListe = table_exists($mysqli, 'pendenzen_liste') ? 'pendenzen_liste' :
  (table_exists($mysqli, 'pendenzen_listen') ? 'pendenzen_listen' : null);
$cntPendenzenListen = $tblPendenzenListe ? count_rows($mysqli, $tblPendenzenListe) : 0;

$kontoTblExists = table_exists($mysqli, 'liegenschafts_konto');
$cntKonto = $kontoTblExists ? count_rows($mysqli, 'liegenschafts_konto') : 0;
$lastImport = null;
if ($kontoTblExists && col_exists($mysqli, 'liegenschafts_konto', 'import_dateiname')) {
  $orderCol = first_existing_col($mysqli, 'liegenschafts_konto', ['import_timestamp', 'aktualisiert_am', 'erstellt_am', 'buchungsdatum', 'id']);
  $sql = "SELECT import_dateiname AS datei, MAX(`{$orderCol}`) AS zeit
          FROM liegenschafts_konto
          GROUP BY import_dateiname
          ORDER BY zeit DESC LIMIT 1";
  if ($res = $mysqli->query($sql)) {
    $lastImport = $res->fetch_assoc();
    $res->free();
  }
}

$recentUsers = fetch_recent($mysqli, 'benutzer', 30);
$recentProjects = fetch_recent($mysqli, 'projekte', 50);

$recentTodos = [];
if (table_exists($mysqli, 'pendenzen')) {
  $orderCol = first_existing_col($mysqli, 'pendenzen', ['sort_index', 'aktualisiert_am', 'erstellt_am', 'id']);
  $res = $mysqli->query("
      SELECT p.*, b.name AS zustaendig_name, pr.name AS projekt_name, o.name AS objekt_name, w.name AS wohnung_name, img.pfad AS cover_pfad
      FROM pendenzen p 
      LEFT JOIN benutzer b ON b.id = p.zustaendig_id 
      LEFT JOIN projekte pr ON pr.id = p.projekt_id 
      LEFT JOIN objekte o ON o.id = p.objekt_id
      LEFT JOIN wohnungen w ON w.id = p.wohnung_id
      LEFT JOIN (SELECT pendenz_id, pfad FROM pendenz_dateien WHERE typ='image' AND is_cover=1 GROUP BY pendenz_id) img ON img.pendenz_id = p.id
      ORDER BY p.`$orderCol` DESC LIMIT 2000");
  if ($res) {
    while ($r = $res->fetch_assoc())
      $recentTodos[] = $r;
    $res->free();
  }
}

/* ==== Spalten für Inline-Edit ==== */
$userNameCol = pick_col($mysqli, 'benutzer', ['name', 'vollname', 'benutzername', 'username']);
$userEmailCol = pick_col($mysqli, 'benutzer', ['email', 'e_mail']);
$userRoleCol = pick_col($mysqli, 'benutzer', ['rolle', 'role', 'rollenname']);
$projNameCol = pick_col($mysqli, 'projekte', ['name', 'titel']);
$projSortCol = pick_col($mysqli, 'projekte', ['sort_index', 'position', 'ordering', 'rang', 'reihenfolge']);
$todoTitleCol = pick_col($mysqli, 'pendenzen', ['titel', 'beschreibung', 'name', 'betreff']);
$todoStatusCol = pick_col($mysqli, 'pendenzen', ['status', 'state']);
$todoSortCol = pick_col($mysqli, 'pendenzen', ['sort_index', 'position', 'ordering', 'rang', 'reihenfolge']);

include __DIR__ . "/includes/header.php";
include __DIR__ . "/includes/nav_superadmin.php";

/* ==== Assets & Fonts ==== */
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= safe(asset_url('dashboard.css')) ?>?v=<?= time() ?>">

<style>
  /* Local Overrides/Tweaks */
  .sdash-icon {
    width: 20px;
    height: 20px;
    stroke-width: 2;
    stroke: currentColor;
    fill: none;
    vertical-align: middle;
  }

  .sdash-hero__icon {
    width: 48px;
    height: 48px;
    margin-bottom: 24px;
    color: var(--sd-primary);
  }
</style>

<div class="sdash-wrap" id="sdash-root" data-endpoint-batch="/pendenz.com/api/batch_update.php"
  data-endpoint-quick="/pendenz.com/api/quick_insert.php" data-endpoint-prefs="/pendenz.com/api/dashboard_prefs.php"
  data-csrf="<?= safe($CSRF) ?>">

  <header class="sdash-hero" role="region" aria-label="Dashboard Intro">
    <div class="sdash-hero__text">
      <svg class="sdash-icon sdash-hero__icon" viewBox="0 0 24 24">
        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
      </svg>
      <h1>Control Center</h1>
      <p>Willkommen im Superadmin-Bereich. Verwalten Sie Benutzer, Projekte und Pendenzen mit maximaler Effizienz.</p>
      <div class="sdash-hero__cta">
        <a class="sdash-btn" href="<?= safe(page_url('pendenz_neu.php')) ?>">
          <svg class="sdash-icon" viewBox="0 0 24 24">
            <path d="M12 5v14M5 12h14" />
          </svg>
          Neue Pendenz
        </a>
        <a class="sdash-btn sdash-btn--ghost" href="http://localhost/pendenz.com/tools/konto_verwaltung/index.php">
          <svg class="sdash-icon" viewBox="0 0 24 24">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" />
          </svg>
          CSV Import
        </a>
      </div>
    </div>
    <div class="sdash-hero__glow"></div>
  </header>

  <div class="sdash-banner" id="sdash-banner" role="alert"></div>

  <style>
    .sdash-col-picker {
      position: relative;
    }

    .sdash-col-menu {
      position: absolute;
      top: calc(100% + 8px);
      right: 0;
      background: var(--sd-surface);
      border: 1px solid var(--sd-border);
      border-radius: var(--sd-radius);
      padding: 16px;
      box-shadow: var(--sd-shadow-lg);
      z-index: 100;
      min-width: 200px;
      display: none;
      flex-direction: column;
      gap: 8px;
    }

    .sdash-col-menu--show {
      display: flex;
    }

    .sdash-col-item {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.875rem;
      cursor: pointer;
      color: var(--sd-text-muted);
      padding: 4px 0;
    }

    .sdash-col-item:hover {
      color: var(--sd-text);
    }

    .sdash-col-item input {
      cursor: pointer;
    }

    /* Column targeting classes added via JS */
    .col-hidden {
      display: none !important;
    }
  </style>

  <div class="sdash-lightbox" id="sdash-lightbox">
    <img src="" id="lightbox-img" alt="Vollbild">
  </div>

  <section class="sdash-cards" role="region" aria-label="Kennzahlen">
    <article class="sdash-card">
      <div class="sdash-card__title">Benutzer</div>
      <div class="sdash-card__num" data-count="<?= safe($cntBenutzer) ?>"><?= safe($cntBenutzer) ?></div>
      <div class="sdash-card__meta">Systemzugänge gesamt</div>
      <a class="sdash-card__link" href="<?= safe(page_url('benutzer.php')) ?>">
        Verwalten
        <svg class="sdash-icon" viewBox="0 0 24 24">
          <path d="M5 12h14M12 5l7 7-7 7" />
        </svg>
      </a>
    </article>
    <article class="sdash-card">
      <div class="sdash-card__title">Projekte</div>
      <div class="sdash-card__num" data-count="<?= safe($cntProjekte) ?>"><?= safe($cntProjekte) ?></div>
      <div class="sdash-card__meta">Aktive Bauvorhaben</div>
      <a class="sdash-card__link" href="<?= safe(page_url('projekte.php')) ?>">
        Konfigurieren
        <svg class="sdash-icon" viewBox="0 0 24 24">
          <path d="M5 12h14M12 5l7 7-7 7" />
        </svg>
      </a>
    </article>
    <article class="sdash-card">
      <div class="sdash-card__title">Pendenzen</div>
      <div class="sdash-card__num" data-count="<?= safe($cntPendenzen) ?>"><?= safe($cntPendenzen) ?></div>
      <div class="sdash-card__meta">Aufgaben & Tickets</div>
      <a class="sdash-card__link" href="<?= safe(page_url('pendenzen.php')) ?>">
        Details
        <svg class="sdash-icon" viewBox="0 0 24 24">
          <path d="M5 12h14M12 5l7 7-7 7" />
        </svg>
      </a>
    </article>
    <article class="sdash-card">
      <div class="sdash-card__title">Listen</div>
      <div class="sdash-card__num" data-count="<?= safe($cntPendenzenListen) ?>"><?= safe($cntPendenzenListen) ?></div>
      <div class="sdash-card__meta">Definierte Ansichten</div>
      <a class="sdash-card__link" href="<?= safe(page_url('pendenzen_liste.php')) ?>">
        Öffnen
        <svg class="sdash-icon" viewBox="0 0 24 24">
          <path d="M5 12h14M12 5l7 7-7 7" />
        </svg>
      </a>
    </article>
    <article class="sdash-card">
      <div class="sdash-card__title">Konto-Audit</div>
      <div class="sdash-card__num" data-count="<?= safe($cntKonto) ?>"><?= safe($cntKonto) ?></div>
      <div class="sdash-card__meta">
        <?php if ($lastImport): ?>
          Letztes File: <span class="sdash-badge"><?= safe($lastImport['datei']) ?></span>
        <?php else: ?>
          Keine aktuellen Importe
        <?php endif; ?>
      </div>
      <a class="sdash-card__link" href="http://localhost/pendenz.com/tools/konto_verwaltung/index.php">
        Audit Tool
        <svg class="sdash-icon" viewBox="0 0 24 24">
          <path d="M5 12h14M12 5l7 7-7 7" />
        </svg>
      </a>
    </article>
  </section>

  <section class="sdash-toolbar" role="region" aria-label="Werkzeuge">
    <label for="sdash-search" class="visually-hidden">Dashboard-Suche</label>
    <input id="sdash-search" name="sdash-search" type="search" placeholder="Globale Suche über alle Tabellen…"
      autocomplete="off">
    <div class="sdash-view-toggle" role="group" aria-label="Ansicht umschalten">
      <button class="sdash-chip sdash-chip--on" data-view="tables" type="button">Tabellen</button>
      <button class="sdash-chip" data-view="cards" type="button">Karten</button>
    </div>
  </section>

  <section class="sdash-grids" data-view="tables">

    <div class="sdash-grid-2">
      <!-- Benutzer -->
      <article class="sdash-panel" style="margin-bottom:0" role="region" aria-labelledby="h-users">
        <header class="sdash-panel__head">
          <h3 id="h-users">
            <svg class="sdash-icon" style="margin-right:8px" viewBox="0 0 24 24">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="9" cy="7" r="4" />
              <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
              <path d="M16 3.13a4 4 0 0 1 0 7.75" />
            </svg>
            Zuletzt registriert
          </h3>
          <a class="sdash-link" href="<?= safe(page_url('benutzer.php')) ?>">Alle Benutzer</a>
        </header>
        <?php if ($recentUsers): ?>
          <div class="sdash-table-wrap">
            <table id="tbl-users" class="sdash-table" aria-describedby="h-users" data-table="benutzer">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>E-Mail</th>
                  <th>Rolle</th>
                  <th>Aktivität</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentUsers as $u):
                  $uid = (int) ($u['id'] ?? 0); ?>
                  <tr data-id="<?= safe($uid) ?>">
                    <td><?= safe($uid) ?></td>
                    <td <?php if ($userNameCol): ?>contenteditable="true" class="sdash-edit" data-edit-table="benutzer"
                        data-edit-id="<?= safe($uid) ?>" data-edit-field="<?= safe($userNameCol) ?>" <?php endif; ?>>
                      <?= safe($u[$userNameCol ?? 'name'] ?? ($u['vollname'] ?? '—')) ?>
                    </td>
                    <td <?php if ($userEmailCol): ?>contenteditable="true" class="sdash-edit" data-edit-table="benutzer"
                        data-edit-id="<?= safe($uid) ?>" data-edit-field="<?= safe($userEmailCol) ?>" <?php endif; ?>>
                      <?= safe($u[$userEmailCol ?? 'email'] ?? '—') ?>
                    </td>
                    <td <?php if ($userRoleCol): ?>contenteditable="true" class="sdash-edit" data-edit-table="benutzer"
                        data-edit-id="<?= safe($uid) ?>" data-edit-field="<?= safe($userRoleCol) ?>" <?php endif; ?>>
                      <span class="sdash-badge"><?= safe($u[$userRoleCol ?? 'rolle'] ?? 'benutzer') ?></span>
                    </td>
                    <td><?= safe($u['letzter_login'] ?? ($u['aktualisiert_am'] ?? ($u['erstellt_am'] ?? '—'))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="sdash-savebar">
              <button class="sdash-btn js-save-table" data-target="#tbl-users" type="button">Speichern</button>
              <span class="sdash-save-status" aria-live="polite"></span>
            </div>
          </div>
        <?php else: ?>
          <p class="sdash-warn">Keine Daten oder Tabelle <code>benutzer</code> fehlt.</p><?php endif; ?>
      </article>

      <!-- Projekte -->
      <article class="sdash-panel" style="margin-bottom:0" role="region" aria-labelledby="h-projects">
        <header class="sdash-panel__head">
          <h3 id="h-projects">
            <svg class="sdash-icon" style="margin-right:8px" viewBox="0 0 24 24">
              <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
              <polyline points="9 22 9 12 15 12 15 22" />
            </svg>
            Bauvorhaben
          </h3>
          <a class="sdash-link" href="<?= safe(page_url('projekte.php')) ?>">Alle Projekte</a>
        </header>
        <div class="sdash-table-tools">
          <button id="btn-add-project" class="sdash-btn sdash-btn--ghost"
            style="padding:6px 12px; font-size:0.8rem; color:var(--sd-text)" type="button">
            <svg class="sdash-icon" style="width:16px; height:16px" viewBox="0 0 24 24">
              <path d="M12 5v14M5 12h14" />
            </svg>
            Neues Projekt
          </button>
        </div>
        <?php if ($recentProjects): ?>
          <div class="sdash-table-wrap">
            <table id="tbl-projects" class="sdash-table" aria-describedby="h-projects" data-table="projekte"
              <?= $projSortCol ? 'data-order-field="' . safe($projSortCol) . '"' : '' ?>>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Bezeichnung</th>
                  <th>Letzte Änderung</th>
                  <th class="reorder-column" data-no-sort></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentProjects as $p):
                  $pid = (int) ($p['id'] ?? 0);
                  $name = $p[$projNameCol ?? 'name'] ?? ($p['titel'] ?? ('Projekt #' . $pid));
                  $ts = $p['aktualisiert_am'] ?? ($p['erstellt_am'] ?? ''); ?>
                  <tr data-id="<?= safe($pid) ?>">
                    <td><?= safe($pid) ?></td>
                    <td contenteditable="true" class="sdash-edit" data-edit-table="projekte"
                      data-edit-id="<?= safe($pid) ?>" data-edit-field="<?= safe($projNameCol ?: 'name') ?>">
                      <?= safe($name) ?></td>
                    <td><small><?= safe($ts) ?></small></td>
                    <td class="reorder-handle" title="Ziehen zum Sortieren">
                      <svg class="sdash-icon" viewBox="0 0 24 24" style="width:16px; height:16px">
                        <path d="M7 15l5 5 5-5M7 9l5-5 5 5" />
                      </svg>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="sdash-savebar">
              <button class="sdash-btn js-save-table" data-target="#tbl-projects" type="button">Speichern</button>
              <span class="sdash-save-status" aria-live="polite"></span>
            </div>
          </div>
        <?php else: ?>
          <p class="sdash-warn">Keine Daten oder Tabelle <code>projekte</code> fehlt.</p><?php endif; ?>
      </article>
    </div>

    <!-- Pendenzen Management (Unified) -->
    <article class="sdash-panel" style="grid-column: 1 / -1" role="region" aria-labelledby="h-pendenzen">
      <header class="sdash-panel__head">
        <h3 id="h-pendenzen">
          <svg class="sdash-icon" style="margin-right:8px" viewBox="0 0 24 24">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
          </svg>
          Pendenzen Management
        </h3>
        <div style="display:flex; gap:12px; align-items:center;">
          <select id="lists-preset" name="lists-preset" class="sdash-select" aria-label="Filter wählen">
            <option value="alle">Alle (Neueste)</option>
            <option value="offen" selected>Nur Offene</option>
            <option value="prio_hoch">Hohe Priorität</option>
            <option value="ueberfaellig">Überfällig</option>
          </select>

          <div class="sdash-col-picker">
            <button class="sdash-btn sdash-btn--ghost" id="btn-col-picker" type="button"
              style="padding: 8px 12px; font-size: 0.8rem;">
              ⚙️ Spalten einblenden / ausblenden
            </button>
            <div class="sdash-col-menu" id="col-menu">
              <?php
              $allCols = [
                'col-id' => 'ID',
                'col-img' => 'Bild',
                'col-proj' => 'Projekt',
                'col-obj' => 'Objekt',
                'col-unit' => 'Wohnung',
                'col-titel' => 'Titel',
                'col-desc' => 'Kurzbeschreibung',
                'col-resp' => 'Verantwortlich',
                'col-status' => 'Status',
                'col-prio' => 'Priorität',
                'col-due' => 'Fällig am',
                'col-created' => 'Erstellt am',
                'col-updated' => 'Letzte Änderung',
                'col-note' => 'Notiz'
              ];
              foreach ($allCols as $cls => $lbl):
                // Default visibility: hide some heavy columns by default
                $checked = !in_array($cls, ['col-desc', 'col-note', 'col-updated', 'col-obj', 'col-unit']) ? 'checked' : '';
                ?>
                <label class="sdash-col-item">
                  <input type="checkbox" <?= $checked ?> data-col="<?= $cls ?>" class="js-col-toggle">
                  <?= h($lbl) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <a class="sdash-link" href="<?= safe(page_url('pendenzen.php')) ?>">Gesamtliste</a>
        </div>
      </header>

      <div class="sdash-table-tools">
        <button id="btn-add-todo" class="sdash-btn sdash-btn--ghost"
          style="padding:6px 12px; font-size:0.8rem; color:var(--sd-text)" type="button">
          <svg class="sdash-icon" style="width:16px; height:16px" viewBox="0 0 24 24">
            <path d="M12 5v14M5 12h14" />
          </svg>
          Schnell-Eintrag
        </button>
      </div>

      <div class="sdash-table-wrap">
        <table id="tbl-todos" class="sdash-table" aria-describedby="h-pendenzen" data-table="pendenzen">
          <thead>
            <tr>
              <th class="col-id">ID</th>
              <th class="col-img">Bild</th>
              <th class="col-proj">Projekt</th>
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
            <?php foreach ($recentTodos as $t):
              $tid = (int) ($t['id'] ?? 0);
              $titel = h($t[$todoTitleCol ?? 'titel'] ?? ($t['beschreibung'] ?? '—'));
              $stat = h($t['status'] ?? 'offen');
              $prio = h($t['prioritaet'] ?? 'normal');
              $due = h($t['faellig_am'] ?? $t['enddatum'] ?? '—');
              $created = h($t['erstellt_am'] ?? '—');
              $updated = h($t['aktualisiert_am'] ?? '—');
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
                      <img src="<?= h($img) ?>" class="sdash-thumb js-lightbox-trigger" alt="Vorschau" loading="lazy"
                        onclick="if(window.openSdashLightbox) openSdashLightbox(this.src)">
                    <?php else: ?>
                      <div class="sdash-thumb js-thumb-empty js-replace-trigger"
                        onclick="this.parentElement.querySelector('.js-col-img-input').click()"
                        style="display:flex;align-items:center;justify-content:center;font-size:10px;color:#ccc">Kein Bild
                      </div>
                    <?php endif; ?>
                    <div class="sdash-replace-btn js-replace-trigger" title="Bild ändern"
                      onclick="this.parentElement.querySelector('.js-col-img-input').click()"></div>
                    <a href="pages/pendenz_show.php?id=<?= $tid ?>" class="sdash-detail-btn"
                      title="Details bearbeiten"></a>
                    <input type="file" class="js-col-img-input hidden" accept="image/*">
                  </div>
                </td>
                <td class="col-proj"><small><?= $proj ?></small></td>
                <td class="col-obj"><small><?= $obj ?></small></td>
                <td class="col-unit"><small><?= $unit ?></small></td>
                <td class="col-titel" contenteditable="true" class="sdash-edit" data-edit-field="titel"><?= $titel ?></td>
                <td class="col-desc" contenteditable="true" class="sdash-edit" data-edit-field="kurzbeschreibung">
                  <?= $desc ?>
                </td>
                <td class="col-resp"><span class="sdash-badge"><?= $user ?></span></td>
                <td class="col-status">
                  <select class="sdash-select status-badge status-<?= $stat ?>" data-edit-field="status"
                    onchange="this.className='sdash-select status-badge status-'+this.value">
                    <?php foreach ($opts as $o): ?>
                      <option value="<?= $o ?>" <?= $o === $stat ? ' selected' : '' ?>><?= $o ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="col-prio" contenteditable="true" class="sdash-edit" data-edit-field="prioritaet"><?= $prio ?>
                </td>
                <td class="col-due" contenteditable="true" class="sdash-edit" data-edit-field="enddatum">
                  <small><?= $due ?></small>
                </td>
                <td class="col-created"><small><?= $created ?></small></td>
                <td class="col-updated"><small><?= $updated ?></small></td>
                <td class="col-note" contenteditable="true" class="sdash-edit" data-edit-field="notiz"><?= $note ?></td>
                <td class="reorder-handle"><svg class="sdash-icon" viewBox="0 0 24 24" style="width:16px; height:16px">
                    <path d="M7 15l5 5 5-5M7 9l5-5 5 5" />
                  </svg></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="sdash-savebar">
          <button class="sdash-btn js-save-table" data-target="#tbl-todos" type="button">Speichern</button>
          <span class="sdash-save-status" aria-live="polite"></span>
        </div>
      </div>
    </article>

  </section>
</div>

<!-- Vollbild / Lightbox Fenster -->
<div id="sdash-lightbox" class="sdash-lightbox" onclick="this.classList.remove('sdash-lightbox--show')">
  <img id="lightbox-img" src="" alt="Vollbild">
</div>

<!-- Vanilla JS -->
<script src="http://localhost/pendenz.com/assets/dashboard.js?v=<?= time() ?>" defer></script>

<?php include __DIR__ . "/includes/footer.php"; ?>