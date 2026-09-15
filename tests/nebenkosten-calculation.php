<?php
require __DIR__.'/../tools/nebenkostenabrechnung/lib.php';
$a=nk_overlap_days('2026-01-15','2026-12-31','2026-01-01','2026-12-31'); if($a!==351) throw new Exception('overlap');
$r=nk_allocate(100,'area',[['wohnung_id'=>1,'area'=>50],['wohnung_id'=>2,'area'=>50]]); if($r['allocations'][1]!==50.0) throw new Exception('allocation');
$s=nk_calculate_statement([['betrag'=>-100,'tenant_allocable'=>true,'verteilerschluessel'=>'area','steuerklasse'=>'unbekannt'],['betrag'=>-40,'tenant_allocable'=>false,'steuerklasse'=>'unterhalt']],[['wohnung_id'=>1,'area'=>100,'units'=>1,'persons'=>1]],365); if($s['tenant_total']!==100.0||$s['owner_effective']!==40.0) throw new Exception('statement'); echo "OK\n";
