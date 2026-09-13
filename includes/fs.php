<?php
// includes/fs.php
if (!defined('FS_INCLUDED')) {
  define('FS_INCLUDED', 1);

  /**
   * Pfadseparatoren normalisieren (Windows/Unix) und doppelte Separatoren reduzieren.
   */
  function fs_norm_sep(string $p): string {
    $p = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $p);
    $ds = DIRECTORY_SEPARATOR;
    return preg_replace('#'.preg_quote($ds,'#').'{2,}#', $ds, $p);
  }

  /**
   * Führende/trailing Separatoren entfernen (behält Mittelteil).
   */
  function fs_trim_sep(string $p): string {
    $ds = DIRECTORY_SEPARATOR;
    return trim($p, $ds." \t\n\r\0\x0B");
  }

  /**
   * Relativpfad normalisieren: einheitlich "/" und ohne führendes/trailing "/".
   */
  function fs_norm_rel(string $rel): string {
    $rel = str_replace('\\','/', $rel);
    $rel = trim($rel);
    $rel = ltrim($rel, '/');
    $rel = rtrim($rel, '/');
    return $rel;
  }

  /**
   * Root-Pfad des Projekts aus DB holen, normalisieren und realpath anwenden (falls möglich).
   */
  function project_root_path(mysqli $db, int $projectId): ?string {
    $st=$db->prepare("SELECT root_path, name FROM projekte WHERE id=?");
    $st->bind_param("i",$projectId);
    $st->execute(); $st->bind_result($root, $projName);
    $ok=$st->fetch(); $st->close();
    if(!$ok || !$root) return null;
    $root=fs_norm_sep($root);
    
    // 1. Wenn der Pfad direkt existiert
    if (is_dir($root)) {
      $rp=@realpath($root);
      return $rp!==false ? $rp : $root;
    }
    
    // 2. Automatischer Fallback für Google Drive (G:\ oder andere Laufwerke)
    $candidateDrives = ['G:', 'C:', 'D:', 'E:', 'F:', 'H:'];
    $subPath = 'Meine Ablage' . DIRECTORY_SEPARATOR . 'Helvetic Immo Treuhand';
    
    $folderName = basename($root);
    if (!$folderName || $folderName === 'Helvetic Immo Treuhand') {
      $folderName = $projName;
    }

    foreach ($candidateDrives as $drv) {
      $tryPath = $drv . DIRECTORY_SEPARATOR . $subPath . DIRECTORY_SEPARATOR . $folderName;
      if (is_dir($tryPath)) {
        $cleanPath = str_replace('\\', '/', $tryPath);
        @$db->query("UPDATE projekte SET root_path='" . $db->real_escape_string($cleanPath) . "' WHERE id=" . (int)$projectId);
        $rp = @realpath($tryPath);
        return $rp !== false ? $rp : $tryPath;
      }
    }

    $rp=@realpath($root);
    return $rp!==false ? $rp : $root;
  }

  /**
   * Root-Pfad des Projekts setzen (nur wenn existierender Ordner).
   */
  function set_project_root_path(mysqli $db, int $projectId, string $absPath): bool {
    $absPath=fs_norm_sep($absPath);
    if(!is_dir($absPath)) return false;
    $st=$db->prepare("UPDATE projekte SET root_path=? WHERE id=?");
    $st->bind_param("si",$absPath,$projectId);
    $ok=$st->execute(); $st->close();
    return $ok;
  }

  /**
   * Root + rel sicher zusammenbauen (kein Escape aus Root).
   * Gibt absoluten Pfad oder null zurück.
   */
  function fs_safe_join(string $root, string $rel): ?string {
    $root=rtrim(fs_norm_sep($root), DIRECTORY_SEPARATOR);
    $rel =fs_trim_sep(fs_norm_sep($rel));
    $full=$root.DIRECTORY_SEPARATOR.$rel;

    $rootCanon=@realpath($root);
    $fullCanon=@realpath($full);
    if($rootCanon && $fullCanon){
      // Windows: case-insensitive Vergleich, Unix: identischer Prefix
      if (stripos(PHP_OS_FAMILY,'Windows') !== false) {
        if (stripos($fullCanon, $rootCanon) !== 0) return null;
      } else {
        if (strpos($fullCanon, $rootCanon) !== 0) return null;
      }
      return $fullCanon;
    }
    // Fallback: Prefix-Check ohne realpath
    $a = (stripos(PHP_OS_FAMILY,'Windows') !== false) ? strtolower($root.DIRECTORY_SEPARATOR) : ($root.DIRECTORY_SEPARATOR);
    $b = substr((stripos(PHP_OS_FAMILY,'Windows') !== false) ? strtolower($full) : $full, 0, strlen($a));
    return ($a===$b) ? $full : null;
  }

  /**
   * Absoluten Pfad aus Root + Relativpfad gewinnen (sicher, s.o.).
   */
  function fs_abs_from_rel(string $root, string $rel): ?string {
    return fs_safe_join($root, fs_norm_rel($rel));
  }

  /**
   * Schneller Voll-Scan: löscht alle Nodes für Projekt und baut neu auf.
   * Vorteil: kein kompliziertes "orphan cleanup".
   *
   * @param int $maxHashBytes  >0: Dateien bis zu dieser Größe werden gehasht (content_sha)
   * @return array{ok:bool,msg:string,count?:int}
   */
  function fs_scan_project(mysqli $db, int $projectId, int $maxHashBytes=0): array {
    $root=project_root_path($db,$projectId);
    if(!$root || !is_dir($root)) return ['ok'=>false,'msg'=>'Root-Ordner fehlt/ungültig'];

    try {
      $db->begin_transaction();

      $del=$db->prepare("DELETE FROM fs_nodes WHERE project_id=?");
      $del->bind_param("i",$projectId);
      $del->execute();
      $del->close();

      $ins=$db->prepare("INSERT INTO fs_nodes
        (project_id, rel_path, name, parent_rel_path, is_dir, size, mtime, content_sha)
        VALUES (?,?,?,?,?,?,?,?)");

      $rootLen=strlen($root);
      $ds=DIRECTORY_SEPARATOR;

      $rii=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
      );

      $count=0;
      foreach($rii as $fi){
        $abs=fs_norm_sep($fi->getPathname());
        // Windows/Unix: berechne rel-Pfad stabil anhand $root
        $rel=ltrim(substr($abs,$rootLen),$ds);
        $rel=str_replace($ds,'/',$rel);            // DB: immer "/" in rel_path
        if($rel==='') continue;                    // Root selbst nicht eintragen
        $name=$fi->getFilename();
        $isDir=(int)$fi->isDir();
        $size=$isDir?0:(int)$fi->getSize();
        $mtime=date('Y-m-d H:i:s',$fi->getMTime());
        $parent=null;
        if(strpos($rel,'/')!==false){
          $parent=substr($rel,0,strrpos($rel,'/'));
        }

        $sha=null;
        if(!$isDir && $maxHashBytes>0 && $size>0 && $size <= $maxHashBytes){
          $sha=@hash_file('sha256',$abs) ?: null;
        }

        $ins->bind_param("isssiiss",$projectId,$rel,$name,$parent,$isDir,$size,$mtime,$sha);
        $ins->execute();
        $count++;
      }
      $ins->close();
      $db->commit();

      return ['ok'=>true,'msg'=>'Scan ok','count'=>$count];
    } catch (Throwable $ex) {
      // Sicherheitshalber zurückrollen
      if ($db->errno === 0) { // falls noch offen
        @$db->rollback();
      }
      return ['ok'=>false,'msg'=>'Scan-Fehler: '.$ex->getMessage()];
    }
  }

  /**
   * Kinder eines Ordners aus der DB listen; Root: $parentRel==='' (NULL oder '').
   * Gibt Felder wie fs_nodes zurück (rel_path, name, is_dir, size, mtime).
   */
  function fs_list_children(mysqli $db, int $projectId, string $parentRel=''): array {
    $parentRel = fs_norm_rel($parentRel);
    if($parentRel===''){
      $sql="SELECT rel_path,name,is_dir,size,mtime
            FROM fs_nodes
            WHERE project_id=? AND (parent_rel_path IS NULL OR parent_rel_path='')
            ORDER BY is_dir DESC, name ASC";
      $st=$db->prepare($sql); $st->bind_param("i",$projectId);
    } else {
      $sql="SELECT rel_path,name,is_dir,size,mtime
            FROM fs_nodes
            WHERE project_id=? AND parent_rel_path=?
            ORDER BY is_dir DESC, name ASC";
      $st=$db->prepare($sql); $st->bind_param("is",$projectId,$parentRel);
    }
    $st->execute();
    $res=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $res;
  }

  /**
   * Live-Dateisystem-Listing (ohne DB), liefert Felder wie fs_nodes plus _live=1.
   * mtime-Format: 'Y-m-d H:i:s' (wie in DB), Größe für Ordner=0.
   */
  function fs_list_children_live(string $absRoot, string $parentRel=''): array {
    if (!is_dir($absRoot)) return [];
    $parentRel = fs_norm_rel($parentRel);
    $dir = fs_abs_from_rel($absRoot, $parentRel);
    if ($dir===null || !is_dir($dir)) return [];

    $items = @scandir($dir) ?: [];
    $out = [];
    foreach ($items as $n) {
      if ($n==='.' || $n==='..') continue;
      $full = $dir . DIRECTORY_SEPARATOR . $n;
      $isDir = is_dir($full);
      $size  = $isDir ? 0 : (@filesize($full) ?: 0);
      $mt    = @filemtime($full);
      $out[] = [
        'rel_path' => $parentRel ? ($parentRel.'/'.$n) : $n,
        'name'     => $n,
        'is_dir'   => $isDir ? 1 : 0,
        'size'     => (int)$size,
        'mtime'    => $mt ? date('Y-m-d H:i:s', $mt) : null,
        '_live'    => 1,
      ];
    }
    // Ordner zuerst, dann natürliche Sortierung
    usort($out, function($a,$b){
      if ($a['is_dir'] != $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
      return strnatcasecmp($a['name'],$b['name']);
    });
    return $out;
  }

  /**
   * "Smartes" Listing: zuerst DB, bei leerem Ergebnis optional Live-Fallback.
   * Gibt zusätzlich ein Flag per Referenz zurück, ob Live genutzt wurde.
   */
  function fs_list_children_smart(mysqli $db, int $projectId, string $parentRel='', bool $fallbackLive=true, ?bool &$usedLive=null): array {
    $parentRel = fs_norm_rel($parentRel);
    $rows = fs_list_children($db, $projectId, $parentRel);
    if ($rows || !$fallbackLive) { $usedLive = false; return $rows; }

    $root = project_root_path($db, $projectId);
    if (!$root) { $usedLive = false; return []; }

    $live = fs_list_children_live($root, $parentRel);
    $usedLive = (bool)$live;
    return $live;
  }

  /**
   * Liest die Dateien live von der Festplatte (Google Drive) und gleicht fs_nodes für diesen Unterordner ab.
   * Dadurch werden Dateien und Ordner, die in Chrome oder im Explorer erstellt wurden, SOFORT angezeigt.
   */
  function fs_list_children_smart_sync(mysqli $db, int $projectId, string $parentRel=''): array {
    $parentRel = fs_norm_rel($parentRel);
    $root = project_root_path($db, $projectId);
    if (!$root || !is_dir($root)) {
      return fs_list_children($db, $projectId, $parentRel);
    }

    $live = fs_list_children_live($root, $parentRel);

    if (!empty($live)) {
      $ins = $db->prepare("INSERT INTO fs_nodes 
        (project_id, rel_path, name, parent_rel_path, is_dir, size, mtime) 
        VALUES (?, ?, ?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
          name = VALUES(name), 
          is_dir = VALUES(is_dir), 
          size = VALUES(size), 
          mtime = VALUES(mtime)");

      if ($ins) {
        $parent = ($parentRel !== '') ? $parentRel : null;
        foreach ($live as $item) {
          $rPath = (string)$item['rel_path'];
          $name  = (string)$item['name'];
          $isD   = (int)$item['is_dir'];
          $sz    = (int)$item['size'];
          $mt    = (string)($item['mtime'] ?: date('Y-m-d H:i:s'));
          $ins->bind_param("isssiis", $projectId, $rPath, $name, $parent, $isD, $sz, $mt);
          $ins->execute();
        }
        $ins->close();
      }
    }

    return $live;
  }

  /**
   * Breadcrumbs für Relativpfad.
   */
  function fs_breadcrumbs(string $rel): array {
    $rel = fs_norm_rel($rel);
    if($rel==='') return [];
    $parts=explode('/',$rel); $crumbs=[];
    for($i=0;$i<count($parts);$i++){
      $crumbs[]=['label'=>$parts[$i],'rel'=>implode('/',array_slice($parts,0,$i+1))];
    }
    return $crumbs;
  }

  /**
   * Stellt sicher, dass die Standard-Hauptordner eines PROJEKTS existieren.
   */
  function fs_ensure_project_structure(mysqli $db, int $projectId): array {
    $root = project_root_path($db, $projectId);
    if (!$root || !is_dir($root)) return ['ok' => false, 'msg' => 'Projekt-Root nicht gefunden'];

    $mainFolders = [
        "01_Rechnungen",
        "02_Handwerker_Unterhalt",
        "03_Versicherungen",
        "04_Steuern",
        "05_Allgemein",
        "06_Bank_Liegenschaftskonto",
        "07_Mietverträge",
        "08_Mahnungen",
        "09_Korrespondenz",
        "10_Mietsache"
    ];

    foreach ($mainFolders as $f) {
        $abs = $root . DIRECTORY_SEPARATOR . $f;
        if (!is_dir($abs)) @mkdir($abs, 0777, true);
    }
    return ['ok' => true];
  }

  /**
   * Stellt sicher, dass die 10 Standard-Hauptordner eines OBJEKTS (Haus) existieren.
   * Inklusive Monats-Ordner für Rechnungen.
   */
  function fs_ensure_object_structure(mysqli $db, int $objId): array {
    $res = $db->query("SELECT o.folder_name, o.projekt_id FROM objekte o WHERE o.id = $objId");
    $obj = $res->fetch_assoc();
    if (!$obj) return ['ok' => false, 'msg' => 'Objekt nicht gefunden'];

    $root = project_root_path($db, (int)$obj['projekt_id']);
    if (!$root || !is_dir($root)) return ['ok' => false, 'msg' => 'Projekt-Root nicht gefunden'];

    $objPath = fs_abs_from_rel($root, $obj['folder_name'] ?: "Objekt_".$objId);
    if (!is_dir($objPath)) @mkdir($objPath, 0777, true);

    $mainFolders = [
        "01_Rechnungen",
        "02_Handwerker_Unterhalt",
        "03_Versicherungen",
        "04_Steuern",
        "05_Allgemein",
        "06_Bank_Liegenschaftskonto",
        "07_Mietverträge",
        "08_Mahnungen",
        "10_Mietsache"
    ];

    foreach ($mainFolders as $f) {
        $abs = $objPath . DIRECTORY_SEPARATOR . $f;
        if (!is_dir($abs)) @mkdir($abs, 0777, true);

        // Spezialfall Rechnungen: Monatsordner
        if ($f === "01_Rechnungen") {
            $months = [
                "01_Januar", "02_Februar", "03_März", "04_April", 
                "05_Mai", "06_Juni", "07_Juli", "08_August", 
                "09_September", "10_Oktober", "11_November", "12_Dezember"
            ];
            foreach ($months as $m) {
                $mAbs = $abs . DIRECTORY_SEPARATOR . $m;
                if (!is_dir($mAbs)) @mkdir($mAbs, 0777, true);
                
                $payAbs = $mAbs . DIRECTORY_SEPARATOR . "Bezahlt";
                if (!is_dir($payAbs)) @mkdir($payAbs, 0777, true);
            }
        }
    }
    return ['ok' => true];
  }

  function ensure_unit_folder(mysqli $db, int $wohnungId): array {
    $res = $db->query("
        SELECT w.id, w.name as w_name, w.folder_name as w_folder, o.projekt_id, o.folder_name as o_folder
        FROM wohnungen w 
        JOIN objekte o ON o.id = w.objekt_id 
        WHERE w.id = $wohnungId
    ");
    $w = $res->fetch_assoc();
    if (!$w) return ['ok' => false, 'msg' => 'Einheit nicht gefunden'];

    $pId = (int)$w['projekt_id'];
    $root = project_root_path($db, $pId);
    if (!$root) return ['ok' => false, 'msg' => 'Projekt-Rootpfad nicht definiert'];

    // Haus-Ebene (Objekt)
    $objRel = $w['o_folder'] ?: "Objekt_".$w['id'];
    
    // Mietsachen-Ebene (fest in 10_Mietsache für Wohnungen)
    $baseRel = $objRel . "/10_Mietsache";
    
    // Wir nehmen den Namen als Basis
    $cleanName = preg_replace('/[\/\\:*?"<>|]/', '_', $w['w_name']);
    $oldFolderName = $w['w_folder'];

    // 1. Initialer Sync, wenn folder_name noch leer
    if (empty($oldFolderName)) {
        $db->query("UPDATE wohnungen SET folder_name = '".$db->real_escape_string($cleanName)."' WHERE id = $wohnungId");
        $oldFolderName = $cleanName;
    }

    $oldUnitRel = $baseRel . "/" . $oldFolderName;
    $newUnitRel = $baseRel . "/" . $cleanName;
    
    $oldAbs = fs_abs_from_rel($root, $oldUnitRel);
    $newAbs = fs_abs_from_rel($root, $newUnitRel);

    $status = "identisch";

    // 2. Umbenennung prüfen
    if ($oldFolderName !== $cleanName) {
        if (is_dir($oldAbs)) {
            if (@rename($oldAbs, $newAbs)) {
                $db->query("UPDATE wohnungen SET folder_name = '".$db->real_escape_string($cleanName)."' WHERE id = $wohnungId");
                $status = "umbenannt";
                $oldAbs = $newAbs; // Pfad für Subordner-Check aktualisieren
            } else {
                // Falls rename fehlschlägt (z.B. Ziel existiert schon), fallback auf alten Namen
                $cleanName = $oldFolderName;
                $newAbs = $oldAbs;
            }
        } else {
            // Wenn alter Ordner gar nicht existiert, einfach DB updaten
            $db->query("UPDATE wohnungen SET folder_name = '".$db->real_escape_string($cleanName)."' WHERE id = $wohnungId");
            $oldAbs = $newAbs;
        }
    }

    // 3. Existenz sicherstellen
    if (!is_dir($oldAbs)) {
        if (@mkdir($oldAbs, 0777, true)) {
            $status = "neu angelegt";
        } else {
            return ['ok' => false, 'msg' => 'Ordner konnte nicht erstellt werden'];
        }
    }

    // Subordner
    $subs = ["01_Mieter", "02_Bilder", "03_Dokumente", "04_Vertraege", "05_Abnahmen"];
    foreach ($subs as $s) {
        $sAbs = $oldAbs . DIRECTORY_SEPARATOR . $s;
        if (!is_dir($sAbs)) @mkdir($sAbs, 0777, true);
    }

    return ['ok' => true, 'msg' => 'Erfolgreich', 'status' => $status];
  }

  /**
   * Massen-Sync für alle Einheiten eines Projekts.
   * Bidirektional: 
   * 1. DB -> Disk: Fehlende Ordner anlegen, Namen korrigieren.
   * 2. Disk -> DB: Fehlende Wohnungen importieren (aus 10_Wohnungen).
   * 3. DB -> Cleanup: Wohnungen löschen, deren Ordner im Drive fehlen.
   */
  function sync_project_folders(mysqli $db, int $projectId, bool $pruneDb = false): array {
    $stats = ['total' => 0, 'created_disk' => 0, 'created_db' => 0, 'deleted_db' => 0, 'renamed' => 0, 'errors' => 0];
    
    $root = project_root_path($db, $projectId);
    if (!$root) return $stats;

    // 1. Zuerst alle Objekte (Häuser) des Projekts prüfen und Namen synchronisieren
    // Wir schauen physisch im Root nach Ordnern, um neue Häuser zu finden oder bestehende zu aktualisieren
    $items = @scandir($root) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (is_dir($root . DIRECTORY_SEPARATOR . $item)) {
            // Check if object exists (by folder_name or name)
            $stmt = $db->prepare("SELECT id, name, folder_name FROM objekte WHERE projekt_id = ? AND (folder_name = ? OR name = ?)");
            $stmt->bind_param("iss", $projectId, $item, $item);
            $stmt->execute();
            $oRes = $stmt->get_result();
            if ($row = $oRes->fetch_assoc()) {
                $oid = (int)$row['id'];
                // Namen angleichen: name = folder_name
                $db->query("UPDATE objekte SET folder_name = '".$db->real_escape_string($item)."', name = '".$db->real_escape_string($item)."' WHERE id = $oid");
            }
        }
    }

    // 2. Jetzt die Wohnungen innerhalb der (bekannten) Objekte synchronisieren
    $resObj = $db->query("SELECT id, name, folder_name FROM objekte WHERE projekt_id = $projectId");
    while($obj = $resObj->fetch_assoc()) {
        $oId = (int)$obj['id'];
        $oFolder = $obj['folder_name'] ?: $obj['name'];
        
        // A. DB -> Disk (Wohnungen anlegen)
        $resW = $db->query("SELECT id FROM wohnungen WHERE objekt_id = $oId");
        while($w = $resW->fetch_assoc()){
            $sync = ensure_unit_folder($db, (int)$w['id']);
            $stats['total']++;
            if($sync['ok']){
                if($sync['status'] == 'neu angelegt') $stats['created_disk']++;
                if($sync['status'] == 'umbenannt') $stats['renamed']++;
            }
        }

        // B. Disk -> DB (Neue Ordner aus 10_Mietsache importieren & Namen korrigieren)
        $baseRel = ($oFolder ? $oFolder . "/" : "") . "10_Mietsache";
        $absBase = fs_abs_from_rel($root, $baseRel);
        
        if ($absBase && is_dir($absBase)) {
            $folders = @scandir($absBase) ?: [];
            foreach ($folders as $f) {
                if ($f === '.' || $f === '..') continue;
                if (is_dir($absBase . DIRECTORY_SEPARATOR . $f)) {
                    // Check if unit exists
                    $stmt = $db->prepare("SELECT id FROM wohnungen WHERE objekt_id = ? AND (folder_name = ? OR name = ?)");
                    $stmt->bind_param("iss", $oId, $f, $f);
                    $stmt->execute();
                    $uRes = $stmt->get_result();
                    if ($uRow = $uRes->fetch_assoc()) {
                        $wid = (int)$uRow['id'];
                        // Name in der DB an Ordnernamen anpassen
                        $db->query("UPDATE wohnungen SET folder_name = '".$db->real_escape_string($f)."', name = '".$db->real_escape_string($f)."' WHERE id = $wid");
                    } else {
                        // Neu importieren
                        $db->query("INSERT INTO wohnungen (objekt_id, name, folder_name) VALUES ($oId, '".$db->real_escape_string($f)."', '".$db->real_escape_string($f)."')");
                        $stats['created_db']++;
                    }
                }
            }
        }

        // C. Pruning (Schutz vor Datenverlust: Kein automatisches Löschen bei fehlenden Cloud-Ordnern)
        if ($pruneDb) {
            $resW2 = $db->query("SELECT id, folder_name, name FROM wohnungen WHERE objekt_id = $oId");
            while($w = $resW2->fetch_assoc()){
                $fRel = $baseRel . "/" . ($w['folder_name'] ?: $w['name']);
                $fAbs = fs_abs_from_rel($root, $fRel);
                if (!$fAbs || !is_dir($fAbs)) {
                    // SICHERHEIT: Niemals DB-Datensätze löschen, nur weil ein Ordner lokal oder in Drive fehlt!
                    error_log("FS-Sync Warnung: Ordner fehlt für Wohnung ID " . (int)$w['id'] . " ($fRel) - Löschen unterbunden.");
                }
            }
        }
    }

    return $stats;
  }
  /**
   * Zentraler Path-Provider für das gesamte System (SSOT).
   * Liefert den absoluten und relativen Pfad für jede Entität.
   * @return array{abs:string|null, rel:string|null, data:array|null}
   */
  function fs_get_entity_path(mysqli $db, string $type, int $id): array {
    $res = null;
    $rel = null;
    $abs = null;

    if ($type === 'wohnung') {
        $st = $db->prepare("
            SELECT w.name as w_name, w.folder_name as w_folder, o.folder_name as o_folder, o.projekt_id 
            FROM wohnungen w 
            JOIN objekte o ON w.objekt_id = o.id 
            WHERE w.id = ?
        ");
        $st->bind_param("i", $id); $st->execute(); $res = $st->get_result()->fetch_assoc(); $st->close();
        
        if ($res) {
            $root = project_root_path($db, (int)$res['projekt_id']);
            if ($root) {
                $oF = $res['o_folder'] ?: "Objekt";
                $wF = !empty($res['w_folder']) ? $res['w_folder'] : $res['w_name'];
                $rel = $oF . "/10_Mietsache/" . $wF;
                $abs = fs_abs_from_rel($root, $rel);
            }
        }
    } elseif ($type === 'objekt') {
        $st = $db->prepare("SELECT folder_name, projekt_id FROM objekte WHERE id = ?");
        $st->bind_param("i", $id); $st->execute(); $res = $st->get_result()->fetch_assoc(); $st->close();
        if ($res) {
            $root = project_root_path($db, (int)$res['projekt_id']);
            if ($root) {
                $rel = $res['folder_name'] ?: "";
                $abs = fs_abs_from_rel($root, $rel);
            }
        }
    } elseif ($type === 'projekt') {
        $root = project_root_path($db, $id);
        if ($root) {
            $rel = "";
            $abs = $root;
        }
    }

    return ['abs' => $abs, 'rel' => $rel, 'data' => $res];
  }
}
