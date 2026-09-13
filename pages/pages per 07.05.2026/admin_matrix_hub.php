<?php
// pages/admin_matrix_hub.php
if (session_status()===PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/csrf.php';
require_login();

$role = $_SESSION['rolle'] ?? 'gast';
if (!in_array($role, ['admin','superadmin'], true)) { http_response_code(403); exit('Keine Berechtigung'); }

$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

/* --- Stammdaten laden --- */
$allUsers=[];  $res=$mysqli->query("SELECT id,name,rolle,firma_name FROM benutzer ORDER BY name");
while($r=$res->fetch_assoc()) $allUsers[(int)$r['id']]=$r;

$projekte=[];  $res=$mysqli->query("SELECT id,name FROM projekte ORDER BY name");
while($r=$res->fetch_assoc()) $projekte[(int)$r['id']]=$r;

$teams=[];     $res=$mysqli->query("SELECT id,name FROM teams ORDER BY name");
while($r=$res->fetch_assoc()) $teams[(int)$r['id']]=$r;

$firmen=[];    $res=$mysqli->query("SELECT id,name FROM firmen ORDER BY name");
while($r=$res->fetch_assoc()) $firmen[(int)$r['id']]=$r;

/* --- Mappings direkte Zuordnungen --- */
$userProjekte=[]; $res=$mysqli->query("SELECT benutzer_id,projekt_id FROM benutzer_projekte");
while($r=$res->fetch_assoc()) $userProjekte[(int)$r['benutzer_id']][]=(int)$r['projekt_id'];

$userTeams=[];    $res=$mysqli->query("SELECT benutzer_id,team_id FROM benutzer_teams");
while($r=$res->fetch_assoc()) $userTeams[(int)$r['benutzer_id']][]=(int)$r['team_id'];

/* --- Team<->Projekt (M2M) --- */
$teamProjekte=[];   // team_id => [projekt_id,...]
$projektTeams=[];   // projekt_id => [team_id,...]
$res=$mysqli->query("SELECT team_id, projekt_id FROM team_projekte");
if ($res) {
  while($r=$res->fetch_assoc()){
    $tid=(int)$r['team_id']; $pid=(int)$r['projekt_id'];
    $teamProjekte[$tid][]=$pid;
    $projektTeams[$pid][]=$tid;
  }
}

/* --- Indirekte Mitglieder pro Projekt via SQL-Join --- */
$projektIndirect = [];
$sql = "
  SELECT tp.projekt_id, bt.benutzer_id, t.name AS team_name
  FROM team_projekte tp
  JOIN benutzer_teams bt ON bt.team_id = tp.team_id
  JOIN teams t          ON t.id = tp.team_id
";
$res = $mysqli->query($sql);
if ($res) {
  while ($r=$res->fetch_assoc()) {
    $pid = (int)$r['projekt_id'];
    $uid = (int)$r['benutzer_id'];
    $projektIndirect[$pid][$uid][] = $r['team_name'];
  }
}

/* --- Firmen-Overrides laden (include/exclude) --- */
$firmInc=[]; $firmExc=[];
$mysqli->query("CREATE TABLE IF NOT EXISTS firmen_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  firma_id INT NOT NULL,
  benutzer_id INT NOT NULL,
  mode ENUM('include','exclude') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_firma_user (firma_id,benutzer_id),
  CONSTRAINT fk_fo_firma FOREIGN KEY (firma_id) REFERENCES firmen(id) ON DELETE CASCADE,
  CONSTRAINT fk_fo_user  FOREIGN KEY (benutzer_id) REFERENCES benutzer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$res=$mysqli->query("SELECT firma_id,benutzer_id,mode FROM firmen_overrides");
if($res){
  while($r=$res->fetch_assoc()){
    $fid=(int)$r['firma_id']; $uid=(int)$r['benutzer_id'];
    if($r['mode']==='include') $firmInc[$fid][$uid]=true;
    else                       $firmExc[$fid][$uid]=true;
  }
}

/* Helper: automatische Firmen-Mitglieder = alle Benutzer mit exakt passendem firma_name */
function firm_auto_members(mysqli $db, string $firmName): array {
  $out=[];
  $st=$db->prepare("SELECT id FROM benutzer WHERE firma_name = ?");
  $st->bind_param("s",$firmName); $st->execute();
  $rs=$st->get_result();
  while($r=$rs->fetch_assoc()) $out[]=(int)$r['id'];
  $st->close();
  return $out;
}

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/nav_dispatch.php';
?>
<div id="amh-root" class="amh" data-csrf="<?= htmlspecialchars(csrf_token(),ENT_QUOTES) ?>" data-prefix="<?= htmlspecialchars($PREFIX,ENT_QUOTES) ?>">
  <div class="amh-toolbar">
    <a id="btn-go-users" class="amh-btn-light" href="<?= htmlspecialchars($PREFIX) ?>pages/benutzer.php">← Zur Benutzerseite</a>
  </div>

  <h1>Admin-Hub: Projekte • Teams • Firmen • Benutzer</h1>

  <!-- 1) Projekte -->
  <details class="amh-acc" open>
    <summary class="amh-sum">1) Projekte verwalten</summary>
    <div class="amh-sec">

      <?php foreach($projekte as $pid=>$p): ?>
      <?php
        // Direkte Mitglieder (benutzer_projekte)
        $projDirect=[];
        foreach($allUsers as $uid=>$u){
          if(in_array($pid, $userProjekte[$uid] ?? [], true)) $projDirect[]=$uid;
        }

        // Indirekte Mitglieder (über team_projekte + benutzer_teams)
        $indirectMap  = $projektIndirect[$pid] ?? [];            // uid => [teamName,...]
        $projIndirect = array_values(array_diff(array_keys($indirectMap), $projDirect));

        // Teams im Projekt + verfügbare Teams
        $teamsImProjekt = $projektTeams[$pid] ?? [];
        $teamsVerfuegbar = array_diff(array_keys($teams), $teamsImProjekt);
      ?>
      <div class="amh-card" data-project-card="<?= (int)$pid ?>">
        <div class="amh-head">
          <div class="amh-title"><?= htmlspecialchars($p['name']) ?></div>
          <div class="amh-tools">
            <label class="amh-toggle">
              <input type="checkbox" class="js-toggle-direct" data-projekt-id="<?= (int)$pid ?>">
              <span>Nur direkte Mitglieder anzeigen</span>
            </label>
            <input type="text" class="amh-filter" placeholder="Benutzer filtern…" data-scope="proj-<?= (int)$pid ?>">
          </div>
        </div>

        <div class="amh-grid">
          <!-- Mitglieder -->
          <div>
            <div class="amh-sub">Mitglieder</div>
            <div class="amh-legend">
              <span class="dot dot-direct"></span> direkt
              <span class="dot dot-indirect"></span> indirekt (via Team)
            </div>

            <div class="amh-badges" id="proj-members-<?= (int)$pid ?>">
              <?php if(!$projDirect && !$projIndirect): ?><div class="amh-hint">Noch keine Mitglieder.</div><?php endif; ?>

              <?php foreach($projDirect as $uid): $u=$allUsers[$uid]; ?>
                <span class="amh-badge <?= htmlspecialchars($u['rolle']) ?>"
                      data-kind="project" data-projekt-id="<?= (int)$pid ?>" data-user-id="<?= (int)$uid ?>"
                      data-indirect="0" title="Direkt im Projekt">
                  <span class="dot dot-direct"></span>
                  <?= htmlspecialchars($u['name']) ?>
                  <button class="amh-x" title="Aus Projekt entfernen">×</button>
                </span>
              <?php endforeach; ?>

              <?php foreach($projIndirect as $uid): $u=$allUsers[$uid]; $via = implode(', ', $indirectMap[$uid]); ?>
                <span class="amh-badge amh-badge-indirect <?= htmlspecialchars($u['rolle']) ?>"
                      data-kind="project-indirect" data-projekt-id="<?= (int)$pid ?>" data-user-id="<?= (int)$uid ?>"
                      data-indirect="1" title="Indirekt via: <?= htmlspecialchars($via) ?>">
                  <span class="dot dot-indirect"></span>
                  <?= htmlspecialchars($u['name']) ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Teams im Projekt -->
          <div>
            <div class="amh-sub">Teams im Projekt</div>
            <div class="amh-badges" id="proj-teams-<?= (int)$pid ?>">
              <?php if(!$teamsImProjekt): ?><div class="amh-hint">Keine Teams zugeordnet.</div><?php endif; ?>
              <?php foreach($teamsImProjekt as $tid): ?>
                <span class="amh-badge amh-team-badge"
                      data-team-id="<?= (int)$tid ?>" data-projekt-id="<?= (int)$pid ?>">
                  <?= htmlspecialchars($teams[$tid]['name'] ?? ('Team #'.$tid)) ?>
                  <button class="amh-x amh-team-x" title="Team aus diesem Projekt lösen">×</button>
                </span>
              <?php endforeach; ?>
            </div>

            <div class="amh-sub" style="margin-top:10px;">Team hinzufügen</div>
            <div class="amh-list">
              <?php foreach($teamsVerfuegbar as $tid): ?>
                <button class="amh-btn-light js-add-team-to-project"
                        data-team-id="<?= (int)$tid ?>" data-projekt-id="<?= (int)$pid ?>">
                  + <?= htmlspecialchars($teams[$tid]['name']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Benutzer hinzufügen -->
          <div>
            <div class="amh-sub">Benutzer hinzufügen</div>
            <div class="amh-list amh-filter-scope" data-scope="proj-<?= (int)$pid ?>">
              <?php foreach($allUsers as $uid=>$u):
                if(!in_array($pid,$userProjekte[$uid]??[],true)): ?>
                <button class="amh-btn js-add-user-to-project"
                        data-user-id="<?= (int)$uid ?>" data-projekt-id="<?= (int)$pid ?>">
                  + <?= htmlspecialchars($u['name']) ?>
                </button>
              <?php endif; endforeach; ?>
            </div>
          </div>

        </div>
      </div>
      <?php endforeach; ?>

    </div>
  </details>

  <!-- 2) Teams -->
  <details class="amh-acc">
    <summary class="amh-sum">2) Teams verwalten</summary>
    <div class="amh-sec">

      <!-- Team erstellen -->
      <div class="amh-card">
        <div class="amh-sub">Neues Team</div>
        <div class="amh-row">
          <input id="team-name" class="amh-input" placeholder="Teamname">
          <select id="team-projekt" class="amh-input">
            <option value="">(Keinem Projekt direkt zuordnen)</option>
            <?php foreach($projekte as $pid=>$p): ?>
              <option value="<?= (int)$pid ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button id="btn-create-team" class="amh-btn">Team erstellen</button>
        </div>
      </div>

      <?php foreach($teams as $tid=>$t): ?>
      <?php
        $teamMembers=[]; foreach($allUsers as $uid=>$u){ if(in_array($tid,$userTeams[$uid]??[],true)) $teamMembers[]=$uid; }
        $teamProj = $teamProjekte[$tid] ?? [];
        $teamProjAvailable = array_diff(array_keys($projekte), $teamProj);
      ?>
      <div class="amh-card">
        <div class="amh-head">
          <div class="amh-title">Team: <?= htmlspecialchars($t['name']) ?></div>
          <div class="amh-tools">
            <button class="amh-btn-danger js-delete-team" data-team-id="<?= (int)$tid ?>">Team löschen</button>
          </div>
        </div>

        <div class="amh-grid">
          <!-- Zugeordnete Projekte -->
          <div>
            <div class="amh-sub">Zugeordnete Projekte</div>
            <div class="amh-badges" id="team-proj-<?= (int)$tid ?>">
              <?php if(!$teamProj): ?><div class="amh-hint">Keinem Projekt zugeordnet.</div><?php endif; ?>
              <?php foreach($teamProj as $pid): ?>
                <span class="amh-badge amh-teamproj-badge"
                      data-team-id="<?= (int)$tid ?>" data-projekt-id="<?= (int)$pid ?>">
                  <?= htmlspecialchars($projekte[$pid]['name']) ?>
                  <button class="amh-x js-remove-team-from-proj" title="Team aus diesem Projekt lösen">×</button>
                </span>
              <?php endforeach; ?>
            </div>

            <div class="amh-sub" style="margin-top:10px;">Projekt hinzufügen</div>
            <div class="amh-list">
              <?php foreach($teamProjAvailable as $pid): ?>
                <button class="amh-btn-light js-add-proj-to-team"
                        data-team-id="<?= (int)$tid ?>" data-projekt-id="<?= (int)$pid ?>">
                  + <?= htmlspecialchars($projekte[$pid]['name']) ?>
                </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Mitglieder -->
          <div>
            <div class="amh-sub">Mitglieder</div>
            <div class="amh-badges" id="team-members-<?= (int)$tid ?>">
              <?php if(!$teamMembers): ?><div class="amh-hint">Noch keine Mitglieder.</div><?php endif; ?>
              <?php foreach($teamMembers as $uid): $u=$allUsers[$uid]; ?>
                <span class="amh-badge <?= htmlspecialchars($u['rolle']) ?>"
                      data-kind="team" data-team-id="<?= (int)$tid ?>" data-user-id="<?= (int)$uid ?>">
                  <?= htmlspecialchars($u['name']) ?> <button class="amh-x" title="Entfernen">×</button>
                </span>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Benutzer hinzufügen -->
          <div>
            <div class="amh-sub">Benutzer hinzufügen</div>
            <div class="amh-list">
              <?php foreach($allUsers as $uid=>$u):
                if(!in_array($tid,$userTeams[$uid]??[],true)): ?>
                <button class="amh-btn js-add-user-to-team"
                        data-user-id="<?= (int)$uid ?>" data-team-id="<?= (int)$tid ?>">
                  + <?= htmlspecialchars($u['name']) ?>
                </button>
              <?php endif; endforeach; ?>
            </div>
          </div>

        </div>
      </div>
      <?php endforeach; ?>

    </div>
  </details>

  <!-- 3) Firmen -->
  <details class="amh-acc">
    <summary class="amh-sum">3) Firmen verwalten</summary>
    <div class="amh-sec">

      <?php if(!$firmen): ?>
        <div class="amh-card"><div class="amh-hint">Keine Firmenvorlagen vorhanden. Lege Firmen unter <a href="<?= htmlspecialchars($PREFIX) ?>pages/firmen.php">Firmen-Vorlagen</a> an.</div></div>
      <?php endif; ?>

      <?php foreach($firmen as $fid=>$f): ?>
      <?php
        $auto = firm_auto_members($mysqli, (string)$f['name']);            // automatisch nach firma_name
        $inc  = array_keys($firmInc[$fid] ?? []);                           // manuell inkludiert
        $exc  = array_keys($firmExc[$fid] ?? []);                           // explizit ausgeschlossen

        $final = array_unique(array_merge($auto, $inc));                    // auto ∪ include
        $final = array_values(array_diff($final, $exc));                    // minus exclude

        // Aufteilen in Anzeigegruppen
        $finalAuto   = array_values(array_diff($final, $inc));              // finale, die auto sind
        $finalManual = array_values(array_intersect($final, $inc));         // finale, die manuell inkludiert sind

        // Kandidaten zum Hinzufügen = alle - final
        $addable = array_diff(array_keys($allUsers), $final);
      ?>
      <div class="amh-card">
        <div class="amh-head">
          <div class="amh-title">Firma: <?= htmlspecialchars($f['name']) ?></div>
        </div>

        <div class="amh-grid">
          <!-- Mitglieder (berechnet) -->
          <div>
            <div class="amh-sub">Mitglieder (berechnet)</div>
            <div class="amh-legend">
              <span class="dot dot-auto"></span> automatisch (Firma im Profil)
              <span class="dot dot-manual"></span> manuell hinzugefügt
            </div>
            <div class="amh-badges" id="firm-members-<?= (int)$fid ?>">
              <?php if(!$final): ?><div class="amh-hint">Keine Mitglieder.</div><?php endif; ?>

              <?php foreach($finalAuto as $uid): $u=$allUsers[$uid]; ?>
                <span class="amh-badge amh-firm-badge"
                      data-kind="firm-auto" data-firma-id="<?= (int)$fid ?>" data-user-id="<?= (int)$uid ?>"
                      title="Automatisch via Profil-Firma">
                  <span class="dot dot-auto"></span>
                  <?= htmlspecialchars($u['name']) ?>
                  <button class="amh-x js-firm-exclude" title="Für diese Firma ausschließen">×</button>
                </span>
              <?php endforeach; ?>

              <?php foreach($finalManual as $uid): $u=$allUsers[$uid]; ?>
                <span class="amh-badge amh-firm-badge"
                      data-kind="firm-manual" data-firma-id="<?= (int)$fid ?>" data-user-id="<?= (int)$uid ?>"
                      title="Manuell hinzugefügt">
                  <span class="dot dot-manual"></span>
                  <?= htmlspecialchars($u['name']) ?>
                  <button class="amh-x js-firm-include-remove" title="Manuelles Hinzufügen rückgängig">×</button>
                </span>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Ausnahmen / Overrides -->
          <div>
            <div class="amh-sub">Ausnahmen</div>
            <div class="amh-legend">
              <span class="dot dot-manual"></span> manuell hinzugefügt
              <span class="dot dot-exc"></span> ausgeschlossen
            </div>

            <div class="amh-sub" style="margin-top:4px;">Manuell hinzugefügt</div>
            <div class="amh-badges" id="firm-inc-<?= (int)$fid ?>">
              <?php if(!$inc): ?><div class="amh-hint">(keine)</div><?php endif; ?>
              <?php foreach($inc as $uid): $u=$allUsers[$uid]; ?>
                <span class="amh-badge"
                      data-kind="firm-manual" data-firma-id="<?= (int)$fid ?>" data-user-id="<?= (int)$uid ?>">
                  <span class="dot dot-manual"></span>
                  <?= htmlspecialchars($u['name']) ?>
                  <button class="amh-x js-firm-include-remove" title="Manuelles Hinzufügen löschen">×</button>
                </span>
              <?php endforeach; ?>
            </div>

            <div class="amh-sub" style="margin-top:10px;">Ausgeschlossen</div>
            <div class="amh-badges" id="firm-exc-<?= (int)$fid ?>">
              <?php if(!$exc): ?><div class="amh-hint">(keine)</div><?php endif; ?>
              <?php foreach($exc as $uid): $u=$allUsers[$uid]; ?>
                <span class="amh-badge"
                      data-kind="firm-exclude" data-firma-id="<?= (int)$fid ?>" data-user-id="<?= (int)$uid ?>">
                  <span class="dot dot-exc"></span>
                  <?= htmlspecialchars($u['name']) ?>
                  <button class="amh-x js-firm-exclude-remove" title="Ausschluss entfernen">×</button>
                </span>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Benutzer hinzufügen -->
          <div>
            <div class="amh-sub">Benutzer hinzufügen</div>
            <div class="amh-list">
              <?php foreach($addable as $uid): $u=$allUsers[$uid]; ?>
                <button class="amh-btn js-firm-include-add"
                        data-firma-id="<?= (int)$fid ?>" data-user-id="<?= (int)$uid ?>">
                  + <?= htmlspecialchars($u['name']) ?>
                </button>
              <?php endforeach; ?>
              <?php if(!$addable): ?><div class="amh-hint">Niemand verfügbar.</div><?php endif; ?>
            </div>
          </div>

        </div>
      </div>
      <?php endforeach; ?>

    </div>
  </details>

  <!-- 4) Benutzer -->
  <details class="amh-acc" id="sec-users">
    <summary class="amh-sum">4) Benutzer verwalten</summary>
    <div class="amh-sec">

      <?php foreach($allUsers as $uid=>$u): ?>
      <?php $userProj = $userProjekte[$uid] ?? []; $userTm = $userTeams[$uid] ?? []; ?>
      <div class="amh-card" id="user-<?= (int)$uid ?>">
        <div class="amh-head">
          <div class="amh-title">Benutzer: <?= htmlspecialchars($u['name']) ?></div>
          <div class="amh-tools">
            <a class="amh-btn-light" href="<?= htmlspecialchars($PREFIX) ?>pages/benutzer.php?view=<?= (int)$uid ?>#profile-form">Profil öffnen</a>
          </div>
        </div>
        <div class="amh-grid">

          <div>
            <div class="amh-sub">Projekte</div>
            <div class="amh-badges" id="user-proj-<?= (int)$uid ?>">
              <?php if(!$userProj): ?><div class="amh-hint">Keinem Projekt zugeordnet.</div><?php endif; ?>
              <?php foreach($userProj as $pid): ?>
                <span class="amh-badge" data-kind="project" data-projekt-id="<?= (int)$pid ?>" data-user-id="<?= (int)$uid ?>">
                  <?= htmlspecialchars($projekte[$pid]['name'] ?? ('#'.$pid)) ?> <button class="amh-x" title="Entfernen">×</button>
                </span>
              <?php endforeach; ?>
            </div>
            <div class="amh-sub" style="margin-top:10px;">Projekt hinzufügen</div>
            <div class="amh-list">
              <?php foreach($projekte as $pid=>$p):
                if(!in_array($pid,$userProj,true)): ?>
                <button class="amh-btn js-add-user-to-project"
                        data-user-id="<?= (int)$uid ?>" data-projekt-id="<?= (int)$pid ?>">
                  + <?= htmlspecialchars($p['name']) ?>
                </button>
              <?php endif; endforeach; ?>
            </div>
          </div>

          <div>
            <div class="amh-sub">Teams</div>
            <div class="amh-badges" id="user-team-<?= (int)$uid ?>">
              <?php if(!$userTm): ?><div class="amh-hint">Keinem Team zugeordnet.</div><?php endif; ?>
              <?php foreach($userTm as $tid): ?>
                <span class="amh-badge" data-kind="team" data-team-id="<?= (int)$tid ?>" data-user-id="<?= (int)$uid ?>">
                  <?= htmlspecialchars($teams[$tid]['name'] ?? ('#'.$tid)) ?> <button class="amh-x" title="Entfernen">×</button>
                </span>
              <?php endforeach; ?>
            </div>
            <div class="amh-sub" style="margin-top:10px;">Team hinzufügen</div>
            <div class="amh-list">
              <?php foreach($teams as $tid=>$t):
                if(!in_array($tid,$userTm,true)): ?>
                <button class="amh-btn js-add-user-to-team"
                        data-user-id="<?= (int)$uid ?>" data-team-id="<?= (int)$tid ?>">
                  + <?= htmlspecialchars($t['name']) ?>
                </button>
              <?php endif; endforeach; ?>
            </div>
          </div>

        </div>
      </div>
      <?php endforeach; ?>

    </div>
  </details>
  
</div>

<style>
/* minimalistisches, eingebettetes Styling */
.amh{max-width:1200px;margin:20px auto;font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;color:#0f172a}
h1{margin:0 0 12px;font-size:22px}
.amh-toolbar{display:flex;justify-content:flex-end;margin-bottom:8px}
.amh-acc{border:1px solid #e2e8f0;border-radius:10px;margin:10px 0;background:#f8fafc}
.amh-sum{cursor:pointer;padding:10px 12px;font-weight:700}
.amh-sec{padding:10px 12px 14px;border-top:1px solid #e2e8f0}
.amh-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px;margin:10px 0}
.amh-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.amh-title{font-weight:700}
.amh-tools{display:flex;gap:10px;align-items:center}
.amh-toggle{display:flex;gap:6px;align-items:center;font-size:13px}
.amh-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:8px}
@media (max-width:1000px){.amh-grid{grid-template-columns:1fr}}
.amh-sub{font-weight:600;margin:6px 0}
.amh-row{display:flex;gap:8px;flex-wrap:wrap}
.amh-input{padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;min-width:200px}
.amh-btn{padding:6px 10px;border:none;border-radius:8px;background:#0b5cff;color:#fff;cursor:pointer}
.amh-btn:hover{background:#1e40af}
.amh-btn-light{padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;cursor:pointer;text-decoration:none;color:#0f172a}
.amh-btn-light:hover{background:#f1f5f9}
.amh-btn-danger{padding:6px 10px;border:none;border-radius:8px;background:#b00020;color:#fff;cursor:pointer}
.amh-btn-danger:hover{background:#8f0019}
.amh-badges{min-height:42px;border:1px dashed #cbd5e1;border-radius:10px;padding:6px}
.amh-badge{display:inline-flex;align-items:center;gap:6px;background:#0b5cff;color:#fff;border-radius:999px;padding:4px 10px;margin:4px 4px 0 0;font-size:12px}
.amh-badge.admin{background:#b00020}.amh-badge.superadmin{background:#1e3a8a}
.amh-badge-indirect{background:#475569}
.amh-x{background:transparent;border:0;color:#fff;font-weight:700;cursor:pointer}
.amh-list{display:flex;flex-wrap:wrap;gap:6px}
.amh-filter{padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px}
.amh-hint{font-size:12px;color:#475569}
.amh-legend{display:flex;gap:12px;align-items:center;margin:4px 0 6px 0;font-size:12px;color:#475569}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.dot-direct{background:#0b5cff}
.dot-indirect{background:#475569}
/* Firmenfarben */
.dot-auto{background:#2563eb}
.dot-manual{background:#16a34a}
.dot-exc{background:#b00020}
</style>

<script>
(function(){
  const root   = document.getElementById('amh-root');
  const CSRF   = root.dataset.csrf;
  const PREFIX = root.dataset.prefix;
  const ASSIGN_URL = PREFIX + 'assets/ajax/assign_membership.php';
  const CREATE_TEAM_URL = PREFIX + 'assets/ajax/create_team.php';
  const DELETE_TEAM_URL = PREFIX + 'assets/ajax/delete_team.php';
  const FIRM_URL = PREFIX + 'assets/ajax/firmen_overrides.php';

  const postJSON = (url, data) =>
    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF': (document.getElementById('amh-root')?.dataset?.csrf) || ''
      },
      body: JSON.stringify(data)
    }).then(r => r.json());

  // ==== Hash-Handling: Benutzer-Abschnitt öffnen + Back-Link auf konkretes Profil setzen
  document.addEventListener('DOMContentLoaded', function(){
    const back = document.getElementById('btn-go-users');
    if (location.hash && location.hash.startsWith('#user-')) {
      const sec = document.getElementById('sec-users');
      if (sec) sec.open = true;
      const uid = parseInt(location.hash.replace('#user-',''),10);
      if (uid && back) back.href = PREFIX + 'pages/benutzer.php?view=' + uid + '#profile-form';
      const target = document.getElementById('user-' + uid);
      if (target) target.scrollIntoView({behavior:'smooth', block:'start'});
    }
  });

  // ==== Projekte: UI ====
  document.querySelectorAll('.js-toggle-direct').forEach(chk=>{
    chk.addEventListener('change', ()=>{
      const pid = chk.dataset.projektId;
      const wrap = document.getElementById('proj-members-'+pid);
      if (!wrap) return;
      wrap.querySelectorAll('[data-indirect="1"]').forEach(el=>{
        el.style.display = chk.checked ? 'none' : 'inline-flex';
      });
    });
  });

  document.querySelectorAll('.js-add-user-to-project').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const userId = +btn.dataset.userId;
      const projId = +btn.dataset.projektId;
      const res = await postJSON(ASSIGN_URL, {csrf:CSRF, action:'add', user_id:userId, projekt_id:projId});
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });

  document.querySelectorAll('.js-add-user-to-team').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const userId = +btn.dataset.userId;
      const teamId = +btn.dataset.teamId;
      const res = await postJSON(ASSIGN_URL, {csrf:CSRF, action:'add', user_id:userId, team_id:teamId});
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });

  document.querySelectorAll('.js-add-team-to-project').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const teamId = +btn.dataset.teamId;
      const projId = +btn.dataset.projektId;
      const res = await postJSON(ASSIGN_URL, {csrf:CSRF, tp_action:'add', team_id:teamId, projekt_id:projId});
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });

  function wireRemoveTeamFromProject(scope){
    const btn = scope.querySelector('.amh-team-x'); if(!btn) return;
    btn.addEventListener('click', async ()=>{
      const teamId = +scope.dataset.teamId;
      const projId = +scope.dataset.projektId;
      const res = await postJSON(ASSIGN_URL, {csrf:CSRF, tp_action:'remove', team_id:teamId, projekt_id:projId});
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  }
  document.querySelectorAll('.amh-team-badge').forEach(wireRemoveTeamFromProject);

  document.querySelectorAll('.js-add-proj-to-team').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const teamId = +btn.dataset.teamId;
      const projId = +btn.dataset.projektId;
      const res = await postJSON(ASSIGN_URL, {csrf:CSRF, tp_action:'add', team_id:teamId, projekt_id:projId});
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });

  function wireRemoveTeamProjBadge(scope){
    const btn = scope.querySelector('.js-remove-team-from-proj'); if(!btn) return;
    btn.addEventListener('click', async ()=>{
      const teamId = +scope.dataset.teamId;
      const projId = +scope.dataset.projektId;
      const res = await postJSON(ASSIGN_URL, {csrf:CSRF, tp_action:'remove', team_id:teamId, projekt_id:projId});
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  }
  document.querySelectorAll('.amh-teamproj-badge').forEach(wireRemoveTeamProjBadge);

  function wireRemoveUserBadge(scope){
    const btn = scope.querySelector('.amh-x'); if(!btn) return;
    btn.addEventListener('click', async (e)=>{
      e.stopPropagation();
      const kind = scope.dataset.kind;
      const uid = +scope.dataset.userId;
      const payload = { csrf: CSRF, action:'remove', user_id: uid };
      if (kind==='project') payload.projekt_id = +scope.dataset.projektId;
      else if (kind==='team') payload.team_id = +scope.dataset.teamId;
      else return;
      const res = await postJSON(ASSIGN_URL, payload);
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  }
  document.querySelectorAll('.amh-badge[data-kind="project"],.amh-badge[data-kind="team"]').forEach(wireRemoveUserBadge);

  // Team erstellen / löschen
  const btnCreate = document.getElementById('btn-create-team');
  if(btnCreate){
    btnCreate.addEventListener('click', async ()=>{
      const name = document.getElementById('team-name').value.trim();
      const proj = document.getElementById('team-projekt').value || null;
      if(!name){ alert('Bitte Teamname angeben'); return; }
      const res = await postJSON(CREATE_TEAM_URL, { csrf: CSRF, name, projekt_id: proj?parseInt(proj,10):null });
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  }

  document.querySelectorAll('.js-delete-team').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const tid = +btn.dataset.teamId;
      if(!confirm('Team wirklich löschen? (Mitgliedschaften & Team-Projekte werden entfernt)')) return;
      const res = await postJSON(DELETE_TEAM_URL, { csrf: CSRF, team_id: tid });
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });

  // Filter pro Projekt
  document.querySelectorAll('.amh-filter').forEach(inp=>{
    inp.addEventListener('input', ()=>{
      const scope = inp.dataset.scope;
      const q = inp.value.trim().toLowerCase();
      document.querySelectorAll(`.amh-filter-scope[data-scope="${scope}"] .amh-btn`).forEach(b=>{
        b.style.display = b.textContent.toLowerCase().includes(q) ? 'inline-block' : 'none';
      });
    });
  });

  // ==== Firmen: Overrides ====
  // Manuell hinzufügen
  document.querySelectorAll('.js-firm-include-add').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const fid = +btn.dataset.firmaId;
      const uid = +btn.dataset.userId;
      const res = await postJSON(FIRM_URL, { csrf: CSRF, action:'include_add', firma_id: fid, user_id: uid });
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });
  // Manuelles Hinzufügen wieder entfernen
  document.querySelectorAll('.js-firm-include-remove').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const wrap = btn.closest('[data-firma-id][data-user-id]');
      const fid = +wrap.dataset.firmaId;
      const uid = +wrap.dataset.userId;
      const res = await postJSON(FIRM_URL, { csrf: CSRF, action:'include_remove', firma_id: fid, user_id: uid });
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });
  // Automatischen Mitglied ausschließen
  document.querySelectorAll('.js-firm-exclude').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const wrap = btn.closest('[data-firma-id][data-user-id]');
      const fid = +wrap.dataset.firmaId;
      const uid = +wrap.dataset.userId;
      const res = await postJSON(FIRM_URL, { csrf: CSRF, action:'exclude_add', firma_id: fid, user_id: uid });
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });
  // Ausschluss aufheben
  document.querySelectorAll('.js-firm-exclude-remove').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const wrap = btn.closest('[data-firma-id][data-user-id]');
      const fid = +wrap.dataset.firmaId;
      const uid = +wrap.dataset.userId;
      const res = await postJSON(FIRM_URL, { csrf: CSRF, action:'exclude_remove', firma_id: fid, user_id: uid });
      if(!res.success){ alert(res.error||'Fehler'); return; }
      location.reload();
    });
  });

})();
</script>

<?php require_once __DIR__.'/../includes/footer.php'; ?>
