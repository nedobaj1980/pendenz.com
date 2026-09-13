<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vorgang_taxonomy.php';

require_login();
if (!is_admin()) {
    die('Zugriff verweigert.');
}

vorgang_taxonomy_ensure_tables($mysqli);

if (!function_exists('h')) {
    function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
function table_has_column(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}
function ensure_column(mysqli $db, string $table, string $column, string $definition): void {
    if (!table_has_column($db, $table, $column)) {
        @$db->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}
function ensure_defaults_table(mysqli $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS `pendenzen_art_empfaenger_defaults` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `pendenz_art_id` int(11) NOT NULL,
      `benutzer_id` int(11) DEFAULT NULL,
      `projekt_id` int(11) DEFAULT NULL,
      `objekt_id` int(11) DEFAULT NULL,
      `wohnung_id` int(11) DEFAULT NULL,
      `bkp_id` int(11) DEFAULT NULL,
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `created_at` datetime NOT NULL DEFAULT current_timestamp(),
      `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_paed_art` (`pendenz_art_id`),
      KEY `idx_paed_benutzer` (`benutzer_id`),
      KEY `idx_paed_projekt` (`projekt_id`),
      KEY `idx_paed_objekt` (`objekt_id`),
      KEY `idx_paed_wohnung` (`wohnung_id`),
      KEY `idx_paed_bkp` (`bkp_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    @$db->query($sql);
}

ensure_column($mysqli, 'pendenzen_arten', 'vorlagen_welt', "`vorlagen_welt` VARCHAR(30) NOT NULL DEFAULT 'bkp' AFTER `name`");
ensure_column($mysqli, 'pendenzen_arten', 'empfaenger_typ', "`empfaenger_typ` VARCHAR(30) NULL DEFAULT NULL AFTER `vorlagen_welt`");
ensure_column($mysqli, 'pendenzen_arten', 'empfaenger_person_type_id', "`empfaenger_person_type_id` INT(11) NULL DEFAULT NULL AFTER `empfaenger_typ`");
ensure_column($mysqli, 'pendenzen_arten', 'empfaenger_person_status_id', "`empfaenger_person_status_id` INT(11) NULL DEFAULT NULL AFTER `empfaenger_person_type_id`");
ensure_column($mysqli, 'pendenzen_arten', 'bkp_erforderlich', "`bkp_erforderlich` TINYINT(1) NOT NULL DEFAULT 0 AFTER `empfaenger_person_status_id`");
ensure_column($mysqli, 'pendenzen_arten', 'nur_firmen_bkp', "`nur_firmen_bkp` TINYINT(1) NOT NULL DEFAULT 0 AFTER `bkp_erforderlich`");
ensure_defaults_table($mysqli);
ensure_column($mysqli, 'pendenzen_art_empfaenger_defaults', 'bkp_mode', "`bkp_mode` VARCHAR(20) NOT NULL DEFAULT 'single' AFTER `bkp_id`");

$flash = '';
$action = (string)($_GET['action'] ?? '');

if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare('DELETE FROM pendenzen_arten WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: vorgangsart_settings.php?flash=' . urlencode('🗑️ Gelöscht.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $slugInput = trim((string)($_POST['slug'] ?? ''));
    $slug = $slugInput !== '' ? vorgang_taxonomy_slugify($slugInput) : vorgang_taxonomy_slugify($name);
    $icon = trim((string)($_POST['icon'] ?? '📝'));
    $color = trim((string)($_POST['color'] ?? '#3b82f6'));
    $sort = (int)($_POST['sort_order'] ?? 100);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $vorlagenWelt = trim((string)($_POST['vorlagen_welt'] ?? 'bkp'));
    if (!in_array($vorlagenWelt, ['bkp', 'mieter', 'vermieter'], true)) {
        $vorlagenWelt = 'bkp';
    }
    $personTypeId = (int)($_POST['empfaenger_person_type_id'] ?? 0);
    $personStatusId = (int)($_POST['empfaenger_person_status_id'] ?? 0);
    $bkpErforderlich = isset($_POST['bkp_erforderlich']) ? 1 : 0;
    $nurFirmenBkp = isset($_POST['nur_firmen_bkp']) ? 1 : 0;

    if ($name === '') {
        $flash = '❌ Name darf nicht leer sein.';
    } else {
        $empfaengerTyp = null;
        if ($personTypeId > 0) {
            $stmt = $mysqli->prepare('SELECT slug FROM person_types WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $personTypeId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $empfaengerTyp = $row['slug'] ?? null;
            $stmt->close();
        }

        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE pendenzen_arten
                SET name=?, slug=?, icon=?, color=?, farbe=?, sort_order=?, is_active=?, vorlagen_welt=?, empfaenger_typ=?, empfaenger_person_type_id=?, empfaenger_person_status_id=?, bkp_erforderlich=?, nur_firmen_bkp=?
                WHERE id=?");
            $stmt->bind_param(
                'sssssisssiiiii',
                $name,
                $slug,
                $icon,
                $color,
                $color,
                $sort,
                $active,
                $vorlagenWelt,
                $empfaengerTyp,
                $personTypeId,
                $personStatusId,
                $bkpErforderlich,
                $nurFirmenBkp,
                $id
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $mysqli->prepare("INSERT INTO pendenzen_arten
                (name, slug, icon, color, farbe, sort_order, is_active, vorlagen_welt, empfaenger_typ, empfaenger_person_type_id, empfaenger_person_status_id, bkp_erforderlich, nur_firmen_bkp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                'sssssisssiiii',
                $name,
                $slug,
                $icon,
                $color,
                $color,
                $sort,
                $active,
                $vorlagenWelt,
                $empfaengerTyp,
                $personTypeId,
                $personStatusId,
                $bkpErforderlich,
                $nurFirmenBkp
            );
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }

        $stmt = $mysqli->prepare('DELETE FROM pendenzen_art_empfaenger_defaults WHERE pendenz_art_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $dBenutzer = $_POST['default_benutzer_id'] ?? [];
        $dProjekt = $_POST['default_projekt_id'] ?? [];
        $dObjekt = $_POST['default_objekt_id'] ?? [];
        $dWohnung = $_POST['default_wohnung_id'] ?? [];
        $dBkp = $_POST['default_bkp_id'] ?? [];
        $dBkpMode = $_POST['default_bkp_mode'] ?? [];
        $dActive = $_POST['default_is_active'] ?? [];

        $dBenutzer = is_array($dBenutzer) ? array_values($dBenutzer) : (($dBenutzer === '' || $dBenutzer === null) ? [] : [$dBenutzer]);
        $dProjekt  = is_array($dProjekt) ? array_values($dProjekt) : (($dProjekt === '' || $dProjekt === null) ? [] : [$dProjekt]);
        $dObjekt   = is_array($dObjekt) ? array_values($dObjekt) : (($dObjekt === '' || $dObjekt === null) ? [] : [$dObjekt]);
        $dWohnung  = is_array($dWohnung) ? array_values($dWohnung) : (($dWohnung === '' || $dWohnung === null) ? [] : [$dWohnung]);
        $dBkp      = is_array($dBkp) ? array_values($dBkp) : (($dBkp === '' || $dBkp === null) ? [] : [$dBkp]);
        $dBkpMode  = is_array($dBkpMode) ? array_values($dBkpMode) : (($dBkpMode === '' || $dBkpMode === null) ? [] : [$dBkpMode]);
        $dActive   = is_array($dActive) ? array_values($dActive) : (($dActive === '' || $dActive === null) ? [] : [$dActive]);

        $insert = $mysqli->prepare('INSERT INTO pendenzen_art_empfaenger_defaults (pendenz_art_id, benutzer_id, projekt_id, objekt_id, wohnung_id, bkp_id, bkp_mode, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $sortIdx = 0;
        $count = max(count($dBenutzer), count($dProjekt), count($dObjekt), count($dWohnung), count($dBkp), count($dBkpMode), count($dActive));
        for ($i = 0; $i < $count; $i++) {
            $benutzerId = isset($dBenutzer[$i]) ? (int)$dBenutzer[$i] : 0;
            $projektId = isset($dProjekt[$i]) ? (int)$dProjekt[$i] : 0;
            $objektId = isset($dObjekt[$i]) ? (int)$dObjekt[$i] : 0;
            $wohnungId = isset($dWohnung[$i]) ? (int)$dWohnung[$i] : 0;
            $bkpId = isset($dBkp[$i]) ? (int)$dBkp[$i] : 0;
            $bkpMode = isset($dBkpMode[$i]) ? trim((string)$dBkpMode[$i]) : 'single';
            if (!in_array($bkpMode, ['single', 'all_firma', 'none'], true)) {
                $bkpMode = 'single';
            }
            if ($bkpMode !== 'single') {
                $bkpId = 0;
            }
            $isActive = isset($dActive[$i]) ? (int)$dActive[$i] : 1;
            if ($benutzerId <= 0 && $projektId <= 0 && $objektId <= 0 && $wohnungId <= 0 && $bkpId <= 0 && $bkpMode === 'none') {
                continue;
            }
            if ($benutzerId <= 0) {
                continue;
            }
            $sortIdx++;
            $benutzerBind = $benutzerId > 0 ? $benutzerId : null;
            $projektBind = $projektId > 0 ? $projektId : null;
            $objektBind = $objektId > 0 ? $objektId : null;
            $wohnungBind = $wohnungId > 0 ? $wohnungId : null;
            $bkpBind = $bkpId > 0 ? $bkpId : null;
            $insert->bind_param('iiiiiisii', $id, $benutzerBind, $projektBind, $objektBind, $wohnungBind, $bkpBind, $bkpMode, $sortIdx, $isActive);
            $insert->execute();
        }
        $insert->close();
        header('Location: vorgangsart_settings.php?edit=' . $id . '&flash=' . urlencode('✅ Gespeichert.'));
        exit;
    }
}

$arten = [];
$sql = "SELECT a.*, pt.name AS person_type_name, ps.name AS person_status_name
        FROM pendenzen_arten a
        LEFT JOIN person_types pt ON pt.id = a.empfaenger_person_type_id
        LEFT JOIN person_statuses ps ON ps.id = a.empfaenger_person_status_id
        ORDER BY a.sort_order, a.name";
$q = $mysqli->query($sql);
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $arten[] = $r;
    }
}

$defaultsByArt = [];
$q = $mysqli->query("SELECT d.*, b.name AS benutzer_name,
                            COALESCE(f.name, b.firma_name, '') AS firma_name,
                            p.name AS projekt_name,
                            o.name AS objekt_name,
                            w.name AS wohnung_name,
                            CONCAT(COALESCE(c.code,''), ' ', COALESCE(c.bezeichnung,'')) AS bkp_name
                     FROM pendenzen_art_empfaenger_defaults d
                     LEFT JOIN benutzer b ON b.id = d.benutzer_id
                     LEFT JOIN firma_user fu ON fu.user_id = b.id AND fu.is_primary = 1
                     LEFT JOIN firmen f ON f.id = fu.firma_id
                     LEFT JOIN projekte p ON p.id = d.projekt_id
                     LEFT JOIN objekte o ON o.id = d.objekt_id
                     LEFT JOIN wohnungen w ON w.id = d.wohnung_id
                     LEFT JOIN bkp_codes c ON c.id = d.bkp_id
                     ORDER BY d.sort_order, d.id");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $artId = (int)$r['pendenz_art_id'];
        $defaultsByArt[$artId] ??= [];
        $defaultsByArt[$artId][] = $r;
    }
}

$personTypes = [];
$q = $mysqli->query('SELECT id, name, slug FROM person_types WHERE active = 1 ORDER BY sort, name');
if ($q) while ($r = $q->fetch_assoc()) $personTypes[] = $r;
$statuses = [];
$q = $mysqli->query('SELECT id, type_id, name FROM person_statuses WHERE active = 1 ORDER BY type_id, sort, name');
if ($q) while ($r = $q->fetch_assoc()) $statuses[] = $r;
$projects = [];
$q = $mysqli->query("SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name");
if ($q) while ($r = $q->fetch_assoc()) $projects[] = $r;
$objects = [];
$q = $mysqli->query('SELECT id, projekt_id, name FROM objekte ORDER BY name');
if ($q) while ($r = $q->fetch_assoc()) $objects[] = $r;
$wohnungen = [];
$q = $mysqli->query('SELECT id, objekt_id, name FROM wohnungen ORDER BY name');
if ($q) while ($r = $q->fetch_assoc()) $wohnungen[] = $r;
$bkpCodes = [];
$q = $mysqli->query("SELECT id, code, bezeichnung FROM bkp_codes ORDER BY code, bezeichnung");
if ($q) while ($r = $q->fetch_assoc()) $bkpCodes[] = $r;

function ensure_firmen_vorlagen_map(mysqli $db): void {
    $db->query("
        CREATE TABLE IF NOT EXISTS firmen_vorlagen_map (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            firma_id INT NOT NULL,
            welt VARCHAR(30) NOT NULL,
            ref_id INT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_firma_welt_ref (firma_id, welt, ref_id),
            KEY idx_firma_welt (firma_id, welt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

ensure_firmen_vorlagen_map($mysqli);

$userFirmaBkpMap = [];
$q = $mysqli->query("SELECT fu.user_id, fvm.ref_id AS bkp_id
                     FROM firma_user fu
                     INNER JOIN firmen_vorlagen_map fvm ON fvm.firma_id = fu.firma_id AND fvm.welt = 'bkp'
                     WHERE fu.user_id IS NOT NULL
                     ORDER BY fu.user_id, fvm.ref_id");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $uid = (int)($r['user_id'] ?? 0);
        $bid = (int)($r['bkp_id'] ?? 0);
        if ($uid > 0 && $bid > 0) {
            $userFirmaBkpMap[$uid] ??= [];
            $userFirmaBkpMap[$uid][$bid] = $bid;
        }
    }
}
foreach ($userFirmaBkpMap as $uid => $ids) {
    $userFirmaBkpMap[$uid] = array_values($ids);
}

$benutzer = [];
$hasPersonCols = table_has_column($mysqli, 'benutzer', 'person_type_id') && table_has_column($mysqli, 'benutzer', 'person_status_id');
$sqlUsers = "SELECT b.id, b.name, b.business_type, b.rolle, b.person_type_id, b.person_status_id,
                    pt.name AS person_type_name, ps.name AS person_status_name,
                    COALESCE(f.name, b.firma_name, '') AS firma_name
             FROM benutzer b
             LEFT JOIN person_types pt ON pt.id = b.person_type_id
             LEFT JOIN person_statuses ps ON ps.id = b.person_status_id
             LEFT JOIN firma_user fu ON fu.user_id = b.id AND fu.is_primary = 1
             LEFT JOIN firmen f ON f.id = fu.firma_id
             WHERE b.deleted_at IS NULL
             ORDER BY b.name";
$q = $mysqli->query($sqlUsers);
if ($q) while ($r = $q->fetch_assoc()) $benutzer[] = $r;

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.vs-wrap{max-width:1380px;margin:0 auto;padding:20px}
.vs-top{display:flex;justify-content:space-between;align-items:center;gap:16px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;padding:20px 24px;border-radius:18px;margin-bottom:22px}
.vs-top h1{margin:0;font-size:24px}.vs-top p{margin:4px 0 0;opacity:.92}
.vs-grid{display:grid;grid-template-columns:1.1fr .95fr;gap:22px;align-items:start}
.vs-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.vs-body{padding:20px}.vs-body h3{margin:0 0 14px 0}
.vs-table{width:100%;border-collapse:collapse}.vs-table th,.vs-table td{padding:14px;border-bottom:1px solid #eef2f7;vertical-align:top}.vs-table th{font-size:11px;text-transform:uppercase;color:#64748b;background:#f8fafc;text-align:left}
.vs-rowtitle{font-weight:700;color:#0f172a}.vs-sub{font-size:12px;color:#64748b;margin-top:4px}
.vs-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:700;margin:2px 6px 2px 0}
.vs-ok{background:#dcfce7;color:#166534}.vs-no{background:#fee2e2;color:#991b1b}
.vs-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.vs-full{grid-column:1/-1}
.vs-label{display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin:0 0 6px}
.vs-input,.vs-select{width:100%;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px;background:#fff}
.vs-box{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fafafa}
.vs-actions{display:flex;gap:8px;justify-content:flex-end}
.btn2{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:12px;text-decoration:none;border:none;cursor:pointer;font-weight:700}
.btn2-primary{background:#2563eb;color:#fff}.btn2-light{background:#eff6ff;color:#1d4ed8}.btn2-danger{background:#fee2e2;color:#991b1b}.btn2-add{background:#dbeafe;color:#1d4ed8}
.default-row{border:1px solid #dbe3ef;border-radius:14px;padding:12px;background:#fff;margin-top:10px}
.default-row-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.default-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;font-weight:700;color:#0f172a}
.helper{font-size:12px;color:#64748b;margin-top:6px}
.flash-ok{color:#166534}.flash-box{margin-bottom:18px;border-left:5px solid #10b981}
@media (max-width:1100px){.vs-grid{grid-template-columns:1fr}.vs-form-grid,.default-row-grid{grid-template-columns:1fr}.vs-top{flex-direction:column;align-items:flex-start}}
</style>

<div class="vs-wrap">
  <div class="vs-top">
    <div>
      <h1>⚙️ Vorgangsarten</h1>
      <p>Hier legst du fest, wer angesprochen wird, ob BKP nötig ist und welche Standard-Empfänger zu dieser Vorgangsart gehören.</p>
    </div>
    <a href="pendenzen.php" class="btn2 btn2-light">Zurück</a>
  </div>

  <?php if ($flash || isset($_GET['flash'])): ?>
    <div class="vs-card flash-box"><div class="vs-body flash-ok"><?= h($flash ?: ($_GET['flash'] ?? '')) ?></div></div>
  <?php endif; ?>

  <div class="vs-grid">
    <div class="vs-card">
      <table class="vs-table">
        <thead>
          <tr>
            <th>Vorgangsart</th>
            <th>Standardwerte</th>
            <th>Freigaben</th>
            <th style="text-align:right">Aktion</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($arten as $a):
          $artId = (int)$a['id'];
          $defaults = $defaultsByArt[$artId] ?? [];
          $defaultsShort = [];
          foreach ($defaults as $d) {
              $label = trim(($d['benutzer_name'] ?? '') . (!empty($d['firma_name']) ? ' · ' . $d['firma_name'] : ''));
              if ($label === '') { $label = 'Eintrag #' . (int)$d['id']; }
              if (!empty($d['bkp_name'])) { $label .= ' · ' . trim((string)$d['bkp_name']); }
              $defaultsShort[] = $label;
          }
        ?>
          <tr>
            <td>
              <div class="vs-rowtitle"><?= h(($a['icon'] ?: '📝') . ' ' . $a['name']) ?></div>
              <div class="vs-sub">/<?= h($a['slug']) ?></div>
              <div class="vs-sub">Vorlagen: <strong><?= h($a['vorlagen_welt'] ?? 'bkp') ?></strong></div>
              <?php if (!empty($a['person_type_name'])): ?>
                <div class="vs-sub">Empfänger: <strong><?= h($a['person_type_name']) ?></strong><?php if (!empty($a['person_status_name'])): ?> · <?= h($a['person_status_name']) ?><?php endif; ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($defaults): ?>
                <?php foreach (array_slice($defaultsShort, 0, 4) as $line): ?><div class="vs-sub">• <?= h($line) ?></div><?php endforeach; ?>
                <?php if (count($defaultsShort) > 4): ?><div class="vs-sub">+ <?= count($defaultsShort) - 4 ?> weitere</div><?php endif; ?>
              <?php else: ?>
                <div class="vs-sub">— keine Standard-Empfänger —</div>
              <?php endif; ?>
            </td>
            <td>
              <span class="vs-badge <?= !empty($a['bkp_erforderlich']) ? 'vs-ok' : 'vs-no' ?>">BKP <?= !empty($a['bkp_erforderlich']) ? 'Pflicht' : 'optional' ?></span>
              <span class="vs-badge <?= !empty($a['nur_firmen_bkp']) ? 'vs-ok' : 'vs-no' ?>">Firmen-BKP <?= !empty($a['nur_firmen_bkp']) ? 'aktiv' : 'aus' ?></span>
              <span class="vs-badge <?= !empty($a['is_active']) ? 'vs-ok' : 'vs-no' ?>"><?= !empty($a['is_active']) ? 'Aktiv' : 'Inaktiv' ?></span>
            </td>
            <td style="text-align:right">
              <div class="vs-actions">
                <button type="button" class="btn2 btn2-light" onclick='editArt(<?= json_encode($a, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($defaults, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>Bearbeiten</button>
                <a class="btn2 btn2-danger" href="?action=delete&id=<?= $artId ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="vs-card">
      <div class="vs-body">
        <h3 id="formTitle">Vorgangsart erstellen</h3>
        <form method="post" id="artForm">
          <input type="hidden" name="id" id="f_id" value="0">
          <div class="vs-form-grid">
            <div>
              <label class="vs-label">Name*</label>
              <input class="vs-input" type="text" name="name" id="f_name" required>
            </div>
            <div>
              <label class="vs-label">Slug</label>
              <input class="vs-input" type="text" name="slug" id="f_slug" placeholder="optional">
            </div>
            <div>
              <label class="vs-label">Icon</label>
              <input class="vs-input" type="text" name="icon" id="f_icon" placeholder="📝">
            </div>
            <div>
              <label class="vs-label">Farbe</label>
              <input class="vs-input" type="color" name="color" id="f_color" value="#3b82f6" style="height:44px;padding:4px">
            </div>
            <div>
              <label class="vs-label">Sortierung</label>
              <input class="vs-input" type="number" name="sort_order" id="f_sort" value="100">
            </div>
            <div style="display:flex;align-items:flex-end">
              <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="is_active" id="f_active" checked> Aktiv</label>
            </div>

            <div class="vs-full vs-box">
              <div class="vs-label">Logik</div>
              <div class="vs-form-grid">
                <div>
                  <label class="vs-label">Vorlagenquelle</label>
                  <select class="vs-select" name="vorlagen_welt" id="f_vorlagen_welt">
                    <option value="bkp">BKP</option>
                    <option value="mieter">Mieter</option>
                    <option value="vermieter">Vermieter</option>
                  </select>
                </div>
                <div>
                  <label class="vs-label">Empfänger Typ</label>
                  <select class="vs-select" name="empfaenger_person_type_id" id="f_empfaenger_person_type_id">
                    <option value="">— Typ wählen —</option>
                    <?php foreach ($personTypes as $t): ?>
                      <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="vs-label">Empfänger Status</label>
                  <select class="vs-select" name="empfaenger_person_status_id" id="f_empfaenger_person_status_id">
                    <option value="">— optional —</option>
                    <?php foreach ($statuses as $s): ?>
                      <option value="<?= (int)$s['id'] ?>" data-type-id="<?= (int)$s['type_id'] ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="vs-label">BKP-Regel</label>
                  <div class="vs-box" style="padding:10px 12px;background:#fff">
                    <label style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><input type="checkbox" name="bkp_erforderlich" id="f_bkp_erforderlich"> BKP ist Pflicht</label>
                    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="nur_firmen_bkp" id="f_nur_firmen_bkp"> nur aktive Firmen-BKP verwenden</label>
                  </div>
                </div>
              </div>
              <div class="helper">Unternehmer = meist BKP + nur aktive Firmen-BKP. Mieter = Mieter-Vorlagen. Vermieter = Vermieter-Vorlagen.</div>
            </div>

            <div class="vs-full vs-box">
              <div class="default-head">
                <span>Standard-Empfänger & Zuordnungen</span>
                <button type="button" class="btn2 btn2-add" onclick="addDefaultRow()">+ Empfänger hinzufügen</button>
              </div>
              <div class="helper">Hier kannst du im gleichen Vorgangsart mehrere Unternehmer, Mieter oder Vermieter festlegen. Jeder Eintrag kann eigenes Projekt, Objekt, Wohnung und BKP haben. Bei Unternehmern kannst du auch direkt alle aktiven Firmen-BKP übernehmen.</div>
              <div id="defaultsContainer"></div>
            </div>

            <div class="vs-full" style="display:flex;gap:10px;justify-content:flex-end">
              <button type="button" class="btn2 btn2-light" onclick="resetForm()">Neu</button>
              <button type="submit" class="btn2 btn2-primary">Speichern</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<template id="defaultRowTemplate">
  <div class="default-row">
    <div class="default-head">
      <span>Empfänger-Eintrag</span>
      <button type="button" class="btn2 btn2-danger" onclick="this.closest('.default-row').remove()">Entfernen</button>
    </div>
    <div class="default-row-grid">
      <div>
        <label class="vs-label">Benutzer / Unternehmer / Mieter</label>
        <select class="vs-select row-benutzer" name="default_benutzer_id[]" onchange="filterRowBkpByBenutzer(this.closest('.default-row'))">
          <option value="">— auswählen —</option>
          <?php foreach ($benutzer as $b):
            $label = $b['name'] . (!empty($b['firma_name']) ? ' · ' . $b['firma_name'] : '');
            $meta = trim(($b['person_type_name'] ?? '') . (!empty($b['person_status_name']) ? ' / ' . $b['person_status_name'] : ''));
          ?>
            <option value="<?= (int)$b['id'] ?>" data-type-id="<?= (int)($b['person_type_id'] ?? 0) ?>" data-status-id="<?= (int)($b['person_status_id'] ?? 0) ?>" data-label="<?= h($meta) ?>"><?= h($label . ($meta !== '' ? ' — ' . $meta : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="helper row-bkp-helper"></div>
      </div>
      <div>
        <label class="vs-label">BKP-Modus</label>
        <select class="vs-select row-bkp-mode" name="default_bkp_mode[]" onchange="updateRowBkpMode(this.closest('.default-row'))">
          <option value="single">Eine BKP auswählen</option>
          <option value="all_firma">Alle aktiven Firmen-BKP</option>
          <option value="none">Keine BKP</option>
        </select>
        <div class="helper">Bei Unternehmern ist "Alle aktiven Firmen-BKP" meist am besten.</div>
      </div>
      <div>
        <label class="vs-label">BKP</label>
        <select class="vs-select row-bkp" name="default_bkp_id[]">
          <option value="">— keine BKP —</option>
          <?php foreach ($bkpCodes as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h(trim($c['code'] . ' ' . $c['bezeichnung'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="vs-label">Projekt</label>
        <select class="vs-select row-projekt" name="default_projekt_id[]" onchange="filterRowObjekte(this)">
          <option value="">— kein Projekt —</option>
          <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="vs-label">Objekt</label>
        <select class="vs-select row-objekt" name="default_objekt_id[]" onchange="filterRowWohnungen(this)">
          <option value="">— kein Objekt —</option>
          <?php foreach ($objects as $o): ?><option value="<?= (int)$o['id'] ?>" data-projekt-id="<?= (int)$o['projekt_id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="vs-label">Wohnung</label>
        <select class="vs-select row-wohnung" name="default_wohnung_id[]">
          <option value="">— keine Wohnung —</option>
          <?php foreach ($wohnungen as $w): ?><option value="<?= (int)$w['id'] ?>" data-objekt-id="<?= (int)$w['objekt_id'] ?>"><?= h($w['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="vs-label">Status</label>
        <select class="vs-select row-active" name="default_is_active[]">
          <option value="1">aktiv</option>
          <option value="0">inaktiv</option>
        </select>
      </div>
    </div>
  </div>
</template>

<script>
const USER_FIRMA_BKP_MAP = <?= json_encode($userFirmaBkpMap, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

function filterStatusOptions() {
  const typeId = document.getElementById('f_empfaenger_person_type_id').value;
  const select = document.getElementById('f_empfaenger_person_status_id');
  Array.from(select.options).forEach(opt => {
    if (!opt.value) { opt.hidden = false; return; }
    opt.hidden = typeId && opt.dataset.typeId !== typeId;
  });
  if (select.selectedOptions[0] && select.selectedOptions[0].hidden) select.value = '';
}

function syncBkpUi() {
  const welt = document.getElementById('f_vorlagen_welt').value;
  const showBkp = welt === 'bkp';
  document.querySelectorAll('.row-bkp').forEach(el => {
    el.disabled = !showBkp;
    el.closest('div').style.opacity = showBkp ? '1' : '.55';
    filterRowBkpByBenutzer(el.closest('.default-row'));
  });
}
function updateRowBkpMode(row) {
  if (!row) return;
  const mode = row.querySelector('.row-bkp-mode')?.value || 'single';
  const bkpWrap = row.querySelector('.row-bkp')?.closest('div');
  const bkpSelect = row.querySelector('.row-bkp');
  if (!bkpSelect || !bkpWrap) return;
  const showBkp = mode === 'single' && document.getElementById('f_vorlagen_welt').value === 'bkp';
  bkpSelect.disabled = !showBkp;
  bkpWrap.style.opacity = showBkp ? '1' : '.55';
  if (!showBkp) {
    bkpSelect.value = '';
  }
}
function filterRowBkpByBenutzer(row) {
  if (!row) return;
  const bkpSelect = row.querySelector('.row-bkp');
  const benutzerSelect = row.querySelector('.row-benutzer');
  const modeSelect = row.querySelector('.row-bkp-mode');
  const mode = modeSelect?.value || 'single';
  const nurFirmenBkp = document.getElementById('f_nur_firmen_bkp').checked;
  const welt = document.getElementById('f_vorlagen_welt').value;
  const userId = String(benutzerSelect?.value || '');
  const allowed = userId && USER_FIRMA_BKP_MAP[userId] ? USER_FIRMA_BKP_MAP[userId].map(String) : [];
  const enforce = welt === 'bkp' && nurFirmenBkp && allowed.length > 0;
  Array.from(bkpSelect.options).forEach(opt => {
    if (!opt.value) {
      opt.hidden = false;
      return;
    }
    opt.hidden = enforce ? !allowed.includes(String(opt.value)) : false;
  });
  if (bkpSelect.selectedOptions[0] && bkpSelect.selectedOptions[0].hidden) {
    bkpSelect.value = '';
  }
  const helper = row.querySelector('.row-bkp-helper');
  if (helper) {
    if (welt !== 'bkp') {
      helper.textContent = 'BKP ist nur bei Vorlagenquelle BKP relevant.';
    } else if (allowed.length > 0 && mode === 'all_firma') {
      const labels = Array.from(bkpSelect.options)
        .filter(opt => opt.value && allowed.includes(String(opt.value)))
        .map(opt => opt.textContent.trim());
      helper.textContent = 'Es werden alle aktiven Firmen-BKP verwendet: ' + labels.join(', ');
    } else if (allowed.length > 0) {
      const labels = Array.from(bkpSelect.options)
        .filter(opt => opt.value && allowed.includes(String(opt.value)))
        .map(opt => opt.textContent.trim());
      helper.textContent = 'Aktive Firmen-BKP: ' + labels.join(', ');
    } else if (nurFirmenBkp && userId) {
      helper.textContent = 'Für diesen Benutzer/Firma sind keine aktiven BKP in firmen.php hinterlegt.';
    } else {
      helper.textContent = '';
    }
  }
  updateRowBkpMode(row);
}
function filterRowObjekte(projectSelect) {
  const row = projectSelect.closest('.default-row');
  const projektId = projectSelect.value;
  const objekt = row.querySelector('.row-objekt');
  Array.from(objekt.options).forEach(opt => {
    if (!opt.value) { opt.hidden = false; return; }
    opt.hidden = projektId && opt.dataset.projektId !== projektId;
  });
  if (objekt.selectedOptions[0] && objekt.selectedOptions[0].hidden) objekt.value = '';
  filterRowWohnungen(objekt);
}
function filterRowWohnungen(objektSelect) {
  const row = objektSelect.closest('.default-row');
  const objektId = objektSelect.value;
  const wohn = row.querySelector('.row-wohnung');
  Array.from(wohn.options).forEach(opt => {
    if (!opt.value) { opt.hidden = false; return; }
    opt.hidden = objektId && opt.dataset.objektId !== objektId;
  });
  if (wohn.selectedOptions[0] && wohn.selectedOptions[0].hidden) wohn.value = '';
}
function filterRowBenutzerByType(row) {
  const typeId = document.getElementById('f_empfaenger_person_type_id').value;
  const statusId = document.getElementById('f_empfaenger_person_status_id').value;
  const select = row.querySelector('.row-benutzer');
  Array.from(select.options).forEach(opt => {
    if (!opt.value) { opt.hidden = false; return; }
    let hidden = false;
    if (typeId && opt.dataset.typeId !== typeId) hidden = true;
    if (!hidden && statusId && opt.dataset.statusId !== statusId) hidden = true;
    opt.hidden = hidden;
  });
  if (select.selectedOptions[0] && select.selectedOptions[0].hidden) select.value = '';
}

function addDefaultRow(data = {}) {
  const tpl = document.getElementById('defaultRowTemplate');
  const node = tpl.content.firstElementChild.cloneNode(true);
  document.getElementById('defaultsContainer').appendChild(node);
  node.querySelector('.row-benutzer').value = data.benutzer_id || '';
  node.querySelector('.row-bkp-mode').value = data.bkp_mode || ((data.bkp_id && String(data.bkp_id) !== '0') ? 'single' : 'all_firma');
  node.querySelector('.row-bkp').value = data.bkp_id || '';
  node.querySelector('.row-projekt').value = data.projekt_id || '';
  node.querySelector('.row-objekt').value = data.objekt_id || '';
  node.querySelector('.row-wohnung').value = data.wohnung_id || '';
  node.querySelector('.row-active').value = String(data.is_active ?? '1');
  filterRowObjekte(node.querySelector('.row-projekt'));
  filterRowBenutzerByType(node);
  filterRowBkpByBenutzer(node);
  updateRowBkpMode(node);
}
function editArt(art, defaults) {
  document.getElementById('formTitle').textContent = 'Vorgangsart bearbeiten';
  document.getElementById('f_id').value = art.id || 0;
  document.getElementById('f_name').value = art.name || '';
  document.getElementById('f_slug').value = art.slug || '';
  document.getElementById('f_icon').value = art.icon || '📝';
  document.getElementById('f_color').value = art.color || art.farbe || '#3b82f6';
  document.getElementById('f_sort').value = art.sort_order || 100;
  document.getElementById('f_active').checked = String(art.is_active || '0') === '1';
  document.getElementById('f_vorlagen_welt').value = art.vorlagen_welt || 'bkp';
  document.getElementById('f_empfaenger_person_type_id').value = art.empfaenger_person_type_id || '';
  filterStatusOptions();
  document.getElementById('f_empfaenger_person_status_id').value = art.empfaenger_person_status_id || '';
  document.getElementById('f_bkp_erforderlich').checked = String(art.bkp_erforderlich || '0') === '1';
  document.getElementById('f_nur_firmen_bkp').checked = String(art.nur_firmen_bkp || '0') === '1';
  document.getElementById('defaultsContainer').innerHTML = '';
  (defaults || []).forEach(item => addDefaultRow(item));
  if (!defaults || !defaults.length) addDefaultRow();
  syncBkpUi();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
function resetForm() {
  document.getElementById('formTitle').textContent = 'Vorgangsart erstellen';
  document.getElementById('artForm').reset();
  document.getElementById('f_id').value = '0';
  document.getElementById('f_color').value = '#3b82f6';
  document.getElementById('defaultsContainer').innerHTML = '';
  addDefaultRow();
  filterStatusOptions();
  syncBkpUi();
}
document.getElementById('f_empfaenger_person_type_id').addEventListener('change', () => {
  filterStatusOptions();
  document.querySelectorAll('.default-row').forEach(filterRowBenutzerByType);
});
document.getElementById('f_empfaenger_person_status_id').addEventListener('change', () => {
  document.querySelectorAll('.default-row').forEach(filterRowBenutzerByType);
});
document.getElementById('f_vorlagen_welt').addEventListener('change', syncBkpUi);
document.getElementById('f_nur_firmen_bkp').addEventListener('change', syncBkpUi);
resetForm();
<?php if (!empty($_GET['edit'])):
  $editWanted = (int)$_GET['edit'];
  $editArtData = null;
  foreach ($arten as $tmpArt) {
      if ((int)$tmpArt['id'] === $editWanted) {
          $editArtData = $tmpArt;
          break;
      }
  }
  if ($editArtData): ?>
editArt(<?= json_encode($editArtData, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($defaultsByArt[$editWanted] ?? [], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>);
<?php endif; endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
