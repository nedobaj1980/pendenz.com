<?php
require 'config.php';
$data = ['userFirmaBkpMap' => []];
$res = $mysqli->query("SELECT fu.user_id, fvm.ref_id AS bkp_id FROM firma_user fu INNER JOIN firmen_vorlagen_map fvm ON fvm.firma_id = fu.firma_id AND fvm.welt = 'bkp' WHERE fu.user_id IS NOT NULL");
if ($res) {
    $tmpMap = [];
    while ($row = $res->fetch_assoc()) {
        $uid = (int) ($row['user_id'] ?? 0);
        $bid = (int) ($row['bkp_id'] ?? 0);
        if ($uid > 0 && $bid > 0) {
            if (!isset($tmpMap[$uid])) $tmpMap[$uid] = [];
            $tmpMap[$uid][$bid] = $bid;
        }
    }
    foreach ($tmpMap as $uid => $ids) {
        $data['userFirmaBkpMap'][(string)$uid] = array_values($ids);
    }
}
var_dump(json_encode($data['userFirmaBkpMap']));
?>
