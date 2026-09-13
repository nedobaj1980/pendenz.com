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
$projektId = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;

if($projektId <= 0) die("❌ Kein Projekt angegeben.");

// Projekt laden
$stmt = $mysqli->prepare("SELECT id,name FROM projekte WHERE id=?");
$stmt->bind_param("i",$projektId);
$stmt->execute();
$projekt = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$projekt) die("❌ Projekt nicht gefunden.");


// Unterkategorien laden
function loadUnterkategorien($mysqli, $projektId, $parentId=null){
    $stmt = $mysqli->prepare("SELECT * FROM unterkategorien WHERE projekt_id=? AND ".($parentId===null?"parent_id IS NULL":"parent_id=?")." ORDER BY position");
    if($parentId===null) $stmt->bind_param("i",$projektId);
    else $stmt->bind_param("ii",$projektId,$parentId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $tree = [];
    foreach($res as $r){
        $r['children'] = loadUnterkategorien($mysqli, $projektId, $r['id']);
        $tree[] = $r;
    }
    return $tree;
}

$tree = loadUnterkategorien($mysqli, $projektId);
?>
<link rel="stylesheet" href="/pendenz.com/assets/css/projekt_baum.css">
<script src="/pendenz.com/assets/js/projekt_baum.js"></script>

<main class="container" style="padding:16px;">
<h1>Projekt Baum: <?= htmlspecialchars($projekt['name']) ?></h1>
<p>Interaktive Strukturübersicht mit Variablen, Favoriten und Bearbeitungsmöglichkeiten.</p>

<div id="treeview" style="border:1px solid #ddd; padding:10px; border-radius:6px;">
<?php
function renderTree($nodes){
    echo "<ul>";
    foreach($nodes as $n){
        echo "<li data-id='{$n['id']}'>";
        echo "<div class='tree-node'>";
        echo htmlspecialchars($n['label_default'])." (".htmlspecialchars($n['name_variable']).") ";
        echo "<span class='favorite' data-id='{$n['id']}'>☆</span> ";
        echo "<a href='unterkategorie_bearbeiten.php?id={$n['id']}' class='btn btn-tiny'>✏</a> ";
        echo "<a href='unterkategorie_neu.php?parent_id={$n['id']}&projekt_id={$n['projekt_id']}' class='btn btn-tiny'>➕</a> ";
        echo "<a href='unterkategorie_loeschen.php?id={$n['id']}' class='btn btn-tiny' onclick='return confirm(\"Löschen?\")'>🗑</a>";
        echo "</div>";
        if(!empty($n['children'])) renderTree($n['children']);
        echo "</li>";
    }
    echo "</ul>";
}
renderTree($tree);
?>
</div>
</main>

<script>
// Drag & Drop
const tree = document.getElementById('treeview');
tree.querySelectorAll('li').forEach(li=>{
    li.draggable = true;

    li.addEventListener('dragstart', e=>{
        e.dataTransfer.setData('text/plain', li.dataset.id);
        li.classList.add('dragging');
    });

    li.addEventListener('dragend', e=>{
        li.classList.remove('dragging');
    });

    li.addEventListener('dragover', e=>{
        e.preventDefault();
        li.classList.add('dragover');
    });

    li.addEventListener('dragleave', e=>{
        li.classList.remove('dragover');
    });

    li.addEventListener('drop', e=>{
        e.preventDefault();
        li.classList.remove('dragover');
        const draggedId = e.dataTransfer.getData('text/plain');
        const targetId = li.dataset.id;
        const draggedLi = tree.querySelector(`li[data-id='${draggedId}']`);
        let ul = li.querySelector('ul');
        if(!ul){ ul = document.createElement('ul'); li.appendChild(ul); }
        ul.appendChild(draggedLi);

        // Ajax call zum Speichern der neuen Parent/Position
        fetch('/api/unterkategorie_move.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                unterkategorie_id: draggedId,
                parent_id: targetId,
                position: Array.from(ul.children).indexOf(draggedLi)
            })
        }).then(r=>r.json()).then(res=>{
            if(!res.ok) alert('Fehler beim Verschieben: '+res.error);
        });
    });
});

// Favoriten
document.querySelectorAll('.favorite').forEach(star=>{
    star.addEventListener('click', e=>{
        const id = star.dataset.id;
        const active = star.textContent==='★';
        star.textContent = active?'☆':'★';
        fetch('/api/favoriten_toggle.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({unterkategorie_id:id, aktiv:!active})
        }).then(r=>r.json()).then(res=>{
            if(!res.ok) alert('Fehler beim Speichern der Favoriten: '+res.error);
        });
    });
});

// TreeNode Toggle
document.querySelectorAll('#treeview li > .tree-node').forEach(node=>{
    node.addEventListener('click', e=>{
        const li = node.parentElement;
        const childUl = li.querySelector('ul');
        if(childUl) childUl.style.display = childUl.style.display==='none'?'block':'none';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
