<?php
require __DIR__ . '/../tools/nebenkostenabrechnung/lib.php';
$groups = [['name'=>'Hauswartung','tenant_allocable'=>1,'tax_class'=>'unterhalt','distribution_key'=>'area']];
$r = nk_match_group('Hauswartung Reinigung', $groups);
if (!$r || $r['distribution_key'] !== 'area' || !$r['tenant_allocable']) { fwrite(STDERR, "group match failed\n"); exit(1); }
if (nk_match_group('Unbekannt', $groups) !== null) { fwrite(STDERR, "unexpected group match\n"); exit(1); }
echo "OK\n";
