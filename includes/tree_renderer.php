<?php
/**
 * Zentrale Funktionen für Baum-Renderer
 */

// Typ-zu-CSS-Klasse Mapping
function getNodeClass($node){
    return match($node['name_variable'] ?? ''){
        'objekt' => 'node-objekt',
        'allgemeinraum' => 'node-allgemeinraum',
        'wohnung' => 'node-wohnung',
        'zimmer' => 'node-zimmer',
        'mieter' => 'node-mieter',
        default => 'node-default',
    };
}

// Rekursives Rendern des Baums mit nummerischer Pfad-Nummerierung
function renderTree(array $nodes, string $prefix='', bool $collapseAll=true){
    echo "<ul>";
    $i = 1;
    foreach($nodes as $n){
        $currentIndex = $prefix ? $prefix.".".$i : $i;
        $class = getNodeClass($n);
        $displayStyle = ($collapseAll && !empty($prefix)) ? 'display:block;' : 'display:block;';

        echo "<li data-id='{$n['id']}'>";
        echo "<div class='tree-node {$class}'>";
        $icon = match($n['name_variable'] ?? ''){
            'objekt' => '🏢',
            'allgemeinraum' => '🏠',
            'wohnung' => '🏘️',
            'zimmer' => '🛏️',
            'mieter' => '👤',
            default => '📦',
        };
        echo "<strong>{$currentIndex}</strong> {$icon} ".htmlspecialchars($n['label_default'])." (".htmlspecialchars($n['name_variable']).") ";
        echo "<span class='favorite' data-id='{$n['id']}'>☆</span> ";
        echo "<a href='unterkategorie_bearbeiten.php?id={$n['id']}' class='btn btn-tiny'>✏</a> ";
        echo "<a href='unterkategorie_neu.php?parent_id={$n['id']}&projekt_id={$n['projekt_id']}' class='btn btn-tiny'>➕</a> ";
        echo "<a href='unterkategorie_loeschen.php?id={$n['id']}' class='btn btn-tiny' onclick='return confirm(\"Löschen?\")'>🗑</a>";
        echo "</div>";

        if(!empty($n['children'])){
            echo "<div style='{$displayStyle}'>";
            renderTree($n['children'],$currentIndex,$collapseAll);
            echo "</div>";
        }
        echo "</li>";
        $i++;
    }
    echo "</ul>";
}

// Unterkategorien laden
function loadUnterkategorien(mysqli $mysqli, int $id, bool $isVorlage=true, ?int $parentId=null): array {
    if($isVorlage){
        $sql = "SELECT * FROM unterkategorien WHERE vorlage_id=? AND ".($parentId===null?"parent_id IS NULL":"parent_id=?")." ORDER BY position";
    } else {
        $sql = "SELECT * FROM unterkategorien WHERE projekt_id=? AND ".($parentId===null?"parent_id IS NULL":"parent_id=?")." ORDER BY position";
    }

    $stmt = $mysqli->prepare($sql);
    if($parentId===null) $stmt->bind_param("i",$id);
    else $stmt->bind_param("ii",$id,$parentId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $tree = [];
    foreach($res as $r){
        $r['children'] = loadUnterkategorien($mysqli,$id,$isVorlage,$r['id']);
        $r['status'] = $r['status'] ?? 'offen';
        $r['owner'] = $r['owner'] ?? '-';
        $tree[] = $r;
    }
    return $tree;
}
