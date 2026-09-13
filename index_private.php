<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/nav_dispatch.php';

// Projekte abrufen (nur die, bei denen der User Mitglied ist oder Admin/Superadmin)
$uid = current_user_id();
if (is_admin()) {
    $projekte = $mysqli->query("SELECT * FROM projekte ORDER BY erstellt_am DESC LIMIT 5");
} else {
    $projekte = $mysqli->query("
        SELECT p.* 
        FROM projekte p 
        JOIN projekt_mitglieder pm ON p.id = pm.projekt_id 
        WHERE pm.benutzer_id = $uid 
        ORDER BY p.erstellt_am DESC 
        LIMIT 5
    ");
}

// Offene Pendenzen abrufen (nur die, die der User sehen darf)
if (is_admin()) {
    $pendenzen = $mysqli->query("
        SELECT p.*, pr.name AS projekt_name 
        FROM pendenzen p
        LEFT JOIN projekte pr ON p.projekt_id = pr.id
        WHERE p.status != 'erledigt'
        ORDER BY p.enddatum ASC
        LIMIT 5
    ");
} else {
    // Einfache Filterung für Dashboard (Sichtbarkeit projekt oder explizit zugewiesen)
    $pendenzen = $mysqli->query("
        SELECT p.*, pr.name AS projekt_name 
        FROM pendenzen p
        LEFT JOIN projekte pr ON p.projekt_id = pr.id
        JOIN projekt_mitglieder pm ON pr.id = pm.projekt_id
        WHERE p.status != 'erledigt' 
        AND pm.benutzer_id = $uid
        AND (p.sichtbarkeit = 'projekt' OR p.erstellt_von = $uid OR p.zustaendig_id = $uid)
        ORDER BY p.enddatum ASC
        LIMIT 5
    ");
}
?>
<div class="container two-col">
  <section class="card">
    <h2>Projekte</h2>
    <?php if ($projekte && $projekte->num_rows > 0): ?>
      <?php while($row = $projekte->fetch_assoc()): ?>
        <div class="item-row">
          <?php if (!empty($row['bild'])): ?>
            <img src="<?= htmlspecialchars($row['bild']) ?>" alt="Projektbild" class="thumb">
          <?php endif; ?>
          <div>
            <strong><?= htmlspecialchars($row['name']) ?></strong><br>
            <small><?= htmlspecialchars($row['adresse']) ?></small>
          </div>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <p>Keine Projekte vorhanden.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Anstehende Pendenzen</h2>
    <?php if ($pendenzen && $pendenzen->num_rows > 0): ?>
      <?php while($row = $pendenzen->fetch_assoc()): ?>
        <div class="item-row">
          <div>
            <strong><?= htmlspecialchars($row['titel']) ?></strong><br>
            <small>Projekt: <?= htmlspecialchars($row['projekt_name']) ?> · Fällig: <?= htmlspecialchars($row['enddatum']) ?></small>
          </div>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <p>Keine offenen Pendenzen.</p>
    <?php endif; ?>
  </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
