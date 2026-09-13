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

$PAGE_TITLE = 'Vermietervorlagen';
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

$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_kategorien_vermieter (
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

$mysqli->query("CREATE TABLE IF NOT EXISTS pendenz_subkategorien_vermieter (
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

ensure_column_pm($mysqli, 'pendenz_kategorien_vermieter', 'projekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_kategorien_vermieter', 'objekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_kategorien_vermieter', 'sortierung', 'INT NOT NULL DEFAULT 0');
ensure_column_pm($mysqli, 'pendenz_kategorien_vermieter', 'aktiv', 'TINYINT(1) NOT NULL DEFAULT 1');
ensure_column_pm($mysqli, 'pendenz_subkategorien_vermieter', 'projekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_subkategorien_vermieter', 'objekt_id', 'INT NULL');
ensure_column_pm($mysqli, 'pendenz_subkategorien_vermieter', 'beschreibung', 'TEXT NULL');
ensure_column_pm($mysqli, 'pendenz_subkategorien_vermieter', 'sortierung', 'INT NOT NULL DEFAULT 0');
ensure_column_pm($mysqli, 'pendenz_subkategorien_vermieter', 'aktiv', 'TINYINT(1) NOT NULL DEFAULT 1');

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
        $redirect = 'pendenz_kategorien_vermieter.php';
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
            $stmt = $mysqli->prepare("INSERT INTO pendenz_kategorien_vermieter (name, projekt_id, objekt_id) VALUES (?, ?, ?)");
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
            $stmt = $mysqli->prepare("UPDATE pendenz_kategorien_vermieter SET name = ?, projekt_id = ?, objekt_id = ?, aktiv = ? WHERE id = ?");
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
            $stmt = $mysqli->prepare("DELETE FROM pendenz_subkategorien_vermieter WHERE kategorie_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM pendenz_kategorien_vermieter WHERE id = ?");
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
                INSERT INTO pendenz_subkategorien_vermieter (kategorie_id, name, beschreibung, projekt_id, objekt_id)
                SELECT id, ?, ?, projekt_id, objekt_id
                FROM pendenz_kategorien_vermieter
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
            $stmt = $mysqli->prepare("UPDATE pendenz_subkategorien_vermieter SET name = ?, beschreibung = ?, aktiv = ? WHERE id = ?");
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
            $stmt = $mysqli->prepare("DELETE FROM pendenz_subkategorien_vermieter WHERE id = ?");
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
    FROM pendenz_kategorien_vermieter k
    LEFT JOIN projekte p ON p.id = k.projekt_id
    LEFT JOIN objekte o ON o.id = k.objekt_id
    {$whereSql}
    ORDER BY COALESCE(k.projekt_id, 0) ASC, COALESCE(k.objekt_id, 0) ASC, k.name ASC
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
    FROM pendenz_subkategorien_vermieter
    ORDER BY name ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $subsByCat[(int)$row['kategorie_id']][] = $row;
    }
    $res->close();
}
?>
<style>
body{background:#f1f5f9}
.vm-wrap{max-width:1320px;margin:0 auto;padding:24px}
.vm-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.vm-title{font-size:28px;font-weight:800;color:#0f172a}
.vm-sub{color:#475569}
.vm-links{display:flex;gap:8px;flex-wrap:wrap}
.vm-links a{padding:10px 14px;border-radius:10px;background:#fff;color:#0f172a;text-decoration:none;border:1px solid #dbe2ea;font-weight:700}
.vm-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;box-shadow:0 10px 30px rgba(15,23,42,.05);margin-bottom:18px}
.vm-grid{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end}
.vm-grid-2{display:grid;grid-template-columns:1.6fr 1.2fr 1.2fr auto auto;gap:10px;align-items:center}
.vm-input,.vm-select,.vm-textarea{width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff}
.vm-textarea{min-height:72px;resize:vertical}
.vm-btn{border:0;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer}
.vm-btn-primary{background:#0f62fe;color:#fff}
.vm-btn-light{background:#e2e8f0;color:#0f172a}
.vm-btn-danger{background:#fee2e2;color:#991b1b}
.vm-btn-save{background:#dcfce7;color:#166534}
.vm-badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:700}
.vm-badge-global{background:#e0f2fe;color:#075985}
.vm-badge-proj{background:#ede9fe;color:#6d28d9}
.vm-badge-obj{background:#ecfccb;color:#3f6212}
.vm-kat{border:1px solid #e2e8f0;border-radius:14px;padding:14px;margin-bottom:14px}
.vm-kat-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.vm-sublist{display:grid;gap:10px;margin-top:12px}
.vm-subitem{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#f8fafc}
.vm-mini{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.vm-check{width:18px;height:18px}
.vm-empty{padding:16px;border-radius:12px;background:#f8fafc;color:#64748b}
.vm-label{font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;display:block}
@media (max-width: 980px){
  .vm-grid,.vm-grid-2{grid-template-columns:1fr}
}
</style>

<div class="vm-wrap">
    <div class="vm-top">
        <div>
            <div class="vm-title">🏠 Vermietervorlagen</div>
            <div class="vm-sub">Titel Kategorie und Kurzbeschreibung für Vermieter projekt- und objektspezifisch pflegen.</div>
        </div>
        <div class="vm-links">
            <a href="pendenz_kategorien.php">Vorlagenübersicht</a>
            <a href="pendenzen.php">Zurück Pendenzen</a>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="vm-card" style="border-color:#fecaca;background:#fff7f7;color:#991b1b;font-weight:700;">
            <?= h($flash) ?>
        </div>
    <?php endif; ?>

    <div class="vm-card">
        <form method="get" class="vm-grid">
            <div>
                <label class="vm-label">Projekt</label>
                <select name="projekt_id" id="filter_projekt_id" class="vm-select">
                    <option value="">Alle Projekte</option>
                    <?php foreach ($projekte as $projekt): ?>
                        <option value="<?= (int)$projekt['id'] ?>" <?= $filterProjektId === (int)$projekt['id'] ? 'selected' : '' ?>>
                            <?= h($projekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="vm-label">Objekt</label>
                <select name="objekt_id" id="filter_objekt_id" class="vm-select">
                    <option value="">Alle Objekte</option>
                    <?php foreach ($objekte as $objekt): ?>
                        <option value="<?= (int)$objekt['id'] ?>" data-projekt-id="<?= (int)$objekt['projekt_id'] ?>" <?= $filterObjektId === (int)$objekt['id'] ? 'selected' : '' ?>>
                            <?= h($objekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div></div>
            <div>
                <button type="submit" class="vm-btn vm-btn-primary">Anzeigen</button>
            </div>
        </form>
    </div>

    <div class="vm-card">
        <form method="post" class="vm-grid">
            <input type="hidden" name="act" value="add_cat">
            <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
            <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">
            <div>
                <label class="vm-label">Neuer Titel Kategorie</label>
                <input type="text" name="name" class="vm-input" placeholder="Titel Kategorie eingeben" required>
            </div>
            <div>
                <label class="vm-label">Projektbezug</label>
                <select name="projekt_id" id="new_projekt_id" class="vm-select">
                    <option value="">Global</option>
                    <?php foreach ($projekte as $projekt): ?>
                        <option value="<?= (int)$projekt['id'] ?>" <?= $filterProjektId === (int)$projekt['id'] ? 'selected' : '' ?>>
                            <?= h($projekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="vm-label">Objektbezug</label>
                <select name="objekt_id" id="new_objekt_id" class="vm-select">
                    <option value="">Kein Objekt</option>
                    <?php foreach ($objekte as $objekt): ?>
                        <option value="<?= (int)$objekt['id'] ?>" data-projekt-id="<?= (int)$objekt['projekt_id'] ?>">
                            <?= h($objekt['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="vm-btn vm-btn-primary">Titel Kategorie hinzufügen</button>
            </div>
        </form>
    </div>

    <div class="vm-card">
        <?php if (!$kategorien): ?>
            <div class="vm-empty">Noch keine Vermietervorlagen vorhanden.</div>
        <?php else: ?>
            <?php foreach ($kategorien as $kat): ?>
                <?php $katId = (int)$kat['id']; $subs = $subsByCat[$katId] ?? []; ?>
                <div class="vm-kat">
                    <div class="vm-kat-head">
                        <div class="vm-mini">
                            <?php if ((int)($kat['projekt_id'] ?? 0) > 0): ?>
                                <span class="vm-badge vm-badge-proj">Projekt · <?= h($kat['projekt_name'] ?? '') ?></span>
                            <?php else: ?>
                                <span class="vm-badge vm-badge-global">Global</span>
                            <?php endif; ?>

                            <?php if ((int)($kat['objekt_id'] ?? 0) > 0): ?>
                                <span class="vm-badge vm-badge-obj">Objekt · <?= h($kat['objekt_name'] ?? '') ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="color:#64748b;font-size:13px;"><?= count($subs) ?> Kurzbeschreibungen</div>
                    </div>

                    <form method="post" class="vm-grid-2" style="margin-bottom:10px;">
                        <input type="hidden" name="act" value="save_cat">
                        <input type="hidden" name="id" value="<?= $katId ?>">
                        <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                        <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">

                        <div>
                            <label class="vm-label">Titel Kategorie</label>
                            <input type="text" name="name" class="vm-input" value="<?= h($kat['name']) ?>" required>
                        </div>

                        <div>
                            <label class="vm-label">Projekt</label>
                            <select name="projekt_id" class="vm-select js-projekt-select" data-target="cat_objekt_<?= $katId ?>">
                                <option value="">Global</option>
                                <?php foreach ($projekte as $projekt): ?>
                                    <option value="<?= (int)$projekt['id'] ?>" <?= (int)$kat['projekt_id'] === (int)$projekt['id'] ? 'selected' : '' ?>>
                                        <?= h($projekt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="vm-label">Objekt</label>
                            <select name="objekt_id" id="cat_objekt_<?= $katId ?>" class="vm-select js-objekt-select">
                                <option value="">Kein Objekt</option>
                                <?php foreach ($objekte as $objekt): ?>
                                    <option value="<?= (int)$objekt['id'] ?>" data-projekt-id="<?= (int)$objekt['projekt_id'] ?>" <?= (int)$kat['objekt_id'] === (int)$objekt['id'] ? 'selected' : '' ?>>
                                        <?= h($objekt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="vm-mini" style="justify-content:flex-end;">
                            <label class="vm-mini" style="margin-right:8px;">
                                <input type="checkbox" class="vm-check" name="aktiv" value="1" <?= (int)$kat['aktiv'] === 1 ? 'checked' : '' ?>>
                                aktiv
                            </label>
                            <button type="submit" class="vm-btn vm-btn-save">Speichern</button>
                        </div>
                    </form>

                    <form method="post" onsubmit="return confirm('Titel Kategorie mit allen Kurzbeschreibungen löschen?');" style="margin-bottom:14px;">
                        <input type="hidden" name="act" value="delete_cat">
                        <input type="hidden" name="id" value="<?= $katId ?>">
                        <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                        <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">
                        <button type="submit" class="vm-btn vm-btn-danger">Löschen</button>
                    </form>

                    <div class="vm-sublist">
                        <?php foreach ($subs as $sub): ?>
                            <form method="post" class="vm-subitem">
                                <input type="hidden" name="act" value="save_sub">
                                <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                                <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                                <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">

                                <label class="vm-label">Kurzbeschreibung</label>
                                <input type="text" name="name" class="vm-input" value="<?= h($sub['name']) ?>" required style="margin-bottom:10px;">

                                <label class="vm-label">Langbeschreibung</label>
                                <textarea name="beschreibung" class="vm-textarea"><?= h($sub['beschreibung'] ?? '') ?></textarea>

                                <div class="vm-mini" style="justify-content:space-between;margin-top:10px;">
                                    <label class="vm-mini">
                                        <input type="checkbox" class="vm-check" name="aktiv" value="1" <?= (int)$sub['aktiv'] === 1 ? 'checked' : '' ?>>
                                        aktiv
                                    </label>
                                    <div class="vm-mini">
                                        <button type="submit" class="vm-btn vm-btn-save">Speichern</button>
                                    </div>
                                </div>
                            </form>
                            <form method="post" onsubmit="return confirm('Kurzbeschreibung löschen?');" style="margin-top:-2px;">
                                <input type="hidden" name="act" value="delete_sub">
                                <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                                <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                                <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">
                                <button type="submit" class="vm-btn vm-btn-light">Kurzbeschreibung löschen</button>
                            </form>
                        <?php endforeach; ?>

                        <form method="post" class="vm-subitem">
                            <input type="hidden" name="act" value="add_sub">
                            <input type="hidden" name="cid" value="<?= $katId ?>">
                            <input type="hidden" name="filter_projekt_id" value="<?= $filterProjektId ?: '' ?>">
                            <input type="hidden" name="filter_objekt_id" value="<?= $filterObjektId ?: '' ?>">

                            <label class="vm-label">Kurzbeschreibung hinzufügen</label>
                            <input type="text" name="name" class="vm-input" placeholder="Kurzbeschreibung eingeben" required style="margin-bottom:10px;">

                            <label class="vm-label">Langbeschreibung optional</label>
                            <textarea name="beschreibung" class="vm-textarea" placeholder="Optionaler Zusatztext"></textarea>

                            <div class="vm-mini" style="justify-content:flex-end;margin-top:10px;">
                                <button type="submit" class="vm-btn vm-btn-primary">Hinzufügen</button>
                            </div>
                        </form>
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
