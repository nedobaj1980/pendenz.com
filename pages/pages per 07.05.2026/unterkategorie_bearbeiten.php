<?php
// pages/unterkategorie_bearbeiten.php
if (session_status()===PHP_SESSION_NONE) session_start();

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_ui.php';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id<=0) die("❌ Ungültige Unterkategorie-ID.");

// Knoten laden
$stmt = $mysqli->prepare("SELECT id, vorlage_id, parent_id, name_variable, label_default, position, code FROM unterkategorien WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$node = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$node) die("❌ Unterkategorie nicht gefunden.");

$vorlageId = (int)$node['vorlage_id'];

// Breadcrumb ermitteln
function breadcrumb(mysqli $db, array $n): array {
  $path = [];
  $cur = $n;
  while ($cur) {
    $path[] = ['id'=>$cur['id'],'label'=>$cur['label_default'],'code'=>$cur['code'],'name_variable'=>$cur['name_variable']];
    if ($cur['parent_id']===NULL) break;
    $st = $db->prepare("SELECT id, vorlage_id, parent_id, name_variable, label_default, code FROM unterkategorien WHERE id=?");
    $st->bind_param("i",$cur['parent_id']);
    $st->execute();
    $cur = $st->get_result()->fetch_assoc();
    $st->close();
  }
  return array_reverse($path);
}
$crumbs = breadcrumb($mysqli, $node);

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $label = trim($_POST['label_default'] ?? '');
  $type  = trim($_POST['name_variable'] ?? '');
  $code  = trim($_POST['code'] ?? ($node['code'] ?? ''));

  if ($label==='') $errors[] = "Label darf nicht leer sein.";
  if ($type==='')  $errors[] = "Typ (name_variable) darf nicht leer sein.";

  // Code nur ändern, wenn Checkbox gesetzt ist – sonst stabil lassen
  $codeChangeRequested = isset($_POST['allow_code_change']) && $_POST['allow_code_change']=='1';
  if (!$codeChangeRequested) {
    $code = $node['code']; // unverändert
  } else {
    // Validierung für Codes (nur Ziffern, Unterstriche, Bindestriche; muss mit Ziffer beginnen)
    if ($code==='') $errors[] = "Code darf nicht leer sein.";
    if (!preg_match('/^[0-9][0-9_\-]*$/', $code)) {
      $errors[] = "Code ist ungültig. Erlaubt sind Ziffern, Unterstrich (_), Bindestrich (-), und er muss mit einer Ziffer beginnen (z. B. 1_1_1-2).";
    } else {
      // Eindeutigkeit pro Vorlage sicherstellen
      $st = $mysqli->prepare("SELECT id FROM unterkategorien WHERE vorlage_id=? AND code=? AND id<>?");
      $st->bind_param("isi", $vorlageId, $code, $id);
      $st->execute();
      $dup = $st->get_result()->fetch_assoc();
      $st->close();
      if ($dup) $errors[] = "Code bereits in dieser Vorlage vergeben. Bitte anderen Code wählen.";
    }
  }

  if (!$errors) {
    $st = $mysqli->prepare("UPDATE unterkategorien SET name_variable=?, label_default=?, code=? WHERE id=?");
    $st->bind_param("sssi", $type, $label, $code, $id);
    $st->execute();
    $st->close();
    $success = "Änderungen gespeichert.";
    // Hinweis: wir verschieben KEINE Dateien automatisch. Nutze bei Code-Änderung "Ordner nach Codes anlegen/prüfen".
    // Zurückladen, um aktuelle Werte zu zeigen
    $stmt = $mysqli->prepare("SELECT id, vorlage_id, parent_id, name_variable, label_default, position, code FROM unterkategorien WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $node = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $crumbs = breadcrumb($mysqli, $node);
  }
}
?>
<link rel="stylesheet" href="/pendenz.com/assets/css/projekt_baum.css">
<style>
.form-card{border:1px solid #e5e7eb;border-radius:8px;padding:16px;background:#fff;max-width:760px}
.form-row{display:grid;grid-template-columns:160px 1fr;gap:12px;align-items:center;margin-bottom:12px}
.form-row label{font-weight:600}
.form-row input[type="text"], .form-row select{width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:6px}
.help{color:#475569;font-size:12px;margin-top:-6px;margin-bottom:10px}
.note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:6px;padding:8px 10px;margin:8px 0}
.alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:6px;padding:8px 10px;margin:8px 0}
.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:6px;padding:8px 10px;margin:8px 0}
.breadcrumb{margin:0 0 12px 0;color:#334155}
.breadcrumb span{opacity:.9}
.actions-bar{display:flex;gap:8px;margin-top:12px}
.btn{display:inline-block;background:#111;color:#fff;border:1px solid #111;padding:8px 12px;border-radius:6px;text-decoration:none}
.btn.secondary{background:#fff;color:#111;border-color:#cbd5e1}
.btn.danger{background:#dc2626;border-color:#b91c1c}
.small{font-size:12px}
</style>

<main class="container" style="padding:16px;">

  <h1>Unterkategorie bearbeiten</h1>
  <p class="breadcrumb">
    <?php foreach($crumbs as $i=>$c): ?>
      <span><?= htmlspecialchars($c['label']) ?> (<?= htmlspecialchars($c['code'] ?: ('id'.$c['id'])) ?>)</span><?= $i<count($crumbs)-1 ? " › " : "" ?>
    <?php endforeach; ?>
  </p>

  <?php if($success): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if($errors): ?>
    <div class="alert">
      <strong>Bitte prüfen:</strong>
      <ul><?php foreach($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
    </div>
  <?php endif; ?>

  <div class="form-card">
    <form method="post">
      <div class="form-row">
        <label>ID</label>
        <div>#<?= (int)$node['id'] ?></div>
      </div>

      <div class="form-row">
        <label>Vorlage</label>
        <div>#<?= (int)$node['vorlage_id'] ?></div>
      </div>

      <div class="form-row">
        <label>Typ</label>
        <select name="name_variable" required>
          <?php
            $opts = ['projekt'=>'projekt','objekt'=>'objekt','wohnung'=>'wohnung','zimmer'=>'zimmer','custom'=>'custom'];
            foreach($opts as $val=>$txt){
              $sel = ($node['name_variable']===$val) ? "selected" : "";
              echo "<option value='{$val}' {$sel}>".$txt."</option>";
            }
          ?>
        </select>
      </div>

      <div class="form-row">
        <label>Label</label>
        <input type="text" name="label_default" value="<?= htmlspecialchars($node['label_default']) ?>" required>
      </div>

      <div class="form-row">
        <label>Code</label>
        <input type="text" name="code" value="<?= htmlspecialchars($node['code']) ?>" id="codeInput" readonly>
      </div>
      <div class="help">Der <strong>Code</strong> ist der stabile Ordner-Name (z. B. <code>1_1_1-2</code>). Umbenennen nur wenn unbedingt nötig!</div>

      <div class="form-row">
        <label>&nbsp;</label>
        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="allow_code_change" value="1" id="allowCodeChange">
          <span>Ich weiß, was ich tue – Code-Feld entsperren</span>
        </label>
      </div>

      <div class="note small">
        ⚠️ Bei Code-Änderungen werden <strong>keine Dateien automatisch verschoben</strong>.
        Nach dem Speichern kannst du unter der Vorlagenansicht „<em>📁 Ordner nach Codes anlegen/prüfen</em>“ neue Ordner anlegen lassen.
      </div>

      <div class="actions-bar">
        <button type="submit" class="btn">💾 Speichern</button>
        <a class="btn secondary" href="vorlage_bearbeiten.php?id=<?= $vorlageId ?>">↩ Zurück zur Vorlage</a>
        <a class="btn danger" href="unterkategorie_loeschen.php?id=<?= (int)$node['id'] ?>" onclick="return confirm('Wirklich löschen? Achtung: nur möglich, wenn keine Kinder/Dateien vorhanden sind.')">🗑 Löschen</a>
      </div>
    </form>
  </div>

  <div class="note" style="margin-top:14px;">
    Tipp: Falsche Ebene? In der Vorlagen-Übersicht kannst du über die Pfeile „← / →“ die Ebene wechseln (einrücken/ausrücken). Maximal 10 Unterordner pro Ebene.
  </div>
</main>

<script>
document.getElementById('allowCodeChange')?.addEventListener('change', function(){
  const inp = document.getElementById('codeInput');
  if(!inp) return;
  if(this.checked){
    inp.removeAttribute('readonly');
    inp.style.borderColor = '#f59e0b';
    inp.focus();
  } else {
    inp.setAttribute('readonly','readonly');
    inp.style.borderColor = '#cbd5e1';
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
