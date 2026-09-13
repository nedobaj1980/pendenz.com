<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vorgang_taxonomy.php';

require_login();
if (!is_admin()) {
    die('Zugriff verweigert.');
}

vorgang_taxonomy_ensure_tables($mysqli);

function h2($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_has_column(mysqli $db, string $table, string $column): bool {
    $table = $db->real_escape_string($table);
    $column = $db->real_escape_string($column);
    $res = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $res && $res->num_rows > 0;
}
function ensure_vorgangsart_column(mysqli $db, string $table, string $column, string $definition): void {
    if (!table_has_column($db, $table, $column)) {
        @$db->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}

ensure_vorgangsart_column($mysqli, 'pendenzen_arten', 'vorlagen_welt', "`vorlagen_welt` VARCHAR(30) NOT NULL DEFAULT 'bkp' AFTER `name`");
ensure_vorgangsart_column($mysqli, 'pendenzen_arten', 'empfaenger_typ', "`empfaenger_typ` VARCHAR(30) NULL DEFAULT NULL AFTER `vorlagen_welt`");

$flash = '';
$action = $_GET['action'] ?? '';

$hasDefaultProjekt  = table_has_column($mysqli, 'pendenzen_arten', 'default_projekt_id');
$hasDefaultObjekt   = table_has_column($mysqli, 'pendenzen_arten', 'default_objekt_id');
$hasDefaultWohnung  = table_has_column($mysqli, 'pendenzen_arten', 'default_wohnung_id');
$hasDefaultBenutzer = table_has_column($mysqli, 'pendenzen_arten', 'default_benutzer_id');
$hasAllowProjekt    = table_has_column($mysqli, 'pendenzen_arten', 'allow_override_projekt');
$hasAllowObjekt     = table_has_column($mysqli, 'pendenzen_arten', 'allow_override_objekt');
$hasAllowWohnung    = table_has_column($mysqli, 'pendenzen_arten', 'allow_override_wohnung');
$hasAllowBenutzer   = table_has_column($mysqli, 'pendenzen_arten', 'allow_override_benutzer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slugInput = trim($_POST['slug'] ?? '');
    $slug = $slugInput !== '' ? vorgang_taxonomy_slugify($slugInput) : vorgang_taxonomy_slugify($name);
    $icon = trim($_POST['icon'] ?? '');
    $color = trim($_POST['color'] ?? '#4a90e2');
    $sort = (int)($_POST['sort_order'] ?? 100);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $roles = !empty($_POST['roles']) && is_array($_POST['roles']) ? implode(',', $_POST['roles']) : null;
    $btypes = !empty($_POST['btypes']) && is_array($_POST['btypes']) ? implode(',', $_POST['btypes']) : null;
    $vorlagenWelt = trim((string)($_POST['vorlagen_welt'] ?? 'bkp'));
    if (!in_array($vorlagenWelt, ['bkp', 'mieter', 'vermieter'], true)) { $vorlagenWelt = 'bkp'; }
    $empfaengerTyp = trim((string)($_POST['empfaenger_typ'] ?? ''));
    if ($empfaengerTyp === '') { $empfaengerTyp = null; }

    $defaultProjektId  = $hasDefaultProjekt  ? ((int)($_POST['default_projekt_id'] ?? 0) ?: null) : null;
    $defaultObjektId   = $hasDefaultObjekt   ? ((int)($_POST['default_objekt_id'] ?? 0) ?: null) : null;
    $defaultWohnungId  = $hasDefaultWohnung  ? ((int)($_POST['default_wohnung_id'] ?? 0) ?: null) : null;
    $defaultBenutzerId = $hasDefaultBenutzer ? ((int)($_POST['default_benutzer_id'] ?? 0) ?: null) : null;
    $allowProjekt      = $hasAllowProjekt    ? (isset($_POST['allow_override_projekt']) ? 1 : 0) : 1;
    $allowObjekt       = $hasAllowObjekt     ? (isset($_POST['allow_override_objekt']) ? 1 : 0) : 1;
    $allowWohnung      = $hasAllowWohnung    ? (isset($_POST['allow_override_wohnung']) ? 1 : 0) : 1;
    $allowBenutzer     = $hasAllowBenutzer   ? (isset($_POST['allow_override_benutzer']) ? 1 : 0) : 1;

    if ($name === '') {
        $flash = '❌ Name darf nicht leer sein.';
    } else {
        if ($id > 0) {
            $sql = "UPDATE pendenzen_arten
                    SET name=?, vorlagen_welt=?, empfaenger_typ=?, slug=?, icon=?, color=?, sort_order=?, is_active=?, allowed_roles=?, allowed_business_types=?,
                        default_projekt_id=?, default_objekt_id=?, default_wohnung_id=?, default_benutzer_id=?,
                        allow_override_projekt=?, allow_override_objekt=?, allow_override_wohnung=?, allow_override_benutzer=?
                    WHERE id=?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param(
                'ssssssiissiiiiiiiii',
                $name,
                $vorlagenWelt,
                $empfaengerTyp,
                $slug,
                $icon,
                $color,
                $sort,
                $active,
                $roles,
                $btypes,
                $defaultProjektId,
                $defaultObjektId,
                $defaultWohnungId,
                $defaultBenutzerId,
                $allowProjekt,
                $allowObjekt,
                $allowWohnung,
                $allowBenutzer,
                $id
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $sql = "INSERT INTO pendenzen_arten
                    (name, vorlagen_welt, empfaenger_typ, slug, icon, color, sort_order, is_active, allowed_roles, allowed_business_types,
                     default_projekt_id, default_objekt_id, default_wohnung_id, default_benutzer_id,
                     allow_override_projekt, allow_override_objekt, allow_override_wohnung, allow_override_benutzer)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param(
                'ssssssiissiiiiiiii',
                $name,
                $vorlagenWelt,
                $empfaengerTyp,
                $slug,
                $icon,
                $color,
                $sort,
                $active,
                $roles,
                $btypes,
                $defaultProjektId,
                $defaultObjektId,
                $defaultWohnungId,
                $defaultBenutzerId,
                $allowProjekt,
                $allowObjekt,
                $allowWohnung,
                $allowBenutzer
            );
            $stmt->execute();
            $id = (int)$stmt->insert_id;
            $stmt->close();
        }
        $flash = '✅ Gespeichert.';
    }
}

if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $mysqli->query("DELETE FROM pendenzen_arten WHERE id={$id}");
    }
    header('Location: vorgangsart_settings.php?flash=' . urlencode('🗑️ Gelöscht.'));
    exit;
}

$arten = [];
$q = $mysqli->query("SELECT * FROM pendenzen_arten ORDER BY sort_order, name");
if ($q) while ($r = $q->fetch_assoc()) $arten[] = $r;

$projects = [];
$q = $mysqli->query("SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name");
if ($q) while ($r = $q->fetch_assoc()) $projects[] = $r;

$objects = [];
$q = $mysqli->query("SELECT id, projekt_id, name FROM objekte ORDER BY name");
if ($q) while ($r = $q->fetch_assoc()) $objects[] = $r;

$wohnungen = [];
$q = $mysqli->query("SELECT id, objekt_id, name FROM wohnungen ORDER BY name");
if ($q) while ($r = $q->fetch_assoc()) $wohnungen[] = $r;

$benutzer = [];
$q = $mysqli->query("SELECT b.id, b.name, b.business_type, COALESCE(f.name, b.firma_name, '') AS firma_name
                     FROM benutzer b
                     LEFT JOIN firma_user fu ON fu.user_id = b.id AND fu.is_primary = 1
                     LEFT JOIN firmen f ON f.id = fu.firma_id
                     WHERE b.deleted_at IS NULL
                     ORDER BY b.name");
if ($q) while ($r = $q->fetch_assoc()) $benutzer[] = $r;

$rolesList = ['superadmin', 'admin', 'benutzer', 'gast'];
$btypesList = [
    'handwerker' => 'Unternehmer / Firma',
    'mieter' => 'Mieter',
    'vermieter' => 'Vermieter',
    'standard' => 'Standard / Intern',
    'kunde' => 'Kunde',
    'lieferant' => 'Lieferant',
    'mietinteressent' => 'Mietinteressent',
    'vormieter' => 'Vormieter',
];


require_once __DIR__ . '/../includes/header.php';
?>
<style>
.vs-wrap{max-width:1280px;margin:0 auto;padding:20px}
.vs-top{display:flex;justify-content:space-between;align-items:center;gap:16px;background:linear-gradient(135deg,#1d4ed8,#2563eb);color:#fff;padding:20px 24px;border-radius:18px;margin-bottom:22px}
.vs-top h1{margin:0;font-size:24px}.vs-top p{margin:4px 0 0;opacity:.9}
.vs-grid{display:grid;grid-template-columns:1.2fr .9fr;gap:22px;align-items:start}
.vs-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 8px 24px rgba(15,23,42,.05)}
.vs-card h3{margin:0 0 14px 0}.vs-body{padding:20px}
.vs-table{width:100%;border-collapse:collapse}.vs-table th,.vs-table td{padding:14px;border-bottom:1px solid #eef2f7;vertical-align:top}
.vs-table th{font-size:11px;text-transform:uppercase;color:#64748b;background:#f8fafc}
.vs-rowtitle{font-weight:700;color:#0f172a}.vs-sub{font-size:12px;color:#64748b}
.vs-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:700;margin:2px 6px 2px 0}
.vs-ok{background:#dcfce7;color:#166534}.vs-no{background:#fee2e2;color:#991b1b}
.vs-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.vs-full{grid-column:1/-1}
.vs-label{display:block;font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;margin:0 0 6px}
.vs-input,.vs-select{width:100%;border:1px solid #d1d5db;border-radius:12px;padding:10px 12px;background:#fff}
.vs-checkgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.vs-box{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fafafa}
.vs-inline{display:flex;flex-wrap:wrap;gap:10px;align-items:center}.vs-actions{display:flex;gap:8px;justify-content:flex-end}
.btn2{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:12px;text-decoration:none;border:none;cursor:pointer;font-weight:700}
.btn2-primary{background:#2563eb;color:#fff}.btn2-light{background:#eff6ff;color:#1d4ed8}.btn2-danger{background:#fee2e2;color:#991b1b}
@media (max-width:980px){.vs-grid{grid-template-columns:1fr}.vs-form-grid{grid-template-columns:1fr}.vs-checkgrid{grid-template-columns:1fr}.vs-top{flex-direction:column;align-items:flex-start}}
</style>

<div class="vs-wrap">
    <div class="vs-top">
        <div>
            <h1>⚙️ Vorgangsarten</h1>
            <p>Standard-Projekt, Objekt, Wohnung und Unternehmer festlegen. Checkboxen erlauben Abweichungen im Dashboard.</p>
        </div>
        <a href="pendenzen.php" class="btn2 btn2-light">Zurück zum Dashboard</a>
    </div>

    <?php if ($flash || isset($_GET['flash'])): ?>
        <div class="vs-card" style="margin-bottom:18px;border-left:5px solid #10b981;">
            <div class="vs-body" style="color:#166534;"><?= h2($flash ?: ($_GET['flash'] ?? '')) ?></div>
        </div>
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
                    $id = (int)$a['id'];
                    $defaultProjektName = '';
                    foreach ($projects as $p) if ((int)$p['id'] === (int)($a['default_projekt_id'] ?? 0)) $defaultProjektName = $p['name'];
                    $defaultObjektName = '';
                    foreach ($objects as $o) if ((int)$o['id'] === (int)($a['default_objekt_id'] ?? 0)) $defaultObjektName = $o['name'];
                    $defaultWohnungName = '';
                    foreach ($wohnungen as $w) if ((int)$w['id'] === (int)($a['default_wohnung_id'] ?? 0)) $defaultWohnungName = $w['name'];
                    $defaultBenutzerName = '';
                    foreach ($benutzer as $b) if ((int)$b['id'] === (int)($a['default_benutzer_id'] ?? 0)) $defaultBenutzerName = $b['name'] . (!empty($b['firma_name']) ? ' · ' . $b['firma_name'] : '');
                ?>
                    <tr>
                        <td>
                            <div class="vs-rowtitle"><?= h2($a['icon'] ?: '📝') ?> <?= h2($a['name']) ?></div>
                            <div class="vs-sub">/<?= h2($a['slug']) ?></div>
                            <div class="vs-sub" style="margin-top:6px">Vorlagen-Welt: <strong><?= h2($a['vorlagen_welt'] ?? 'bkp') ?></strong><?= !empty($a['empfaenger_typ']) ? ' · Empfänger: <strong>' . h2($a['empfaenger_typ']) . '</strong>' : '' ?></div>
                            <?php if (!empty($a['allowed_business_types'])): ?><div class="vs-sub" style="margin-top:6px">Typ: <?= h2($a['allowed_business_types']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($defaultProjektName): ?><div class="vs-sub">Projekt: <strong><?= h2($defaultProjektName) ?></strong></div><?php endif; ?>
                            <?php if ($defaultObjektName): ?><div class="vs-sub">Objekt: <strong><?= h2($defaultObjektName) ?></strong></div><?php endif; ?>
                            <?php if ($defaultWohnungName): ?><div class="vs-sub">Wohnung: <strong><?= h2($defaultWohnungName) ?></strong></div><?php endif; ?>
                            <?php if ($defaultBenutzerName): ?><div class="vs-sub">Unternehmer: <strong><?= h2($defaultBenutzerName) ?></strong></div><?php endif; ?>
                            <?php if (!$defaultProjektName && !$defaultObjektName && !$defaultWohnungName && !$defaultBenutzerName): ?><div class="vs-sub">— keine Standardwerte —</div><?php endif; ?>
                        </td>
                        <td>
                            <div class="vs-inline">
                                <span class="vs-badge <?= !empty($a['allow_override_projekt']) ? 'vs-ok' : 'vs-no' ?>">Projekt <?= !empty($a['allow_override_projekt']) ? 'frei' : 'fix' ?></span>
                                <span class="vs-badge <?= !empty($a['allow_override_objekt']) ? 'vs-ok' : 'vs-no' ?>">Objekt <?= !empty($a['allow_override_objekt']) ? 'frei' : 'fix' ?></span>
                                <span class="vs-badge <?= !empty($a['allow_override_wohnung']) ? 'vs-ok' : 'vs-no' ?>">Wohnung <?= !empty($a['allow_override_wohnung']) ? 'frei' : 'fix' ?></span>
                                <span class="vs-badge <?= !empty($a['allow_override_benutzer']) ? 'vs-ok' : 'vs-no' ?>">Unternehmer <?= !empty($a['allow_override_benutzer']) ? 'frei' : 'fix' ?></span>
                            </div>
                        </td>
                        <td style="text-align:right">
                            <div class="vs-actions">
                                <button type="button" class="btn2 btn2-light" onclick='editArt(<?= json_encode($a, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>Bearbeiten</button>
                                <a class="btn2 btn2-danger" href="?action=delete&id=<?= $id ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a>
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
                            <input class="vs-input" type="color" name="color" id="f_color" value="#4a90e2" style="height:44px;padding:4px">
                        </div>
                        <div>
                            <label class="vs-label">Sortierung</label>
                            <input class="vs-input" type="number" name="sort_order" id="f_sort" value="100">
                        </div>
                        <div style="display:flex;align-items:flex-end">
                            <label style="display:flex;gap:8px;align-items:center;font-weight:700"><input type="checkbox" name="is_active" id="f_active" checked> Aktiv</label>
                        </div>

                        <div>
                            <label class="vs-label">Vorlagen-Welt</label>
                            <select class="vs-select" name="vorlagen_welt" id="f_vorlagen_welt">
                                <option value="bkp">BKP</option>
                                <option value="mieter">Mieter</option>
                                <option value="vermieter">Vermieter</option>
                            </select>
                        </div>
                        <div>
                            <label class="vs-label">Empfänger-Typ</label>
                            <select class="vs-select" name="empfaenger_typ" id="f_empfaenger_typ">
                                <option value="">— optional —</option>
                                <option value="unternehmer">Unternehmer</option>
                                <option value="bauleiter">Bauleiter</option>
                                <option value="architekt">Architekt</option>
                                <option value="fachplaner">Fachplaner</option>
                                <option value="bauingenieur">Bauingenieur</option>
                                <option value="mieter">Mieter</option>
                                <option value="vermieter">Vermieter</option>
                                <option value="bauherr">Bauherr</option>
                            </select>
                        </div>

                        <div class="vs-full vs-box">
                            <div class="vs-label">Standardwerte</div>
                            <div class="vs-form-grid">
                                <div>
                                    <label class="vs-label">Projekt</label>
                                    <select class="vs-select" name="default_projekt_id" id="f_default_projekt_id">
                                        <option value="">— keines —</option>
                                        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h2($p['name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="allow_override_projekt" id="f_allow_override_projekt" checked> anderes Projekt zulassen</label>
                                </div>
                                <div>
                                    <label class="vs-label">Objekt</label>
                                    <select class="vs-select" name="default_objekt_id" id="f_default_objekt_id">
                                        <option value="">— keines —</option>
                                        <?php foreach ($objects as $o): ?><option value="<?= (int)$o['id'] ?>" data-projekt-id="<?= (int)$o['projekt_id'] ?>"><?= h2($o['name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="allow_override_objekt" id="f_allow_override_objekt" checked> anderes Objekt zulassen</label>
                                </div>
                                <div>
                                    <label class="vs-label">Wohnung</label>
                                    <select class="vs-select" name="default_wohnung_id" id="f_default_wohnung_id">
                                        <option value="">— keine —</option>
                                        <?php foreach ($wohnungen as $w): ?><option value="<?= (int)$w['id'] ?>" data-objekt-id="<?= (int)$w['objekt_id'] ?>"><?= h2($w['name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="allow_override_wohnung" id="f_allow_override_wohnung" checked> andere Wohnung zulassen</label>
                                </div>
                                <div>
                                    <label class="vs-label">Unternehmer / Benutzer</label>
                                    <select class="vs-select" name="default_benutzer_id" id="f_default_benutzer_id">
                                        <option value="">— keiner —</option>
                                        <?php foreach ($benutzer as $b): ?><option value="<?= (int)$b['id'] ?>" data-btype="<?= h2($b['business_type']) ?>"><?= h2($b['name'] . (!empty($b['firma_name']) ? ' · ' . $b['firma_name'] : '')) ?></option><?php endforeach; ?>
                                    </select>
                                    <label style="display:flex;gap:8px;align-items:center;margin-top:8px"><input type="checkbox" name="allow_override_benutzer" id="f_allow_override_benutzer" checked> anderen Unternehmer zulassen</label>
                                </div>
                            </div>
                        </div>

                        <div class="vs-full vs-box">
                            <div class="vs-label">Filter</div>
                            <div class="vs-form-grid">
                                <div>
                                    <label class="vs-label">Rollen</label>
                                    <div class="vs-checkgrid">
                                        <?php foreach ($rolesList as $r): ?>
                                            <label><input type="checkbox" name="roles[]" value="<?= h2($r) ?>"> <?= h2($r) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div>
                                    <label class="vs-label">Benutzerarten</label>
                                    <div class="vs-checkgrid">
                                        <?php foreach ($btypesList as $key => $label): ?>
                                            <label><input type="checkbox" name="btypes[]" value="<?= h2($key) ?>"> <?= h2($label) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
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

<script>
function setCheckedByName(name, values){
    const set = new Set((values || []).filter(Boolean));
    document.querySelectorAll('input[name="'+name+'"]').forEach(el => {
        el.checked = set.has(el.value);
    });
}
function filterObjekte(){
    const projektId = document.getElementById('f_default_projekt_id').value;
    const objekt = document.getElementById('f_default_objekt_id');
    Array.from(objekt.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        opt.hidden = projektId && opt.dataset.projektId !== projektId;
    });
    if (objekt.selectedOptions[0] && objekt.selectedOptions[0].hidden) objekt.value = '';
    filterWohnungen();
}
function filterWohnungen(){
    const objektId = document.getElementById('f_default_objekt_id').value;
    const wohn = document.getElementById('f_default_wohnung_id');
    Array.from(wohn.options).forEach(opt => {
        if (!opt.value) { opt.hidden = false; return; }
        opt.hidden = objektId && opt.dataset.objektId !== objektId;
    });
    if (wohn.selectedOptions[0] && wohn.selectedOptions[0].hidden) wohn.value = '';
}
function editArt(art, links){
    document.getElementById('formTitle').textContent = 'Vorgangsart bearbeiten';
    document.getElementById('f_id').value = art.id || 0;
    document.getElementById('f_name').value = art.name || '';
    document.getElementById('f_slug').value = art.slug || '';
    document.getElementById('f_vorlagen_welt').value = art.vorlagen_welt || 'bkp';
    document.getElementById('f_empfaenger_typ').value = art.empfaenger_typ || '';
    document.getElementById('f_icon').value = art.icon || '';
    document.getElementById('f_color').value = art.color || art.farbe || '#4a90e2';
    document.getElementById('f_sort').value = art.sort_order || 100;
    document.getElementById('f_active').checked = String(art.is_active || '0') === '1';
    document.getElementById('f_default_projekt_id').value = art.default_projekt_id || '';
    document.getElementById('f_default_objekt_id').value = art.default_objekt_id || '';
    document.getElementById('f_default_wohnung_id').value = art.default_wohnung_id || '';
    document.getElementById('f_default_benutzer_id').value = art.default_benutzer_id || '';
    document.getElementById('f_allow_override_projekt').checked = String(art.allow_override_projekt ?? '1') === '1';
    document.getElementById('f_allow_override_objekt').checked = String(art.allow_override_objekt ?? '1') === '1';
    document.getElementById('f_allow_override_wohnung').checked = String(art.allow_override_wohnung ?? '1') === '1';
    document.getElementById('f_allow_override_benutzer').checked = String(art.allow_override_benutzer ?? '1') === '1';
    setCheckedByName('roles[]', (art.allowed_roles || '').split(',').map(v => v.trim()).filter(Boolean));
    setCheckedByName('btypes[]', (art.allowed_business_types || '').split(',').map(v => v.trim()).filter(Boolean));
    setSelectedMulti('objekt_ids[]', (links || []).map(x => x.objekt_id).filter(Boolean));
    filterObjekte();
    window.scrollTo({top:0, behavior:'smooth'});
}
function resetForm(){
    document.getElementById('formTitle').textContent = 'Vorgangsart erstellen';
    document.getElementById('artForm').reset();
    document.getElementById('f_id').value = 0;
    document.getElementById('f_color').value = '#4a90e2';
    document.getElementById('f_vorlagen_welt').value = 'bkp';
    document.getElementById('f_empfaenger_typ').value = '';
    filterObjekte();
}
document.getElementById('f_default_projekt_id').addEventListener('change', filterObjekte);
document.getElementById('f_default_objekt_id').addEventListener('change', filterWohnungen);
filterObjekte();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
