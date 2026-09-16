<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pendenz_domain.php';
require_once __DIR__ . '/../includes/property_scope.php';

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

check(pendenz_normalize_status('in Bearbeitung') === 'in_bearbeitung', 'status aliases normalize');
check(pendenz_normalize_status('') === 'offen', 'empty status defaults to offen');
check(pendenz_status_is_valid('wartend'), 'wartend is valid');
check(!pendenz_status_is_valid('bogus'), 'unknown status rejected');

check(pendenz_location_is_consistent(['projekt_id'=>3,'objekt_projekt_id'=>3,'wohnung_objekt_id'=>9,'raum_wohnung_id'=>10]), 'consistent hierarchy accepted');
check(!pendenz_location_is_consistent(['projekt_id'=>3,'objekt_projekt_id'=>4]), 'project/object mismatch rejected');
check(!pendenz_location_is_consistent(['wohnung_id'=>10,'wohnung_objekt_id'=>null]), 'missing apartment relation rejected');

check(property_scope_type('Treppenhaus') === 'common', 'common room classified');
check(property_scope_type('Parkplätze') === 'parking', 'parking classified');
check(property_scope_type('Wohnung 201') === 'residential', 'residential unit classified');
check(property_scope_is_tenant_unit('Gewerbe EG - Tres Chic'), 'commercial unit is tenant-relevant');
check(!property_scope_is_tenant_unit('Umgebung'), 'common area excluded from tenant logic');

echo "OK\n";
