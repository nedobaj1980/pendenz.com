<?php
// api/pendenzen_list.php
// Liefert Pendenzen als JSON (für AJAX-Listen/Filter/Pagination)

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/bootstrap.php';        // stellt $mysqli + auth bereit
  require_once __DIR__ . '/../includes/auth.php'; // require_login()
  require_login();

  // ---------- kleine Helfer ----------
  $json = fn($arr) => print json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $bad  = fn($msg, $code=400) => (http_response_code($code) && $json(['ok'=>false,'error'=>$msg]));

  // Spalten aus information_schema holen (für Sort-Whitelist & Defaults)
  $cols = [];
  $stmt = $mysqli->prepare(
    "SELECT column_name FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'pendenzen' ORDER BY ordinal_position"
  );
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $cols[] = $r['column_name'];
  $stmt->close();

  $has = fn(string $c): bool => in_array($c, $cols, true);
  $sortDefault = $has('enddatum') ? 'enddatum' : ($has('created_at') ? 'created_at' : 'id');

  // ---------- Eingaben ----------
  $q          = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $status     = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
  $projekt_id = isset($_GET['projekt_id']) && $_GET['projekt_id'] !== '' ? (int)$_GET['projekt_id'] : null;
  $von        = isset($_GET['von']) ? trim((string)$_GET['von']) : '';
  $bis        = isset($_GET['bis']) ? trim((string)$_GET['bis']) : '';
  $only_open  = !empty($_GET['only_open']);

  $sort       = isset($_GET['sort']) ? trim((string)$_GET['sort']) : $sortDefault;
  $dirParam   = strtolower($_GET['dir'] ?? 'asc');
  $dirSql     = ($dirParam === 'desc') ? 'DESC' : 'ASC';

  $page     = max(1, (int)($_GET['page'] ?? 1));
  $per_page = max(5, min(200, (int)($_GET['per_page'] ?? 25)));
  $offset   = ($page - 1) * $per_page;

  // ---------- Whitelist & Queryteile ----------
  $sortable = array_flip(array_merge($cols, ['projekt_name','zustaendig_name', 'objekt_name', 'wohnung_name', 'raum_name']));

  $baseSql = "FROM pendenzen p
              LEFT JOIN projekte pr ON pr.id = p.projekt_id
              LEFT JOIN benutzer b  ON b.id = p.zustaendig_id
              LEFT JOIN objekte ob ON ob.id = p.objekt_id
              LEFT JOIN wohnungen wo ON wo.id = p.wohnung_id
              LEFT JOIN raeume rm ON rm.id = p.raum_id
              WHERE p.deleted_at IS NULL";

  $where   = [];
  $params  = [];
  $types   = "";

  if ($q !== '') {
    if ($has('beschreibung')) {
      $where[] = "(p.titel LIKE ? OR p.beschreibung LIKE ?)";
      $params[] = "%$q%"; $params[] = "%$q%"; $types .= "ss";
    } else {
      $where[] = "p.titel LIKE ?";
      $params[] = "%$q%"; $types .= "s";
    }
  }
  if ($status !== '') {
    $where[] = "p.status = ?";
    $params[] = $status; $types .= "s";
  }
  if (!empty($projekt_id)) {
    $where[] = "p.projekt_id = ?";
    $params[] = $projekt_id; $types .= "i";
  }
  if ($has('enddatum') && $von !== '') {
    $where[] = "(p.enddatum IS NOT NULL AND p.enddatum >= ?)";
    $params[] = $von; $types .= "s";
  }
  if ($has('enddatum') && $bis !== '') {
    $where[] = "(p.enddatum IS NOT NULL AND p.enddatum <= ?)";
    $params[] = $bis; $types .= "s";
  }
  if ($only_open) {
    $where[] = "p.status IN ('offen','in_bearbeitung','wartend')";
  }

  $whereSql = $where ? (" AND " . implode(" AND ", $where)) : "";

  if (!isset($sortable[$sort])) $sort = $sortDefault;
  if ($sort === 'enddatum' && $has('enddatum')) {
    $orderSql = " ORDER BY p.enddatum IS NULL, p.enddatum ASC, p.id DESC";
  } elseif ($sort === 'projekt_name') {
    $orderSql = " ORDER BY pr.name $dirSql, p.id DESC";
  } elseif ($sort === 'zustaendig_name') {
    $orderSql = " ORDER BY b.name $dirSql, p.id DESC";
  } elseif ($sort === 'objekt_name') {
    $orderSql = " ORDER BY ob.name $dirSql, p.id DESC";
  } elseif ($sort === 'wohnung_name') {
    $orderSql = " ORDER BY wo.name $dirSql, p.id DESC";
  } elseif ($sort === 'raum_name') {
    $orderSql = " ORDER BY rm.name $dirSql, p.id DESC";
  } else {
    $orderSql = " ORDER BY p.`" . str_replace('`','``',$sort) . "` $dirSql";
  }

  // ---------- Count ----------
  $countSql = "SELECT COUNT(*) ".$baseSql.$whereSql;
  $stmt = $mysqli->prepare($countSql);
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->bind_result($totalRows);
  $stmt->fetch();
  $stmt->close();

  $totalRows  = (int)$totalRows;
  $totalPages = max(1, (int)ceil($totalRows / $per_page));
  if ($page > $totalPages) { $page = $totalPages; $offset = ($page-1)*$per_page; }

  // ---------- Daten ----------
  $selectSql = "SELECT
                  p.*,
                  pr.name AS projekt_name,
                  b.name  AS zustaendig_name,
                  ob.name AS objekt_name,
                  wo.name AS wohnung_name,
                  rm.name AS raum_name
                ".$baseSql.$whereSql.$orderSql." LIMIT ? OFFSET ?";

  $bindTypes  = $types . "ii";
  $bindParams = $params; $bindParams[] = $per_page; $bindParams[] = $offset;

  $stmt = $mysqli->prepare($selectSql);
  $stmt->bind_param($bindTypes, ...$bindParams);
  $stmt->execute();
  $res  = $stmt->get_result();
  $rows = $res->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  // ---------- Ausgabe ----------
  $json([
    'ok'         => true,
    'page'       => $page,
    'per_page'   => $per_page,
    'total_rows' => $totalRows,
    'total_pages'=> $totalPages,
    'sort'       => $sort,
    'dir'        => $dirParam === 'desc' ? 'desc' : 'asc',
    'filters'    => [
      'q' => $q, 'status'=>$status, 'projekt_id'=>$projekt_id,
      'von'=>$von, 'bis'=>$bis, 'only_open'=>$only_open ? 1 : 0
    ],
    'rows'       => $rows
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()]);
}
