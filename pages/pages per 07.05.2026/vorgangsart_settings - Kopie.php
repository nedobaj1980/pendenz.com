<?php
// pages/vorgangsart_settings.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vorgang_taxonomy.php';

require_login();
if (!is_admin()) {
    die("Zugriff verweigert.");
}

vorgang_taxonomy_ensure_tables($mysqli);

$flash = "";
$action = $_GET['action'] ?? '';

// --- Save / Create ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $slug = vorgang_taxonomy_slugify($name);
    $icon = trim($_POST['icon'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 100);
    $active = isset($_POST['is_active']) ? 1 : 0;
    
    $roles = isset($_POST['roles']) ? implode(',', $_POST['roles']) : null;
    $btypes = isset($_POST['btypes']) ? implode(',', $_POST['btypes']) : null;
    $pids = $_POST['project_ids'] ?? [];
    $oids = $_POST['objekt_ids'] ?? [];

    if ($name === '') {
        $flash = "❌ Name darf nicht leer sein.";
    } else {
        if ($id > 0) {
            $stmt = $mysqli->prepare("UPDATE pendenzen_arten SET name=?, slug=?, icon=?, color=?, sort_order=?, is_active=?, allowed_roles=?, allowed_business_types=? WHERE id=?");
            $stmt->bind_param("ssssiissi", $name, $slug, $icon, $color, $sort, $active, $roles, $btypes, $id);
            $stmt->execute();
        } else {
            $stmt = $mysqli->prepare("INSERT INTO pendenzen_arten (name, slug, icon, color, sort_order, is_active, allowed_roles, allowed_business_types) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssiiss", $name, $slug, $icon, $color, $sort, $active, $roles, $btypes);
            $stmt->execute();
            $id = $stmt->insert_id;
        }

        // Projects junction
        $mysqli->query("DELETE FROM pendenzen_arten_projekte WHERE vorgangsart_id=$id");
        if (!empty($pids)) {
            $stP = $mysqli->prepare("INSERT INTO pendenzen_arten_projekte (vorgangsart_id, projekt_id, objekt_id) VALUES (?, ?, ?)");
            foreach ($pids as $pid) {
                $pid = (int)$pid;
                // Check if any objects are selected for THIS project
                $projectObjects = $oids[$pid] ?? [];
                if (empty($projectObjects)) {
                    // No specific objects -> applies to the whole project
                    $oid = null;
                    $stP->bind_param("iii", $id, $pid, $oid);
                    $stP->execute();
                } else {
                    // Specific objects selected
                    foreach ($projectObjects as $oid) {
                        $oid = (int)$oid;
                        $stP->bind_param("iii", $id, $pid, $oid);
                        $stP->execute();
                    }
                }
            }
        }
        $flash = "✅ Gespeichert.";
    }
}

// --- Delete ---
if ($action === 'delete') {
    $id = (int)$_GET['id'];
    $mysqli->query("DELETE FROM pendenzen_arten_projekte WHERE vorgangsart_id=$id");
    $mysqli->query("DELETE FROM pendenzen_arten WHERE id=$id");
    header("Location: vorgangsart_settings.php?flash=" . urlencode("🗑️ Gelöscht."));
    exit;
}

$arten = vorgangsarten_all($mysqli);
$projects = [];
$resP = $mysqli->query("SELECT id, name FROM projekte WHERE deleted_at IS NULL ORDER BY name");
if ($resP) {
    while($row = $resP->fetch_assoc()) {
        $pid = (int)$row['id'];
        $row['objects'] = [];
        $resO = $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=$pid ORDER BY name");
        if ($resO) while($o = $resO->fetch_assoc()) $row['objects'][] = $o;
        $projects[] = $row;
    }
}

$roles_list = ['superadmin', 'admin', 'benutzer', 'gast'];
$btypes_list = [
    'handwerker'   => '🏗️ Partner / Unternehmer',
    'mieter'       => '🏠 Mieter',
    'eigentuemer'  => '👑 Eigentümer',
    'standard'     => '👤 Standard / Intern'
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid" style="padding: 20px; max-width: 1200px; margin: 0 auto;">
    <header class="hero hero-teal" style="display:flex; justify-content:space-between; align-items:center; border-radius: 16px; margin-bottom: 30px; padding: 20px 30px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: white; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.2);">
        <div>
            <h1 style="margin:0; font-size: 24px; font-weight: 800;">⚙️ Vorgangsarten & Berechtigungen</h1>
            <p style="margin: 5px 0 0 0; opacity: 0.8; font-size: 14px;">Definiere Prozess-Typen und schränke sie auf Projekte oder Rollen ein.</p>
        </div>
        <a href="pendenzen.php" class="btn" style="background: rgba(255,255,255,0.2); color: white; text-decoration: none; padding: 10px 20px; border-radius: 10px; font-weight: 600;">Zurück zum Dashboard</a>
    </header>

    <?php if ($flash || isset($_GET['flash'])): ?>
        <div class="card" style="border-left: 5px solid #10b981; background: #f0fdf4; color: #166534; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;">
            <?= h($flash ?: $_GET['flash']) ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 30px; align-items: start;">
        <!-- Liste -->
        <div class="card" style="padding: 0; overflow: hidden; border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
            <table class="table" style="width: 100%; border-collapse: collapse; margin: 0;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <th style="padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b;">Icon / Name</th>
                        <th style="padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b;">Einschränkungen</th>
                        <th style="padding: 15px; text-align: left; font-size: 11px; text-transform: uppercase; color: #64748b;">Status</th>
                        <th style="padding: 15px; text-align: right; font-size: 11px; text-transform: uppercase; color: #64748b;">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($arten as $a): 
                        $id = (int)$a['id'];
                        $pRes = $mysqli->query("
                            SELECT p.name as p_name, o.name as o_name 
                            FROM pendenzen_arten_projekte ap 
                            JOIN projekte p ON p.id=ap.projekt_id 
                            LEFT JOIN objekte o ON o.id=ap.objekt_id
                            WHERE ap.vorgangsart_id=$id
                        ");
                        $pDetails = []; 
                        while($p = $pRes->fetch_assoc()) {
                            $proj = $p['p_name'];
                            if (!isset($pDetails[$proj])) $pDetails[$proj] = [];
                            if ($p['o_name']) $pDetails[$proj][] = $p['o_name'];
                        }
                        $pStrs = [];
                        foreach($pDetails as $pn => $on) {
                            $pStrs[] = $pn . (!empty($on) ? " (".implode(', ', $on).")" : "");
                        }
                        
                        $pLinksRes = $mysqli->query("SELECT projekt_id, objekt_id FROM pendenzen_arten_projekte WHERE vorgangsart_id=$id");
                        $pLinks = $pLinksRes ? $pLinksRes->fetch_all(MYSQLI_ASSOC) : [];
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 15px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <span style="font-size: 24px; background: <?= h($a['color'] ?: '#f1f5f9') ?>20; padding: 8px; border-radius: 10px;"><?= h($a['icon'] ?: '📝') ?></span>
                                <div>
                                    <strong style="color: #1e293b; display: block;"><?= h($a['name']) ?></strong>
                                    <span style="font-size: 11px; color: #94a3b8;">/<?= h($a['slug']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 15px;">
                            <?php if ($a['allowed_roles']): ?>
                                <div style="margin-bottom: 4px;">👤 <span style="font-size: 11px; font-weight: 600; color: #6366f1;"><?= h($a['allowed_roles']) ?></span></div>
                            <?php endif; ?>
                            <?php if ($a['allowed_business_types']): ?>
                                <div style="margin-bottom: 4px;">📂 <span style="font-size: 11px; font-weight: 600; color: #ec4899;"><?php 
                                    $bt_labels = [];
                                    foreach(explode(',', $a['allowed_business_types']) as $bt) $bt_labels[] = $btypes_list[$bt] ?? $bt;
                                    echo h(implode(', ', $bt_labels));
                                ?></span></div>
                            <?php endif; ?>
                            <?php if (!empty($pStrs)): ?>
                                <div>🏗️ <span style="font-size: 11px; font-weight: 600; color: #f59e0b;"><?= h(implode(', ', $pStrs)) ?></span></div>
                            <?php endif; ?>
                            <?php if (!$a['allowed_roles'] && !$a['allowed_business_types'] && empty($pNames)): ?>
                                <i style="color: #cbd5e1; font-size: 12px;">Keine (für alle sichtbar)</i>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 15px;">
                            <?php if ($a['is_active']): ?>
                                <span style="background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase;">Aktiv</span>
                            <?php else: ?>
                                <span style="background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase;">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 15px; text-align: right;">
                            <button onclick='editArten(<?= json_encode($a) ?>, <?= json_encode($pLinks) ?>)' class="btn btn-teal btn-small" style="padding: 6px 12px;">Edit</button>
                            <a href="?action=delete&id=<?= $id ?>" class="btn btn-danger btn-small" onclick="return confirm('Wirklich löschen?')" style="padding: 6px 12px;">Löschen</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Formular -->
        <div class="card" id="editor" style="padding: 25px; border-radius: 20px; border: 1px solid #e2e8f0; position: sticky; top: 20px;">
            <h3 id="formTitle" style="margin: 0 0 20px 0; font-size: 18px; font-weight: 800; color: #0f172a;">Art erstellen</h3>
            <form method="post" id="artenForm">
                <input type="hidden" name="id" id="f_id" value="0">
                
                <div style="display: grid; grid-template-columns: 80px 1fr; gap: 15px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px;">Icon</label>
                        <input type="text" name="icon" id="f_icon" class="form-control" style="font-size: 24px; text-align:center; padding: 10px;" placeholder="📝">
                    </div>
                    <div>
                        <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px;">Farbe</label>
                        <input type="color" name="color" id="f_color" class="form-control" style="height: 48px; padding: 5px;" value="#4a90e2">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px;">Name*</label>
                    <input type="text" name="name" id="f_name" class="form-control" placeholder="z.B. Wohnungsübergabe" required>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px;">Sortierung</label>
                    <input type="number" name="sort_order" id="f_sort" class="form-control" value="100">
                </div>

                <hr style="border: 0; border-top: 1px solid #f1f5f9; margin: 25px 0;">
                
                <h4 style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: #475569; margin-bottom: 15px;">Berechtigungen & Sichtbarkeit</h4>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px;">Benutzer-Rolle (System)</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                        <?php foreach($roles_list as $r): ?>
                            <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer;">
                                <input type="checkbox" name="roles[]" value="<?= h($r) ?>" class="role-check"> <?= h($r) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px;">User-Typ (Kontext)</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                        <?php foreach($btypes_list as $b_val => $b_label): ?>
                            <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer;">
                                <input type="checkbox" name="btypes[]" value="<?= h($b_val) ?>" class="btype-check"> <?= h($b_label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px;">Projekte & Objekte</label>
                    <div style="max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px;">
                        <?php foreach($projects as $p): ?>
                            <div style="margin-bottom: 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 5px;">
                                <label style="display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 13px; cursor: pointer;">
                                    <input type="checkbox" name="project_ids[]" value="<?= (int)$p['id'] ?>" class="project-check" onchange="toggleObjektList(this)"> <?= h($p['name']) ?>
                                </label>
                                <?php if (!empty($p['objects'])): ?>
                                    <div class="objekt-list" id="obj-list-<?= (int)$p['id'] ?>" style="margin-left: 25px; margin-top: 5px; display: none;">
                                        <?php foreach($p['objects'] as $o): ?>
                                            <label style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b; cursor: pointer; margin-bottom: 3px;">
                                                <input type="checkbox" name="objekt_ids[<?= (int)$p['id'] ?>][]" value="<?= (int)$o['id'] ?>" class="objekt-check" data-pid="<?= (int)$p['id'] ?>"> <?= h($o['name']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small style="color: #94a3b8; font-size: 10px; display: block; margin-top: 5px;">Keine Projekt-Auswahl = für alles verfügbar. Projektauswahl ohne Objekt = gilt für ganzes Projekt.</small>
                </div>

                <div style="margin-bottom: 25px;">
                    <label style="display: flex; align-items: center; gap: 10px; font-weight: 700; color: #1e293b;">
                        <input type="checkbox" name="is_active" id="f_active" value="1" checked> Aktiviert
                    </label>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-teal" style="flex: 1; padding: 14px; font-weight: 800;">Speichern</button>
                    <button type="button" onclick="resetForm()" class="btn" style="background:#f1f5f9; color:#64748b; padding: 14px;">Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editArten(art, pLinks) {
    document.getElementById('formTitle').innerText = 'Art bearbeiten';
    document.getElementById('f_id').value = art.id;
    document.getElementById('f_name').value = art.name;
    document.getElementById('f_icon').value = art.icon;
    document.getElementById('f_color').value = art.color || '#4a90e2';
    document.getElementById('f_sort').value = art.sort_order;
    document.getElementById('f_active').checked = parseInt(art.is_active) === 1;

    // Roles
    const roles = art.allowed_roles ? art.allowed_roles.split(',') : [];
    document.querySelectorAll('.role-check').forEach(ck => ck.checked = roles.includes(ck.value));

    // Business Types
    const btypes = art.allowed_business_types ? art.allowed_business_types.split(',') : [];
    document.querySelectorAll('.btype-check').forEach(ck => ck.checked = btypes.includes(ck.value));

    // Projects & Objects
    document.querySelectorAll('.project-check, .objekt-check').forEach(ck => ck.checked = false);
    
    pLinks.forEach(link => {
        const pid = parseInt(link.projekt_id);
        const oid = link.objekt_id ? parseInt(link.objekt_id) : null;
        
        // Check project
        const pck = document.querySelector(`.project-check[value="${pid}"]`);
        if (pck) {
            pck.checked = true;
            toggleObjektList(pck);
        }
        
        // Check object
        if (oid) {
            const ock = document.querySelector(`.objekt-check[value="${oid}"][data-pid="${pid}"]`);
            if (ock) ock.checked = true;
        }
    });

    document.getElementById('editor').scrollIntoView({ behavior: 'smooth' });
}

function toggleObjektList(el) {
    const list = document.getElementById('obj-list-' + el.value);
    if (list) list.style.display = el.checked ? 'block' : 'none';
}

function resetForm() {
    document.getElementById('formTitle').innerText = 'Art erstellen';
    document.querySelector('#artenForm').reset();
    document.getElementById('f_id').value = '0';
    document.getElementById('f_active').checked = true;
    document.querySelectorAll('.role-check, .btype-check, .project-check, .objekt-check').forEach(ck => ck.checked = false);
    document.querySelectorAll('.objekt-list').forEach(l => l.style.display = 'none');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
