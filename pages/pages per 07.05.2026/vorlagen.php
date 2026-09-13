<?php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

/* ====================== Helpers ====================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function chip($text, $href=null, $title=''){
  $t = h($text);
  $ttl = $title ? ' title="'.h($title).'"' : '';
  if ($href) return '<a class="chip" href="'.h($href).'"'.$ttl.'>'.$t.'</a>';
  return '<span class="chip"'.$ttl.'>'.$t.'</span>';
}

function table_exists(mysqli $db, string $table): bool {
  $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
  $st = $db->prepare($sql);
  $st->bind_param("s",$table);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_column();
  $st->close();
  return $ok;
}

/* ====================== Daten laden ====================== */

/** Vorlagen + Metadaten (eigene zuerst) */
$sqlVorlagen = "
  SELECT v.id, v.name, v.description, v.user_id, u.name AS creator,
         (SELECT COUNT(*)
            FROM unterkategorien uk
           WHERE uk.vorlage_id=v.id AND (uk.projekt_id IS NULL OR uk.projekt_id=0)
         ) AS nodes_count
  FROM struktur_vorlagen v
  LEFT JOIN benutzer u ON u.id = v.user_id
  ORDER BY (v.user_id = ?) DESC, v.name ASC
";
$st = $mysqli->prepare($sqlVorlagen);
$st->bind_param("i",$userId);
$st->execute();
$vorlagen = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/** Projekte */
$projekte = $mysqli->query("SELECT id, name, status FROM projekte ORDER BY name")->fetch_all(MYSQLI_ASSOC);

/** Mapping: Vorlage → Projekte (falls Tabelle existiert) */
$mapTplProjects = [];  // vorlage_id => [ ['projekt_id'=>..,'name'=>..], ... ]
if (table_exists($mysqli,'projekt_vorlagen')) {
  if ($res = $mysqli->query("
      SELECT pv.vorlage_id, p.id AS projekt_id, p.name
      FROM projekt_vorlagen pv
      JOIN projekte p ON p.id = pv.projekt_id
      ORDER BY p.name ASC
  ")) {
    while($r = $res->fetch_assoc()){
      $vid = (int)$r['vorlage_id'];
      if (!isset($mapTplProjects[$vid])) $mapTplProjects[$vid] = [];
      $mapTplProjects[$vid][] = ['projekt_id'=>(int)$r['projekt_id'], 'name'=>$r['name']];
    }
  }
}

/** Strukturstatus je Projekt: hat es schon Ordner? */
$projectHasStruct = []; // projekt_id => count
if ($res = $mysqli->query("
  SELECT projekt_id, COUNT(*) as c
  FROM unterkategorien
  WHERE projekt_id IS NOT NULL
  GROUP BY projekt_id
")) {
  while($r = $res->fetch_assoc()){
    $projectHasStruct[(int)$r['projekt_id']] = (int)$r['c'];
  }
}

/** Statistik */
$totalTemplates = count($vorlagen);
$usedTemplateIds = array_keys($mapTplProjects);
$unusedTemplates = 0;
foreach ($vorlagen as $v) {
  if (!in_array((int)$v['id'], $usedTemplateIds, true)) $unusedTemplates++;
}
$projectsWithStruct = count($projectHasStruct);
$projectsTotal = count($projekte);
$projectsWithoutStruct = max(0, $projectsTotal - $projectsWithStruct);
?>
<link rel="stylesheet" href="/pendenz.com/assets/css/projekt_baum.css">
<link rel="stylesheet" href="/pendenz.com/assets/css/vorlagen_dashboard.css">
<script defer src="/pendenz.com/assets/js/projekt_baum.js"></script>

<main class="container vd-container">

  <!-- Hero / Kopf -->
  <header class="vd-hero">
    <div class="vd-hero-text">
      <h1>Vorlagen &amp; Nutzung</h1>
      <p>Verwalte Struktur-Vorlagen, sieh ihre Verwendung in Projekten und baue sie gezielt ein.</p>
    </div>
    <div class="vd-hero-actions">
      <a href="vorlage_bearbeiten.php" class="btn btn-primary">➕ Neue Vorlage erstellen</a>
    </div>
  </header>

  <!-- Statistik-Kacheln -->
  <section class="vd-stats">
    <div class="stat-card hover-pop">
      <div class="stat-num"><?= (int)$totalTemplates ?></div>
      <div class="stat-label">Vorlagen gesamt</div>
    </div>
    <div class="stat-card hover-pop">
      <div class="stat-num"><?= (int)$unusedTemplates ?></div>
      <div class="stat-label">davon ungenutzt</div>
    </div>
    <div class="stat-card hover-pop">
      <div class="stat-num"><?= (int)$projectsWithStruct ?> / <?= (int)$projectsTotal ?></div>
      <div class="stat-label">Projekte mit Struktur</div>
    </div>
    <div class="stat-card hover-pop">
      <div class="stat-num"><?= (int)$projectsWithoutStruct ?></div>
      <div class="stat-label">Projekte ohne Struktur</div>
    </div>
  </section>

  <!-- VORLAGENLISTE -->
  <section class="card vd-card">
    <div class="card-head">
      <h2>Vorlagen</h2>
      <div class="vd-tools">
        <form method="get" class="vd-search">
          <input type="text" class="input" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Vorlagen suchen …">
          <button class="btn btn-small" type="submit">Suchen</button>
          <?php if (!empty($_GET['q'])): ?>
            <a class="btn btn-small btn-secondary" href="vorlagen.php">Zurücksetzen</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <div class="vd-table-wrap">
      <table class="table vd-table modern">
        <thead>
          <tr>
            <th style="min-width:240px;">Name</th>
            <th>Beschreibung</th>
            <th style="width:120px;">Knoten</th>
            <th style="min-width:240px;">Verwendet in Projekten</th>
            <th style="min-width:300px;">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $needle = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        foreach($vorlagen as $row):
          if ($needle!=='') {
            $hay = mb_strtolower(($row['name'].' '.$row['description']));
            if (mb_strpos($hay, mb_strtolower($needle)) === false) continue;
          }
          $vid = (int)$row['id'];
          $creator = ($row['user_id']==$userId) ? 'Ich' : ($row['creator'] ?: 'System');
          $nodesCount = (int)($row['nodes_count'] ?? 0);
          $usedIn = $mapTplProjects[$vid] ?? [];
        ?>
          <tr class="row-hover">
            <td>
              <a class="vd-name" href="vorlage_bearbeiten.php?id=<?= $vid ?>"><?= h($row['name']) ?></a>
              <div class="vd-meta">
                <?= chip($creator) ?>
                <?php if($nodesCount>0): ?>
                  <?= chip($nodesCount.' Knoten', null, 'Anzahl Ordner in der Vorlage') ?>
                <?php endif; ?>
              </div>
            </td>
            <td><div class="vd-desc"><?= h($row['description'] ?? '') ?></div></td>
            <td class="vd-center"><?= (int)$nodesCount ?></td>
            <td>
              <?php if(empty($usedIn)): ?>
                <span class="muted">Noch in keinem Projekt</span>
              <?php else: ?>
                <div class="chips">
                  <?php foreach($usedIn as $u):
                    $pid = (int)$u['projekt_id'];
                    $has = (int)($projectHasStruct[$pid] ?? 0);
                    $title = $has ? ($has.' Ordner vorhanden') : 'Noch keine Ordner';
                    echo chip($u['name'], 'projekt_baum.php?projekt_id='.$pid, $title);
                  endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="vd-actions-cell">
              <div class="vd-actions-row">
                <a href="vorlage_bearbeiten.php?id=<?= $vid ?>" class="btn btn-small">✏ Bearbeiten</a>
                <a href="vorlage_bearbeiten.php?copy=<?= $vid ?>" class="btn btn-small">📋 Kopieren</a>
                <a href="vorlage_loeschen.php?id=<?= $vid ?>" class="btn btn-small btn-danger" onclick="return confirm('Vorlage wirklich löschen? Projektstrukturen bleiben erhalten.')">🗑 Löschen</a>
              </div>
              <form action="struktur_einbauen.php" method="get" class="vd-inline-form">
                <input type="hidden" name="vorlage_id" value="<?= $vid ?>">
                <select name="projekt_id">
                  <?php foreach($projekte as $p):
                    $pid = (int)$p['id'];
                    $has = (int)($projectHasStruct[$pid] ?? 0);
                    $lbl = $has ? $p['name'].' ('.$has.' Ordner)' : $p['name'].' (leer)';
                  ?>
                    <option value="<?= $pid ?>"><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-small">➡ In Projekt einbauen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="vd-hint">
      ⚠️ <strong>Sicherheits-Hinweis:</strong> Das Löschen einer <em>Vorlage</em> entfernt keine bereits in Projekten vorhandenen Ordnerstrukturen.
      Projektordner haben immer ein <code>projekt_id</code> und bleiben bestehen.
    </div>
  </section>

  <!-- PROJEKTE & STRUKTURSTATUS (jetzt unten, volle Breite) -->
  <section class="card vd-card">
    <div class="card-head">
      <h2>Projekte &amp; Strukturstatus</h2>
      <div class="vd-tools">
        <a class="btn btn-secondary btn-small" href="#top">Nach oben</a>
      </div>
    </div>

    <div class="vd-table-wrap">
      <table class="table vd-table modern">
        <thead>
          <tr>
            <th>Projekt</th>
            <th style="min-width:220px;">Vorlagen-Zuordnung</th>
            <th style="width:160px;">Ordnerstruktur</th>
            <th style="min-width:260px;">Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($projekte as $p):
          $pid = (int)$p['id'];
          // Vorlagen, die diesem Projekt zugewiesen sind:
          $tpls = [];
          foreach($mapTplProjects as $vid => $arr){
            foreach($arr as $x){
              if ((int)$x['projekt_id'] === $pid){
                $tpls[] = $vid;
              }
            }
          }
          $hasCount = (int)($projectHasStruct[$pid] ?? 0);
        ?>
          <tr class="row-hover">
            <td>
              <a class="vd-name" href="projekt_baum.php?projekt_id=<?= $pid ?>"><?= h($p['name']) ?></a>
              <?php if(!empty($p['status'])): ?>
                <div class="vd-meta"><?= chip($p['status']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if(empty($tpls)): ?>
                <span class="muted">Keine Vorlage zugewiesen</span>
              <?php else: ?>
                <div class="chips">
                  <?php foreach($tpls as $tid):
                    $t = null;
                    foreach($vorlagen as $_v) if ((int)$_v['id']===$tid) { $t=$_v; break; }
                    if ($t) echo chip($t['name'], 'vorlage_bearbeiten.php?id='.$tid, 'Vorlage öffnen');
                  endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="vd-center">
              <?php if($hasCount>0): ?>
                <span class="badge badge-ok">✔ <?= $hasCount ?> Ordner</span>
              <?php else: ?>
                <span class="badge badge-warn">✖ keine Ordner</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="vd-actions-row">
                <a class="btn btn-small" href="projekt_baum.php?projekt_id=<?= $pid ?>">👁️ Struktur ansehen</a>
                <form action="struktur_einbauen.php" method="get" class="vd-inline-form">
                  <input type="hidden" name="projekt_id" value="<?= $pid ?>">
                  <select name="vorlage_id">
                    <?php foreach($vorlagen as $v): ?>
                      <option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-small">➕ Vorlage einbauen</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
