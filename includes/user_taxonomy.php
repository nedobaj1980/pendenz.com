<?php
// includes/user_taxonomy.php
// Zentrale Taxonomie-Funktionen für Personen-Typen & -Status
// Mit idempotenter Schema-Absicherung (legt Tabellen an & ergänzt fehlende Spalten/Indizes)

if (!function_exists('h')) {
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function user_taxonomy_slugify(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

/* ===== Schema-Helper ===== */
function _col_exists(mysqli $db, string $table, string $col): bool {
  $sql = "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $db->prepare($sql); $st->bind_param('ss',$table,$col); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function _index_exists(mysqli $db, string $table, string $index): bool {
  $sql = "SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1";
  $st = $db->prepare($sql); $st->bind_param('ss',$table,$index); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function _fk_exists(mysqli $db, string $table, string $fkName): bool {
  $sql = "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE='FOREIGN KEY'
            AND CONSTRAINT_NAME = ? LIMIT 1";
  $st = $db->prepare($sql); $st->bind_param('ss',$table,$fkName); $st->execute();
  $ok = (bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

/** Tabellen anlegen und fehlende Spalten/Indizes ergänzen */
function user_taxonomy_ensure_tables(mysqli $db): void {
  // 1) person_types
  $db->query("CREATE TABLE IF NOT EXISTS person_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort INT NOT NULL DEFAULT 100,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug),
    UNIQUE KEY uq_name (name),
    KEY idx_sort (sort)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!_col_exists($db,'person_types','slug')) {
    @$db->query("ALTER TABLE person_types ADD COLUMN slug VARCHAR(100) NOT NULL AFTER id");
  }
  // Falls Slugs leer sind -> belegen
  $resS = $db->query("SELECT id, name FROM person_types WHERE slug = '' OR slug IS NULL");
  if ($resS) {
    while ($r = $resS->fetch_assoc()) {
      $newSlug = user_taxonomy_slugify($r['name']) ?: ('type-' . $r['id']);
      $db->query("UPDATE person_types SET slug = '".$db->real_escape_string($newSlug)."' WHERE id = " . (int)$r['id']);
    }
  }
  // Prüfe auf Index 'slug' oder 'uq_slug'
  if (!_index_exists($db,'person_types','slug') && !_index_exists($db,'person_types','uq_slug')) {
    @$db->query("ALTER TABLE person_types ADD UNIQUE KEY slug (slug)");
  }

  if (!_col_exists($db,'person_types','name')) {
    // ganz alte/andere Tabelle – versuch sie minimal kompatibel zu machen
    @$db->query("ALTER TABLE person_types ADD COLUMN name VARCHAR(100) NOT NULL");
  }
  if (!_col_exists($db,'person_types','sort')) {
    @$db->query("ALTER TABLE person_types ADD COLUMN sort INT NOT NULL DEFAULT 100 AFTER name");
    // sinnvolle Reihenfolge, falls alles auf Default 100 steht
    @$db->query("UPDATE person_types SET sort = id*10 WHERE sort IS NULL OR sort=0 OR sort=100");
  }
  if (!_index_exists($db,'person_types','uq_name')) {
    // Dublikatfehler möglich – dann einfach ignorieren
    @$db->query("ALTER TABLE person_types ADD UNIQUE KEY uq_name (name)");
  }
  if (!_index_exists($db,'person_types','idx_sort')) {
    @$db->query("ALTER TABLE person_types ADD KEY idx_sort (sort)");
  }

  // 2) person_statuses
  $db->query("CREATE TABLE IF NOT EXISTS person_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_id INT NOT NULL,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort INT NOT NULL DEFAULT 100,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_type_slug (type_id, slug),
    UNIQUE KEY uq_type_name (type_id, name),
    KEY idx_type_sort (type_id, sort),
    CONSTRAINT fk_ps_type FOREIGN KEY (type_id) REFERENCES person_types(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  if (!_col_exists($db,'person_statuses','slug')) {
    @$db->query("ALTER TABLE person_statuses ADD COLUMN slug VARCHAR(100) NOT NULL AFTER type_id");
  }
  // Nachrüsten leerer Slugs
  $resS = $db->query("SELECT id, name FROM person_statuses WHERE slug = '' OR slug IS NULL");
  if ($resS) {
    while ($r = $resS->fetch_assoc()) {
      $newSlug = user_taxonomy_slugify($r['name']) ?: ('status-' . $r['id']);
      $db->query("UPDATE person_statuses SET slug = '".$db->real_escape_string($newSlug)."' WHERE id = " . (int)$r['id']);
    }
  }
  // Prüfe auf Index 'uniq_type_slug', 'uq_type_slug' oder 'uq_type_slug'
  if (!_index_exists($db,'person_statuses','uniq_type_slug') && !_index_exists($db,'person_statuses','uq_type_slug')) {
    @$db->query("ALTER TABLE person_statuses ADD UNIQUE KEY uq_type_slug (type_id, slug)");
  }

  if (!_col_exists($db,'person_statuses','type_id')) {
    // als Fallback 0 – sinnvoll belegen kannst du später über die UI
    @$db->query("ALTER TABLE person_statuses ADD COLUMN type_id INT NOT NULL DEFAULT 0");
  }
  if (!_col_exists($db,'person_statuses','name')) {
    @$db->query("ALTER TABLE person_statuses ADD COLUMN name VARCHAR(100) NOT NULL");
  }
  if (!_col_exists($db,'person_statuses','sort')) {
    @$db->query("ALTER TABLE person_statuses ADD COLUMN sort INT NOT NULL DEFAULT 100");
    @$db->query("UPDATE person_statuses SET sort = id*10 WHERE sort IS NULL OR sort=0 OR sort=100");
  }
  if (!_col_exists($db,'person_statuses','is_default')) {
    @$db->query("ALTER TABLE person_statuses ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0");
  }
  if (!_index_exists($db,'person_statuses','uq_type_name')) {
    @$db->query("ALTER TABLE person_statuses ADD UNIQUE KEY uq_type_name (type_id, name)");
  }
  if (!_index_exists($db,'person_statuses','idx_type_sort')) {
    @$db->query("ALTER TABLE person_statuses ADD KEY idx_type_sort (type_id, sort)");
  }
  if (!_fk_exists($db,'person_statuses','fk_ps_type')) {
    // FK hinzufügen (falls Daten inkonsistent -> kann fehlschlagen, dann ignorieren)
    @$db->query("ALTER TABLE person_statuses
                 ADD CONSTRAINT fk_ps_type FOREIGN KEY (type_id)
                 REFERENCES person_types(id) ON DELETE CASCADE");
  }
}

/* ===== Abfragen ===== */

/** Alle Typen (sortiert) */
function user_types_all(mysqli $db): array {
  user_taxonomy_ensure_tables($db);
  $out=[]; $rs=$db->query("SELECT id,name,sort FROM person_types ORDER BY sort ASC, name ASC");
  if($rs){ while($r=$rs->fetch_assoc()) $out[]=$r; $rs->close(); }
  return $out;
}

/** Alle Status gruppiert: [type_id] => [ {id,name,sort,is_default}, ... ] */
function user_statuses_all_grouped(mysqli $db): array {
  user_taxonomy_ensure_tables($db);
  $map=[];
  $rs=$db->query("SELECT id,type_id,name,sort,is_default
                  FROM person_statuses
                  ORDER BY type_id ASC, sort ASC, name ASC");
  if($rs){ while($r=$rs->fetch_assoc()){ $map[(int)$r['type_id']][]=$r; } $rs->close(); }
  return $map;
}

function user_type_create(mysqli $db, string $name, ?int $sort=null): int {
  user_taxonomy_ensure_tables($db);
  $name=trim($name); if($name==='') throw new Exception('Name für Typ fehlt.');
  $slug = user_taxonomy_slugify($name);
  if ($slug === '') $slug = 'type-' . time();
  
  $s = $sort ?? 100;
  $st=$db->prepare("INSERT INTO person_types (name, slug, sort) VALUES (?,?,?)");
  $st->bind_param('ssi',$name, $slug, $s); $st->execute();
  $id=(int)$db->insert_id; $st->close();
  return $id;
}
function user_type_rename(mysqli $db, int $id, string $name): void {
  user_taxonomy_ensure_tables($db);
  $name=trim($name); if($name==='') throw new Exception('Name für Typ fehlt.');
  $st=$db->prepare("UPDATE person_types SET name=? WHERE id=?");
  $st->bind_param('si',$name,$id); $st->execute(); $st->close();
}
function user_type_delete(mysqli $db, int $id): void {
  user_taxonomy_ensure_tables($db);
  $st=$db->prepare("DELETE FROM person_types WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}
function user_type_move(mysqli $db, int $id, string $dir): void {
  user_taxonomy_ensure_tables($db);
  $dir = ($dir==='up') ? 'up' : 'down';
  $st=$db->prepare("SELECT id,sort FROM person_types WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $cur=$st->get_result()->fetch_assoc(); $st->close();
  if(!$cur) return;
  $curSort=(int)$cur['sort'];

  if($dir==='up'){
    $rs=$db->query("SELECT id,sort FROM person_types WHERE sort < ".$curSort." ORDER BY sort DESC LIMIT 1");
  } else {
    $rs=$db->query("SELECT id,sort FROM person_types WHERE sort > ".$curSort." ORDER BY sort ASC LIMIT 1");
  }
  $nei=$rs?$rs->fetch_assoc():null; if($rs) $rs->close();
  if(!$nei) return;

  $nid=(int)$nei['id']; $ns=(int)$nei['sort'];
  $db->query("UPDATE person_types SET sort=".$ns." WHERE id=".$id);
  $db->query("UPDATE person_types SET sort=".$curSort." WHERE id=".$nid);
}

/* ===== Mutationen Status ===== */
function user_status_create(mysqli $db, int $typeId, string $name, ?int $sort=null, int $isDefault=0): int {
  user_taxonomy_ensure_tables($db);
  $name=trim($name); if($name==='') throw new Exception('Name für Status fehlt.');
  $slug = user_taxonomy_slugify($name);
  if ($slug === '') $slug = 'status-' . time();

  $s = $sort ?? 100;
  $st=$db->prepare("INSERT INTO person_statuses (type_id, slug, name, sort, is_default) VALUES (?,?,?,?,?)");
  $st->bind_param('issii',$typeId, $slug, $name, $s, $isDefault); $st->execute();
  $id=(int)$db->insert_id; $st->close();
  return $id;
}
function user_status_rename(mysqli $db, int $id, string $name): void {
  user_taxonomy_ensure_tables($db);
  $name=trim($name); if($name==='') throw new Exception('Name für Status fehlt.');
  $st=$db->prepare("UPDATE person_statuses SET name=? WHERE id=?");
  $st->bind_param('si',$name,$id); $st->execute(); $st->close();
}
function user_status_delete(mysqli $db, int $id): void {
  user_taxonomy_ensure_tables($db);
  $st=$db->prepare("DELETE FROM person_statuses WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}
function user_status_move(mysqli $db, int $id, string $dir): void {
  user_taxonomy_ensure_tables($db);
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
function user_status_set_default(mysqli $db, int $id): void {
  user_taxonomy_ensure_tables($db);
  $st=$db->prepare("SELECT type_id FROM person_statuses WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
  if(!$row) return;
  $tid=(int)$row['type_id'];
  $db->query("UPDATE person_statuses SET is_default=0 WHERE type_id=".$tid);
  $st=$db->prepare("UPDATE person_statuses SET is_default=1 WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $st->close();
}
