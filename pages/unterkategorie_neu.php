<?php
if(session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_login();

$vorlageId = (int)($_GET['vorlage_id'] ?? 0);
$parentId  = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : null;
if($vorlageId<=0) die("❌ vorlage_id fehlt.");

if($_SERVER['REQUEST_METHOD']==='POST'){
    $label = trim($_POST['label_default'] ?? '');
    $name  = trim($_POST['name_variable'] ?? 'custom');
    if($label==='') die("❌ Label erforderlich.");

    // Anzahl Geschwister zählen
    if($parentId===null){
        $st=$mysqli->prepare("SELECT COUNT(*) c FROM unterkategorien WHERE vorlage_id=? AND parent_id IS NULL");
        $st->bind_param("i",$vorlageId);
    } else {
        $st=$mysqli->prepare("SELECT COUNT(*) c FROM unterkategorien WHERE vorlage_id=? AND parent_id=?");
        $st->bind_param("ii",$vorlageId,$parentId);
    }
    $st->execute(); $c=(int)($st->get_result()->fetch_assoc()['c'] ?? 0); $st->close();
    if($c>=10) die("❌ Maximal 10 Unterordner pro Ebene erreicht.");

    // nächste Position
    if($parentId===null){
        $st=$mysqli->prepare("SELECT COALESCE(MAX(position),0)+1 p FROM unterkategorien WHERE vorlage_id=? AND parent_id IS NULL");
        $st->bind_param("i",$vorlageId);
    } else {
        $st=$mysqli->prepare("SELECT COALESCE(MAX(position),0)+1 p FROM unterkategorien WHERE vorlage_id=? AND parent_id=?");
        $st->bind_param("ii",$vorlageId,$parentId);
    }
    $st->execute(); $pos=(int)($st->get_result()->fetch_assoc()['p'] ?? 1); $st->close();

    // code generieren (vereinfacht: Parent-Code + Suffix)
    $parentCode = null;
    if($parentId!==null){
        $st=$mysqli->prepare("SELECT code FROM unterkategorien WHERE id=?");
        $st->bind_param("i",$parentId); $st->execute();
        $parentCode = $st->get_result()->fetch_assoc()['code'] ?? null;
        $st->close();
    }
    $suffix = str_pad((string)$pos, 1, '0', STR_PAD_LEFT); // 1-stellig (1..9,10 → du hast Limit 10)
    $code = $parentCode ? ($parentCode . (strpos($parentCode,'-')!==false ? '_' : '-') . $suffix) : (string)($pos);

    $st=$mysqli->prepare("INSERT INTO unterkategorien (vorlage_id, parent_id, name_variable, label_default, position, code)
                          VALUES (?,?,?,?,?,?)");
    if($parentId===null) { $null=null; $st->bind_param("ississ",$vorlageId,$null,$name,$label,$pos,$code); }
    else                 { $st->bind_param("iissis",$vorlageId,$parentId,$name,$label,$pos,$code); }
    $st->execute(); $st->close();

    header("Location: vorlage_bearbeiten.php?id=".$vorlageId);
    exit;
}
?>
<!doctype html><title>Unterkategorie neu</title>
<form method="post">
  <label>Label: <input name="label_default" required></label><br>
  <label>Typ (name_variable): 
    <select name="name_variable">
      <option value="objekt">objekt</option>
      <option value="wohnung">wohnung</option>
      <option value="zimmer">zimmer</option>
      <option value="custom">custom</option>
    </select>
  </label><br>
  <button type="submit">Anlegen</button>
</form>
