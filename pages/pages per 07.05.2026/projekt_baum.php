<?php
// pages/projekt_baum.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_login();
require_once __DIR__ . '/../includes/functions.php'; // e(), page_url(), ...
require_once __DIR__ . '/../includes/user_ui.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

/* -------- Input -------- */
$projekt_id = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_GET['id'] ?? 0);
if ($projekt_id <= 0) die("❌ projekt_id fehlt.");

/* -------- DB helpers -------- */
function table_exists(mysqli $db, string $table): bool {
  $st = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
  $st->bind_param("s",$table); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $st = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
  $st->bind_param("ss",$table,$col); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/* -------- Projekt laden -------- */
$stmt = $mysqli->prepare("SELECT id, name, adresse, status, bild" . (column_exists($mysqli,'projekte','ordner_vorlage_id') ? ", ordner_vorlage_id" : "") . " FROM projekte WHERE id=?");
$stmt->bind_param("i",$projekt_id); $stmt->execute();
$projekt = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$projekt) die("❌ Projekt nicht gefunden.");

/* -------- Vorlage-ID ermitteln (neu) --------
 * 1) projekte.ordner_vorlage_id (falls Spalte vorhanden und gesetzt)
 * 2) Mapping-Tabelle (projekt_vorlagen oder projekte_vorlagen)
 * 3) Default aus ordner_vorlagen (is_default=1) oder niedrigste id
 */
$vorlage_id = null;
if (isset($projekt['ordner_vorlage_id']) && (int)$projekt['ordner_vorlage_id'] > 0) {
  $vorlage_id = (int)$projekt['ordner_vorlage_id'];
} else {
  if (table_exists($mysqli,'projekt_vorlagen')) {
    $st = $mysqli->prepare("SELECT vorlage_id FROM projekt_vorlagen WHERE projekt_id=? LIMIT 1");
    $st->bind_param("i",$projekt_id); $st->execute();
    $vorlage_id = (int)($st->get_result()->fetch_column() ?? 0); $st->close();
  } elseif (table_exists($mysqli,'projekte_vorlagen')) {
    $st = $mysqli->prepare("SELECT vorlage_id FROM projekte_vorlagen WHERE projekt_id=? LIMIT 1");
    $st->bind_param("i",$projekt_id); $st->execute();
    $vorlage_id = (int)($st->get_result()->fetch_column() ?? 0); $st->close();
  }
  if (!$vorlage_id && table_exists($mysqli,'ordner_vorlagen')) {
    // Default nehmen
    if (column_exists($mysqli,'ordner_vorlagen','is_default')) {
      $q = $mysqli->query("SELECT id FROM ordner_vorlagen WHERE is_default=1 ORDER BY id ASC LIMIT 1");
      $vorlage_id = (int)($q->fetch_column() ?? 0);
    }
    if (!$vorlage_id) {
      $q = $mysqli->query("SELECT id FROM ordner_vorlagen ORDER BY id ASC LIMIT 1");
      $vorlage_id = (int)($q->fetch_column() ?? 0);
    }
  }
}
if (!$vorlage_id) $vorlage_id = 0;

/* -------- Daten laden: FS-Index einmalig -------- */
$fs = [
  'all'=>[],            // rel_path => row
  'by_parent'=>[]       // parent_rel_path => [rows]
];
$st = $mysqli->prepare("SELECT rel_path, parent_rel_path, name, is_dir, size, mtime FROM fs_nodes WHERE project_id=? ORDER BY is_dir DESC, name ASC");
$st->bind_param("i",$projekt_id); $st->execute();
$res = $st->get_result();
while($r = $res->fetch_assoc()){
  $rp = (string)$r['rel_path'];
  $pp = (string)($r['parent_rel_path'] ?? '');
  $fs['all'][$rp] = $r;
  $fs['by_parent'][$pp][] = $r;
}
$st->close();

/* -------- Template laden (neu) --------
 * Tabellen: unterkategorien (id, vorlage_id, parent_id, label_default, name_variable, sort)
 */
$template_available = $vorlage_id > 0 && table_exists($mysqli,'unterkategorien');
$tpl_nodes = [];     // id => node
$tpl_roots = [];     // root ids
if ($template_available) {
  $st = $mysqli->prepare("
    SELECT id, parent_id, label_default, COALESCE(name_variable,'') AS name_variable,
           " . (column_exists($mysqli,'unterkategorien','sort') ? "sort" : "0 AS sort") . "
    FROM unterkategorien
    WHERE vorlage_id=?
    ORDER BY parent_id IS NULL DESC, sort ASC, id ASC
  ");
  $st->bind_param("i",$vorlage_id); $st->execute();
  $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();

  // Baum konstruieren
  foreach($rows as $r){
    $id = (int)$r['id'];
    $tpl_nodes[$id] = [
      'id'=>$id,
      'parent_id'=> $r['parent_id']!==null ? (int)$r['parent_id'] : null,
      'label'=> trim($r['label_default'] ?? ''),
      'var'  => trim($r['name_variable'] ?? ''),
      'sort' => (int)($r['sort'] ?? 0),
      'children'=>[],
      'rel_path'=>'' // wird unten gefüllt
    ];
  }
  foreach($tpl_nodes as $id=>&$n){
    $pid = $n['parent_id'];
    if ($pid && isset($tpl_nodes[$pid])) {
      $tpl_nodes[$pid]['children'][] = $id;
    } else {
      $tpl_roots[] = $id;
    }
  } unset($n);

  // rel_path für jeden Node bauen (aus label_default Kette)
  $calcPath = function($id) use (&$tpl_nodes, &$calcPath): string {
    $node = $tpl_nodes[$id];
    $label = $node['label'] !== '' ? $node['label'] : ($node['var'] ?: ('Ordner-'.$id));
    if ($node['parent_id'] && isset($tpl_nodes[$node['parent_id']])) {
      return $calcPath($node['parent_id']).'/'.$label;
    }
    return $label;
  };
  foreach($tpl_nodes as $id=>&$n){ $n['rel_path'] = $calcPath($id); } unset($n);
}

/* -------- Render-Helfer -------- */
function count_direct_in_fs(array $fs_by_parent, string $rel): array {
  $dirs=0;$files=0;
  foreach($fs_by_parent[$rel] ?? [] as $row){
    if ((int)$row['is_dir']===1) $dirs++; else $files++;
  }
  return ['dirs'=>$dirs,'files'=>$files];
}

function render_tpl_node(mysqli $db, array $tpl_nodes, array $fs, int $id, int $level, int $projekt_id){
  $n = $tpl_nodes[$id];
  $rel = $n['rel_path'];
  $exists = isset($fs['all'][$rel]) && (int)$fs['all'][$rel]['is_dir']===1;
  $counts = count_direct_in_fs($fs['by_parent'], $rel);
  $indent = str_repeat('&nbsp;&nbsp;&nbsp;', max(0,$level));
  $openUrl = page_url('files.php?projekt_id='.$projekt_id.'&path='.rawurlencode($rel));
  ?>
  <tr>
    <td><?= $indent ?><?= $exists ? '✅' : '⚠︎' ?> <?= e(basename($rel)) ?></td>
    <td><?= e($rel) ?></td>
    <td><?= (int)$counts['dirs'] ?></td>
    <td><?= (int)$counts['files'] ?></td>
    <td><a class="btn" href="<?= e($openUrl) ?>">Öffnen</a></td>
  </tr>
  <?php
  // Kinder
  $children = $n['children'];
  usort($children, fn($a,$b)=>($tpl_nodes[$a]['sort']<=>$tpl_nodes[$b]['sort']) ?: ($a<=>$b));
  foreach($children as $cid){
    render_tpl_node($db,$tpl_nodes,$fs,$cid,$level+1,$projekt_id);
  }
}

/* -------- Unbekannte (nicht in Vorlage) -------- */
$tpl_paths = [];
if ($template_available) {
  foreach($tpl_nodes as $n) $tpl_paths[$n['rel_path']] = true;
}
$unknown_dirs = [];
foreach ($fs['all'] as $rp=>$row){
  if ((int)$row['is_dir']!==1) continue;
  if ($template_available && isset($tpl_paths[$rp])) continue;
  // Root-Ordner ohne Vorlage oder beliebige Exoten
  if (!$template_available || !isset($tpl_paths[$rp])) $unknown_dirs[] = $row;
}

/* -------- Styles -------- */
?>
<style>
.tree-wrap{max-width:1100px;margin:24px auto}
.card{border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden}
.card h2{margin:0;padding:12px 14px;border-bottom:1px solid #eef2f7}
.toolbar{display:flex;gap:8px;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafafa}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:left}
.badge{display:inline-block;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:3px 8px;font-size:12px}
.note{padding:10px 12px;color:#374151}
</style>

<main class="container" style="padding:16px;">
  <div class="tree-wrap">
    <div class="card">
      <h2>🌳 Projektstruktur – <?= e($projekt['name'] ?? ('Projekt #'.$projekt_id)) ?></h2>
      <div class="toolbar">
        <?php if ($template_available): ?>
          <span class="badge">Vorlage-ID: <?= (int)$vorlage_id ?></span>
          <span class="badge">Modus: Vorlage ➜ FS</span>
        <?php else: ?>
          <span class="badge">Modus: Nur Dateisystem (keine Vorlage gefunden)</span>
        <?php endif; ?>
        <a class="btn" href="<?= e(page_url('files.php?projekt_id='.$projekt_id)) ?>">📁 Dateibrowser</a>
        <a class="btn" href="<?= e(page_url('projekt_dashboard.php?id='.$projekt_id)) ?>">↩ Zurück zum Dashboard</a>
      </div>

      <?php if ($template_available): ?>
        <div class="note">✅ = Ordner existiert in <code>fs_nodes</code>, ⚠︎ = (noch) nicht vorhanden. Namen stammen aus der Vorlage (<code>unterkategorien</code>).</div>
        <table class="table">
          <thead>
            <tr>
              <th>Ordner</th>
              <th>Relativer Pfad</th>
              <th>Unterordner</th>
              <th>Dateien</th>
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Wurzeln in stabiler Reihenfolge
            usort($tpl_roots, fn($a,$b)=>($tpl_nodes[$a]['sort']<=>$tpl_nodes[$b]['sort']) ?: ($a<=>$b));
            foreach($tpl_roots as $rid){
              render_tpl_node($mysqli,$tpl_nodes,$fs,$rid,0,$projekt_id);
            }
            ?>
          </tbody>
        </table>

        <?php if (!empty($unknown_dirs)): ?>
          <h2 style="margin:16px 14px 8px;">➕ Nicht in Vorlage (zusätzliche Ordner)</h2>
          <table class="table">
            <thead>
              <tr>
                <th>Ordner</th>
                <th>Relativer Pfad</th>
                <th>Unterordner</th>
                <th>Dateien</th>
                <th>Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php
              foreach($unknown_dirs as $r){
                $rp = (string)$r['rel_path'];
                $counts = count_direct_in_fs($fs['by_parent'],$rp);
                $openUrl = page_url('files.php?projekt_id='.$projekt_id.'&path='.rawurlencode($rp));
                echo '<tr>';
                echo '<td>ℹ︎ '.e(basename($rp)).'</td>';
                echo '<td>'.e($rp).'</td>';
                echo '<td>'.(int)$counts['dirs'].'</td>';
                echo '<td>'.(int)$counts['files'].'</td>';
                echo '<td><a class="btn" href="'.e($openUrl).'">Öffnen</a></td>';
                echo '</tr>';
              }
              ?>
            </tbody>
          </table>
        <?php endif; ?>

      <?php else: // Fallback: Nur FS-Baum ohne Vorlage ?>
        <div class="note">Keine Vorlagen-Tabellen gefunden – zeige Dateisystem-Baum.</div>
        <table class="table">
          <thead>
            <tr>
              <th>Ordner</th>
              <th>Relativer Pfad</th>
              <th>Unterordner</th>
              <th>Dateien</th>
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php
            // Root-Ebene: parent_rel_path NULL oder ''
            $roots = $fs['by_parent'][''] ?? [];
            foreach($roots as $row){
              if ((int)$row['is_dir']!==1) continue;
              $rp = (string)$row['rel_path'];
              $counts = count_direct_in_fs($fs['by_parent'],$rp);
              $openUrl = page_url('files.php?projekt_id='.$projekt_id.'&path='.rawurlencode($rp));
              echo '<tr>';
              echo '<td>✅ '.e($row['name']).'</td>';
              echo '<td>'.e($rp).'</td>';
              echo '<td>'.(int)$counts['dirs'].'</td>';
              echo '<td>'.(int)$counts['files'].'</td>';
              echo '<td><a class="btn" href="'.e($openUrl).'">Öffnen</a></td>';
              echo '</tr>';
              // 1. Ebene Kinder
              foreach($fs['by_parent'][$rp] ?? [] as $c1){
                if ((int)$c1['is_dir']!==1) continue;
                $rp1 = (string)$c1['rel_path'];
                $counts1 = count_direct_in_fs($fs['by_parent'],$rp1);
                $openUrl1 = page_url('files.php?projekt_id='.$projekt_id.'&path='.rawurlencode($rp1));
                echo '<tr>';
                echo '<td>&nbsp;&nbsp;&nbsp;✅ '.e($c1['name']).'</td>';
                echo '<td>'.e($rp1).'</td>';
                echo '<td>'.(int)$counts1['dirs'].'</td>';
                echo '<td>'.(int)$counts1['files'].'</td>';
                echo '<td><a class="btn" href="'.e($openUrl1).'">Öffnen</a></td>';
                echo '</tr>';
              }
            }
            ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</main>
