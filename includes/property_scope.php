<?php
declare(strict_types=1);

/** Classifies the existing wohnungen rows without changing the database schema. */
if (!function_exists('property_scope_type')) {
    function property_scope_type(?string $name): string {
        $n = mb_strtolower(trim((string)$name));
        if ($n === '') return 'other';
        if (preg_match('/treppenhaus|tiefgarage|umgebung|spielplatz|fahrgasse|allgemein|keller|technik|hauswartung/', $n)) return 'common';
        if (preg_match('/parkpl|garage|stellplatz|aussenplatz|pp\b/', $n)) return 'parking';
        if (preg_match('/gewerbe|büro|laden|atelier|praxis/', $n)) return 'commercial';
        if (preg_match('/\bwohnung\b|\bwhg\b|zi\.|zimmer|attika|w\-\d/', $n)) return 'residential';
        return 'other';
    }
}
if (!function_exists('property_scope_is_tenant_unit')) {
    function property_scope_is_tenant_unit(?string $name): bool {
        return in_array(property_scope_type($name), ['residential','commercial'], true);
    }
}
