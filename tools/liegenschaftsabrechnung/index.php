<?php
// tools/liegenschaftsabrechnung/index.php
// Schweizer Liegenschaftsabrechnung & Steuerdeklaration für Immobilienverwaltung

if (session_status() === PHP_SESSION_NONE) {
    session_name('PENDENZ_SESSID');
    session_start();
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/authz.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fs.php';
require_once __DIR__ . '/../nebenkostenabrechnung/bootstrap.php';
require_once __DIR__ . '/../nebenkostenabrechnung/lib.php';
if (file_exists(__DIR__ . '/../../includes/csrf.php')) {
    require_once __DIR__ . '/../../includes/csrf.php';
}
require_login();

$role = $_SESSION['rolle'] ?? '';
if (!in_array($role, ['admin', 'superadmin'])) {
    die("Zugriff verweigert (nur Admin/Superadmin).");
}

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('csrf_field')) {
    function csrf_field(string $name = 'csrf'): string {
        $tok = $_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? '');
        return '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($tok) . '">';
    }
}

global $mysqli;
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $mysqli = $GLOBALS['mysqli'] ?? $GLOBALS['db'] ?? null;
}
nk_bootstrap($mysqli);

// -------------------------------------------------------------
// POST Handling: Schnell-Kategorisierung / Notiz ändern
// -------------------------------------------------------------
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_kategorie') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $newKat = trim($_POST['kategorie'] ?? '');
        if ($bookingId > 0 && $newKat !== '') {
            $stmt = $mysqli->prepare("UPDATE liegenschafts_konto SET kategorie = ? WHERE id = ?");
            $stmt->bind_param("si", $newKat, $bookingId);
            if ($stmt->execute()) {
                $flash = "Kategorie für Buchung #$bookingId erfolgreich aktualisiert.";
            } else {
                $flash = "Fehler beim Speichern: " . $mysqli->error;
                $flashType = "danger";
            }
            $stmt->close();
        }
    }
}

// -------------------------------------------------------------
// Parameter: Liegenschaft (Projekt) & Jahr
// -------------------------------------------------------------
// 1. Alle Projekte laden
$projekte = [];
$resP = $mysqli->query("SELECT id, nummer, name, adresse FROM projekte ORDER BY name ASC");
if ($resP) {
    while ($r = $resP->fetch_assoc()) {
        $projekte[] = $r;
    }
}

$pid = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : (int)($_SESSION['current_project_id'] ?? 0);
if ($pid <= 0 && !empty($projekte)) {
    $pid = (int)$projekte[0]['id'];
}

// 2. Verfügbare Buchungsjahre ermitteln
$dbYears = [];
$yrRes = $mysqli->query("SELECT DISTINCT YEAR(buchungsdatum) AS yr FROM liegenschafts_konto WHERE YEAR(buchungsdatum) BETWEEN 2000 AND 2099 ORDER BY yr DESC");
if ($yrRes) {
    while ($r = $yrRes->fetch_assoc()) {
        $y = (int)$r['yr'];
        if ($y >= 2000 && $y <= 2099) $dbYears[] = $y;
    }
}
$availableYears = array_unique(array_merge([(int)date('Y'), (int)date('Y') - 1], $dbYears));
rsort($availableYears);

$selYear = isset($_GET['jahr']) && (int)$_GET['jahr'] > 2000 ? (int)$_GET['jahr'] : 0;
if ($selYear <= 0) {
    // Prüfe ob Buchungen für das Projekt existieren
    $chkYr = $mysqli->query("SELECT DISTINCT YEAR(buchungsdatum) as yr FROM liegenschafts_konto WHERE (projekt_id = $pid OR liegenschaft_id = $pid) AND YEAR(buchungsdatum) BETWEEN 2000 AND 2099 ORDER BY buchungsdatum DESC LIMIT 1");
    if ($chkYr && ($cy = $chkYr->fetch_assoc())) {
        $selYear = (int)$cy['yr'];
    } else {
        $selYear = !empty($dbYears) ? $dbYears[0] : (int)date('Y');
    }
}

// Aktives Projekt & Liegenschaftsdaten laden
$aktProjekt = null;
foreach ($projekte as $p) {
    if ((int)$p['id'] === $pid) {
        $aktProjekt = $p;
        break;
    }
}

// Bankkonto der Liegenschaft aus kv_konten laden
$activeKonto = null;
if ($pid > 0) {
    $kRes = $mysqli->query("SELECT * FROM kv_konten WHERE projekt_id = $pid OR liegenschaft_id = $pid LIMIT 1");
    if ($kRes && ($kr = $kRes->fetch_assoc())) {
        $activeKonto = $kr;
    }
}

// Wohnungen der Liegenschaft zählen
$wohnungCount = 0;
$wRes = $mysqli->query("SELECT COUNT(*) as c FROM wohnungen w JOIN objekte o ON w.objekt_id = o.id WHERE o.projekt_id = $pid");
if ($wRes && ($wr = $wRes->fetch_assoc())) {
    $wohnungCount = (int)$wr['c'];
}

// -------------------------------------------------------------
// Buchungen für Projekt & Jahr laden
// -------------------------------------------------------------
$whereProj = $pid > 0 ? "AND (k.projekt_id = $pid OR k.liegenschaft_id = $pid)" : "";
$buchungenSql = "
    SELECT k.*, 
           w.name as wohnung_name,
           b.name as mieter_name
    FROM liegenschafts_konto k
    LEFT JOIN wohnungen w ON w.id = k.wohnung_id
    LEFT JOIN benutzer b ON b.id = k.mieter_id
    WHERE YEAR(k.buchungsdatum) = $selYear
      $whereProj
    ORDER BY k.buchungsdatum ASC, k.id ASC
";
$resB = $mysqli->query($buchungenSql);
$buchungen = [];
if ($resB) {
    while ($b = $resB->fetch_assoc()) {
        $buchungen[] = $b;
    }
}

// -------------------------------------------------------------
// Schweizer Standard Liegenschafts-Kontenrahmen & Gruppierung
// -------------------------------------------------------------
/*
 * Hauptgruppen:
 * A. Erträge (Mietzinse, Nebenkostenvorschüsse, Parkplätze, übrige Erträge)
 * B. Betriebskosten (Strom, Wasser/Abwasser, Kehricht, Hauswartung, übrige)
 * C. Liegenschaftsunterhalt & Reparaturen (Werterhaltend - Handwerker, Service-Abos)
 * D. Investitionen & Sanierungen (Wertvermehrend)
 * E. Versicherungen & Steuern (Gebäudeversicherung, Gemeindesteuern)
 * F. Finanzierung & Verwaltung (Hypothekarzinsen, Bankspesen, Verwaltung)
 * G. Eigentümerverkehr (Auszahlungen an Eigentümer, Entnahmen, Einlagen)
 * H. Neutrale & Transitorische Positionen (Kautionen, Korrekturen)
 */

$gruppen = [
    'ertrag' => [
        'title' => '1. Mietzinse & Erträge',
        'icon' => '📈',
        'color' => '#10b981',
        'sub' => [
            'miete' => ['title' => 'Mietzinseinnahmen (Wohnungen / Gewerbe / PP)', 'items' => [], 'total' => 0.0],
            'nk_ertrag' => ['title' => 'Nebenkosten-Akonto / Vorauszahlungen', 'items' => [], 'total' => 0.0],
            'uebrige_ertrag' => ['title' => 'Übrige Liegenschaftserträge', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'betrieb' => [
        'title' => '2. Betriebs- & Nebenkosten (Laufend)',
        'icon' => '⚡',
        'color' => '#f59e0b',
        'sub' => [
            'strom' => ['title' => 'Strom / EW (Allgemeinstrom & Haustechnik)', 'items' => [], 'total' => 0.0],
            'wasser' => ['title' => 'Wasser / Abwasser / Kehrichtgebühren', 'items' => [], 'total' => 0.0],
            'hauswart' => ['title' => 'Hauswartung & Reinigung / Umgebung', 'items' => [], 'total' => 0.0],
            'heizung' => ['title' => 'Heizung / Warmwasser / Brennstoffe', 'items' => [], 'total' => 0.0],
            'betrieb_uebrige' => ['title' => 'Übrige Betriebskosten & Abgaben', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'unterhalt' => [
        'title' => '3. Liegenschaftsunterhalt & Reparaturen (Werterhaltend)',
        'icon' => '🛠️',
        'color' => '#8b5cf6',
        'sub' => [
            'reparaturen' => ['title' => 'Handwerker & Reparaturen (Sanitär, Maler, etc.)', 'items' => [], 'total' => 0.0],
            'service' => ['title' => 'Service-Abonnemente (Lift, Heizung, Boiler etc.)', 'items' => [], 'total' => 0.0],
            'unterhalt_laufend' => ['title' => 'Laufender Unterhalt & Instandstellung', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'investition' => [
        'title' => '4. Investitionen & Sanierungen (Wertvermehrend)',
        'icon' => '🏗️',
        'color' => '#6366f1',
        'sub' => [
            'investitionen' => ['title' => 'Sanierungen, Umbauten & Investitionen (PV etc.)', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'versicherung_steuer' => [
        'title' => '5. Versicherungen & Steuern',
        'icon' => '🛡️',
        'color' => '#0ea5e9',
        'sub' => [
            'versicherung' => ['title' => 'Gebäude- & Haftpflichtversicherung', 'items' => [], 'total' => 0.0],
            'steuern' => ['title' => 'Liegenschafts- & Gemeindesteuern / Abgaben', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'finanz_admin' => [
        'title' => '6. Kapitalkosten & Verwaltung',
        'icon' => '🏦',
        'color' => '#ec4899',
        'sub' => [
            'hypothek' => ['title' => 'Hypothekarzinsen', 'items' => [], 'total' => 0.0],
            'bankspesen' => ['title' => 'Bankspesen & Kontoführung', 'items' => [], 'total' => 0.0],
            'verwaltung' => ['title' => 'Verwaltungshonorar & Porti/Spesen', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'eigentuemer' => [
        'title' => '7. Eigentümer-Verkehr (Auszahlungen & Entnahmen)',
        'icon' => '💼',
        'color' => '#3b82f6',
        'sub' => [
            'auszahlung' => ['title' => 'Auszahlungen / Entnahmen an Eigentümer', 'items' => [], 'total' => 0.0],
            'einlage' => ['title' => 'Eigentümer-Einlagen / Zuschüsse', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
    'neutral' => [
        'title' => '8. Neutrale & Transitorische Posten',
        'icon' => '⚖️',
        'color' => '#64748b',
        'sub' => [
            'kaution' => ['title' => 'Mietkautionen / Depots', 'items' => [], 'total' => 0.0],
            'korrektur' => ['title' => 'Rückzahlungen / Korrekturbuchungen', 'items' => [], 'total' => 0.0],
            'sonstige' => ['title' => 'Sonstige / Nicht zugeordnet', 'items' => [], 'total' => 0.0],
        ],
        'total' => 0.0,
    ],
];

// Automatische Zuordnung jeder Buchung
$customGroups = nk_load_groups($mysqli, $pid, 'liegenschaft');
foreach ($buchungen as &$b) {
    $kat = (string)($b['kategorie'] ?? '');
    $txt = (string)($b['beschreibung'] ?? '');
    $betrag = (float)$b['betrag'];
    $absBetrag = abs($betrag);

    $matchedMain = null;
    $matchedSub = null;

    // Projektbezogene eigene Gruppe hat Vorrang vor der Standardautomatik.
    $custom = nk_match_group($kat . ' ' . $txt, $customGroups);
    if ($custom) {
        $map = ['unterhalt'=>['unterhalt','reparaturen'], 'investition'=>['investition','investitionen'], 'verwaltung'=>['finanz_admin','verwaltung'], 'finanzierung'=>['finanz_admin','hypothek'], 'privat'=>['eigentuemer', $betrag < 0 ? 'auszahlung' : 'einlage']];
        $mapped = $map[$custom['tax_class'] ?? ''] ?? null;
        if ($mapped) { $matchedMain=$mapped[0]; $matchedSub=$mapped[1]; }
    }

    // 1. Mieteinnahmen & Erträge
    if ($matchedMain !== null) {
        // Bereits durch eine eigene Projektgruppe zugeordnet.
    } elseif (stripos($kat, 'Mietzins') !== false || $kat === 'Miete' || stripos($kat, 'Mieteinnahmen') !== false) {
        $matchedMain = 'ertrag';
        $matchedSub = 'miete';
    } elseif ($betrag > 0 && (stripos($txt, 'Miete') !== false || stripos($txt, 'Mietzins') !== false)) {
        $matchedMain = 'ertrag';
        $matchedSub = 'miete';
    } elseif (stripos($kat, 'Nebenkosten') !== false && $betrag > 0) {
        $matchedMain = 'ertrag';
        $matchedSub = 'nk_ertrag';
    } elseif ($betrag > 0 && stripos($kat, 'Ertrag') !== false) {
        $matchedMain = 'ertrag';
        $matchedSub = 'uebrige_ertrag';
    }
    // 2. Betriebskosten
    elseif (stripos($kat, 'Strom') !== false || stripos($kat, 'EW') !== false || stripos($txt, 'Elektro') !== false || stripos($txt, 'Strom') !== false || stripos($txt, 'EW ') !== false) {
        $matchedMain = 'betrieb';
        $matchedSub = 'strom';
    } elseif (stripos($kat, 'Wasser') !== false || stripos($kat, 'Kehricht') !== false || stripos($txt, 'Wasser') !== false || stripos($txt, 'Abwasser') !== false || stripos($txt, 'Kehricht') !== false) {
        $matchedMain = 'betrieb';
        $matchedSub = 'wasser';
    } elseif (stripos($kat, 'Hauswart') !== false || stripos($txt, 'Hauswart') !== false || stripos($txt, 'Reinigung') !== false) {
        $matchedMain = 'betrieb';
        $matchedSub = 'hauswart';
    } elseif (stripos($kat, 'Heizung') !== false || stripos($txt, 'Heizöl') !== false || stripos($txt, 'Pellets') !== false || stripos($txt, 'Fernwärme') !== false) {
        $matchedMain = 'betrieb';
        $matchedSub = 'heizung';
    } elseif (stripos($kat, 'Betriebskosten') !== false || (stripos($kat, 'Nebenkosten') !== false && $betrag < 0)) {
        $matchedMain = 'betrieb';
        $matchedSub = 'betrieb_uebrige';
    }
    // 3. Unterhalt & Reparaturen
    elseif (stripos($kat, 'Service') !== false || stripos($kat, 'Abonnement') !== false || stripos($txt, 'Service-Abo') !== false || stripos($txt, 'Wartungsvertrag') !== false || stripos($txt, 'Lift') !== false) {
        $matchedMain = 'unterhalt';
        $matchedSub = 'service';
    } elseif (stripos($kat, 'Reparatur') !== false || stripos($kat, 'Unterhalt') !== false || stripos($kat, 'Handwerker') !== false || stripos($txt, 'Reparatur') !== false || stripos($txt, 'Sanitär') !== false || stripos($txt, 'Maler') !== false) {
        $matchedMain = 'unterhalt';
        $matchedSub = 'reparaturen';
    }
    // 4. Investitionen
    elseif (stripos($kat, 'Investition') !== false || stripos($kat, 'Sanierung') !== false || stripos($kat, 'PV-Anlage') !== false) {
        $matchedMain = 'investition';
        $matchedSub = 'investitionen';
    }
    // 5. Versicherungen & Steuern
    elseif (stripos($kat, 'Versicherung') !== false || stripos($txt, 'Gebäudeversicherung') !== false || stripos($txt, 'Mobiliar') !== false || stripos($txt, 'Helvetia') !== false || stripos($txt, 'AXA') !== false || stripos($txt, 'Allianz') !== false) {
        $matchedMain = 'versicherung_steuer';
        $matchedSub = 'versicherung';
    } elseif (stripos($kat, 'Steuer') !== false || stripos($kat, 'Gemeinde') !== false || stripos($txt, 'Gemeindesteuer') !== false || stripos($txt, 'Liegenschaftssteuer') !== false || stripos($txt, 'Staatssteuer') !== false) {
        $matchedMain = 'versicherung_steuer';
        $matchedSub = 'steuern';
    }
    // 6. Kapitalkosten & Verwaltung
    elseif (stripos($kat, 'Hypothek') !== false || stripos($txt, 'Hypothekarzins') !== false || stripos($txt, 'Hypozins') !== false) {
        $matchedMain = 'finanz_admin';
        $matchedSub = 'hypothek';
    } elseif (stripos($kat, 'Bank') !== false || stripos($kat, 'Spesen') !== false || stripos($txt, 'Bankspesen') !== false || stripos($txt, 'Kontoführung') !== false || stripos($txt, 'Kartenpreis') !== false) {
        $matchedMain = 'finanz_admin';
        $matchedSub = 'bankspesen';
    } elseif (stripos($kat, 'Verwaltung') !== false || stripos($txt, 'Verwaltungshonorar') !== false) {
        $matchedMain = 'finanz_admin';
        $matchedSub = 'verwaltung';
    }
    // 7. Eigentümerverkehr
    elseif (stripos($kat, 'Auszahlung Eigentümer') !== false || stripos($kat, 'Eigentümer') !== false || stripos($kat, 'Privatbezug') !== false || stripos($txt, 'Auszahlung Eigentümer') !== false || stripos($txt, 'Eigentümer') !== false) {
        $matchedMain = 'eigentuemer';
        $matchedSub = $betrag < 0 ? 'auszahlung' : 'einlage';
    }
    // 8. Neutrale / Transitorische Posten
    elseif (stripos($kat, 'Kaution') !== false || stripos($kat, 'Depot') !== false || stripos($txt, 'Mietkaution') !== false || stripos($txt, 'Mietzinsdepot') !== false) {
        $matchedMain = 'neutral';
        $matchedSub = 'kaution';
    } elseif (stripos($kat, 'Korrektur') !== false || stripos($kat, 'Rückzahlung') !== false || stripos($txt, 'Rückerstattung') !== false || stripos($txt, 'Fehlüberweisung') !== false) {
        $matchedMain = 'neutral';
        $matchedSub = 'korrektur';
    }
    // Fallback: Positive Beträge zu Ertrag, negative Beträge zu Betrieb/Unterhalt
    else {
        if ($betrag > 0) {
            $matchedMain = 'ertrag';
            $matchedSub = 'uebrige_ertrag';
        } else {
            $matchedMain = 'neutral';
            $matchedSub = 'sonstige';
        }
    }

    $b['matched_main'] = $matchedMain;
    $b['matched_sub'] = $matchedSub;

    $gruppen[$matchedMain]['sub'][$matchedSub]['items'][] = $b;
    // Bei Ertrag ist es positiv (+), bei Kosten zählen wir als Aufwand (absoluter Betrag oder tatsächlicher Vorzeichenbetrag)
    if ($matchedMain === 'ertrag') {
        $gruppen[$matchedMain]['sub'][$matchedSub]['total'] += $betrag;
        $gruppen[$matchedMain]['total'] += $betrag;
    } elseif ($matchedMain === 'eigentuemer') {
        if ($matchedSub === 'auszahlung') {
            $gruppen[$matchedMain]['sub'][$matchedSub]['total'] += abs($betrag);
            $gruppen[$matchedMain]['total'] += abs($betrag);
        } else {
            $gruppen[$matchedMain]['sub'][$matchedSub]['total'] += abs($betrag);
            $gruppen[$matchedMain]['total'] -= abs($betrag); // Einlage reduziert Nettoentnahmen
        }
    } elseif ($matchedMain === 'neutral') {
        $gruppen[$matchedMain]['sub'][$matchedSub]['total'] += $betrag;
        $gruppen[$matchedMain]['total'] += $betrag;
    } else {
        // Aufwandskonten (Betrieb, Unterhalt, Investitionen, Versicherung/Steuer, Finanz/Admin)
        // Wir führen Aufwände als positive Werte für die Erfolgsrechnung
        $aufwandVal = abs($betrag);
        $gruppen[$matchedMain]['sub'][$matchedSub]['total'] += $aufwandVal;
        $gruppen[$matchedMain]['total'] += $aufwandVal;
    }
}
unset($b);

// -------------------------------------------------------------
// Salden & Kennzahlen der Liegenschaft
// -------------------------------------------------------------
$totalErtrag = $gruppen['ertrag']['total'];
$totalBetrieb = $gruppen['betrieb']['total'];
$totalUnterhalt = $gruppen['unterhalt']['total'];
$totalInvestition = $gruppen['investition']['total'];
$totalVersicherungSteuer = $gruppen['versicherung_steuer']['total'];
$totalFinanzAdmin = $gruppen['finanz_admin']['total'];

// Liegenschafts-Gesamtaufwand (vor Eigentümerentnahmen & Investitionen)
$betrieblicherAufwand = $totalBetrieb + $totalUnterhalt + $totalVersicherungSteuer + $totalFinanzAdmin;
$gesamtaufwandInklInvest = $betrieblicherAufwand + $totalInvestition;

// Nettoertrag / Reingewinn der Liegenschaft
$nettoertragLiegenschaft = $totalErtrag - $gesamtaufwandInklInvest;

// Eigentümerverkehr
$totalAuszahlungEigentuemer = $gruppen['eigentuemer']['sub']['auszahlung']['total'];
$totalEinlagenEigentuemer = $gruppen['eigentuemer']['sub']['einlage']['total'];
$nettoEigentuemerEntnahmen = $totalAuszahlungEigentuemer - $totalEinlagenEigentuemer;

// Saldo der Abrechnungsperiode (zu Gunsten / Lasten Eigentümer)
$saldoAbrechnung = $nettoertragLiegenschaft - $nettoEigentuemerEntnahmen;

// Kennzahlen
$aufwandsQuote = $totalErtrag > 0 ? round(($betrieblicherAufwand / $totalErtrag) * 100, 1) : 0;
$unterhaltsQuote = $totalErtrag > 0 ? round(($totalUnterhalt / $totalErtrag) * 100, 1) : 0;
$finanzQuote = $totalErtrag > 0 ? round(($totalFinanzAdmin / $totalErtrag) * 100, 1) : 0;
$nettoRenditeQuote = $totalErtrag > 0 ? round(($nettoertragLiegenschaft / $totalErtrag) * 100, 1) : 0;

// -------------------------------------------------------------
// Schweizer Steuerdeklarations-Werte (Kantonales Steuerformular)
// -------------------------------------------------------------
// 1. Steuerbarer Ertrag (Brutto-Mieteinnahmen)
$steuerErtrag = $totalErtrag;

// 2. Steuerlich abzugsfähiger Liegenschaftsunterhalt (Werterhaltend)
// Inkl. Handwerker, Reparaturen, Service-Abos, Gebäudeversicherungen, Liegenschaftssteuer
$steuerAbzugLiegenschaft = $totalUnterhalt + $gruppen['versicherung_steuer']['sub']['versicherung']['total'] + $gruppen['betrieb']['sub']['hauswart']['total'];

// 3. Schuldzinsen (Hypothekarzinsen, separat in Steuererklärung Ziffer "Schuldzinsen")
$steuerSchuldzinsen = $gruppen['finanz_admin']['sub']['hypothek']['total'];

// 4. Pauschalabzug vs. Effektiver Abzug Vergleich (CH-Steuerpraxis):
// Bis 10 Jahre Gebäudealter: 10% der Brutto-Mietzinse
// Über 10 Jahre Gebäudealter: 20% der Brutto-Mietzinse
$pauschale10 = round($steuerErtrag * 0.10, 2);
$pauschale20 = round($steuerErtrag * 0.20, 2);
$effektiverVorteil10 = $steuerAbzugLiegenschaft - $pauschale10;
$effektiverVorteil20 = $steuerAbzugLiegenschaft - $pauschale20;

// -------------------------------------------------------------
// CSV-Export Handler
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $dateiname = 'Liegenschaftsabrechnung_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $aktProjekt['name'] ?? 'Liegenschaft') . '_' . $selYear . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $dateiname . '"');
    
    // UTF-8 BOM für Microsoft Excel
    echo "\xEF\xBB\xBF";
    
    $out = fopen('php://output', 'w');
    fputcsv($out, ['LIEGENSCHAFTSABRECHNUNG', $aktProjekt['name'] ?? 'Liegenschaft #'.$pid, 'JAHR: ' . $selYear], ';');
    fputcsv($out, ['IBAN', $activeKonto['iban'] ?? 'Keine', 'BANK', $activeKonto['bank'] ?? 'Raiffeisen'], ';');
    fputcsv($out, [], ';');
    
    // Zusammenfassung
    fputcsv($out, ['ERFOLGSRECHNUNG / ZUSAMMENFASSUNG', 'BETRAG (CHF)'], ';');
    fputcsv($out, ['Total Mietzinseinnahmen & Erträge', number_format($totalErtrag, 2, '.', '')], ';');
    fputcsv($out, ['Total Betriebs- & Nebenkosten', number_format(-$totalBetrieb, 2, '.', '')], ';');
    fputcsv($out, ['Total Liegenschaftsunterhalt (Werterhaltend)', number_format(-$totalUnterhalt, 2, '.', '')], ';');
    fputcsv($out, ['Total Investitionen & Sanierungen', number_format(-$totalInvestition, 2, '.', '')], ';');
    fputcsv($out, ['Total Versicherungen & Steuern', number_format(-$totalVersicherungSteuer, 2, '.', '')], ';');
    fputcsv($out, ['Total Hypothekarzinsen & Finanz/Verwaltung', number_format(-$totalFinanzAdmin, 2, '.', '')], ';');
    fputcsv($out, ['NETTOERTRAG LIEGENSCHAFT (Reingewinn)', number_format($nettoertragLiegenschaft, 2, '.', '')], ';');
    fputcsv($out, ['Auszahlungen / Entnahmen Eigentümer', number_format(-$totalAuszahlungEigentuemer, 2, '.', '')], ';');
    fputcsv($out, ['Eigentümer-Einlagen', number_format($totalEinlagenEigentuemer, 2, '.', '')], ';');
    fputcsv($out, ['ABRECHNUNGSSALDO PERIODE', number_format($saldoAbrechnung, 2, '.', '')], ';');
    fputcsv($out, [], ';');
    
    // Einzelbuchungen
    fputcsv($out, ['ID', 'Datum', 'Hauptgruppe', 'Unterkategorie', 'Buchungstext', 'Wohnung / Einheit', 'Zahlungsart', 'Betrag (CHF)'], ';');
    foreach ($buchungen as $b) {
        $mainKey = $b['matched_main'] ?? 'neutral';
        $subKey = $b['matched_sub'] ?? 'sonstige';
        $mainTitle = $gruppen[$mainKey]['title'] ?? $mainKey;
        $subTitle = $gruppen[$mainKey]['sub'][$subKey]['title'] ?? $subKey;
        fputcsv($out, [
            $b['id'],
            $b['buchungsdatum'],
            $mainTitle,
            $subTitle,
            $b['beschreibung'],
            $b['wohnung_name'] ?: ($b['wohnung_label'] ?: '—'),
            $b['zahlungsart'] ?: 'Bank',
            number_format((float)$b['betrag'], 2, '.', '')
        ], ';');
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------
// POST: Direkt auf Google Drive sichern
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_to_drive') {
    $root = project_root_path($mysqli, $pid);
    if (!$root || !is_dir($root)) {
        $flash = "❌ Google Drive Pfad für diese Liegenschaft wurde nicht gefunden.";
        $flashType = "danger";
    } else {
        $targetDir = $root . DIRECTORY_SEPARATOR . '06_Bank_Liegenschaftskonto';
        if (!is_dir($targetDir)) {
            $targetDir = $root;
        }
        $safeProj = preg_replace('/[^a-zA-Z0-9_-]/', '_', $aktProjekt['name'] ?? 'Liegenschaft');
        $csvFileName = "Liegenschaftsabrechnung_{$safeProj}_{$selYear}_" . date('Ymd_His') . ".csv";
        $destPath = $targetDir . DIRECTORY_SEPARATOR . $csvFileName;

        $fp = fopen($destPath, 'w');
        if ($fp) {
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, ['LIEGENSCHAFTSABRECHNUNG', $aktProjekt['name'] ?? 'Liegenschaft #'.$pid, 'JAHR: ' . $selYear], ';');
            fputcsv($fp, ['IBAN', $activeKonto['iban'] ?? 'Keine', 'BANK', $activeKonto['bank'] ?? 'Raiffeisen'], ';');
            fputcsv($fp, ['ERSTELLT AM', date('d.m.Y H:i:s'), 'BENUTZER', $_SESSION['username'] ?? 'Admin'], ';');
            fputcsv($fp, [], ';');
            
            fputcsv($fp, ['ERFOLGSRECHNUNG / ZUSAMMENFASSUNG', 'BETRAG (CHF)'], ';');
            fputcsv($fp, ['Total Mietzinseinnahmen & Erträge', number_format($totalErtrag, 2, '.', '')], ';');
            fputcsv($fp, ['Total Betriebs- & Nebenkosten', number_format(-$totalBetrieb, 2, '.', '')], ';');
            fputcsv($fp, ['Total Liegenschaftsunterhalt (Werterhaltend)', number_format(-$totalUnterhalt, 2, '.', '')], ';');
            fputcsv($fp, ['Total Investitionen & Sanierungen', number_format(-$totalInvestition, 2, '.', '')], ';');
            fputcsv($fp, ['Total Versicherungen & Steuern', number_format(-$totalVersicherungSteuer, 2, '.', '')], ';');
            fputcsv($fp, ['Total Hypothekarzinsen & Finanz/Verwaltung', number_format(-$totalFinanzAdmin, 2, '.', '')], ';');
            fputcsv($fp, ['NETTOERTRAG LIEGENSCHAFT (Reingewinn)', number_format($nettoertragLiegenschaft, 2, '.', '')], ';');
            fputcsv($fp, ['Auszahlungen / Entnahmen Eigentümer', number_format(-$totalAuszahlungEigentuemer, 2, '.', '')], ';');
            fputcsv($fp, ['Eigentümer-Einlagen', number_format($totalEinlagenEigentuemer, 2, '.', '')], ';');
            fputcsv($fp, ['ABRECHNUNGSSALDO PERIODE', number_format($saldoAbrechnung, 2, '.', '')], ';');
            fputcsv($fp, [], ';');

            fputcsv($fp, ['SCHWEIZER STEUERERKLÄRUNG (LIEGENSCHAFTSKOSTEN)', 'BETRAG (CHF)'], ';');
            fputcsv($fp, ['1. Steuerbare Mietzinseinnahmen', number_format($steuerErtrag, 2, '.', '')], ';');
            fputcsv($fp, ['2. Abzugsfähiger Liegenschaftsunterhalt (Werterhaltend)', number_format($steuerAbzugLiegenschaft, 2, '.', '')], ';');
            fputcsv($fp, ['3. Schuldzinsen (Hypothekarzinsen)', number_format($steuerSchuldzinsen, 2, '.', '')], ';');
            fputcsv($fp, ['4. Steuerlicher Liegenschafts-Reinertrag', number_format($steuerErtrag - $steuerAbzugLiegenschaft, 2, '.', '')], ';');
            fputcsv($fp, [], ';');

            fputcsv($fp, ['ID', 'Datum', 'Hauptgruppe', 'Unterkategorie', 'Buchungstext', 'Wohnung / Einheit', 'Zahlungsart', 'Betrag (CHF)'], ';');
            foreach ($buchungen as $b) {
                $mainKey = $b['matched_main'] ?? 'neutral';
                $subKey = $b['matched_sub'] ?? 'sonstige';
                $mainTitle = $gruppen[$mainKey]['title'] ?? $mainKey;
                $subTitle = $gruppen[$mainKey]['sub'][$subKey]['title'] ?? $subKey;
                fputcsv($fp, [
                    $b['id'],
                    $b['buchungsdatum'],
                    $mainTitle,
                    $subTitle,
                    $b['beschreibung'],
                    $b['wohnung_name'] ?: ($b['wohnung_label'] ?: '—'),
                    $b['zahlungsart'] ?: 'Bank',
                    number_format((float)$b['betrag'], 2, '.', '')
                ], ';');
            }
            fclose($fp);
            $flash = "☁️ Abrechnung erfolgreich direkt auf Google Drive gespeichert:<br><strong style='font-family:monospace;'>" . htmlspecialchars($destPath) . "</strong>";
        } else {
            $flash = "❌ Fehler beim Schreiben auf Google Drive: " . htmlspecialchars($destPath);
            $flashType = "danger";
        }
    }
}

// -------------------------------------------------------------
// Header & Navigation einbinden
// -------------------------------------------------------------
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/nav_dispatch.php';
?>

<style>
/* Scoped Styles für Schweizer Liegenschaftsabrechnung */
:root {
  --la-primary: #7c3aed;
  --la-primary-dark: #6d28d9;
  --la-primary-light: #f5f3ff;
  --la-success: #10b981;
  --la-danger: #ef4444;
  --la-warning: #f59e0b;
  --la-slate: #0f172a;
}

.la-wrap {
  max-width: 1540px;
  margin: 20px auto;
  padding: 0 16px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
  color: #1e293b;
}

/* Hero Header */
.la-hero {
  background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
  border-radius: 16px;
  padding: 24px 28px;
  color: #ffffff;
  margin-bottom: 20px;
  box-shadow: 0 10px 25px -5px rgba(67, 56, 202, 0.25);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}

.la-hero-title {
  display: flex;
  align-items: center;
  gap: 12px;
}
.la-hero-title h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 800;
  letter-spacing: -0.5px;
}
.la-hero-title p {
  margin: 4px 0 0 0;
  font-size: 13px;
  color: #c7d2fe;
}
.la-hero-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: center;
}

.la-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  border: 1px solid transparent;
  cursor: pointer;
  transition: all 0.15s ease;
}
.la-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.la-btn-white { background: #ffffff; color: #1e1b4b; }
.la-btn-purple { background: #8b5cf6; color: #ffffff; border-color: #a78bfa; }
.la-btn-emerald { background: #10b981; color: #ffffff; }
.la-btn-indigo { background: #4f46e5; color: #ffffff; }
.la-btn-slate { background: rgba(255,255,255,0.15); color: #ffffff; border-color: rgba(255,255,255,0.25); }

/* Filter Bar */
.la-filter-bar {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 16px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 20px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.03);
}
.la-filter-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.la-filter-label {
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.la-select {
  padding: 8px 14px;
  border: 1.5px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  background: #fff;
  color: #0f172a;
  outline: none;
}
.la-select:focus {
  border-color: #7c3aed;
  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
}

.la-year-pills {
  display: flex;
  gap: 6px;
  align-items: center;
  flex-wrap: wrap;
}
.la-year-pill {
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 700;
  text-decoration: none;
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
  transition: all 0.15s ease;
}
.la-year-pill:hover {
  background: #e2e8f0;
  color: #0f172a;
}
.la-year-pill.active {
  background: #7c3aed;
  color: #ffffff;
  border-color: #7c3aed;
  box-shadow: 0 2px 8px rgba(124, 58, 237, 0.3);
}

/* Liegenschafts Info Banner */
.la-prop-banner {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-left: 5px solid #7c3aed;
  border-radius: 12px;
  padding: 16px 20px;
  margin-bottom: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.02);
}
.la-prop-title {
  font-size: 17px;
  font-weight: 800;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 8px;
}
.la-prop-details {
  font-size: 13px;
  color: #64748b;
  margin-top: 4px;
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}
.la-prop-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #f8fafc;
  padding: 4px 10px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  font-weight: 600;
}

/* KPI Executive Cards */
.la-kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}
.la-kpi-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.03);
  position: relative;
  overflow: hidden;
}
.la-kpi-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
}
.la-kpi-card.green::before { background: #10b981; }
.la-kpi-card.red::before { background: #ef4444; }
.la-kpi-card.purple::before { background: #8b5cf6; }
.la-kpi-card.blue::before { background: #3b82f6; }
.la-kpi-card.indigo::before { background: #6366f1; }

.la-kpi-title {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #64748b;
  margin-bottom: 6px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.la-kpi-val {
  font-size: 26px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: #0f172a;
}
.la-kpi-sub {
  font-size: 12px;
  color: #64748b;
  margin-top: 6px;
}

/* Tab Navigation */
.la-tabs {
  display: flex;
  gap: 8px;
  border-bottom: 2px solid #e2e8f0;
  margin-bottom: 24px;
  flex-wrap: wrap;
}
.la-tab-btn {
  padding: 12px 20px;
  font-size: 14px;
  font-weight: 700;
  color: #64748b;
  background: transparent;
  border: none;
  border-bottom: 3px solid transparent;
  margin-bottom: -2px;
  cursor: pointer;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.la-tab-btn:hover {
  color: #7c3aed;
}
.la-tab-btn.active {
  color: #7c3aed;
  border-bottom-color: #7c3aed;
}

/* Tab Panels */
.la-tab-panel {
  display: none;
}
.la-tab-panel.active {
  display: block;
}

/* Erfolgsrechnungs-Tabelle */
.la-table-wrap {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.03);
  margin-bottom: 24px;
}
.la-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}
.la-table th {
  background: #0f172a;
  color: #ffffff;
  padding: 14px 18px;
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.la-table td {
  padding: 12px 18px;
  border-bottom: 1px solid #f1f5f9;
  font-size: 14px;
  vertical-align: middle;
}
.la-table tr:hover {
  background: #f8fafc;
}

.row-main-header {
  background: #f8fafc;
  font-weight: 800;
  color: #0f172a;
  border-top: 2px solid #e2e8f0;
  border-bottom: 1px solid #cbd5e1;
}
.row-sub-header {
  font-weight: 600;
  color: #334155;
}
.row-total {
  font-weight: 800;
  background: #f1f5f9;
  border-top: 2px solid #cbd5e1;
  border-bottom: 2px solid #0f172a;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-family: Consolas, "SF Mono", Monaco, monospace;
}
.num-bold {
  font-weight: 800;
}
.text-green { color: #16a34a; }
.text-red { color: #dc2626; }
.text-purple { color: #7c3aed; }

/* Schweizer Steuer-Zusammenstellung Card */
.la-tax-box {
  background: linear-gradient(135deg, #fefce8 0%, #fffbeb 100%);
  border: 1.5px solid #fde68a;
  border-radius: 14px;
  padding: 24px;
  margin-bottom: 24px;
  box-shadow: 0 4px 15px rgba(245, 158, 11, 0.08);
}
.la-tax-title {
  font-size: 18px;
  font-weight: 800;
  color: #92400e;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.la-tax-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.la-tax-item {
  background: #ffffff;
  border: 1px solid #fef3c7;
  border-radius: 10px;
  padding: 14px 16px;
}
.la-tax-item-title {
  font-size: 12px;
  font-weight: 700;
  color: #b45309;
  text-transform: uppercase;
}
.la-tax-item-val {
  font-size: 22px;
  font-weight: 800;
  color: #78350f;
  margin-top: 4px;
  font-variant-numeric: tabular-nums;
}
.la-tax-rec {
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 10px;
  padding: 14px 18px;
  color: #065f46;
  font-size: 13.5px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Akkordeon für Einzelbuchungen */
.acc-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
  color: #7c3aed;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px;
  border-radius: 4px;
}
.acc-btn:hover {
  background: #f3e8ff;
}

/* Badges */
.badge-ch {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
}
.badge-income { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.badge-expense { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.badge-neutral { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

/* Druck-Layout (@media print) */
@media print {
  body {
    background: #ffffff !important;
    color: #000000 !important;
  }
  .no-print, header, nav, .la-hero-actions, .la-filter-bar, .la-tabs, .btn, button, .acc-btn {
    display: none !important;
  }
  .la-wrap {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
  }
  .la-hero {
    background: none !important;
    color: #000000 !important;
    padding: 0 0 15px 0 !important;
    border-bottom: 2px solid #000000 !important;
    box-shadow: none !important;
    margin-bottom: 15px !important;
  }
  .la-hero-title h1 {
    font-size: 20pt !important;
    color: #000000 !important;
  }
  .la-hero-title p {
    color: #444444 !important;
  }
  .la-table th {
    background: #f1f5f9 !important;
    color: #000000 !important;
    border: 1px solid #cccccc !important;
  }
  .la-table td {
    border: 1px solid #eeeeee !important;
    padding: 6px 10px !important;
    font-size: 10pt !important;
  }
  .la-tab-panel {
    display: block !important;
  }
  .print-only {
    display: block !important;
  }
  .la-tax-box {
    border: 1px solid #000000 !important;
    background: #ffffff !important;
  }
}
.print-only {
  display: none;
}
</style>

<div class="la-wrap">

  <!-- HERO HEADER -->
  <header class="la-hero">
    <div class="la-hero-title">
      <span style="font-size:36px;">📑</span>
      <div>
        <h1>Schweizer Liegenschaftsabrechnung</h1>
        <p>Jahresabrechnung, Erfolgsrechnung &amp; Steuerdeklaration nach Schweizer Immobilienstandard</p>
      </div>
    </div>
    <div class="la-hero-actions no-print">
      <button onclick="window.print()" class="la-btn la-btn-white">🖨️ Abrechnung drucken / PDF</button>
      <a href="?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>&export=csv" class="la-btn la-btn-purple">📥 Excel / CSV Export</a>
      <form method="post" style="margin:0;display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_to_drive">
        <button type="submit" class="la-btn" style="background:#0284c7; color:#fff; border-color:#0284c7;" title="Speichert diese Abrechnung direkt in den Google Drive Ordner dieser Liegenschaft">
          ☁️ Auf Drive sichern
        </button>
      </form>
      <a href="../konto_verwaltung/index.php?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>" class="la-btn la-btn-emerald">💳 Zum Bankkonto</a>
      <a href="../mietkontrolle/index.php?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>" class="la-btn la-btn-indigo">💰 Zur Mietkontrolle</a>
      <a href="../finanzgruppen/index.php?projekt_id=<?= $pid ?>" class="la-btn" style="background:#475569;color:#fff;">🗂️ Gruppen</a>
    </div>
  </header>

  <?php if ($flash): ?>
    <div style="background:<?= $flashType==='success' ? '#ecfdf5' : '#fee2e2' ?>; border-left:4px solid <?= $flashType==='success' ? '#10b981' : '#ef4444' ?>; padding:12px 18px; border-radius:8px; margin-bottom:18px; font-weight:700; color:<?= $flashType==='success' ? '#065f46' : '#991b1b' ?>;">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>

  <!-- FILTER & JAHRESAUSWAHL -->
  <div class="la-filter-bar no-print">
    <div class="la-filter-group">
      <label class="la-filter-label" for="pid_select">Liegenschaft / Projekt:</label>
      <select id="pid_select" class="la-select" onchange="location.href='?projekt_id=' + this.value + '&jahr=<?= $selYear ?>'">
        <?php foreach ($projekte as $pr): ?>
          <option value="<?= (int)$pr['id'] ?>" <?= (int)$pr['id'] === $pid ? 'selected' : '' ?>>
            <?= htmlspecialchars($pr['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="la-filter-group">
      <span class="la-filter-label">Rechnungsjahr:</span>
      <div class="la-year-pills">
        <?php foreach ($availableYears as $ay): ?>
          <a href="?projekt_id=<?= $pid ?>&jahr=<?= $ay ?>" class="la-year-pill <?= (int)$ay === $selYear ? 'active' : '' ?>">
            <?= $ay ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- LIEGENSCHAFTS- & BANKBANNER -->
  <div class="la-prop-banner">
    <div>
      <div class="la-prop-title">
        <span>🏠 <?= htmlspecialchars($aktProjekt['name'] ?? 'Liegenschaft #'.$pid) ?></span>
      </div>
      <div class="la-prop-details">
        <span class="la-prop-badge">
          📅 Abrechnungsperiode: <strong>01.01.<?= $selYear ?> – 31.12.<?= $selYear ?></strong>
        </span>
        <span class="la-prop-badge">
          🏢 Einheiten: <strong><?= $wohnungCount ?> Wohnungen / Mietobjekte</strong>
        </span>
        <span class="la-prop-badge">
          💳 Bankkonto: <strong><?= htmlspecialchars($activeKonto['bank'] ?? 'Raiffeisen') ?></strong> 
          (<?= htmlspecialchars($activeKonto['iban'] ? chunk_split($activeKonto['iban'], 4, ' ') : 'Keine IBAN') ?>)
        </span>
        <span class="la-prop-badge">
          🔢 Buchungen: <strong><?= count($buchungen) ?> Einträge erfasst</strong>
        </span>
      </div>
    </div>
    <div class="no-print">
      <a href="../konto_verwaltung/import.php" class="la-btn la-btn-slate" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; font-size:12px;">
        📥 Bank-CSV importieren
      </a>
    </div>
  </div>

  <!-- EXECUTIVE KPI GRID -->
  <div class="la-kpi-grid">
    <div class="la-kpi-card green">
      <div class="la-kpi-title">
        <span>Mietzinseinnahmen (Total)</span>
        <span>📈</span>
      </div>
      <div class="la-kpi-val text-green">
        CHF <?= number_format($totalErtrag, 2, '.', "'") ?>
      </div>
      <div class="la-kpi-sub">
        100% Brutto-Rohertrag <?= $selYear ?>
      </div>
    </div>

    <div class="la-kpi-card red">
      <div class="la-kpi-title">
        <span>Betriebs- &amp; Unterhalt</span>
        <span>🛠️</span>
      </div>
      <div class="la-kpi-val text-red">
        CHF <?= number_format($totalBetrieb + $totalUnterhalt, 2, '.', "'") ?>
      </div>
      <div class="la-kpi-sub">
        Quote: <?= $totalErtrag > 0 ? round((($totalBetrieb + $totalUnterhalt) / $totalErtrag) * 100, 1) : 0 ?>% vom Mietertrag
      </div>
    </div>

    <div class="la-kpi-card purple">
      <div class="la-kpi-title">
        <span>Hypothekarzinsen &amp; Finanz</span>
        <span>🏦</span>
      </div>
      <div class="la-kpi-val text-purple">
        CHF <?= number_format($totalFinanzAdmin, 2, '.', "'") ?>
      </div>
      <div class="la-kpi-sub">
        Hypozinsen: CHF <?= number_format($gruppen['finanz_admin']['sub']['hypothek']['total'], 2, '.', "'") ?>
      </div>
    </div>

    <div class="la-kpi-card indigo">
      <div class="la-kpi-title">
        <span>Reingewinn Liegenschaft</span>
        <span>⭐</span>
      </div>
      <div class="la-kpi-val" style="color: <?= $nettoertragLiegenschaft >= 0 ? '#4338ca' : '#dc2626' ?>;">
        CHF <?= number_format($nettoertragLiegenschaft, 2, '.', "'") ?>
      </div>
      <div class="la-kpi-sub">
        Netto-Marge: <?= $nettoRenditeQuote ?>% vor Eigentümerentnahmen
      </div>
    </div>

    <div class="la-kpi-card blue">
      <div class="la-kpi-title">
        <span>Auszahlungen Eigentümer</span>
        <span>💼</span>
      </div>
      <div class="la-kpi-val" style="color:#2563eb;">
        CHF <?= number_format($totalAuszahlungEigentuemer, 2, '.', "'") ?>
      </div>
      <div class="la-kpi-sub">
        Saldo Periode: <strong>CHF <?= number_format($saldoAbrechnung, 2, '.', "'") ?></strong>
      </div>
    </div>
  </div>

  <!-- TAB NAVIGATION -->
  <div class="la-tabs no-print">
    <button class="la-tab-btn active" onclick="switchTab('tab-erfolg', this)">
      📊 Erfolgsrechnung &amp; Abrechnung
    </button>
    <button class="la-tab-btn" onclick="switchTab('tab-steuer', this)">
      🏛️ Schweizer Steuererklärung (Liegenschaftskosten)
    </button>
    <button class="la-tab-btn" onclick="switchTab('tab-journal', this)">
      📜 Detailliertes Buchungsjournal (<?= count($buchungen) ?>)
    </button>
    <button class="la-tab-btn" onclick="switchTab('tab-eigentuemer', this)">
      💼 Eigentümer-Konto &amp; Saldo
    </button>
  </div>

  <!-- ============================================================= -->
  <!-- TAB 1: ERFOLGSRECHNUNG & HAUPTABRECHNUNG -->
  <!-- ============================================================= -->
  <div id="tab-erfolg" class="la-tab-panel active">
    <div class="la-table-wrap">
      <table class="la-table">
        <thead>
          <tr>
            <th style="width:50%;">Kategorie / Kontengruppe</th>
            <th class="num" style="width:20%;">Anzahl Buchungen</th>
            <th class="num" style="width:30%;">Total Betrag (CHF)</th>
          </tr>
        </thead>
        <tbody>

          <!-- 1. ERTRÄGE -->
          <tr class="row-main-header">
            <td>
              <span style="font-size:16px;">📈</span> <strong>1. MIETZINSEINNAHMEN &amp; ERTRÄGE</strong>
            </td>
            <td class="num"><?= count($gruppen['ertrag']['sub']['miete']['items']) + count($gruppen['ertrag']['sub']['nk_ertrag']['items']) + count($gruppen['ertrag']['sub']['uebrige_ertrag']['items']) ?></td>
            <td class="num num-bold text-green">+ CHF <?= number_format($totalErtrag, 2, '.', "'") ?></td>
          </tr>
          <?php foreach ($gruppen['ertrag']['sub'] as $subKey => $sub): ?>
            <?php if (count($sub['items']) > 0 || $sub['total'] > 0): ?>
              <tr>
                <td style="padding-left:36px;">&bull; <?= htmlspecialchars($sub['title']) ?></td>
                <td class="num"><?= count($sub['items']) ?></td>
                <td class="num text-green">+ CHF <?= number_format($sub['total'], 2, '.', "'") ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- 2. BETRIEBSKOSTEN -->
          <tr class="row-main-header">
            <td>
              <span style="font-size:16px;">⚡</span> <strong>2. BETRIEBS- &amp; NEBENKOSTEN</strong>
            </td>
            <td class="num">
              <?php 
                $cntBetrieb = 0; 
                foreach ($gruppen['betrieb']['sub'] as $s) $cntBetrieb += count($s['items']);
                echo $cntBetrieb;
              ?>
            </td>
            <td class="num num-bold text-red">- CHF <?= number_format($totalBetrieb, 2, '.', "'") ?></td>
          </tr>
          <?php foreach ($gruppen['betrieb']['sub'] as $subKey => $sub): ?>
            <?php if (count($sub['items']) > 0 || $sub['total'] > 0): ?>
              <tr>
                <td style="padding-left:36px;">&bull; <?= htmlspecialchars($sub['title']) ?></td>
                <td class="num"><?= count($sub['items']) ?></td>
                <td class="num text-red">- CHF <?= number_format($sub['total'], 2, '.', "'") ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- 3. LIEGENSCHAFTSUNTERHALT -->
          <tr class="row-main-header">
            <td>
              <span style="font-size:16px;">🛠️</span> <strong>3. LIEGENSCHAFTSUNTERHALT &amp; REPARATUREN (Werterhaltend)</strong>
            </td>
            <td class="num">
              <?php 
                $cntUnt = 0; 
                foreach ($gruppen['unterhalt']['sub'] as $s) $cntUnt += count($s['items']);
                echo $cntUnt;
              ?>
            </td>
            <td class="num num-bold text-purple">- CHF <?= number_format($totalUnterhalt, 2, '.', "'") ?></td>
          </tr>
          <?php foreach ($gruppen['unterhalt']['sub'] as $subKey => $sub): ?>
            <?php if (count($sub['items']) > 0 || $sub['total'] > 0): ?>
              <tr>
                <td style="padding-left:36px;">&bull; <?= htmlspecialchars($sub['title']) ?></td>
                <td class="num"><?= count($sub['items']) ?></td>
                <td class="num text-purple">- CHF <?= number_format($sub['total'], 2, '.', "'") ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- 4. INVESTITIONEN -->
          <?php if ($totalInvestition > 0): ?>
            <tr class="row-main-header">
              <td>
                <span style="font-size:16px;">🏗️</span> <strong>4. INVESTITIONEN &amp; SANIERUNGEN (Wertvermehrend)</strong>
              </td>
              <td class="num"><?= count($gruppen['investition']['sub']['investitionen']['items']) ?></td>
              <td class="num num-bold" style="color:#6366f1;">- CHF <?= number_format($totalInvestition, 2, '.', "'") ?></td>
            </tr>
          <?php endif; ?>

          <!-- 5. VERSICHERUNGEN & STEUERN -->
          <tr class="row-main-header">
            <td>
              <span style="font-size:16px;">🛡️</span> <strong>5. VERSICHERUNGEN &amp; STEUERN</strong>
            </td>
            <td class="num">
              <?php 
                $cntVers = 0; 
                foreach ($gruppen['versicherung_steuer']['sub'] as $s) $cntVers += count($s['items']);
                echo $cntVers;
              ?>
            </td>
            <td class="num num-bold" style="color:#0ea5e9;">- CHF <?= number_format($totalVersicherungSteuer, 2, '.', "'") ?></td>
          </tr>
          <?php foreach ($gruppen['versicherung_steuer']['sub'] as $subKey => $sub): ?>
            <?php if (count($sub['items']) > 0 || $sub['total'] > 0): ?>
              <tr>
                <td style="padding-left:36px;">&bull; <?= htmlspecialchars($sub['title']) ?></td>
                <td class="num"><?= count($sub['items']) ?></td>
                <td class="num" style="color:#0ea5e9;">- CHF <?= number_format($sub['total'], 2, '.', "'") ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- 6. KAPITALKOSTEN & VERWALTUNG -->
          <tr class="row-main-header">
            <td>
              <span style="font-size:16px;">🏦</span> <strong>6. KAPITALKOSTEN &amp; VERWALTUNG</strong>
            </td>
            <td class="num">
              <?php 
                $cntFin = 0; 
                foreach ($gruppen['finanz_admin']['sub'] as $s) $cntFin += count($s['items']);
                echo $cntFin;
              ?>
            </td>
            <td class="num num-bold" style="color:#ec4899;">- CHF <?= number_format($totalFinanzAdmin, 2, '.', "'") ?></td>
          </tr>
          <?php foreach ($gruppen['finanz_admin']['sub'] as $subKey => $sub): ?>
            <?php if (count($sub['items']) > 0 || $sub['total'] > 0): ?>
              <tr>
                <td style="padding-left:36px;">&bull; <?= htmlspecialchars($sub['title']) ?></td>
                <td class="num"><?= count($sub['items']) ?></td>
                <td class="num" style="color:#ec4899;">- CHF <?= number_format($sub['total'], 2, '.', "'") ?></td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>

          <!-- GESAMTARENTABILITÄT & REINGEWINN -->
          <tr class="row-total" style="background:#eef2ff;">
            <td>
              <span style="font-size:16px;">⭐</span> <strong>NETTOERTRAG / REINGEWINN DER LIEGENSCHAFT</strong><br>
              <span style="font-size:11px; font-weight:400; color:#475569;">Total Erträge (CHF <?= number_format($totalErtrag, 2, '.', "'") ?>) abzüglich Gesamtaufwand (CHF <?= number_format($gesamtaufwandInklInvest, 2, '.', "'") ?>)</span>
            </td>
            <td class="num"><strong><?= count($buchungen) ?></strong></td>
            <td class="num num-bold" style="font-size:16px; color:<?= $nettoertragLiegenschaft >= 0 ? '#3730a3' : '#dc2626' ?>;">
              CHF <?= number_format($nettoertragLiegenschaft, 2, '.', "'") ?>
            </td>
          </tr>

          <!-- 7. EIGENTÜMER-VERKEHR -->
          <tr class="row-main-header">
            <td>
              <span style="font-size:16px;">💼</span> <strong>7. ABRECHNUNG MIT DEM EIGENTÜMER</strong>
            </td>
            <td class="num"><?= count($gruppen['eigentuemer']['sub']['auszahlung']['items']) + count($gruppen['eigentuemer']['sub']['einlage']['items']) ?></td>
            <td class="num num-bold" style="color:#2563eb;">- CHF <?= number_format($nettoEigentuemerEntnahmen, 2, '.', "'") ?></td>
          </tr>
          <tr>
            <td style="padding-left:36px;">&bull; Auszahlungen / Akonto-Entnahmen an Eigentümer</td>
            <td class="num"><?= count($gruppen['eigentuemer']['sub']['auszahlung']['items']) ?></td>
            <td class="num text-red">- CHF <?= number_format($totalAuszahlungEigentuemer, 2, '.', "'") ?></td>
          </tr>
          <?php if ($totalEinlagenEigentuemer > 0): ?>
            <tr>
              <td style="padding-left:36px;">&bull; Einlagen / Zuschüsse des Eigentümers</td>
              <td class="num"><?= count($gruppen['eigentuemer']['sub']['einlage']['items']) ?></td>
              <td class="num text-green">+ CHF <?= number_format($totalEinlagenEigentuemer, 2, '.', "'") ?></td>
            </tr>
          <?php endif; ?>

          <!-- ENDSALDO -->
          <tr class="row-total" style="background:#f8fafc; border-top:3px double #0f172a;">
            <td>
              <span style="font-size:18px;">🏁</span> <strong>SALDO ABRECHNUNGSPERIODE (Guthaben / Überschuss)</strong><br>
              <span style="font-size:11px; font-weight:400; color:#64748b;">
                <?= $saldoAbrechnung >= 0 ? '✅ Überschuss zu Gunsten des Eigentümers bzw. Liegenschaftskontos' : '⚠️ Netto-Unterdeckung / Nachzahlung' ?>
              </span>
            </td>
            <td></td>
            <td class="num num-bold" style="font-size:18px; color:<?= $saldoAbrechnung >= 0 ? '#166534' : '#dc2626' ?>;">
              CHF <?= number_format($saldoAbrechnung, 2, '.', "'") ?>
            </td>
          </tr>

        </tbody>
      </table>
    </div>

    <!-- DRUCK-UNTERSCHRIFTSFELD (Nur bei Print sichtbar) -->
    <div class="print-only" style="margin-top:40px; padding-top:20px; border-top:1px solid #ccc; font-size:11pt;">
      <div style="display:flex; justify-content:space-between; margin-top:40px;">
        <div style="width:40%;">
          <div>Ort, Datum: _______________________</div>
          <div style="margin-top:50px; border-top:1px dotted #000; padding-top:5px;">Liegenschaftsverwaltung / Bewirtschaftung</div>
        </div>
        <div style="width:40%;">
          <div>Geprüft und genehmigt:</div>
          <div style="margin-top:50px; border-top:1px dotted #000; padding-top:5px;">Eigentümer / Eigentümerin</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- TAB 2: SCHWEIZER STEUERDEKLARATION (LIEGENSCHAFTSKOSTEN) -->
  <!-- ============================================================= -->
  <div id="tab-steuer" class="la-tab-panel">
    <div class="la-tax-box">
      <div class="la-tax-title">
        <span>🏛️</span>
        <span>Schweizer Steuererklärung <?= $selYear ?>: Formular Liegenschaftskosten</span>
      </div>
      <p style="margin:0 0 16px 0; font-size:13.5px; color:#78350f;">
        Diese Werte können direkt in das kantonale Steuererklärungsformular (z. B. Formular <em>«Liegenschaftenverzeichnis» / «Liegenschaftsunterhalt»</em>) übertragen werden.
      </p>

      <div class="la-tax-grid">
        <div class="la-tax-item">
          <div class="la-tax-item-title">1. Steuerbare Mietzinseinnahmen</div>
          <div class="la-tax-item-val text-green">CHF <?= number_format($steuerErtrag, 2, '.', "'") ?></div>
          <div style="font-size:11px; color:#64748b; margin-top:4px;">Brutto-Mieterträge im Steuerjahr <?= $selYear ?></div>
        </div>

        <div class="la-tax-item">
          <div class="la-tax-item-title">2. Abzugsfähiger Liegenschaftsunterhalt</div>
          <div class="la-tax-item-val text-purple">CHF <?= number_format($steuerAbzugLiegenschaft, 2, '.', "'") ?></div>
          <div style="font-size:11px; color:#64748b; margin-top:4px;">Werterhaltend (Reparaturen, Service, Gebäudevers.)</div>
        </div>

        <div class="la-tax-item">
          <div class="la-tax-item-title">3. Schuldzinsen (Hypothek)</div>
          <div class="la-tax-item-val" style="color:#ec4899;">CHF <?= number_format($steuerSchuldzinsen, 2, '.', "'") ?></div>
          <div style="font-size:11px; color:#64748b; margin-top:4px;">Separater Abzug Ziffer Schulden/Schuldzinsen</div>
        </div>

        <div class="la-tax-item">
          <div class="la-tax-item-title">4. Steuerlicher Liegenschafts-Reinertrag</div>
          <div class="la-tax-item-val" style="color:#0f172a;">CHF <?= number_format($steuerErtrag - $steuerAbzugLiegenschaft, 2, '.', "'") ?></div>
          <div style="font-size:11px; color:#64748b; margin-top:4px;">Mietertrag minus abzugsfähiger Unterhalt</div>
        </div>
      </div>

      <!-- PAUSCHALE VS. EFFEKTIVER ABZUG VERGLEICH -->
      <div style="background:#ffffff; border:1px solid #fed7aa; border-radius:12px; padding:18px; margin-top:16px;">
        <div style="font-size:14px; font-weight:800; color:#9a3412; margin-bottom:10px;">
          ⚖️ Vergleich: Pauschalabzug vs. Effektiver Abzug (Kantonale Steueroptimierung)
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:14px; font-size:13px; margin-bottom:12px;">
          <div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
            <div style="color:#64748b; font-weight:700; font-size:11px;">Pauschale 10% (&lt; 10 Jahre Gebäudealter)</div>
            <div style="font-size:17px; font-weight:800; color:#0f172a; margin-top:2px;">CHF <?= number_format($pauschale10, 2, '.', "'") ?></div>
            <div style="font-size:11px; color:<?= $effektiverVorteil10 >= 0 ? '#16a34a' : '#dc2626' ?>; margin-top:2px; font-weight:600;">
              <?= $effektiverVorteil10 >= 0 ? 'Effektiv +CHF '.number_format($effektiverVorteil10, 2, '.', "'").' höher' : 'Pauschale +CHF '.number_format(abs($effektiverVorteil10), 2, '.', "'").' höher' ?>
            </div>
          </div>
          <div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
            <div style="color:#64748b; font-weight:700; font-size:11px;">Pauschale 20% (&ge; 10 Jahre Gebäudealter)</div>
            <div style="font-size:17px; font-weight:800; color:#0f172a; margin-top:2px;">CHF <?= number_format($pauschale20, 2, '.', "'") ?></div>
            <div style="font-size:11px; color:<?= $effektiverVorteil20 >= 0 ? '#16a34a' : '#dc2626' ?>; margin-top:2px; font-weight:600;">
              <?= $effektiverVorteil20 >= 0 ? 'Effektiv +CHF '.number_format($effektiverVorteil20, 2, '.', "'").' höher' : 'Pauschale +CHF '.number_format(abs($effektiverVorteil20), 2, '.', "'").' höher' ?>
            </div>
          </div>
          <div style="background:#f5f3ff; padding:12px; border-radius:8px; border:1px solid #ddd6fe;">
            <div style="color:#6d28d9; font-weight:700; font-size:11px;">Tatsächlich angefallene Unterhaltskosten</div>
            <div style="font-size:17px; font-weight:800; color:#5b21b6; margin-top:2px;">CHF <?= number_format($steuerAbzugLiegenschaft, 2, '.', "'") ?></div>
            <div style="font-size:11px; color:#6d28d9; margin-top:2px; font-weight:600;">Aus Buchhaltung nachgewiesen</div>
          </div>
        </div>

        <div class="la-tax-rec">
          <span style="font-size:18px;">💡</span>
          <div>
            <?php if ($steuerAbzugLiegenschaft > $pauschale20): ?>
              <strong>Steuerempfehlung:</strong> Deklarieren Sie den <strong>effektiven Abzug</strong>! Sie sparen gegenüber der 20%-Pauschale zusätzlich <strong>CHF <?= number_format($effektiverVorteil20, 2, '.', "'") ?></strong> an steuerbarem Einkommen.
            <?php else: ?>
              <strong>Steuerempfehlung:</strong> Prüfen Sie den <strong>Pauschalabzug</strong>! Die Pauschale übersteigt Ihre tatsächlichen Kosten, was steuerlich optimal genutzt werden kann.
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- TAB 3: DETAILLIERTES BUCHUNGSJOURNAL -->
  <!-- ============================================================= -->
  <div id="tab-journal" class="la-tab-panel">
    <div class="la-table-wrap">
      <div style="padding:14px 18px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
          <strong>Alle Buchungen im Rechnungsjahr <?= $selYear ?></strong> (<?= count($buchungen) ?> Einträge)
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
          <input type="text" id="journalSearch" placeholder="🔍 Suchen nach Text, Betrag, Wohnung..." style="padding:7px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; width:260px;" onkeyup="filterJournal()">
        </div>
      </div>

      <table class="la-table" id="journalTable">
        <thead>
          <tr>
            <th style="width:100px;">Datum</th>
            <th>Buchungstext &amp; Details</th>
            <th style="width:160px;">Einheit / Mieter</th>
            <th style="width:200px;">Kategorie (Deklaration)</th>
            <th class="num" style="width:130px;">Betrag</th>
            <th style="width:70px; text-align:center;" class="no-print">Aktion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($buchungen as $b): ?>
            <?php 
              $bVal = (float)$b['betrag'];
              $isIncome = $bVal > 0;
            ?>
            <tr class="journal-row" data-search="<?= strtolower(htmlspecialchars($b['beschreibung'] . ' ' . $b['kategorie'] . ' ' . $b['wohnung_name'] . ' ' . $b['betrag'])) ?>">
              <td style="font-family:monospace; font-size:12px; color:#475569;">
                <?= date('d.m.Y', strtotime($b['buchungsdatum'])) ?>
              </td>
              <td>
                <div style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($b['beschreibung']) ?></div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                  ID: #<?= $b['id'] ?> &bull; Zahlungsart: <?= htmlspecialchars($b['zahlungsart'] ?: 'Bank') ?>
                  <?php if (!empty($b['konto_nr'])): ?>
                    &bull; Absender: <span style="font-family:monospace;"><?= htmlspecialchars($b['konto_nr']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="font-size:13px;">
                <?php if (!empty($b['wohnung_name'])): ?>
                  <strong>🏠 <?= htmlspecialchars($b['wohnung_name']) ?></strong>
                <?php elseif (!empty($b['wohnung_label'])): ?>
                  <?= htmlspecialchars($b['wohnung_label']) ?>
                <?php else: ?>
                  <span style="color:#94a3b8;">—</span>
                <?php endif; ?>
                <?php if (!empty($b['mieter_name'])): ?>
                  <div style="font-size:11px; color:#64748b;">👤 <?= htmlspecialchars($b['mieter_name']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" style="margin:0; display:inline;" class="no-print" onchange="this.submit()">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_kategorie">
                  <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                  <select name="kategorie" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; width:100%; font-weight:600; background:#fff;">
                    <option value="Miete" <?= ($b['kategorie'] === 'Miete' || stripos($b['kategorie'], 'Mietzins') !== false) ? 'selected' : '' ?>>1. Mieteinnahmen (Mietzins)</option>
                    <option value="Nebenkosten" <?= $b['kategorie'] === 'Nebenkosten' ? 'selected' : '' ?>>2. Nebenkosten (EW, Strom, Wasser)</option>
                    <option value="Steuern" <?= $b['kategorie'] === 'Steuern' ? 'selected' : '' ?>>3. Steuern &amp; Abgaben</option>
                    <option value="Versicherung" <?= $b['kategorie'] === 'Versicherung' ? 'selected' : '' ?>>4. Versicherungen</option>
                    <option value="Hypothek / Bank" <?= ($b['kategorie'] === 'Hypothek / Bank' || stripos($b['kategorie'], 'Hypo') !== false) ? 'selected' : '' ?>>5. Hypothek &amp; Bankspesen</option>
                    <option value="Unterhalt & Reparaturen" <?= ($b['kategorie'] === 'Unterhalt & Reparaturen' || stripos($b['kategorie'], 'Unterhalt') !== false) ? 'selected' : '' ?>>6. Unterhalt &amp; Handwerker</option>
                    <option value="Investitionen" <?= $b['kategorie'] === 'Investitionen' ? 'selected' : '' ?>>7. Investitionen (Sanierung)</option>
                    <option value="Auszahlung Eigentümer" <?= ($b['kategorie'] === 'Auszahlung Eigentümer' || stripos($b['kategorie'], 'Eigentümer') !== false) ? 'selected' : '' ?>>8. Auszahlung Eigentümer</option>
                    <option value="Kaution" <?= $b['kategorie'] === 'Kaution' ? 'selected' : '' ?>>🛡️ Mietkaution / Depot</option>
                    <option value="Rückzahlung / Korrektur" <?= $b['kategorie'] === 'Rückzahlung / Korrektur' ? 'selected' : '' ?>>↩️ Rückzahlung / Korrektur</option>
                  </select>
                </form>
                <div class="print-only">
                  <?= htmlspecialchars($b['kategorie']) ?>
                </div>
              </td>
              <td class="num num-bold" style="color:<?= $isIncome ? '#16a34a' : '#dc2626' ?>;">
                <?= $isIncome ? '+' : '' ?>CHF <?= number_format($bVal, 2, '.', "'") ?>
              </td>
              <td style="text-align:center;" class="no-print">
                <a href="../konto_verwaltung/index.php?projekt_id=<?= $pid ?>&jahr=<?= $selYear ?>#b_<?= $b['id'] ?>" title="Im Liegenschaftskonto öffnen" style="text-decoration:none; font-size:14px;">
                  🔍
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- TAB 4: EIGENTÜMER-KONTO & AUSZAHLUNGEN -->
  <!-- ============================================================= -->
  <div id="tab-eigentuemer" class="la-tab-panel">
    <div class="la-table-wrap">
      <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <h3 style="margin:0 0 4px 0; font-size:16px; color:#0f172a;">💼 Eigentümer-Abrechnungskonto</h3>
        <p style="margin:0; font-size:13px; color:#64748b;">
          Aufstellung aller Auszahlungen, Privatbezüge und Einlagen für das Jahr <?= $selYear ?>
        </p>
      </div>

      <div style="padding:20px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-bottom:20px;">
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
            <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Nettoertrag Liegenschaft</div>
            <div style="font-size:22px; font-weight:800; color:#4338ca; margin-top:4px;">
              CHF <?= number_format($nettoertragLiegenschaft, 2, '.', "'") ?>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:4px;">Reingewinn vor Eigentümerentnahmen</div>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
            <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Geleistete Auszahlungen</div>
            <div style="font-size:22px; font-weight:800; color:#dc2626; margin-top:4px;">
              CHF <?= number_format($totalAuszahlungEigentuemer, 2, '.', "'") ?>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:4px;"><?= count($gruppen['eigentuemer']['sub']['auszahlung']['items']) ?> Auszahlungen an Eigentümer</div>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
            <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Abrechnungssaldo Periode</div>
            <div style="font-size:22px; font-weight:800; color:<?= $saldoAbrechnung >= 0 ? '#16a34a' : '#dc2626' ?>; margin-top:4px;">
              CHF <?= number_format($saldoAbrechnung, 2, '.', "'") ?>
            </div>
            <div style="font-size:11px; color:#64748b; margin-top:4px;">
              <?= $saldoAbrechnung >= 0 ? 'Überschuss auf Liegenschaftskonto' : 'Nachzahlung/Unterdeckung' ?>
            </div>
          </div>
        </div>

        <h4 style="font-size:14px; font-weight:800; color:#0f172a; margin:16px 0 10px 0;">Einzelne Eigentümer-Transaktionen:</h4>
        <table class="la-table" style="border:1px solid #e2e8f0; border-radius:8px;">
          <thead>
            <tr>
              <th style="width:120px;">Datum</th>
              <th>Buchungstext / Verwendungszweck</th>
              <th class="num" style="width:160px;">Betrag (CHF)</th>
            </tr>
          </thead>
          <tbody>
            <?php 
              $eigItems = array_merge(
                  $gruppen['eigentuemer']['sub']['auszahlung']['items'],
                  $gruppen['eigentuemer']['sub']['einlage']['items']
              );
            ?>
            <?php if (empty($eigItems)): ?>
              <tr>
                <td colspan="3" style="text-align:center; padding:24px; color:#94a3b8;">
                  Keine direkten Eigentümer-Entnahmen im Jahr <?= $selYear ?> erfasst.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($eigItems as $ei): ?>
                <tr>
                  <td style="font-family:monospace;"><?= date('d.m.Y', strtotime($ei['buchungsdatum'])) ?></td>
                  <td><?= htmlspecialchars($ei['beschreibung']) ?></td>
                  <td class="num num-bold" style="color:<?= (float)$ei['betrag'] < 0 ? '#dc2626' : '#16a34a' ?>;">
                    CHF <?= number_format(abs((float)$ei['betrag']), 2, '.', "'") ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
function switchTab(tabId, btn) {
  document.querySelectorAll('.la-tab-panel').forEach(function(el) {
    el.classList.remove('active');
  });
  document.querySelectorAll('.la-tab-btn').forEach(function(el) {
    el.classList.remove('active');
  });
  var target = document.getElementById(tabId);
  if (target) {
    target.classList.add('active');
  }
  if (btn) {
    btn.classList.add('active');
  }
}

function filterJournal() {
  var input = document.getElementById('journalSearch');
  var filter = input.value.toLowerCase().trim();
  var rows = document.querySelectorAll('.journal-row');
  rows.forEach(function(row) {
    var text = row.getAttribute('data-search') || '';
    if (filter === '' || text.indexOf(filter) !== -1) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
