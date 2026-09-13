<?php
$hash = '$2y$10$nw/AfU1M/FCKqlWVmNjBvecwVGn8qSMH0jiXdVOm.uuwtH85MuqDO';
$pass = 'Test1234.';
echo password_verify($pass, $hash) ? "OK" : "FAIL";
