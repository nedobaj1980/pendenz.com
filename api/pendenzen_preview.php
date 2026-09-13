<?php
require_once __DIR__ . '/_bootstrap.php';

require_superadmin_json();

api_try(function () {
  $db = db();

  $in     = json_decode(file_get_contents('php://input'), true) ?: [];
  $preset = $in['preset'] ?? ($_GET['preset'] ?? 'offen');

  if (!table_exists($db, 'pendenzen')) {
    json_response(['ok' => true, 'rows' => []]);
  }

  // Hilfsfunktion: erste existierende Spalte aus Kandidatenliste zurückgeben
  $pick = function (array $cands) use ($db) {
    foreach ($cands as $c) {
      if (col_exists($db, 'pendenzen', $c)) return $c;
    }
    return null;
  };

  // Spalten dynamisch ermitteln (mit sinnvollen Synonymen)
  $titelCol  = $pick(['titel','title','name','beschreibung','beschreibung_kurz','betreff','subject','aufgabe','task']);
  $statusCol = $pick(['status','state','zustand']);
  $prioCol   = $pick(['prioritaet','prio','priority']);
  $dueCol    = $pick(['faellig_am','faelligkeit','due_date','deadline','faellig_bis']);

  // WHERE je nach Preset – nur filtern, wenn Spalte existiert
  $where = "1=1";
  switch ($preset) {
    case 'prio_hoch':
      if ($prioCol) {
        $where = "$prioCol IN ('hoch','high','prio_high','Hoch','HIGH')";
      } elseif ($statusCol) {
        $where = "$statusCol IN ('in_arbeit','wartend','open','todo')";
      }
      break;

    case 'ueberfaellig':
      if ($dueCol && $statusCol) {
        $where = "$statusCol <> 'erledigt' AND $dueCol < CURDATE()";
      } elseif ($dueCol) {
        $where = "$dueCol < CURDATE()";
      }
      break;

    case 'alle':
      $where = "1=1";
      break;

    default: // 'offen'
      if ($statusCol) {
        $where = "$statusCol IN ('offen','in_arbeit','wartend','open','todo')";
      }
  }

  // ORDER: nimm die erste vorhandene Sortierspalte
  $orderCol = 'id';
  foreach (['aktualisiert_am','updated_at','faellig_am','due_date','erstellt_am','created_at','sort_index','id'] as $c) {
    if (col_exists($db, 'pendenzen', $c)) { $orderCol = $c; break; }
  }

  $selectTitel  = $titelCol  ? "COALESCE($titelCol,'—')"               : "'—'";
  $selectStatus = $statusCol ? "COALESCE($statusCol,'—')"              : "'—'";
  $selectPrio   = $prioCol   ? "COALESCE($prioCol,'—')"                : "'—'";
  $selectDue    = $dueCol    ? "DATE_FORMAT($dueCol,'%Y-%m-%d')"       : "'—'";
  $selectCreated = col_exists($db, 'pendenzen', 'erstellt_am') ? 'erstellt_am' : "'—'";
  $selectUpdated = col_exists($db, 'pendenzen', 'aktualisiert_am') ? 'aktualisiert_am' : "'—'";

  $limit = ($preset === 'alle') ? 2000 : 50;

  $sql = "SELECT p.id,
                 $selectTitel  AS titel,
                 $selectStatus AS status,
                 $selectPrio   AS prioritaet,
                 $selectDue    AS faellig_am,
                 $selectCreated AS erstellt_am,
                 $selectUpdated AS aktualisiert_am,
                 p.kurzbeschreibung,
                 p.notiz,
                 b.name AS zustaendig_name,
                 pr.name AS projekt_name,
                 o.name AS objekt_name,
                 w.name AS wohnung_name,
                 img.pfad AS cover_pfad
          FROM pendenzen p
          LEFT JOIN benutzer b ON b.id = p.zustaendig_id
          LEFT JOIN projekte pr ON pr.id = p.projekt_id
          LEFT JOIN objekte o ON o.id = p.objekt_id
          LEFT JOIN wohnungen w ON w.id = p.wohnung_id
          LEFT JOIN (SELECT pendenz_id, pfad FROM pendenz_dateien WHERE typ='image' AND is_cover=1 GROUP BY pendenz_id) img ON img.pendenz_id = p.id
          WHERE $where
          ORDER BY p.$orderCol DESC
          LIMIT $limit";

  $rows = [];
  $res = $db->query($sql);
  while ($row = $res->fetch_assoc()) $rows[] = $row;
  $res->free();

  json_response([
    'ok'   => true,
    'rows' => $rows,
    'meta' => [
      'used_cols' => compact('titelCol','statusCol','prioCol','dueCol','orderCol')
    ]
  ]);
});
