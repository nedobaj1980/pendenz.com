<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!in_array(($_SESSION['rolle'] ?? 'gast'), ['superadmin'], true)) {
  die('Zugriff verweigert: Nur Superadmin.');
}
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  die('DB-Verbindung fehlt (config.php).');
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function ok($m){ return '<div class="alert ok">'.$m.'</div>'; }
function bad($m){ return '<div class="alert err">'.$m.'</div>'; }
function ident_ok($s){ return (bool)preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $s); }

/** Ensure settings table exists */
$mysqli->query("
CREATE TABLE IF NOT EXISTS smarttable_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  table_name VARCHAR(128) NOT NULL UNIQUE,
  pk VARCHAR(64) DEFAULT 'id',
  columns_json JSON NOT NULL,
  searchable_json JSON NULL,
  editdeny_json JSON NULL,
  soft_delete TINYINT(1) DEFAULT 1,
  enabled TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/** Helpers */
function db_tables(mysqli $db): array {
  $out=[]; $res=$db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'");
  if ($res) { while ($r=$res->fetch_array(MYSQLI_NUM)) $out[]=$r[0]; }
  return $out;
}
function table_columns(mysqli $db, string $table): array {
  $stmt=$db->prepare("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = ?
                      ORDER BY ORDINAL_POSITION");
  $stmt->bind_param('s',$table); $stmt->execute();
  $res=$stmt->get_result(); $cols=[];
  while($row=$res->fetch_assoc()) $cols[]=$row;
  $stmt->close(); return $cols;
}
function table_pk(mysqli $db, string $table): ?string {
  $stmt=$db->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                      WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name='PRIMARY' LIMIT 1");
  $stmt->bind_param('s',$table); $stmt->execute();
  $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
  return $r['COLUMN_NAME'] ?? null;
}

/** Server-Aktionen */
$flash=''; $err='';
$act = $_POST['action'] ?? '';

if ($act === 'create_table') {
  $table = trim($_POST['ct_table'] ?? '');
  $pk    = trim($_POST['ct_pk'] ?? 'id');
  $cols_json_str= trim($_POST['ct_cols_json'] ?? '[]'); // per JS befüllt

  if (!ident_ok($table)) $err .= bad('Ungültiger Tabellenname.');
  if (!ident_ok($pk))    $err .= bad('Ungültiger Primärschlüssel.');

  $cols = json_decode($cols_json_str, true);
  if (!is_array($cols)) $err .= bad('Interner Fehler: Spaltenliste ungültig.');

  $allowed_types = ['INT','BIGINT','TINYINT','DECIMAL','VARCHAR','TEXT','DATE','DATETIME','JSON'];
  $defs=[];
  if (!$err) {
    $defs[]="`$pk` INT NOT NULL AUTO_INCREMENT";
    foreach ($cols as $i=>$c) {
      $name=$c['name']??''; $type=strtoupper($c['type']??'');
      $len=$c['len']??null; $nullable=isset($c['null'])?(bool)$c['null']:true;
      $defSet = array_key_exists('default',$c); $defVal=$c['default']??null;

      if (!ident_ok($name)) { $err .= bad("Spalte[$i]: Ungültiger Name."); continue; }
      if (!in_array($type,$allowed_types,true)) { $err .= bad("Spalte[$i]: Typ $type nicht erlaubt."); continue; }

      $sqlType=$type;
      if ($type==='VARCHAR'){ $l=(int)($len??255); if($l<1||$l>65535)$l=255; $sqlType.="($l)"; }
      if ($type==='DECIMAL'){
        if (is_string($len) && preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*$/',$len,$m)){ $p=(int)$m[1]; $s=(int)$m[2]; }
        elseif(is_array($len)&&count($len)===2){ $p=(int)$len[0]; $s=(int)$len[1]; }
        else { $p=10; $s=2; }
        $sqlType.="($p,$s)";
      }
      if ($type==='TINYINT'){ $sqlType.="(1)"; }

      $line="`$name` $sqlType".($nullable?" NULL":" NOT NULL");
      if ($defSet) {
        if ($defVal===null) { $line.=" DEFAULT NULL"; }
        elseif (is_numeric($defVal)) { $line.=" DEFAULT $defVal"; }
        elseif (in_array($type,['DATE','DATETIME'],true) && strtoupper((string)$defVal)==='CURRENT_TIMESTAMP') {
          $line.=" DEFAULT CURRENT_TIMESTAMP";
        } else {
          $line.=" DEFAULT '".$mysqli->real_escape_string((string)$defVal)."'";
        }
      }
      $defs[]=$line;
    }
    $defs[]="`created_at` DATETIME NULL";
    $defs[]="`updated_at` DATETIME NULL";
    $defs[]="`deleted_at` DATETIME NULL";
    $defs[]="PRIMARY KEY (`$pk`)";

    if (!$err) {
      $sql="CREATE TABLE IF NOT EXISTS `{$table}` (\n  ".implode(",\n  ",$defs)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
      if (!$mysqli->query($sql)) {
        $err .= bad('CREATE TABLE fehlgeschlagen: '.$mysqli->error);
      } else {
        // Settings auto
        $c = table_columns($mysqli,$table);
        $names = array_map(fn($r)=>$r['COLUMN_NAME'],$c);
        $soft  = in_array('deleted_at',$names,true)?1:0;
        $search=[];
        foreach($c as $r){ $t=strtolower($r['DATA_TYPE']); if(in_array($t,['varchar','text','mediumtext','longtext','char'],true)) $search[]=$r['COLUMN_NAME']; }
        $search = array_slice($search,0,5);

        $stmt=$mysqli->prepare("INSERT INTO smarttable_settings (table_name, pk, columns_json, searchable_json, editdeny_json, soft_delete, enabled)
                                VALUES (?,?,?,?,?,?,1)
                                ON DUPLICATE KEY UPDATE pk=VALUES(pk), columns_json=VALUES(columns_json),
                                  searchable_json=VALUES(searchable_json), editdeny_json=VALUES(editdeny_json),
                                  soft_delete=VALUES(soft_delete), enabled=VALUES(enabled)");
        $stmt->bind_param('sssssi',$table,$pk,json_encode($names,JSON_UNESCAPED_UNICODE),
                          json_encode($search,JSON_UNESCAPED_UNICODE),
                          json_encode([$pk,'created_at','updated_at','deleted_at'],JSON_UNESCAPED_UNICODE),
                          $soft);
        if ($stmt->execute()) $flash .= ok("Tabelle <b>".h($table)."</b> erstellt & konfiguriert.");
        else $err .= bad('Settings-Insert fehlgeschlagen: '.$stmt->error);
        $stmt->close();
      }
    }
  }
}

if ($act === 'bind_table') {
  $tb = trim($_POST['bt_table'] ?? '');
  if (!ident_ok($tb)) { $err .= bad('Ungültiger Tabellenname.'); }
  else {
    $cols = table_columns($mysqli,$tb);
    if (!$cols) { $err .= bad('Tabelle nicht gefunden.'); }
    else {
      $pk = table_pk($mysqli,$tb) ?: (in_array('id',array_column($cols,'COLUMN_NAME'),true)?'id':$cols[0]['COLUMN_NAME']);
      $names = array_map(fn($r)=>$r['COLUMN_NAME'],$cols);
      $soft  = in_array('deleted_at',$names,true)?1:0;
      $search=[];
      foreach($cols as $r){ $t=strtolower($r['DATA_TYPE']); if(in_array($t,['varchar','text','mediumtext','longtext','char'],true)) $search[]=$r['COLUMN_NAME']; }
      $search = array_slice($search,0,5);

      $stmt=$mysqli->prepare("INSERT INTO smarttable_settings (table_name, pk, columns_json, searchable_json, editdeny_json, soft_delete, enabled)
                              VALUES (?,?,?,?,?,?,1)
                              ON DUPLICATE KEY UPDATE pk=VALUES(pk), columns_json=VALUES(columns_json),
                                searchable_json=VALUES(searchable_json), editdeny_json=VALUES(editdeny_json),
                                soft_delete=VALUES(soft_delete), enabled=VALUES(enabled)");
      $stmt->bind_param('sssssi',$tb,$pk,json_encode($names,JSON_UNESCAPED_UNICODE),
                        json_encode($search,JSON_UNESCAPED_UNICODE),
                        json_encode([$pk,'created_at','updated_at','deleted_at'],JSON_UNESCAPED_UNICODE),$soft);
      if ($stmt->execute()) $flash .= ok("Tabelle <b>".h($tb)."</b> verbunden.");
      else $err .= bad('Settings-Insert fehlgeschlagen: '.$stmt->error);
      $stmt->close();
    }
  }
}

if ($act === 'save_settings') {
  $sid  = (int)($_POST['ss_id'] ?? 0);
  $name = trim($_POST['ss_table'] ?? '');
  $pk   = trim($_POST['ss_pk'] ?? 'id');
  $cols_json   = $_POST['ss_cols_json'] ?? '[]';
  $search_json = $_POST['ss_search_json'] ?? '[]';
  $deny_json   = $_POST['ss_deny_json'] ?? '[]';
  $soft = isset($_POST['ss_soft']) ? 1 : 0;
  $ena  = isset($_POST['ss_enabled']) ? 1 : 0;

  if (!ident_ok($name)) $err .= bad('Ungültiger Tabellenname.');
  if (!ident_ok($pk))   $err .= bad('Ungültiger PK-Name.');

  $cj=json_decode($cols_json,true); $sj=json_decode($search_json,true); $dj=json_decode($deny_json,true);
  if (!is_array($cj)) $err .= bad('columns_json ist kein Array.');
  if ($sj!==null && !is_array($sj)) $err .= bad('searchable_json ist kein Array.');
  if ($dj!==null && !is_array($dj)) $err .= bad('editdeny_json ist kein Array.');

  if (!$err) {
    if ($sid>0){
      $stmt=$mysqli->prepare("UPDATE smarttable_settings SET table_name=?, pk=?, columns_json=?, searchable_json=?, editdeny_json=?, soft_delete=?, enabled=? WHERE id=?");
      $cj_s=json_encode($cj,JSON_UNESCAPED_UNICODE);
      $sj_s=$sj===null?null:json_encode($sj,JSON_UNESCAPED_UNICODE);
      $dj_s=$dj===null?null:json_encode($dj,JSON_UNESCAPED_UNICODE);
      $stmt->bind_param('ssssssii',$name,$pk,$cj_s,$sj_s,$dj_s,$soft,$ena,$sid);
      if ($stmt->execute()) $flash .= ok('Settings gespeichert.');
      else $err .= bad('Update fehlgeschlagen: '.$stmt->error);
      $stmt->close();
    } else {
      $stmt=$mysqli->prepare("INSERT INTO smarttable_settings (table_name, pk, columns_json, searchable_json, editdeny_json, soft_delete, enabled)
                              VALUES (?,?,?,?,?,?,?)");
      $cj_s=json_encode($cj,JSON_UNESCAPED_UNICODE);
      $sj_s=$sj===null?null:json_encode($sj,JSON_UNESCAPED_UNICODE);
      $dj_s=$dj===null?null:json_encode($dj,JSON_UNESCAPED_UNICODE);
      $stmt->bind_param('ssssssi',$name,$pk,$cj_s,$sj_s,$dj_s,$soft,$ena);
      if ($stmt->execute()) $flash .= ok('Settings angelegt.');
      else $err .= bad('Insert fehlgeschlagen: '.$stmt->error);
      $stmt->close();
    }
  }
}

$tables = db_tables($mysqli);
$settings = [];
$res=$mysqli->query("SELECT * FROM smarttable_settings ORDER BY table_name");
if ($res) while($r=$res->fetch_assoc()) $settings[]=$r;

$edit_row=null; $edit_id=isset($_GET['edit'])?(int)$_GET['edit']:0;
if ($edit_id) { foreach($settings as $r) if ((int)$r['id']===$edit_id) { $edit_row=$r; break; } }

// >>> HIER: globalen Header einbinden (Navigation & globale Styles)
require_once __DIR__ . '/../includes/header.php';
?>

<!-- lokale Styles für diese Admin-Seite (im Body okay; DEV-CSP lässt inline zu) -->
<style>
  :root { --bd:#e5e7eb; --bg:#f8fafc; --ink:#0f172a; --mut:#64748b; }
  * { box-sizing:border-box; }
  .subtitle { color:#64748b; font-size:12px; margin-top:4px; }
  main.settings { padding:18px; display:grid; gap:18px; max-width:1200px; margin:0 auto; }
  .grid { display:grid; gap:18px; grid-template-columns: 1fr 1fr; }
  .card { background:#fff; border:1px solid var(--bd); border-radius:12px; overflow:hidden; }
  .card header { background:#f1f5f9; padding:10px 12px; font-weight:600; }
  .card .content { padding:12px; display:grid; gap:12px; }
  .row { display:grid; grid-template-columns: 1fr 2fr; gap:10px; align-items:center; }
  .flex { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .right { display:flex; gap:10px; justify-content:flex-end; }
  input[type=text],select{ width:100%; padding:10px; border:1px solid var(--bd); border-radius:10px; background:#fff;}
  table { width:100%; border-collapse:collapse; }
  th,td { padding:10px 10px; border-bottom:1px solid var(--bd); vertical-align:middle; }
  .muted { color:var(--mut); font-size:12px; }
  .btn { border:1px solid var(--bd); background:#fff; border-radius:10px; padding:10px 14px; cursor:pointer; font-weight:600; }
  .btn.primary { background:#2563eb; border-color:#1d4ed8; color:#fff; }
  .btn.danger  { background:#ef4444; border-color:#dc2626; color:#fff; }
  .alert { padding:10px 12px; border-radius:10px; }
  .alert.ok { background:#d1fae5; border:1px solid #a7f3d0; color:#065f46; }
  .alert.err{ background:#fee2e2; border:1px solid #fecaca; color:#991b1b; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; }
  .hidden { display:none; }
  .nowrap { white-space:nowrap; }
  .switch { position:relative; display:inline-block; width:44px; height:24px; vertical-align:middle; }
  .switch input { display:none; }
  .slider { position:absolute; top:0; left:0; right:0; bottom:0; background:#e5e7eb; border-radius:999px; transition:.2s; }
  .slider:before { position:absolute; content:""; height:18px; width:18px; left:3px; top:3px; background:white; border-radius:50%; transition:.2s; box-shadow:0 1px 2px rgba(0,0,0,.15); }
  .switch input:checked + .slider { background:#22c55e; }
  .switch input:checked + .slider:before { transform:translateX(20px); }
</style>

<main class="settings">
  <header class="top">
    <h1 style="margin:0;font-size:22px;">SmartTable – Einfache Einstellungen</h1>
    <div class="subtitle">Ein-Klick Erstellen, Verbinden, Bearbeiten – ohne Programmieren</div>
  </header>

  <?= $flash ?><?= $err ?>

  <div class="grid">
    <!-- 1) SCHNELLSTART: 1-Klick Tabellen -->
    <section class="card">
      <header>1) Schnellstart: Tabelle in 1-Klick</header>
      <div class="content">
        <form method="post" id="formQuick" class="flex">
          <input type="hidden" name="action" value="create_table">
          <input type="hidden" name="ct_cols_json" id="ct_cols_json">
          <input type="hidden" name="ct_created" value="1">
          <input type="hidden" name="ct_updated" value="1">
          <input type="hidden" name="ct_deleted" value="1">

          <div class="row">
            <label>Tabellenname</label>
            <input name="ct_table" id="ct_table" type="text" placeholder="z.B. maengelliste">
          </div>
          <div class="row">
            <label>Primärschlüssel</label>
            <input name="ct_pk" id="ct_pk" type="text" value="id">
          </div>

          <div class="flex">
            <button type="button" class="btn" data-template="maengelliste">Mängelliste anlegen</button>
            <button type="button" class="btn" data-template="projekte">Projekte anlegen</button>
            <button type="button" class="btn" data-template="benutzer">Benutzer anlegen</button>
            <button type="submit" class="btn primary">Erstellen</button>
          </div>
          <div class="muted">Tipp: Klicke zuerst auf eine Vorlage, dann auf „Erstellen“.</div>
        </form>
      </div>
    </section>

    <!-- 2) VERBINDEN: bestehende Tabelle -->
    <section class="card">
      <header>2) Vorhandene Tabelle verbinden</header>
      <div class="content">
        <form method="post" class="flex">
          <input type="hidden" name="action" value="bind_table">
          <select name="bt_table">
            <?php foreach ($tables as $t): ?>
              <option value="<?=h($t)?>"><?=h($t)?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn primary">Verbinden</button>
        </form>
        <div class="muted">Das legt automatisch die Einstellungen für die SmartTable-API an.</div>
      </div>
    </section>
  </div>

  <!-- 3) SETTINGS-LISTE -->
  <section class="card">
    <header>3) Tabellen verwalten</header>
    <div class="content">
      <table>
        <thead><tr><th>ID</th><th>Tabelle</th><th>PK</th><th>Soft</th><th>Aktiv</th><th>Bearbeiten</th><th>Löschen</th></tr></thead>
      <tbody>
        <?php if (!$settings): ?>
          <tr><td colspan="7" class="muted">Noch keine Einträge.</td></tr>
        <?php else: foreach ($settings as $s): ?>
          <tr>
            <td class="mono"><?= (int)$s['id'] ?></td>
            <td class="mono"><?= h($s['table_name']) ?></td>
            <td class="mono"><?= h($s['pk']) ?></td>
            <td><?= ((int)$s['soft_delete']) ? 'Ja' : 'Nein' ?></td>
            <td><?= ((int)$s['enabled']) ? 'Ja' : 'Nein' ?></td>
            <td><a class="btn" href="?edit=<?= (int)$s['id'] ?>">Öffnen</a></td>
            <td>
              <form method="post" class="delete-form">
                <input type="hidden" name="action" value="delete_settings">
                <input type="hidden" name="del_id" value="<?= (int)$s['id'] ?>">
                <button class="btn danger">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      </table>
    </div>
  </section>

  <!-- 4) EINFACH BEARBEITEN -->
  <section class="card">
    <header><?= $edit_row ? 'Tabelle bearbeiten' : 'Tabelle auswählen & bearbeiten' ?></header>
    <div class="content">
      <form method="post" id="formEdit">
        <input type="hidden" name="action" value="save_settings">
        <?php if ($edit_row): ?>
          <input type="hidden" name="ss_id" value="<?= (int)$edit_row['id'] ?>">
        <?php endif; ?>

        <!-- Hidden JSON (vom JS gesetzt) -->
        <input type="hidden" name="ss_cols_json" id="ss_cols_json" value="<?= h($edit_row['columns_json'] ?? '[]') ?>">
        <input type="hidden" name="ss_search_json" id="ss_search_json" value="<?= h($edit_row['searchable_json'] ?? '[]') ?>">
        <input type="hidden" name="ss_deny_json" id="ss_deny_json" value="<?= h($edit_row['editdeny_json'] ?? '["id","created_at","updated_at","deleted_at"]') ?>">

        <div class="row">
          <label>Tabellenname</label>
          <input name="ss_table" id="ss_table" type="text" value="<?= h($edit_row['table_name'] ?? '') ?>" placeholder="z.B. maengelliste" required>
        </div>
        <div class="row">
          <label>Primärschlüssel</label>
          <input name="ss_pk" id="ss_pk" type="text" value="<?= h($edit_row['pk'] ?? 'id') ?>">
        </div>

        <div class="flex">
          <label class="flex" style="gap:8px;align-items:center;">
            <span class="switch"><input type="checkbox" name="ss_soft" id="ss_soft" <?= (($edit_row['soft_delete'] ?? 1) ? 'checked' : '') ?>><span class="slider"></span></span> Soft-Delete
          </label>
          <label class="flex" style="gap:8px;align-items:center;">
            <span class="switch"><input type="checkbox" name="ss_enabled" id="ss_enabled" <?= (($edit_row['enabled'] ?? 1) ? 'checked' : '') ?>><span class="slider"></span></span> Aktiv
          </label>
          <button type="button" class="btn" id="btnLoadCols">Spalten laden</button>
        </div>

        <div>
          <table id="editTable">
            <thead>
              <tr><th>↑↓</th><th>Spalte</th><th>Suchbar</th><th>Sperren</th></tr>
            </thead>
            <tbody><!-- JS rendert hier --></tbody>
          </table>
          <div class="muted">Mit ↑/↓ sortieren · Suchbar = in der Volltextsuche · Sperren = im Editor unveränderbar</div>
        </div>

        <div class="right" style="margin-top:8px;">
          <button class="btn primary">Speichern</button>
        </div>
      </form>

      <!-- Erweiterungen: Advanced + Preview -->
      <div class="flex" style="margin-top:8px;">
        <button type="button" class="btn" id="btnAdvanced">Erweiterte Spalten (Relationen)</button>
        <button type="button" class="btn" id="btnPreview">Vorschau</button>
      </div>

      <div id="advancedPanel" class="card hidden" style="margin-top:12px;">
        <header>Erweiterte Spalten – Label/Typ/Relation</header>
        <div class="content" id="advancedContent"></div>
        <div class="right" style="padding:12px;">
          <button type="button" class="btn primary" id="btnSaveColumns">Spalten-Metadaten speichern</button>
        </div>
      </div>

      <div id="previewPanel" class="card hidden" style="margin-top:12px;">
        <header>Vorschau (erste 10 Zeilen)</header>
        <div class="content" id="previewContent" style="overflow:auto;"></div>
      </div>
    </div>
  </section>
</main>

<!-- Externes, CSP-sicheres JS -->
<script src="/pendenz.com/assets/js/smarttable_settings_wizard.js?v=20250920a" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
