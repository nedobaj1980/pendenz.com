<?php
declare(strict_types=1);
if(session_status()===PHP_SESSION_NONE){session_name('PENDENZ_SESSID');session_start();}
require_once __DIR__.'/../../config.php'; require_once __DIR__.'/../../includes/auth.php'; require_once __DIR__.'/../../includes/authz.php'; require_once __DIR__.'/../../includes/functions.php'; require_once __DIR__.'/../../includes/property_scope.php'; require_once __DIR__.'/bootstrap.php'; require_once __DIR__.'/lib.php';
require_login(); $role=$_SESSION['rolle']??'gast'; if(!in_array($role,['admin','superadmin'],true)){http_response_code(403);exit('Zugriff verweigert');}
nk_bootstrap($mysqli); $h=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$year=(int)($_GET['jahr']??date('Y')); $pid=(int)($_GET['projekt_id']??($_SESSION['current_project_id']??0)); $projects=$mysqli->query('SELECT id,nummer,name FROM projekte ORDER BY name')->fetch_all(MYSQLI_ASSOC); if(!$pid && $projects)$pid=(int)$projects[0]['id'];
$bookings=$pid?nk_load_bookings($mysqli,$pid,$year):[]; $units=[]; if($pid){$st=$mysqli->prepare('SELECT w.id AS wohnung_id, w.name AS wohnung_name, COALESCE(w.flaeche,0) area FROM wohnungen w JOIN objekte o ON o.id=w.objekt_id WHERE o.projekt_id=?'); if($st){$st->bind_param('i',$pid);$st->execute();$units=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();}} $units=array_values(array_filter($units,fn($u)=>property_scope_is_tenant_unit($u['wohnung_name']??'')));
foreach($units as &$u){$u['units']=1;$u['persons']=1;} unset($u); $result=nk_calculate_statement($bookings,$units,($year%4===0?366:365));
$unitsById = [];
foreach ($units as $u) {
    $unitsById[(int)$u['wohnung_id']] = $u['wohnung_name'];
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Nebenkostenabrechnung – <?=SITE_NAME?></title>
    <link rel="stylesheet" href="../../assets/css/style.css?v=20260915">
    <script src="../../assets/js/finance-tables.js?v=20260915.2" defer></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; background: #f8fafc; color: #0f172a; }
        .wrap { max-width: 1280px; margin: 24px auto; padding: 0 20px; }
        .nk-header-card { background: #fff; border-radius: 14px; padding: 24px; margin-bottom: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .nk-title { font-size: 24px; font-weight: 800; color: #1e293b; margin: 0 0 8px 0; display: flex; align-items: center; gap: 10px; }
        .nk-desc { color: #64748b; font-size: 14px; margin: 0 0 18px 0; }
        .nk-form { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }
        select, input[type="number"] { padding: 9px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; color: #0f172a; min-width: 180px; }
        .btn-calc { background: linear-gradient(135deg, #0f766e, #0d9488); color: #fff; font-weight: 700; padding: 10px 22px; border: none; border-radius: 8px; cursor: pointer; transition: opacity 0.2s; }
        .btn-calc:hover { opacity: 0.92; }
        .btn-link { display: inline-flex; align-items: center; gap: 6px; color: #4f46e5; text-decoration: none; font-size: 13px; font-weight: 600; padding: 8px 14px; background: #eef2ff; border-radius: 8px; }
        .btn-link:hover { background: #e0e7ff; }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .kpi-card { background: #fff; border-radius: 12px; padding: 18px 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .kpi-label { font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 6px; }
        .kpi-val { font-size: 22px; font-weight: 800; color: #0f172a; }
        .kpi-val.highlight { color: #0f766e; }
        .kpi-val.info { color: #3b82f6; }
        .card { background: #fff; border-radius: 14px; padding: 22px; margin-bottom: 20px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card h2 { font-size: 18px; font-weight: 700; margin: 0 0 16px 0; color: #1e293b; }
        .warn-card { background: #fffbeb; border: 1px solid #fef3c7; color: #92400e; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; }
        .warn-card strong { display: block; margin-bottom: 6px; }
        .warn-card ul { margin: 0; padding-left: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8fafc; padding: 12px 14px; border-bottom: 2px solid #e2e8f0; font-weight: 700; color: #475569; text-align: left; }
        td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
        tr:hover td { background: #f8fafc; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .actions-bar { display: flex; gap: 12px; align-items: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f1f5f9; }
        .actions-bar a { text-decoration: none; font-size: 13px; font-weight: 600; padding: 8px 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; }
        .btn-csv { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-pdf { background: #4f46e5; color: #fff; }
    </style>
</head>
<body>
<?php require __DIR__.'/../../includes/nav_dispatch.php'; ?>
<main class="wrap">
    <div class="nk-header-card">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="nk-title">📑 Schweizer Nebenkostenabrechnung</h1>
                <p class="nk-desc">Periodengerechte Heiz- und Nebenkostenverteilung sowie Steueroptimierung (Pauschal- vs. Effektivabzug).</p>
            </div>
            <a href="../finanzgruppen/index.php?projekt_id=<?=$pid?>" class="btn-link">🗂️ Finanzgruppen &amp; Schlüssel verwalten</a>
        </div>
        <form method="get" class="nk-form">
            <div class="form-group">
                <label>Liegenschaft / Projekt</label>
                <select name="projekt_id">
                    <?php foreach($projects as $p): ?>
                        <option value="<?=$p['id']?>" <?=$pid===(int)$p['id']?'selected':''?>><?=$h(($p['nummer']? $p['nummer'].' – ':'').$p['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Abrechnungsjahr</label>
                <input type="number" name="jahr" value="<?=$year?>" min="2000" max="2100">
            </div>
            <button type="submit" class="btn-calc">🔄 Neu berechnen</button>
        </form>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Umlagefähige Kosten (Mieter)</div>
            <div class="kpi-val highlight">CHF <?=number_format($result['tenant_total'],2,'.',"'")?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Effektiver Unterhalt / Steuerabzug</div>
            <div class="kpi-val">CHF <?=number_format($result['owner_effective'],2,'.',"'")?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Pauschalabzug (20% Ertrag)</div>
            <div class="kpi-val">CHF <?=number_format($result['owner_flat'],2,'.',"'")?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Empfohlener Steuerabzug</div>
            <div class="kpi-val info">CHF <?=number_format($result['owner_recommended'],2,'.',"'")?></div>
        </div>
    </div>

    <?php if($result['warnings']): ?>
        <div class="warn-card">
            <strong>⚠️ Prüfhinweise für diese Periode:</strong>
            <ul>
                <?php foreach($result['warnings'] as $w): ?>
                    <li><?=$h($w)?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>👥 Mieterabrechnung je Mieteinheit</h2>
        <table>
            <thead>
                <tr>
                    <th>Mietobjekt / Einheit</th>
                    <th class="num">Umlageanteil</th>
                    <th class="num">Geleistete Akontozahlung</th>
                    <th class="num">Abrechnungssaldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($result['by_unit'] as $uid=>$amt): 
                    $unitName = $unitsById[$uid] ?? ('Einheit #' . $uid);
                ?>
                <tr>
                    <td><strong><?=$h($unitName)?></strong></td>
                    <td class="num">CHF <?=number_format($amt,2,'.',"'")?></td>
                    <td class="num" style="color:#64748b;">—</td>
                    <td class="num" style="font-weight:700;">CHF <?=number_format($amt,2,'.',"'")?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($result['by_unit'])): ?>
                <tr>
                    <td colspan="4" style="text-align:center; color:#64748b; padding:24px;">Keine umlagefähigen Buchungen für das ausgewählte Jahr und Projekt gefunden.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>🏛️ Eigentümer- &amp; Steuerübersicht</h2>
        <table>
            <thead>
                <tr>
                    <th>Steuerklasse / Kostenart</th>
                    <th class="num">Betrag</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($result['owner'] as $k=>$v): ?>
                <tr>
                    <td><?=$h(ucfirst($k))?></td>
                    <td class="num">CHF <?=number_format($v,2,'.',"'")?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-top:16px; font-size:12px; color:#64748b;">
            💡 <em>Hinweis:</em> Steuerliche Behandlung nach kantonaler Praxis (Kanton SG / TG / ZH), Gebäudealter (unter/über 10 Jahre) und Privat- vs. Geschäftsvermögen.
        </div>

        <div class="actions-bar">
            <a href="export.php?projekt_id=<?=$pid?>&jahr=<?=$year?>&format=csv" class="btn-csv">📥 CSV-Export</a>
            <a href="export.php?projekt_id=<?=$pid?>&jahr=<?=$year?>&format=pdf" class="btn-pdf">📄 PDF-Abrechnung drucken</a>
        </div>
    </div>
</main>
</body>
</html>
