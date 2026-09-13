<?php
// includes/user_taxonomy.php
// Zentrale Taxonomie-Funktionen für Personen-Typen & -Status

if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/** Tabellen anlegen (idempotent) */
function user_taxonomy_ensure_tables(mysqli $db): void {
  $db->query("CREATE TABLE IF NOT EXISTS person_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_name (name),
    KEY idx_sort (sort)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $db->query("CREATE TABLE IF NOT EXISTS person_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort INT NOT NULL DEFAULT 100,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_type_name (type_id, name),
    KEY idx_type_sort (type_id, sort),
    CONSTRAINT fk_ps_type FOREIGN KEY (type_id) REFERENCES person_types(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Alle Typen */
function user_types_all(mysqli $db): array {
  user_taxonomy_ensure_tables($db);
  $out=[]; $rs=$db->query("SELECT id,name,sort FROM person_types ORDER BY sort ASC, name ASC");
  if($rs){ while($r=$rs->fetch_assoc()) $out[]=$r; $rs->close(); }
  return $out;
}

/** Alle Status, gruppiert nach Typ-ID: [type_id] => [ {id,name,sort,is_default}, ... ] */
function user_statuses_all_grouped(mysqli $db): array {
  user_taxonomy_ensure_tables($db);
  $map=[];
  $rs=$db->query("SELECT id,type_id,name,sort,is_default FROM person_statuses ORDER BY type_id ASC, sort ASC, name ASC");
  if($rs){ while($r=$rs->fetch_assoc()){ $tid=(int)$r['type_id']; $map[$tid][]=$r; } $rs->close(); }
  return $map;
}

/** Typ anlegen */
function user_type_create(mysqli $db, string $name, ?int $sort=null): int {
  $name=trim($name); if($name==='') throw new Exception('Name für Typ fehlt.');
  $s = $sort ?? 100;
  $st=$db->prepare("INSERT INTO person_types (name,sort) VALUES (?,?)");
  $st->bind_param('si',$name,$s); $st->execute();
  $id=(int)$db->insert_id; $st->close();
  return $id;
}

/** Typ umbenennen */
function user_type_rename(mysqli $db, int $id, string $name): void {
  $name=trim($name); if($name==='') throw new Exception('Name für Typ fehlt.');
  $st=$db->prepare("UPDATE person_types SET name=? WHERE id=?");
  $st->bind_param('si',$name,$id); $st->execute(); $st->close();
}

/** Typ löschen (löscht Status via FK-CASCADE) */
function user_type_delete(mysqli $db, int $id): void {
  $st=$db->prepare("DELETE FROM person_types WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}

/** Typ nach oben/unten bewegen (swap mit Nachbar) */
function user_type_move(mysqli $db, int $id, string $dir): void {
  $dir = ($dir==='up') ? 'up' : 'down';
  $st=$db->prepare("SELECT id,sort FROM person_types WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $cur=$st->get_result()->fetch_assoc(); $st->close();
  if(!$cur) return;
  $curSort=(int)$cur['sort'];

  if($dir==='up'){
    $rs=$db->query("SELECT id,sort FROM person_types WHERE sort < ".(int)$curSort." ORDER BY sort DESC LIMIT 1");
  } else {
    $rs=$db->query("SELECT id,sort FROM person_types WHERE sort > ".(int)$curSort." ORDER BY sort ASC LIMIT 1");
  }
  $nei=$rs?$rs->fetch_assoc():null; if($rs) $rs->close();
  if(!$nei) return;

  $nid=(int)$nei['id']; $ns=(int)$nei['sort'];
  $db->query("UPDATE person_types SET sort=".$ns." WHERE id=".$id);
  $db->query("UPDATE person_types SET sort=".$curSort." WHERE id=".$nid);
}

/** Status anlegen */
function user_status_create(mysqli $db, int $typeId, string $name, ?int $sort=null, int $isDefault=0): int {
  $name=trim($name); if($name==='') throw new Exception('Name für Status fehlt.');
  $s = $sort ?? 100;
  $st=$db->prepare("INSERT INTO person_statuses (type_id,name,sort,is_default) VALUES (?,?,?,?)");
  $st->bind_param('isii',$typeId,$name,$s,$isDefault); $st->execute();
  $id=(int)$db->insert_id; $st->close();
  return $id;
}

/** Status umbenennen */
function user_status_rename(mysqli $db, int $id, string $name): void {
  $name=trim($name); if($name==='') throw new Exception('Name für Status fehlt.');
  $st=$db->prepare("UPDATE person_statuses SET name=? WHERE id=?");
  $st->bind_param('si',$name,$id); $st->execute(); $st->close();
}

/** Status löschen */
function user_status_delete(mysqli $db, int $id): void {
  $st=$db->prepare("DELETE FROM person_statuses WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}

/** Status bewegen innerhalb eines Typs */
function user_status_move(mysqli $db, int $id, string $dir): void {
  $dir = ($dir==='up') ? 'up' : 'down';
  $st=$db->prepare("SELECT id,type_id,sort FROM person_statuses WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $cur=$st->get_result()->fetch_assoc(); $st->close();
  if(!$cur) return;
  $tid=(int)$cur['type_id']; $curSort=(int)$cur['sort'];

  if($dir==='up'){
    $rs=$db->query("SELECT id,sort FROM person_statuses WHERE type_id=".$tid." AND sort < ".$curSort." ORDER BY sort DESC LIMIT 1");
  } else {
    $rs=$db->query("SELECT id,sort FROM person_statuses WHERE type_id=".$tid." AND sort > ".$curSort." ORDER BY sort ASC LIMIT 1");
  }
  $nei=$rs?$rs->fetch_assoc():null; if($rs) $rs->close();
  if(!$nei) return;

  $nid=(int)$nei['id']; $ns=(int)$nei['sort'];
  $db->query("UPDATE person_statuses SET sort=".$ns." WHERE id=".$id);
  $db->query("UPDATE person_statuses SET sort=".$curSort." WHERE id=".$nid);
}

/** Optional: einen Status als Default markieren (alle anderen desselben Typs auf 0) */
function user_status_set_default(mysqli $db, int $id): void {
  $st=$db->prepare("SELECT type_id FROM person_statuses WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
  if(!$row) return;
  $tid=(int)$row['type_id'];
  $db->query("UPDATE person_statuses SET is_default=0 WHERE type_id=".$tid);
  $st=$db->prepare("UPDATE person_statuses SET is_default=1 WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}
