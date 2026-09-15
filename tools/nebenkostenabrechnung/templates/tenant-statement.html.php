<h2>Mieterabrechnung <?= htmlspecialchars((string)$year) ?></h2>
<p>Umlagefähige Kosten: CHF <?= number_format((float)$result['tenant_total'],2,'.',"'") ?></p>
