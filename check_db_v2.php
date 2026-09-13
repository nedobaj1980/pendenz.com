<?php
$conn = mysqli_connect('localhost', 'root', '', 'pendenz_com');
if (!$conn) {
    echo "Connection failed: " . mysqli_connect_error();
    exit;
}
$res = mysqli_query($conn, "DESCRIBE pendenzen");
while($row = mysqli_fetch_assoc($res)){
    if($row['Field'] == 'vorgaenger_id') {
        echo "vorgaenger_id | " . $row['Type'] . "\n";
    }
}
mysqli_close($conn);
