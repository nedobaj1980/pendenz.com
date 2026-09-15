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
function nk_calculate_statement(array $bookings,array $units,int $daysInPeriod,float $flatRate=0.20): array {
    $tenantTotal=0.0; $owner=['unterhalt'=>0.0,'investition'=>0.0,'verwaltung'=>0.0,'finanzierung'=>0.0,'privat'=>0.0,'unbekannt'=>0.0]; $warnings=[]; $byUnit=[];
    foreach($bookings as $b){$amt=abs((float)$b['betrag']); $tax=$b['steuerklasse']??'unbekannt'; $owner[$tax]=($owner[$tax]??0)+$amt; if(!empty($b['tenant_allocable'])){$r=nk_allocate($amt,$b['verteilerschluessel']??'area',$units); if($r['warning'])$warnings[]=$r['warning']; foreach($r['allocations'] as $id=>$v){$byUnit[$id]=($byUnit[$id]??0)+$v;} $tenantTotal+=$amt;}}
    $ownerEffective=$owner['unterhalt']+$owner['verwaltung']; $ownerFlat=round($tenantTotal*$flatRate,2);
    return ['tenant_total'=>round($tenantTotal,2),'by_unit'=>$byUnit,'owner'=>$owner,'owner_effective'=>round($ownerEffective,2),'owner_flat'=> $ownerFlat,'owner_recommended'=>min($ownerEffective,$ownerFlat),'warnings'=>array_values(array_unique($warnings))];
}
function nk_load_bookings(mysqli $db,int $projectId,int $year): array {
    $from=$year.'-01-01'; $to=$year.'-12-31'; $st=$db->prepare("SELECT id,betrag,beschreibung,kategorie FROM liegenschafts_konto WHERE (liegenschaft_id=? OR projekt_id=?) AND buchungsdatum BETWEEN ? AND ? AND betrag<0 ORDER BY buchungsdatum,id"); $st->bind_param('iiss',$projectId,$projectId,$from,$to); $st->execute(); $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    foreach($rows as &$r){$r['tenant_allocable']=stripos((string)$r['kategorie'],'Betrieb')!==false || stripos((string)$r['kategorie'],'Nebenkosten')!==false; $r['verteilerschluessel']='area'; $r['steuerklasse']=$r['tenant_allocable']?'unbekannt':'unterhalt';} return $rows;
}
