<?php
// Fallback-Service wenn app/modules/pendenzen/Service.php nicht vorhanden ist
// Wird nur geladen wenn die echte Service-Klasse nicht gefunden wurde

namespace App\Modules\Pendenzen;

class Service {
    private \mysqli $db;
    private array $_tableCache = [];

    public function __construct(\mysqli $db) {
        $this->db = $db;
    }

    private function tableExists(string $table): bool {
        if (!isset($this->_tableCache[$table])) {
            $t = $this->db->real_escape_string($table);
            $res = $this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'");
            $this->_tableCache[$table] = ($res && $res->num_rows > 0);
        }
        return $this->_tableCache[$table];
    }

    private function columnExists(string $table, string $col): bool {
        $key = "$table.$col";
        if (!isset($this->_tableCache[$key])) {
            $t = $this->db->real_escape_string($table);
            $c = $this->db->real_escape_string($col);
            $res = $this->db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' AND column_name='$c'");
            $this->_tableCache[$key] = ($res && $res->num_rows > 0);
        }
        return $this->_tableCache[$key];
    }

    public function searchResult(array $f = []): \mysqli_result {
        $where = []; $args = []; $types = '';
        $uid    = (int)($f['owner_isolation_id'] ?? 0);
        $isAdmin = !empty($f['is_superadmin']);

        if ($uid > 0 && !$isAdmin) {
            $hasMitgl = $this->tableExists('projekt_mitglieder') ? 'projekt_mitglieder'
                      : ($this->tableExists('benutzer_projekte') ? 'benutzer_projekte' : null);
            if ($hasMitgl) {
                $where[] = "(p.erstellt_von = ? OR p.zustaendig_id = ? OR p.projekt_id IN (SELECT projekt_id FROM $hasMitgl WHERE benutzer_id = ?))";
                $types .= 'iii'; array_push($args, $uid, $uid, $uid);
            } else {
                $where[] = '(p.erstellt_von = ? OR p.zustaendig_id = ?)';
                $types .= 'ii'; array_push($args, $uid, $uid);
            }
        }
        if (!empty($f['projekt_id']))    { $where[]='p.projekt_id=?';   $types.='i'; $args[]=(int)$f['projekt_id']; }
        if (!empty($f['status']))        { $where[]='p.status=?';       $types.='s'; $args[]=$f['status']; }
        if (!empty($f['wohnung_id']))    { $where[]='p.wohnung_id=?';   $types.='i'; $args[]=(int)$f['wohnung_id']; }
        if (!empty($f['vorgangsart_id'])){ $where[]='p.vorgangsart_id=?'; $types.='i'; $args[]=(int)$f['vorgangsart_id']; }
        if (!empty($f['q'])) {
            $qq='%'.$f['q'].'%';
            $where[]='(p.titel LIKE ? OR p.kurzbeschreibung LIKE ?)';
            $types.='ss'; array_push($args,$qq,$qq);
        }

        $ws  = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $lim = max(1,(int)($f['limit']??100));
        $off = max(0,(int)($f['offset']??0));

        $hasDat  = $this->tableExists('pendenz_dateien');
        $hasKat  = $this->tableExists('pendenz_kategorien');
        $hasArt  = $this->tableExists('pendenzen_arten');
        $hasRaum = $this->tableExists('raeume');
        $hasFin  = $this->tableExists('finanzen_konto');
        $hasBkp  = $this->columnExists('pendenzen', 'bkp_id');
        $hasVorg = $this->columnExists('pendenzen', 'vorgangsart_id');
        $hasRaumCol = $this->columnExists('pendenzen', 'raum_id');

        $imgSql  = $hasDat ? "(SELECT d.pfad FROM pendenz_dateien d WHERE d.pendenz_id=p.id AND d.typ='image' ORDER BY d.is_cover DESC,d.id LIMIT 1)" : "NULL";
        $bilderSql = $hasDat ? "(SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id=p.id AND d.typ='image')" : "0";
        $anhSql    = $hasDat ? "(SELECT COUNT(*) FROM pendenz_dateien d WHERE d.pendenz_id=p.id AND d.typ<>'image')" : "0";
        $balSql    = $hasFin ? "(SELECT SUM(fk.soll-fk.haben) FROM finanzen_konto fk WHERE fk.wohnung_id=p.wohnung_id)" : "NULL";
        $katCol    = $hasKat ? "pk.name" : "NULL";
        $artCol    = ($hasArt && $hasVorg) ? "pa.name" : "NULL";
        $raumCol   = ($hasRaum && $hasRaumCol) ? "r.name" : "NULL";
        $katJoin   = $hasKat ? "LEFT JOIN pendenz_kategorien pk ON pk.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(p.extra_json,'$.kategorie_id')) AS UNSIGNED)" : "";
        $artJoin   = ($hasArt && $hasVorg) ? "LEFT JOIN pendenzen_arten pa ON pa.id=p.vorgangsart_id" : "";
        $raumJoin  = ($hasRaum && $hasRaumCol) ? "LEFT JOIN raeume r ON r.id=p.raum_id" : "";

        $sql = "
          SELECT p.*, pr.name AS projekt_name, w.name AS wohnung_name,
            $katCol AS kategorie_name, b.name AS zustaendig_name,
            $artCol AS art_name, $raumCol AS raum_name,
            $imgSql AS erstes_bild,
            $bilderSql AS bilder_count,
            $anhSql AS anhaenge_count,
            $balSql AS balance,
            p.id AS _pid_dummy
          FROM pendenzen p
          LEFT JOIN projekte pr ON pr.id=p.projekt_id
          LEFT JOIN wohnungen w ON w.id=p.wohnung_id
          LEFT JOIN benutzer b ON b.id=p.zustaendig_id
          $katJoin $artJoin $raumJoin
          $ws
          ORDER BY p.id DESC
          LIMIT ? OFFSET ?
        ";
        $types .= 'ii'; $args[] = $lim; $args[] = $off;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function searchCount(array $f = []): int { return 0; }
    public function list(array $opts = []): array { return []; }
    public function find(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM pendenzen WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
    public function create(array $d): int { return 0; }
}
