<?php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

$projektId = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
if ($projektId <= 0) die("❌ Kein Projekt angegeben.");

// Projekt laden
$st = $mysqli->prepare("SELECT id, name, adresse, status FROM projekte WHERE id=?");
$st->bind_param("i", $projektId);
$st->execute();
$projekt = $st->get_result()->fetch_assoc();
$st->close();
if (!$projekt) die("❌ Projekt nicht gefunden.");

/* ----------------------------- Daten laden ----------------------------- */
function loadTree(mysqli $db, int $projektId, ?int $parentId=null): array {
    $sql = "SELECT id, vorlage_id, projekt_id, parent_id, name_variable, label_default, position, code
            FROM unterkategorien
            WHERE projekt_id=? AND ".($parentId===null ? "parent_id IS NULL" : "parent_id=?")."
            ORDER BY position, id";
    $st = $db->prepare($sql);
    if ($parentId===null) $st->bind_param("i", $projektId);
    else $st->bind_param("ii", $projektId, $parentId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    foreach($rows as &$r){
        $r['children'] = loadTree($db, $projektId, $r['id']);
    }
    return $rows;
}

$tree = loadTree($mysqli, $projektId);

/* ----------------------------- Farben pro Ebene ----------------------------- */
function depthColors(int $level): array {
    $d = max(0, min(9, $level));
    static $palette = [
        0 => ['#FEF08A', '#F59E0B'], // 0: Gelb (Projekt)
        1 => ['#FCA5A5', '#EF4444'], // 1: Rot (Objekt)
        2 => ['#93C5FD', '#3B82F6'], // 2: Blau (Wohnung)
        3 => ['#A7F3D0', '#10B981'], // 3: Grün (Zimmer)
        4 => ['#E9D5FF', '#8B5CF6'], // 4: Violett
        5 => ['#FED7AA', '#F97316'], // 5: Orange
        6 => ['#A5F3FC', '#06B6D4'], // 6: Türkis
        7 => ['#FBCFE8', '#EC4899'], // 7: Pink
        8 => ['#E2E8F0', '#64748B'], // 8: Slate
        9 => ['#C7D2FE', '#6366F1'], // 9: Indigo
    ];
    return $palette[$d];
}

/* ----------------------------- Files anzeigen ----------------------------- */
/** Dateien im Ordner /uploads/projekte/<projekt_id>/<code>/ anzeigen */
function renderFilesFor(string $baseDir){
    if(!is_dir($baseDir)) return;
    $items = array_values(array_diff(scandir($baseDir), ['.','..']));
    if(empty($items)) return;
    echo "<ul class='files'>";
    foreach($items as $it){
        $p = $baseDir . DIRECTORY_SEPARATOR . $it;
        $isDir = is_dir($p);
        $sizeB = $isDir ? '' : (string)filesize($p) . " B";
        $mtime = date('Y-m-d H:i:s', filemtime($p));
        $icon = $isDir ? "📁" : "📄";
        echo "<li>{$icon} ".htmlspecialchars($it)." ".($sizeB? " · {$sizeB}" : "")." · {$mtime}</li>";
    }
    echo "</ul>";
}

/* ----------------------------- Legenden-Helfer ----------------------------- */
/** Beispiel-Code pro Ebene erzeugen: Ebene 0 => "1", Ebene 1 => "1_1", ... */
function exampleCodeForDepth(int $depth): string {
    if ($depth <= 0) return "1";
    return "1" . str_repeat("_1", $depth);
}

/* ----------------------------- Baum-Renderer ----------------------------- */
function renderTree(array $nodes, int $projektId, int $level=0){
    // Linienfarbe pro Level
    [, $bdLevel] = depthColors($level);
    echo "<ul class='branch' data-depth='{$level}' style='--line-color: {$bdLevel}; --connector: 14px;'>";
    foreach($nodes as $n){
        $icon = match($n['name_variable']){
            'projekt' => '🏢','objekt'=>'🏠','wohnung'=>'🏘️','zimmer'=>'🛏️',
            default=>'📦'
        };
        $code = $n['code'] ?: ('id'.$n['id']);
        $parentAttr = $n['parent_id']===null ? '' : (string)$n['parent_id'];

        [$bgNode,$bdNode] = depthColors($level);
        $style = "background: {$bgNode} !important; border: 1px solid {$bdNode} !important;";

        echo "<li data-id='{$n['id']}' data-parent-id='{$parentAttr}' data-projekt-id='{$projektId}' data-code='".htmlspecialchars($code,ENT_QUOTES)."'>";
        echo   "<div class='tree-node' style=\"{$style}\">";
        echo     "<div class='node-main'>";
        echo       "<span class='drag-handle' title='Reihenfolge ziehen'>⋮⋮</span>";

        // 🔢 Code-Badge statt Punkte
        echo       "<span class='code-badge' title='Struktur-Code'>{$code}</span>";

        echo       "<span class='title'>{$icon} ".htmlspecialchars($n['label_default'])."</span>";
        echo     "</div>";
        echo     "<div class='actions' role='toolbar' aria-label='Aktionen'>";
        // hier nur „sanfte“ Aktionen im Projekt (kein Indent, um Stabilität zu wahren)
        echo       "<a class='btn btn-tiny' href='unterkategorie_bearbeiten.php?id={$n['id']}' title='Bearbeiten'><span class='icon'>✏</span><span class='label'>Bearbeiten</span></a>";
        echo       "<a class='btn btn-tiny' href='unterkategorie_neu.php?projekt_id={$projektId}&parent_id={$n['id']}' title='Unterordner anlegen'><span class='icon'>➕</span><span class='label'>Neu</span></a>";
        echo     "</div>";
        echo   "</div>";

        // Dateien dieses Knotens (Projekte-Pfad)
        $baseDir = realpath(__DIR__."/../uploads/projekte") . DIRECTORY_SEPARATOR . $projektId . DIRECTORY_SEPARATOR . $code;
        renderFilesFor($baseDir);

        if(!empty($n['children'])) renderTree($n['children'], $projektId, $level+1);
        echo "</li>";
    }
    echo "</ul>";
}
?>
<link rel="stylesheet" href="/pendenz.com/assets/css/projekt_baum.css">
<script defer src="/pendenz.com/assets/js/projekt_baum.js"></script>

<main class="container" style="padding:16px;">
  <!-- Header -->
  <header class="vd-hero" style="margin-bottom:12px;">
    <div class="vd-hero-text">
      <h1>Projektstruktur: <?= htmlspecialchars($projekt['name']) ?></h1>
      <?php if(!empty($projekt['status'])): ?>
        <p class="muted">Status: <?= htmlspecialchars($projekt['status']) ?></p>
      <?php endif; ?>
    </div>
    <div class="vd-hero-actions">
      <a class="btn" href="projekte.php">← Zur Projektübersicht</a>
    </div>
  </header>

  <!-- Legende – Farben pro Ebene + Code-Beispiele -->
  <section class="legend card" style="margin:10px 0; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
    <strong>Legende – Farben pro Ebene &amp; Codes</strong>
    <?php for($d=0; $d<10; $d++):
      [$bg,$bd] = depthColors($d);
      $code = exampleCodeForDepth($d);
      $inst = $code.'-1';
    ?>
      <div class="legend-row">
        <span class="legend-col">Ebene <?= $d ?>:</span>
        <span class="swatch" style="background:<?= $bg ?>; border-color:<?= $bd ?>"></span>
        <span class="legend-code">Code: <code><?= htmlspecialchars($code) ?></code></span>
        <span class="legend-sep">•</span>
        <span class="legend-code muted">Instanz: <code><?= htmlspecialchars($inst) ?></code></span>
      </div>
    <?php endfor; ?>
    <div class="legend-note">Jeder Knoten zeigt links seinen **numerischen Struktur-Code** (z. B. <code>1_2_1</code>) – identisch über alle Ansichten.</div>
  </section>

  <!-- Baum -->
  <div id="treeview" class="treeview" data-projekt-id="<?= (int)$projektId ?>">
    <?php if(empty($tree)): ?>
      <div class="card" style="padding:10px;">
        <p class="muted">Keine Ordner vorhanden. Du kannst über eine Vorlage Ordner einbauen oder manuell Unterordner anlegen.</p>
        <p>
          <a class="btn" href="vorlagen.php">➡ Vorlage auswählen &amp; einbauen</a>
          <a class="btn" href="unterkategorie_neu.php?projekt_id=<?= (int)$projektId ?>">➕ Ersten Ordner anlegen</a>
        </p>
      </div>
    <?php else: ?>
      <?php renderTree($tree, (int)$projektId, 0); ?>
    <?php endif; ?>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
