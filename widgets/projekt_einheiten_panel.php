<?php
// widgets/projekt_einheiten_panel.php
if (!isset($mysqli)) { die("mysqli fehlt"); }
if (!isset($projektId)) { die("\$projektId fehlt"); }
require_once __DIR__ . '/../includes/units_adapter.php';

ua_ensure_konto_entity($mysqli);
$units = ua_units_for_project($mysqli, (int)$projektId);
?>
<div class="card">
  <h3 style="margin:0 0 8px">Einheiten (aus Ordnerstruktur)</h3>
  <?php if (!$units): ?>
    <p class="muted">Keine Einheiten erkannt. Lege im Projekt-Baum Blätter an (z.B. „2.OG rechts“) – Blätter gelten als Einheiten.</p>
  <?php else: ?>
    <table class="table" style="width:100%">
      <thead><tr><th>Pfad</th><th style="width:280px">Aktionen</th></tr></thead>
      <tbody>
      <?php foreach ($units as $u): $uid=(int)$u['id']; ?>
        <tr>
          <td><?= htmlspecialchars($u['path'] ?: $u['label']) ?></td>
          <td style="white-space:nowrap">
            <a class="btn btn-small" href="<?= htmlspecialchars(url('tools/mieterspiegel/index.php').'?projekt_id='.(int)$projektId.'&entity_node_id='.$uid) ?>">🧾 Mieterspiegel</a>
            <a class="btn btn-small btn-secondary" href="<?= htmlspecialchars(url('tools/mieterspiegel/index.php').'?projekt_id='.(int)$projektId.'&entity_node_id='.$uid.'#kontrolle') ?>">✅ Mietkontrolle</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
