<?php
// tools/mieterspiegel/index.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/authz.php';
require_once __DIR__ . '/../../includes/functions.php';
require_login();

$PREFIX = function_exists('site_prefix') ? site_prefix() : '/pendenz.com/';

if (($_SESSION['rolle'] ?? null) !== 'superadmin') {
    die("Zugriff verweigert: Nur Superadmin hat Zugriff auf dieses Tool.");
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* === Helpers === */
function firstOfMonth(string $ym): string { // "2025-10" -> "2025-10-01"
  if (preg_match('~^\d{4}-\d{2}$~', $ym)) return $ym.'-01';
  return date('Y-m-01');
}
function monthKey(DateTime $d): string { return $d->format('Y-m'); }
function dt(string $s): DateTime { return new DateTime($s); }
function daysOverlap(DateTime $from, ?DateTime $to, DateTime $mStart, DateTime $mEnd): int {
  $to = $to ? clone $to : null;
  if (!$to) { $to = (clone $mEnd); } // open-ended treat as month end at most
  $a = max($from, $mStart);
  $b = min($to,   $mEnd);
  if ($a > $b) return 0;
  return (int)$a->diff($b)->days + 1;
}
function monthBoundaries(DateTime $any): array {
  $mStart = dt($any->format('Y-m-01'));
  $mEnd   = dt($any->format('Y-m-t')); // last day of month
  return [$mStart, $mEnd, (int)$mEnd->format('t')];
}

/* === Eingaben === */
$projId = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
$whg    = trim($_GET['wohnung'] ?? '');

$vonYm  = trim($_GET['von'] ?? '');
$bisYm  = trim($_GET['bis'] ?? '');
if (!preg_match('~^\d{4}-\d{2}$~', $vonYm)) $vonYm = date('Y-m', strtotime('-11 months'));
if (!preg_match('~^\d{4}-\d{2}$~', $bisYm)) $bisYm = date('Y-m');

$von = dt(firstOfMonth($vonYm));
$bis = dt(firstOfMonth($bisYm));
$bis->modify('last day of this month');

/* === Projekte laden === */
$projekte=[];
if ($res=$mysqli->query("SELECT id,name FROM projekte ORDER BY name")) {
  while($r=$res->fetch_assoc()) $projekte[]=$r;
  $res->close();
}

/* === Wohnungen (aus Konten + optional wohnungen) === */
$wohnungen=[];
if ($projId>0) {
  // aus Kontobuchungen
  $st = $mysqli->prepare("SELECT DISTINCT COALESCE(wohnung_label,'') AS wl
                          FROM liegenschafts_konto
                          WHERE liegenschaft_id=? AND COALESCE(wohnung_label,'')<>'' 
                          ORDER BY wl");
  $st->bind_param("i",$projId); $st->execute();
  $rs=$st->get_result(); while($r=$rs->fetch_assoc()) $wohnungen[]=$r['wl']; $st->close();
  // plus explizit hinterlegte wohnungen
  $st = $mysqli->prepare("SELECT label FROM wohnungen WHERE liegenschaft_id=? ORDER BY label");
  $st->bind_param("i",$projId); $st->execute();
  $rs=$st->get_result(); while($r=$rs->fetch_assoc()) if(!in_array($r['label'],$wohnungen,true)) $wohnungen[]=$r['label'];
  $st->close();
}

/* === POST: Perioden & Anpassungen pflegen === */
$flash = "";
if ($_SERVER['REQUEST_METHOD']==='POST' && $projId>0 && $whg!=='') {
  try{
    $act = $_POST['action'] ?? '';
    if ($act==='add_schedule') {
      $valid_from = $_POST['valid_from'] ?: date('Y-m-01');
      $valid_to   = $_POST['valid_to']   ?: null;
      $soll       = (float)($_POST['soll_miete'] ?? 0);
      $typ        = $_POST['typ'] ?? 'vertrag_start';
      $bem        = trim($_POST['bemerkung'] ?? '');
      $autoClose  = !empty($_POST['autoclose_prev']);

      if ($autoClose) {
        // letztes offenes Intervall schließen (valid_to = Tag vor neuem Start)
        $st=$mysqli->prepare("UPDATE miete_schedules 
                              SET valid_to = DATE_SUB(?, INTERVAL 1 DAY) 
                              WHERE liegenschaft_id=? AND wohnung_label=? AND (valid_to IS NULL OR valid_to>?)");
        $st->bind_param("siss",$valid_from,$projId,$whg,$valid_from);
        $st->execute(); $st->close();
      }

      $st=$mysqli->prepare("INSERT INTO miete_schedules (liegenschaft_id,wohnung_label,valid_from,valid_to,soll_miete,typ,bemerkung)
                            VALUES (?,?,?,?,?,?,?)");
      $st->bind_param("isssdss",$projId,$whg,$valid_from,$valid_to,$soll,$typ,$bem);
      $st->execute(); $st->close();
      $flash="✅ Zeitraum gespeichert.";
    }
    elseif ($act==='close_schedule') {
      $id = (int)($_POST['id'] ?? 0);
      $to = $_POST['close_to'] ?: date('Y-m-t');
      $st=$mysqli->prepare("UPDATE miete_schedules SET valid_to=? WHERE id=? AND liegenschaft_id=? AND wohnung_label=?");
      $st->bind_param("siis",$to,$id,$projId,$whg);
      $st->execute(); $st->close();
      $flash="✅ Zeitraum beendet.";
    }
    elseif ($act==='delete_schedule') {
      $id = (int)($_POST['id'] ?? 0);
      $st=$mysqli->prepare("DELETE FROM miete_schedules WHERE id=? AND liegenschaft_id=? AND wohnung_label=?");
      $st->bind_param("iis",$id,$projId,$whg);
      $st->execute(); $st->close();
      $flash="🗑️ Zeitraum gelöscht.";
    }
    elseif ($act==='add_adjust') {
      $monat = firstOfMonth($_POST['monat'] ?? '');
      $delta = (float)($_POST['delta'] ?? 0);
      $grund = trim($_POST['grund'] ?? '');
      $st=$mysqli->prepare("INSERT INTO miete_adjustments (liegenschaft_id,wohnung_label,monat,delta,grund)
                            VALUES (?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE delta=VALUES(delta), grund=VALUES(grund)");
      $st->bind_param("issds",$projId,$whg,$monat,$delta,$grund);
      $st->execute(); $st->close();
      $flash="✅ Anpassung gespeichert.";
    }
    elseif ($act==='delete_adjust') {
      $id=(int)($_POST['id'] ?? 0);
      $st=$mysqli->prepare("DELETE FROM miete_adjustments WHERE id=? AND liegenschaft_id=? AND wohnung_label=?");
      $st->bind_param("iis",$id,$projId,$whg);
      $st->execute(); $st->close();
      $flash="🗑️ Anpassung gelöscht.";
    }
  }catch(Throwable $e){ $flash="❌ ".$e->getMessage(); }
}

/* === Daten laden === */
$schedules=[];
if ($projId>0 && $whg!=='') {
  $st=$mysqli->prepare("SELECT * FROM miete_schedules 
                        WHERE liegenschaft_id=? AND wohnung_label=?
                        ORDER BY valid_from ASC, id ASC");
  $st->bind_param("is",$projId,$whg);
  $st->execute();
  $rs=$st->get_result(); while($r=$rs->fetch_assoc()) $schedules[]=$r;
  $st->close();

  $adjust=[];
  $st=$mysqli->prepare("SELECT * FROM miete_adjustments 
                        WHERE liegenschaft_id=? AND wohnung_label=? 
                          AND monat BETWEEN ? AND ?
                        ORDER BY monat");
  $vonStr = $von->format('Y-m-01'); $bisStr = $bis->format('Y-m-01');
  $st->bind_param("isss",$projId,$whg,$vonStr,$bisStr);
  $st->execute();
  $rs=$st->get_result(); while($r=$rs->fetch_assoc()) $adjust[$r['monat']][]=$r;
  $st->close();
}

/* === Zahlungen (Ist) pro Monat === */
$payments=[];
if ($projId>0 && $whg!=='') {
  $st=$mysqli->prepare("SELECT DATE_FORMAT(buchungsdatum,'%Y-%m-01') AS m, COALESCE(SUM(betrag),0) s
                        FROM liegenschafts_konto
                        WHERE liegenschaft_id=? AND COALESCE(wohnung_label,'')=? 
                          AND buchungsdatum BETWEEN ? AND ?
                        GROUP BY m ORDER BY m");
  $vonDate = $von->format('Y-m-01'); $bisDate = $bis->format('Y-m-t');
  $st->bind_param("isss",$projId,$whg,$vonDate,$bisDate);
  $st->execute();
  $rs=$st->get_result(); while($r=$rs->fetch_assoc()) $payments[$r['m']] = (float)$r['s'];
  $st->close();
}

/* === Soll je Monat berechnen (pro rata) === */
function sollForMonth(array $schedules, DateTime $mStart, DateTime $mEnd, int $daysInMonth): float {
  $sum = 0.0;
  foreach ($schedules as $s) {
    $sFrom = dt($s['valid_from']);
    $sTo   = $s['valid_to'] ? dt($s['valid_to']) : null;
    $over  = daysOverlap($sFrom, $sTo, $mStart, $mEnd);
    if ($over>0) {
      $ratio = $over / $daysInMonth; // pro rata nach Kalendertagen
      $sum += (float)$s['soll_miete'] * $ratio;
    }
  }
  return $sum;
}

/* === UI === */
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/nav_dispatch.php';
?>
<div class="container" style="max-width:1200px;margin:16px auto;">
  <h2>Mieterspiegel</h2>

  <?php if($flash): ?>
    <div class="card" style="background:#f4fffa;border-left:4px solid #1abc9c;padding:8px 10px;margin:8px 0;">
      <?= h($flash) ?>
    </div>
  <?php endif; ?>

  <form method="get" class="card" style="display:grid;grid-template-columns:220px 1fr 140px 140px auto;gap:.5rem;align-items:end;">
    <div>
      <label>Projekt</label>
      <select name="projekt_id" onchange="this.form.submit()">
        <option value="0">— wählen —</option>
        <?php foreach($projekte as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $projId===(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Wohnung/Einheit</label>
      <select name="wohnung" <?= $projId>0 ? '' : 'disabled' ?> onchange="this.form.submit()">
        <option value="">— wählen —</option>
        <?php foreach($wohnungen as $wl): ?>
          <option value="<?= h($wl) ?>" <?= $whg===$wl?'selected':'' ?>><?= h($wl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Von (Monat)</label>
      <input type="month" name="von" value="<?= h($vonYm) ?>">
    </div>
    <div>
      <label>Bis (Monat)</label>
      <input type="month" name="bis" value="<?= h($bisYm) ?>">
    </div>
    <div>
      <button class="btn" type="submit" style="margin-top:.9rem;">Anzeigen</button>
    </div>
  </form>

  <?php if ($projId>0 && $whg!==''): ?>
  <div class="grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <!-- Tarifverlauf -->
    <div class="card">
      <h3>Tarifverlauf</h3>
      <form method="post" style="display:grid;grid-template-columns:repeat(6,1fr);gap:.4rem;align-items:end;">
        <input type="hidden" name="action" value="add_schedule">
        <input type="hidden" name="projekt_id" value="<?= (int)$projId ?>">
        <input type="hidden" name="wohnung" value="<?= h($whg) ?>">
        <div>
          <label>Beginn</label>
          <input type="date" name="valid_from" required>
        </div>
        <div>
          <label>Ende</label>
          <input type="date" name="valid_to" placeholder="leer = offen">
        </div>
        <div>
          <label>Soll/Monat</label>
          <input type="number" step="0.01" name="soll_miete" required>
        </div>
        <div>
          <label>Typ</label>
          <select name="typ">
            <option value="vertrag_start">vertrag_start</option>
            <option value="wiedervermietung">wiedervermietung</option>
            <option value="index">index</option>
            <option value="reduktion">reduktion</option>
            <option value="korrektur">korrektur</option>
          </select>
        </div>
        <div>
          <label>Bemerkung</label>
          <input type="text" name="bemerkung">
        </div>
        <label style="display:flex;gap:.4rem;align-items:center;margin-bottom:.1rem;">
          <input type="checkbox" name="autoclose_prev" value="1" checked> Vorperiode beenden
        </label>
        <div style="grid-column:1/-1;">
          <button class="btn" type="submit">➕ Zeitraum hinzufügen</button>
        </div>
      </form>

      <?php
      if ($schedules):
      ?>
      <table class="table" style="width:100%;margin-top:.5rem;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="text-align:left;">Von</th>
            <th style="text-align:left;">Bis</th>
            <th style="text-align:right;">Soll/Monat</th>
            <th>Typ</th>
            <th>Bemerkung</th>
            <th>Aktionen</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($schedules as $s): ?>
          <tr>
            <td><?= h($s['valid_from']) ?></td>
            <td><?= h($s['valid_to'] ?? '—') ?></td>
            <td style="text-align:right;"><?= number_format((float)$s['soll_miete'],2,',',' ') ?></td>
            <td><?= h($s['typ']) ?></td>
            <td><?= h($s['bemerkung']) ?></td>
            <td style="white-space:nowrap;">
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="close_schedule">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <input type="date" name="close_to" value="<?= h(date('Y-m-t')) ?>">
                <button class="btn btn-small" type="submit">Beenden</button>
              </form>
              <form method="post" style="display:inline;" onsubmit="return confirm('Diesen Zeitraum löschen?')">
                <input type="hidden" name="action" value="delete_schedule">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-small btn-danger" type="submit">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <p class="muted">Noch kein Tarif hinterlegt.</p>
      <?php endif; ?>
    </div>

    <!-- Monatsanpassungen -->
    <div class="card">
      <h3>Monats-Anpassungen</h3>
      <form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:.4rem;align-items:end;">
        <input type="hidden" name="action" value="add_adjust">
        <div>
          <label>Monat</label>
          <input type="month" name="monat" value="<?= h($vonYm) ?>">
        </div>
        <div>
          <label>Delta (±)</label>
          <input type="number" step="0.01" name="delta" required>
        </div>
        <div>
          <label>Grund</label>
          <input type="text" name="grund" placeholder="z. B. Reduktion">
        </div>
        <div>
          <button class="btn" type="submit">➕ Speichern</button>
        </div>
      </form>

      <?php
      $flatAdj=[];
      foreach ($adjust as $m=>$arr) foreach ($arr as $a) $flatAdj[]=$a;
      if ($flatAdj):
      ?>
      <table class="table" style="width:100%;margin-top:.5rem;border-collapse:collapse;">
        <thead><tr><th>Monat</th><th style="text-align:right;">Delta</th><th>Grund</th><th>Aktion</th></tr></thead>
        <tbody>
          <?php foreach($flatAdj as $a): ?>
            <tr>
              <td><?= h(substr($a['monat'],0,7)) ?></td>
              <td style="text-align:right;"><?= number_format((float)$a['delta'],2,',',' ') ?></td>
              <td><?= h($a['grund']) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Anpassung löschen?')" style="display:inline;">
                  <input type="hidden" name="action" value="delete_adjust">
                  <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-small btn-danger" type="submit">Löschen</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <p class="muted">Keine Anpassungen im gewählten Zeitraum.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Mieterspiegel -->
  <div class="card" style="margin-top:12px;">
    <h3>Mieterspiegel für <?= h($whg) ?> · <?= h($vonYm) ?> bis <?= h($bisYm) ?></h3>
    <table class="table" style="width:100%;border-collapse:collapse;">
      <thead>
        <tr>
          <th>Monat</th>
          <th style="text-align:right;">Soll (pro rata)</th>
          <th style="text-align:right;">Anpassungen</th>
          <th style="text-align:right;">Erwartet</th>
          <th style="text-align:right;">Zahlungen</th>
          <th style="text-align:right;">Differenz</th>
          <th style="text-align:right;">Saldo kumuliert</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $run = 0.0;
        $cursor = clone $von;
        while ($cursor <= $bis):
          [$mStart,$mEnd,$daysInMonth] = monthBoundaries($cursor);
          $mKey = $mStart->format('Y-m-01');
          $soll = sollForMonth($schedules, $mStart, $mEnd, $daysInMonth);
          $adj  = 0.0;
          if (isset($adjust[$mKey])) foreach ($adjust[$mKey] as $a) $adj += (float)$a['delta'];
          $exp  = $soll + $adj;
          $pay  = (float)($payments[$mKey] ?? 0.0);
          $diff = $exp - $pay;       // >0 = offen (Mieterschuld)
          $run += $diff;
        ?>
        <tr>
          <td><?= h($cursor->format('Y-m')) ?></td>
          <td style="text-align:right;"><?= number_format($soll,2,',',' ') ?></td>
          <td style="text-align:right;"><?= number_format($adj,2,',',' ') ?></td>
          <td style="text-align:right;"><?= number_format($exp,2,',',' ') ?></td>
          <td style="text-align:right;"><?= number_format($pay,2,',',' ') ?></td>
          <td style="text-align:right;"><?= number_format($diff,2,',',' ') ?></td>
          <td style="text-align:right;font-weight:600;"><?= number_format($run,2,',',' ') ?></td>
        </tr>
        <?php
          $cursor->modify('first day of next month');
        endwhile; ?>
      </tbody>
    </table>
    <p class="muted">Hinweis: Positive Differenz/Saldo = **Mieterschuld**, negative = Guthaben.</p>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
