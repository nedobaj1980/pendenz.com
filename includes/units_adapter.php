<?php
// includes/units_adapter.php
// Adapter: „Einheiten“ eines Projekts aus der bestehenden Ordnerstruktur lesen
// – völlig generisch, funktioniert auch für andere Branchen (Kunden/Objekte/etc.)

if (!function_exists('ua_hasTable')) {
  function ua_hasTable(mysqli $db, string $table): bool {
    $sql = "SELECT COUNT(*) c FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name=?";
    $st=$db->prepare($sql); $st->bind_param("s",$table); $st->execute();
    $c=(int)($st->get_result()->fetch_assoc()['c']??0); $st->close(); return $c>0;
  }
}
if (!function_exists('ua_hasColumn')) {
  function ua_hasColumn(mysqli $db, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) c FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name=? AND column_name=?";
    $st=$db->prepare($sql); $st->bind_param("ss",$table,$col); $st->execute();
    $c=(int)($st->get_result()->fetch_assoc()['c']??0); $st->close(); return $c>0;
  }
}

/**
 * Sucht eine Ordner-/Knoten-Tabelle für Projekte (heuristisch).
 * Unterstützte Kandidaten: projekt_ordner, ordner, folders, project_folders
 * Benötigte Spalten (irgendeine Benennung): id, projekt_id|project_id, parent_id, name|label|titel
 * Optional: typ/knoten_typ → Filter auf Blattknoten bestimmter Typen (wohnung, unit, kunde)
 */
function ua_detect_folder_table(mysqli $db): ?array {
  $cands = ['projekt_ordner','ordner','folders','project_folders'];
  foreach ($cands as $t) {
    if (!ua_hasTable($db,$t)) continue;
    $id = ua_hasColumn($db,$t,'id');
    $p1 = ua_hasColumn($db,$t,'projekt_id'); $p2 = ua_hasColumn($db,$t,'project_id');
    $par= ua_hasColumn($db,$t,'parent_id');
    $n1 = ua_hasColumn($db,$t,'name'); $n2 = ua_hasColumn($db,$t,'label'); $n3 = ua_hasColumn($db,$t,'titel');
    if ($id && $par && ($p1 || $p2) && ($n1 || $n2 || $n3)) {
      return [
        'table' => $t,
        'col_project' => $p1 ? 'projekt_id' : 'project_id',
        'col_parent'  => 'parent_id',
        'col_name'    => $n1 ? 'name' : ($n2 ? 'label' : 'titel'),
        'col_type'    => ua_hasColumn($db,$t,'typ') ? 'typ' : (ua_hasColumn($db,$t,'knoten_typ') ? 'knoten_typ' : null),
      ];
    }
  }
  return null;
}

/** Lädt alle Knoten eines Projektes und liefert nur Blatt-Knoten (= „Einheiten“). */
function ua_units_for_project(mysqli $db, int $projektId): array {
  $cfg = ua_detect_folder_table($db);
  if (!$cfg) return []; // keine struktur
  $t = $cfg['table']; $cp = $cfg['col_project']; $pp = $cfg['col_parent']; $cn = $cfg['col_name']; $ct = $cfg['col_type'];
  $nodes = [];
  $sql = "SELECT id, $pp AS parent_id, $cn AS name" . ($ct? ", $ct AS typ":"") . " FROM $t WHERE $cp=?";
  $st=$db->prepare($sql); $st->bind_param("i",$projektId); $st->execute();
  $rs=$st->get_result(); while($r=$rs->fetch_assoc()) $nodes[(int)$r['id']]=$r; $st->close();
  if (!$nodes) return [];
  // Kinder zählen → Blätter erkennen
  $hasChild = [];
  foreach ($nodes as $n) { $pid=(int)($n['parent_id'] ?? 0); if ($pid) $hasChild[$pid]=true; }
  $leafs = array_filter($nodes, fn($n)=> empty($hasChild[(int)$n['id']]));
  // Optional nach Typ filtern (falls vorhanden)
  if ($ct) {
    $leafs = array_filter($leafs, function($n){
      $typ = strtolower((string)($n['typ'] ?? ''));           // whg/wohnung/unit/kunde/…
      return $typ==='' || in_array($typ, ['wohnung','whg','unit','kunde','raum','objekt'], true);
    });
  }
  // Pfade bauen
  $paths = [];
  $index = $nodes;
  $pathOf = function($id) use (&$index){
    $trail=[]; $cur=$id; $guard=0;
    while ($cur && isset($index[$cur]) && $guard++<200) { array_unshift($trail, $index[$cur]['name']); $cur=(int)($index[$cur]['parent_id']??0); }
    return implode(' / ', array_filter($trail, fn($s)=>trim((string)$s) !== ''));
  };
  foreach (array_keys($leafs) as $id) $paths[(int)$id] = $pathOf((int)$id);
  $units=[];
  foreach ($leafs as $id=>$n) $units[]=['id'=>(int)$id,'label'=>$n['name'],'path'=>$paths[(int)$id]];
  // sort by path asc
  usort($units, fn($a,$b)=>strcmp($a['path'],$b['path']));
  return $units;
}

/** Optional: Konto-Spalte entity_node_id idempotent anlegen. */
function ua_ensure_konto_entity(mysqli $db): void {
  if (!ua_hasTable($db,'liegenschafts_konto')) return;
  if (!ua_hasColumn($db,'liegenschafts_konto','entity_node_id')) {
    try { $db->query("ALTER TABLE liegenschafts_konto ADD COLUMN entity_node_id INT NULL AFTER liegenschaft_id, ADD INDEX (entity_node_id)"); } catch(Throwable $e){}
  }
}
