<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/functions.php'; // handle_upload(), best_image_url(), site_prefix()
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/user_ui.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_role.php';

$PREFIX = site_prefix();

/* ---------- DB: aktuellen Benutzer ---------- */
$sid = (int)($_SESSION['user_id'] ?? 0);
$stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
$stmt->bind_param("i",$sid); $stmt->execute();
$self = $stmt->get_result()->fetch_assoc();
$stmt->close();
if(!$self){ http_response_code(404); exit('Benutzer nicht gefunden.'); }

/* ---------- Helfer ---------- */
function split_name_guess($full){ $full=trim(preg_replace('/\s+/',' ',$full?:'')); if($full==='') return ['vorname'=>'','nachname'=>'']; $p=explode(' ',$full); return count($p)===1?['vorname'=>$p[0],'nachname'=>'']:['vorname'=>implode(' ',array_slice($p,0,-1)),'nachname'=>end($p)]; }
function parse_address_guess($addr){
  $addr=trim($addr?:''); $o=['strasse'=>'','hausnummer'=>'','plz'=>'','ort'=>'']; if($addr==='') return $o;
  $left=$addr; $right='';
  if(($pos=strpos($addr,','))!==false){ $left=trim(substr($addr,0,$pos)); $right=trim(substr($addr,$pos+1)); }
  $t=preg_split('/\s+/',$left); $last=$t?end($t):'';
  if($last && preg_match('/^\d+[a-zA-Z\-\/]*$/',$last)){ $o['hausnummer']=$last; array_pop($t); }
  $o['strasse']=trim(implode(' ',$t));
  if($right && preg_match('/^\s*([0-9]{4,6})\s+(.*)\s*$/u',$right,$m)){ $o['plz']=$m[1]; $o['ort']=trim($m[2]); }
  return $o;
}
function date_in($d){ if(!$d) return null; $d=trim($d); if($d==='') return null; if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) return $d; if(preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/',$d,$m)) return sprintf('%04d-%02d-%02d',$m[3],$m[2],$m[1]); $ts=strtotime($d); return $ts?date('Y-m-d',$ts):null; }
function date_for_input($d){ if(!$d) return null; if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) return $d; if(($ts=strtotime($d))) return date('Y-m-d',$ts); return null; }
function compose_addr($s,$hn,$plz,$ort){ $street=trim(trim($s).' '.trim($hn)); $city=trim(trim($plz).' '.trim($ort)); return trim($street.($street&&$city?', ':'').$city); }
function vis_can_see($level,$aud){ return $aud==='private' ? true : ($aud==='internal' ? in_array($level,['internal','public'],true) : ($aud==='project' ? in_array($level,['project','public'],true) : $level==='public')); }

/* ---------- Felddefinitionen ---------- */
$LEVELS = ['private','internal','project','public'];
$LEVEL_LABEL = ['private'=>'Nur ich','internal'=>'Intern','project'=>'Projekt','public'=>'Öffentlich'];
$LEVEL_ICON  = ['private'=>'🔒','internal'=>'🏢','project'=>'👥','public'=>'🌍'];

/* Vorschau-Felder (kompakt aus Detailfeldern gebaut) */
$FIELDS_PREVIEW = [
  'name'            =>'Name',
  'email'           =>'E-Mail',
  'telefonnummer'   =>'Telefon',
  'adresse'         =>'Adresse',
  'firma_name'      =>'Firma – Name',
  'firma_adresse'   =>'Firma – Adresse',
  'firma_telefon'   =>'Firma – Telefon',
  'firma_email'     =>'Firma – E-Mail',
  'firma_website'   =>'Firma – Website',
  'geburtsdatum'    =>'Geburtstag',
  'heimatland'      =>'Heimatland',
  'position'        =>'Position',
  'beruf'           =>'Beruf',
];

/* Eingabegruppen */
$FIELDS_PERSON = [
  ['key'=>'anrede','label'=>'Anrede','type'=>'select','options'=>[''=>'– bitte wählen –','Herr'=>'Herr','Frau'=>'Frau']],
  ['key'=>'vorname','label'=>'Vorname'], ['key'=>'nachname','label'=>'Nachname'],
  ['key'=>'telefonnummer','label'=>'Telefon'], ['key'=>'email','label'=>'E-Mail','type'=>'email'],
  ['key'=>'geburtsdatum','label'=>'Geburtstag','type'=>'date'],
  ['key'=>'heimatland','label'=>'Heimatland'], ['key'=>'beruf','label'=>'Beruf'], ['key'=>'position','label'=>'Position'],
];
$FIELDS_ADDR_PRIVATE = [
  ['key'=>'strasse','label'=>'Straße'], ['key'=>'hausnummer','label'=>'Nr'],
  ['key'=>'plz','label'=>'PLZ'], ['key'=>'ort','label'=>'Ort'],
];
$FIELDS_COMPANY = [
  ['key'=>'firma_name','label'=>'Firma – Name'],
  ['key'=>'firma_telefon','label'=>'Firma – Telefon'],
  ['key'=>'firma_email','label'=>'Firma – E-Mail','type'=>'email'],
  ['key'=>'firma_website','label'=>'Firma – Website'],
  ['key'=>'firma_strasse','label'=>'Firma – Straße'],
  ['key'=>'firma_hausnummer','label'=>'Firma – Nr'],
  ['key'=>'firma_plz','label'=>'Firma – PLZ'],
  ['key'=>'firma_ort','label'=>'Firma – Ort'],
];

/* ---------- profile_vis laden + Defaults ---------- */
$profileVis = json_decode($self['profile_vis'] ?? "{}", true) ?: [];
$allVisKeys = [];
foreach ([$FIELDS_PERSON,$FIELDS_ADDR_PRIVATE,$FIELDS_COMPANY] as $group) foreach($group as $f) $allVisKeys[]=$f['key'];
$allVisKeys = array_unique(array_merge($allVisKeys, array_keys($FIELDS_PREVIEW), ['profilbild','firmenlogo','titelbild']));
foreach($allVisKeys as $k) if(empty($profileVis[$k])) $profileVis[$k]='private';
$profileVis += ['titelbild_max_height'=>260,'profilbild_max_size'=>160,'firmenlogo_max_size'=>160];

/* ---------- Prefill Detail aus alten Feldern ---------- */
$form = $self;
if (empty($form['vorname']) && empty($form['nachname']) && !empty($self['name'])) { $nm = split_name_guess($self['name']); $form['vorname']=$nm['vorname']; $form['nachname']=$nm['nachname']; }
if (empty($form['strasse']) && empty($form['plz']) && !empty($self['adresse'])) { $ad = parse_address_guess($self['adresse']); foreach($ad as $k=>$v) $form[$k]=$v; }
if (empty($form['firma_strasse']) && empty($form['firma_plz']) && !empty($self['firma_adresse'])) {
  $fa = parse_address_guess($self['firma_adresse']);
  $form['firma_strasse']=$fa['strasse']; $form['firma_hausnummer']=$fa['hausnummer']; $form['firma_plz']=$fa['plz']; $form['firma_ort']=$fa['ort'];
}
$form['geburtsdatum'] = date_for_input($form['geburtsdatum'] ?? null);

/* ---------- POST: Speichern ---------- */
$flash="";
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    csrf_validate_or_throw($_POST['csrf'] ?? null);

    // Werte holen
    $data=[];
    // Personen
    foreach($FIELDS_PERSON as $f){
      $k=$f['key']; $v=$_POST[$k] ?? '';
      $data[$k] = ($k==='geburtsdatum') ? date_in($v) : trim($v);
      $pv=$_POST['vis_'.$k] ?? null; if($pv && in_array($pv,$LEVELS,true)) $profileVis[$k]=$pv;
    }
    // Pflicht
    $fullName = trim(($data['vorname'] ?? '').' '.($data['nachname'] ?? ''));
    if($fullName==='' || ($data['email'] ?? '')==='') throw new Exception('Vorname/Nachname und E-Mail sind Pflichtfelder.');

    // Privatadresse
    foreach($FIELDS_ADDR_PRIVATE as $f){
      $k=$f['key']; $data[$k]=trim($_POST[$k] ?? '');
      $pv=$_POST['vis_'.$k] ?? null; if($pv && in_array($pv,$LEVELS,true)) $profileVis[$k]=$pv;
    }
    $adresse = compose_addr($data['strasse'],$data['hausnummer'],$data['plz'],$data['ort']);

    // Firma
    foreach($FIELDS_COMPANY as $f){
      $k=$f['key']; $data[$k]=trim($_POST[$k] ?? '');
      $pv=$_POST['vis_'.$k] ?? null; if($pv && in_array($pv,$LEVELS,true)) $profileVis[$k]=$pv;
    }
    $firma_adresse = compose_addr($data['firma_strasse'],$data['firma_hausnummer'],$data['firma_plz'],$data['firma_ort']);

    // Bilder
    $profilbild = handle_upload('profilbild', $self['profilbild'] ?? null, $sid, ($fullName?:'user'), ['image/jpeg','image/png','image/gif'], 5_000_000, 800, 800) ?? ($self['profilbild'] ?? null);
    $firmenlogo = handle_upload('firmenlogo', $self['firmenlogo'] ?? null, $sid, ($fullName?:'user'), ['image/jpeg','image/png','image/gif'], 5_000_000, 800, 800) ?? ($self['firmenlogo'] ?? null);
    $titelbild  = handle_upload('titelbild',  $self['titelbild']  ?? null, $sid, ($fullName?:'user'), ['image/jpeg','image/png','image/gif'], 8_000_000, 2400, 1200) ?? ($self['titelbild']  ?? null);
    foreach(['profilbild','firmenlogo','titelbild'] as $k){ $pv=$_POST['vis_'.$k] ?? 'private'; if(in_array($pv,$LEVELS,true)) $profileVis[$k]=$pv; }

    // Größen
    $profileVis['profilbild_max_size']  = max(80,  min(400, (int)($_POST['profilbild_max_size_num'] ?? $_POST['profilbild_max_size'] ?? $profileVis['profilbild_max_size'])));
    $profileVis['firmenlogo_max_size']  = max(80,  min(400, (int)($_POST['firmenlogo_max_size_num'] ?? $_POST['firmenlogo_max_size'] ?? $profileVis['firmenlogo_max_size'])));
    $profileVis['titelbild_max_height'] = max(100, min(800, (int)($_POST['titelbild_max_height_num'] ?? $_POST['titelbild_max_height'] ?? $profileVis['titelbild_max_height'])));
    $visJson = json_encode($profileVis, JSON_UNESCAPED_UNICODE);

    // UPDATE dynamisch
    $cols = [
      'name'         => $fullName,
      'email'        => $data['email'],
      'telefonnummer'=> $data['telefonnummer'],
      'adresse'      => $adresse,
      'anrede'       => $data['anrede'],
      'vorname'      => $data['vorname'],
      'nachname'     => $data['nachname'],
      'strasse'      => $data['strasse'],
      'hausnummer'   => $data['hausnummer'],
      'plz'          => $data['plz'],
      'ort'          => $data['ort'],
      'firma_name'   => $data['firma_name'],
      'firma_adresse'=> $firma_adresse,
      'firma_telefon'=> $data['firma_telefon'],
      'firma_email'  => $data['firma_email'],
      'firma_website'=> $data['firma_website'],
      'firma_strasse'=> $data['firma_strasse'],
      'firma_hausnummer'=> $data['firma_hausnummer'],
      'firma_plz'    => $data['firma_plz'],
      'firma_ort'    => $data['firma_ort'],
      'geburtsdatum' => $data['geburtsdatum'],
      'heimatland'   => $data['heimatland'],
      'position'     => $data['position'],
      'beruf'        => $data['beruf'],
      'profilbild'   => $profilbild,
      'firmenlogo'   => $firmenlogo,
      'titelbild'    => $titelbild,
      'profile_vis'  => $visJson
    ];
    $setParts=[]; $types=''; $vals=[];
    foreach($cols as $c=>$v){ $setParts[]="$c=?"; $types.='s'; $vals[]=$v; }
    $sql = "UPDATE benutzer SET ".implode(',', $setParts).", profile_updated_at=NOW() WHERE id=?";
    $types.='i'; $vals[]=$sid;

    $stmt=$mysqli->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();

    $flash="✅ Profil gespeichert.";

    // Reload
    $stmt = $mysqli->prepare("SELECT * FROM benutzer WHERE id=?");
    $stmt->bind_param("i",$sid); $stmt->execute();
    $self = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $profileVis = json_decode($self['profile_vis'] ?? "{}", true) ?: $profileVis;

  }catch(Throwable $e){ $flash = "❌ ".$e->getMessage(); }
}

/* ---------- Anzeige-URLs + Größen ---------- */
$profilURL = best_image_url($self['profilbild'] ?? null);
$logoURL   = best_image_url($self['firmenlogo'] ?? null);
$titelURL  = best_image_url($self['titelbild']  ?? null);
$pbMaxW    = (int)($profileVis['profilbild_max_size']  ?? 160);
$lgMaxW    = (int)($profileVis['firmenlogo_max_size']  ?? 160);
$tbMaxH    = (int)($profileVis['titelbild_max_height'] ?? 260);

/* ---------- Komponierte Vorschauwerte ---------- */
$preview = $self;
$nm = trim(($self['vorname'] ?? '').' '.($self['nachname'] ?? '')); if($nm!=='') $preview['name']=$nm;
$addr = compose_addr($self['strasse'] ?? '', $self['hausnummer'] ?? '', $self['plz'] ?? '', $self['ort'] ?? ''); if($addr!=='') $preview['adresse']=$addr;
$faddr = compose_addr($self['firma_strasse'] ?? '', $self['firma_hausnummer'] ?? '', $self['firma_plz'] ?? '', $self['firma_ort'] ?? ''); if($faddr!=='') $preview['firma_adresse']=$faddr;

/* ---------- Render-Helper ---------- */
function render_row($key,$label,$value,$vis,$type='text',$options=null){
  global $LEVEL_ICON,$LEVEL_LABEL;
  $id="f_$key"; $icon=$LEVEL_ICON[$vis]??'🔒'; $title=$LEVEL_LABEL[$vis]??'';
  echo "<label for=\"$id\">".htmlspecialchars($label)."</label>";
  if($type==='select'){
    echo "<select id=\"$id\" name=\"$key\">";
    foreach(($options?:[]) as $val=>$txt){
      $sel = ((string)$value===(string)$val)?'selected':'';
      echo "<option value=\"".htmlspecialchars($val)."\" $sel>".htmlspecialchars($txt)."</option>";
    }
    echo "</select>";
  } else {
    $inputType = in_array($type,['email','date'])? $type : 'text';
    $val = htmlspecialchars((string)$value);
    echo "<input type=\"$inputType\" id=\"$id\" name=\"$key\" value=\"$val\">";
  }
  echo "<button type=\"button\" class=\"icon-toggle\" data-for=\"$key\" title=\"".htmlspecialchars($title)."\">$icon</button>";
  echo "<input type=\"hidden\" name=\"vis_$key\" id=\"vis_$key\" value=\"".htmlspecialchars($vis)."\">";
}

/* ---------- Styles ---------- */
?>
<style>
.container{max-width:1240px;margin:20px auto}
.badge{display:inline-block;padding:4px 10px;border-radius:12px;background:#1e40af;color:#fff;font-size:12px}
.btn{padding:8px 12px;border:0;border-radius:6px;background:#0b5cff;color:#fff;cursor:pointer}
.card{background:#fff;border-radius:10px;padding:12px;margin:12px 0;box-shadow:0 2px 6px rgba(0,0,0,.06)}
.hero{padding:8px;margin:12px 0;background:#e6efff;border-radius:8px;display:flex;justify-content:space-between;align-items:center}
.top-title{margin:-8px -8px 16px -8px;overflow:hidden;border-radius:8px}
.summary{display:grid;grid-template-columns:1fr 2fr 1fr;gap:20px;align-items:start}
.summary .left img{border-radius:8px;display:block}
.summary .right{display:flex;justify-content:flex-end}
.summary .right img{display:block;border-radius:8px}
.kv{display:grid;grid-template-columns:220px 1fr;gap:10px;align-items:center}
.kv+.kv{margin-top:8px}
.field-rows{display:grid;grid-template-columns:260px 1fr 40px;column-gap:12px;row-gap:10px;align-items:center}
.field-rows .icon-toggle{cursor:pointer;font-size:18px;text-align:center;user-select:none;border:1px solid transparent;background:transparent}
.field-rows .icon-toggle:focus{outline:none;border-color:#c7d2fe;border-radius:6px}
.field-rows input, .field-rows select{padding:8px;border:1px solid #e5e7eb;border-radius:6px;width:100%}
.img-thumb{border-radius:6px;display:block}
.img-wrap{display:flex;flex-direction:column;align-items:flex-start;gap:8px}
.accordion{border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
.accordion .row-of-four{display:flex;flex-direction:column;gap:8px;padding:8px;background:#f8fafc}
.accordion details{border-top:1px solid #e5e7eb}
.accordion details:first-of-type{border-top:0}
.accordion summary{padding:10px 12px;cursor:pointer;font-weight:600;background:#f8fafc}
.accordion details[open] summary{background:#eef2ff; box-shadow:inset 0 0 0 2px #c7d2fe;}
.panel{padding:12px}
.panel .summary{display:grid;grid-template-columns:1fr 2fr 1fr;gap:20px;align-items:start}
.inline-size{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.inline-size input[type="number"]{width:100px}
.hint{font-size:12px;color:#555;margin-top:6px}
</style>

<div class="container" id="profile-root" data-prefix="<?= htmlspecialchars($PREFIX, ENT_QUOTES) ?>">
  <header class="hero">
    <h1 style="margin:0;">Mein Profil</h1>
    <span class="badge"><?= htmlspecialchars($self['rolle'] ?? 'gast') ?></span>
  </header>

  <?php if(!empty($flash)): ?>
    <div class="card" style="border-left:4px solid #1abc9c;"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <!-- OBERER BEREICH: Vorschau -->
  <div class="card">
    <?php if($titelURL): ?>
      <div class="top-title"><img src="<?= htmlspecialchars($titelURL) ?>" alt="Titelbild" style="width:100%;max-height:<?= (int)$tbMaxH ?>px;object-fit:cover"></div>
    <?php endif; ?>
    <div class="summary">
      <div class="left">
        <?php if($profilURL): ?><img src="<?= htmlspecialchars($profilURL) ?>" alt="Profilbild" style="max-width:<?= (int)$pbMaxW ?>px;border-radius:50%"><?php endif; ?>
      </div>
      <div>
        <?php foreach($FIELDS_PREVIEW as $k=>$label): $val=trim((string)($preview[$k]??'')); ?>
          <div class="kv"><div class="key"><strong><?= htmlspecialchars($label) ?></strong></div><div class="val"><?= $val!==''?nl2br(htmlspecialchars($val)):'—' ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="right">
        <?php if($logoURL): ?><img src="<?= htmlspecialchars($logoURL) ?>" alt="Firmenlogo" style="max-width:<?= (int)$lgMaxW ?>px"><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- BEARBEITEN -->
  <div class="card">
    <h2>Mein Profil bearbeiten</h2>
    <form method="post" enctype="multipart/form-data" id="profile-form" autocomplete="on">
      <?= csrf_input() ?>

      <div class="field-rows">
        <?php
          foreach($FIELDS_PERSON as $f){
            $k=$f['key']; $label=$f['label']; $type=$f['type']??'text'; $opts=$f['options']??null;
            $val = ($k==='geburtsdatum') ? date_for_input($self[$k] ?? null) : ($self[$k] ?? '');
            render_row($k,$label,$val,$profileVis[$k]??'private',$type,$opts);
          }
          foreach($FIELDS_ADDR_PRIVATE as $f){ render_row($f['key'],$f['label'],$self[$f['key']] ?? '',$profileVis[$f['key']]??'private'); }
          foreach($FIELDS_COMPANY as $f){
            $type=$f['type']??'text';
            render_row($f['key'],$f['label'],$self[$f['key']] ?? '',$profileVis[$f['key']]??'private',$type);
          }
        ?>

        <!-- Profilbild -->
        <label><strong>Profilbild</strong></label>
        <div class="img-wrap">
          <?php if($profilURL): ?><img src="<?= htmlspecialchars($profilURL) ?>" class="img-thumb" alt="Profilbild" style="max-width:<?= (int)$pbMaxW ?>px;border-radius:50%"><?php endif; ?>
          <input type="file" name="profilbild" accept="image/*">
          <div class="inline-size">
            <label style="min-width:160px">Profilbild-Größe</label>
            <input type="range"  id="profilbild_max_size"     name="profilbild_max_size"     min="80" max="400" step="5"  value="<?= (int)$pbMaxW ?>">
            <input type="number" id="profilbild_max_size_num" name="profilbild_max_size_num" min="80" max="400" step="5"  value="<?= (int)$pbMaxW ?>"> <span>px</span>
          </div>
          <div class="hint">PNG/JPG/GIF bis 5MB, ~800px</div>
        </div>
        <?php $lvl=$profileVis['profilbild']??'private'; ?>
        <button type="button" class="icon-toggle" data-for="profilbild" title="<?= $LEVEL_LABEL[$lvl] ?>"><?= $LEVEL_ICON[$lvl] ?></button>
        <input type="hidden" name="vis_profilbild" id="vis_profilbild" value="<?= htmlspecialchars($lvl) ?>">

        <!-- Firmenlogo -->
        <label><strong>Firmenlogo</strong></label>
        <div class="img-wrap">
          <?php if($logoURL): ?><img src="<?= htmlspecialchars($logoURL) ?>" class="img-thumb" alt="Firmenlogo" style="max-width:<?= (int)$lgMaxW ?>px"><?php endif; ?>
          <input type="file" name="firmenlogo" accept="image/*">
          <div class="inline-size">
            <label style="min-width:160px">Firmenlogo-Größe</label>
            <input type="range"  id="firmenlogo_max_size"     name="firmenlogo_max_size"     min="80" max="400" step="5"  value="<?= (int)$lgMaxW ?>">
            <input type="number" id="firmenlogo_max_size_num" name="firmenlogo_max_size_num" min="80" max="400" step="5"  value="<?= (int)$lgMaxW ?>"> <span>px</span>
          </div>
        </div>
        <?php $lvl=$profileVis['firmenlogo']??'private'; ?>
        <button type="button" class="icon-toggle" data-for="firmenlogo" title="<?= $LEVEL_LABEL[$lvl] ?>"><?= $LEVEL_ICON[$lvl] ?></button>
        <input type="hidden" name="vis_firmenlogo" id="vis_firmenlogo" value="<?= htmlspecialchars($lvl) ?>">

        <!-- Titelbild -->
        <label><strong>Titelbild</strong></label>
        <div class="img-wrap">
          <?php if($titelURL): ?><img src="<?= htmlspecialchars($titelURL) ?>" class="img-thumb" alt="Titelbild" style="max-height:<?= (int)$tbMaxH ?>px;width:100%;object-fit:cover"><?php endif; ?>
          <input type="file" name="titelbild" accept="image/*">
          <div class="inline-size">
            <label style="min-width:160px">Titelbild-Höhe</label>
            <input type="range"  id="titelbild_max_height"     name="titelbild_max_height"     min="100" max="800" step="10" value="<?= (int)$tbMaxH ?>">
            <input type="number" id="titelbild_max_height_num" name="titelbild_max_height_num" min="100" max="800" step="10" value="<?= (int)$tbMaxH ?>"> <span>px</span>
          </div>
        </div>
        <?php $lvl=$profileVis['titelbild']??'private'; ?>
        <button type="button" class="icon-toggle" data-for="titelbild" title="<?= $LEVEL_LABEL[$lvl] ?>"><?= $LEVEL_ICON[$lvl] ?></button>
        <input type="hidden" name="vis_titelbild" id="vis_titelbild" value="<?= htmlspecialchars($lvl) ?>">
      </div>

      <div style="margin-top:12px"><button class="btn" type="submit">Speichern</button></div>
    </form>
  </div>

  <!-- UNTERE PROFILVORSCHAU -->
  <div class="card">
    <h2>Profilvorschau</h2>
    <div class="accordion">
      <div class="row-of-four">
        <?php foreach(['private'=>'🔒 Nur ich','internal'=>'🏢 Intern','project'=>'👥 Projekt','public'=>'🌍 Öffentlich'] as $audKey=>$audLbl): ?>
          <details>
            <summary><?= htmlspecialchars($audLbl) ?></summary>
            <div class="panel">
              <?php if($titelURL && vis_can_see($profileVis['titelbild']??'private',$audKey)): ?>
                <div class="top-title" style="margin:-8px -8px 16px -8px;">
                  <img src="<?= htmlspecialchars($titelURL) ?>" alt="Titelbild" style="width:100%;max-height:<?= (int)$tbMaxH ?>px;object-fit:cover">
                </div>
              <?php endif; ?>
              <div class="summary">
                <div class="left">
                  <?php if($profilURL && vis_can_see($profileVis['profilbild']??'private',$audKey)): ?>
                    <img src="<?= htmlspecialchars($profilURL) ?>" alt="Profilbild" style="max-width:<?= (int)$pbMaxW ?>px;border-radius:50%">
                  <?php endif; ?>
                </div>
                <div>
                  <?php
                    $show = $preview; // bereits zusammengesetzt
                    foreach($FIELDS_PREVIEW as $k=>$label):
                      if(!vis_can_see($profileVis[$k]??'private',$audKey)) continue;
                      $val = trim((string)($show[$k]??'')); if($val==='') continue; ?>
                      <div class="kv"><div class="key"><strong><?= htmlspecialchars($label) ?></strong></div><div class="val"><?= nl2br(htmlspecialchars($val)) ?></div></div>
                  <?php endforeach; ?>
                </div>
                <div class="right">
                  <?php if($logoURL && vis_can_see($profileVis['firmenlogo']??'private',$audKey)): ?>
                    <img src="<?= htmlspecialchars($logoURL) ?>" alt="Firmenlogo" style="max-width:<?= (int)$lgMaxW ?>px">
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- EXTERNES JS (Icon-Toggles etc.) -->
<script defer src="<?= htmlspecialchars($PREFIX) ?>assets/js/benutzer_edit.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
