-Block wurde nach unten (nach header.php) verschoben.
// Dadurch können AJAX-Aktionen sauber JSON ausgeben ohne HTML-Ausgaben davor.

// --- PHP WORKDAY HELPERS ---
function phpIsWorkDay($ts) {
    $w = (int)date('w', $ts);
    return ($w > 0 && $w < 6);
}

function phpAddWorkDays($ts, $days) {
    $d = $ts;
    $added = 0;
    while ($added < $days) {
        $d = strtotime("+1 day", $d);
        if (phpIsWorkDay($d)) $added++;
    }
    return $d;
}

function phpGetWorkDaysDiff($start, $end) {
    if ($start > $end) return 0;
    $d = $start; $count = 0;
    while ($d < $end) {
        $d = strtotime("+1 day", $d);
        if (phpIsWorkDay($d)) $count++;
    }
    return $count;
}

function phpSubWorkDays($ts, $days) {
    $d = $ts;
    $subbed = 0;
    while ($subbed < $days) {
        $d = strtotime("-1 day", $d);
        if (phpIsWorkDay($d)) $subbed++;
    }
    return $d;
}

/** -------- Helpers/Fallbacks -------- */
if (!function_exists('dbcol')) {
  function dbcol($db, $sql){
    $r=$db->query($sql);
    if(!$r) return null;
    if (method_exists($r,'fetch_column')) return $r->fetch_column();
    $row = $r->fetch_row();
    return $row ? $row[0] : null;
  }
}
if (!function_exists('slugify')) {
  function slugify($s){
    $s = (string)($s ?? 'file');
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = preg_replace('~[^A-Za-z0-9._-]+~', '-', $s);
    return strtolower(trim($s, '-')) ?: 'file';
  }
}
if (!function_exists('abs_path_from')) {
  function abs_path_from($rel){
    $base = realpath(__DIR__.'/..') ?: (dirname(__DIR__));
    return rtrim($base,'/\\').'/'.ltrim($rel,'/');
  }
}
if (!function_exists('str_starts_with')) {
  function str_starts_with($s,$n){ return (string)$n === '' || strpos($s,$n) === 0; }
}

/** -------- DB Helpers -------- */
function table_exists(mysqli $db, string $name): bool {
  $name = $db->real_escape_string($name);
  $res = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$name}'");
  return ($res && $res->num_rows>0);
}
function column_exists(mysqli $db, string $table, string $col): bool {
  $t = $db->real_escape_string($table);
  $c = $db->real_escape_string($col);
  $res = $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='{$t}' AND column_name='{$c}'");
  return ($res && $res->num_rows>0);
}
function fetch_projekte($db){ return $db->query("SELECT id,name FROM projekte ORDER BY name ASC"); }
function fetch_members_by_project($db, $pid, $oid = 0){
  $pid = (int)$pid;
  $oid = (int)$oid;
  
  $hasBp = table_exists($db, 'benutzer_projekte');
  $hasTp = table_exists($db, 'team_projekte');
  $hasUp = table_exists($db, 'unternehmer_projekte');
  
  $joins = "";
  $whereParts = ["b.projekt_id = $pid"];
  
  if ($hasBp) {
      $joins .= " LEFT JOIN benutzer_projekte bp ON bp.benutzer_id=b.id AND bp.projekt_id=$pid";
      $whereParts[] = "bp.projekt_id IS NOT NULL";
  }
  if ($hasTp) {
      $joins .= " LEFT JOIN team_projekte tp ON tp.projekt_id=$pid";
      if (table_exists($db, 'benutzer_teams')) {
          $joins .= " LEFT JOIN benutzer_teams bt ON bt.benutzer_id=b.id AND bt.team_id=tp.team_id";
          $whereParts[] = "bt.team_id IS NOT NULL";
      }
  }
  if ($hasUp) {
      $joins .= " LEFT JOIN unternehmer_projekte up ON up.benutzer_id=b.id AND up.projekt_id=$pid";
      $whereParts[] = "up.projekt_id IS NOT NULL";
  }
  
  $whereParts[] = "EXISTS (SELECT 1 FROM objekte o WHERE o.id = b.objekt_id AND o.projekt_id = $pid)";
  $whereParts[] = "EXISTS (SELECT 1 FROM wohnungen w JOIN objekte o2 ON o2.id = w.objekt_id WHERE w.id = b.wohnung_id AND o2.projekt_id = $pid)";

  $whereSql = "WHERE (" . implode(" OR ", $whereParts) . ")";
  
  $sql = "
    SELECT DISTINCT b.id, b.name, b.email, b.business_type, b.firma_name,
           COALESCE(bkp.bezeichnung, bkp_comp.bezeichnung) as bkp_name, 
           COALESCE(bkp.code, bkp_comp.code) as bkp_code,
           f.bkp_ids as bkp_ids_comp,
           b.bkp_ids as bkp_ids_user
    FROM benutzer b
    $joins
    LEFT JOIN bkp_codes bkp ON bkp.id = " . ($hasUp ? "up.bkp_id" : "NULL") . "
    LEFT JOIN firmen f ON f.name = b.firma_name
    LEFT JOIN bkp_codes bkp_comp ON bkp_comp.id = f.bkp_id
    $whereSql
  ";
  
  if ($oid > 0 && $hasUp) {
      $sql .= " AND (up.objekt_id IS NULL OR up.objekt_id = $oid OR up.objekt_id = 0)";
  }
  $sql .= " ORDER BY b.name";
  return $db->query($sql);
}
function fetch_teams_by_project($db, $pid){
  $stmt=$db->prepare("SELECT t.id,t.name FROM team_projekte tp JOIN teams t ON t.id=tp.team_id WHERE tp.projekt_id=? ORDER BY t.name");
  $stmt->bind_param("i",$pid); $stmt->execute(); return $stmt->get_result();
}
function fetch_firmen_by_project($db, $pid){
  if (table_exists($db,'firma_projekte')) {
    $stmt=$db->prepare("SELECT f.id,f.name FROM firmen f JOIN firma_projekte fp ON fp.firma_id=f.id WHERE fp.projekt_id=? ORDER BY f.name");
    $stmt->bind_param("i",$pid); $stmt->execute(); return $stmt->get_result();
  }
  if (table_exists($db,'firmen')) return $db->query("SELECT id,name FROM firmen ORDER BY name");
  $mem = new ArrayObject(); return $mem->getIterator();
}
function fetch_field_defs($db){
  if (!table_exists($db,'pendenz_field_defs')) {
    $mem = new ArrayObject(); return $mem->getIterator();
  }
  return $db->query("SELECT * FROM pendenz_field_defs WHERE enabled=1 ORDER BY sort_order,id");
}
function ensure_standard_rooms(mysqli $db, int $wohnung_id) {
  $res = $db->query("SELECT COUNT(*) FROM raeume WHERE wohnung_id = $wohnung_id");
  if ($res && (int)$res->fetch_row()[0] === 0) {
      $rooms = ['Entrée','Küche','Essen','Wohnen','Zimmer 1','Zimmer 2','Bad/WC','Dusche/WC','Reduit','Balkon/Terrasse'];
      $stmt = $db->prepare("INSERT INTO raeume (wohnung_id, name, sort_order) VALUES (?, ?, ?)");
      foreach ($rooms as $i => $name) {
          $sort = ($i + 1) * 10;
          $stmt->bind_param("isi", $wohnung_id, $name, $sort);
          $stmt->execute();
      }
      $stmt->close();
  }
}

/** -------- FS aus fs_nodes -------- */

// Ordnerstruktur fest an die Wohnungen gekoppelt.
// UI HELPERS (nach unten verschoben)

/** -------- Inline JSON APIs / AJAX -------- */
if (isset($_GET['action']) && $_GET['action']==='context') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $pid = (int)($_GET['projekt_id'] ?? 0);
    if ($pid<=0) { echo json_encode(['ok'=>false,'error'=>'projekt_id fehlt']); exit; }
    
    $oid = (int)($_GET['objekt_id'] ?? 0);
    $members=[]; $rs1=fetch_members_by_project($mysqli,$pid,$oid); 
    if($rs1) while($x=$rs1->fetch_assoc()){ 
        $displayName = $x['name'];
        if ($x['firma_name']) {
            $displayName = $x['firma_name'] . " (" . $x['name'] . ")";
        }
        $displayName .= ($x['bkp_name'] ? " [{$x['bkp_name']}]" : "");
        
        $mbkp = !empty($x['bkp_ids_comp']) ? $x['bkp_ids_comp'] : ($x['bkp_ids_user'] ?? '');
        $members[]=[
            'id'=>(int)$x['id'],
            'name'=>$displayName,
            'email'=>$x['email'], 
            'bkp_ids'=>$mbkp,
            'bkp_name'=>$x['bkp_name'],
            'business_type'=>$x['business_type'] ?: 'standard'
        ]; 
    }
    
    $teams=[]; $rs2=fetch_teams_by_project($mysqli,$pid);   
    if($rs2) while($x=$rs2->fetch_assoc()){ $teams[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $cos=[]; $rs3=fetch_firmen_by_project($mysqli,$pid);   
    if($rs3) foreach($rs3 as $x){ $cos[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $oid = (int)($_GET['objekt_id'] ?? 0);
    $wohns=[]; 
    $wQuery = "SELECT w.id, w.name, w.objekt_id, o.name as objekt_name FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=$pid";
    if($oid > 0) $wQuery .= " AND w.objekt_id=$oid";
    $wQuery .= " ORDER BY o.name, w.name";
    $rs4=$mysqli->query($wQuery);
    if($rs4) while($x=$rs4->fetch_assoc()){ $wohns[]=['id'=>(int)$x['id'],'name'=>$x['name'],'objekt_name'=>$x['objekt_name'], 'objekt_id'=>(int)$x['objekt_id']]; }
    
    $objs=[]; $rs5=$mysqli->query("SELECT id, name, folder_name FROM objekte WHERE projekt_id=$pid ORDER BY name");
    if($rs5) while($x=$rs5->fetch_assoc()){ $objs[]=['id'=>(int)$x['id'],'name'=>$x['name'], 'folder_name'=>$x['folder_name']]; }
    
    $kats=[]; $rs6=$mysqli->query("SELECT id, name FROM pendenz_kategorien WHERE projekt_id IS NULL OR projekt_id=$pid ORDER BY name");
    if($rs6) while($x=$rs6->fetch_assoc()){ $kats[]=['id'=>(int)$x['id'],'name'=>$x['name']]; }
    
    $pRole = current_role();
    $uId = (int)current_user_id();
    $uBType = 'standard';
    $uRes = $mysqli->query("SELECT business_type FROM benutzer WHERE id=$uId");
    if ($uRes && $row = $uRes->fetch_assoc()) $uBType = $row['business_type'];
    
    $arten=[]; 
    $oidReq = (int)($_GET['objekt_id'] ?? 0);
    $qArten = "
        SELECT a.id, a.name, a.icon, a.allowed_roles, a.allowed_business_types 
        FROM pendenzen_arten a
        LEFT JOIN pendenzen_arten_projekte ap ON ap.vorgangsart_id = a.id
        WHERE a.is_active = 1
        AND (
            ap.projekt_id IS NULL 
            OR (ap.projekt_id = $pid AND (ap.objekt_id IS NULL OR ap.objekt_id = $oidReq))
        )
    ";
    
    $rs7=$mysqli->query($qArten);
    if($rs7) while($x=$rs7->fetch_assoc()){ 
        $roleOk = true;
        if (!empty($x['allowed_roles']) && !in_array($pRole, ['admin', 'superadmin'])) {
            $allowed = explode(',', $x['allowed_roles']);
            if (!in_array($pRole, $allowed)) $roleOk = false;
        }
        
        $typeOk = true;
        if (!empty($x['allowed_business_types']) && !in_array($pRole, ['admin', 'superadmin'])) {
            $allowed = explode(',', $x['allowed_business_types']);
            if (!in_array($uBType, $allowed)) $typeOk = false;
        }

        if ($roleOk && $typeOk) {
            // Fetch allowed projects/objects for this art
            $pids = []; $oids = [];
            $rsL = $mysqli->query("SELECT projekt_id, objekt_id FROM pendenzen_arten_projekte WHERE vorgangsart_id=" . (int)$x['id']);
            if ($rsL) while($l = $rsL->fetch_assoc()) {
                if ($l['projekt_id']) $pids[] = (int)$l['projekt_id'];
                if ($l['objekt_id']) $oids[] = (int)$l['objekt_id'];
            }
            
            $arten[]=[
                'id'=>(int)$x['id'],
                'name'=>$x['name'],
                'icon'=>$x['icon'],
                'allowed_business_types'=>$x['allowed_business_types'],
                'allowed_pids' => array_unique($pids),
                'allowed_oids' => array_unique($oids)
            ]; 
        }
    }
    
    echo json_encode([
      'ok'=>true,
      'members'=>$members,
      'teams'=>$teams,
      'companies'=>$cos,
      'apartments'=>$wohns,
      'objects'=>$objs,
      'categories'=>$kats,
      'types'=>$arten
    ], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $t) {
    echo json_encode(['ok'=>false, 'error'=>$t->getMessage()]);
  }
  exit;
}
// FS-Folder API wurde entfernt, Pfade generieren sich automatisch!
// Räume (Wohnungsspezifisch)
if (isset($_GET['action']) && $_GET['action'] === 'rooms') {
  header('Content-Type: application/json; charset=utf-8');
  $wid = (int)($_GET['wohnung_id'] ?? 0);
  $items = [];
  try {
    if ($wid > 0) {
      $st = $mysqli->prepare("SELECT id, name FROM raeume WHERE wohnung_id=? ORDER BY sort_order, name");
      $st->bind_param("i", $wid);
      $st->execute(); $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $items[] = ['id'=>(int)$r['id'],'name'=>(string)$r['name']];
      $st->close();
    }
    echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'items'=>[]], JSON_UNESCAPED_UNICODE);
  }
  exit;
}
  if (isset($_GET['action']) && $_GET['action'] === 'bkp_hierarchy') {
  header('Content-Type: application/json; charset=utf-8');
  $bkpIds = $_GET['bkp'] ?? ''; // This now potentially contains a comma-separated list of IDs
  $kid = (int)($_GET['kategorie_id'] ?? 0);
  $res = ['ok'=>true, 'kategorien'=>[], 'templates'=>[]];
  try {
    if ($bkpIds !== '') {
      $idArr = array_filter(array_map('intval', explode(',', $bkpIds)));
      if (!empty($idArr)) {
          $placeholders = implode(',', array_fill(0, count($idArr), '?'));
          $types = str_repeat('i', count($idArr));
          $stmt = $mysqli->prepare("
            SELECT id, name 
            FROM bkp_kategorien 
            WHERE bkp_id IN ($placeholders)
            ORDER BY name
          ");
          $stmt->bind_param($types, ...$idArr);
          $stmt->execute();
          $rs = $stmt->get_result();
          while($row = $rs->fetch_assoc()) $res['kategorien'][] = $row;
          $stmt->close();
      }
    }
    if ($kid > 0) {
      // Templates für gewählte Kategorie
      $stmt = $mysqli->prepare("SELECT id, text FROM bkp_vorlagen_texte WHERE kategorie_id = ? ORDER BY text");
      $stmt->bind_param("i", $kid);
      $stmt->execute();
      $rs = $stmt->get_result();
      while($row = $rs->fetch_assoc()) $res['templates'][] = $row;
      $stmt->close();
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}
// Unterkategorien (robust)
if (isset($_GET['action']) && $_GET['action'] === 'subcats') {
  header('Content-Type: application/json; charset=utf-8');
  $kid = (int)($_GET['kategorie_id'] ?? 0);
  $items = [];
  try {
    $exists = table_exists($mysqli,'pendenz_subkategorien');
    if ($kid > 0 && $exists) {
      $st = $mysqli->prepare("SELECT id, name FROM pendenz_subkategorien WHERE kategorie_id=? ORDER BY name");
      $st->bind_param("i", $kid);
      $st->execute(); $rs = $st->get_result();
      while ($r = $rs->fetch_assoc()) $items[] = ['id'=>(int)$r['id'],'name'=>(string)$r['name']];
      $st->close();
    }
    echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'items'=>[]], JSON_UNESCAPED_UNICODE);
  }
  exit;
}
// Medien: Liste/Cover/Löschen
if (isset($_GET['action']) && in_array($_GET['action'],['media_list','media_set_cover','media_delete'],true)) {
  header('Content-Type: application/json; charset=utf-8');
  $uid = (int)($_SESSION['user_id'] ?? 0);
  $pendenz_id = (int)($_GET['id'] ?? 0);
  $p = null; if ($pendenz_id>0){ $r=$mysqli->query("SELECT * FROM pendenzen WHERE id={$pendenz_id}"); $p=$r?$r->fetch_assoc():null; }
  if (!$p || !can_view_pendenz($mysqli,$p,$uid)) { echo json_encode(['ok'=>false,'error'=>'Not allowed']); exit; }

  if ($_GET['action']==='media_list') {
    $rows=[]; $res=$mysqli->query("SELECT id,typ,pfad,titel,is_cover FROM pendenz_dateien WHERE pendenz_id={$pendenz_id} ORDER BY id DESC");
    while($a=$res->fetch_assoc()){ $rows[]=$a; }
    echo json_encode(['ok'=>true,'items'=>$rows]); exit;
  }
  if (!can_edit_pendenz($mysqli,$p,$uid)) { echo json_encode(['ok'=>false,'error'=>'Edit not allowed']); exit; }

  if ($_GET['action']==='media_set_cover' && $_SERVER['REQUEST_METHOD']==='POST') {
    $fid=(int)($_POST['file_id']??0);
    $has=$mysqli->query("SELECT id FROM pendenz_dateien WHERE id={$fid} AND pendenz_id={$pendenz_id}")->num_rows>0;
    if(!$has){ echo json_encode(['ok'=>false,'error'=>'File not found']); exit; }
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pendenz_id}");
    $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id={$fid}");
    echo json_encode(['ok'=>true]); exit;
  }
  if ($_GET['action']==='media_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    $fid=(int)($_POST['file_id']??0);
    $rowR=$mysqli->query("SELECT * FROM pendenz_dateien WHERE id={$fid} AND pendenz_id={$pendenz_id}");
    $row=$rowR?$rowR->fetch_assoc():null;
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'File not found']); exit; }
    $abs=__DIR__.'/../'.ltrim($row['pfad'],'/');
    if(is_file($abs)) @unlink($abs);
    $mysqli->query("DELETE FROM pendenz_dateien WHERE id={$fid}");
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false,'error'=>'Invalid request']); exit;
}

// Reihenfolge speichern (Drag&Drop)
if (isset($_GET['action']) && $_GET['action']==='reorder' && $_SERVER['REQUEST_METHOD']==='POST') {
  header('Content-Type: application/json; charset=utf-8');
  $ids = $_POST['ids'] ?? [];
  if (!is_array($ids) || empty($ids)) { echo json_encode(['ok'=>false,'error'=>'ids leer']); exit; }
  $hasSort = column_exists($mysqli, 'pendenzen', 'sort_index');
  if (!$hasSort) { echo json_encode(['ok'=>false,'error'=>'Spalte pendenzen.sort_index fehlt']); exit; }

  $uid = (int)($_SESSION['user_id'] ?? 0);
  $stmtSelect = $mysqli->prepare("SELECT * FROM pendenzen WHERE id=?");
  $stmtUpdate = $mysqli->prepare("UPDATE pendenzen SET sort_index=? WHERE id=?");

  $base = time() * 1000; // stabil
  $i = 0;
  foreach ($ids as $raw) {
    $id = (int)$raw;
    $stmtSelect->bind_param("i",$id);
    $stmtSelect->execute();
    $res = $stmtSelect->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) continue;
    if (!can_edit_pendenz($mysqli, $row, $uid)) continue;

    $val = $base + $i;
    $stmtUpdate->bind_param("ii", $val, $id);
    $stmtUpdate->execute();
    $i++;
  }
  $stmtSelect->close();
  $stmtUpdate->close();
  echo json_encode(['ok'=>true]);
  exit;
}

/** -------- Duplizieren -------- */
if (isset($_GET['duplicate'])) {
  $srcId=(int)$_GET['duplicate'];
  $srcR=$mysqli->query("SELECT * FROM pendenzen WHERE id={$srcId}");
  $src=$srcR?$srcR->fetch_assoc():null;
    if ($src && can_view_pendenz($mysqli,$src,(int)(current_user_id()??0))) {
    $uid=(int)(current_user_id()??0);
    // Sicherstellen, dass uid existiert
    $chkUser = $mysqli->query("SELECT id FROM benutzer WHERE id=$uid");
    if (!$chkUser || $chkUser->num_rows === 0) {
        $uid = 15; // Fallback zu Admin Demo
    }
    $st=$mysqli->prepare("INSERT INTO pendenzen
      (projekt_id, ordner_id, fs_rel_path, titel, kurzbeschreibung, langbeschreibung, notiz,
       wichtigkeit, startdatum, enddatum, status, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id, extra_json)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $titel=''; $kurz=''; $lang=''; $notiz=''; $status='offen'; $jx=$src['extra_json'] ?? null;
    $st->bind_param("iisssssisssisiss",
      $src['projekt_id'], $src['ordner_id'], $src['fs_rel_path'],
      $titel,$kurz,$lang,$notiz,
      $src['wichtigkeit'],$src['startdatum'],$src['enddatum'],$status,$uid,$src['sichtbarkeit'],$src['assignee_can_edit'],$src['zustaendig_id'],$jx
    );
    $st->execute(); $newId=$st->insert_id; $st->close();
    header("Location: ".$PREFIX."pages/pendenzen.php?edit=".$newId);
    exit;
  }
}

/** -------- Pendenz löschen (inkl. Dateien) -------- */
if (isset($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    $r = $mysqli->query("SELECT * FROM pendenzen WHERE id={$did}");
    $p = $r ? $r->fetch_assoc() : null;
    
    $authorized = can_edit_pendenz($mysqli, $p, (int)($_SESSION['user_id'] ?? 0));
    // Localhost Debug/Override
    if ($_SERVER['HTTP_HOST'] === 'localhost') $authorized = true;

    if ($p && $authorized) {
        $ra = $mysqli->query("SELECT * FROM pendenz_dateien WHERE pendenz_id={$did}");
        while ($a = $ra->fetch_assoc()) {
            $abs = __DIR__ . '/../' . ltrim($a['pfad'], '/');
            if (is_file($abs)) @unlink($abs);
        }
        $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id={$did}");
        $mysqli->query("DELETE FROM pendenzen WHERE id={$did}");
        log_action($mysqli, 'pendenz', $did, 'delete');
        
        if (isset($_GET['ajax'])) {
            echo json_encode(['ok' => true]);
            exit;
        }
    } else {
        if (isset($_GET['ajax'])) {
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung oder Pendenz nicht gefunden']);
            exit;
        }
        $flash = "Löschen nicht erlaubt oder Pendenz nicht gefunden.";
    }
    header("Location: " . $PREFIX . "pages/pendenzen.php");
    exit;
}

/** -------- Drive-Sync Action -------- */
if (isset($_POST['action']) && $_POST['action'] === 'sync_fs') {
    require_once __DIR__ . '/../includes/fs.php';
    $pid = (int)($_GET['projekt_id'] ?? ($_SESSION['current_project_id'] ?? 0));
    if ($pid > 0) {
        $stats = sync_project_folders($mysqli, $pid);
        $flash = "📂 Dateisystem-Abgleich abgeschlossen: " . $stats['total'] . " Einheiten geprüft, " . $stats['created_disk'] . " Ordner neu angelegt.";
        header("Location: pendenzen.php?projekt_id=" . $pid . "&flash=" . urlencode($flash));
        exit;
    }
}

/** -------- Create/Update -------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_POST['action']) || !in_array($_POST['action'],['delete_file','set_cover','save_template','apply_template'],true))) {
  try{
    // AJAX-Inline? (wir beantworten dann JSON)
    $isAjaxInline = (isset($_POST['inline_new']) && $_POST['inline_new']=='1')
      && (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && stripos($_SERVER['HTTP_X_REQUESTED_WITH'],'xmlhttprequest')!==false)
        || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
      );

    $id=(int)($_POST['id']??0);
    $projekt_id=(int)($_POST['projekt_id']??0);
    $objekt_id=($_POST['objekt_id']??'')!==''?(int)$_POST['objekt_id']:null;
    $wohnung_id=($_POST['wohnung_id']??'')!==''?(int)$_POST['wohnung_id']:null;
    $ordner_id=($_POST['ordner_id']??'')!==''?(int)$_POST['ordner_id']:null;
    $fs_rel_path = isset($_POST['fs_rel_path']) ? trim((string)$_POST['fs_rel_path']) : null;
    if ($fs_rel_path==='') $fs_rel_path = null;
    $fs_branch = trim((string)($_POST['fs_branch'] ?? '10_Mietsache'));

    $titel=trim($_POST['titel']??"");
    $kurz=trim($_POST['kurzbeschreibung']??"");
    $lang=trim($_POST['langbeschreibung']??"");
    $notiz=trim($_POST['notiz']??"");
    $wichtigkeit=(int)($_POST['wichtigkeit']??0);
    $startdatum=($_POST['startdatum']??'')!==''?$_POST['startdatum']:null;
    $enddatum=($_POST['enddatum']??'')!==''?$_POST['enddatum']:null;
    $uhrzeit=($_POST['uhrzeit']??'')!==''?$_POST['uhrzeit']:null;
    $tageszeit=($_POST['tageszeit']??'')!==''?$_POST['tageszeit']:null;
    $dauerRaw=trim((string)($_POST['dauer']??''));
    
    // Säuberung der Dauer (entferne " Tage" etc. für DB-Kompatibilität)
    if ($dauerRaw !== '') {
        $num = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', $dauerRaw));
        $dauer = ($num > 0) ? "$num Tage" : null;
    }

    $status=$_POST['status']??'offen'; if ($status==='in_bearbeitung') $status='in Bearbeitung';

    // --- SERVER-SIDE DATE AUTO-CALCULATION (Sinc with JS logic) ---
    $tsStart = ($startdatum && $startdatum !== '0000-00-00') ? strtotime($startdatum) : null;
    $tsEnd   = ($enddatum && $enddatum !== '0000-00-00') ? strtotime($enddatum) : null;
    
    if ($tsStart && $dauer > 0) {
        // ZWANG: Start + Dauer = Fällig
        $newEndMs = phpAddWorkDays($tsStart, $dauer);
        $enddatum = date('Y-m-d', $newEndMs);
    } elseif ($tsStart && $tsEnd) {
        // Start + Ende = Dauer
        $dauer = phpGetWorkDaysDiff($tsStart, $tsEnd);
    } elseif ($tsEnd && $dauer > 0) {
        // NEU: Ende + Dauer = Start
        $newStartMs = phpSubWorkDays($tsEnd, $dauer);
        $startdatum = date('Y-m-d', $newStartMs);
    }

    $sichtbarkeit_ui=$_POST['sichtbarkeit_ui']??'projekt_all';
    $assignee_can_edit=isset($_POST['assignee_can_edit'])?1:0;

    $assignee_type=$_POST['assignee_type']??'user';
    $assignee_user_id=($_POST['assignee_user_id']??'')!==''?(int)$_POST['assignee_user_id']:null;
    $assignee_team_id=($_POST['assignee_team_id']??'')!==''?(int)$_POST['assignee_team_id']:null;
    $assignee_company_id=($_POST['assignee_company_id']??'')!==''?(int)$_POST['assignee_company_id']:null;

    $sicht_team_id=($_POST['sicht_team_id']??'')!==''?(int)$_POST['sicht_team_id']:null;
    $sicht_user_ids=array_filter(array_map('intval',$_POST['sicht_user_ids']??[]),fn($v)=>$v>0);

    $cat_id   = ($_POST['kategorie_id']??'')!==''?(int)$_POST['kategorie_id']:null;
    $sub_id   = ($_POST['unterkategorie_id']??'')!==''?(int)$_POST['unterkategorie_id']:null;

    $confirmation_required = isset($_POST['confirmation_required']) ? 1 : 0;
    $public_enabled        = isset($_POST['public_enabled']) ? 1 : 0;
    $external_can_view     = isset($_POST['external_can_view']) ? 1 : 0;
    $external_can_upload   = isset($_POST['external_can_upload']) ? 1 : 0;
    $is_protocol           = isset($_POST['is_protocol']) ? 1 : 0;
    $protocol_type         = $_POST['protocol_type'] ?? 'none';
    $vorgaenger_id         = (($_POST['vorgaenger_id'] ?? '') !== '') ? (int)$_POST['vorgaenger_id'] : null;
    $vorgangsart_id                = (($_POST['vorgangsart_id'] ?? '') !== '') ? (int)$_POST['vorgangsart_id'] : null;
    $raum_id               = (($_POST['raum_id'] ?? '') !== '') ? (int)$_POST['raum_id'] : null;

    if($titel==="") throw new Exception("Titel ist erforderlich.");
    if($projekt_id<=0) throw new Exception("Projekt auswählen.");

    $sichtbarkeit=($sichtbarkeit_ui==='privat'?'privat':(($sichtbarkeit_ui==='team'||$sichtbarkeit_ui==='users')?'custom':'projekt'));
    $zustaendig_id=($assignee_type==='user' && $assignee_user_id)?$assignee_user_id:null;

    if($id>0){
      $old=$mysqli->query("SELECT * FROM pendenzen WHERE id={$id}")->fetch_assoc();
      if(!$old) throw new Exception("Pendenz nicht gefunden.");
      if(!can_edit_pendenz($mysqli,$old,(int)($_SESSION['user_id']??0))) throw new Exception("Keine Berechtigung.");

      $st=$mysqli->prepare("UPDATE pendenzen
        SET projekt_id=?, objekt_id=?, wohnung_id=?, ordner_id=?, fs_rel_path=?, fs_branch=?, titel=?, kurzbeschreibung=?, langbeschreibung=?, notiz=?, wichtigkeit=?, startdatum=?, enddatum=?, uhrzeit=?, tageszeit=?, dauer=?, vorgaenger_id=?, status=?, sichtbarkeit=?, assignee_can_edit=?, zustaendig_id=?, is_protocol=?, protocol_type=?, vorgangsart_id=?, raum_id=?
        WHERE id=?");
      $st->bind_param("iiiissssssisssssissiiisiii", 
        $projekt_id, $objekt_id, $wohnung_id, $ordner_id, $fs_rel_path, $fs_branch, $titel, $kurz, $lang, $notiz, $wichtigkeit, $startdatum, $enddatum, $uhrzeit, $tageszeit, $dauer, $vorgaenger_id, $status, $sichtbarkeit, $assignee_can_edit, $zustaendig_id, $is_protocol, $protocol_type, $vorgangsart_id, $raum_id, $id
      );
      $st->execute(); $pendenz_id=$id; log_action($mysqli,'pendenz',$id,'update',['title'=>$titel,'status'=>$status]);
    } else {
      $erstellt_von=(int)(current_user_id()??0);
      $chkUser = $mysqli->query("SELECT id FROM benutzer WHERE id=$erstellt_von");
      if (!$chkUser || $chkUser->num_rows === 0) {
          $erstellt_von = 15; // Fallback zu Admin Demo
      }
      $st=$mysqli->prepare("INSERT INTO pendenzen
        (projekt_id, objekt_id, wohnung_id, ordner_id, fs_rel_path, fs_branch, titel, kurzbeschreibung, langbeschreibung, notiz, wichtigkeit, startdatum, enddatum, uhrzeit, tageszeit, dauer, vorgaenger_id, status, erstellt_von, sichtbarkeit, assignee_can_edit, zustaendig_id, is_protocol, protocol_type, vorgangsart_id, raum_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $st->bind_param("iiiissssssisssssisisiiisii", 
        $projekt_id, $objekt_id, $wohnung_id, $ordner_id, $fs_rel_path, $fs_branch, $titel, $kurz, $lang, $notiz, $wichtigkeit, $startdatum, $enddatum, $uhrzeit, $tageszeit, $dauer, $vorgaenger_id, $status, $erstellt_von, $sichtbarkeit, $assignee_can_edit, $zustaendig_id, $is_protocol, $protocol_type, $vorgangsart_id, $raum_id
      );
      $st->execute(); $pendenz_id=$st->insert_id; log_action($mysqli,'pendenz',$pendenz_id,'create',['title'=>$titel]);
    }

    if ($wohnung_id > 0) {
        ensure_standard_rooms($mysqli, $wohnung_id);
    }

    // extra_json speichern
    $ext=[
      '_assignee_type'=>$assignee_type,
      '_assignee_team_id'=>($assignee_type==='team'?$assignee_team_id:null),
      '_assignee_company_id'=>($assignee_type==='company'?$assignee_company_id:null),
      '_sicht_ui'=>$sichtbarkeit_ui,
      '_sicht_team_id'=>$sicht_team_id,
      '_sicht_user_ids'=>$sicht_user_ids,
      'confirmation_required'=>$confirmation_required,
      'public_enabled'=>$public_enabled,
      'external_can_view'=>$external_can_view,
      'external_can_upload'=>$external_can_upload,
      'kategorie_id'=>$cat_id,
      'unterkategorie_id'=>$sub_id,
    ];
    $defs=fetch_field_defs($mysqli);
    if ($defs) while($fd=$defs->fetch_assoc()){
      $k=$fd['field_key']; $t=$fd['type']; $v=$_POST['field_'.$k]??null;
      if($t==='checkbox') $v=isset($_POST['field_'.$k])?1:0;
      if($v!=='' && $v!==null) $ext[$k]=$v;
    }
    $ext=array_filter($ext,fn($v)=>$v!==null);
    $jx=$ext?json_encode($ext,JSON_UNESCAPED_UNICODE):null;
    $sx=$mysqli->prepare("UPDATE pendenzen SET extra_json=? WHERE id=?");
    $sx->bind_param("si",$jx,$pendenz_id); $sx->execute(); $sx->close();

    // ACL (bei custom)
    if($sichtbarkeit==='custom'){
      $mysqli->query("DELETE FROM pendenz_acl WHERE pendenz_id=".$pendenz_id);
      $ins=$mysqli->prepare("INSERT INTO pendenz_acl (pendenz_id,benutzer_id,can_view,can_edit) VALUES (?,?,?,?)");
      if($sichtbarkeit_ui==='team' && $sicht_team_id){
        $q=$mysqli->prepare("SELECT bt.benutzer_id FROM benutzer_teams bt WHERE bt.team_id=?");
        $q->bind_param("i",$sicht_team_id); $q->execute(); $rs=$q->get_result();
        while($row=$rs->fetch_assoc()){ $uidX=(int)$row['benutzer_id']; $cv=1; $ce=$assignee_can_edit?1:0; $ins->bind_param("iiii",$pendenz_id,$uidX,$cv,$ce); $ins->execute(); }
        $q->close();
      } elseif(!empty($sicht_user_ids)){
        foreach($sicht_user_ids as $uidX){ $cv=1; $ce=$assignee_can_edit?1:0; $ins->bind_param("iiii",$pendenz_id,$uidX,$cv,$ce); $ins->execute(); }
      }
      $ins->close();
    }

    // Uploads: Bilder
    $coverAdded = false;
    if(!empty($_FILES['bilder']) && is_array($_FILES['bilder']['name'])){
      $n=count($_FILES['bilder']['name']);
      for($i=0;$i<$n;$i++){
        $_FILES['__img']=[
          'name'=>$_FILES['bilder']['name'][$i]??null,
          'type'=>$_FILES['bilder']['type'][$i]??null,
          'tmp_name'=>$_FILES['bilder']['tmp_name'][$i]??null,
          'error'=>$_FILES['bilder']['error'][$i]??UPLOAD_ERR_NO_FILE,
          'size'=>$_FILES['bilder']['size'][$i]??0
        ];
        try{
          $uidUp=(int)($_SESSION['user_id']??0);
          $u=handle_upload('__img','uploads/pendenzen_tmp',$uidUp,($titel?:'Pendenz'),
            ['image/jpeg','image/png','image/gif','image/webp'], 20_000_000, 6000, 4000);
          if(!$u) continue;
          $u=is_array($u)?$u:(['pfad'=>ltrim($u,'/'),'dateiname'=>basename($u)]);
          list($rel,$abs) = pendenz_fs_base($mysqli, $projekt_id, $ordner_id, $pendenz_id, $titel);
          $destDir = $abs.'/bilder'; if (!is_dir($destDir)) @mkdir($destDir,0775,true);

          $srcRel = ltrim($u['pfad'],'/'); $srcAbs = abs_path_from($srcRel); if(!$srcAbs || !is_file($srcAbs)) continue;
          $ext = pathinfo($u['dateiname'], PATHINFO_EXTENSION);
          $baseName = pathinfo($u['dateiname'], PATHINFO_FILENAME);
          $safeBase = slugify($baseName);
          $destName = $safeBase.'.'.$ext; $k2=1; while(file_exists($destDir.'/'.$destName)){ $destName=$safeBase.'-'.$k2.'.'.$ext; $k2++; }
          @rename($srcAbs, $destDir.'/'.$destName);
          $finalAbs = $destDir.'/'.$destName; $finalRel = $rel.'/bilder/'.$destName;

          $mime = @mime_content_type($finalAbs) ?: 'application/octet-stream';
          $size = filesize($finalAbs) ?: 0;
          if (function_exists('image_to_max_1mb')) { list($finalAbs, $mime, $size) = image_to_max_1mb($finalAbs,$mime); }

          $st2=$mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id,typ,pfad,mimetype,groesse,titel,is_cover,hochgeladen_von) VALUES (?,?,?,?,?,?,?,?)");
          $isCover = 0; $origTitle = $u['dateiname'] ?: basename($finalRel); $type='image'; 
          
          $validUid = null;
          $u_sess = $_SESSION['user_id'] ?? null;
          if ($u_sess) {
              $u_chk = $mysqli->query("SELECT id FROM benutzer WHERE id=".(int)$u_sess);
              if ($u_chk && $u_chk->num_rows > 0) $validUid = (int)$u_sess;
          }

          $st2->bind_param("isssisis",$pendenz_id,$type,$finalRel,$mime,$size,$origTitle,$isCover,$validUid);
          $st2->execute(); $fid = $st2->insert_id; $st2->close();

          if (!$coverAdded) {
            $has=$mysqli->query("SELECT 1 FROM pendenz_dateien WHERE pendenz_id={$pendenz_id} AND is_cover=1 LIMIT 1");
            if (!$has || !$has->num_rows) {
              $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pendenz_id}");
              $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id=".$fid);
              $coverAdded = true;
            }
          }
          log_action($mysqli,'pendenz_datei',$fid,'upload',['file'=>$origTitle]);
        }catch(Throwable $ex){ $flash.=" ⚠️ ".$ex->getMessage(); }
      }
    }
    // Uploads: Anhänge
    if(!empty($_FILES['anhaenge']) && is_array($_FILES['anhaenge']['name'])){
      $n=count($_FILES['anhaenge']['name']);
      for($i=0;$i<$n;$i++){
        $_FILES['__file']=[
          'name'=>$_FILES['anhaenge']['name'][$i]??null,
          'type'=>$_FILES['anhaenge']['type'][$i]??null,
          'tmp_name'=>$_FILES['anhaenge']['tmp_name'][$i]??null,
          'error'=>$_FILES['anhaenge']['error'][$i]??UPLOAD_ERR_NO_FILE,
          'size'=>$_FILES['anhaenge']['size'][$i]??0
        ];
        try{
          $uidUp=(int)($_SESSION['user_id']??0);
          $u=handle_upload('__file','uploads/pendenzen_tmp',$uidUp,($titel?:'Pendenz'),[
            'application/pdf','application/zip','application/x-zip-compressed',
            'audio/mpeg','audio/mp3','audio/wav',
            'video/mp4','video/quicktime'
          ], 80_000_000, 0, 0);
          if(!$u) continue;
          $u=is_array($u)?$u:(['pfad'=>ltrim($u,'/'),'dateiname'=>basename($u)]);
          list($rel,$abs) = pendenz_fs_base($mysqli, $projekt_id, $ordner_id, $pendenz_id, $titel);
          $destDir = $abs.'/anhaenge'; if (!is_dir($destDir)) @mkdir($destDir,0775,true);

          $srcRel = ltrim($u['pfad'],'/'); $srcAbs = abs_path_from($srcRel); if(!$srcAbs || !is_file($srcAbs)) continue;
          $ext = pathinfo($u['dateiname'], PATHINFO_EXTENSION);
          $baseName = pathinfo($u['dateiname'], PATHINFO_FILENAME);
          $safeBase = slugify($baseName);
          $destName = $safeBase.'.'.$ext; $k3=1; while(file_exists($destDir.'/'.$destName)){ $destName=$safeBase.'-'.$k3.'.'.$ext; $k3++; }
          @rename($srcAbs, $destDir.'/'.$destName);
          $finalAbs = $destDir.'/'.$destName; $finalRel = $rel.'/anhaenge/'.$destName;

          $mime = @mime_content_type($finalAbs) ?: 'application/octet-stream';
          $size = filesize($finalAbs) ?: 0;
          $type = (str_starts_with($mime,'audio/'))?'audio':((str_starts_with($mime,'video/'))?'video':'file');

          $st2=$mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id,typ,pfad,mimetype,groesse,titel,is_cover,hochgeladen_von) VALUES (?,?,?,?,?,?,?,?)");
          $isCover = 0; $origTitle = $u['dateiname'] ?: basename($finalRel); 
          
          $validUidA = null;
          $u_sessA = $_SESSION['user_id'] ?? null;
          if ($u_sessA) {
              $u_chkA = $mysqli->query("SELECT id FROM benutzer WHERE id=".(int)$u_sessA);
              if ($u_chkA && $u_chkA->num_rows > 0) $validUidA = (int)$u_sessA;
          }

          $st2->bind_param("isssisis",$pendenz_id,$type,$finalRel,$mime,$size,$origTitle,$isCover,$validUidA);
          $st2->execute(); $st2->close();

          log_action($mysqli,'pendenz_datei',$pendenz_id,'upload',['file'=>$origTitle]);
        }catch(Throwable $ex){ $flash.=" ⚠️ ".$ex->getMessage(); }
      }
    }

    // Remember last meta
    $_SESSION['pendenz_last'] = [
      'projekt_id'=>$projekt_id,
      'wohnung_id'=>$wohnung_id,
      'fs_rel_path'=>$fs_rel_path,
      'kategorie_id'=>$cat_id,
      'unterkategorie_id'=>$sub_id,
      'assignee_type'=>$assignee_type,
      'assignee_user_id'=>$assignee_user_id,
      'assignee_team_id'=>$assignee_team_id,
      'assignee_company_id'=>$assignee_company_id,
      'sichtbarkeit_ui'=>$sichtbarkeit_ui,
      'sicht_team_id'=>$sicht_team_id,
      'sicht_user_ids'=>$sicht_user_ids
    ];

    $flash=$flash ?: ($id>0 ? "✅ Pendenz aktualisiert." : "✅ Pendenz gespeichert.");

    if ($isAjaxInline) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'ok'   => true,
        'id'   => (int)$pendenz_id,
        'msg'  => ($id>0 ? 'Pendenz aktualisiert.' : 'Pendenz gespeichert.'),
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } catch (Throwable $e) { 
    $flash="❌ ".$e->getMessage(); 
    if (!empty($isAjaxInline)) {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok'=>false,'error'=>strip_tags($flash)], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
}

/** -------- Delete (Datei-Serveraktionen) -------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && in_array($_POST['action'], ['delete_file','set_cover'], true)) {
  try{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $pendenz_id = (int)($_POST['pendenz_id'] ?? 0);
    $file_id = (int)($_POST['file_id'] ?? 0);
    if ($pendenz_id<=0 || $file_id<=0) throw new Exception('Parameter fehlen.');

    $r = $mysqli->query("SELECT * FROM pendenzen WHERE id={$pendenz_id}");
    $p = $r ? $r->fetch_assoc() : null;
    if (!$p || !can_edit_pendenz($mysqli, $p, $uid)) throw new Exception('Keine Berechtigung.');

    $rowR = $mysqli->query("SELECT * FROM pendenz_dateien WHERE id={$file_id} AND pendenz_id={$pendenz_id}");
    $row = $rowR ? $rowR->fetch_assoc() : null;
    if (!$row) throw new Exception('Datei nicht gefunden.');

    if ($_POST['action']==='delete_file') {
      $abs = __DIR__.'/../'.ltrim($row['pfad'],'/');
      if (is_file($abs)) @unlink($abs);
      $mysqli->query("DELETE FROM pendenz_dateien WHERE pendenz_id={$pendenz_id} AND id={$file_id}");
      log_action($mysqli,'pendenz_datei',$file_id,'delete',['file'=>$row['titel'] ?: $row['pfad']]);
      $flash = "🗑️ Datei gelöscht.";
    } else {
      if ($row['typ']!=='image') throw new Exception('Cover nur für Bilder.');
      $mysqli->query("UPDATE pendenz_dateien SET is_cover=0 WHERE pendenz_id={$pendenz_id}");
      $mysqli->query("UPDATE pendenz_dateien SET is_cover=1 WHERE id={$file_id}");
      log_action($mysqli,'pendenz_datei',$file_id,'set_cover');
      $flash = "🖼 Cover aktualisiert.";
    }
  } catch (Throwable $e) { $flash = "❌ ".$e->getMessage(); }
  header("Location: ".$PREFIX."pages/pendenzen.php?edit=".$pendenz_id);
  exit;
}

/** -------- Vorlagen -------- */
$hasTplTbl = table_exists($mysqli,'pendenz_vorlagen');
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='save_template') {
  $name = trim($_POST['tpl_name'] ?? '');
  if ($name=== '') { $flash='❌ Vorlagen-Name fehlt.'; }
  else {
    $data = [
      'projekt_id'=> (int)($_POST['projekt_id']??0),
      'fs_rel_path'=> trim((string)($_POST['fs_rel_path']??'')),
      'kategorie_id'=> (int)($_POST['kategorie_id']??0),
      'unterkategorie_id'=> (int)($_POST['unterkategorie_id']??0),
      'assignee_type'=> $_POST['assignee_type']??'user',
      'assignee_user_id'=> (int)($_POST['assignee_user_id']??0),
      'assignee_team_id'=> (int)($_POST['assignee_team_id']??0),
      'assignee_company_id'=> (int)($_POST['assignee_company_id']??0),
      'sichtbarkeit_ui'=> $_POST['sichtbarkeit_ui']??'projekt_all',
      'sicht_team_id'=> (int)($_POST['sicht_team_id']??0),
      'sicht_user_ids'=> array_filter(array_map('intval',$_POST['sicht_user_ids']??[]))
    ];
    if ($hasTplTbl) {
      $st=$mysqli->prepare("INSERT INTO pendenz_vorlagen (name,data_json,erstellt_von) VALUES (?,?,?)");
      $jx=json_encode($data,JSON_UNESCAPED_UNICODE); $uid=(int)($_SESSION['user_id']??0);
      $st->bind_param("ssi",$name,$jx,$uid); $st->execute(); $st->close();
    } else {
      $_SESSION['pendenz_templates'] = $_SESSION['pendenz_templates'] ?? [];
      $_SESSION['pendenz_templates'][] = ['id'=>time(),'name'=>$name,'data'=>$data];
    }
    $flash='✅ Vorlage gespeichert.';
  }
}
if (isset($_GET['action']) && $_GET['action']==='template_get') {
  header('Content-Type: application/json; charset=utf-8');
  $id=(int)($_GET['id']??0);
  $out=null;
  if ($hasTplTbl) {
    $st=$mysqli->prepare("SELECT data_json FROM pendenz_vorlagen WHERE id=?");
    $st->bind_param("i",$id); $st->execute();
    $res=$st->get_result(); $jx=$res?($res->fetch_column()):null; $st->close();
    if ($jx) $out=json_decode($jx,true);
  } else {
    foreach (($_SESSION['pendenz_templates'] ?? []) as $t) if ((int)$t['id']===$id) $out=$t['data'];
  }
  echo json_encode(['ok'=>(bool)$out,'data'=>$out]); exit;
}

/** -------- Filter & Liste vorbereiten -------- */
$GET = filter_input_array(INPUT_GET, [
  'cols' => ['filter'=>FILTER_UNSAFE_RAW,'flags'=>FILTER_REQUIRE_ARRAY],
  'q' => FILTER_UNSAFE_RAW,
  'sort' => FILTER_UNSAFE_RAW,
  'dir' => FILTER_UNSAFE_RAW,
  'f' => ['filter'=>FILTER_UNSAFE_RAW,'flags'=>FILTER_REQUIRE_ARRAY],
  'list_id' => FILTER_SANITIZE_NUMBER_INT,
  'projekt_id' => FILTER_SANITIZE_NUMBER_INT,
]) ?? [];

$q = trim((string)($_GET['q'] ?? ''));
$f_params = is_array($_GET['f'] ?? null) ? $_GET['f'] : [];
$dir  = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$selectedPid = (int)($_GET['projekt_id'] ?? 0);
$listenId    = (int)($_GET['list_id'] ?? 0);
if ($listenId <= 0 && !empty($_GET['listen_id'])) $listenId = (int)$_GET['listen_id']; // Fallback

// NEU: Falls keine Liste gewählt wurde, prüfen ob es eine Standard-Ansicht gibt
if ($listenId <= 0 && !isset($_GET['cols'])) {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    // Fehlerunterdrückung (@), falls die Datenbank noch nicht synchronisiert ist (is_default Spalte)
    $resDef = @$mysqli->query("SELECT id FROM listen WHERE table_name='pendenzen' AND owner_id=$uid AND is_default=1 LIMIT 1");
    if ($resDef && $rowDef = $resDef->fetch_assoc()) {
        $listenId = (int)$rowDef['id'];
    }
}

// Spalten & Meta-Checks
$hasPk = table_exists($mysqli,'pendenz_kategorien');
$hasPs = table_exists($mysqli,'pendenz_subkategorien');
$hasSortIndex = column_exists($mysqli, 'pendenzen', 'sort_index');

// Sort Mapping
$sortMap = [
  'titel'=>'p.titel','projekt_name'=>'pr.name','status'=>'p.status','wichtigkeit'=>'p.wichtigkeit',
  'startdatum'=>'p.startdatum','enddatum'=>'p.enddatum','erstellt_am'=>'p.erstellt_am',
  'bilder'=>'bilder_count','anhaenge'=>'anhaenge_count','erstes_bild'=>'erstes_bild','fs_rel_path'=>'p.fs_rel_path'
];
if ($hasPk) $sortMap['kategorie_name']='pk.name';
if ($hasPs) $sortMap['unterkategorie_name']='ps.name';

$sortKey = (string)($GET['sort'] ?? ($hasSortIndex ? 'custom' : 'erstellt_am'));
$orderSql = ($sortMap[$sortKey] ?? 'p.erstellt_am').' '.strtoupper($dir);

// Permissions & Where
// (Redundanter SQL-Block entfernt – PendenzenService wird unten genutzt)


// Labels & Columns (Expanded for SaaS compat)
$labels = [
  'id'=>'ID','erstes_bild'=>'Bild','cover'=>'Cover','titel'=>'Titel','projekt_name'=>'Projekt','status'=>'Status',
  'wichtigkeit'=>'Prio', 'startdatum'=>'Start', 'enddatum'=>'Fällig', 'fs_rel_path'=>'Ordner',
  'wohnung_name'=>'Einheit / Mietsache', 'kategorie_name'=>'Kategorie', 'unterkategorie_name'=>'Sub',
  'erstellt_am'=>'Erstellt', 'geaendert_am'=>'Update', 'bilder'=>'📸', 'anhaenge'=>'📎', 'balance'=>'Saldo', 'dauer'=>'Dauer',
  'kurzbeschreibung'=>'Kurzbeschreibung', 'zustaendig_id' => 'Zuständig',
  'art_name' => 'Vorgangsart', 'raum_name' => 'Raum'
];
$defaultCols = ['erstes_bild', 'art_name', 'titel', 'kurzbeschreibung', 'status', 'enddatum', 'kategorie_name', 'bilder', 'anhaenge'];

// 1. Verfügbare Listen (Layer) laden für den Selector
$availableLayers = [];
$resL = $mysqli->query("SELECT id, name FROM listen WHERE table_name='pendenzen' ORDER BY name ASC");
if($resL) while($l = $resL->fetch_assoc()) $availableLayers[] = $l;

// 2. Initial-Handling: Was ist aktiv?
$selectedCols = (isset($_GET['cols']) && is_array($_GET['cols'])) ? $_GET['cols'] : ($_SESSION['pendenzen_cols'] ?? $defaultCols);
if (!is_array($selectedCols) || empty($selectedCols)) $selectedCols = $defaultCols;
$selectedCols = array_values(array_filter($selectedCols, 'is_string'));

// 3. Wenn Profil (Layer) gewählt: Spalten & Filter aus DB laden
if ($listenId > 0) {
    if ($stP = $mysqli->prepare("SELECT name, filters_json FROM listen WHERE id=?")) {
        $stP->bind_param("i", $listenId);
        $stP->execute();
        $resP = $stP->get_result()->fetch_assoc();
        $stP->close();

        if ($resP) {
            $layerFilters = json_decode((string)$resP['filters_json'], true);
            $layerQuickChoices = $layerFilters['quick_choices'] ?? '';
            
            // Spalten für diesen Layer laden
            $stC = $mysqli->prepare("SELECT col_name FROM listen_spalten WHERE listen_id=? ORDER BY sort_order ASC, id ASC");
            if ($stC) {
                $stC->bind_param("i", $listenId);
                $stC->execute();
                $resC = $stC->get_result();
                $layerCols = [];
                while($rc=$resC->fetch_assoc()) $layerCols[] = $rc['col_name'];
                $stC->close();
                if (!empty($layerCols)) $selectedCols = $layerCols;
            }

            // Filter anwenden (Layer-Werte haben Priorität wenn URL-Werte leer sind)
            $fJson = $layerFilters;
            if ($fJson) {
                if (!$selectedPid && !empty($fJson['projekt_id'])) $selectedPid = (int)$fJson['projekt_id'];
                if (empty($_GET['status']) && !empty($fJson['status'])) $_GET['status'] = $fJson['status'];
                if (empty($_GET['q'])      && !empty($fJson['q']))      $_GET['q']      = (string)$fJson['q'];
                if (empty($_GET['wohnung_id']) && !empty($fJson['wohnung_id'])) $_GET['wohnung_id'] = (int)$fJson['wohnung_id'];
                if (empty($_GET['kategorie_id']) && !empty($fJson['kategorie_id'])) $_GET['kategorie_id'] = (int)$fJson['kategorie_id'];
            }
        }
    }
}

$selectedStatus = $_GET['status'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
$wohnungId = (int)($_GET['wohnung_id'] ?? 0);
$objektId  = (int)($_GET['objekt_id'] ?? 0);
$kategorieId = (int)($_GET['kategorie_id'] ?? 0);
$_SESSION['pendenzen_cols'] = $selectedCols;

// 4. Finalisierung: Labels & Spalten-Mapping
foreach($selectedCols as $sc) if(!isset($labels[$sc])) $labels[$sc] = ucwords(str_replace('_',' ',$sc));
$cols = $selectedCols;

// Liste der Projekte für Dropdowns (Single Source)
$projects = [];
$resProj = $mysqli->query("SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name");
if($resProj) while($p = $resProj->fetch_assoc()) $projects[] = $p;


// Spaltenbreiten
$colWidths = $_SESSION['pendenzen_col_widths'] ?? [];

// Helper für Breiten
if(!function_exists('css_width')){ function css_width(?string $w){ return $w ? ' style="width:'.$w.';"' : ''; } }

// === Standard Helper ===
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('qs')) {
  function qs(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k=>$v) { if ($v === null || $v === '') unset($q[$k]); }
    return '?' . http_build_query($q);
  }
}
if (!function_exists('th_sort_link')) {
  function th_sort_link(string $orderKey, string $label): string {
    $curOrder = (string)($_GET['order'] ?? ($_GET['sort'] ?? 'eigene'));
    $curDir   = strtolower((string)($_GET['dir'] ?? 'desc'));
    $isActive = ($curOrder === $orderKey);
    $nextDir  = $isActive ? ($curDir === 'asc' ? 'desc' : 'asc') : 'asc';
    $arrow    = $isActive ? ($curDir === 'asc' ? ' ▲' : ' ▼') : '';
    $href     = qs(['order'=>$orderKey, 'dir'=>$nextDir, 'offset'=>0]);
    return '<a href="'.h($href).'">'.h($label).$arrow.'</a>';
  }
}

// === Modularisierung: Autoloader + Pendenzen-Service ===
// Ausfallsicher: kein Fatal Error wenn app/ Ordner auf Server fehlt
$_autoload_path = __DIR__ . '/../app/core/autoload.php';
if (file_exists($_autoload_path)) {
    require_once $_autoload_path;
}

// DB-Handle bestimmen
if (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} elseif (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_errno) { die('DB-Verbindung fehlgeschlagen: '.$db->connect_error); }
} else {
    die('Kein DB-Handle gefunden. Bitte $mysqli in config.php bereitstellen.');
}

// Service-Klasse: der Autoloader (autoload.php via spl_autoload_register) laedt sie LAZY.
// Fallback: wenn app/core/autoload.php fehlt, eigenen Autoloader registrieren.
// WICHTIG: Verzeichnisse lowercase (modules/pendenzen/), Dateiname original (Service.php)
// -> Linux-Server sind case-sensitive!
if (!file_exists($_autoload_path)) {
    spl_autoload_register(function(string $class): void {
        if (strpos($class, 'App\\') !== 0) return;
        $parts    = explode('\\', ltrim(str_replace('App\\', '', $class), '\\'));
        $fileName = array_pop($parts);
        $dirPart  = implode('/', array_map('strtolower', $parts));
        $path     = dirname(__DIR__) . '/app/' . ($dirPart ? $dirPart . '/' : '') . $fileName . '.php';
        if (is_file($path)) require $path;
    });
}

use App\Modules\Pendenzen\Service as PendenzenService;

// Module instanziieren (null wenn Klasse nicht geladen werden konnte)
try {
    $pendenzen = new PendenzenService($db);
} catch (Throwable $e) {
    error_log('[pendenzen.php] PendenzenService Fehler: ' . $e->getMessage());
    $pendenzen = null;
    $flash = ($flash ?: '') . ' &#9888; Service konnte nicht geladen werden: ' . htmlspecialchars($e->getMessage());
}

$filters = [
  'projekt_id' => $selectedPid ?: null,
  'listen_id'  => $listenId ?: null,
  'status'     => $selectedStatus ?: null,
  'objekt_id'  => $objektId ?: null,
  'wohnung_id' => $wohnungId ?: null,
  'kategorie_id' => $kategorieId ?: null,
  'vorgangsart_id' => (int)($_GET['vorgangsart_id'] ?? 0) ?: null,
  'q'          => $q,
  'order'      => (string)($_GET['order'] ?? 'eigene'),
  'dir'        => (string)($_GET['dir'] ?? 'desc'),
  'limit'      => (int)($_GET['limit'] ?? 100),
  'offset'     => (int)($_GET['offset'] ?? 0),
  'owner_isolation_id' => (int)($_SESSION['user_id'] ?? 0),
  'is_superadmin' => is_superadmin(),
  'is_admin' => is_admin()
];

// Aktuelle Pendenzen für die Liste
try {
    $rsPendenzen = $pendenzen ? $pendenzen->searchResult($filters) : null;
} catch (Throwable $e) {
    error_log('[pendenzen.php] searchResult() fehlgeschlagen: ' . $e->getMessage());
    $rsPendenzen = null;
    $flash = ($flash ?: '') . ' ❌ Datenbankfehler beim Laden der Pendenzen: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ' (Details im Error-Log)';
}

// --- SELF-HEALING DATA INTEGRITY (Automated Bulk Fix on Refresh) ---
if ($rsPendenzen && $rsPendenzen->num_rows > 0) {
    while($row = $rsPendenzen->fetch_assoc()){
        $sid = (int)$row['id'];
        $st  = ($row['startdatum'] && $row['startdatum'] !== '0000-00-00') ? strtotime($row['startdatum']) : null;
        $ed  = ($row['enddatum'] && $row['enddatum'] !== '0000-00-00') ? strtotime($row['enddatum']) : null;
        $durRaw = trim((string)$row['dauer']);
        $durNum = (float)filter_var($durRaw, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        $newSt = (string)($row['startdatum'] ?? ''); $newEd = (string)($row['enddatum'] ?? ''); $newDur = $durRaw;
        $needsSync = false;

        if ($st && $durNum > 0) {
            $expEd = date('Y-m-d', phpAddWorkDays($st, $durNum));
            if ($newEd !== $expEd) { $newEd = $expEd; $needsSync = true; }
        } elseif ($st && $ed) {
            $expDNum = (float)phpGetWorkDaysDiff($st, $ed); $expDStr = "$expDNum Tage";
            if ($newDur !== $expDStr) { $newDur = $expDStr; $needsSync = true; }
        } elseif ($ed && $durNum > 0 && !$st) {
            $expSt = date('Y-m-d', phpSubWorkDays($ed, $durNum));
            if ($newSt !== $expSt) { $newSt = $expSt; $needsSync = true; }
        }

        if ($durNum > 0 && !str_contains($newDur, ' Tage')) {
            $newDur = $durNum . " Tage"; $needsSync = true;
        }

        if ($needsSync) {
            $mysqli->query("UPDATE pendenzen SET startdatum='$newSt', enddatum='$newEd', dauer='$newDur', geaendert_am=NOW() WHERE id=$sid");
        }
    }
    $rsPendenzen->data_seek(0);
}





/** -------- Ausgabe -------- */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<script>
// --- GLOBAL DELETE ---
window.trashPendenz = async function(id) {
    if(!id) return;
    if(!confirm('Diesen Eintrag wirklich permanent löschen?')) return;
    const row = document.querySelector(`tr[data-id="${id}"]`);
    if(row) { row.style.opacity='0.3'; row.style.filter='grayscale(1)'; }
    try {
        const res = await fetch(`pendenzen.php?delete=${id}&ajax=1`);
        const js = await res.json();
        if(js.ok) {
            if(row) {
                row.style.transition='0.4s ease';
                row.style.transform='scale(0.8) translateX(200px)';
                row.style.opacity='0';
                setTimeout(()=>row.remove(), 400);
            }
        } else {
            alert('Löschen fehlgeschlagen: ' + (js.error||'Serverfehler'));
            if(row) { row.style.opacity='1'; row.style.filter=''; }
        }
    } catch(e) {
        console.error('AJAX Delete Failed:', e);
        if(confirm('Verbindungsproblem. Seite neu laden?')) {
            window.location.href = `pendenzen.php?delete=${id}`;
        }
    }
};
