<?php
require_once '../config.php';
require_once '../includes/room_taxonomy.php';
require_once '../includes/vorgang_taxonomy.php';

$pid = $_GET['projekt_id'] ?? 0;
$uid = $_GET['unit_id'] ?? 0;

// Liegenschaft & Wohnung laden
$unit = $mysqli->query("SELECT w.*, p.name as p_name, o.name as obj_name FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id JOIN projekte p ON o.projekt_id = p.id WHERE w.id = $uid AND p.id = $pid")->fetch_assoc();
if (!$unit) {
    die("Wohnung nicht gefunden.");
}

// Aktuellen Mieter finden
$mieter = $mysqli->query("SELECT * FROM wohnung_mieter WHERE wohnung_id = $uid AND (status = 'aktiv' OR (startdatum <= CURDATE() AND (enddatum IS NULL OR enddatum >= CURDATE()))) LIMIT 1")->fetch_assoc();

// Alle Mieter dieser Wohnung (für Dropdown)
$allTenants = $mysqli->query("SELECT * FROM wohnung_mieter WHERE wohnung_id = $uid ORDER BY mieter_name ASC")->fetch_all(MYSQLI_ASSOC);

// Alle Benutzer (Vermieter/Verwalter)
$allUsers = $mysqli->query("SELECT id, name FROM benutzer ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Vorhandene Protokolle dieser Wohnung laden
$savedProtocols = $mysqli->query("SELECT id, erstellt_am, mieter_name_custom, daten_json, pdf_pfad FROM abnahme_protokolle WHERE wohnung_id = $uid AND daten_json LIKE '%wohnungsabnahme_protokoll%' ORDER BY erstellt_am DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
foreach ($savedProtocols as &$sp) {
    $spData = json_decode($sp['daten_json'], true);
    $sp['name_display'] = $spData['protokoll_bezeichnung'] ?? $sp['mieter_name_custom'] ?? 'Wohnungsabnahme';
}
unset($sp);

// Bestehendes Protokoll laden falls ID übergeben
$loadedData = null;
$loadedProtocolId = 0;
if (isset($_GET['protocol_id'])) {
    $loadedProtocolId = (int)$_GET['protocol_id'];
    $pRes = $mysqli->query("SELECT daten_json FROM abnahme_protokolle WHERE id = $loadedProtocolId AND wohnung_id = $uid");
    if ($pRow = $pRes->fetch_assoc()) {
        $loadedData = json_decode($pRow['daten_json'], true);
    }
}

// Navigation: Nächste / Vorherige Wohnung im gleichen Projekt
$allUnits = $mysqli->query("SELECT w.id FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE o.projekt_id = $pid ORDER BY w.name ASC, w.id ASC")->fetch_all(MYSQLI_ASSOC);
$prevUid = null;
$nextUid = null;
for ($i = 0; $i < count($allUnits); $i++) {
    if ($allUnits[$i]['id'] == $uid) {
        $prevUid = $allUnits[$i - 1]['id'] ?? null;
        $nextUid = $allUnits[$i + 1]['id'] ?? null;
        break;
    }
}

// Definition der 215 Positionen gruppiert nach Räumen aus dem mp_interaktiv PDF
$pdf_rooms = [
    'Küche' => [
        'fresh_paint_options' => true,
        'items' => [
            1 => 'Boden', 2 => 'Wände', 3 => 'Decke', 4 => 'Schränke oben', 5 => 'Schränke unten', 
            6 => 'Plättli', 7 => 'Türen', 8 => 'Schloss/Schlüssel', 9 => 'Fenster DV/IV', 10 => 'Rollläden', 
            11 => 'Gurten/Kurbeln', 12 => 'Vorhangbrett/-schienen', 13 => 'Heizkörper/-ventil', 
            14 => 'Backofen', '14a' => 'Backofenzubehör', 15 => 'Blech', 16 => 'Grill', 17 => 'Rost', 
            18 => 'Herd', 19 => 'Dunstabzugshaube', 20 => 'Schüttstein/Chromstahl', 21 => 'Batterie', 
            22 => 'Kühlschrank/Tiefkühler', 23 => 'Elektr./Schalter/Stecker', 24 => 'Geschirrspüler', 
            25 => 'Zusatz 25', 26 => 'Zusatz 26', 27 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Bad/Dusche/WC' => [
        'fresh_paint_options' => true,
        'items' => [
            28 => 'Boden', 29 => 'Plättli', 30 => 'Wände', 31 => 'Decke', 32 => 'Türen', 
            33 => 'Schloss/Schlüssel', 34 => 'Fenster', 35 => 'Lüftung', 36 => 'Rollläden', 
            37 => 'Gurten/Kurbeln', 38 => 'Elektr./Schalter/Stecker', 39 => 'Wanne/Dusche', 
            40 => 'Batterie', 41 => 'Brause/Schlauch', 42 => 'Badetuchstange', 43 => 'Seifenhalter/Schale', 
            44 => 'Klosett / Spülkasten / WC-Brille', 45 => 'Papierhalter', 46 => 'Lavabo', 
            47 => 'Batterie', 48 => 'Spiegel/-kasten', 49 => 'Zusatz 49', 50 => 'Tablare', 
            51 => 'Wandschränke', 52 => 'Heizkörper/-ventil', 53 => 'Zusatz 53', 54 => 'Zusatz 54', 
            55 => 'Zusatz 55', 56 => 'Zusatz 56', 57 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Separater Nassraum' => [
        'fresh_paint_options' => true,
        'items' => [
            58 => 'Boden', 59 => 'Plättli', 60 => 'Wände', 61 => 'Decke', 62 => 'Türen', 
            63 => 'Fenster', 64 => 'Klosett (Spülkasten / WC-Brille)', 65 => 'Papierhalter', 
            66 => 'Lavabo', 67 => 'Wanne/Dusche', 68 => 'Batterie', 69 => 'Brause/Schlauch', 
            70 => 'Badetuchstange', 71 => 'Seifenhalter/Schale', 72 => 'Spiegel/-kasten', 
            73 => 'Glashalter/Glas', 74 => 'Zusatz 74', 75 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Korridor' => [
        'fresh_paint_options' => true,
        'items' => [
            76 => 'Boden', 77 => 'Wände', 78 => 'Decke', 79 => 'Eingangstüre', 80 => 'Türen', 
            81 => 'Fenster', 82 => 'Wandschränke', 83 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Wohnzimmer' => [
        'fresh_paint_options' => true,
        'items' => [
            84 => 'Boden', 85 => 'Wände', 86 => 'Decke', 87 => 'Türe', 88 => 'Schloss/Schlüssel', 
            89 => 'Fenstertüren', 90 => 'Fenster DV/IV', 91 => 'Simse', 92 => 'Vorhangbrett', 
            93 => 'Rollläden', 94 => 'Gurten/Kurbel', 95 => 'Elektr./Schalter/Stecker', 
            96 => 'TV-/Telefonanschluss', 97 => 'Heizkörper/-ventil', 98 => 'Wandschränke', 
            99 => 'Balkon/Sitzplatz', 100 => 'Sonnenstoren', 101 => 'Gurten/Kurbeln', 
            102 => 'Zusatz 102', 103 => 'Zusatz 103', 104 => 'Zusatz 104', 
            105 => 'Zusatz 105', 106 => 'Zusatz 106', 107 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Schlafzimmer' => [
        'fresh_paint_options' => true,
        'items' => [
            108 => 'Boden', 109 => 'Wände', 110 => 'Decke', 111 => 'Türe', 112 => 'Schloss/Schlüssel', 
            113 => 'Fenstertüren', 114 => 'Fenster DV/IV', 115 => 'Simse', 116 => 'Rollläden', 
            117 => 'Gurten/Kurbeln', 118 => 'Elektr./Schalter/Stecker', 119 => 'Wandschränke', 
            120 => 'Heizkörper/-ventil', 121 => 'Zusatz 121', 122 => 'Zusatz 122', 
            123 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Zimmer 1' => [
        'fresh_paint_options' => true,
        'items' => [
            124 => 'Boden', 125 => 'Wände', 126 => 'Decke', 127 => 'Türe', 128 => 'Schloss/Schlüssel', 
            129 => 'Fenstertüren', 130 => 'Fenster DV/IV', 131 => 'Simse', 132 => 'Rollläden', 
            133 => 'Gurten/Kurbeln', 134 => 'Elektr./Schalter/Stecker', 135 => 'Wandschränke', 
            136 => 'Heizkörper/-ventil', 137 => 'Zusatz 137', 138 => 'Zusatz 138', 
            139 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Zimmer 2' => [
        'fresh_paint_options' => true,
        'items' => [
            140 => 'Boden', 141 => 'Wände', 142 => 'Decke', 143 => 'Türe', 144 => 'Schloss/Schlüssel', 
            145 => 'Fenstertüren', 146 => 'Fenster DV/IV', 147 => 'Simse', 148 => 'Rollläden', 
            149 => 'Gurten/Kurbeln', 150 => 'Elektr./Schalter/Stecker', 151 => 'Wandschränke', 
            152 => 'Heizkörper/-ventil', 153 => 'Zusatz 153', 154 => 'Zusatz 154', 
            155 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Zimmer 3' => [
        'fresh_paint_options' => true,
        'items' => [
            156 => 'Boden', 157 => 'Wände', 158 => 'Decke', 159 => 'Türe', 160 => 'Schloss/Schlüssel', 
            161 => 'Fenstertüren', 162 => 'Fenster DV/IV', 163 => 'Simse', 164 => 'Rollläden', 
            165 => 'Gurten/Kurbeln', 166 => 'Elektr./Schalter/Stecker', 167 => 'Wandschränke', 
            168 => 'Heizkörper/-ventil', 169 => 'Zusatz 169', 170 => 'Zusatz 170', 
            171 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Bastelraum' => [
        'fresh_paint_options' => true,
        'items' => [
            172 => 'Boden', 173 => 'Wände', 174 => 'Decke', 175 => 'Türe', 176 => 'Schloss/Schlüssel', 
            177 => 'Fenster DV/IV', 178 => 'Simse', 179 => 'Rollläden', 180 => 'Gurten/Kurbeln', 
            181 => 'Elektr./Schalter/Stecker', 182 => 'Wandschränke', 183 => 'Heizkörper/-ventil', 
            184 => 'Zusatz 184', 185 => 'Zusatz 185', 186 => 'Zusatz 186', 187 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Waschküche' => [
        'fresh_paint_options' => true,
        'items' => [
            188 => 'Boden', 189 => 'Wände', 190 => 'Decke', 191 => 'Türe', 192 => 'Schloss/Schlüssel', 
            193 => 'Fenster', 194 => 'Lüftung', 195 => 'Schlüssel (Anzahl)'
        ]
    ],
    'Diverses' => [
        'fresh_paint_options' => false,
        'items' => [
            196 => 'Sonnenstoren (Balkon)', 197 => 'Gurten (Balkon)', 198 => 'Hurde (Balkon)', 
            199 => 'Fenster (Balkon)', 200 => 'Schlüssel (Balkon, Anzahl)', 201 => 'Garage/Abstellplatz', 
            202 => 'Garage Schlüssel (Anzahl)', 203 => 'Keller', 204 => 'Estrich', 
            205 => 'Keller/Estrich Schlüssel (Anzahl)', 206 => 'Brief-/Milchkasten Schlüssel (Anzahl)', 
            207 => 'Küche (Übrige Schlüssel)', 208 => 'Bad/Dusche/WC (Übrige Schlüssel)', 
            209 => 'Separates WC (Übrige Schlüssel)', 210 => 'Korridor (Übrige Schlüssel)', 
            211 => 'Haustür Schlüssel (Anzahl)', 212 => 'Wohn.-Tür Schlüssel (Anzahl)', 
            213 => 'Weiteres 1', 214 => 'Weiteres 2', 215 => 'Weiteres 3'
        ]
    ]
];

$PAGE_TITLE = 'Wohnungsabnahme - ' . ($unit['name'] ?? '');
require_once '../includes/header.php';
require_once '../includes/nav_dispatch.php';
?>

<main class="page">
    <!-- Premium Interactive Styling -->
    <style>
        :root {
            --primary: #007a3d;
            --primary-dark: #005a2d;
            --primary-light: #f0fdf4;
            --primary-border: #bbf7d0;
            --secondary: #3b82f6;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --bg-body: #f3f4f6;
            --card-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 5px -1px rgba(0, 0, 0, 0.03);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .wa-container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 30px 20px;
            background-color: var(--bg-body);
            min-height: 100vh;
            font-family: 'Outfit', 'Inter', -apple-system, sans-serif;
            color: var(--text-dark);
        }

        .premium-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(229, 231, 235, 0.7);
            padding: 30px;
            margin-bottom: 30px;
            transition: var(--transition-smooth);
        }

        .premium-card:hover {
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.07);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid var(--primary);
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            margin: 0;
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }

        .nav-buttons {
            display: flex;
            gap: 12px;
        }

        .nav-btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition-smooth);
            cursor: pointer;
            border: none;
        }

        .nav-btn-prev {
            background: #e5e7eb;
            color: #4b5563;
        }

        .nav-btn-prev:hover {
            background: #d1d5db;
        }

        .nav-btn-next {
            background: var(--primary);
            color: #ffffff;
        }

        .nav-btn-next:hover {
            background: var(--primary-dark);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            background: var(--primary-light);
            padding: 24px;
            border-radius: 12px;
            border: 1px solid var(--primary-border);
            margin-bottom: 30px;
        }

        .meta-box label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--primary);
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 6px;
        }

        .meta-box input, .meta-box select {
            width: 100%;
            border: 1px solid #d1d5db;
            background: #ffffff;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            transition: var(--transition-smooth);
        }

        .meta-box input:focus, .meta-box select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 122, 61, 0.15);
        }

        /* Room Grid checklist */
        /* Room Grid checklist (Full width room-by-room) */
        .rooms-grid {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .room-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            transition: var(--transition-smooth);
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
        }

        .room-card:hover {
            border-color: var(--primary-border);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
        }

        .room-header {
            background: #f9fafb;
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .room-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .room-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .paint-toggle {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px 20px;
            background: #f3f4f6;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
            margin: 10px 20px 0 20px;
        }

        .paint-toggle label {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }

        .room-body {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 12px;
            flex-grow: 1;
        }

        @media (min-width: 768px) {
            .room-body {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 1200px) {
            .room-body {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        /* Checkbox Switch & Container styling */
        .check-item-container {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            transition: var(--transition-smooth);
            background: #ffffff;
            height: fit-content;
        }
        
        .check-item-container.has-active-defect {
            grid-column: 1 / -1;
        }
        
        .check-item-container:hover {
            border-color: #cbd5e1;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            background: #f9fafb;
            cursor: pointer;
            user-select: none;
            transition: var(--transition-smooth);
            border: none;
        }

        .check-item:hover {
            background: #f1f5f9;
        }

        .check-item.has-defect {
            background: #fef2f2;
            color: #b91c1c;
        }

        .check-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #dc2626;
            cursor: pointer;
        }

        .check-item span {
            font-size: 14px;
            font-weight: 700;
        }

        .pos-num {
            font-size: 12px;
            font-weight: 800;
            color: var(--text-muted);
            min-width: 24px;
        }

        .check-item.has-defect .pos-num {
            color: #ef4444;
        }

        .inline-defect-panel {
            padding: 20px;
            background: #fffdfd;
            border-top: 1px solid #fee2e2;
            animation: slideDown 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mängelliste Dynamic Table */
        .mangel-section-title {
            font-size: 20px;
            font-weight: 800;
            color: #b91c1c;
            border-bottom: 2px solid #fca5a5;
            padding-bottom: 10px;
            margin-bottom: 20px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .mangel-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .mangel-table th {
            background: #fef2f2;
            color: #991b1b;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            padding: 12px;
            border: 1px solid #fee2e2;
            text-align: left;
        }

        .mangel-table td {
            padding: 16px 12px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }

        .mangel-row {
            background: #ffffff;
            transition: var(--transition-smooth);
        }

        .mangel-row:hover {
            background: #fff8f8;
        }

        .resp-selector {
            display: flex;
            gap: 5px;
        }

        .resp-selector label {
            flex: 1;
            padding: 6px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-smooth);
            background: #ffffff;
        }

        .resp-selector input[type="radio"] {
            display: none;
        }

        .resp-selector input[type="radio"]:checked + label.lbl-vermieter {
            background: #d1fae5;
            color: #065f46;
            border-color: #34d399;
        }

        .resp-selector input[type="radio"]:checked + label.lbl-mieter {
            background: #fef3c7;
            color: #92400e;
            border-color: #fbbf24;
        }

        /* Photo Upload UI */
        .photo-upload-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-photo-upload {
            background: #e0f2fe;
            color: #0369a1;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #bae6fd;
            transition: var(--transition-smooth);
        }

        .btn-photo-upload:hover {
            background: #bae6fd;
        }

        .img-preview {
            width: 50px;
            height: 38px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            display: none;
        }

        /* Signatures Layout */
        .signatures-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .sig-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            background: #f9fafb;
        }

        .sig-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .sig-header label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .sig-clear-btn {
            background: none;
            border: none;
            color: #ef4444;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
        }

        .sig-canvas {
            width: 100%;
            height: 120px;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            cursor: crosshair;
        }

        .main-save-btn {
            background: var(--primary);
            color: #ffffff;
            font-size: 18px;
            font-weight: 800;
            padding: 18px 30px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(0, 122, 61, 0.3);
        }

        .main-save-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 122, 61, 0.4);
        }

        .action-grid-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .btn-sec-save {
            background: #4b5563;
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: var(--transition-smooth);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-sec-save:hover {
            background: #374151;
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e293b;
            color: #ffffff;
            padding: 12px 24px;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            font-weight: 600;
            font-size: 14px;
            z-index: 9999;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .toast-notification.show {
            transform: translateY(0);
            opacity: 1;
        }
    </style>

    <div class="wa-container">
        
        <!-- Header Section -->
        <div class="premium-card page-header">
            <div>
                <div style="font-size: 11px; font-weight: 800; color: var(--primary); margin-bottom: 5px; letter-spacing: 0.5px;">WOHNUNGSABNAHMEPROTOKOL - MP 2016</div>
                <h1><?= htmlspecialchars($unit['name']) ?></h1>
                <div style="font-size: 14px; color: var(--text-muted); font-weight: 600; margin-top: 5px;">
                    Liegenschaft: <?= htmlspecialchars($unit['p_name']) ?> (<?= htmlspecialchars($unit['obj_name']) ?>)
                </div>
            </div>
            <div class="nav-buttons">
                <a href="<?= $prevUid ? "?projekt_id=$pid&unit_id=$prevUid" : '#' ?>" class="nav-btn nav-btn-prev <?= !$prevUid ? 'disabled' : '' ?>">
                    <i class="fas fa-chevron-left"></i> Vorherige
                </a>
                <a href="<?= $nextUid ? "?projekt_id=$pid&unit_id=$nextUid" : '#' ?>" class="nav-btn nav-btn-next <?= !$nextUid ? 'disabled' : '' ?>">
                    Nächste <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>

        <!-- Saved Protocols Dropdown -->
        <?php if (!empty($savedProtocols)): ?>
            <div class="premium-card" style="padding: 24px; border: 1px solid rgba(0, 122, 61, 0.15); background: linear-gradient(180deg, #ffffff 0%, #fcfdfd 100%);">
                <label style="font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; display: block; margin-bottom: 16px; letter-spacing: 0.5px;">
                    <i class="fas fa-history" style="margin-right: 6px;"></i> Bereits gespeicherte Wohnungsabnahmen (Zum Laden &amp; Weiterarbeiten anklicken)
                </label>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 15px;">
                    <?php foreach ($savedProtocols as $sp): ?>
                        <div onclick="window.location.href='?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=<?= $sp['id'] ?>'" 
                             style="cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; padding: 16px; background: #ffffff; border-radius: 12px; border: 1px solid #e5e7eb; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02); position: relative; gap: 12px;"
                             onmouseover="this.style.borderColor='var(--primary)'; this.style.boxShadow='0 8px 16px -4px rgba(0,0,0,0.06)'; this.style.transform='translateY(-2px)';" 
                             onmouseout="this.style.borderColor='#e5e7eb'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.02)'; this.style.transform='translateY(0)';"
                             title="Protokoll öffnen">
                            <div>
                                <div style="font-weight: 800; font-size: 14px; color: var(--text-dark); display: flex; align-items: center; gap: 8px; line-height: 1.3;">
                                    <i class="fas fa-file-alt" style="color: var(--primary); font-size: 15px;"></i>
                                    <?= htmlspecialchars($sp['name_display']) ?>
                                </div>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 6px; font-weight: 600; display: flex; align-items: center; gap: 5px;">
                                    <i class="far fa-calendar-alt"></i> <?= date('d.m.Y, H:i', strtotime($sp['erstellt_am'])) ?> Uhr
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px; margin-top: 5px;" onclick="event.stopPropagation();">
                                <a href="?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>&protocol_id=<?= $sp['id'] ?>" 
                                   class="nav-btn" 
                                   style="padding: 6px 12px; font-size: 11px; font-weight: 800; background: var(--primary-light); color: var(--primary); border: 1px solid var(--primary-border); border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: var(--transition-smooth);"
                                   onmouseover="this.style.background='var(--primary)'; this.style.color='#ffffff';"
                                   onmouseout="this.style.background='var(--primary-light)'; this.style.color='var(--primary)';">
                                    <i class="fas fa-folder-open"></i> Öffnen
                                </a>
                                <?php if ($sp['pdf_pfad']): ?>
                                    <a href="../uploads/protocols/<?= htmlspecialchars($sp['pdf_pfad']) ?>" 
                                       target="_blank" 
                                       class="nav-btn" 
                                       style="padding: 6px 12px; font-size: 11px; font-weight: 800; background: #fff1f2; color: #e11d48; border: 1px solid #ffe4e6; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; transition: var(--transition-smooth);"
                                       onmouseover="this.style.background='#e11d48'; this.style.color='#ffffff';"
                                       onmouseout="this.style.background='#fff1f2'; this.style.color='#e11d48';">
                                        <i class="fas fa-file-pdf"></i> PDF
                                    </a>
                                <?php endif; ?>
                                <a href="javascript:void(0)" 
                                   onclick="deleteProtocol(<?= $sp['id'] ?>)" 
                                   class="nav-btn" 
                                   style="padding: 6px 10px; font-size: 11px; font-weight: 800; background: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: var(--transition-smooth); margin-left: auto;"
                                   onmouseover="this.style.background='#ef4444'; this.style.color='#ffffff'; this.style.borderColor='#fca5a5';"
                                   onmouseout="this.style.background='#f3f4f6'; this.style.color='#4b5563'; this.style.borderColor='#e5e7eb';"
                                   title="Protokoll löschen">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- MAIN PROTOCOL FORM -->
        <form id="abnahmeForm">
            <!-- Form State / IDs -->
            <input type="hidden" name="protocol_id" value="<?= $loadedProtocolId ?>">
            <input type="hidden" name="projekt_id" value="<?= $pid ?>">
            <input type="hidden" name="unit_id" value="<?= $uid ?>">

            <!-- Meta Data / Header Information matching PDF Page 2 -->
            <div class="premium-card">
                <div class="mangel-section-title" style="color: var(--primary); border-bottom-color: var(--primary-border);">
                    <i class="fas fa-file-invoice"></i> 1. Protokoll-Stammdaten &amp; Zählerstände
                </div>
                
                <div class="meta-grid">
                    <div class="meta-box">
                        <label>Vermieter / Verwaltung</label>
                        <select name="user_id">
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= (isset($loadedData['user_id']) && $loadedData['user_id'] == $u['id']) || ($_SESSION['user_id'] == $u['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="meta-box">
                        <label>Ein- bzw. ausziehende(r) MieterIn</label>
                        <select name="mieter_id" id="mieter_picker" onchange="toggleCustomTenantInput(this)">
                            <option value="0">-- Mieter wählen / Manuelle Eingabe --</option>
                            <optgroup label="Zugeordnete Wohnungsmieter">
                                <?php foreach ($allTenants as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= (isset($loadedData['mieter_id']) && $loadedData['mieter_id'] == $t['id']) || ($mieter['id'] == $t['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['mieter_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <option value="custom" <?= isset($loadedData['mieter_id']) && $loadedData['mieter_id'] === 'custom' ? 'selected' : '' ?>>Neuer Mieter (Handeingabe)...</option>
                        </select>
                        <input type="text" name="mieter_name_custom" id="mieter_custom" 
                               value="<?= htmlspecialchars($loadedData['mieter_name_custom'] ?? '') ?>"
                               style="display: <?= isset($loadedData['mieter_id']) && $loadedData['mieter_id'] === 'custom' ? 'block' : 'none' ?>; margin-top:8px;" 
                               placeholder="Mieter Name eingeben...">
                    </div>

                    <div class="meta-box">
                        <label>Mieter Vertretung / Bevollmächtigte(r)</label>
                        <input type="text" name="mieter_vertreter" value="<?= htmlspecialchars($loadedData['mieter_vertreter'] ?? '') ?>" placeholder="z.B. Name des Vertreters...">
                    </div>

                    <div class="meta-box">
                        <label>Letztes Protokoll erstellt am</label>
                        <input type="text" name="letzte_abnahme" value="<?= htmlspecialchars($loadedData['letzte_abnahme'] ?? '') ?>" placeholder="z.B. 01.05.2023 oder 'Keines'">
                    </div>
                </div>

                <!-- Zählerstände & Schlüssel Übergabe -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
                    <div>
                        <h4 style="margin: 0 0 10px 0; color: var(--primary); font-size: 14px; font-weight: 800; text-transform: uppercase;">Zählerstände</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="meta-box">
                                <label>Elektr. I (kWh)</label>
                                <input type="number" step="0.01" name="zaehler_strom_1" value="<?= htmlspecialchars($loadedData['zaehler_strom_1'] ?? '') ?>" placeholder="0.00">
                            </div>
                            <div class="meta-box">
                                <label>Elektr. II (kWh)</label>
                                <input type="number" step="0.01" name="zaehler_strom_2" value="<?= htmlspecialchars($loadedData['zaehler_strom_2'] ?? '') ?>" placeholder="0.00">
                            </div>
                            <div class="meta-box">
                                <label>Wasser (m³)</label>
                                <input type="number" step="0.01" name="zaehler_wasser" value="<?= htmlspecialchars($loadedData['zaehler_wasser'] ?? '') ?>" placeholder="0.00">
                            </div>
                            <div class="meta-box">
                                <label>Warmwasser (m³)</label>
                                <input type="number" step="0.01" name="zaehler_warmwasser" value="<?= htmlspecialchars($loadedData['zaehler_warmwasser'] ?? '') ?>" placeholder="0.00">
                            </div>
                            <div class="meta-box">
                                <label>Gas</label>
                                <input type="text" name="zaehler_gas" value="<?= htmlspecialchars($loadedData['zaehler_gas'] ?? '') ?>" placeholder="z.B. Stand oder -">
                            </div>
                            <div class="meta-box">
                                <label>Heizöl</label>
                                <input type="text" name="zaehler_heizoel" value="<?= htmlspecialchars($loadedData['zaehler_heizoel'] ?? '') ?>" placeholder="z.B. Stand oder -">
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 style="margin: 0 0 10px 0; color: var(--primary); font-size: 14px; font-weight: 800; text-transform: uppercase;">Schlüssel Anzahl</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="meta-box">
                                <label>Haustüre</label>
                                <input type="number" name="keys_house" value="<?= htmlspecialchars($loadedData['keys_house'] ?? '3') ?>">
                            </div>
                            <div class="meta-box">
                                <label>Wohnungstüre</label>
                                <input type="number" name="keys_apartment" value="<?= htmlspecialchars($loadedData['keys_apartment'] ?? '3') ?>">
                            </div>
                            <div class="meta-box">
                                <label>Briefkasten</label>
                                <input type="number" name="keys_mail" value="<?= htmlspecialchars($loadedData['keys_mail'] ?? '2') ?>">
                            </div>
                            <div class="meta-box">
                                <label>Keller / Estrich</label>
                                <input type="number" name="keys_cellar" value="<?= htmlspecialchars($loadedData['keys_cellar'] ?? '1') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CHECKLIST SYSTEM - 215 Positionen -->
            <div class="premium-card">
                <div class="mangel-section-title" style="color: var(--primary); border-bottom-color: var(--primary-border);">
                    <i class="fas fa-check-square"></i> 2. Abnahme Checkliste (215 Positionen)
                </div>
                <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 20px;">
                    Gehen Sie durch die Räume der Wohnung und haken Sie die entsprechenden Positionen ab. 
                    <strong>Ein gesetzter Haken signalisiert einen MANGEL.</strong> Beim Anklicken einer Checkbox wird die Position sofort in die Mängelliste am Ende der Seite eingefügt, wo Sie Bilder hinzufügen und Details festlegen können.
                </p>

                <div class="rooms-grid">
                    <?php foreach ($pdf_rooms as $roomName => $roomInfo): ?>
                        <div class="room-card" id="room_card_<?= md5($roomName) ?>">
                            <div class="room-header">
                                <h3><i class="fas fa-home"></i> <?= htmlspecialchars($roomName) ?></h3>
                                <div class="room-actions">
                                    <button type="button" class="nav-btn nav-btn-prev" style="padding: 4px 10px; font-size: 11px;" onclick="setAllOk('<?= addslashes($roomName) ?>')">
                                        <i class="fas fa-check-circle"></i> Alle OK
                                    </button>
                                </div>
                            </div>

                            <?php if ($roomInfo['fresh_paint_options']): ?>
                                <div class="paint-toggle">
                                    <span>Beim Einzug frisch gestrichen / neu:</span>
                                    <label>
                                        <input type="checkbox" name="paint_wände[<?= htmlspecialchars($roomName) ?>]" value="1" <?= isset($loadedData['paint_wände'][$roomName]) ? 'checked' : '' ?>> Wände
                                    </label>
                                    <label>
                                        <input type="checkbox" name="paint_holz[<?= htmlspecialchars($roomName) ?>]" value="1" <?= isset($loadedData['paint_holz'][$roomName]) ? 'checked' : '' ?>> Holzwerk
                                    </label>
                                    <label>
                                        <input type="checkbox" name="paint_boden[<?= htmlspecialchars($roomName) ?>]" value="1" <?= isset($loadedData['paint_boden'][$roomName]) ? 'checked' : '' ?>> Bodenbelag
                                    </label>
                                </div>
                            <?php endif; ?>

                            <div class="room-body">
                                <?php foreach ($roomInfo['items'] as $num => $itemName): 
                                    $chkName = "chk_" . $num;
                                    $isDefect = isset($loadedData['checkboxes']) && in_array((string)$num, $loadedData['checkboxes']);
                                    $loadedDef = $loadedData['defects'][$num] ?? null;
                                    ?>
                                    <div class="check-item-container <?= $isDefect ? 'has-active-defect' : '' ?>" id="item_container_<?= $num ?>" style="width: 100%;">
                                        <div class="check-item <?= $isDefect ? 'has-defect' : '' ?>" 
                                             id="item_box_<?= $num ?>" 
                                             onclick="toggleCheck('<?= $num ?>', '<?= addslashes($roomName) ?>', '<?= addslashes($itemName) ?>')">
                                            <span class="pos-num"><?= $num ?>.</span>
                                            <input type="checkbox" 
                                                   id="chk_<?= $num ?>" 
                                                   value="<?= $num ?>" 
                                                   <?= $isDefect ? 'checked' : '' ?>
                                                   onclick="event.stopPropagation(); handleCheckboxChange('<?= $num ?>', '<?= addslashes($roomName) ?>', '<?= addslashes($itemName) ?>')">
                                            <span><?= htmlspecialchars($itemName) ?></span>
                                        </div>
                                        
                                        <div class="inline-defect-panel" id="defect_panel_<?= $num ?>" style="display: <?= $isDefect ? 'block' : 'none' ?>;">
                                            <input type="hidden" name="defects[<?= $num ?>][room]" value="<?= htmlspecialchars($roomName) ?>" <?= !$isDefect ? 'disabled' : '' ?>>
                                            <input type="hidden" name="defects[<?= $num ?>][item]" value="<?= htmlspecialchars($itemName) ?>" <?= !$isDefect ? 'disabled' : '' ?>>
                                            <input type="hidden" name="defects[<?= $num ?>][pendenz_id]" value="<?= htmlspecialchars($loadedDef['pendenz_id'] ?? '0') ?>" <?= !$isDefect ? 'disabled' : '' ?>>
                                            <input type="hidden" name="defects[<?= $num ?>][photo]" id="input_photo_<?= $num ?>" value="<?= htmlspecialchars($loadedDef['photo'] ?? '') ?>" <?= !$isDefect ? 'disabled' : '' ?>>

                                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                                                <div class="meta-box">
                                                    <label style="font-size: 10px; font-weight: 800; color: #b91c1c; text-transform: uppercase;">Behebung durch</label>
                                                    <div class="resp-selector" style="margin-top: 5px;">
                                                        <input type="radio" id="resp_v_<?= $num ?>" name="defects[<?= $num ?>][behebung_durch]" value="vermieter" <?= ($loadedDef['behebung_durch'] ?? 'vermieter') === 'vermieter' ? 'checked' : '' ?> <?= !$isDefect ? 'disabled' : '' ?> onchange="updateDefectField('<?= $num ?>', 'behebung_durch', 'vermieter')">
                                                        <label for="resp_v_<?= $num ?>" class="lbl-vermieter" style="padding: 8px;">Vermieter</label>
                                                        
                                                        <input type="radio" id="resp_m_<?= $num ?>" name="defects[<?= $num ?>][behebung_durch]" value="mieter" <?= ($loadedDef['behebung_durch'] ?? '') === 'mieter' ? 'checked' : '' ?> <?= !$isDefect ? 'disabled' : '' ?> onchange="updateDefectField('<?= $num ?>', 'behebung_durch', 'mieter')">
                                                        <label for="resp_m_<?= $num ?>" class="lbl-mieter" style="padding: 8px;">Mieter</label>
                                                    </div>
                                                </div>

                                                <div class="meta-box">
                                                    <label style="font-size: 10px; font-weight: 800; color: #b91c1c; text-transform: uppercase;">Erledigt bis</label>
                                                    <input type="date" name="defects[<?= $num ?>][bis_datum]" value="<?= htmlspecialchars($loadedDef['bis_datum'] ?? '') ?>" <?= !$isDefect ? 'disabled' : '' ?> onchange="updateDefectField('<?= $num ?>', 'bis_datum', this.value)" style="margin-top: 5px; padding: 6px 12px; font-size: 13px; font-weight: 600; width: 100%;">
                                                </div>

                                                <div class="meta-box">
                                                    <label style="font-size: 10px; font-weight: 800; color: #b91c1c; text-transform: uppercase;">Foto hinzufügen</label>
                                                    <div class="photo-upload-wrapper" style="margin-top: 5px; display: flex; align-items: center; gap: 10px;">
                                                        <img id="img_prev_<?= $num ?>" src="<?= !empty($loadedDef['photo']) ? '../uploads/protocols/' . htmlspecialchars($loadedDef['photo']) : '' ?>" class="img-preview" style="display: <?= !empty($loadedDef['photo']) ? 'block' : 'none' ?>; width: 60px; height: 45px; object-fit: cover; border-radius: 6px;">
                                                        <label class="btn-photo-upload" style="margin: 0; padding: 8px 12px;">
                                                            <i class="fas fa-camera"></i> Foto
                                                            <input type="file" accept="image/*" style="display: none;" onchange="uploadRowPhoto('<?= $num ?>', this)" <?= !$isDefect ? 'disabled' : '' ?>>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>

                                            <div style="margin-bottom: 15px;">
                                                <label style="font-size: 10px; font-weight: 800; color: #b91c1c; text-transform: uppercase; display: block; margin-bottom: 6px;">Mängelbeschreibung (Pos. <?= $num ?>)</label>
                                                <textarea id="desc_<?= $num ?>" name="defects[<?= $num ?>][beschreibung]" rows="2" <?= !$isDefect ? 'disabled' : '' ?> oninput="updateDefectField('<?= $num ?>', 'beschreibung', this.value)" style="width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 12px; font-size: 13px; font-family: inherit; resize: vertical;" placeholder="Genaue Mängelbeschreibung..."><?= htmlspecialchars($loadedDef['beschreibung'] ?? '') ?></textarea>
                                            </div>

                                            <div style="background: #f9fafb; padding: 12px 15px; border-radius: 8px; border: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                                                <label style="font-size: 12px; font-weight: 700; color: #4b5563; display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                                                    <input type="checkbox" name="defects[<?= $num ?>][as_pendenz]" value="1" <?= ($loadedDef['as_pendenz'] ?? '0') === '1' ? 'checked' : '' ?> onchange="updateDefectField('<?= $num ?>', 'as_pendenz', this.checked ? '1' : '0'); toggleTaskOptions('<?= $num ?>', this.checked)" <?= !$isDefect ? 'disabled' : '' ?> style="width: 16px; height: 16px;">
                                                    In Pendenzenliste erfassen
                                                </label>

                                                <div id="task_options_<?= $num ?>" style="display: <?= ($loadedDef['as_pendenz'] ?? '0') === '1' ? 'flex' : 'none' ?>; gap: 12px; align-items: center;">
                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                        <label style="font-size: 9px; font-weight: bold; color: var(--text-muted); text-transform: uppercase;">Zuständig</label>
                                                        <select name="defects[<?= $num ?>][responsible]" <?= !$isDefect ? 'disabled' : '' ?> onchange="updateDefectField('<?= $num ?>', 'responsible', this.value)" style="width: 150px; font-size: 11px; padding: 6px; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 600;">
                                                            <option value="">-- Wer erledigt? --</option>
                                                            <?php foreach ($allUsers as $u): ?>
                                                                <option value="<?= $u['id'] ?>" <?= ($loadedDef['responsible'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                        <label style="font-size: 9px; font-weight: bold; color: var(--text-muted); text-transform: uppercase;">Priorität</label>
                                                        <select name="defects[<?= $num ?>][priority]" <?= !$isDefect ? 'disabled' : '' ?> onchange="updateDefectField('<?= $num ?>', 'priority', this.value)" style="width: 100px; font-size: 11px; padding: 6px; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 600;">
                                                            <option value="1" <?= ($loadedDef['priority'] ?? '') == '1' ? 'selected' : '' ?>>Prio 1 (Hoch)</option>
                                                            <option value="2" <?= ($loadedDef['priority'] ?? '') == '2' ? 'selected' : '' ?>>Prio 2</option>
                                                            <option value="3" <?= ($loadedDef['priority'] ?? '3') == '3' ? 'selected' : '' ?>>Prio 3 (Mittel)</option>
                                                            <option value="4" <?= ($loadedDef['priority'] ?? '') == '4' ? 'selected' : '' ?>>Prio 4</option>
                                                            <option value="5" <?= ($loadedDef['priority'] ?? '') == '5' ? 'selected' : '' ?>>Prio 5 (Tief)</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- DYNAMIC MÄNGELLISTE TABLE -->
            <div class="premium-card" id="mängelliste_container" style="display: none;">
                <div class="mangel-section-title">
                    <i class="fas fa-exclamation-triangle"></i> 3. Mängelbehebung beim Ein- und Auszug
                </div>
                <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 15px;">
                    Die folgenden Zeilen entsprechen den oben ausgewählten Mängeln. Bitte füllen Sie die Behebungsinformationen und den genauen Beschrieb hier aus.
                </p>

                <div style="overflow-x: auto;">
                    <table class="mangel-table">
                        <thead>
                            <tr>
                                <th width="8%">Pos.</th>
                                <th width="20%">Raum / Bauteil</th>
                                <th width="18%">Behebung durch</th>
                                <th width="15%">Erledigt bis</th>
                                <th>Mängelbeschreibung / Kosten / Fotos / Pendenzen</th>
                            </tr>
                        </thead>
                        <tbody id="mangel_table_body">
                            <!-- Dynamically injected by JavaScript -->
                            <tr id="empty_mangel_row">
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px; font-weight: 600;">
                                    Keine Mängel ausgewählt. Aktivieren Sie Kontrollkästchen oben, um Mängel zu erfassen.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- FURTHER REMARKS AND BEMERKUNGEN -->
            <div class="premium-card">
                <div class="mangel-section-title" style="color: var(--primary); border-bottom-color: var(--primary-border);">
                    <i class="fas fa-pencil-alt"></i> 4. Weitere Vereinbarungen &amp; Bemerkungen
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="font-size: 12px; font-weight: bold; color: var(--text-dark); display: block; margin-bottom: 6px;">Weitere Vereinbarungen / Protokolleinträge</label>
                    <textarea name="weitere_bemerkungen" rows="4" style="width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 12px; font-size: 14px; font-family: inherit; resize: vertical;" placeholder="Hier können sonstige vertragliche Zusätze erfasst werden..."><?= htmlspecialchars($loadedData['weitere_bemerkungen'] ?? '') ?></textarea>
                </div>
                <div class="meta-box" style="max-width: 400px;">
                    <label>Heiz- und Nebenkostenabrechnung wird erstellt bis</label>
                    <input type="text" name="nebenkosten_bis" value="<?= htmlspecialchars($loadedData['nebenkosten_bis'] ?? '') ?>" placeholder="z.B. 31. Dezember oder ein Datum...">
                </div>
            </div>

            <!-- DIGITAL SIGNATURES -->
            <div class="premium-card">
                <div class="mangel-section-title" style="color: var(--primary); border-bottom-color: var(--primary-border);">
                    <i class="fas fa-signature"></i> 5. Digitale Unterschriften
                </div>
                <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 20px;">
                    Bitte unterzeichnen Sie direkt auf dem Touchscreen oder mit der Maus. Die Unterschriften werden im PDF eingebunden.
                </p>

                <div class="signatures-grid">
                    <div class="sig-card">
                        <div class="sig-header">
                            <label>Vermieter / Verwaltung</label>
                            <button type="button" class="sig-clear-btn" onclick="clearSig('sig_vermieter')">Löschen</button>
                        </div>
                        <canvas id="sig_vermieter" class="sig-canvas"></canvas>
                        <input type="hidden" name="signature_vermieter" id="input_sig_vermieter" value="<?= htmlspecialchars($loadedData['signature_vermieter'] ?? '') ?>">
                    </div>

                    <div class="sig-card">
                        <div class="sig-header">
                            <label>Mieter (ein- bzw. ausziehend)</label>
                            <button type="button" class="sig-clear-btn" onclick="clearSig('sig_mieter')">Löschen</button>
                        </div>
                        <canvas id="sig_mieter" class="sig-canvas"></canvas>
                        <input type="hidden" name="signature_mieter" id="input_sig_mieter" value="<?= htmlspecialchars($loadedData['signature_mieter'] ?? '') ?>">
                    </div>

                    <div class="sig-card">
                        <div class="sig-header">
                            <label>ExpertIn (optional)</label>
                            <button type="button" class="sig-clear-btn" onclick="clearSig('sig_experte')">Löschen</button>
                        </div>
                        <canvas id="sig_experte" class="sig-canvas"></canvas>
                        <input type="hidden" name="signature_experte" id="input_sig_experte" value="<?= htmlspecialchars($loadedData['signature_experte'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- SAVE AND PROCESS ACTIONS -->
            <div class="premium-card" style="background: #faf5ff; border: 1px dashed #d8b4fe;">
                <label style="display: block; margin-bottom: 8px; font-weight: 800; color: #6b21a8; font-size: 12px; text-transform: uppercase;">Protokoll Speichern &amp; PDF</label>
                <div style="margin-bottom: 20px;">
                    <input type="text" name="protokoll_bezeichnung" value="<?= htmlspecialchars($loadedData['protokoll_bezeichnung'] ?? '') ?>" placeholder="Optional: Bezeichnung für diese Abnahme (z.B. 'Einzug Mustername')" style="width: 100%; padding: 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 15px; font-weight: 600;">
                </div>

                <button type="button" class="main-save-btn" onclick="submitForm(false, false)">
                    <i class="fas fa-file-pdf"></i> PROTOKOLL ABSCHLIESSEN &amp; PDF GENERIEREN
                </button>

                <div class="action-grid-buttons">
                    <button type="button" class="btn-sec-save" onclick="syncPendenzenList()" style="background-color: var(--secondary);">
                        <i class="fas fa-sync-alt"></i> Pendenzen in Liste übertragen
                    </button>
                    <button type="button" class="btn-sec-save" onclick="submitForm(true, false)">
                        <i class="fas fa-save"></i> <?= $loadedProtocolId > 0 ? 'Entwurf aktualisieren' : 'Als Entwurf speichern' ?>
                    </button>
                    <button type="button" class="btn-sec-save" onclick="saveAsNewDraft()" style="background-color: #059669;">
                        <i class="fas fa-copy"></i> Speichern als... (neue Vorlage / Kopie)
                    </button>
                    <?php if ($loadedProtocolId > 0): ?>
                    <button type="button" class="btn-sec-save" onclick="deleteProtocol(<?= $loadedProtocolId ?>)" style="background-color: #dc2626;">
                        <i class="fas fa-trash-alt"></i> Diesen Entwurf löschen
                    </button>
                    <?php endif; ?>
                </div>
            </div>

        </form>

    </div>
</main>

<div class="toast-notification" id="toast_notification"></div>

<!-- JS Core Logic for Interactive Checklists and Signature Capture -->
<script>
    // State storage for defects populated from loaded data
    const activeDefects = {};
    const usersList = <?= json_encode($allUsers) ?>;
    
    // Initialise canvases for drawing signatures
    const sigCanvases = {};
    
    document.addEventListener("DOMContentLoaded", function() {
        initSignatures();
        
        // Load pre-existing defects from JSON if populated
        <?php if (!empty($loadedData['defects'])): ?>
            const initialDefects = <?= json_encode($loadedData['defects']) ?>;
            for (let num in initialDefects) {
                activeDefects[num] = initialDefects[num];
            }
        <?php endif; ?>
        
        // Listen to window resizes for canvases
        window.addEventListener('resize', resizeCanvases);
        
        // Auto-load signature images onto canvas if loaded
        loadExistingSignatures();
    });

    function toggleCustomTenantInput(select) {
        const customInput = document.getElementById("mieter_custom");
        if (select.value === "custom") {
            customInput.style.display = "block";
            customInput.focus();
        } else {
            customInput.style.display = "none";
        }
    }

    function initSignatures() {
        ['sig_vermieter', 'sig_mieter', 'sig_experte'].forEach(id => {
            const canvas = document.getElementById(id);
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            
            sigCanvases[id] = { canvas, ctx, drawing: false };
            
            // Set scale for high DPI displays
            const dpr = window.devicePixelRatio || 1;
            canvas.width = canvas.offsetWidth * dpr;
            canvas.height = canvas.offsetHeight * dpr;
            ctx.scale(dpr, dpr);
            
            ctx.strokeStyle = '#1e293b';
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            
            // Touch support
            canvas.addEventListener('touchstart', (e) => {
                e.preventDefault();
                const rect = canvas.getBoundingClientRect();
                const touch = e.touches[0];
                ctx.beginPath();
                ctx.moveTo(touch.clientX - rect.left, touch.clientY - rect.top);
                sigCanvases[id].drawing = true;
            });
            
            canvas.addEventListener('touchmove', (e) => {
                e.preventDefault();
                if (!sigCanvases[id].drawing) return;
                const rect = canvas.getBoundingClientRect();
                const touch = e.touches[0];
                ctx.lineTo(touch.clientX - rect.left, touch.clientY - rect.top);
                ctx.stroke();
            });
            
            canvas.addEventListener('touchend', () => {
                sigCanvases[id].drawing = false;
                saveSignatureBase64(id);
            });
            
            // Mouse support
            canvas.addEventListener('mousedown', (e) => {
                const rect = canvas.getBoundingClientRect();
                ctx.beginPath();
                ctx.moveTo(e.clientX - rect.left, e.clientY - rect.top);
                sigCanvases[id].drawing = true;
            });
            
            canvas.addEventListener('mousemove', (e) => {
                if (!sigCanvases[id].drawing) return;
                const rect = canvas.getBoundingClientRect();
                ctx.lineTo(e.clientX - rect.left, e.clientY - rect.top);
                ctx.stroke();
            });
            
            canvas.addEventListener('mouseup', () => {
                sigCanvases[id].drawing = false;
                saveSignatureBase64(id);
            });
        });
    }

    function resizeCanvases() {
        // Safe resize logic without losing drawings
        for (let id in sigCanvases) {
            const canvas = sigCanvases[id].canvas;
            const ctx = sigCanvases[id].ctx;
            const tempVal = document.getElementById("input_" + id).value;
            
            const dpr = window.devicePixelRatio || 1;
            canvas.width = canvas.offsetWidth * dpr;
            canvas.height = canvas.offsetHeight * dpr;
            ctx.scale(dpr, dpr);
            ctx.strokeStyle = '#1e293b';
            ctx.lineWidth = 2.5;
            ctx.lineCap = 'round';
            
            if (tempVal) {
                const img = new Image();
                img.src = tempVal;
                img.onload = () => {
                    ctx.drawImage(img, 0, 0, canvas.offsetWidth, canvas.offsetHeight);
                };
            }
        }
    }

    function loadExistingSignatures() {
        ['sig_vermieter', 'sig_mieter', 'sig_experte'].forEach(id => {
            let val = document.getElementById("input_" + id).value;
            if (val) {
                if (!val.startsWith('data:')) {
                    val = 'data:image/png;base64,' + val;
                }
                const canvas = document.getElementById(id);
                const ctx = canvas.getContext('2d');
                const img = new Image();
                img.src = val;
                img.onload = () => {
                    ctx.drawImage(img, 0, 0, canvas.offsetWidth, canvas.offsetHeight);
                };
            }
        });
    }

    function clearSig(id) {
        const canvas = document.getElementById(id);
        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById("input_" + id).value = "";
    }

    function saveSignatureBase64(id) {
        const canvas = document.getElementById(id);
        const dataUrl = canvas.toDataURL('image/png');
        document.getElementById("input_" + id).value = dataUrl;
    }

    // Toggle checklists
    function toggleCheck(num, roomName, itemName) {
        const chk = document.getElementById("chk_" + num);
        chk.checked = !chk.checked;
        handleCheckboxChange(num, roomName, itemName);
    }

    function handleCheckboxChange(num, roomName, itemName) {
        const itemBox = document.getElementById("item_box_" + num);
        const chk = document.getElementById("chk_" + num);
        const panel = document.getElementById("defect_panel_" + num);
        const container = document.getElementById("item_container_" + num);
        const inputs = panel.querySelectorAll("input, select, textarea");
        
        if (chk.checked) {
            itemBox.classList.add("has-defect");
            if (container) container.classList.add("has-active-defect");
            panel.style.display = "block";
            inputs.forEach(input => input.removeAttribute("disabled"));
            
            if (!activeDefects[num]) {
                activeDefects[num] = {
                    room: roomName,
                    item: itemName,
                    behebung_durch: 'vermieter',
                    bis_datum: '',
                    beschreibung: '',
                    photo: '',
                    as_pendenz: '0',
                    pendenz_id: '0',
                    priority: '3',
                    responsible: ''
                };
            }
        } else {
            // Check if there is already text written to avoid deleting
            const descArea = document.getElementById("desc_" + num);
            if (descArea && descArea.value.trim() !== '') {
                if (!confirm(`Die Beschreibung für Position ${num} ist nicht leer. Wollen Sie diesen Mangel wirklich löschen?`)) {
                    chk.checked = true;
                    return;
                }
            }
            
            itemBox.classList.remove("has-defect");
            if (container) container.classList.remove("has-active-defect");
            panel.style.display = "none";
            inputs.forEach(input => input.setAttribute("disabled", "disabled"));
            delete activeDefects[num];
        }
    }

    function setAllOk(roomName) {
        // Select all items belonging to this room card
        const card = document.getElementById("room_card_" + md5(roomName));
        if (!card) return;
        const checkboxes = card.querySelectorAll("input[type='checkbox']");
        
        let confirmNeeded = false;
        checkboxes.forEach(chk => {
            const num = chk.value;
            const descArea = document.getElementById("desc_" + num);
            if (chk.checked && descArea && descArea.value.trim() !== '') {
                confirmNeeded = true;
            }
        });
        
        if (confirmNeeded) {
            if (!confirm(`Einige erfasste Mängel in '${roomName}' enthalten Beschreibungen. Wollen Sie wirklich alle Positionen auf 'OK' setzen und diese Mängel löschen?`)) {
                return;
            }
        }

        checkboxes.forEach(chk => {
            const num = chk.value;
            if (chk.checked) {
                chk.checked = false;
                const itemBox = document.getElementById("item_box_" + num);
                if (itemBox) itemBox.classList.remove("has-defect");
                
                const container = document.getElementById("item_container_" + num);
                if (container) container.classList.remove("has-active-defect");
                
                const panel = document.getElementById("defect_panel_" + num);
                if (panel) {
                    panel.style.display = "none";
                    const inputs = panel.querySelectorAll("input, select, textarea");
                    inputs.forEach(input => input.setAttribute("disabled", "disabled"));
                }
                
                delete activeDefects[num];
            }
        });
        showToast(`Raum '${roomName}' komplett auf OK gesetzt!`);
    }

    // Inline remarks and defect options helpers
    function updateDefectField(num, field, value) {
        if (activeDefects[num]) {
            activeDefects[num][field] = value;
        }
    }

    function toggleTaskOptions(num, visible) {
        const container = document.getElementById("task_options_" + num);
        if (container) {
            container.style.display = visible ? "flex" : "none";
        }
    }

    // Instantly upload photo using AJAX when file is picked
    function uploadRowPhoto(num, fileInput) {
        const file = fileInput.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append("photo", file);
        
        const uploadBtn = fileInput.closest(".btn-photo-upload");
        const originalText = uploadBtn.innerHTML;
        uploadBtn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Lade...`;
        
        fetch("wohnungsabnahme_save.php?action=upload_photo", {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            uploadBtn.innerHTML = originalText;
            if (data.success) {
                updateDefectField(num, 'photo', data.filename);
                document.getElementById("input_photo_" + num).value = data.filename;
                
                const preview = document.getElementById("img_prev_" + num);
                preview.src = "../uploads/protocols/" + data.filename;
                preview.style.display = "block";
                
                showToast("Foto erfolgreich hochgeladen und optimiert!");
            } else {
                alert("Fehler beim Hochladen: " + data.error);
            }
        })
        .catch(err => {
            uploadBtn.innerHTML = originalText;
            alert("Verbindungsfehler beim Foto-Upload.");
        });
    }

    // AJAX submit master form (Draft or Final)
    function submitForm(isDraft, saveAsNew = false) {
        const form = document.getElementById("abnahmeForm");
        const formData = new FormData(form);
        formData.append("is_draft", isDraft ? "1" : "0");
        
        if (saveAsNew) {
            formData.set("protocol_id", "0");
        }
        
        // Add checkboxes values list explicitly
        const checkedList = Object.keys(activeDefects);
        checkedList.forEach(num => {
            formData.append("checkboxes[]", num);
        });

        // Strip data:image/png;base64, prefix to bypass WAF
        ['signature_vermieter', 'signature_mieter', 'signature_experte'].forEach(fieldName => {
            let val = formData.get(fieldName);
            if (val && val.startsWith('data:')) {
                let commaIdx = val.indexOf(',');
                if (commaIdx !== -1) {
                    formData.set(fieldName, val.substring(commaIdx + 1));
                }
            }
        });

        // Submit to PHP
        const saveBtn = document.querySelector(".main-save-btn");
        const origBtnText = saveBtn.innerHTML;
        saveBtn.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i> Verarbeitung läuft...`;
        saveBtn.disabled = true;
        
        fetch(`wohnungsabnahme_save.php?projekt_id=${formData.get('projekt_id')}&unit_id=${formData.get('unit_id')}&protocol_id=${formData.get('protocol_id')}`, {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            saveBtn.innerHTML = origBtnText;
            saveBtn.disabled = false;
            
            if (data.success) {
                showToast(isDraft ? "Entwurf erfolgreich gespeichert!" : "Protokoll finalisiert und PDF generiert!");
                
                // Update protocol id in form
                if (data.protocol_id) {
                    form.querySelector("[name='protocol_id']").value = data.protocol_id;
                }
                
                const nextUrl = `?projekt_id=${formData.get('projekt_id')}&unit_id=${formData.get('unit_id')}&protocol_id=${data.protocol_id}`;
                
                if (data.pdf_url) {
                    // Open the PDF in a new window immediately
                    setTimeout(() => {
                        window.open(data.pdf_url, "_blank");
                        window.location.href = nextUrl;
                    }, 1200);
                } else {
                    setTimeout(() => { window.location.href = nextUrl; }, 1200);
                }
            } else {
                alert("Fehler beim Speichern: " + data.error);
            }
        })
        .catch(err => {
            saveBtn.innerHTML = origBtnText;
            saveBtn.disabled = false;
            alert("Fehler bei der Serververbindung.");
        });
    }

    // Save as a new draft/template with custom name/description
    function saveAsNewDraft() {
        const descInput = document.querySelector("[name='protokoll_bezeichnung']");
        const defaultName = descInput ? descInput.value : "";
        const newName = prompt("Bitte geben Sie eine Bezeichnung / Beschreibung für diesen neuen Entwurf ein (um ihn als Vorlage zu behalten):", defaultName);
        if (newName === null) {
            return; // Abgebrochen
        }
        if (descInput) {
            descInput.value = newName;
        }
        submitForm(true, true); // isDraft = true, saveAsNew = true
    }

    // Sync pendenzen table
    function syncPendenzenList() {
        const form = document.getElementById("abnahmeForm");
        const formData = new FormData(form);
        
        fetch(`wohnungsabnahme_save.php?action=sync_pendenzen&projekt_id=${formData.get('projekt_id')}&unit_id=${formData.get('unit_id')}&protocol_id=${formData.get('protocol_id')}`, {
            method: "POST",
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast("Pendenzen erfolgreich mit der Hauptliste synchronisiert!");
                // Update the form values with sync returned pendenz IDs
                for (let num in data.synced_ids) {
                    const pidInput = form.querySelector(`[name="defects[${num}][pendenz_id]"]`);
                    if (pidInput) pidInput.value = data.synced_ids[num];
                }
            } else {
                alert("Fehler beim Synchronisieren: " + data.error);
            }
        })
        .catch(err => {
            alert("Verbindungsfehler bei Pendenzen-Sync.");
        });
    }

    // AJAX delete protocol handler
    function deleteProtocol(id) {
        if (!confirm("Wollen Sie diese Wohnungsabnahme wirklich unwiderruflich löschen?")) return;
        
        fetch(`wohnungsabnahme_save.php?action=delete&protocol_id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast("Protokoll gelöscht!");
                setTimeout(() => { window.location.href = `?projekt_id=<?= $pid ?>&unit_id=<?= $uid ?>`; }, 1000);
            } else {
                alert("Fehler: " + data.error);
            }
        });
    }

    // Display high-quality overlay toast alerts
    function showToast(message) {
        const toast = document.getElementById("toast_notification");
        toast.innerText = message;
        toast.classList.add("show");
        setTimeout(() => {
            toast.classList.remove("show");
        }, 3000);
    }

    // Helpers
    function md5(string) {
        return string.split('').reduce((a, b) => {
            a = ((a << 5) - a) + b.charCodeAt(0);
            return a & a;
        }, 0).toString();
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
</script>
