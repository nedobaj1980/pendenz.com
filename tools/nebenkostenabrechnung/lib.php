<?php
declare(strict_types=1);

function nk_overlap_days(string $from, string $to, string $periodFrom, string $periodTo): int {
    $a=max(strtotime($from),strtotime($periodFrom)); $b=min(strtotime($to),strtotime($periodTo));
    return $b<$a ? 0 : (int)floor(($b-$a)/86400)+1;
}
function nk_allocate(float $total, string $key, array $units): array {
    $den=0.0; foreach($units as $u){$den += max(0.0,(float)($u[$key]??0));}
    if($den<=0) return ['allocations'=>[], 'warning'=>"Kein gültiger Verteilerschlüssel '$key'."];
    $out=[]; foreach($units as $u){$share=max(0.0,(float)($u[$key]??0))/$den; $out[(int)$u['wohnung_id']]=round($total*$share,2);} return ['allocations'=>$out,'warning'=>null];
}
function nk_calculate_statement(array $bookings, array $units, int $daysInPeriod, float $flatRate=0.20): array {
    $tenantTotal=0.0; $owner=['unterhalt'=>0.0,'investition'=>0.0,'verwaltung'=>0.0,'finanzierung'=>0.0,'privat'=>0.0,'unbekannt'=>0.0]; $warnings=[]; $byUnit=[];
    foreach($bookings as $b){$amt=abs((float)$b['betrag']); $tax=$b['steuerklasse']??'unbekannt'; $owner[$tax]=($owner[$tax]??0)+$amt; if(!empty($b['tenant_allocable'])){$r=nk_allocate($amt,$b['verteilerschluessel']??'area',$units); if($r['warning'])$warnings[]=$r['warning']; foreach($r['allocations'] as $id=>$v){$byUnit[$id]=($byUnit[$id]??0)+$v;} $tenantTotal+=$amt;}}
    $ownerEffective=$owner['unterhalt']+$owner['verwaltung']; $ownerFlat=round($tenantTotal*$flatRate,2);
    
    $unitDetails = [];
    $totalAkonto = 0.0;
    foreach ($units as $u) {
        $uid = (int)($u['wohnung_id'] ?? 0);
        $cost = $byUnit[$uid] ?? 0.0;
        $monthlyAkonto = (float)($u['nk_akonto'] ?? 0.0);
        $start = !empty($u['startdatum']) ? $u['startdatum'] : '2000-01-01';
        $end = !empty($u['enddatum']) ? $u['enddatum'] : '2099-12-31';
        $pFrom = $u['period_from'] ?? date('Y') . '-01-01';
        $pTo = $u['period_to'] ?? date('Y') . '-12-31';

        $activeDays = !empty($u['mieter_name']) ? nk_overlap_days($start, $end, $pFrom, $pTo) : 0;
        $fraction = ($daysInPeriod > 0 && $activeDays > 0) ? ($activeDays / $daysInPeriod) : 0;
        $akontoPaid = round($monthlyAkonto * $fraction * 12, 2);
        $saldo = round($akontoPaid - $cost, 2);
        $totalAkonto += $akontoPaid;

        $unitDetails[$uid] = [
            'wohnung_id' => $uid,
            'wohnung_name' => $u['wohnung_name'] ?? ('Einheit #' . $uid),
            'mieter_name' => $u['mieter_name'] ?? null,
            'area' => (float)($u['area'] ?? 0),
            'active_days' => $activeDays,
            'monthly_akonto' => $monthlyAkonto,
            'cost' => $cost,
            'akonto_paid' => $akontoPaid,
            'saldo' => $saldo,
            'saldo_type' => $saldo >= 0 ? 'guthaben' : 'nachzahlung',
            'is_leerstand' => empty($u['mieter_name'])
        ];
    }

    $totalSaldo = round($totalAkonto - $tenantTotal, 2);

    return [
        'tenant_total' => round($tenantTotal,2),
        'tenant_akonto_total' => round($totalAkonto, 2),
        'tenant_saldo_total' => $totalSaldo,
        'by_unit' => $byUnit,
        'unit_details' => $unitDetails,
        'owner' => $owner,
        'owner_effective' => round($ownerEffective,2),
        'owner_flat' => $ownerFlat,
        'owner_recommended' => min($ownerEffective,$ownerFlat),
        'warnings' => array_values(array_unique($warnings))
    ];
}
function nk_match_group(string $text, array $groups): ?array {
    foreach ($groups as $group) {
        $name = trim((string)($group['name'] ?? ''));
        if ($name !== '' && stripos($text, $name) !== false) return $group;
    }
    return null;
}
function nk_load_groups(mysqli $db, int $projectId, string $tool='beide'): array {
    $st = $db->prepare("SELECT * FROM finance_groups WHERE active=1 AND (projekt_id IS NULL OR projekt_id=?) AND (tool=? OR tool='beide') ORDER BY projekt_id IS NOT NULL DESC, sort_order, name");
    if (!$st) return [];
    $st->bind_param('is', $projectId, $tool); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close(); return $rows;
}
function nk_load_bookings(mysqli $db,int $projectId,int $year): array {
    $from=$year.'-01-01'; $to=$year.'-12-31'; $st=$db->prepare("SELECT id,betrag,beschreibung,kategorie FROM liegenschafts_konto WHERE (liegenschaft_id=? OR projekt_id=?) AND buchungsdatum BETWEEN ? AND ? AND betrag<0 ORDER BY buchungsdatum,id"); $st->bind_param('iiss',$projectId,$projectId,$from,$to); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $groups=nk_load_groups($db,$projectId,'nebenkosten');
    foreach($rows as &$r){$g=nk_match_group((string)$r['kategorie'].' '.(string)$r['beschreibung'],$groups);$r['tenant_allocable']=$g?(bool)$g['tenant_allocable']:(stripos((string)$r['kategorie'],'Betrieb')!==false || stripos((string)$r['kategorie'],'Nebenkosten')!==false);$r['verteilerschluessel']=$g?($g['distribution_key']??'area'):'area';$r['steuerklasse']=$g?($g['tax_class']??'unbekannt'):($r['tenant_allocable']?'unbekannt':'unterhalt');} return $rows;
}
