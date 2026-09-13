<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login(); // Nur eingeloggte User

/* Helper für öffentliche Pfade */
function public_path(?string $relPath): string {
    if (!$relPath) return "";
    $relPath = ltrim($relPath, "/");
    return "/pendenz.com/" . $relPath;
}

/* Projekt-ID prüfen */
$projekt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projekt_id <= 0) die("❌ Kein Projekt gewählt.");

/* Projekt laden */
$stmt = $mysqli->prepare("SELECT id, name, adresse, status, bild FROM projekte WHERE id=?");
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$proj = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$proj) die("❌ Projekt (#{$projekt_id}) nicht gefunden.");

/* KPIs zählen */
$kpis = ['objekte'=>0,'wohnungen'=>0,'mieter'=>0,'pendenzen'=>0];
$q1 = $mysqli->prepare("SELECT COUNT(*) AS c FROM objekte WHERE projekt_id=?");
$q1->bind_param("i",$projekt_id); $q1->execute();
$kpis['objekte'] = $q1->get_result()->fetch_assoc()['c'] ?? 0; $q1->close();

$q2 = $mysqli->prepare("SELECT COUNT(*) AS c FROM wohnungen w JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=?");
$q2->bind_param("i",$projekt_id); $q2->execute();
$kpis['wohnungen'] = $q2->get_result()->fetch_assoc()['c'] ?? 0; $q2->close();

$q3 = $mysqli->prepare("SELECT COUNT(DISTINCT wm.benutzer_id) AS c FROM wohnung_mieter wm JOIN wohnungen w ON wm.wohnung_id=w.id JOIN objekte o ON w.objekt_id=o.id WHERE o.projekt_id=? AND (wm.enddatum IS NULL OR wm.enddatum >= CURDATE())");
$q3->bind_param("i",$projekt_id); $q3->execute();
$kpis['mieter'] = $q3->get_result()->fetch_assoc()['c'] ?? 0; $q3->close();

$q4 = $mysqli->prepare("SELECT COUNT(*) AS c FROM pendenzen WHERE projekt_id=?");
$q4->bind_param("i",$projekt_id); $q4->execute();
$kpis['pendenzen'] = $q4->get_result()->fetch_assoc()['c'] ?? 0; $q4->close();

/* Projektstruktur laden */
$sql = "
SELECT
  o.id AS objekt_id, o.name AS objekt_name, o.bild AS objekt_bild,
  w.id AS wohnung_id, w.name AS wohnung_name, w.bild AS wohnung_bild,
  b.id AS mieter_id, b.name AS mieter_name,
  z.id AS zimmer_id, z.name AS zimmer_name, z.bild AS zimmer_bild,
  g.id AS gegenstand_id, g.name AS gegenstand_name, g.bild AS gegenstand_bild
FROM objekte o
LEFT JOIN wohnungen w ON w.objekt_id=o.id
LEFT JOIN wohnung_mieter wm ON wm.wohnung_id=w.id AND wm.rolle='mieter' AND (wm.enddatum IS NULL OR wm.enddatum>=CURDATE())
LEFT JOIN benutzer b ON b.id = wm.benutzer_id
LEFT JOIN zimmer z ON z.wohnung_id=w.id
LEFT JOIN gegenstaende g ON g.zimmer_id=z.id
WHERE o.projekt_id=?
ORDER BY o.id,w.id,z.id,g.id
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($r = $res->fetch_assoc()) {
    $oid = $r['objekt_id'] ?? null;
    $wid = $r['wohnung_id'] ?? null;
    $zid = $r['zimmer_id'] ?? null;

    if ($oid && !isset($data[$oid])) {
        $data[$oid] = ['id'=>$oid,'name'=>$r['objekt_name'],'bild'=>$r['objekt_bild'],'wohnungen'=>[]];
    }
    if ($oid && $wid && !isset($data[$oid]['wohnungen'][$wid])) {
        $data[$oid]['wohnungen'][$wid] = ['id'=>$wid,'name'=>$r['wohnung_name'],'bild'=>$r['wohnung_bild'],'mieter'=>$r['mieter_name'] ?? null,'zimmer'=>[]];
    }
    if ($oid && $wid && $zid && !isset($data[$oid]['wohnungen'][$wid]['zimmer'][$zid])) {
        $data[$oid]['wohnungen'][$wid]['zimmer'][$zid] = ['id'=>$zid,'name'=>$r['zimmer_name'],'bild'=>$r['zimmer_bild'],'gegenstaende'=>[]];
    }
    if ($oid && $wid && $zid && !empty($r['gegenstand_id'])) {
        $data[$oid]['wohnungen'][$wid]['zimmer'][$zid]['gegenstaende'][] = ['id'=>$r['gegenstand_id'],'name'=>$r['gegenstand_name'],'bild'=>$r['gegenstand_bild']];
    }
}
$stmt->close();

/* Pendenzen des Projekts laden */
$stmt = $mysqli->prepare("SELECT id, titel, status, faelligkeit, zuweisung FROM pendenzen WHERE projekt_id=? ORDER BY faelligkeit ASC");
$stmt->bind_param("i",$projekt_id);
$stmt->execute();
$pendenzen = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Header + Navigation */
include __DIR__ . '/../includes/header.php';
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../includes/nav_dispatch.php';
} else {
    require_once __DIR__ . '/../includes/nav_public.php';
}
?>

<main class="container" style="padding:16px;">

<!-- Projekt Header + KPIs -->
<div class="card" style="margin-bottom:20px; background:#f5f9ff; border-left:6px solid #3498db;">
    <h1><?= htmlspecialchars($proj['name']) ?></h1>
    <?php if(!empty($proj['adresse'])): ?><p><strong>Adresse:</strong> <?= htmlspecialchars($proj['adresse']) ?></p><?php endif; ?>
    <div style="display:flex;gap:16px;margin-top:10px;">
        <div class="card" style="text-align:center;background:#eef7ff;"><h2><?= $kpis['objekte'] ?></h2><p>Objekte</p></div>
        <div class="card" style="text-align:center;background:#fef7ee;"><h2><?= $kpis['wohnungen'] ?></h2><p>Wohnungen</p></div>
        <div class="card" style="text-align:center;background:#f0fff0;"><h2><?= $kpis['mieter'] ?></h2><p>Mieter</p></div>
        <div class="card" style="text-align:center;background:#fff0f5;"><h2><?= $kpis['pendenzen'] ?></h2><p>Pendenzen</p></div>
    </div>
</div>


<div id="chat-root" class="card"></div>
<script defer>
document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('chat-root');
  // Beispiel: Raum-ID 1 anzeigen – später dynamisch aus DB nehmen
  if (window.TeamChat) TeamChat.mountChat(el, {roomId: 1, pollMs: 3000});
});
</script>

<!-- Pendenzen des Projekts -->
<div class="card" style="margin-bottom:20px;">
    <h2>Pendenzen des Projekts</h2>
    <?php if(empty($pendenzen)): ?>
        <p>📭 Noch keine Pendenzen vorhanden.</p>
        <a href="pendenzen_neu.php?projekt_id=<?= $proj['id'] ?>" class="btn">➕ Neue Pendenz</a>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead style="background:#eef7ff;">
                <tr>
                    <th style="padding:8px;border:1px solid #ddd;">Titel</th>
                    <th style="padding:8px;border:1px solid #ddd;">Status</th>
                    <th style="padding:8px;border:1px solid #ddd;">Fälligkeit</th>
                    <th style="padding:8px;border:1px solid #ddd;">Zuweisung</th>
                    <th style="padding:8px;border:1px solid #ddd;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pendenzen as $p): ?>
                <tr>
                    <td style="padding:8px;border:1px solid #ddd;"><?= htmlspecialchars($p['titel']) ?></td>
                    <td style="padding:8px;border:1px solid #ddd;"><?= htmlspecialchars($p['status']) ?></td>
                    <td style="padding:8px;border:1px solid #ddd;"><?= htmlspecialchars($p['faelligkeit']) ?></td>
                    <td style="padding:8px;border:1px solid #ddd;"><?= htmlspecialchars($p['zuweisung']) ?></td>
                    <td style="padding:8px;border:1px solid #ddd;">
                        <a href="pendenzen_edit.php?id=<?= $p['id'] ?>" class="btn btn-small">✏ Bearbeiten</a>
                        <a href="pendenzen_loeschen.php?id=<?= $p['id'] ?>" class="btn btn-small">🗑 Löschen</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
