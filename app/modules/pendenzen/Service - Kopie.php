<?php
namespace App\Modules\Pendenzen;

class Service {
  private \mysqli $db;

  public function __construct(\mysqli $db) {
    $this->db = $db;
  }

  /**
   * Liefert eine Array-Liste (assoc) – für Endpunkte/JSON etc.
   */
  public function list(array $opts = []): array {
    $order = 'p.created_at DESC';
    $limit = max(1, (int)($opts['limit'] ?? 100));

    $sql = "SELECT
              p.*,
              pr.name AS projekt_name,
              (SELECT d.pfad
                 FROM pendenz_dateien d
                WHERE d.pendenz_id = p.id AND d.typ = 'image'
                ORDER BY d.is_cover DESC, d.sort_index IS NULL, d.sort_index, d.id
                LIMIT 1) AS erstes_bild,
              (SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id = p.id AND d.typ = 'image')  AS bilder_count,
              (SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id = p.id AND d.typ <> 'image') AS anhaenge_count
            FROM pendenzen p
            LEFT JOIN projekte pr ON pr.id = p.projekt_id
            LEFT JOIN wohnungen w ON w.id = p.wohnung_id
            ORDER BY $order
            LIMIT ?";

    $stmt = $this->db->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
  }

  /**
   * Liefert ein mysqli_result – kompatibel mit deinem bestehenden while(fetch_assoc())-Rendering.
   */
  public function listResult(array $opts = []): \mysqli_result {
    $order = 'p.created_at DESC';
    $limit = max(1, (int)($opts['limit'] ?? 100));

    $sql = "SELECT
              p.*,
              pr.name AS projekt_name,
              (SELECT d.pfad
                 FROM pendenz_dateien d
                WHERE d.pendenz_id = p.id AND d.typ = 'image'
                ORDER BY d.is_cover DESC, d.sort_index IS NULL, d.sort_index, d.id
                LIMIT 1) AS erstes_bild,
              (SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id = p.id AND d.typ = 'image')  AS bilder_count,
              (SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id = p.id AND d.typ <> 'image') AS anhaenge_count
            FROM pendenzen p
            LEFT JOIN projekte pr ON pr.id = p.projekt_id
            LEFT JOIN wohnungen w ON w.id = p.wohnung_id
            ORDER BY $order
            LIMIT ?";

    $stmt = $this->db->prepare($sql);
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    return $stmt->get_result();
  }

  /**
   * Suche/Filter/Sort + Paging – liefert mysqli_result fürs Grid.
   */
  public function searchResult(array $f = []): \mysqli_result {
    $where = [];
    $args  = [];
    $types = '';

    // ---- ROLE-BASED ISOLATION ---------------------------------------------
    if (!empty($f['owner_isolation_id']) && empty($f['is_superadmin'])) {
        $oid = (int)$f['owner_isolation_id'];
        
        // Fetch user context for query augmentation
        $u = $this->db->query("SELECT business_type, wohnung_id FROM benutzer WHERE id=$oid")->fetch_assoc();
        $uType = $u['business_type'] ?? 'standard';

        if ($uType === 'mieter') {
            $where[] = 'p.wohnung_id = ?';
            $types  .= 'i';
            $args[]  = (int)($u['wohnung_id'] ?? 0);
        } elseif ($uType === 'handwerker') {
            // Unternehmer: Nur Projekte sehen, in denen man als Unternehmer gelistet ist
            // UND falls dort ein BKP gesetzt ist, nur dieses BKP sehen
            $where[] = 'p.projekt_id IN (SELECT up.projekt_id FROM unternehmer_projekte up WHERE up.benutzer_id = ? AND (up.bkp_id IS NULL OR up.bkp_id = p.vorgangsart_id OR up.bkp_id = p.bkp_id))';
            $types  .= 'i';
            $args[]  = $oid;
        } else {
            // Standard: Eigene, Zuständige oder Projekt-Mitglied
            $where[] = '(p.erstellt_von = ? OR p.zustaendig_id = ? OR p.projekt_id IN (SELECT projekt_id FROM projekt_mitglieder WHERE benutzer_id = ?))';
            $types  .= 'iii';
            array_push($args, $oid, $oid, $oid);
        }
    }

    // ---- Filter -----------------------------------------------------------
    if (!empty($f['projekt_id'])) {
      $where[] = 'p.projekt_id = ?';
      $types  .= 'i';
      $args[]  = (int)$f['projekt_id'];
    }

    if (!empty($f['wohnung_id'])) {
      $where[] = 'p.wohnung_id = ?';
      $types  .= 'i';
      $args[]  = (int)$f['wohnung_id'];
    }

    if (!empty($f['objekt_id'])) {
      $where[] = 'COALESCE(p.objekt_id, w.objekt_id) = ?';
      $types  .= 'i';
      $args[]  = (int)$f['objekt_id'];
    }

    if (!empty($f['status'])) {
      $where[] = 'p.status = ?';
      $types  .= 's';
      $args[]  = (string)$f['status'];
    }

    if (!empty($f['kategorie_id'])) {
      $where[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.extra_json, '$.kategorie_id')) AS UNSIGNED) = ?";
      $types  .= 'i';
      $args[]  = (int)$f['kategorie_id'];
    }
    if (!empty($f['vorgangsart_id'])) {
      $where[] = "p.vorgangsart_id = ?";
      $types  .= 'i';
      $args[]  = (int)$f['vorgangsart_id'];
    }
    if (!empty($f['unterkategorie_id'])) {
      $where[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.extra_json, '$.unterkategorie_id')) AS UNSIGNED) = ?";
      $types  .= 'i';
      $args[]  = (int)$f['unterkategorie_id'];
    }
    if (!empty($f['q'])) {
      $q = '%'.(string)$f['q'].'%';
      $where[] = '(p.titel LIKE ? OR p.kurzbeschreibung LIKE ? OR p.langbeschreibung LIKE ? OR p.fs_rel_path LIKE ?)';
      $types  .= 'ssss';
      array_push($args, $q, $q, $q, $q);
    }

    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

 // ---- Sortierung -------------------------------------------------------
$orderKey = strtolower((string)($f['order'] ?? 'created_at'));
$dirIn    = strtolower((string)($f['dir'] ?? 'desc'));
$dir      = $dirIn === 'asc' ? 'ASC' : 'DESC';

switch ($orderKey) {
  case 'eigene':
    // Eigene Reihenfolge: NULLs zuletzt, dann sort_index, dann id
    $orderSql = "p.sort_index IS NULL, p.sort_index $dir, p.id $dir";
    break;

  case 'enddatum':
    // NULLs ans Ende, dann Datum
    $orderSql = "p.enddatum IS NULL, p.enddatum $dir, p.id $dir";
    break;

  case 'projekt_name':
    // Sortierung über JOIN-Spalte
    $orderSql = "pr.name $dir, p.id $dir";
    break;

  case 'status':
    $orderSql = "p.status $dir, p.id $dir";
    break;

  case 'titel':
    $orderSql = "p.titel $dir, p.id $dir";
    break;
    
  case 'wichtigkeit':
    $orderSql = "p.wichtigkeit $dir, p.id $dir";
    break;

  case 'objekt_name':
    $orderSql = "o.name $dir, p.id $dir";
    break;

  case 'wohnung_name':
    $orderSql = "w.name $dir, p.id $dir";
    break;

  case 'created_at':
  default:
    $orderSql = "p.created_at $dir, p.id $dir";
    break;
}

// ---- Paging -----------------------------------------------------------
$limit  = max(1, (int)($f['limit']  ?? 200));
$offset = max(0, (int)($f['offset'] ?? 0));


    // ---- Query ------------------------------------------------------------
$sql = "
  SELECT
    p.*,
    pr.name AS projekt_name,
    o.name AS objekt_name,
    w.name AS wohnung_name,
    pk.name AS kategorie_name,
    b.name AS zustaendig_name,
    pa.name AS art_name,
    r.name AS raum_name,
    (SELECT d.pfad FROM pendenz_dateien d
      WHERE d.pendenz_id = p.id AND d.typ='image'
      ORDER BY d.is_cover DESC, d.sort_index IS NULL, d.sort_index, d.id
      LIMIT 1) AS erstes_bild,
    (SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id = p.id AND d.typ = 'image')  AS bilder_count,
    (SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id = p.id AND d.typ <> 'image') AS anhaenge_count,
    (SELECT SUM(fk.soll - fk.haben) FROM finanzen_konto fk WHERE fk.wohnung_id = p.wohnung_id) AS balance
  FROM pendenzen p
  LEFT JOIN projekte pr ON pr.id = p.projekt_id
  LEFT JOIN wohnungen w ON w.id = p.wohnung_id
  LEFT JOIN objekte o ON o.id = COALESCE(p.objekt_id, w.objekt_id)
  LEFT JOIN pendenz_kategorien pk ON pk.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(p.extra_json, '$.kategorie_id')) AS UNSIGNED)
  LEFT JOIN benutzer b ON b.id = p.zustaendig_id
  LEFT JOIN pendenzen_arten pa ON pa.id = p.vorgangsart_id
  LEFT JOIN raeume r ON r.id = p.raum_id
  $whereSql
  ORDER BY $orderSql
  LIMIT ? OFFSET ?
";


    // ---- Statement --------------------------------------------------------
    $stmt = $this->db->prepare($sql);
    // Limit/Offset anhängen
    $types .= 'ii';
    $args[]  = $limit;
    $args[]  = $offset;

    // Dynamisch binden
    $stmt->bind_param($types, ...$args);
    $stmt->execute();
    return $stmt->get_result();
  }

  public function searchCount(array $f = []): int {
    $where = [];
    $args  = [];
    $types = '';

    if (!empty($f['owner_isolation_id']) && empty($f['is_superadmin'])) {
        $oid = (int)$f['owner_isolation_id'];
        $where[] = '(p.erstellt_von = ? OR p.zustaendig_id = ? OR p.projekt_id IN (SELECT projekt_id FROM projekt_mitglieder WHERE benutzer_id = ?))';
        $types  .= 'iii';
        array_push($args, $oid, $oid, $oid);
    }

    if (!empty($f['projekt_id'])) {
      $where[] = 'p.projekt_id = ?'; $types.='i'; $args[] = (int)$f['projekt_id'];
    }
    if (!empty($f['status'])) {
      $where[] = 'p.status = ?'; $types.='s'; $args[] = (string)$f['status'];
    }
    if (!empty($f['kategorie_id'])) {
      $where[] = "CAST(JSON_UNQUOTE(JSON_EXTRACT(p.extra_json, '$.kategorie_id')) AS UNSIGNED) = ?";
      $types .= 'i'; $args[] = (int)$f['kategorie_id'];
    }
    if (!empty($f['q'])) {
      $q = '%'.(string)$f['q'].'%';
      $where[] = '(p.titel LIKE ? OR p.kurzbeschreibung LIKE ? OR p.langbeschreibung LIKE ? OR p.fs_rel_path LIKE ?)';
      $types.='ssss'; array_push($args, $q,$q,$q,$q);
    }

    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    $sql = "SELECT COUNT(*) AS cnt FROM pendenzen p $whereSql";

    $stmt = $this->db->prepare($sql);
    if ($types !== '') {
      $stmt->bind_param($types, ...$args);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (int)($row['cnt'] ?? 0);
  }

  public function create(array $d): int {
    $titel = trim($d['titel'] ?? '');
    if ($titel === '') {
      throw new \InvalidArgumentException('Titel ist erforderlich.');
    }
    $beschreibung = trim($d['beschreibung'] ?? '');
    $projekt_id   = (int)($d['projekt_id'] ?? 0);

    $sql = "INSERT INTO pendenzen (titel, beschreibung, projekt_id, status, created_at)
            VALUES (?, ?, ?, 'neu', NOW())";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param('ssi', $titel, $beschreibung, $projekt_id);
    $stmt->execute();
    return (int)$this->db->insert_id;
  }

  /** Detail für Show-View */
  public function find(int $id): ?array {
    $stmt = $this->db->prepare("SELECT * FROM pendenzen WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res ?: null;
  }
}
