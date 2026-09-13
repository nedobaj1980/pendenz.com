<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';

if (!function_exists('h')) {
    function h(mixed $v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$PAGE_TITLE = 'Mietervorlagen';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die('Keine gültige Datenbankverbindung vorhanden.');
}


if (!function_exists('column_exists_pm')) {
    function column_exists_pm(mysqli $mysqli, string $table, string $column): bool
    {
        $table = $mysqli->real_escape_string($table);
        $column = $mysqli->real_escape_string($column);
        $res = $mysqli->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        if (!$res) {
            return false;
        }
        $ok = $res->num_rows > 0;
        $res->close();
        return $ok;
    }
}

if (!function_exists('ensure_column_pm')) {
    function ensure_column_pm(mysqli $mysqli, string $table, string $column, string $definition): void
    {
        if (!column_exists_pm($mysqli, $table, $column)) {
            $mysqli->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}


if (!function_exists('safe_redirect_pm')) {
    function safe_redirect_pm(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }
        $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"></noscript>';
        exit;
    }
}


$flash = '';
$prefix = function_exists('site_prefix') ? site_prefix() : '/';

$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_kategorien_mieter (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    projekt_id INT NULL,
    objekt_id INT NULL,
    name VARCHAR(255) NOT NULL,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_projekt (projekt_id),
    KEY idx_objekt (objekt_id),
    KEY idx_aktiv (aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_subkategorien_mieter (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kategorie_id INT NOT NULL,
    projekt_id INT NULL,
    objekt_id INT NULL,
    name VARCHAR(255) NOT NULL,
    beschreibung TEXT NULL,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_kategorie (kategorie_id),
    KEY idx_projekt (projekt_id),
    KEY idx_objekt (objekt_id),
    KEY idx_aktiv (aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

ensure_column_pm($mysqli, 'pendenz_kategorien_mieter', 'projekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_kategorien_mieter', 'objekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_kategorien_mieter', 'sortierung', 'INT NOT NULL DEFAULT 0');
ensure_column_pm($mysqli, 'pendenz_kategorien_mieter', 'aktiv', 'TINYINT(1) NOT NULL DEFAULT 1');
ensure_column_pm($mysqli, 'pendenz_subkategorien_mieter', 'projekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_subkategorien_mieter', 'objekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_subkategorien_mieter', 'beschreibung', 'TEXT NULL');
ensure_column_pm($mysqli, 'pendenz_subkategorien_mieter', 'sortierung', 'INT NOT NULL DEFAULT 0');
ensure_column_pm($mysqli, 'pendenz_subkategorien_mieter', 'aktiv', 'TINYINT(1) NOT NULL DEFAULT 1');

$projekte = [];
$res = $mysqli->query("SELECT id, name FROM projekte ORDER BY name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $projekte[] = $row;
    }
    $res->close();
}

$objekte = [];
$res = $mysqli->query("SELECT id, projekt_id, name FROM objekte ORDER BY projekt_id ASC, name ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $objekte[] = $row;
    }
    $res->close();
}

$projektMap = [];
foreach ($projekte as $p) {
    $projektMap[(int)$p['id']] = $p['name'];
}

$objektMap = [];
$objekteByProjekt = [];
foreach ($objekte as $o) {
    $objektMap[(int)$o['id']] = $o;
    $pid = (int)($o['projekt_id'] ?? 0);
    $objekteByProjekt[$pid][] = $o;
}

function toNullableInt(string $key): ?int
{
    $value = trim((string)($_POST[$key] ?? ''));
    if ($value === '' || $value === '0') {
        return null;
    }
    return ctype_digit($value) ? (int)$value : null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = (string)($_POST['act'] ?? '');
        $redirect = 'pendenz_kategorien_mieter.php';
        $filterProjekt = trim((string)($_POST['filter_projekt_id'] ?? ''));
        $filterObjekt = trim((string)($_POST['filter_objekt_id'] ?? ''));
        $qs = [];
        if ($filterProjekt !== '') {
            $qs[] = 'projekt_id=' . urlencode($filterProjekt);
        }
        if ($filterObjekt !== '') {
            $qs[] = 'objekt_id=' . urlencode($filterObjekt);
        }
        if ($qs) {
            $redirect .= '?' . implode('&', $qs);
        }

        if ($act === 'add_cat') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Titel Kategorie eingeben.');
            }
            $projektId = toNullableInt('projekt_id');
            $objektId = toNullableInt('objekt_id');
            $stmt = $mysqli->prepare("INSERT INTO pendenz_kategorien_mieter (name, projekt_id, objekt_id) VALUES (?, ?, ?)");
            $stmt->bind_param('sii', $name, $projektId, $objektId);
            $stmt->execute();
            $stmt->close();
            safe_redirect_pm($redirect);
        }

        if ($act === 'save_cat') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Kategorie konnte nicht gespeichert werden.');
            }
            $projektId = toNullableInt('projekt_id');
            $objektId = toNullableInt('objekt_id');
            $aktiv = isset($_POST['aktiv']) ? 1 : 0;
            $stmt = $mysqli->prepare("UPDATE pendenz_kategorien_mieter SET name = ?, projekt_id = ?, objekt_id = ?, aktiv = ? WHERE id = ?");
            $stmt->bind_param('siiii', $name, $projektId, $objektId, $aktiv, $id);
            $stmt->execute();
            $stmt->close();
            safe_redirect_pm($redirect);
        }

        if ($act === 'delete_cat') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Kategorie-ID fehlt.');
            }
            $stmt = $mysqli->prepare("DELETE FROM pendenz_subkategorien_mieter WHERE kategorie_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM pendenz_kategorien_mieter WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            safe_redirect_pm($redirect);
        }

        if ($act === 'add_sub') {
            $cid = (int)($_POST['cid'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($cid <= 0 || $name === '') {
                throw new RuntimeException('Kurzbeschreibung eingeben.');
            }
            $beschreibung = trim((string)($_POST['beschreibung'] ?? ''));
            $stmt = $mysqli->prepare("
                INSERT INTO pendenz_subkategorien_mieter (kategorie_id, name, beschreibung, projekt_id, objekt_id)
                SELECT id, ?, ?, projekt_id, objekt_id
                FROM pendenz_kategorien_mieter
                WHERE id = ?
            ");
            $stmt->bind_param('ssi', $name, $beschreibung, $cid);
            $stmt->execute();
            $stmt->close();
            safe_redirect_pm($redirect);
        }

        if ($act === 'save_sub') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $beschreibung = trim((string)($_POST['beschreibung'] ?? ''));
            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Kurzbeschreibung konnte nicht gespeichert werden.');
            }
            $aktiv = isset($_POST['aktiv']) ? 1 : 0;
            $stmt = $mysqli->prepare("UPDATE pendenz_subkategorien_mieter SET name = ?, beschreibung = ?, aktiv = ? WHERE id = ?");
            $stmt->bind_param('ssii', $name, $beschreibung, $aktiv, $id);
            $stmt->execute();
            $stmt->close();
            safe_redirect_pm($redirect);
        }

        if ($act === 'delete_sub') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Kurzbeschreibung-ID fehlt.');
            }
            $stmt = $mysqli->prepare("DELETE FROM pendenz_subkategorien_mieter WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            safe_redirect_pm($redirect);
        }
    }
} catch (Throwable $e) {
    $flash = $e->getMessage();
}

$filterProjektId = isset($_GET['projekt_id']) && $_GET['projekt_id'] !== '' ? (int)$_GET['projekt_id'] : 0;
$filterObjektId = isset($_GET['objekt_id']) && $_GET['objekt_id'] !== '' ? (int)$_GET['objekt_id'] : 0;

$where = [];
if ($filterProjektId > 0) {
    $where[] = "(k.projekt_id = {$filterProjektId} OR k.projekt_id IS NULL)";
}
if ($filterObjektId > 0) {
    $where[] = "(k.objekt_id = {$filterObjektId} OR k.objekt_id IS NULL)";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$kategorien = [];
$sql = "
    SELECT
        k.id,
        k.name,
        k.projekt_id,
        k.objekt_id,
        k.aktiv,
        p.name AS projekt_name,
        o.name AS objekt_name
    FROM pendenz_kategorien_mieter k
    LEFT JOIN projekte p ON p.id = k.projekt_id
    LEFT JOIN objekte o ON o.id = k.objekt_id
    {$whereSql}
    ORDER BY COALESCE(k.projekt_id, 0) ASC, COALESCE(k.objekt_id, 0) ASC, k.sortierung ASC, k.name ASC
";
$res = $mysqli->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $kategorien[] = $row;
    }
    $res->close();
}

$subsByCat = [];
$res = $mysqli->query("
    SELECT id, kategorie_id, name, beschreibung, aktiv
    FROM pendenz_subkategorien_mieter
    ORDER BY sortierung ASC, name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $subsByCat[(int)$row['kategorie_id']][] = $row;
    }
    $res->close();
}

$pageDescription = 'Titel Kategorien und Kurzbeschreibungen für Mieter klar und kompakt verwalten.';
?>

<style>
body{background:#f1f5f9}
.kv-wrap{max-width:1280px;margin:0 auto;padding:24px}
.kv-hero{background:linear-gradient(135deg,#1abc9c,#0e8a72);border-radius:16px;padding:24px 28px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.kv-hero h1{margin:0;color:#fff;font-size:24px;font-weight:800}
.kv-hero p{margin:6px 0 0;color:rgba(255,255,255,.88);font-size:13px}
.kv-links{display:flex;gap:8px;flex-wrap:wrap}
.kv-links a{display:inline-flex;align-items:center;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.16);color:#fff;text-decoration:none;border:1px solid rgba(255,255,255,.22);font-weight:700}
.kv-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;box-shadow:0 10px 30px rgba(15,23,42,.04);margin-bottom:18px}
.kv-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end}
.kv-input,.kv-select,.kv-textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff}
.kv-textarea{min-height:80px;resize:vertical}
.kv-label{display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px}
.kv-btn{border:0;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer}
.kv-btn-primary{background:#0f62fe;color:#fff}
.kv-btn-light{background:#e2e8f0;color:#0f172a}
.kv-btn-danger{background:#fee2e2;color:#991b1b}
.kv-btn-save{background:#dcfce7;color:#166534}
.kv-filter{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
.kv-filter > div{min-width:220px;flex:1}
.kv-list{display:flex;flex-direction:column;gap:12px}
.kv-row{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden}
.kv-row-head{display:flex;gap:12px;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap}
.kv-row-head-left{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.kv-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:700}
.kv-badge-global{background:#e0f2fe;color:#075985}
.kv-badge-proj{background:#ede9fe;color:#6d28d9}
.kv-badge-obj{background:#ecfccb;color:#3f6212}
.kv-badge-soft{background:#f8fafc;color:#475569;border:1px solid #e2e8f0}
.kv-title-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.kv-title-strong{font-size:18px;font-weight:800;color:#0f172a}
.kv-row-body{padding:16px}
.kv-row-form{display:grid;grid-template-columns:1.8fr 1fr 1fr auto auto;gap:10px;align-items:end}
.kv-subs{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.kv-sub-chip{min-width:260px;flex:1 1 320px;border:1px solid #dbe2ea;border-radius:12px;background:#f8fafc;padding:10px 12px}
.kv-sub-name{font-weight:800;color:#0f172a;margin-bottom:4px}
.kv-sub-desc{font-size:13px;color:#475569;line-height:1.4;white-space:pre-wrap}
.kv-sub-actions{display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap}
.kv-sub-actions-left{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.kv-sub-add{border:1px dashed #cbd5e1;border-radius:12px;background:#fff;padding:12px;min-width:280px;flex:1 1 360px}
.kv-empty{padding:16px;border-radius:12px;background:#f8fafc;color:#64748b}
.kv-switch{display:flex;align-items:center;gap:8px;white-space:nowrap}
.kv-check{width:18px;height:18px}
.kv-muted{color:#64748b;font-size:13px}
details.kv-edit{margin-top:10px}
details.kv-edit > summary{cursor:pointer;list-style:none;font-weight:700;color:#334155}
details.kv-edit > summary::-webkit-details-marker{display:none}
details.kv-edit[open] > summary{margin-bottom:10px}
@media (max-width: 980px){
  .kv-grid,.kv-row-form{grid-template-columns:1fr}
  .kv-filter > div{min-width:unset;flex:unset;width:100%}
}
</style>

<div class="kv-wrap">
    <div class="kv-hero">
        <div>
            <h1><?= h($PAGE_TITLE) ?></h1>
            <p><?= h($pageDescription) ?></p>
        </div>
        <div class="kv-links">
            <a href="pendenz_kategorien.php">Vorlagenübersicht</a>
            <a href="pendenzen.php">Zurück Pendenzen</a>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="kv-card" style="border-color:#fecaca;background:#fff7f7;color:#991b1b;font-weight:700;">
            <?= h($flash) ?>
        </div>
    <?php endif; ?>

    <div class="kv-card">
        <form method="get" class="kv-filter">
            <div>
                <label class="kv-label">Projekt</label>
                <select name="projekt_id" id="filter_projekt_id" class="kv-select">
                    <option value="">Alle Projekte</option>
                    <?php foreach ($projekte as $projekt): ?>
                        <option value="<?= (int)$projekt['id'] ?>" <?= $filterProjektId === (int)$projekt['id'] ? 'selected' : '' ?>>
                            <?= h($projekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="kv-label">Objekt</label>
                <select name="objekt_id" id="filter_objekt_id" class="kv-select">
                    <option value="">Alle Objekte</option>
                    <?php foreach ($objekte as $objekt): ?>
                        <option value="<?= (int)$objekt['id'] ?>" data-projekt-id="<?= (int)$objekt['projekt_id'] ?>" <?= $filterObjektId === (int)$objekt['id'] ? 'selected' : '' ?>>
                            <?= h($objekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="align-self:end">
                <button type="submit" class="kv-btn kv-btn-primary">Anzeigen</button>
            </div>
        </form>
    </div>

    <div class="kv-card">
        <form method="post" class="kv-grid">
            <input type="hidden" name="act" value="add_cat">
            <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
            <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">
            <div>
                <label class="kv-label">Neue Titel Kategorie</label>
                <input type="text" name="name" class="kv-input" placeholder="Titel Kategorie eingeben" required>
            </div>
            <div>
                <label class="kv-label">Projektbezug</label>
                <select name="projekt_id" id="new_projekt_id" class="kv-select">
                    <option value="">Global</option>
                    <?php foreach ($projekte as $projekt): ?>
                        <option value="<?= (int)$projekt['id'] ?>" <?= $filterProjektId === (int)$projekt['id'] ? 'selected' : '' ?>>
                            <?= h($projekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="kv-label">Objektbezug</label>
                <select name="objekt_id" id="new_objekt_id" class="kv-select">
                    <option value="">Kein Objekt</option>
                    <?php foreach ($objekte as $objekt): ?>
                        <option value="<?= (int)$objekt['id'] ?>" data-projekt-id="<?= (int)$objekt['projekt_id'] ?>" <?= $filterObjektId === (int)$objekt['id'] ? 'selected' : '' ?>>
                            <?= h($objekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="kv-btn kv-btn-primary">Kategorie hinzufügen</button>
            </div>
        </form>
    </div>

    <div class="kv-list">
        <?php if (!$kategorien): ?>
            <div class="kv-card">
                <div class="kv-empty">Noch keine Vorlagen vorhanden.</div>
            </div>
        <?php else: ?>
            <?php foreach ($kategorien as $kat): ?>
                <?php $katId = (int)$kat['id']; $subs = $subsByCat[$katId] ?? []; ?>
                <div class="kv-row">
                    <div class="kv-row-head">
                        <div>
                            <div class="kv-title-line">
                                <span class="kv-title-strong"><?= h($kat['name']) ?></span>
                                <?php if ((int)$kat['aktiv'] !== 1): ?>
                                    <span class="kv-badge kv-badge-soft">Inaktiv</span>
                                <?php endif; ?>
                            </div>
                            <div class="kv-row-head-left" style="margin-top:8px">
                                <?php if ((int)($kat['projekt_id'] ?? 0) > 0): ?>
                                    <span class="kv-badge kv-badge-proj">Projekt · <?= h($kat['projekt_name'] ?? '') ?></span>
                                <?php else: ?>
                                    <span class="kv-badge kv-badge-global">Global</span>
                                <?php endif; ?>
                                <?php if ((int)($kat['objekt_id'] ?? 0) > 0): ?>
                                    <span class="kv-badge kv-badge-obj">Objekt · <?= h($kat['objekt_name'] ?? '') ?></span>
                                <?php endif; ?>
                                <span class="kv-badge kv-badge-soft"><?= count($subs) ?> Kurzbeschreibungen</span>
                            </div>
                        </div>
                    </div>

                    <div class="kv-row-body">
                        <form method="post" class="kv-row-form">
                            <input type="hidden" name="act" value="save_cat">
                            <input type="hidden" name="id" value="<?= $katId ?>">
                            <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                            <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">
                            <div>
                                <label class="kv-label">Titel Kategorie</label>
                                <input type="text" name="name" class="kv-input" value="<?= h($kat['name']) ?>" required>
                            </div>
                            <div>
                                <label class="kv-label">Projekt</label>
                                <select name="projekt_id" class="kv-select js-projekt-select" data-target="cat_objekt_<?= $katId ?>">
                                    <option value="">Global</option>
                                    <?php foreach ($projekte as $projekt): ?>
                                        <option value="<?= (int)$projekt['id'] ?>" <?= (int)$kat['projekt_id'] === (int)$projekt['id'] ? 'selected' : '' ?>>
                                            <?= h($projekt['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="kv-label">Objekt</label>
                                <select name="objekt_id" id="cat_objekt_<?= $katId ?>" class="kv-select">
                                    <option value="">Kein Objekt</option>
                                    <?php foreach ($objekte as $objekt): ?>
                                        <option value="<?= (int)$objekt['id'] ?>" data-projekt-id="<?= (int)$objekt['projekt_id'] ?>" <?= (int)$kat['objekt_id'] === (int)$objekt['id'] ? 'selected' : '' ?>>
                                            <?= h($objekt['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <label class="kv-switch">
                                <input type="checkbox" class="kv-check" name="aktiv" value="1" <?= (int)$kat['aktiv'] === 1 ? 'checked' : '' ?>>
                                aktiv
                            </label>
                            <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
                                <button type="submit" class="kv-btn kv-btn-save">Speichern</button>
                                <button type="submit"
                                        formaction=""
                                        formmethod="post"
                                        name="act"
                                        value="delete_cat"
                                        onclick="this.form.act.value='delete_cat'; return confirm('Kategorie und alle Kurzbeschreibungen löschen?');"
                                        class="kv-btn kv-btn-light">Löschen</button>
                            </div>
                        </form>

                        <div class="kv-subs">
                            <?php foreach ($subs as $sub): ?>
                                <div class="kv-sub-chip">
                                    <div class="kv-sub-name"><?= h($sub['name']) ?></div>
                                    <?php if (trim((string)($sub['beschreibung'] ?? '')) !== ''): ?>
                                        <div class="kv-sub-desc"><?= nl2br(h($sub['beschreibung'])) ?></div>
                                    <?php else: ?>
                                        <div class="kv-muted">Keine Langbeschreibung.</div>
                                    <?php endif; ?>

                                    <div class="kv-sub-actions">
                                        <div class="kv-sub-actions-left">
                                            <?php if ((int)$sub['aktiv'] === 1): ?>
                                                <span class="kv-badge kv-badge-soft">aktiv</span>
                                            <?php else: ?>
                                                <span class="kv-badge kv-badge-soft">inaktiv</span>
                                            <?php endif; ?>
                                        </div>
                                        <details class="kv-edit">
                                            <summary>Bearbeiten</summary>
                                            <form method="post">
                                                <input type="hidden" name="act" value="save_sub">
                                                <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                                                <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                                                <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">

                                                <label class="kv-label">Kurzbeschreibung</label>
                                                <input type="text" name="name" class="kv-input" value="<?= h($sub['name']) ?>" required style="margin-bottom:10px;">

                                                <label class="kv-label">Langbeschreibung</label>
                                                <textarea name="beschreibung" class="kv-textarea"><?= h($sub['beschreibung'] ?? '') ?></textarea>

                                                <div class="kv-sub-actions" style="margin-top:10px">
                                                    <label class="kv-switch">
                                                        <input type="checkbox" class="kv-check" name="aktiv" value="1" <?= (int)$sub['aktiv'] === 1 ? 'checked' : '' ?>>
                                                        aktiv
                                                    </label>
                                                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                                                        <button type="submit" class="kv-btn kv-btn-save">Speichern</button>
                                                        <button type="submit"
                                                                formaction=""
                                                                formmethod="post"
                                                                name="act"
                                                                value="delete_sub"
                                                                onclick="this.form.act.value='delete_sub'; return confirm('Kurzbeschreibung löschen?');"
                                                                class="kv-btn kv-btn-light">Löschen</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </details>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <form method="post" class="kv-sub-add">
                                <input type="hidden" name="act" value="add_sub">
                                <input type="hidden" name="cid" value="<?= $katId ?>">
                                <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                                <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">

                                <label class="kv-label">Kurzbeschreibung hinzufügen</label>
                                <input type="text" name="name" class="kv-input" placeholder="Kurzbeschreibung eingeben" required style="margin-bottom:10px;">
                                <label class="kv-label">Langbeschreibung optional</label>
                                <textarea name="beschreibung" class="kv-textarea" placeholder="Optionaler Zusatztext"></textarea>
                                <div style="display:flex;justify-content:flex-end;margin-top:10px">
                                    <button type="submit" class="kv-btn kv-btn-primary">Hinzufügen</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    function filterObjekte(projektSelect, objektSelectId) {
        var projektId = projektSelect ? String(projektSelect.value || '') : '';
        var objektSelect = document.getElementById(objektSelectId);
        if (!objektSelect) return;
        Array.prototype.forEach.call(objektSelect.options, function(opt, idx){
            if (idx === 0) {
                opt.hidden = false;
                return;
            }
            var optProjekt = String(opt.getAttribute('data-projekt-id') || '');
            var show = !projektId || optProjekt === projektId;
            opt.hidden = !show;
            if (!show && opt.selected) {
                objektSelect.value = '';
            }
        });
    }

    var filterProjekt = document.getElementById('filter_projekt_id');
    if (filterProjekt) {
        filterProjekt.addEventListener('change', function(){
            filterObjekte(filterProjekt, 'filter_objekt_id');
        });
        filterObjekte(filterProjekt, 'filter_objekt_id');
    }

    var newProjekt = document.getElementById('new_projekt_id');
    if (newProjekt) {
        newProjekt.addEventListener('change', function(){
            filterObjekte(newProjekt, 'new_objekt_id');
        });
        filterObjekte(newProjekt, 'new_objekt_id');
    }

    Array.prototype.forEach.call(document.querySelectorAll('.js-projekt-select'), function(el){
        var target = el.getAttribute('data-target');
        el.addEventListener('change', function(){
            filterObjekte(el, target);
        });
        filterObjekte(el, target);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
