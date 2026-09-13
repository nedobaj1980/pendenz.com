<?php
require '../config.php';
$stmtA = $mysqli->prepare("INSERT INTO pendenz_dateien (pendenz_id, pfad, typ, mimetype, is_cover) VALUES (110, 'uploads/protocols/Foto_test.jpg', 'image', 'image/jpeg', 1)");
if ($stmtA) {
    if($stmtA->execute()) {
        echo 'OK: Inserted.';
    } else {
        echo 'Exec Err: ' . $mysqli->error;
    }
} else {
    echo 'Prep Err: ' . $mysqli->error;
}
