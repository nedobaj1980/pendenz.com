<?php
declare(strict_types=1);

if (!function_exists('pendenz_status_aliases')) {
    function pendenz_status_aliases(): array {
        return [
            'offen'=>'offen', 'open'=>'offen', 'todo'=>'offen',
            'in bearbeitung'=>'in_bearbeitung', 'in_bearbeitung'=>'in_bearbeitung', 'in_arbeit'=>'in_bearbeitung',
            'wartend'=>'wartend', 'zugewiesen'=>'zugewiesen', 'eingereicht'=>'eingereicht',
            'in_pruefung'=>'in_pruefung', 'freigegeben'=>'freigegeben', 'nacharbeit'=>'nacharbeit',
            'abgelehnt'=>'abgelehnt', 'unt. erledigt.'=>'unternehmer_erledigt', 'unternehmer_erledigt'=>'unternehmer_erledigt',
            'erledigt'=>'erledigt', 'completed'=>'erledigt', 'archiviert'=>'archiviert'
        ];
    }
}
if (!function_exists('pendenz_normalize_status')) {
    function pendenz_normalize_status(?string $status): string {
        $key = mb_strtolower(trim((string)$status));
        return pendenz_status_aliases()[$key] ?? 'offen';
    }
}
if (!function_exists('pendenz_status_is_valid')) {
    function pendenz_status_is_valid(?string $status): bool {
        $key = mb_strtolower(trim((string)$status));
        return $key !== '' && array_key_exists($key, pendenz_status_aliases());
    }
}
if (!function_exists('pendenz_location_is_consistent')) {
    function pendenz_location_is_consistent(array $location): bool {
        foreach ([['projekt_id','objekt_projekt_id'], ['objekt_id','wohnung_objekt_id'], ['wohnung_id','wohnung_objekt_id'], ['raum_id','raum_wohnung_id']] as [$child,$parent]) {
            if (!empty($location[$child]) && array_key_exists($parent,$location) && empty($location[$parent])) return false;
            if (!empty($location[$child]) && !empty($location[$parent]) && (int)$location[$child] !== (int)$location[$parent]) return false;
        }
        return true;
    }
}
