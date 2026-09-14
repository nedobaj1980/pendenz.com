<?php
// tools/konto_verwaltung/index.php
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/authz.php';
require_once __DIR__ . '/../../includes/audit.php';
require_login();
require_once __DIR__ . '/../../includes/functions.php'; // url(), site_prefix(), db()

/* ===== Layout / Nav ===== */
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/nav_dispatch.php';

// ... deine bestehenden require_once ...
require_once __DIR__ . '/../../includes/csrf.php'; // CSRF für POST-Formulare

// ==== HILFSFUNKTIONEN (kollisionsfrei, präfix "kv_") =========================
if (!function_exists('kv_table_has_column')) {
  function kv_table_has_column(mysqli $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $st->bind_param("ss",$table,$col);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
  }
}
if (!function_exists('kv_fetch_all')) {
  function kv_fetch_all(mysqli $db, string $sql, array $params=[], string $types=''){
    $st=$db->prepare($sql);
    if($params){ $st->bind_param($types, ...$params); }
    $st->execute(); $rs=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    return $rs;
  }
}
if (!function_exists('kv_html')) {
  function kv_html($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}

// ==== KONTEXT (GET) ==========================================================
$ctx_projekt_id  = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
$ctx_objekt_id   = isset($_GET['objekt_id'])  ? (int)$_GET['objekt_id']  : 0;
$ctx_wohnung_id  = isset($_GET['wohnung_id']) ? (int)$_GET['wohnung_id'] : 0;

// ==== LISTEN LADEN ===========================================================
// Projekte
$kv_projekte = kv_fetch_all($mysqli, "SELECT id,name FROM projekte ORDER BY name");
// Objekte (optional nach Projekt)
$kv_objekte  = $ctx_projekt_id
  ? kv_fetch_all($mysqli, "SELECT id, COALESCE(NULLIF(bezeichnung,''), name) as bezeichnung FROM objekte WHERE projekt_id=? ORDER BY name", [$ctx_projekt_id], 'i')
  : [];
// Wohnungen (optional nach Objekt)
$kv_wohnungen = $ctx_objekt_id
  ? kv_fetch_all($mysqli, "SELECT id, name as bezeichnung FROM wohnungen WHERE objekt_id=? ORDER BY name", [$ctx_objekt_id], 'i')
  : [];
// Aktiver Mieter (heute) für Wohnung
$kv_mieter = [];
if ($ctx_wohnung_id) {
  $kv_mieter = kv_fetch_all(
    $mysqli,
    "SELECT b.id, b.name
       FROM wohnung_mieter wm
       JOIN benutzer b ON b.id=wm.benutzer_id
      WHERE wm.wohnung_id=? AND (wm.enddatum IS NULL OR wm.enddatum>=CURDATE())
      ORDER BY b.name",
    [$ctx_wohnung_id], 'i'
  );
}

// Alle Wohnungen & Mieter für Schnell-Zuweisung laden
$allWohnungen = kv_fetch_all($mysqli, "
    SELECT w.id, w.name as wohnung_name, o.name as objekt_name, p.name as projekt_name, p.id as projekt_id 
    FROM wohnungen w 
    JOIN objekte o ON w.objekt_id = o.id 
    JOIN projekte p ON o.projekt_id = p.id 
    ORDER BY p.name, o.name, w.name
");
$allMieter = kv_fetch_all($mysqli, "
    SELECT wm.id as wm_id, wm.wohnung_id, wm.benutzer_id, wm.mieter_name,
           b.name as benutzer_name, w.name as wohnung_name
    FROM wohnung_mieter wm
    LEFT JOIN benutzer b ON wm.benutzer_id = b.id
    LEFT JOIN wohnungen w ON wm.wohnung_id = w.id
    WHERE wm.status = 'aktiv'
    ORDER BY COALESCE(NULLIF(wm.mieter_name,''), b.name) ASC
");

// ==== POST-AKTIONEN ==========================================================
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_require(); // sichert alle schreibenden Aktionen

  $act = $_POST['action'] ?? '';

  // 1) Konto-Standardzuordnung speichern (kv_konten.projekt_id / liegenschaft_id)
  if ($act === 'kv_save_konto_mapping') {
    $konto_id  = (int)($_POST['konto_id'] ?? 0);
    $proj_id   = (int)($_POST['ctx_projekt_id'] ?? 0);
    $obj_id    = (int)($_POST['ctx_objekt_id'] ?? 0);

    if ($konto_id > 0) {
      $parts = []; $types=''; $vals=[];
      if (kv_table_has_column($mysqli, 'kv_konten','projekt_id'))      { $parts[]='projekt_id=?';      $types.='i'; $vals[]=$proj_id ?: null; }
      if (kv_table_has_column($mysqli, 'kv_konten','liegenschaft_id')) { $parts[]='liegenschaft_id=?'; $types.='i'; $vals[]=$obj_id  ?: null; }
      if ($parts) {
        $types.='i'; $vals[]=$konto_id;
        $sql = "UPDATE kv_konten SET ".implode(',', $parts)." WHERE id=?";
        $st  = $mysqli->prepare($sql);
        $st->bind_param($types, ...$vals);
        $st->execute();
        $st->close();
        $kv_flash = "✅ Zuordnung für Konto #{$konto_id} gespeichert.";
      } else {
        $kv_flash = "ℹ️ In Tabelle kv_konten fehlen Spalten projekt_id/liegenschaft_id – nichts zu speichern.";
      }
    } else {
      $kv_flash = "❌ Kein Konto gewählt.";
    }
  }

  // 2) Neue Buchung unter aktuellem Kontext erfassen (liegenschafts_konto)
  if ($act === 'kv_new_booking') {
    $konto_id  = (int)($_POST['konto_id'] ?? 0);
    $datum     = trim($_POST['buchungsdatum'] ?? '');
    $betrag    = (float)($_POST['betrag'] ?? 0);
    $text      = trim($_POST['beschreibung'] ?? '');

    $proj_id   = (int)($_POST['ctx_projekt_id'] ?? 0);
    $obj_id    = (int)($_POST['ctx_objekt_id'] ?? 0);
    $whg_id    = (int)($_POST['ctx_wohnung_id'] ?? 0);
    $mieter_id = (int)($_POST['ctx_mieter_id'] ?? 0); // optional (falls du das speichern willst & Spalte existiert)

    if ($konto_id<=0 || $datum==='') {
      $kv_flash = "❌ Bitte Konto und Datum angeben.";
    } else {
      // dynamischer INSERT – nur vorhandene Spalten werden genutzt
      $cols=['konto_id','buchungsdatum','betrag','beschreibung'];
      $types='isds';
      $vals=[ $konto_id, $datum, $betrag, $text ];

      if (kv_table_has_column($mysqli,'liegenschafts_konto','projekt_id'))      { $cols[]='projekt_id';      $types.='i'; $vals[]=$proj_id ?: null; }
      if (kv_table_has_column($mysqli,'liegenschafts_konto','liegenschaft_id')) { $cols[]='liegenschaft_id'; $types.='i'; $vals[]=$obj_id  ?: null; }
      if (kv_table_has_column($mysqli,'liegenschafts_konto','wohnung_id'))      { $cols[]='wohnung_id';      $types.='i'; $vals[]=$whg_id ?: null; }
      if (kv_table_has_column($mysqli,'liegenschafts_konto','mieter_id'))       { $cols[]='mieter_id';       $types.='i'; $vals[]=$mieter_id ?: null; }

      $place = implode(',', array_fill(0,count($cols),'?'));
      $sql   = "INSERT INTO liegenschafts_konto (".implode(',',$cols).") VALUES ($place)";
      $st = $mysqli->prepare($sql);
      $st->bind_param($types, ...$vals);
      $st->execute();
      $st->close();

      $kv_flash = "✅ Buchung erfasst.";
    }
  }

  // 3) Auto-Match ausführen
  if ($act === 'run_auto_match') {
    require_once __DIR__ . '/auto_match.php';
    $pFilter = (int)($_POST['filter_projekt_id'] ?? 0);
    $res = run_auto_match($mysqli, $pFilter);
    $kv_flash = "⚡ Auto-Match abgeschlossen: {$res['matched_count']} von {$res['total_checked']} Buchungen wurden erfolgreich Mietern/Wohnungen zugeordnet!";
  }

  // 4) Einzelne Buchung Wohnung & Mieter zuweisen
  if ($act === 'assign_single') {
    $kid = (int)($_POST['konto_id'] ?? 0);
    $wid = (int)($_POST['wohnung_id'] ?? 0);
    $mid = (int)($_POST['mieter_id'] ?? 0);
    $wLbl = '';
    if ($wid > 0) {
      $rw = $mysqli->query("SELECT name FROM wohnungen WHERE id = $wid")->fetch_assoc();
      $wLbl = $rw['name'] ?? '';
    }
    if ($mid > 0) {
      $chk = $mysqli->query("SELECT id FROM benutzer WHERE id = $mid");
      if (!$chk || $chk->num_rows === 0) $mid = null;
    } else {
      $mid = null;
    }
    $st = $mysqli->prepare("UPDATE liegenschafts_konto SET wohnung_id=?, mieter_id=?, wohnung_label=?, kategorie = IF(kategorie IS NULL OR kategorie='', 'Miete', kategorie) WHERE id=?");
    $st->bind_param("iisi", $wid, $mid, $wLbl, $kid);
    $st->execute();
    $st->close();
    $kv_flash = "✅ Buchung #$kid erfolgreich Wohnung & Mieter zugewiesen.";
  }

  // 5) Bankkonto einer Liegenschaft speichern / bearbeiten
  if ($act === 'save_single_konto') {
    $kid = (int)($_POST['konto_id'] ?? 0);
    $pid = (int)($_POST['projekt_id'] ?? 0);
    $accName = trim($_POST['konto_name'] ?? '');
    $iban = trim(str_replace(' ', '', $_POST['iban'] ?? ''));
    $bank = trim($_POST['bank'] ?? 'Raiffeisen');
    
    if ($kid > 0) {
      $st = $mysqli->prepare("UPDATE kv_konten SET name=?, iban=NULLIF(?,''), bank=?, projekt_id=?, liegenschaft_id=?, updated_at=NOW() WHERE id=?");
      $st->bind_param("sssiii", $accName, $iban, $bank, $pid, $pid, $kid);
      $st->execute();
      $st->close();
      $kv_flash = "✅ Bankkonto #$kid erfolgreich aktualisiert.";
    } else {
      $st = $mysqli->prepare("INSERT INTO kv_konten (name, iban, bank, waehrung, liegenschaft_id, projekt_id, created_at, updated_at) VALUES (?, NULLIF(?,''), ?, 'CHF', ?, ?, NOW(), NOW())");
      $st->bind_param("sssii", $accName, $iban, $bank, $pid, $pid);
      $st->execute();
      $st->close();
      $kv_flash = "✅ Neues Bankkonto angelegt.";
    }
  }
}

$PREFIX = site_prefix();
$mysqli = $mysqli ?? db();

if (!in_array($_SESSION['rolle'] ?? '', ['superadmin', 'admin'])) {
  die("Zugriff verweigert: Nur Administratoren haben Zugriff auf dieses Tool.");
}

/** Helpers */
if (!function_exists('table_exists')) {
  function table_exists(mysqli $db, string $name): bool {
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
    $st = $db->prepare($sql); $st->bind_param("s",$name); $st->execute();
    $res = $st->get_result(); $ok = (bool)$res->fetch_row(); $st->close(); return $ok;
  }
}
if (!function_exists('hasColumn')) {
  function hasColumn(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?";
    $st = $db->prepare($sql); $st->bind_param("ss",$table,$column); $st->execute();
    $res = $st->get_result()->fetch_assoc(); $st->close(); return (int)$res['c'] > 0;
  }
}

/** Auto-Installer: liegenschafts_konto (+ optional wohnung_label) */
if (!function_exists('ensure_liegenschafts_konto')) {
  function ensure_liegenschafts_konto(mysqli $db): void {
    if (!table_exists($db,'liegenschafts_konto')) {
      $db->query("CREATE TABLE IF NOT EXISTS liegenschafts_konto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        liegenschaft_id INT NULL,
        buchungsdatum DATE NULL,
        betrag DECIMAL(12,2) NOT NULL DEFAULT 0,
        beschreibung VARCHAR(255) NULL,
        kategorie VARCHAR(80) NULL,
        zahlungsart VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT NOW(),
        updated_at DATETIME NOT NULL DEFAULT NOW(),
        INDEX idx_lieg (liegenschaft_id),
        INDEX idx_date (buchungsdatum),
        INDEX idx_cat  (kategorie),
        INDEX idx_besch(beschreibung)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    // Zusatzspalte wohnung_label (optional)
    if (!hasColumn($db,'liegenschafts_konto','wohnung_label')) {
      $db->query("ALTER TABLE liegenschafts_konto ADD COLUMN wohnung_label VARCHAR(100) NULL AFTER kategorie");
    }
  }
}
ensure_liegenschafts_konto($mysqli);

/* ------------------------------
   Projekte & Liegenschafts-Bankkonten laden
--------------------------------*/
$projekte = [];
if ($res = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) $projekte[] = $r;
  $res->free();
}
$projById = [];
foreach ($projekte as $p) $projById[(int)$p['id']] = $p['name'];

// Alle Liegenschafts-Bankkonten
$allKonten = [];
$kRes = $mysqli->query("SELECT k.id, k.name, k.iban, k.bank, k.waehrung, k.projekt_id, k.liegenschaft_id, p.name AS projekt_name 
                        FROM kv_konten k 
                        LEFT JOIN projekte p ON k.projekt_id = p.id 
                        ORDER BY p.name ASC, k.name ASC");
if ($kRes) {
  while ($r = $kRes->fetch_assoc()) $allKonten[] = $r;
}
$kontenById = [];
$kontenByProj = [];
foreach ($allKonten as $ak) {
  $kontenById[(int)$ak['id']] = $ak;
  if (!empty($ak['projekt_id'])) {
    $kontenByProj[(int)$ak['projekt_id']] = $ak;
  }
}

// Alle Buchungsjahre aus liegenschafts_konto ermitteln
$dbYears = [];
$yrRes = $mysqli->query("SELECT DISTINCT YEAR(buchungsdatum) AS yr FROM liegenschafts_konto WHERE buchungsdatum IS NOT NULL AND buchungsdatum != '0000-00-00' ORDER BY yr DESC");
if ($yrRes) {
  while ($r = $yrRes->fetch_assoc()) {
    $y = (int)$r['yr'];
    if ($y >= 2000 && $y <= 2099) $dbYears[] = $y;
  }
}
$currentCalYear = (int)date('Y');
$availableYears = array_unique(array_merge([$currentCalYear, $currentCalYear - 1], $dbYears));
rsort($availableYears);

/* ------------------------------
   Filter einsammeln
--------------------------------*/
$projektId    = isset($_GET['projekt_id']) && $_GET['projekt_id'] !== '' ? (int)$_GET['projekt_id'] : null;
$kontoId      = isset($_GET['konto_id']) && $_GET['konto_id'] !== '' ? (int)$_GET['konto_id'] : null;

// Wenn konto_id gewählt ist, aber kein projekt_id, projekt_id ableiten
if ($kontoId && isset($kontenById[$kontoId]) && empty($projektId)) {
  $projektId = (int)($kontenById[$kontoId]['projekt_id'] ?? 0);
}

// Jahres-Auswahl:
// 'all' oder '0' => Alle Jahre anzeigen
// Zahl => genau dieses Jahr
// Standardmäßig: das neueste Jahr mit Buchungen (z.B. 2023)
$selectedYear = null;
if (isset($_GET['jahr'])) {
  if ($_GET['jahr'] === 'all' || $_GET['jahr'] === '0') {
    $selectedYear = 0;
  } else {
    $selectedYear = (int)$_GET['jahr'];
  }
} else {
  $selectedYear = !empty($dbYears) ? $dbYears[0] : $currentCalYear;
}

// Monats-Auswahl:
$selectedMonth = isset($_GET['monat']) && $_GET['monat'] !== '' && $_GET['monat'] !== 'all' ? (int)$_GET['monat'] : 0;

$datumVon     = trim($_GET['datum_von'] ?? '');
$datumBis     = trim($_GET['datum_bis'] ?? '');
$betragVon    = trim($_GET['betrag_von'] ?? '');
$betragBis    = trim($_GET['betrag_bis'] ?? '');
$suchtext     = trim($_GET['beschreibung'] ?? '');
$splitByDescs = isset($_GET['split']) && $_GET['split'] === '1';

/* URL-Helfer für Filter & Tabs */
if (!function_exists('buildKontoUrl')) {
  function buildKontoUrl(array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
      if ($v === null || $v === '') {
        unset($q[$k]);
      } else {
        $q[$k] = $v;
      }
    }
    if (isset($overrides['jahr']) || isset($overrides['monat']) || isset($overrides['projekt_id']) || isset($overrides['konto_id']) || isset($overrides['match_status'])) {
      unset($q['page']);
    }
    return '?' . http_build_query($q);
  }
}

/* Mehrfachauswahl nach Beschreibung */
$selectedDescs = isset($_GET['beschreibungen']) && is_array($_GET['beschreibungen'])
  ? array_values(array_filter($_GET['beschreibungen'], fn($v)=>$v!=='')) : [];

/* Sortierung & Pagination */
$sortable = ['id','liegenschaft_id','buchungsdatum','betrag','beschreibung','kategorie','zahlungsart'];
$sort     = $_GET['sort'] ?? 'buchungsdatum';
if (!in_array($sort, $sortable, true)) $sort = 'buchungsdatum';

$dir = strtolower($_GET['dir'] ?? 'desc');
$dir = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';

$perPageOptions = [25,50,100,200,500];
$perPage = (int)($_GET['per'] ?? 100);
if (!in_array($perPage, $perPageOptions, true)) $perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$matchStatus  = trim($_GET['match_status'] ?? 'all'); // 'all', 'unmatched', 'matched'

/* WHERE-Bedingungen */
$where = [];
$params = [];
$types  = "";

if ($kontoId) {
  $where[] = "k.konto_id = ?";
  $params[] = $kontoId;
  $types .= "i";
} elseif ($projektId) {
  $where[] = "(k.liegenschaft_id = ? OR k.projekt_id = ?)";
  $params[] = $projektId;
  $params[] = $projektId;
  $types .= "ii";
}

if ($selectedYear > 0) {
  $where[] = "YEAR(k.buchungsdatum) = ?";
  $params[] = $selectedYear;
  $types .= "i";
}

if ($selectedMonth >= 1 && $selectedMonth <= 12) {
  $where[] = "MONTH(k.buchungsdatum) = ?";
  $params[] = $selectedMonth;
  $types .= "i";
}

if ($datumVon !== '') { $where[] = "k.buchungsdatum >= ?";  $params[] = $datumVon;   $types .= "s"; }
if ($datumBis !== '') { $where[] = "k.buchungsdatum <= ?";  $params[] = $datumBis;   $types .= "s"; }
if ($betragVon !== ''){ $where[] = "k.betrag >= ?";        $params[] = (float)$betragVon; $types .= "d"; }
if ($betragBis !== ''){ $where[] = "k.betrag <= ?";        $params[] = (float)$betragBis; $types .= "d"; }
if ($suchtext !== '') { $where[] = "k.beschreibung LIKE ?";$params[] = "%{$suchtext}%";   $types .= "s"; }

if ($matchStatus === 'unmatched') {
  $where[] = "(k.wohnung_id IS NULL OR k.wohnung_id = 0)";
} elseif ($matchStatus === 'matched') {
  $where[] = "(k.wohnung_id IS NOT NULL AND k.wohnung_id > 0)";
}

if (!empty($selectedDescs)) {
  $in = implode(",", array_fill(0, count($selectedDescs), "?"));
  $where[] = "k.beschreibung IN ($in)";
  foreach ($selectedDescs as $b) { $params[] = $b; $types .= "s"; }
}
$whereSql = $where ? (" WHERE " . implode(" AND ", $where)) : "";

/* ------------------------------
   Batch-Aktionen
--------------------------------*/
$flash = "";
$hasWohnLbl = hasColumn($mysqli, 'liegenschafts_konto', 'wohnung_label');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $ids = array_map('intval', array_filter($ids, fn($v)=>$v!=='')); // sauber

    $action = $_POST['do'] ?? '';

    if ($action === 'set_cat') {
      $newCat = trim($_POST['new_cat'] ?? '');
      if (!$ids || $newCat === '') throw new Exception("Bitte Zeilen auswählen und neue Kategorie angeben.");
      $st = $mysqli->prepare("UPDATE liegenschafts_konto SET kategorie=? WHERE id=?");
      foreach ($ids as $id) { $st->bind_param("si", $newCat, $id); $st->execute(); }
      $st->close();
      $flash = "✅ Kategorie bei ".count($ids)." Einträgen gesetzt.";
    }

    if ($action === 'assign_proj_whg') {
      $pid = (int)($_POST['assign_projekt_id'] ?? 0);
      $wid = (int)($_POST['assign_wohnung_id'] ?? 0);
      $whg = $hasWohnLbl ? trim($_POST['assign_wohnung'] ?? '') : null;
      $mid = (int)($_POST['assign_mieter_id'] ?? 0);

      if ($wid > 0) {
        $rw = $mysqli->query("SELECT w.name, o.projekt_id FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE w.id = $wid")->fetch_assoc();
        if ($rw) {
          $whg = $rw['name'];
          if ($pid <= 0) $pid = (int)$rw['projekt_id'];
        }
        if ($mid <= 0) {
          $rm = $mysqli->query("SELECT benutzer_id FROM wohnung_mieter WHERE wohnung_id = $wid AND status = 'aktiv' LIMIT 1")->fetch_assoc();
          if ($rm && !empty($rm['benutzer_id'])) $mid = (int)$rm['benutzer_id'];
        }
      }

      if ($mid > 0) {
        $chk = $mysqli->query("SELECT id FROM benutzer WHERE id = $mid");
        if (!$chk || $chk->num_rows === 0) $mid = null;
      } else {
        $mid = null;
      }

      if (!$ids) throw new Exception("Bitte Zeilen auswählen.");
      if ($pid <= 0 && $wid <= 0 && (!$hasWohnLbl || $whg === '')) throw new Exception("Bitte Projekt und/oder Wohnung angeben.");

      $st = $mysqli->prepare("UPDATE liegenschafts_konto SET 
        liegenschaft_id = IF(? > 0, ?, liegenschaft_id),
        wohnung_id = IF(? > 0, ?, wohnung_id),
        wohnung_label = IF(? != '', ?, wohnung_label),
        mieter_id = IF(? IS NOT NULL, ?, mieter_id),
        kategorie = IF(kategorie IS NULL OR kategorie='', 'Miete', kategorie)
        WHERE id = ?");

      foreach ($ids as $id) {
        $st->bind_param("iiiiisis", $pid, $pid, $wid, $wid, $whg, $whg, $mid, $mid, $id);
        $st->execute();
      }
      $st->close();
      $flash = "✅ Zuweisung bei ".count($ids)." Einträgen gesetzt.";
    }

    if ($action === 'delete_rows') {
      if (!$ids) throw new Exception("Bitte Zeilen auswählen.");
      $in = implode(",", array_fill(0, count($ids), "?"));
      $typesDel = str_repeat("i", count($ids));
      $st = $mysqli->prepare("DELETE FROM liegenschafts_konto WHERE id IN ($in)");
      $st->bind_param($typesDel, ...$ids);
      $st->execute();
      $aff = $st->affected_rows;
      $st->close();
      $flash = "🗑️ $aff Einträge gelöscht.";
    }

    if (isset($_POST['apply_assignment'])) {
      $assignName    = trim($_POST['assign_name'] ?? '');
      $useRegex      = isset($_POST['assign_regex']) && $_POST['assign_regex'] === '1';
      $assignProj    = (int)($_POST['assign_projekt_id'] ?? 0);
      $assignWohnung = $hasWohnLbl ? trim($_POST['assign_wohnung'] ?? '') : null;
      $scopeFilter   = isset($_POST['assign_scope']) && $_POST['assign_scope'] === '1';

      if ($assignProj <= 0 && (!$hasWohnLbl || $assignWohnung === '')) {
        throw new Exception("Nichts zuzuweisen: Bitte Projekt und/oder Wohnung angeben.");
      }
      if ($assignName === '') {
        throw new Exception("Bitte 'Name enthält' ausfüllen oder Beschreibungen auswählen.");
      }

      $idsSel = [];
      $selWhere = []; $selParams=[]; $selTypes="";
      if ($scopeFilter && $where) { $selWhere=$where; $selParams=$params; $selTypes=$types; }
      if ($useRegex) { $selWhere[]="beschreibung REGEXP ?"; $selParams[]=$assignName; $selTypes.='s'; }
      else { $selWhere[]="beschreibung LIKE ?"; $selParams[]="%{$assignName}%"; $selTypes.='s'; }

      $sqlSel = "SELECT k.id FROM liegenschafts_konto k";
      if ($selWhere) $sqlSel .= " WHERE " . implode(" AND ", $selWhere);
      $stmt = $mysqli->prepare($sqlSel);
      if ($selParams) $stmt->bind_param($selTypes, ...$selParams);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) $idsSel[] = (int)$r['id'];
      $stmt->close();

      if (!$idsSel) {
        $flash = "⚠️ Keine Datensätze gefunden, die zugeordnet werden könnten.";
      } else {
        if ($hasWohnLbl) {
          if ($assignProj > 0 && $assignWohnung !== '') {
            $up = $mysqli->prepare("UPDATE liegenschafts_konto SET liegenschaft_id=?, wohnung_label=? WHERE id=?");
            foreach ($idsSel as $id) { $up->bind_param("isi", $assignProj, $assignWohnung, $id); $up->execute(); }
            $up->close();
          } elseif ($assignProj > 0) {
            $up = $mysqli->prepare("UPDATE liegenschafts_konto SET liegenschaft_id=? WHERE id=?");
            foreach ($idsSel as $id) { $up->bind_param("ii", $assignProj, $id); $up->execute(); }
            $up->close();
          } else {
            $up = $mysqli->prepare("UPDATE liegenschafts_konto SET wohnung_label=? WHERE id=?");
            foreach ($idsSel as $id) { $up->bind_param("si", $assignWohnung, $id); $up->execute(); }
            $up->close();
          }
        } else {
          if ($assignProj > 0) {
            $up = $mysqli->prepare("UPDATE liegenschafts_konto SET liegenschaft_id=? WHERE id=?");
            foreach ($idsSel as $id) { $up->bind_param("ii", $assignProj, $id); $up->execute(); }
            $up->close();
          }
        }
        $flash = "✅ Zuweisung durchgeführt für " . count($idsSel) . " Datensätze.";
      }
    }

  } catch (Throwable $e) {
    $flash = "❌ " . $e->getMessage();
  }
}

/* ------------------------------
   Daten laden (paginiert + sortiert)
--------------------------------*/
$statTotal = (int)($mysqli->query("SELECT COUNT(*) FROM liegenschafts_konto")->fetch_column() ?? 0);

$sumSql = "SELECT COUNT(*) AS cnt, 
                  COALESCE(SUM(k.betrag),0) AS summe,
                  COALESCE(SUM(CASE WHEN k.betrag > 0 THEN k.betrag ELSE 0 END),0) AS einnahmen,
                  COALESCE(SUM(CASE WHEN k.betrag < 0 THEN k.betrag ELSE 0 END),0) AS ausgaben,
                  COALESCE(SUM(CASE WHEN k.wohnung_id IS NOT NULL AND k.wohnung_id > 0 THEN 1 ELSE 0 END),0) AS matched_cnt,
                  COALESCE(SUM(CASE WHEN (k.wohnung_id IS NULL OR k.wohnung_id = 0) AND k.betrag > 0 THEN 1 ELSE 0 END),0) AS unmatched_cnt
           FROM liegenschafts_konto k" . $whereSql;
$stmt = $mysqli->prepare($sumSql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$sumRes  = $stmt->get_result()->fetch_assoc();
$stmt->close();
$totalMatch       = (int)($sumRes['cnt'] ?? 0);
$totalSum         = (float)($sumRes['summe'] ?? 0.0);
$totalEinnahmen   = (float)($sumRes['einnahmen'] ?? 0.0);
$totalAusgaben    = (float)($sumRes['ausgaben'] ?? 0.0);
$statMatched      = (int)($sumRes['matched_cnt'] ?? 0);
$statUnmatched    = (int)($sumRes['unmatched_cnt'] ?? 0);

// Monatsübersicht für das gewählte Jahr
$monthlySummary = [];
$monthNames = [
  1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
  5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
  9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'
];
$monthShort = [
  1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr',
  5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
  9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez'
];

if ($selectedYear > 0) {
  $mWhere = [];
  $mParams = [];
  $mTypes = "";
  if ($kontoId) {
    $mWhere[] = "k.konto_id = ?";
    $mParams[] = $kontoId;
    $mTypes .= "i";
  } elseif ($projektId) {
    $mWhere[] = "(k.liegenschaft_id = ? OR k.projekt_id = ?)";
    $mParams[] = $projektId;
    $mParams[] = $projektId;
    $mTypes .= "ii";
  }
  $mWhere[] = "YEAR(k.buchungsdatum) = ?";
  $mParams[] = $selectedYear;
  $mTypes .= "i";

  $mSql = "SELECT MONTH(k.buchungsdatum) AS m,
                  COUNT(*) AS cnt,
                  COALESCE(SUM(CASE WHEN k.betrag > 0 THEN k.betrag ELSE 0 END),0) AS einnahmen,
                  COALESCE(SUM(CASE WHEN k.betrag < 0 THEN k.betrag ELSE 0 END),0) AS ausgaben,
                  COALESCE(SUM(k.betrag),0) AS saldo,
                  COALESCE(SUM(CASE WHEN k.wohnung_id > 0 THEN 1 ELSE 0 END),0) AS matched_cnt,
                  COALESCE(SUM(CASE WHEN (k.wohnung_id IS NULL OR k.wohnung_id = 0) AND k.betrag > 0 THEN 1 ELSE 0 END),0) AS open_cnt
           FROM liegenschafts_konto k
           WHERE " . implode(" AND ", $mWhere) . "
           GROUP BY MONTH(k.buchungsdatum)
           ORDER BY m ASC";
  $mStmt = $mysqli->prepare($mSql);
  if ($mParams) $mStmt->bind_param($mTypes, ...$mParams);
  $mStmt->execute();
  $mRes = $mStmt->get_result();
  while ($mr = $mRes->fetch_assoc()) {
    $monthlySummary[(int)$mr['m']] = $mr;
  }
  $mStmt->close();
}

$cols = "k.id, k.liegenschaft_id, k.projekt_id, k.buchungsdatum, k.betrag, k.beschreibung, k.kategorie, k.zahlungsart, k.wohnung_label, k.wohnung_id, k.mieter_id, 
         w.name as wohnung_name, p.name as projekt_name,
         COALESCE(NULLIF(wm.mieter_name,''), b.name, k.wohnung_label) as mieter_display_name";

$orderCol = ($sort === 'id') ? 'k.id' : (($sort === 'buchungsdatum') ? 'k.buchungsdatum' : 'k.' . $sort);
$listSql = "SELECT $cols FROM liegenschafts_konto k
            LEFT JOIN wohnungen w ON k.wohnung_id = w.id
            LEFT JOIN objekte o ON w.objekt_id = o.id
            LEFT JOIN projekte p ON (k.liegenschaft_id = p.id OR k.projekt_id = p.id OR o.projekt_id = p.id)
            LEFT JOIN benutzer b ON k.mieter_id = b.id
            LEFT JOIN wohnung_mieter wm ON (wm.wohnung_id = k.wohnung_id AND wm.status = 'aktiv')
            " . $whereSql . " 
            GROUP BY k.id
            ORDER BY $orderCol $dir LIMIT ? OFFSET ?";
$stmt = $mysqli->prepare($listSql);
if ($params) {
  $types2 = $types . "ii";
  $params2 = array_merge($params, [$perPage, $offset]);
  $stmt->bind_param($types2, ...$params2);
} else {
  $stmt->bind_param("ii", $perPage, $offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

/* Distinct Beschreibungen (Top 400) */
$descOptions = [];
$descSql =
  "SELECT beschreibung, COUNT(*) c
     FROM liegenschafts_konto"
   . ($projektId ? " WHERE liegenschaft_id=?" : "")
   . " GROUP BY beschreibung
     HAVING beschreibung IS NOT NULL AND beschreibung <> ''
     ORDER BY c DESC
     LIMIT 400";
$st = $mysqli->prepare($descSql);
if ($projektId) $st->bind_param("i", $projektId);
$st->execute();
$cr = $st->get_result();
while ($rw = $cr->fetch_assoc()) {
  $b = trim((string)$rw['beschreibung']);
  if ($b !== '') $descOptions[] = $b;
}
$st->close();

/* Vorschläge Top 30 */
$suggestions = [];
$sugSql = "SELECT k.beschreibung, COUNT(*) AS c, COALESCE(SUM(k.betrag),0) AS s
           FROM liegenschafts_konto k" . $whereSql . "
           GROUP BY k.beschreibung
           ORDER BY c DESC, s DESC
           LIMIT 30";
$st = $mysqli->prepare($sugSql);
if ($params) $st->bind_param($types, ...$params);
$st->execute();
$sr = $st->get_result();
while ($rw = $sr->fetch_assoc()) $suggestions[] = $rw;
$st->close();

/* Wohnungen & Mieter für Dropdowns & Zuweisung laden */
$allWohnungen = [];
$wRes = $mysqli->query("SELECT w.id, w.name, o.projekt_id, p.name AS projekt_name 
                        FROM wohnungen w 
                        LEFT JOIN objekte o ON w.objekt_id = o.id 
                        LEFT JOIN projekte p ON o.projekt_id = p.id 
                        ORDER BY p.name ASC, w.name ASC");
if ($wRes) {
  while ($rw = $wRes->fetch_assoc()) $allWohnungen[] = $rw;
}

$allMieter = [];
$mRes = $mysqli->query("SELECT wm.id AS wm_id, wm.wohnung_id, wm.benutzer_id, wm.mieter_name, b.name AS benutzer_name 
                        FROM wohnung_mieter wm 
                        LEFT JOIN benutzer b ON wm.benutzer_id = b.id 
                        ORDER BY wm.mieter_name ASC, b.name ASC");
if ($mRes) {
  while ($rw = $mRes->fetch_assoc()) $allMieter[] = $rw;
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars(url('tools/konto_verwaltung/style.css')) ?>">

<div class="konto-container">
  <header class="kv-header" style="display:flex;gap:1rem;justify-content:space-between;align-items:center;margin:8px 0 16px;flex-wrap:wrap;">
    <div>
      <h2 style="margin:0 0 4px 0;">🏦 Liegenschafts-Buchhaltung &amp; Kontoauszug</h2>
      <div style="font-size:13px; color:#64748b;">Bankkonten je Liegenschaft, Jahresansicht, Zahlungsabgleich und Zuordnung</div>
    </div>
    <div class="kv-actions" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;">
      <a class="btn" href="<?= htmlspecialchars(url('tools/konto_verwaltung/import.php')) ?>" style="background:#0284c7;color:#fff;">📂 CSV importieren</a>
      
      <button type="button" class="btn" onclick="openKontenModal()" style="background:#0d9488;color:#fff;border:none;cursor:pointer;">
        🏛️ Bankkonten verwalten
      </button>

      <form method="post" style="margin:0;display:inline;">
        <input type="hidden" name="action" value="run_auto_match">
        <input type="hidden" name="filter_projekt_id" value="<?= (int)($projektId ?: 0) ?>">
        <button type="submit" class="btn" style="background:#10b981;color:#fff;border:none;cursor:pointer;" title="Durchsucht alle Buchungstexte nach bekannten Mietern und Wohnungen">
          ⚡ Auto-Match starten
        </button>
      </form>

      <a class="btn" href="<?= htmlspecialchars(url('tools/mietkontrolle/index.php') . '?' . http_build_query(['projekt_id' => $projektId, 'jahr' => $selectedYear ?: date('Y')])) ?>" style="background:#6366f1;color:#fff;">
        💰 Zur Mietkontrolle (<?= $selectedYear ?: date('Y') ?>)
      </a>

      <a class="btn secondary" href="<?= htmlspecialchars(url('tools/konto_verwaltung/index.php')) ?>">⟲ Zurücksetzen</a>
    </div>
  </header>

  <?php if ($flash): ?>
    <div class="card" style="background:#f4fffa;border-left:4px solid #1abc9c;margin-bottom:12px;padding:12px 16px;font-weight:600;">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($kv_flash)): ?>
    <div class="card" style="background:#f0fdf4;border-left:4px solid #22c55e;margin-bottom:12px;padding:12px 16px;font-weight:600;color:#15803d;">
      <?= htmlspecialchars($kv_flash) ?>
    </div>
  <?php endif; ?>

  <!-- Liegenschafts- und Bankkonto Status-Banner -->
  <?php 
    $activeAcc = null;
    if ($kontoId && isset($kontenById[$kontoId])) {
      $activeAcc = $kontenById[$kontoId];
    } elseif ($projektId && isset($kontenByProj[$projektId])) {
      $activeAcc = $kontenByProj[$projektId];
    }
  ?>
  <div class="kv-account-banner" style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #0284c7; border-radius:10px; padding:12px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
      <?php if ($activeAcc): ?>
        <div style="font-size:11px; font-weight:700; color:#0284c7; text-transform:uppercase; letter-spacing:0.5px;">Aktives Liegenschafts-Bankkonto:</div>
        <div style="font-size:15px; font-weight:800; color:#0f172a; margin-top:2px;">
          <?= htmlspecialchars($activeAcc['name']) ?> 
          <span style="font-size:13px; font-weight:600; color:#475569;">(<?= htmlspecialchars($activeAcc['bank'] ?: 'Bank') ?>)</span>
        </div>
        <div style="font-size:13px; color:#334155; font-family:monospace; margin-top:3px;">
          IBAN: <strong><?= htmlspecialchars($activeAcc['iban'] ? chunk_split($activeAcc['iban'], 4, ' ') : '— Noch keine IBAN hinterlegt —') ?></strong>
          <?php if (!empty($activeAcc['projekt_name'])): ?>
            <span style="color:#64748b; font-family:sans-serif; margin-left:12px;">🏠 Liegenschaft: <?= htmlspecialchars($activeAcc['projekt_name']) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($projektId): ?>
        <div style="font-size:11px; font-weight:700; color:#eab308; text-transform:uppercase;">Liegenschaft ohne hinterlegtes Bankkonto:</div>
        <div style="font-size:14px; font-weight:700; color:#0f172a; margin-top:2px;">
          🏠 <?= htmlspecialchars($projById[$projektId] ?? 'Liegenschaft #'.$projektId) ?>
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:2px;">
          Für diese Liegenschaft wurde in <em>kv_konten</em> noch kein Bankkonto eingetragen.
        </div>
      <?php else: ?>
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">Liegenschafts-Auswahl:</div>
        <div style="font-size:14px; font-weight:700; color:#0f172a; margin-top:2px;">
          🌐 Alle Liegenschaften &amp; Konten (Übersicht)
        </div>
        <div style="font-size:12px; color:#64748b; margin-top:2px;">
          Wähle unten ein Projekt oder ein Konto, um gezielt die Buchungen einer einzelnen Liegenschaft zu prüfen.
        </div>
      <?php endif; ?>
    </div>
    <div>
      <button type="button" class="btn secondary" onclick="openKontenModal()" style="font-size:12px; padding:6px 12px; border-radius:6px;">
        ⚙️ Kontodaten bearbeiten
      </button>
    </div>
  </div>

  <!-- Jahres-Navigation & Jahres-Tabs Bar -->
  <div class="kv-year-tabs" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px 16px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <span style="font-weight:700; font-size:13px; color:#475569; display:flex; align-items:center; gap:4px;">
        📅 Jahr:
      </span>
      <?php foreach ($availableYears as $yr): 
        $isActiveYr = ($selectedYear === $yr);
      ?>
        <a href="<?= htmlspecialchars(buildKontoUrl(['jahr' => $yr, 'monat' => null])) ?>" 
           style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; transition:all 0.15s ease; <?= $isActiveYr ? 'background:#2563eb; color:#fff; box-shadow:0 2px 4px rgba(37,99,235,0.3);' : 'background:#f1f5f9; color:#475569;' ?>">
          <?= $yr ?>
        </a>
      <?php endforeach; ?>
      <a href="<?= htmlspecialchars(buildKontoUrl(['jahr' => 'all', 'monat' => null])) ?>" 
         style="display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; transition:all 0.15s ease; <?= ($selectedYear === 0) ? 'background:#0f172a; color:#fff;' : 'background:#f1f5f9; color:#475569;' ?>">
        🌐 Alle Jahre
      </a>
    </div>

    <?php if ($selectedYear > 0): ?>
      <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
        <span style="font-size:12px; font-weight:600; color:#64748b; margin-right:4px;">Monat:</span>
        <a href="<?= htmlspecialchars(buildKontoUrl(['monat' => 'all'])) ?>" 
           style="padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; text-decoration:none; <?= ($selectedMonth === 0) ? 'background:#3b82f6; color:#fff;' : 'background:#f8fafc; color:#64748b; border:1px solid #e2e8f0;' ?>">
          Alle
        </a>
        <?php for ($m = 1; $m <= 12; $m++): 
          $isActiveM = ($selectedMonth === $m);
          $hasData = isset($monthlySummary[$m]) && $monthlySummary[$m]['cnt'] > 0;
        ?>
          <a href="<?= htmlspecialchars(buildKontoUrl(['monat' => $m])) ?>" 
             style="padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; text-decoration:none; <?= $isActiveM ? 'background:#2563eb; color:#fff;' : ($hasData ? 'background:#e0f2fe; color:#0369a1;' : 'background:#f8fafc; color:#94a3b8;') ?>">
            <?= $monthShort[$m] ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Stats + KPIs Kopf (pro gewählte Periode) -->
  <section class="kv-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.8rem;margin:12px 0 16px;">
    <div class="stat" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 2px 4px rgba(0,0,0,0.02);">
      <div class="stat-label" style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase;">
        <?= $selectedYear ? 'Buchungen ' . $selectedYear : 'Buchungen Gesamt' ?>
      </div>
      <div class="stat-value" style="font-size:22px;font-weight:800;color:#0f172a;margin-top:4px;">
        <?= number_format($totalMatch, 0, ',', "'") ?> <span style="font-size:12px;color:#94a3b8;font-weight:500;">/ <?= number_format($statTotal, 0, ',', "'") ?></span>
      </div>
    </div>

    <div class="stat" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 2px 4px rgba(0,0,0,0.02);">
      <div class="stat-label" style="font-size:12px;color:#16a34a;font-weight:700;text-transform:uppercase;">Einnahmen (+)</div>
      <div class="stat-value" style="font-size:22px;font-weight:800;color:#16a34a;margin-top:4px;">
        +<?= number_format($totalEinnahmen, 2, '.', "'") ?> <span style="font-size:12px;font-weight:500;">CHF</span>
      </div>
    </div>

    <div class="stat" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 2px 4px rgba(0,0,0,0.02);">
      <div class="stat-label" style="font-size:12px;color:#dc2626;font-weight:700;text-transform:uppercase;">Ausgaben (-)</div>
      <div class="stat-value" style="font-size:22px;font-weight:800;color:#dc2626;margin-top:4px;">
        <?= number_format($totalAusgaben, 2, '.', "'") ?> <span style="font-size:12px;font-weight:500;">CHF</span>
      </div>
    </div>

    <div class="stat" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 2px 4px rgba(0,0,0,0.02);">
      <div class="stat-label" style="font-size:12px;color:#64748b;font-weight:700;text-transform:uppercase;">Saldo <?= $selectedYear ?: 'Periode' ?></div>
      <div class="stat-value" style="font-size:22px;font-weight:800;color:<?= $totalSum >= 0 ? '#16a34a' : '#dc2626' ?>;margin-top:4px;">
        <?= ($totalSum >= 0 ? '+' : '') . number_format($totalSum, 2, '.', "'") ?> <span style="font-size:12px;font-weight:500;">CHF</span>
      </div>
    </div>

    <a href="<?= htmlspecialchars(buildKontoUrl(['match_status' => 'matched'])) ?>" class="stat" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px 16px;text-decoration:none;display:block;">
      <div class="stat-label" style="font-size:12px;color:#166534;font-weight:700;text-transform:uppercase;">🟢 Zugeordnet</div>
      <div class="stat-value" style="font-size:22px;font-weight:800;color:#15803d;margin-top:4px;">
        <?= number_format($statMatched, 0, ',', "'") ?> <span style="font-size:12px;font-weight:500;">Buchungen</span>
      </div>
    </a>

    <a href="<?= htmlspecialchars(buildKontoUrl(['match_status' => 'unmatched'])) ?>" class="stat" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px 16px;text-decoration:none;display:block;">
      <div class="stat-label" style="font-size:12px;color:#991b1b;font-weight:700;text-transform:uppercase;">🔴 Unzugeordnet (Offen)</div>
      <div class="stat-value" style="font-size:22px;font-weight:800;color:#dc2626;margin-top:4px;">
        <?= number_format($statUnmatched, 0, ',', "'") ?> <span style="font-size:12px;font-weight:500;">offen</span>
      </div>
    </a>
  </section>

  <!-- Aufklappbare Monats-Übersicht für das gewählte Jahr -->
  <?php if ($selectedYear > 0 && !empty($monthlySummary)): ?>
    <details style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; margin-bottom:16px;" <?= ($selectedMonth > 0) ? 'open' : '' ?>>
      <summary style="font-weight:700; font-size:14px; color:#334155; cursor:pointer; display:flex; align-items:center; justify-content:space-between;">
        <span>📊 Monatsübersicht für das Jahr <?= $selectedYear ?> (Einnahmen, Ausgaben &amp; Saldo je Monat)</span>
        <span style="font-size:12px; color:#64748b; font-weight:normal;">(Klicken zum Auf-/Zuklappen)</span>
      </summary>
      <div style="overflow-x:auto; margin-top:10px;">
        <table class="modern-table" style="width:100%; border-collapse:collapse; font-size:12px;">
          <thead>
            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
              <th style="padding:6px 10px; text-align:left;">Monat</th>
              <th style="padding:6px 10px; text-align:right; color:#16a34a;">Einnahmen (+)</th>
              <th style="padding:6px 10px; text-align:right; color:#dc2626;">Ausgaben (-)</th>
              <th style="padding:6px 10px; text-align:right;">Saldo</th>
              <th style="padding:6px 10px; text-align:center;">Buchungen</th>
              <th style="padding:6px 10px; text-align:center;">Zugeordnet</th>
              <th style="padding:6px 10px; text-align:center;">Offen</th>
              <th style="padding:6px 10px; text-align:center;">Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($m = 1; $m <= 12; $m++): 
              $mRow = $monthlySummary[$m] ?? ['cnt'=>0, 'einnahmen'=>0, 'ausgaben'=>0, 'saldo'=>0, 'matched_cnt'=>0, 'open_cnt'=>0];
              $isCurM = ($selectedMonth === $m);
            ?>
              <tr style="border-bottom:1px solid #f1f5f9; <?= $isCurM ? 'background:#eff6ff;' : '' ?>">
                <td style="padding:6px 10px; font-weight:700;">
                  <?= $monthNames[$m] ?> <?= $selectedYear ?>
                </td>
                <td style="padding:6px 10px; text-align:right; color:#16a34a; font-weight:600;">
                  <?= $mRow['einnahmen'] > 0 ? ('+' . number_format((float)$mRow['einnahmen'], 2, '.', "'")) : '—' ?>
                </td>
                <td style="padding:6px 10px; text-align:right; color:#dc2626; font-weight:600;">
                  <?= $mRow['ausgaben'] < 0 ? number_format((float)$mRow['ausgaben'], 2, '.', "'") : '—' ?>
                </td>
                <td style="padding:6px 10px; text-align:right; font-weight:700; color:<?= (float)$mRow['saldo'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
                  <?= ((float)$mRow['saldo'] >= 0 ? '+' : '') . number_format((float)$mRow['saldo'], 2, '.', "'") ?>
                </td>
                <td style="padding:6px 10px; text-align:center; font-weight:600; color:#475569;">
                  <?= (int)$mRow['cnt'] ?>
                </td>
                <td style="padding:6px 10px; text-align:center;">
                  <span style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:700;">
                    <?= (int)$mRow['matched_cnt'] ?>
                  </span>
                </td>
                <td style="padding:6px 10px; text-align:center;">
                  <?php if ((int)$mRow['open_cnt'] > 0): ?>
                    <span style="background:#fee2e2; color:#991b1b; padding:2px 6px; border-radius:4px; font-size:11px; font-weight:700;">
                      <?= (int)$mRow['open_cnt'] ?> offen
                    </span>
                  <?php else: ?>
                    <span style="color:#94a3b8;">0</span>
                  <?php endif; ?>
                </td>
                <td style="padding:6px 10px; text-align:center;">
                  <a href="<?= htmlspecialchars(buildKontoUrl(['monat' => $m])) ?>" class="btn" style="padding:2px 8px; font-size:11px; background:#e0f2fe; color:#0369a1; border:none; border-radius:4px; text-decoration:none;">
                    Filtern
                  </a>
                </td>
              </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endif; ?>

  <!-- Filter -->
  <form class="filter-box" method="get" action="" style="background:#f9fafc;border:1px solid #e7e9ef;border-radius:12px;padding:12px;margin:10px 0">
    <input type="hidden" name="jahr" value="<?= htmlspecialchars((string)($selectedYear ?: 'all')) ?>">
    <?php if ($selectedMonth > 0): ?>
      <input type="hidden" name="monat" value="<?= $selectedMonth ?>">
    <?php endif; ?>

    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(140px,1fr));gap:.6rem">
      <div>
        <label for="projekt_id" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Liegenschaft / Projekt</label>
        <select id="projekt_id" name="projekt_id" style="width:100%;" onchange="this.form.submit()">
          <option value="">-- Alle Liegenschaften --</option>
          <?php foreach ($projekte as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ($projektId == (int)$p['id'] ? 'selected' : '') ?>>
              <?= htmlspecialchars($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="konto_id" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Bankkonto</label>
        <select id="konto_id" name="konto_id" style="width:100%;" onchange="this.form.submit()">
          <option value="">-- Alle Konten --</option>
          <?php foreach ($allKonten as $ak): ?>
            <option value="<?= (int)$ak['id'] ?>" <?= ($kontoId == (int)$ak['id'] ? 'selected' : '') ?>>
              <?= htmlspecialchars($ak['name'] . ($ak['iban'] ? ' (' . substr($ak['iban'], -8) . ')' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="datum_von" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Datum von</label>
        <input id="datum_von" type="date" name="datum_von" value="<?= htmlspecialchars($datumVon) ?>" style="width:100%;">
      </div>

      <div>
        <label for="datum_bis" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Datum bis</label>
        <input id="datum_bis" type="date" name="datum_bis" value="<?= htmlspecialchars($datumBis) ?>" style="width:100%;">
      </div>

      <div>
        <label for="match_status" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Zuordnungs-Status</label>
        <select id="match_status" name="match_status" style="width:100%;">
          <option value="all">-- Alle Buchungen --</option>
          <option value="unmatched" <?= $matchStatus==='unmatched'?'selected':'' ?>>🔴 Unzugeordnet (offen)</option>
          <option value="matched" <?= $matchStatus==='matched'?'selected':'' ?>>🟢 Zugeordnet</option>
        </select>
      </div>

      <div>
        <label for="beschreibung" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Textsuche</label>
        <input id="beschreibung" type="text" name="beschreibung" placeholder="z.B. Miete, Name..." value="<?= htmlspecialchars($suchtext) ?>" style="width:100%;">
      </div>

      <div>
        <label for="sort" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Sortieren nach</label>
        <select id="sort" name="sort" style="width:100%;">
          <?php foreach ($sortable as $col): ?>
            <option value="<?= htmlspecialchars($col) ?>" <?= $sort === $col ? 'selected' : '' ?>>
              <?= htmlspecialchars($col) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="dir" style="display:block; font-size:12px; font-weight:700; margin-bottom:3px;">Reihenfolge</label>
        <select id="dir" name="dir" style="width:100%;">
          <option value="asc"  <?= $dir==='asc'?'selected':'' ?>>aufsteigend</option>
          <option value="desc" <?= $dir==='desc'?'selected':'' ?>>absteigend</option>
      </div>
    </div>

    <?php if (!empty($descOptions)): ?>
      <fieldset class="cat-fieldset" style="margin-top:.6rem">
        <legend>Beschreibungen (Mehrfachwahl)</legend>
        <label class="checkbox-inline"><input type="checkbox" id="desc_all"> alle/keine</label>
        <div class="cat-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.4rem;margin-top:.4rem;max-height:340px;overflow:auto;">
          <?php foreach ($descOptions as $b):
              $checked = in_array($b, $selectedDescs, true) ? 'checked' : '';
          ?>
            <label class="checkbox-inline">
              <input type="checkbox" name="beschreibungen[]" value="<?= htmlspecialchars($b) ?>" <?= $checked ?>>
              <?= htmlspecialchars($b) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endif; ?>

    <label class="checkbox-inline" style="margin-top:.5rem;display:inline-flex;gap:.4rem;align-items:center;">
      <input type="checkbox" name="split" value="1" <?= $splitByDescs ? 'checked' : '' ?>>
      Beschreibungen unten einzeln auflisten
    </label>

    <div class="actions" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
      <button type="submit" class="btn">🔍 Filtern</button>
      <a class="btn secondary" href="<?= htmlspecialchars(url('tools/konto_verwaltung/index.php')) ?>">⟲ Zurücksetzen</a>
    </div>
  </form>

  <!-- Toolbar: Batch-Aktionen -->
  <div class="toolbar" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:10px 0">
    <form method="post">
      <input type="hidden" name="do" value="set_cat">
      <span class="muted">Auswahl:</span>
      <input type="text" name="new_cat" placeholder="Neue Kategorie">
      <button class="btn" type="submit">🏷️ Kategorie setzen</button>
      <div id="ids_holder_cat"></div>
    </form>

    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
      <input type="hidden" name="do" value="assign_proj_whg">
      <select name="assign_wohnung_id" style="max-width:240px;">
        <option value="0">-- Wohnung zuweisen --</option>
        <?php foreach ($allWohnungen as $aw): ?>
          <option value="<?= (int)$aw['id'] ?>">
            <?= htmlspecialchars($aw['projekt_name'] . ' ➔ ' . $aw['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="assign_mieter_id" style="max-width:200px;">
        <option value="0">-- Mieter zuweisen --</option>
        <?php foreach ($allMieter as $am): 
          $dName = trim($am['mieter_name'] ?: $am['benutzer_name']);
        ?>
          <option value="<?= (int)$am['benutzer_id'] ?>">
            <?= htmlspecialchars($dName) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button class="btn" type="submit" style="background:#059669;color:#fff;">📌 Für Auswahl speichern</button>
      <div id="ids_holder_assign"></div>
    </form>

    <form method="post" onsubmit="return confirm('Ausgewählte Einträge löschen?')">
      <input type="hidden" name="do" value="delete_rows">
      <button class="btn danger" type="submit">🗑️ Löschen</button>
      <div id="ids_holder_del"></div>
    </form>
  </div>

  <!-- Schnell-Zuordnung -->
  <div class="kv-zuordnung card" style="padding:12px;background:#f9fafc;border:1px solid #e7e9ef;border-radius:12px;">
    <h3>Schnell-Zuordnung: Zahler → Projekt/Wohnung</h3>
    <form method="post">
      <div class="grid" style="display:grid;grid-template-columns:repeat(4,minmax(200px,1fr));gap:.6rem">
        <label for="assign_name">Name enthält (oder Regex)</label>
        <input id="assign_name" type="text" name="assign_name" placeholder="z.B. Aeschlimann|Ramdedovic (Regex)">

        <label for="assign_projekt_id">Projekt setzen auf</label>
        <select id="assign_projekt_id" name="assign_projekt_id">
          <option value="0">-- nicht ändern --</option>
          <?php foreach ($projekte as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <?php if ($hasWohnLbl): ?>
          <label for="assign_wohnung">Wohnung/Einheit (frei)</label>
          <input id="assign_wohnung" type="text" name="assign_wohnung" placeholder="z.B. Whg 2.OG rechts">
        <?php endif; ?>

        <label class="checkbox-inline" style="margin-top:1.7rem;">
          <input type="checkbox" name="assign_scope" value="1" checked>
          Nur aktuelle Filter berücksichtigen
        </label>

        <label class="checkbox-inline" style="margin-top:1.7rem;">
          <input type="checkbox" name="assign_regex" value="1">
          Als Regex behandeln
        </label>
      </div>

      <div class="actions" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem">
        <button class="btn" type="submit" name="apply_assignment" value="1">💾 Zuordnung anwenden</button>
      </div>
    </form>

    <?php if ($suggestions): ?>
      <details style="margin-top:.5rem;">
        <summary>Beschreibungen (Top 30 aus Treffern) auswählen → in „Name enthält“ übernehmen</summary>
        <div class="sug-list" style="margin:.5rem 0;">
          <?php foreach ($suggestions as $s): ?>
            <label class="checkbox-inline" style="display:block;">
              <input type="checkbox" class="sug-check" value="<?= htmlspecialchars($s['beschreibung']) ?>">
              <?= htmlspecialchars($s['beschreibung']) ?>
              <span class="muted"> (<?= (int)$s['c'] ?>× / <?= number_format((float)$s['s'], 2, ',', ' ') ?>)</span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn secondary" id="sug_to_name" type="button">Auswahl → „Name enthält“ (Regex)</button>
      </details>
    <?php endif; ?>
  </div>

  <?php
  // Ausgabe: entweder normal (eine Tabelle) oder pro Beschreibung getrennt
  if ($splitByDescs && $selectedDescs) {
      // Pro Beschreibung Abschnitt (nur aus aktueller Seite)
      $byDesc = [];
      foreach ($rows as $r) {
          $b = $r['beschreibung'] ?? '';
          if ($b !== '' && in_array($b, $selectedDescs, true)) {
              $byDesc[$b][] = $r;
          }
      }
      if (!$byDesc) {
          echo '<p><em>Keine Ergebnisse.</em></p>';
      } else {
          foreach ($selectedDescs as $descName) {
              $list = $byDesc[$descName] ?? [];
              if (!$list) continue;

              // Summen pro Beschreibung
              $sum = 0.0;
              foreach ($list as $r) $sum += (float)$r['betrag'];
              ?>
              <h3 style="margin-top:1.2rem;">Beschreibung: <?= htmlspecialchars($descName) ?> (<?= count($list) ?> / <?= number_format($sum,2,',',' ') ?>)</h3>
              <form class="selectable">
                <table class="modern-table">
                  <thead>
                    <tr>
                      <th><input type="checkbox" class="select_all"></th>
                      <th>ID</th>
                      <th>Projekt-ID</th>
                      <th>Datum</th>
                      <th style="text-align:right;">Betrag</th>
                      <th>Beschreibung</th>
                      <th>Kategorie</th>
                      <?php if ($hasWohnLbl): ?><th>Wohnung</th><?php endif; ?>
                      <th>Zahlungsart</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($list as $r): ?>
                    <tr>
                      <td><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= (int)$r['liegenschaft_id'] ?></td>
                      <td><?= htmlspecialchars($r['buchungsdatum']) ?></td>
                      <td style="text-align:right;"><?= number_format((float)$r['betrag'], 2, ',', ' ') ?></td>
                      <td><?= htmlspecialchars($r['beschreibung']) ?></td>
                      <td><?= htmlspecialchars($r['kategorie']) ?></td>
                      <?php if ($hasWohnLbl): ?><td><?= htmlspecialchars($r['wohnung_label'] ?? '') ?></td><?php endif; ?>
                      <td><?= htmlspecialchars($r['zahlungsart']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </form>
              <?php
          }
      }
  } else {
      // Normale Gesamttabelle (aktuelle Seite)
      if ($rows): ?>
        <form class="selectable">
          <table class="modern-table" style="width:100%; border-collapse:collapse;">
            <thead>
              <tr style="background:#0f172a; color:#fff;">
                <th style="width:30px;"><input type="checkbox" class="select_all"></th>
                <th style="width:50px;">ID</th>
                <th style="width:100px;">Datum</th>
                <th>Liegenschaft</th>
                <th style="text-align:right; width:130px;">Betrag</th>
                <th>Buchungstext / Beschreibung</th>
                <th style="width:100px;">Kategorie</th>
                <th style="width:150px;">Wohnung</th>
                <th style="width:180px;">Mieter</th>
                <th style="width:90px; text-align:center;">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): 
                $betrag = (float)$r['betrag'];
                $isPos = $betrag >= 0;
              ?>
                <tr id="row_<?= (int)$r['id'] ?>" style="border-bottom:1px solid #f1f5f9;">
                  <td><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
                  <td style="color:#64748b; font-size:12px;">#<?= (int)$r['id'] ?></td>
                  <td style="white-space:nowrap; font-size:13px;"><?= htmlspecialchars(date('d.m.Y', strtotime($r['buchungsdatum']))) ?></td>
                  <td>
                    <span style="font-size:12px; font-weight:600; color:#475569;">
                      <?= htmlspecialchars($r['projekt_name'] ?: ($r['liegenschaft_id'] ? 'Liegenschaft #'.$r['liegenschaft_id'] : '—')) ?>
                    </span>
                  </td>
                  <td style="text-align:right; font-weight:700; color:<?= $isPos ? '#16a34a' : '#dc2626' ?>; white-space:nowrap;">
                    <?= ($isPos ? '+' : '') . number_format($betrag, 2, '.', "'") ?> CHF
                  </td>
                  <td style="max-width:320px; word-break:break-word; font-size:13px;" title="<?= htmlspecialchars($r['beschreibung']) ?>">
                    <?= htmlspecialchars($r['beschreibung']) ?>
                  </td>
                  <td>
                    <span style="background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600;">
                      <?= htmlspecialchars($r['kategorie'] ?: 'Miete') ?>
                    </span>
                  </td>
                  <td>
                    <?php if (!empty($r['wohnung_id']) && !empty($r['wohnung_name'])): ?>
                      <span style="display:inline-flex; align-items:center; gap:4px; background:#e0f2fe; color:#0369a1; padding:3px 8px; border-radius:6px; font-weight:700; font-size:12px;">
                        🏠 <?= htmlspecialchars($r['wohnung_name']) ?>
                      </span>
                    <?php elseif (!empty($r['wohnung_label'])): ?>
                      <span style="display:inline-flex; align-items:center; gap:4px; background:#f0fdf4; color:#15803d; padding:3px 8px; border-radius:6px; font-weight:600; font-size:12px;">
                        🏠 <?= htmlspecialchars($r['wohnung_label']) ?>
                      </span>
                    <?php else: ?>
                      <span style="color:#94a3b8; font-size:12px; font-style:italic;">— offen —</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($r['mieter_id']) || (!empty($r['mieter_display_name']) && $r['mieter_display_name'] !== $r['wohnung_label'])): ?>
                      <span style="display:inline-flex; align-items:center; gap:4px; background:#dcfce7; color:#166534; padding:3px 8px; border-radius:6px; font-weight:700; font-size:12px;">
                        👤 <?= htmlspecialchars($r['mieter_display_name']) ?>
                      </span>
                    <?php else: ?>
                      <span style="color:#ef4444; font-size:12px; font-weight:600;">🔴 Offen</span>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:center;">
                    <button type="button" class="btn" style="padding:4px 10px; font-size:11px; background:#3b82f6; color:#fff; border:none; border-radius:6px; cursor:pointer;"
                            onclick="openAssignModal(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['beschreibung'])) ?>', <?= (float)$r['betrag'] ?>, <?= (int)($r['wohnung_id'] ?: 0) ?>, <?= (int)($r['mieter_id'] ?: 0) ?>)">
                      📌 Zuweisen
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      <?php else: ?>
        <p><em>Keine Ergebnisse.</em></p>
      <?php endif;
  }
  ?>

  <!-- Pagination unten -->
  <div class="actions" style="display:flex;gap:.5rem;flex-wrap:wrap;justify-content:flex-end;margin-top:.5rem;">
    <?php
      $pages = max(1, (int)ceil($totalMatch/$perPage));
      $build = function($p){
        $qs = $_GET; $qs['page']=$p;
        return url('tools/konto_verwaltung/index.php') . '?' . http_build_query($qs);
      };
    ?>
    <a class="btn secondary" href="<?= htmlspecialchars($build(1)) ?>">⏮️</a>
    <a class="btn secondary" href="<?= htmlspecialchars($build(max(1,$page-1))) ?>">◀️</a>
    <span class="muted" style="align-self:center;">Seite <?= $page ?> von <?= $pages ?></span>
    <a class="btn secondary" href="<?= htmlspecialchars($build(min($pages,$page+1))) ?>">▶️</a>
    <a class="btn secondary" href="<?= htmlspecialchars($build($pages)) ?>">⏭️</a>
  </div>

  <div class="kv-footer-actions" style="margin-top:.75rem;">
    <a class="btn" href="<?= htmlspecialchars(url('tools/konto_verwaltung/import.php')) ?>">📂 CSV importieren</a>
  </div>
  
</div>

<!-- Modal für 1-Klick Einzelzuweisung -->
<div id="assignModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.55); backdrop-filter:blur(2px); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:14px; padding:24px; width:92%; max-width:540px; box-shadow:0 25px 30px -5px rgba(0,0,0,0.3); border:1px solid #e2e8f0;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
      <h3 style="margin:0; font-size:18px; color:#0f172a; display:flex; align-items:center; gap:8px;">
        📌 Buchung zuweisen
      </h3>
      <button type="button" onclick="closeAssignModal()" style="background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer; padding:0 4px;">&times;</button>
    </div>
    
    <div id="assignModalDesc" style="font-size:13px; color:#475569; background:#f8fafc; border-left:3px solid #3b82f6; padding:10px 12px; border-radius:6px; margin-bottom:16px; word-break:break-word; max-height:80px; overflow-y:auto;"></div>
    
    <form method="post" id="assignModalForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_single">
      <input type="hidden" name="konto_id" id="modal_konto_id" value="">
      
      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:5px;">Wohnung / Einheit:</label>
        <select name="wohnung_id" id="modal_wohnung_id" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff;" onchange="autoSelectTenantForUnit(this.value)">
          <option value="0">-- Keine / Offen --</option>
          <?php foreach ($allWohnungen as $aw): ?>
            <option value="<?= (int)$aw['id'] ?>">
              <?= htmlspecialchars($aw['projekt_name'] . ' ➔ ' . $aw['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-bottom:20px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:5px;">Mieter (Benutzer):</label>
        <select name="mieter_id" id="modal_mieter_id" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff;">
          <option value="0">-- Kein Mieter zugeordnet --</option>
          <?php foreach ($allMieter as $am): 
            $bId = (int)($am['benutzer_id'] ?: 0);
            $dName = trim($am['mieter_name'] ?: ($am['benutzer_name'] ?: 'Mieter #'.$am['wm_id']));
          ?>
            <option value="<?= $bId ?>" data-wid="<?= (int)$am['wohnung_id'] ?>">
              <?= htmlspecialchars($dName) ?><?= $bId > 0 ? '' : ' (Kein Benutzer-Account)' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px; color:#64748b; margin-top:4px;">💡 Bei Auswahl einer Wohnung wird der hinterlegte Mieter automatisch vorausgewählt.</div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px;">
        <button type="button" class="btn secondary" onclick="closeAssignModal()" style="padding:8px 16px; border-radius:8px;">Abbrechen</button>
        <button type="submit" class="btn" style="background:#2563eb; color:#fff; padding:8px 18px; border-radius:8px; font-weight:600; border:none; cursor:pointer;">💾 Speichern</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Bankkonten je Liegenschaft verwalten -->
<div id="kontenModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.55); backdrop-filter:blur(2px); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:14px; padding:24px; width:92%; max-width:820px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 30px -5px rgba(0,0,0,0.3); border:1px solid #e2e8f0;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
      <div>
        <h3 style="margin:0 0 4px 0; font-size:18px; color:#0f172a; display:flex; align-items:center; gap:8px;">
          🏛️ Bankkonten der Liegenschaften
        </h3>
        <p style="margin:0; font-size:13px; color:#64748b;">
          Jede Liegenschaft hat ihr eigenes Bankkonto. Hier können IBAN, Bankname und Kontobezeichnung gepflegt werden.
        </p>
      </div>
      <button type="button" onclick="closeKontenModal()" style="background:none; border:none; font-size:24px; color:#94a3b8; cursor:pointer; padding:0 4px;">&times;</button>
    </div>

    <div style="overflow-x:auto; margin-top:12px;">
      <table class="modern-table" style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
            <th style="padding:8px 10px; text-align:left;">Liegenschaft</th>
            <th style="padding:8px 10px; text-align:left;">Kontoname</th>
            <th style="padding:8px 10px; text-align:left;">Bank</th>
            <th style="padding:8px 10px; text-align:left;">IBAN</th>
            <th style="padding:8px 10px; text-align:center;">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projekte as $p): 
            $pid = (int)$p['id'];
            $pAcc = $kontenByProj[$pid] ?? null;
          ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_single_konto">
                <input type="hidden" name="projekt_id" value="<?= $pid ?>">
                <input type="hidden" name="konto_id" value="<?= (int)($pAcc['id'] ?? 0) ?>">
                <td style="padding:8px 10px; font-weight:600; color:#334155; white-space:nowrap;">
                  <?= htmlspecialchars($p['name']) ?>
                </td>
                <td style="padding:8px 10px;">
                  <input type="text" name="konto_name" value="<?= htmlspecialchars($pAcc['name'] ?? ('Mietkonto ' . preg_replace('/^\d+_/', '', $p['name']))) ?>" style="width:100%; min-width:160px; padding:6px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
                </td>
                <td style="padding:8px 10px;">
                  <input type="text" name="bank" value="<?= htmlspecialchars($pAcc['bank'] ?? 'Raiffeisen') ?>" style="width:100%; min-width:90px; padding:6px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
                </td>
                <td style="padding:8px 10px;">
                  <input type="text" name="iban" value="<?= htmlspecialchars($pAcc['iban'] ?? '') ?>" placeholder="CH..." style="width:100%; min-width:180px; padding:6px 8px; font-size:12px; font-family:monospace; border:1px solid #cbd5e1; border-radius:6px;">
                </td>
                <td style="padding:8px 10px; text-align:center;">
                  <button type="submit" class="btn" style="padding:6px 12px; font-size:11px; background:#059669; color:#fff; border:none; border-radius:6px; cursor:pointer; white-space:nowrap;">
                    💾 Speichern
                  </button>
                </td>
              </form>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex; justify-content:flex-end; margin-top:20px;">
      <button type="button" class="btn secondary" onclick="closeKontenModal()" style="padding:8px 18px; border-radius:8px;">Schließen</button>
    </div>
  </div>
</div>

<script>
// Mapping Wohnung -> Mieter Benutzer-ID
const unitToTenantMap = <?= json_encode(array_reduce($allMieter, function($acc, $m){
  if (!empty($m['wohnung_id']) && !empty($m['benutzer_id'])) {
    $acc[$m['wohnung_id']] = (int)$m['benutzer_id'];
  }
  return $acc;
}, [])) ?>;

function openAssignModal(id, desc, amount, wid, mid) {
  document.getElementById('modal_konto_id').value = id;
  const prefix = (amount >= 0 ? '+' : '');
  document.getElementById('assignModalDesc').innerHTML = '<strong>Buchung #' + id + ' (' + prefix + Number(amount).toLocaleString('de-CH', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' CHF):</strong><br>' + desc;
  document.getElementById('modal_wohnung_id').value = wid || 0;
  document.getElementById('modal_mieter_id').value = mid || 0;
  const modal = document.getElementById('assignModal');
  modal.style.display = 'flex';
}

function closeAssignModal() {
  document.getElementById('assignModal').style.display = 'none';
}

function openKontenModal() {
  const modal = document.getElementById('kontenModal');
  if (modal) modal.style.display = 'flex';
}

function closeKontenModal() {
  const modal = document.getElementById('kontenModal');
  if (modal) modal.style.display = 'none';
}

function autoSelectTenantForUnit(wid) {
  if (unitToTenantMap[wid]) {
    document.getElementById('modal_mieter_id').value = unitToTenantMap[wid];
  }
}

// "alle/keine" für Beschreibungen
document.getElementById('desc_all')?.addEventListener('change', function(){
  document.querySelectorAll('input[name="beschreibungen[]"]').forEach(cb => cb.checked = this.checked);
});

// Auswahl sammeln & in Toolbar-Formulare spiegeln
function gatherSelected(){
  const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(cb=>cb.value);
  const holders = ['ids_holder_cat','ids_holder_assign','ids_holder_del'];
  holders.forEach(id=>{
    const box = document.getElementById(id);
    if (!box) return;
    box.innerHTML = '';
    ids.forEach(v=>{
      const i = document.createElement('input');
      i.type='hidden'; i.name='ids[]'; i.value=v;
      box.appendChild(i);
    });
  });
}
document.addEventListener('change', (e)=>{
  if (e.target.matches('.row-check')) gatherSelected();
  if (e.target.matches('.select_all')) {
    const table = e.target.closest('table');
    table?.querySelectorAll('.row-check').forEach(cb => cb.checked = e.target.checked);
    gatherSelected();
  }
});

// Vorschläge → assign_name (Regex)
document.getElementById('sug_to_name')?.addEventListener('click', function(){
  const checks = Array.from(document.querySelectorAll('.sug-check:checked'));
  if (!checks.length) { alert('Bitte mindestens eine Beschreibung auswählen.'); return; }
  const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = checks.map(c => esc(c.value)).join('|');
  const f = document.getElementById('assign_name');
  f.value = pattern;
  const rx = document.querySelector('input[name="assign_regex"]');
  if (rx) rx.checked = true;
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
