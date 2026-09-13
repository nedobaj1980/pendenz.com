<?php
if(session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_login();
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/user_ui.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$vorlageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ========== CREATE / COPY / SAVE META ========== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (isset($_POST['action']) && $_POST['action']==='create') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name==='') die("❌ Name darf nicht leer sein.");

        // Vorlage anlegen
        $st = $mysqli->prepare("INSERT INTO struktur_vorlagen (name, description, user_id) VALUES (?,?,?)");
        $st->bind_param("ssi", $name, $desc, $userId);
        $st->execute();
        $newId = (int)$st->insert_id;
        $st->close();

        // Sofort Root (Code '1') anlegen
        $sqlRoot = "INSERT INTO unterkategorien (vorlage_id, projekt_id, parent_id, name_variable, label_default, position, code)
                    VALUES (?, NULL, NULL, 'projekt', 'Projekt', 1, '1')";
        $st = $mysqli->prepare($sqlRoot);
        $st->bind_param("i", $newId);
        $st->execute();
        $st->close();

        // FS-Ordner
        $baseDir = realpath(__DIR__."/../uploads");
        if ($baseDir !== false) {
            $dir = $baseDir . DIRECTORY_SEPARATOR . "vorlagen" . DIRECTORY_SEPARATOR . $newId . DIRECTORY_SEPARATOR . "1";
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        }

        header("Location: vorlage_bearbeiten.php?id=".$newId);
        exit;
    }

    if (isset($_POST['action']) && $_POST['action']==='save_meta' && $vorlageId>0) {
        // nur Eigentümer
        $st = $mysqli->prepare("SELECT user_id FROM struktur_vorlagen WHERE id=?");
        $st->bind_param("i",$vorlageId);
        $st->execute();
        $owner = (int)($st->get_result()->fetch_column() ?? 0);
        $st->close();
        if ($owner!==$userId) die("❌ Keine Berechtigung diese Vorlage zu ändern.");

        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name==='') die("❌ Name darf nicht leer sein.");

        $st = $mysqli->prepare("UPDATE struktur_vorlagen SET name=?, description=? WHERE id=?");
        $st->bind_param("ssi", $name, $desc, $vorlageId);
        $st->execute();
        $st->close();

        header("Location: vorlage_bearbeiten.php?id=".$vorlageId);
        exit;
    }
}

/* ========== COPY (GET) ========== */
if (!$vorlageId && isset($_GET['copy'])) {
    $copyId = (int)$_GET['copy'];
    // Quelle
    $st = $mysqli->prepare("SELECT name, description FROM struktur_vorlagen WHERE id=?");
    $st->bind_param("i",$copyId);
    $st->execute();
    $src = $st->get_result()->fetch_assoc();
    $st->close();
    if(!$src) die("❌ Vorlage zum Kopieren nicht gefunden.");

    // Neue Vorlage
    $newName = $src['name'].' (Kopie)';
    $newDesc = $src['description'];

    $st = $mysqli->prepare("INSERT INTO struktur_vorlagen (name, description, user_id) VALUES (?,?,?)");
    $st->bind_param("ssi", $newName, $newDesc, $userId);
    $st->execute();
    $newId = (int)$st->insert_id;
    $st->close();

    // Unterkategorien kopieren (projekt_id NULL!)
    $map = [];
    $rows = $mysqli->query("SELECT * FROM unterkategorien WHERE vorlage_id=".$copyId." ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    foreach($rows as $r){
        $sql = "INSERT INTO unterkategorien (vorlage_id, projekt_id, parent_id, name_variable, label_default, position, code)
                VALUES (?, NULL, NULL, ?, ?, ?, ?)";
        $st = $mysqli->prepare($sql);
        $st->bind_param("issis", $newId, $r['name_variable'], $r['label_default'], $r['position'], $r['code']);
        $st->execute();
        $newChildId = (int)$st->insert_id;
        $st->close();
        $map[(int)$r['id']] = $newChildId;
    }
    foreach($rows as $r){
        if (!empty($r['parent_id'])) {
            $oldParent = (int)$r['parent_id'];
            $oldId     = (int)$r['id'];
            if(isset($map[$oldParent]) && isset($map[$oldId])){
                $newParent = $map[$oldParent];
                $newIdRow  = $map[$oldId];
                $st = $mysqli->prepare("UPDATE unterkategorien SET parent_id=? WHERE id=?");
                $st->bind_param("ii",$newParent,$newIdRow);
                $st->execute();
                $st->close();
            }
        }
    }

    // FS-Ordner für alle Codes
    $baseDir = realpath(__DIR__."/../uploads");
    if ($baseDir !== false) {
        foreach($rows as $r){
            $code = $r['code'] ?: '';
            if ($code==='') continue;
            $dir = $baseDir . DIRECTORY_SEPARATOR . "vorlagen" . DIRECTORY_SEPARATOR . $newId . DIRECTORY_SEPARATOR . $code;
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        }
    }

    header("Location: vorlage_bearbeiten.php?id=".$newId);
    exit;
}

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/nav_dispatch.php';

/* ========== VIEW MODE (CREATE vs EDIT) ========== */
if (!$vorlageId) {
    // CREATE-FORM
    ?>
    <main class="container" style="padding:16px;">
      <h1>Neue Vorlage erstellen</h1>
      <form method="post" action="vorlage_bearbeiten.php" class="card" style="padding:12px; max-width:600px;">
        <input type="hidden" name="action" value="create">
        <div style="margin-bottom:8px;">
          <label for="name"><strong>Name</strong></label><br>
          <input type="text" id="name" name="name" class="input" required style="width:100%;" placeholder="z. B. Architektur Standard">
        </div>
        <div style="margin-bottom:8px;">
          <label for="description"><strong>Beschreibung</strong></label><br>
          <textarea id="description" name="description" class="input" rows="3" style="width:100%;" placeholder="Kurzbeschreibung …"></textarea>
        </div>
        <p class="muted" style="margin:6px 0 12px;">
          Hinweis: Es wird automatisch ein Root-Ordner <code>Projekt</code> (Code <code>1</code>) angelegt.
        </p>
        <button class="btn" type="submit">💾 Vorlage anlegen</button>
        <a href="vorlagen.php" class="btn btn-secondary">Abbrechen</a>
      </form>
    </main>
    <?php include __DIR__ . '/../includes/footer.php'; exit;
}

/* ab hier: Bearbeiten-Modus */
$stmt = $mysqli->prepare("SELECT id, name, description, user_id FROM struktur_vorlagen WHERE id=?");
$stmt->bind_param("i",$vorlageId);
$stmt->execute();
$vorlage = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$vorlage) die("❌ Vorlage nicht gefunden.");

$ownerId = (int)$vorlage['user_id'];

/* Baum-Laden */
function loadTree(mysqli $db, int $vorlageId, ?int $parentId=null): array {
    $sql = "SELECT id, vorlage_id, parent_id, name_variable, label_default, position, code
            FROM unterkategorien
            WHERE vorlage_id=? AND ".($parentId===null?"parent_id IS NULL":"parent_id=?")."
            ORDER BY position, id";
    $st = $db->prepare($sql);
    if($parentId===null) $st->bind_param("i",$vorlageId); else $st->bind_param("ii",$vorlageId,$parentId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    foreach($rows as &$r){
        $r['children'] = loadTree($db,$vorlageId,$r['id']);
    }
    return $rows;
}
$tree = loadTree($mysqli,$vorlageId);

/* Ebenenfarben */
function depthColors(int $level): array {
    $d = max(0, min(9, $level));
    static $palette = [
        0 => ['#FEF08A', '#F59E0B'], // 0: Gelb
        1 => ['#FCA5A5', '#EF4444'], // 1: Rot
        2 => ['#93C5FD', '#3B82F6'], // 2: Blau
        3 => ['#A7F3D0', '#10B981'], // 3: Grün
        4 => ['#E9D5FF', '#8B5CF6'], // 4: Violett
        5 => ['#FED7AA', '#F97316'], // 5: Orange
        6 => ['#A5F3FC', '#06B6D4'], // 6: Türkis
        7 => ['#FBCFE8', '#EC4899'], // 7: Pink
        8 => ['#E2E8F0', '#64748B'], // 8: Slate
        9 => ['#C7D2FE', '#6366F1'], // 9: Indigo
    ];
    return $palette[$d];
}

/* Dateien anzeigen */
function renderFilesFor(string $baseDir){
    if(!is_dir($baseDir)) return;
    $items = array_diff(scandir($baseDir), ['.','..']);
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

/* Punkte */
function depthDotsHtml(int $level): string {
    $max = min(10, max(0, $level));
    if ($max === 0) return '';
    $out = '';
    for ($i=1; $i<=$max; $i++) $out .= "<i class='dot dot-{$i}' aria-hidden='true'>•</i>";
    return $out;
}

/* Baum rendern */
function renderTree(array $nodes, int $vorlageId, int $level=0){
    [$bgLevel,$bdLevel] = depthColors($level);
    echo "<ul class='branch' data-depth='{$level}' style='--line-color: {$bdLevel}; --connector: 14px;'>";
    foreach($nodes as $n){
        $icon = match($n['name_variable']){
            'projekt' => '🏢','objekt'=>'🏠','wohnung'=>'🏘️','zimmer'=>'🛏️', default=>'📦'
        };
        $code = $n['code'] ?: ('id'.$n['id']);
        $parentAttr = $n['parent_id']===null ? '' : (string)$n['parent_id'];
        [$bgNode,$bdNode] = depthColors($level);
        $style = "background: {$bgNode} !important; border: 1px solid {$bdNode} !important;";
        $dotsHtml = depthDotsHtml($level);

        echo "<li data-id='{$n['id']}' data-parent-id='{$parentAttr}' data-vorlage-id='{$vorlageId}' data-code='".htmlspecialchars($code,ENT_QUOTES)."' data-depth='{$level}'>";
        echo   "<div class='tree-node' style=\"{$style}\">";
        echo     "<div class='node-main'>";
        echo       "<span class='drag-handle' title='Ziehen zum Sortieren'>⋮⋮</span>";
        echo       "<span class='depth-dots' data-depth='{$level}'>{$dotsHtml}</span>";
        echo       "<span class='title'>".$icon." ".htmlspecialchars($n['label_default'])."</span>";
        echo       " <span class='code' title='Stabiler Ordner-Code'>(".htmlspecialchars($code).")</span>";
        echo     "</div>";
        echo     "<div class='actions' role='toolbar' aria-label='Aktionen'>";
        /* Fallback onclick -> funktioniert auch ohne JS-Delegation */
        echo       "<button type='button' class='btn btn-tiny btn-add-child' title='Unterordner hinzufügen' data-action='add-child' onclick='window.VB_addChild && window.VB_addChild(this); return false;'><span class=\"icon\">➕</span><span class=\"label\">Neu</span></button>";
        echo       "<a class='btn btn-tiny' href='unterkategorie_bearbeiten.php?id={$n['id']}' title='Bearbeiten'><span class='icon'>✏</span><span class='label'>Bearbeiten</span></a>";
        echo       "<button type='button' class='btn btn-tiny indent-left' title='Ebene nach links (hoch) verschieben'><span class='icon'>&larr;</span><span class='label'>Links</span></button>";
        echo       "<button type='button' class='btn btn-tiny indent-right' title='Ebene nach rechts (untergeordnet) verschieben'><span class='icon'>&rarr;</span><span class='label'>Rechts</span></button>";
        echo     "</div>";
        echo   "</div>";
        echo   "<div class='inline-add host' hidden></div>";
        $baseDir = realpath(__DIR__."/../uploads/vorlagen") . DIRECTORY_SEPARATOR . $vorlageId . DIRECTORY_SEPARATOR . $code;
        renderFilesFor($baseDir);
        if(!empty($n['children'])) renderTree($n['children'],$vorlageId,$level+1);
        echo "</li>";
    }
    echo "</ul>";
}
?>

<link rel="stylesheet" href="/pendenz.com/assets/css/projekt_baum.css">
<!-- wichtig: data-vorlage-id am Container -->
<div id="treeview" data-vorlage-id="<?= (int)$vorlageId ?>" style="display:none"></div>
<script defer src="/pendenz.com/assets/js/projekt_baum.js"></script>

<main class="container" style="padding:16px;">
  <h1>Vorlage bearbeiten</h1>

  <!-- Meta-Form -->
  <form method="post" class="card" style="padding:12px; max-width:900px; margin-bottom:12px;">
    <input type="hidden" name="action" value="save_meta">
    <div style="display:grid; grid-template-columns: 1fr; gap:8px;">
      <div>
        <label for="name"><strong>Name</strong></label><br>
        <input type="text" id="name" name="name" class="input" required style="width:100%;"
               value="<?= htmlspecialchars($vorlage['name']) ?>" <?= ($ownerId===$userId?'':'disabled') ?>>
      </div>
      <div>
        <label for="description"><strong>Beschreibung</strong></label><br>
        <textarea id="description" name="description" class="input" rows="2" style="width:100%;" <?= ($ownerId===$userId?'':'disabled') ?>><?= htmlspecialchars($vorlage['description'] ?? '') ?></textarea>
      </div>
    </div>
    <?php if($ownerId===$userId): ?>
      <div style="margin-top:8px;">
        <button class="btn" type="submit">💾 Speichern</button>
        <a class="btn btn-secondary" href="vorlagen.php">Zurück</a>
      </div>
    <?php else: ?>
      <div class="muted">Nur der Ersteller kann Name/Beschreibung ändern.</div>
    <?php endif; ?>
  </form>

  <!-- Legende – Farben pro Ebene -->
  <div class="legend card" style="margin:10px 0;padding:10px;border:1px solid #e5e7eb;border-radius:8px;">
    <strong>Legende – Farben pro Ebene</strong>
    <?php for($d=0; $d<10; $d++): [$bg,$bd]=depthColors($d); ?>
      <div class="legend-row">
        <span class="legend-col">Ebene <?= $d ?>:</span>
        <span class="swatch" style="background:<?= $bg ?>; border-color:<?= $bd ?>"></span>
      </div>
    <?php endfor; ?>
    <hr style="margin:10px 0;border:none;border-top:1px solid #e5e7eb;">
    <strong>Tiefen-Punkte</strong>
    <div class="legend-row">
      <span class="legend-col">Beispiel:</span>
      <span class="legend-dots">
        <i class="dot dot-1">•</i><i class="dot dot-2">•</i><i class="dot dot-3">•</i><i class="dot dot-4">•</i><i class="dot dot-5">•</i>
        <i class="dot dot-6">•</i><i class="dot dot-7">•</i><i class="dot dot-8">•</i><i class="dot dot-9">•</i><i class="dot dot-10">•</i>
      </span>
      <span class="legend-note">Links zeigen farbige Linien die Ebene an (gleich zur Box).</span>
    </div>
  </div>

  <div class="card" style="margin-bottom:10px;">
    <a class="btn" href="vorlage_sync_fs.php?id=<?= (int)$vorlageId ?>">📁 Ordner nach Codes anlegen/prüfen (ohne Löschen)</a>
  </div>

  <div id="treeview" class="treeview" data-vorlage-id="<?= (int)$vorlageId ?>">
    <?php renderTree($tree,(int)$vorlageId,0); ?>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
