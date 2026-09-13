<?php
// smarttable_api.php – sauber, CSP-konform, nur JSON
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? '';

try {
    if ($action === 'list') {
        $table = $data['table'];
        $limit = intval($data['limit'] ?? 25);
        $offset = intval($data['offset'] ?? 0);
        $sort_by = $data['sort_by'] ?? 'id';
        $sort_dir = strtoupper($data['sort_dir'] ?? 'DESC');
        $search = $data['search'] ?? '';

        $where = [];
        $params = [];

        // Filter optional
        foreach (['status','projekt_id','zugewiesen_an'] as $f) {
            if (!empty($data[$f])) {
                $where[] = "$f = :$f";
                $params[":$f"] = $data[$f];
            }
        }

        if ($search) {
            $where[] = "(titel LIKE :s OR beschreibung LIKE :s)";
            $params[':s'] = "%$search%";
        }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM $table $where_sql");
        $stmt_total->execute($params);
        $total = $stmt_total->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM $table $where_sql ORDER BY $sort_by $sort_dir LIMIT :limit OFFSET :offset");
        foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
        $stmt->bindValue(':limit',$limit,PDO::PARAM_INT);
        $stmt->bindValue(':offset',$offset,PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok'=>true,'total'=>$total,'data'=>$rows]);
        exit;

    } elseif ($action === 'update') {
        $table = $data['table'];
        $id = intval($data['id']);
        $column = $data['column'];
        $value = $data['value'];

        $stmt = $pdo->prepare("UPDATE $table SET $column = :val WHERE id = :id");
        $stmt->execute([':val'=>$value, ':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;

    } elseif ($action === 'insert') {
        $table = $data['table'];
        $values = $data['data'] ?? [];
        $cols = implode(',', array_keys($values));
        $placeholders = ':' . implode(', :', array_keys($values));
        $stmt = $pdo->prepare("INSERT INTO $table ($cols) VALUES ($placeholders)");
        foreach($values as $k=>$v) $stmt->bindValue(":$k",$v);
        $stmt->execute();
        echo json_encode(['ok'=>true]);
        exit;

    } elseif ($action === 'delete') {
        $table = $data['table'];
        $id = intval($data['id']);
        $stmt = $pdo->prepare("DELETE FROM $table WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'Ungültige Aktion']);
} catch(Exception $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
