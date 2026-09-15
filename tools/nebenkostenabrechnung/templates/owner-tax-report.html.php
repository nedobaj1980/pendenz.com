<h2>Eigentümer-/Steuerübersicht <?= htmlspecialchars((string)$year) ?></h2>
<p>Effektiver Abzug: CHF <?= number_format((float)$result['owner_effective'],2,'.',"'") ?></p>
