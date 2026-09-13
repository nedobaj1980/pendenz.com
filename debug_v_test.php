<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';

$output = [];

$vKatOrder = (columnExists($mysqli, 'pendenz_kategorien_vermieter', 'sortierung') ? 'sortierung ASC, ' : '') . (columnExists($mysqli, 'pendenz_kategorien_vermieter', 'sort_order') ? 'sort_order ASC, ' : '') . 'name ASC';

if (!function_exists('selectExistingColumn')) {
function selectExistingColumn(mysqli $db, string $table, string $column, string $defaultSql = 'NULL', ?string $alias = null): string
{
    $alias = $alias ?: $column;
    if (columnExists($db, $table, $column)) {
        return '`' . $column . '`';
    }
    return $defaultSql . ' AS `' . $alias . '`';
}
}

$vKatProj = selectExistingColumn($mysqli, 'pendenz_kategorien_vermieter', 'projekt_id', 'NULL');
$vKatObj = selectExistingColumn($mysqli, 'pendenz_kategorien_vermieter', 'objekt_id', 'NULL');
$sql = "SELECT id, name, {$vKatProj}, {$vKatObj} FROM pendenz_kategorien_vermieter WHERE aktiv = 1 ORDER BY {$vKatOrder}";
$res = $mysqli->query($sql);
if (!$res) {
    $output['error'] = $mysqli->error;
    $output['sql'] = $sql;
} else {
    $output['data'] = [];
    while($r = $res->fetch_assoc()) $output['data'][] = $r;
}

file_put_contents(__DIR__ . '/scratch/debug_vermieter.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Done.";
