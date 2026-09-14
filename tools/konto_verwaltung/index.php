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
require_once __DIR__ . '/../../includes/functions.php'; // url(), site_prefix()

/* ===== Layout / Nav ===== */
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/nav_dispatch.php';
require_once __DIR__ . '/../../includes/csrf.php';

global $mysqli, $db;
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  if (isset($GLOBALS['mysqli']) && ($GLOBALS['mysqli'] instanceof mysqli)) {
    $mysqli = $GLOBALS['mysqli'];
  } elseif (isset($GLOBALS['db']) && ($GLOBALS['db'] instanceof mysqli)) {
    $mysqli = $GLOBALS['db'];
  } elseif (isset($db) && ($db instanceof mysqli)) {
    $mysqli = $db;
  }
}

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

// ==== KONTEXT (GET / SESSION) ===============================================
$isPortfolioOverview = (isset($_GET['projekt_id']) && $_GET['projekt_id'] === 'all');
if ($isPortfolioOverview) {
  $ctx_projekt_id = 0;
  $projektId      = null;
} elseif (isset($_GET['projekt_id']) && (int)$_GET['projekt_id'] > 0) {
  $ctx_projekt_id = (int)$_GET['projekt_id'];
  $projektId      = $ctx_projekt_id;
  $_SESSION['current_project_id'] = $projektId;
} elseif (!empty($_SESSION['current_project_id']) && (int)$_SESSION['current_project_id'] > 0) {
  $ctx_projekt_id = (int)$_SESSION['current_project_id'];
  $projektId      = $ctx_projekt_id;
} else {
  // Standardmäßig: Projekt 3 (Romanshorn) wo Buchungen vorhanden sind
  $ctx_projekt_id = 3;
  $projektId      = 3;
  $_SESSION['current_project_id'] = 3;
}
$GLOBALS['current_active_pid'] = $projektId;
$GLOBALS['__projekt_id']       = $projektId;

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
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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

  // 4) Einzelne Buchung Wohnung & Mieter zuweisen mit Kategorie & Regel-Lernen
  if ($act === 'assign_single') {
    $kid = (int)($_POST['konto_id'] ?? 0);
    $wid = (int)($_POST['wohnung_id'] ?? 0);
    $mid = (int)($_POST['mieter_id'] ?? 0);
    $kat = trim($_POST['kategorie'] ?? '');
    $rememberRule = !empty($_POST['remember_rule']);

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

    if (empty($kat)) {
      $kat = ($wid > 0 || $mid > 0) ? 'Miete' : 'Unterhalt & Reparaturen';
    }

    $st = $mysqli->prepare("UPDATE liegenschafts_konto SET wohnung_id=?, mieter_id=?, wohnung_label=?, kategorie=? WHERE id=?");
    $st->bind_param("iissi", $wid, $mid, $wLbl, $kat, $kid);
    $st->execute();
    $st->close();

    // Regel für Folgejahre merken (Dauer-Regel für diesen Zahlungsabsender)
    if ($rememberRule && $kid > 0) {
      $bkRow = $mysqli->query("SELECT beschreibung, liegenschaft_id, projekt_id FROM liegenschafts_konto WHERE id = $kid")->fetch_assoc();
      if ($bkRow) {
        $bDesc = trim($bkRow['beschreibung']);
        $cleaned = preg_replace('/^(Gutschrift\s+|E-Banking\s+(Auftrag|Dauerauftrag)\s+(an\s+)?|Vergütung\s+|Belastung\s+)/i', '', $bDesc);
        $cleaned = trim($cleaned);
        $parts = preg_split('/[,\/]/', $cleaned);
        $rulePat = trim($parts[0]);
        if (mb_strlen($rulePat) >= 3) {
          $rPid = (int)($bkRow['projekt_id'] ?: ($bkRow['liegenschaft_id'] ?: $projektId));
          $stChk = $mysqli->prepare("SELECT id FROM konto_rules WHERE pattern = ? AND (liegenschaft_id = ? OR liegenschaft_id IS NULL OR liegenschaft_id = 0)");
          $stChk->bind_param("si", $rulePat, $rPid);
          $stChk->execute();
          $exRule = $stChk->get_result()->fetch_assoc();
          $stChk->close();

          if ($exRule) {
            $stUpd = $mysqli->prepare("UPDATE konto_rules SET wohnung_id=?, mieter_id=?, wohnung_label=?, set_kategorie=?, aktiv=1 WHERE id=?");
            $stUpd->bind_param("iissi", $wid, $mid, $wLbl, $kat, $exRule['id']);
            $stUpd->execute();
            $stUpd->close();
          } else {
            $stIns = $mysqli->prepare("INSERT INTO konto_rules (aktiv, priority, pattern, is_regex, liegenschaft_id, wohnung_id, mieter_id, wohnung_label, set_kategorie, note, created_at) VALUES (1, 10, ?, 0, ?, ?, ?, ?, ?, 'Gelernt aus Zuweisung', NOW())");
            $stIns->bind_param("siiisss", $rulePat, $rPid, $wid, $mid, $wLbl, $kat);
            $stIns->execute();
            $stIns->close();
          }
        }
      }
    }

    $kv_flash = "✅ Buchung #$kid erfolgreich zugewiesen" . ($rememberRule ? " und Dauer-Regel für Folgejahre gemerkt!" : ".");
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

// Alle Buchungsjahre aus liegenschafts_konto ermitteln (projektbezogen wenn Projekt aktiv)
$dbYears = [];
$yrSql = "SELECT DISTINCT YEAR(buchungsdatum) AS yr FROM liegenschafts_konto WHERE buchungsdatum IS NOT NULL AND buchungsdatum != '0000-00-00'";
if ($projektId) {
  $yrSql .= " AND (liegenschaft_id = $projektId OR projekt_id = $projektId)";
}
$yrSql .= " ORDER BY yr DESC";
$yrRes = $mysqli->query($yrSql);
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
$kontoId = isset($_GET['konto_id']) && $_GET['konto_id'] !== '' ? (int)$_GET['konto_id'] : null;

// Wenn konto_id gewählt ist, aber kein projekt_id, projekt_id ableiten
if ($kontoId && isset($kontenById[$kontoId]) && empty($projektId)) {
  $projektId = (int)($kontenById[$kontoId]['projekt_id'] ?? 0);
}

// Jahres-Auswahl (unterstützt Mehrfachauswahl via Checkboxen):
$selectedYears = [];
if (isset($_GET['jahre'])) {
  if (is_array($_GET['jahre'])) {
    $selectedYears = array_values(array_unique(array_filter(array_map('intval', $_GET['jahre']), fn($y) => $y > 0)));
  } elseif (is_string($_GET['jahre']) && trim($_GET['jahre']) !== '') {
    $parts = explode(',', $_GET['jahre']);
    $selectedYears = array_values(array_unique(array_filter(array_map('intval', $parts), fn($y) => $y > 0)));
  }
} elseif (isset($_GET['jahr'])) {
  if ($_GET['jahr'] === 'all' || $_GET['jahr'] === '0') {
    $selectedYears = []; // Alle Jahre
  } else {
    $y = (int)$_GET['jahr'];
    if ($y > 0) $selectedYears = [$y];
  }
} else {
  // Standardmäßig: das relevanteste Einzeljahr mit Buchungen für dieses Projekt (z.B. 2023), strikt jahr-spezifisch
  $defaultY = !empty($dbYears) ? $dbYears[0] : $currentCalYear;
  $selectedYears = [$defaultY];
}

// Wenn genau 1 Jahr gewählt ist, für Einzeljahr-Features merken:
$selectedYear = (count($selectedYears) === 1) ? $selectedYears[0] : 0;

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
    // Wenn nicht im Portfolio-Modus und kein projekt_id override, aktives projekt_id sichern
    if (!isset($overrides['projekt_id']) && !isset($q['projekt_id']) && !empty($GLOBALS['current_active_pid'])) {
      $q['projekt_id'] = $GLOBALS['current_active_pid'];
    }
    foreach ($overrides as $k => $v) {
      if ($v === null || $v === '') {
        unset($q[$k]);
      } else {
        $q[$k] = $v;
      }
    }
    if (isset($overrides['jahre']) || isset($overrides['jahr'])) {
      if (isset($overrides['jahre'])) unset($q['jahr']);
      if (isset($overrides['jahr'])) unset($q['jahre']);
      unset($q['page']);
    }
    if (isset($overrides['monat']) || isset($overrides['projekt_id']) || isset($overrides['konto_id']) || isset($overrides['match_status'])) {
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

if (!empty($selectedYears)) {
  $inY = implode(",", array_fill(0, count($selectedYears), "?"));
  $where[] = "YEAR(k.buchungsdatum) IN ($inY)";
  foreach ($selectedYears as $yVal) {
    $params[] = (int)$yVal;
    $types .= "i";
  }
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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
if (!empty($selectedYears)) {
  $inY = implode(",", array_fill(0, count($selectedYears), "?"));
  $mWhere[] = "YEAR(k.buchungsdatum) IN ($inY)";
  foreach ($selectedYears as $yVal) {
    $mParams[] = (int)$yVal;
    $mTypes .= "i";
  }
}

$mSql = "SELECT MONTH(k.buchungsdatum) AS m,
                COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN k.betrag > 0 THEN k.betrag ELSE 0 END),0) AS einnahmen,
                COALESCE(SUM(CASE WHEN k.betrag < 0 THEN k.betrag ELSE 0 END),0) AS ausgaben,
                COALESCE(SUM(k.betrag),0) AS saldo,
                COALESCE(SUM(CASE WHEN k.wohnung_id > 0 THEN 1 ELSE 0 END),0) AS matched_cnt,
                COALESCE(SUM(CASE WHEN (k.wohnung_id IS NULL OR k.wohnung_id = 0) AND k.betrag > 0 THEN 1 ELSE 0 END),0) AS open_cnt
         FROM liegenschafts_konto k"
       . ($mWhere ? " WHERE " . implode(" AND ", $mWhere) : "") . "
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

$cols = "k.id, k.liegenschaft_id, k.projekt_id, k.konto_id, k.buchungsdatum, k.betrag, k.beschreibung, k.kategorie, k.zahlungsart, k.wohnung_label, k.wohnung_id, k.mieter_id, 
         w.name as wohnung_name, p.name as projekt_name,
         COALESCE(NULLIF(k.konto_nr, ''), kk.iban, '') as konto_iban, COALESCE(kk.name, '') as konto_name, COALESCE(kk.bank, '') as konto_bank,
         COALESCE(NULLIF(wm.mieter_name,''), b.name, k.wohnung_label) as mieter_display_name";

$orderCol = ($sort === 'id') ? 'k.id' : (($sort === 'buchungsdatum') ? 'k.buchungsdatum' : 'k.' . $sort);
$listSql = "SELECT $cols FROM liegenschafts_konto k
            LEFT JOIN wohnungen w ON k.wohnung_id = w.id
            LEFT JOIN objekte o ON w.objekt_id = o.id
            LEFT JOIN projekte p ON (k.liegenschaft_id = p.id OR k.projekt_id = p.id OR o.projekt_id = p.id)
            LEFT JOIN kv_konten kk ON (k.konto_id = kk.id OR (k.konto_id IS NULL AND (k.liegenschaft_id = kk.liegenschaft_id OR k.projekt_id = kk.projekt_id)))
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
$wRes = $mysqli->query("SELECT w.id, w.name as wohnung_name, o.id as objekt_id, o.name as objekt_name, o.projekt_id, p.name AS projekt_name 
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
                        ORDER BY COALESCE(b.name, wm.mieter_name) ASC");
if ($mRes) {
  while ($rw = $mRes->fetch_assoc()) $allMieter[] = $rw;
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars(url('tools/konto_verwaltung/style.css')) ?>?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<div class="konto-container">
  <header class="kv-header">
    <div class="kv-header-title">
      <h2>🏦 Liegenschafts-Buchhaltung &amp; Bankkonto</h2>
      <p>Bankkonten je Liegenschaft &bull; Jahresansicht &bull; Zahlungsabgleich &bull; Mieter-Zuordnung</p>
    </div>
    <div class="kv-actions">
      <a class="btn btn-primary" href="<?= htmlspecialchars(url('tools/konto_verwaltung/import.php')) ?>">📂 CSV importieren</a>
      <button type="button" class="btn btn-teal" onclick="openKontenModal()">🏛️ Bankkonten verwalten</button>
      <form method="post" style="margin:0;display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="run_auto_match">
        <input type="hidden" name="filter_projekt_id" value="<?= (int)($projektId ?: 0) ?>">
        <button type="submit" class="btn btn-success" title="Durchsucht alle Buchungstexte nach bekannten Mietern und Wohnungen">
          ⚡ Auto-Match starten
        </button>
      </form>
      <a class="btn btn-indigo" href="<?= htmlspecialchars(url('tools/mietkontrolle/index.php') . '?' . http_build_query(['projekt_id' => $projektId, 'jahr' => $selectedYear ?: (!empty($selectedYears) ? $selectedYears[0] : date('Y'))])) ?>">
        💰 Zur Mietkontrolle (<?= $selectedYear ?: (!empty($selectedYears) ? $selectedYears[0] : date('Y')) ?>)
      </a>
      <a class="btn" href="<?= htmlspecialchars(url('tools/liegenschaftsabrechnung/index.php') . '?' . http_build_query(['projekt_id' => $projektId, 'jahr' => $selectedYear ?: (!empty($selectedYears) ? $selectedYears[0] : date('Y'))])) ?>" style="background:#7c3aed; color:#fff; border-color:#6d28d9; font-weight:600;">
        📑 Liegenschaftsabrechnung (<?= $selectedYear ?: (!empty($selectedYears) ? $selectedYears[0] : date('Y')) ?>)
      </a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(url('tools/konto_verwaltung/index.php')) ?>">⟲ Zurücksetzen</a>
    </div>
  </header>

  <?php if ($flash): ?>
    <div style="background:#f0fdf4; border-left:4px solid #16a34a; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-weight:600; color:#166534;">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($kv_flash)): ?>
    <div style="background:#f0fdf4; border-left:4px solid #16a34a; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-weight:600; color:#166534;">
      <?= htmlspecialchars($kv_flash) ?>
    </div>
  <?php endif; ?>

  <!-- Liegenschafts- und Bankkonto Hero-Card -->
  <?php 
    $activeAcc = null;
    if ($kontoId && isset($kontenById[$kontoId])) {
      $activeAcc = $kontenById[$kontoId];
    } elseif ($projektId && isset($kontenByProj[$projektId])) {
      $activeAcc = $kontenByProj[$projektId];
    }
  ?>
  <div class="kv-hero-card">
    <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
      <form method="get" id="quickProjForm" style="margin:0; display:flex; align-items:center; gap:10px;">
        <label for="hero_proj" style="font-size:12px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px;">Liegenschaft:</label>
        <select id="hero_proj" name="projekt_id" class="kv-hero-select" onchange="document.getElementById('quickProjForm').submit()">
          <?php foreach ($projekte as $p): 
            $pidVal = (int)$p['id'];
            $isSel = (!$isPortfolioOverview && $projektId === $pidVal);
          ?>
            <option value="<?= $pidVal ?>" <?= $isSel ? 'selected' : '' ?>>
              🏠 <?= htmlspecialchars($p['name']) ?>
            </option>
          <?php endforeach; ?>
          <option disabled>──────────────────────────</option>
          <option value="all" <?= $isPortfolioOverview ? 'selected' : '' ?>>
            🌐 Portfolio-Gesamtansicht (Alle Liegenschaften)
          </option>
        </select>
        <?php foreach ($selectedYears as $sy): ?>
          <input type="hidden" name="jahre[]" value="<?= (int)$sy ?>">
        <?php endforeach; ?>
        <?php if (empty($selectedYears)): ?>
          <input type="hidden" name="jahr" value="all">
        <?php endif; ?>
        <?php if ($selectedMonth > 0): ?>
          <input type="hidden" name="monat" value="<?= $selectedMonth ?>">
        <?php endif; ?>
      </form>
    </div>

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <?php if ($activeAcc): ?>
        <div class="kv-bank-info-box">
          <div style="font-size:22px;">🏛️</div>
          <div>
            <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase;">
              <?= htmlspecialchars($activeAcc['bank'] ?: 'Bankkonto') ?> &bull; <?= htmlspecialchars($activeAcc['name']) ?>
            </div>
            <div class="kv-bank-iban"><?= htmlspecialchars($activeAcc['iban'] ? chunk_split($activeAcc['iban'], 4, ' ') : '— Noch keine IBAN hinterlegt —') ?></div>
          </div>
        </div>
      <?php elseif ($projektId): ?>
        <div class="kv-bank-info-box" style="border-color:#fef08a; background:#fefce8;">
          <span style="font-size:13px; font-weight:600; color:#854d0e;">⚠️ Kein Bankkonto für diese Liegenschaft hinterlegt</span>
        </div>
      <?php endif; ?>
      <button type="button" class="btn btn-secondary" onclick="openKontenModal()" style="font-size:12px;">
        ⚙️ Bankkonten bearbeiten
      </button>
    </div>
  </div>

  <?php if ($isPortfolioOverview): ?>
    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px 18px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <div>
        <div style="font-size:14px; font-weight:800; color:#1e40af; display:flex; align-items:center; gap:6px;">
          🌐 Portfolio-Gesamtansicht aktiv (Alle Liegenschaften &amp; Konten)
        </div>
        <div style="font-size:12px; color:#3b82f6; margin-top:2px;">
          Du siehst hier alle Konten und Buchungen summiert für das Monats- und Jahres-Cockpit.
        </div>
      </div>
      <a href="<?= htmlspecialchars(buildKontoUrl(['projekt_id' => ($_SESSION['current_project_id'] ?? 3)])) ?>" class="btn btn-primary" style="font-size:12px; padding:6px 14px; background:#2563eb;">
        🏠 Zurück zur Projektarbeit (<?= htmlspecialchars($projById[$_SESSION['current_project_id'] ?? 3] ?? 'Projekt') ?>)
      </a>
    </div>
  <?php endif; ?>

  <!-- Jahres-Navigation & Jahres-Checkboxen (Mehrfachauswahl) -->
  <div class="kv-nav-strip">
    <form method="get" id="yearsNavForm" class="kv-years-form" style="margin:0;">
      <?php if ($projektId): ?>
        <input type="hidden" name="projekt_id" value="<?= (int)$projektId ?>">
      <?php endif; ?>
      <?php if ($kontoId): ?>
        <input type="hidden" name="konto_id" value="<?= (int)$kontoId ?>">
      <?php endif; ?>
      <?php if ($selectedMonth > 0): ?>
        <input type="hidden" name="monat" value="<?= (int)$selectedMonth ?>">
      <?php endif; ?>
      <?php if ($suchtext !== ''): ?>
        <input type="hidden" name="beschreibung" value="<?= htmlspecialchars($suchtext) ?>">
      <?php endif; ?>
      <?php if ($matchStatus !== 'all'): ?>
        <input type="hidden" name="match_status" value="<?= htmlspecialchars($matchStatus) ?>">
      <?php endif; ?>

      <span class="kv-years-label">📅 Jahre:</span>
      <?php foreach ($availableYears as $yr): 
        $isChecked = in_array($yr, $selectedYears, true);
      ?>
        <label class="kv-year-chip <?= $isChecked ? 'active' : '' ?>" title="Jahr <?= $yr ?> auswählen / abwählen">
          <input type="checkbox" name="jahre[]" value="<?= $yr ?>" <?= $isChecked ? 'checked' : '' ?> onchange="document.getElementById('yearsNavForm').submit()">
          <span><?= $yr ?></span>
        </label>
      <?php endforeach; ?>

      <button type="button" class="kv-year-btn <?= empty($selectedYears) ? 'active' : '' ?>" onclick="selectAllYearsNav()" title="Alle Buchungsjahre anzeigen">
        🌐 Alle Jahre
      </button>
    </form>

    <div style="display:flex; align-items:center; gap:4px; flex-wrap:wrap;">
      <span style="font-size:12px; font-weight:700; color:#64748b; margin-right:4px;">Monat:</span>
      <a href="<?= htmlspecialchars(buildKontoUrl(['monat' => 'all'])) ?>" 
         class="kv-mo-pill <?= ($selectedMonth === 0) ? 'active' : '' ?>">
        Alle
      </a>
      <?php for ($m = 1; $m <= 12; $m++): 
        $isActiveM = ($selectedMonth === $m);
        $hasData = isset($monthlySummary[$m]) && $monthlySummary[$m]['cnt'] > 0;
      ?>
        <a href="<?= htmlspecialchars(buildKontoUrl(['monat' => $m])) ?>" 
           class="kv-mo-pill <?= $isActiveM ? 'active' : '' ?> <?= $hasData ? 'has-data' : '' ?>"
           title="<?= $monthNames[$m] ?>: <?= $hasData ? $monthlySummary[$m]['cnt'].' Buchungen' : 'Keine Buchungen' ?>">
          <?= $monthShort[$m] ?>
        </a>
      <?php endfor; ?>
    </div>
  </div>

  <?php
    if (empty($selectedYears)) {
      $periodLabel = 'Gesamt';
    } elseif (count($selectedYears) === 1) {
      $periodLabel = (string)$selectedYears[0];
    } else {
      $periodLabel = implode(', ', $selectedYears);
    }
  ?>

  <!-- KPI Grid (Executive Dashboard) -->
  <div class="kv-kpi-grid">
    <div class="kv-kpi-card">
      <div class="kv-kpi-title">Buchungen <?= htmlspecialchars($periodLabel) ?></div>
      <div class="kv-kpi-val" style="color:#0f172a;">
        <?= number_format($totalMatch, 0, ',', "'") ?> 
        <span style="font-size:12px; color:#94a3b8; font-weight:500;">/ <?= number_format($statTotal, 0, ',', "'") ?></span>
      </div>
      <div style="font-size:12px; color:#64748b; margin-top:2px;">Transaktionen im Auszug</div>
    </div>

    <div class="kv-kpi-card">
      <div class="kv-kpi-title" style="color:#16a34a;">Einnahmen (+)</div>
      <div class="kv-kpi-val" style="color:#16a34a;">
        +<?= number_format($totalEinnahmen, 2, '.', "'") ?> <span style="font-size:13px; font-weight:500;">CHF</span>
      </div>
      <div style="font-size:12px; color:#16a34a; margin-top:2px;">Mieteingänge &amp; Gutschriften</div>
    </div>

    <div class="kv-kpi-card">
      <div class="kv-kpi-title" style="color:#dc2626;">Ausgaben (-)</div>
      <div class="kv-kpi-val" style="color:#dc2626;">
        <?= number_format($totalAusgaben, 2, '.', "'") ?> <span style="font-size:13px; font-weight:500;">CHF</span>
      </div>
      <div style="font-size:12px; color:#dc2626; margin-top:2px;">Zahlungen &amp; Belastungen</div>
    </div>

    <div class="kv-kpi-card">
      <div class="kv-kpi-title">Saldo <?= htmlspecialchars($periodLabel) ?></div>
      <div class="kv-kpi-val" style="color:<?= $totalSum >= 0 ? '#16a34a' : '#dc2626' ?>;">
        <?= ($totalSum >= 0 ? '+' : '') . number_format($totalSum, 2, '.', "'") ?> <span style="font-size:13px; font-weight:500;">CHF</span>
      </div>
      <div style="font-size:12px; color:#64748b; margin-top:2px;">Netto-Ergebnis der Liegenschaft</div>
    </div>

    <div class="kv-kpi-card" style="display:flex; flex-direction:column; justify-content:space-between;">
      <div class="kv-kpi-title">Zuordnungs-Status</div>
      <div style="display:flex; gap:8px; align-items:center; margin-top:4px;">
        <a href="<?= htmlspecialchars(buildKontoUrl(['match_status' => 'matched'])) ?>" 
           style="flex:1; padding:6px 10px; border-radius:6px; background:#dcfce7; color:#166534; border:1px solid #bbf7d0; text-decoration:none; font-weight:700; font-size:12px; text-align:center;">
          🟢 <?= number_format($statMatched, 0, ',', "'") ?> Zugeordnet
        </a>
        <a href="<?= htmlspecialchars(buildKontoUrl(['match_status' => 'unmatched'])) ?>" 
           style="flex:1; padding:6px 10px; border-radius:6px; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; text-decoration:none; font-weight:700; font-size:12px; text-align:center;">
          🔴 <?= number_format($statUnmatched, 0, ',', "'") ?> Offen
        </a>
      </div>
      <div style="font-size:11px; color:#64748b; margin-top:4px; text-align:center;">Klicken zum Filtern</div>
    </div>
  </div>

  <!-- Aufklappbare Monats-Übersicht für das gewählte Jahr -->
  <?php if (!empty($monthlySummary)): ?>
    <details style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:12px 18px; margin-bottom:16px; box-shadow:0 1px 3px rgba(0,0,0,0.02);" <?= ($selectedMonth > 0) ? 'open' : '' ?>>
      <summary style="font-weight:700; font-size:14px; color:#334155; cursor:pointer; display:flex; align-items:center; justify-content:space-between;">
        <span>📊 Monatsübersicht (<?= htmlspecialchars($periodLabel) ?>) – Einnahmen, Ausgaben &amp; Saldo je Monat</span>
        <span style="font-size:12px; color:#2563eb; font-weight:600;">(Klicken zum Auf-/Zuklappen ▾)</span>
      </summary>
      <div style="overflow-x:auto; margin-top:12px;">
        <table style="width:100%; border-collapse:collapse; font-size:12px;">
          <thead>
            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
              <th style="padding:8px 12px; text-align:left;">Monat</th>
              <th style="padding:8px 12px; text-align:right; color:#16a34a;">Einnahmen (+)</th>
              <th style="padding:8px 12px; text-align:right; color:#dc2626;">Ausgaben (-)</th>
              <th style="padding:8px 12px; text-align:right;">Saldo</th>
              <th style="padding:8px 12px; text-align:center;">Buchungen</th>
              <th style="padding:8px 12px; text-align:center;">Zugeordnet</th>
              <th style="padding:8px 12px; text-align:center;">Offen</th>
              <th style="padding:8px 12px; text-align:center;">Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($m = 1; $m <= 12; $m++): 
              $mRow = $monthlySummary[$m] ?? ['cnt'=>0, 'einnahmen'=>0, 'ausgaben'=>0, 'saldo'=>0, 'matched_cnt'=>0, 'open_cnt'=>0];
              $isCurM = ($selectedMonth === $m);
            ?>
              <tr style="border-bottom:1px solid #f1f5f9; <?= $isCurM ? 'background:#eff6ff;' : '' ?>">
                <td style="padding:8px 12px; font-weight:700; color:#1e293b;">
                  <?= $monthNames[$m] ?> <?= $selectedYear ?>
                </td>
                <td style="padding:8px 12px; text-align:right; color:#16a34a; font-weight:700;">
                  <?= $mRow['einnahmen'] > 0 ? ('+' . number_format((float)$mRow['einnahmen'], 2, '.', "'")) : '—' ?>
                </td>
                <td style="padding:8px 12px; text-align:right; color:#dc2626; font-weight:700;">
                  <?= $mRow['ausgaben'] < 0 ? number_format((float)$mRow['ausgaben'], 2, '.', "'") : '—' ?>
                </td>
                <td style="padding:8px 12px; text-align:right; font-weight:700; color:<?= (float)$mRow['saldo'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
                  <?= ((float)$mRow['saldo'] >= 0 ? '+' : '') . number_format((float)$mRow['saldo'], 2, '.', "'") ?>
                </td>
                <td style="padding:8px 12px; text-align:center; font-weight:600; color:#475569;">
                  <?= (int)$mRow['cnt'] ?>
                </td>
                <td style="padding:8px 12px; text-align:center;">
                  <span style="background:#dcfce7; color:#166534; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">
                    <?= (int)$mRow['matched_cnt'] ?>
                  </span>
                </td>
                <td style="padding:8px 12px; text-align:center;">
                  <?php if ((int)$mRow['open_cnt'] > 0): ?>
                    <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">
                      <?= (int)$mRow['open_cnt'] ?> offen
                    </span>
                  <?php else: ?>
                    <span style="color:#94a3b8;">0</span>
                  <?php endif; ?>
                </td>
                <td style="padding:8px 12px; text-align:center;">
                  <a href="<?= htmlspecialchars(buildKontoUrl(['monat' => $m])) ?>" class="btn btn-secondary" style="padding:3px 10px; font-size:11px;">
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

  <!-- Kompakte Filter-Leiste -->
  <form class="kv-filter-strip" method="get" action="">
    <?php foreach ($selectedYears as $sy): ?>
      <input type="hidden" name="jahre[]" value="<?= (int)$sy ?>">
    <?php endforeach; ?>
    <?php if (empty($selectedYears)): ?>
      <input type="hidden" name="jahr" value="all">
    <?php endif; ?>
    <?php if ($selectedMonth > 0): ?>
      <input type="hidden" name="monat" value="<?= $selectedMonth ?>">
    <?php endif; ?>
    <?php if ($projektId): ?>
      <input type="hidden" name="projekt_id" value="<?= $projektId ?>">
    <?php endif; ?>

    <div class="kv-filter-row">
      <input type="text" name="beschreibung" class="kv-search-input" placeholder="🔍 Textsuche (Mieter, Verwendungszweck, Betrag)..." value="<?= htmlspecialchars($suchtext) ?>">
      
      <select name="match_status" class="kv-select">
        <option value="all">-- Alle Status --</option>
        <option value="unmatched" <?= $matchStatus==='unmatched'?'selected':'' ?>>🔴 Nur Unzugeordnete (offen)</option>
        <option value="matched" <?= $matchStatus==='matched'?'selected':'' ?>>🟢 Nur Zugeordnete</option>
      </select>

      <div style="display:flex; align-items:center; gap:6px;">
        <span style="font-size:12px; color:#64748b; font-weight:600;">Von:</span>
        <input type="date" name="datum_von" value="<?= htmlspecialchars($datumVon) ?>" class="kv-select" style="padding:7px 10px;">
        <span style="font-size:12px; color:#64748b; font-weight:600;">Bis:</span>
        <input type="date" name="datum_bis" value="<?= htmlspecialchars($datumBis) ?>" class="kv-select" style="padding:7px 10px;">
      </div>

      <select name="sort" class="kv-select">
        <option value="buchungsdatum" <?= $sort==='buchungsdatum'?'selected':'' ?>>Sortierung: Datum</option>
        <option value="betrag" <?= $sort==='betrag'?'selected':'' ?>>Sortierung: Betrag</option>
        <option value="beschreibung" <?= $sort==='beschreibung'?'selected':'' ?>>Sortierung: Buchungstext</option>
        <option value="id" <?= $sort==='id'?'selected':'' ?>>Sortierung: ID</option>
      </select>

      <select name="dir" class="kv-select">
        <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Neueste zuerst</option>
        <option value="asc" <?= $dir==='asc'?'selected':'' ?>>Älteste zuerst</option>
      </select>

      <button type="submit" class="btn btn-primary">🔍 Filtern</button>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(url('tools/konto_verwaltung/index.php') . ($projektId ? '?projekt_id='.$projektId : '')) ?>">⟲ Reset</a>
    </div>

    <!-- Einklappbarer Bereich für Massen-Aktionen & Regex-Tools -->
    <details class="kv-tools-accordion" style="margin-top:12px; border-top:1px solid #f1f5f9; padding-top:8px;">
      <summary style="cursor:pointer; font-size:12px; font-weight:700; color:#64748b;">
        ⚙️ Massen-Aktionen &amp; Erweiterte Filter-Werkzeuge (Hier klicken zum Einblenden)
      </summary>
      
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-top:10px;">
        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
          <span style="font-weight:700; font-size:12px; color:#334155;">Aktion für markierte Zeilen:</span>
          
          <input type="text" id="batch_new_cat" placeholder="Neue Kategorie" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;">
          <button class="btn btn-secondary" type="button" onclick="submitBatchCat()" style="font-size:12px;">🏷️ Kategorie setzen</button>

          <select id="batch_wohnung_id" style="max-width:220px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;">
            <option value="0">-- Wohnung zuweisen --</option>
            <?php foreach ($allWohnungen as $aw): ?>
              <option value="<?= (int)$aw['id'] ?>"><?= htmlspecialchars($aw['projekt_name'] . ' ➔ ' . ($aw['wohnung_name'] ?? $aw['name'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>

          <select id="batch_mieter_id" style="max-width:180px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;">
            <option value="0">-- Mieter zuweisen --</option>
            <?php foreach ($allMieter as $am): ?>
              <option value="<?= (int)$am['benutzer_id'] ?>"><?= htmlspecialchars($am['mieter_name'] ?: $am['benutzer_name']) ?></option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-success" type="button" onclick="submitBatchAssign()" style="font-size:12px;">📌 Zuweisen</button>
          <button class="btn btn-danger" type="button" onclick="submitBatchDelete()" style="font-size:12px;">🗑️ Löschen</button>
        </div>

        <div style="border-top:1px solid #e2e8f0; padding-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
          <span style="font-weight:700; font-size:12px; color:#334155;">Schnell-Zuordnung (Regex):</span>
          <input id="assign_name" type="text" name="assign_name" placeholder="Name enthält (z.B. Nieder|Kaden)" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; min-width:220px;">
          <select id="assign_projekt_id" name="assign_projekt_id" style="padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;">
            <option value="0">-- Liegenschaft setzen --</option>
            <?php foreach ($projekte as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <label style="font-size:12px; display:inline-flex; align-items:center; gap:4px; font-weight:600;">
            <input type="checkbox" name="assign_regex" value="1"> Als Regex behandeln
          </label>
          <button class="btn btn-secondary" type="submit" name="apply_assignment" value="1" style="font-size:12px;">💾 Zuordnen</button>
        </div>

        <?php if (!empty($descOptions)): ?>
          <details style="margin-top:10px;">
            <summary style="font-size:12px; color:#64748b; font-weight:600; cursor:pointer;">Top-Beschreibungen Mehrfachfilter anzeigen (<?= count($descOptions) ?> Filter)</summary>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:6px; max-height:200px; overflow-y:auto; margin-top:8px; padding:8px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; font-size:11px;">
              <?php foreach ($descOptions as $b):
                $checked = in_array($b, $selectedDescs, true) ? 'checked' : '';
              ?>
                <label style="display:flex; align-items:center; gap:5px;">
                  <input type="checkbox" name="beschreibungen[]" value="<?= htmlspecialchars($b) ?>" <?= $checked ?>>
                  <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($b) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>
    </details>
  </form>

  <!-- Versteckte Helper-Formulare für Batch-Aktionen -->
  <form id="form_batch_cat" method="post" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="set_cat">
    <input type="hidden" name="new_cat" id="hidden_batch_cat" value="">
    <div id="ids_holder_cat"></div>
  </form>

  <form id="form_batch_assign" method="post" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="assign_proj_whg">
    <input type="hidden" name="assign_wohnung_id" id="hidden_batch_wid" value="0">
    <input type="hidden" name="assign_mieter_id" id="hidden_batch_mid" value="0">
    <div id="ids_holder_assign"></div>
  </form>

  <form id="form_batch_delete" method="post" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="delete_rows">
    <div id="ids_holder_del"></div>
  </form>

  <!-- Moderne Buchungs-Tabelle -->
  <div class="kv-table-container">
    <table class="kv-table">
      <thead>
        <tr>
          <th style="width:34px; text-align:center;"><input type="checkbox" class="select_all"></th>
          <th style="width:95px;">Datum</th>
          <th style="width:140px; text-align:right;">Betrag</th>
          <th>Buchungstext / Beschreibung</th>
          <th style="width:125px;">Kategorie</th>
          <th style="min-width:220px;">Zuweisung &amp; Mieter</th>
          <th style="width:190px;">Konto / IBAN</th>
          <?php if (!$projektId): ?>
            <th style="width:150px;">Liegenschaft</th>
          <?php endif; ?>
          <th style="width:80px; text-align:center;">Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="<?= $projektId ? 8 : 9 ?>" style="text-align:center; padding:40px; color:#94a3b8; font-size:14px;">
              Keine Buchungen für die gewählte Filter-Einstellung gefunden.
            </td>
          </tr>
        <?php else: foreach ($rows as $r): 
          $betrag = (float)$r['betrag'];
          $isPos = $betrag >= 0;

          // Intelligente Kategorie
          $rawKat = trim((string)($r['kategorie'] ?? ''));
          if ($rawKat !== '') {
              $displayKat = $rawKat;
          } else {
              if ($isPos) {
                  $displayKat = 'Miete';
              } else {
                  $lDesc = mb_strtolower($r['beschreibung']);
                  if (str_contains($lDesc, 'gebühr') || str_contains($lDesc, 'spesen') || str_contains($lDesc, 'abschluss')) {
                      $displayKat = 'Bankgebühren';
                  } elseif (str_contains($lDesc, 'übertrag') || str_contains($lDesc, 'kontoübertrag')) {
                      $displayKat = 'Kontoübertrag';
                  } elseif (str_contains($lDesc, 'versicherung')) {
                      $displayKat = 'Versicherung';
                  } elseif (str_contains($lDesc, 'ew ') || str_contains($lDesc, 'strom') || str_contains($lDesc, 'energie')) {
                      $displayKat = 'Nebenkosten';
                  } else {
                      $displayKat = 'Aufwand';
                  }
              }
          }
        ?>
          <tr id="row_<?= (int)$r['id'] ?>">
            <td style="text-align:center;"><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
            <td style="white-space:nowrap; font-weight:600; color:#334155;">
              <?= htmlspecialchars(date('d.m.Y', strtotime($r['buchungsdatum']))) ?>
            </td>
            <td style="text-align:right;">
              <?php if ($isPos): ?>
                <span class="amount-badge-in">+CHF <?= number_format($betrag, 2, '.', "'") ?></span>
              <?php else: ?>
                <span class="amount-badge-out"><?= number_format($betrag, 2, '.', "'") ?> CHF</span>
              <?php endif; ?>
            </td>
            <td style="max-width:360px; word-break:break-word; color:#1e293b; font-weight:500;" title="<?= htmlspecialchars($r['beschreibung']) ?>">
              <?= htmlspecialchars($r['beschreibung']) ?>
            </td>
            <td>
              <span class="badge-cat"><?= htmlspecialchars($displayKat) ?></span>
            </td>
            <td>
              <?php if (!empty($r['wohnung_id']) || !empty($r['mieter_id']) || !empty($r['mieter_display_name'])): ?>
                <span class="badge-assigned">
                  👤 <?= htmlspecialchars($r['mieter_display_name'] ?: 'Mieter') ?>
                  <?php if (!empty($r['wohnung_name'])): ?>
                    &bull; 🏠 <?= htmlspecialchars($r['wohnung_name']) ?>
                  <?php endif; ?>
                </span>
              <?php else: ?>
                <span class="badge-unassigned">🔴 Nicht zugewiesen</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($r['konto_iban'])): ?>
                <div style="display:flex; flex-direction:column; gap:2px;">
                  <span style="font-family:monospace; font-size:11px; font-weight:700; color:#0f172a; background:#f8fafc; border:1px solid #cbd5e1; padding:2px 6px; border-radius:4px; white-space:nowrap; display:inline-block;" title="<?= htmlspecialchars($r['konto_name'] ?? '') ?>">
                    💳 <?= htmlspecialchars(trim(chunk_split(str_replace(' ', '', $r['konto_iban']), 4, ' '))) ?>
                  </span>
                  <?php if (!empty($r['konto_bank']) || !empty($r['konto_name'])): ?>
                    <span style="font-size:10px; color:#64748b; white-space:nowrap;">
                      <?= htmlspecialchars($r['konto_bank'] ?: 'Bank') ?><?= !empty($r['konto_name']) ? ' &bull; ' . htmlspecialchars($r['konto_name']) : '' ?>
                    </span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span style="font-size:11px; color:#94a3b8; font-style:italic;">— Keine IBAN —</span>
              <?php endif; ?>
            </td>
            <?php if (!$projektId): ?>
              <td>
                <span style="font-size:12px; font-weight:600; color:#475569;">
                  <?= htmlspecialchars($r['projekt_name'] ?: '—') ?>
                </span>
              </td>
            <?php endif; ?>
            <td style="text-align:center;">
              <button type="button" class="btn btn-primary" style="padding:4px 10px; font-size:11px;"
                      onclick="openAssignModal(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['beschreibung'])) ?>', <?= (float)$r['betrag'] ?>, <?= (int)($r['wohnung_id'] ?: 0) ?>, <?= (int)($r['mieter_id'] ?: 0) ?>, <?= (int)($r['projekt_id'] ?: ($r['liegenschaft_id'] ?: $projektId)) ?>, '<?= htmlspecialchars(addslashes($r['kategorie'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($r['projekt_name'] ?? '')) ?>')">
                📌 Zuweisen
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination unten -->
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-top:8px;">
    <div style="font-size:13px; color:#64748b;">
      Zeige <?= count($rows) ?> von <?= number_format($totalMatch, 0, ',', "'") ?> Buchungen
    </div>

    <div style="display:flex; gap:6px; align-items:center;">
      <?php
        $pages = max(1, (int)ceil($totalMatch/$perPage));
        $build = function($p){
          $qs = $_GET; $qs['page']=$p;
          return url('tools/konto_verwaltung/index.php') . '?' . http_build_query($qs);
        };
      ?>
      <a class="btn btn-secondary" href="<?= htmlspecialchars($build(1)) ?>" style="padding:6px 10px;">⏮️</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars($build(max(1,$page-1))) ?>" style="padding:6px 10px;">◀️</a>
      <span style="font-size:13px; font-weight:600; color:#334155; padding:0 8px;">Seite <?= $page ?> von <?= $pages ?></span>
      <a class="btn btn-secondary" href="<?= htmlspecialchars($build(min($pages,$page+1))) ?>" style="padding:6px 10px;">▶️</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars($build($pages)) ?>" style="padding:6px 10px;">⏭️</a>
    </div>
  </div>

  <div class="kv-footer-actions" style="margin-top:.75rem;">
    <a class="btn" href="<?= htmlspecialchars(url('tools/konto_verwaltung/import.php')) ?>">📂 CSV importieren</a>
  </div>
  
</div>

<!-- Modal für 1-Klick Einzelzuweisung -->
<div id="assignModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.55); backdrop-filter:blur(2px); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:14px; padding:24px; width:92%; max-width:560px; max-height:92vh; overflow-y:auto; box-shadow:0 25px 30px -5px rgba(0,0,0,0.3); border:1px solid #e2e8f0;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
      <h3 style="margin:0; font-size:18px; color:#0f172a; display:flex; align-items:center; gap:8px;">
        📌 Buchung zuweisen &amp; deklarieren
      </h3>
      <button type="button" onclick="closeAssignModal()" style="background:none; border:none; font-size:20px; color:#94a3b8; cursor:pointer; padding:0 4px;">&times;</button>
    </div>
    
    <div id="modal_proj_info" style="font-size:12px; font-weight:700; color:#2563eb; background:#eff6ff; padding:6px 12px; border-radius:6px; margin-bottom:10px; border:1px solid #bfdbfe;"></div>
    <div id="assignModalDesc" style="font-size:13px; color:#475569; background:#f8fafc; border-left:3px solid #3b82f6; padding:10px 12px; border-radius:6px; margin-bottom:16px; word-break:break-word; max-height:80px; overflow-y:auto;"></div>
    
    <form method="post" id="assignModalForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign_single">
      <input type="hidden" name="konto_id" id="modal_konto_id" value="">
      
      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:5px;">Wohnung / Einheit (dieser Liegenschaft):</label>
        <select name="wohnung_id" id="modal_wohnung_id" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff;" onchange="autoSelectTenantForUnit(this.value)">
          <option value="0">-- Keine / Offen --</option>
          <?php foreach ($allWohnungen as $aw): ?>
            <option value="<?= (int)$aw['id'] ?>" data-pid="<?= (int)$aw['projekt_id'] ?>">
              <?= htmlspecialchars($aw['wohnung_name'] . (!empty($aw['objekt_name']) && $aw['objekt_name'] !== $aw['wohnung_name'] ? ' (' . $aw['objekt_name'] . ')' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px; color:#64748b; margin-top:4px;">🎯 Filtert automatisch auf die Einheiten der jeweiligen Liegenschaft.</div>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:5px;">Mieter (Benutzer):</label>
        <select name="mieter_id" id="modal_mieter_id" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff;">
          <option value="0">-- Kein Mieter zugeordnet --</option>
          <?php foreach ($allMieter as $am): 
            $bId = (int)($am['benutzer_id'] ?: 0);
            $dName = trim($am['mieter_name'] ?: ($am['benutzer_name'] ?: 'Mieter #'.$am['wm_id']));
          ?>
            <option value="<?= $bId ?>" data-wid="<?= (int)$am['wohnung_id'] ?>">
              <?= htmlspecialchars($dName) ?><?= $bId > 0 ? '' : ' (Kein Account)' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px; color:#64748b; margin-top:4px;">💡 Bei Auswahl einer Wohnung wird der hinterlegte Mieter automatisch vorausgewählt.</div>
      </div>

      <div style="margin-bottom:14px;">
        <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:5px;">Kategorie / Deklaration (für Liegenschaftsabrechnung):</label>
        <select name="kategorie" id="modal_kategorie" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; background:#fff; font-weight:600;">
          <option value="Miete">1. Mieteinnahmen (Mietzins)</option>
          <option value="Nebenkosten">2. Bezahlte Nebenkosten (EW, Strom, Wasser, Heizung)</option>
          <option value="Steuern">3. Bezahlte Steuern (Gemeindesteuern, Liegenschaftssteuer)</option>
          <option value="Versicherung">4. Bezahlte Versicherungen (Gebäudeversicherung, Haftpflicht)</option>
          <option value="Hypothek / Bank">5. Bezahlte Hypothek &amp; Bankspesen (Zinsen, Kontoführung)</option>
          <option value="Unterhalt & Reparaturen">6. Bezahlte Unternehmer (Handwerker, Unterhalt, Reparaturen)</option>
          <option value="Investitionen">7. Investitionen (PV-Anlage, Sanierungen)</option>
          <option value="Auszahlung Eigentümer">8. Auszahlungen an Eigentümer (Privatbezug)</option>
          <option value="Kaution">🛡️ Mietkaution / Mietzinsdepot (Neutral)</option>
          <option value="Rückzahlung / Korrektur">↩️ Rückzahlung / Korrektur (Doppelzahlung, Rückerstattung)</option>
        </select>
      </div>

      <div style="margin-top:14px; margin-bottom:14px;">
        <label style="display:flex; align-items:flex-start; gap:10px; font-size:13px; font-weight:600; color:#0f172a; background:#f0fdf4; border:1px solid #bbf7d0; padding:10px 14px; border-radius:8px; cursor:pointer;">
          <input type="checkbox" name="remember_rule" value="1" checked style="margin-top:2px; transform:scale(1.15);">
          <div>
            <div>🧠 Für nächste Jahre merken (Dauer-Regel für diesen Zahlungsabsender)</div>
            <div style="font-size:11px; font-weight:400; color:#15803d; margin-top:2px;">Erstellt eine Dauer-Regel: Künftige Buchungen dieses Absenders werden in Folgejahren automatisch so deklariert (auch bei mehreren verschiedenen Zahlern pro Wohnung).</div>
          </div>
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
        <button type="button" class="btn secondary" onclick="closeAssignModal()" style="padding:8px 16px; border-radius:8px;">Abbrechen</button>
        <button type="submit" class="btn" style="background:#2563eb; color:#fff; padding:8px 18px; border-radius:8px; font-weight:600; border:none; cursor:pointer;">💾 Speichern &amp; Merken</button>
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

function openAssignModal(id, desc, amount, wid, mid, bookingPid, kat, projName) {
  document.getElementById('modal_konto_id').value = id;
  const prefix = (amount >= 0 ? '+' : '');
  document.getElementById('assignModalDesc').innerHTML = '<strong>Buchung #' + id + ' (' + prefix + Number(amount).toLocaleString('de-CH', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' CHF):</strong><br>' + desc;
  
  const targetPid = parseInt(bookingPid || <?= (int)($projektId ?: 0) ?>, 10);
  const pName = projName || 'Liegenschaft';
  document.getElementById('modal_proj_info').innerHTML = '📍 <strong>Liegenschaft:</strong> ' + pName + (targetPid > 0 ? ' (ID #' + targetPid + ')' : '');

  const wSelect = document.getElementById('modal_wohnung_id');
  Array.from(wSelect.options).forEach(opt => {
    if (opt.value === '0') {
      opt.style.display = '';
      return;
    }
    const optPid = parseInt(opt.dataset.pid || 0, 10);
    if (targetPid > 0 && optPid !== targetPid) {
      opt.style.display = 'none';
    } else {
      opt.style.display = '';
    }
  });

  document.getElementById('modal_wohnung_id').value = wid || 0;
  document.getElementById('modal_mieter_id').value = mid || 0;

  if (kat && kat !== '') {
    document.getElementById('modal_kategorie').value = kat;
  } else {
    document.getElementById('modal_kategorie').value = (amount >= 0 ? 'Miete' : 'Unterhalt & Reparaturen');
  }

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

function selectAllYearsNav() {
  const form = document.getElementById('yearsNavForm');
  if (!form) return;
  form.querySelectorAll('input[name="jahre[]"]').forEach(cb => cb.checked = false);
  let inp = form.querySelector('input[name="jahr"]');
  if (!inp) {
    inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'jahr';
    form.appendChild(inp);
  }
  inp.value = 'all';
  form.submit();
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
