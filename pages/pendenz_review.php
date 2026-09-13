<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

/* Helper: relative Pfade in öffentliche URLs umwandeln */
function public_path(?string $relPath): string {
    if (!$relPath) return "";
    $relPath = ltrim($relPath, "/");
    return "/pendenz.com/" . $relPath;
}

$projekt_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($projekt_id <= 0) die("❌ Kein Projekt gewählt.");

/* Projekt laden */
$stmt = $mysqli->prepare("SELECT id, name, adresse, status, bild FROM projekte WHERE id=?");
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$proj = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$proj) die("❌ Projekt (#{$projekt_id}) nicht gefunden.");

/* Struktur laden */
$sql = "
SELECT
  o.id   AS objekt_id, o.name   AS objekt_name, o.bild   AS objekt_bild,
  w.id   AS wohnung_id, w.name  AS wohnung_name, w.bild  AS wohnung_bild,
  b.id   AS mieter_id,  b.name  AS mieter_name,
  z.id   AS zimmer_id,  z.name  AS zimmer_name, z.bild  AS zimmer_bild,
  g.id   AS gegenstand_id, g.name AS gegenstand_name, g.bild AS gegenstand_bild
FROM objekte o
LEFT JOIN wohnungen w       ON w.objekt_id = o.id
LEFT JOIN wohnung_mieter wm ON wm.wohnung_id = w.id
                             AND wm.rolle='mieter'
                             AND (wm.enddatum IS NULL OR wm.enddatum >= CURDATE())
LEFT JOIN benutzer b        ON b.id = wm.benutzer_id
LEFT JOIN zimmer z          ON z.wohnung_id = w.id
LEFT JOIN gegenstaende g    ON g.zimmer_id = z.id
WHERE o.projekt_id=?
ORDER BY o.id, w.id, z.id, g.id
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $projekt_id);
$stmt->execute();
$res = $stmt->get_result();

/* Hierarchie in PHP gruppieren */
$data = [];
while ($r = $res->fetch_assoc()) {
    $oid = $r['objekt_id'] ?? null;
    $wid = $r['wohnung_id'] ?? null;
    $zid = $r['zimmer_id'] ?? null;

    if ($oid && !isset($data[$oid])) {
        $data[$oid] = [
            'id'=>$oid,'name'=>$r['objekt_name'],'bild'=>$r['objekt_bild'],'wohnungen'=>[]
        ];
    }
    if ($oid && $wid && !isset($data[$oid]['wohnungen'][$wid])) {
        $data[$oid]['wohnungen'][$wid] = [
            'id'=>$wid,'name'=>$r['wohnung_name'],'bild'=>$r['wohnung_bild'],
            'mieter'=>$r['mieter_name'] ?? null,'zimmer'=>[]
        ];
    }
    if ($oid && $wid && $zid && !isset($data[$oid]['wohnungen'][$wid]['zimmer'][$zid])) {
        $data[$oid]['wohnungen'][$wid]['zimmer'][$zid] = [
            'id'=>$zid,'name'=>$r['zimmer_name'],'bild'=>$r['zimmer_bild'],'gegenstaende'=>[]
        ];
    }
    if ($oid && $wid && $zid && !empty($r['gegenstand_id'])) {
        $data[$oid]['wohnungen'][$wid]['zimmer'][$zid]['gegenstaende'][] = [
            'id'=>$r['gegenstand_id'],'name'=>$r['gegenstand_name'],'bild'=>$r['gegenstand_bild']
        ];
    }
}
$stmt->close();
?>

<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="container" style="padding:16px;">

  <!-- Dashboard Header -->
  <div class="card" style="margin-bottom:20px; background:#f5f9ff; border-left:6px solid #3498db;">
    <div style="display:flex;gap:20px;align-items:flex-start;">
      <?php if (!empty($proj['bild'])): ?>
        <img src="<?= public_path($proj['bild']) ?>" alt="Projektbild"
             style="max-width:220px;border-radius:8px;border:1px solid #ddd;">
      <?php endif; ?>
      <div>
        <h1 style="margin:0;"><?= htmlspecialchars($proj['name']) ?></h1>
        <p><strong>Status:</strong> <?= htmlspecialchars($proj['status']) ?></p>
        <?php if (!empty($proj['adresse'])): ?>
          <p><strong>Adresse:</strong> <?= htmlspecialchars($proj['adresse']) ?></p>
        <?php endif; ?>
        <div style="margin-top:10px;display:flex;gap:10px;">
          <a href="projekte.php?edit=<?= $proj['id'] ?>" class="btn">✏ Bearbeiten</a>
          <a href="objekt_neu.php?projekt_id=<?= $proj['id'] ?>" class="btn">➕ Objekt</a>
          <a href="pendenzen.php?projekt_id=<?= $proj['id'] ?>" class="btn">📋 Pendenzen</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Projektstruktur -->
  <?php if (empty($data)): ?>
    <div class="card">
      <p>📭 Noch keine Objekte, Wohnungen oder Zimmer erfasst.</p>
      <a href="objekt_neu.php?projekt_id=<?= $proj['id'] ?>" class="btn">➕ Jetzt Objekt hinzufügen</a>
    </div>
  <?php else: ?>
    <?php foreach ($data as $objekt): ?>
      <div class="card" style="margin-bottom:16px;">
        <h2 style="margin:0 0 8px 0;">🏢 <?= htmlspecialchars($objekt['name']) ?></h2>
        <?php if ($objekt['bild']): ?>
          <img src="<?= public_path($objekt['bild']) ?>" alt="Objektbild"
               style="max-width:180px;border-radius:6px;border:1px solid #ddd;margin-bottom:8px;">
        <?php endif; ?>
        <div style="margin-bottom:8px;">
          <a href="objekt_edit.php?id=<?= $objekt['id'] ?>" class="btn btn-small">✏ Bearbeiten</a>
          <a href="wohnung_neu.php?objekt_id=<?= $objekt['id'] ?>" class="btn btn-small">➕ Wohnung</a>
        </div>

        <?php foreach ($objekt['wohnungen'] as $wohnung): ?>
          <div class="card" style="margin:10px;padding:10px;background:#fafafa;">
            <h3 style="margin:0;">🏠 <?= htmlspecialchars($wohnung['name']) ?></h3>
            <?php if ($wohnung['bild']): ?>
              <img src="<?= public_path($wohnung['bild']) ?>" alt="Wohnung"
                   style="max-width:120px;border-radius:6px;border:1px solid #ddd;">
            <?php endif; ?>
            <p><strong>Mieter:</strong> <?= htmlspecialchars($wohnung['mieter'] ?? '-') ?></p>
            <div style="margin-bottom:8px;">
              <a href="wohnung_edit.php?id=<?= $wohnung['id'] ?>" class="btn btn-small">✏ Bearbeiten</a>
              <a href="zimmer_neu.php?wohnung_id=<?= $wohnung['id'] ?>" class="btn btn-small">➕ Zimmer</a>
            </div>

            <?php foreach ($wohnung['zimmer'] as $zimmer): ?>
              <div class="card" style="margin:8px;padding:8px;">
                <h4 style="margin:0;">🛏 <?= htmlspecialchars($zimmer['name']) ?></h4>
                <?php if ($zimmer['bild']): ?>
                  <img src="<?= public_path($zimmer['bild']) ?>" alt="Zimmer"
                       style="max-width:90px;border-radius:6px;border:1px solid #ddd;">
                <?php endif; ?>
                <div style="margin-bottom:6px;">
                  <a href="zimmer_edit.php?id=<?= $zimmer['id'] ?>" class="btn btn-tiny">✏</a>
                  <a href="gegenstand_neu.php?zimmer_id=<?= $zimmer['id'] ?>" class="btn btn-tiny">➕ Gegenstand</a>
                </div>

                <?php if (!empty($zimmer['gegenstaende'])): ?>
                  <ul>
                    <?php foreach ($zimmer['gegenstaende'] as $g): ?>
                      <li>
                        <?= htmlspecialchars($g['name']) ?>
                        <?php if ($g['bild']): ?>
                          <img src="<?= public_path($g['bild']) ?>" class="thumb-xs" alt="Gegenstand">
                        <?php endif; ?>
                        <a href="gegenstand_edit.php?id=<?= $g['id'] ?>" class="btn btn-tiny">✏</a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p style="color:#777;">Keine Gegenstände</p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
