<?php
require 'config.php';
try {
  $mysqli->query("SELECT id, name FROM objekte WHERE projekt_id=4");
  echo "objekte ok\n";
} catch(Exception $e) { echo "objekte error: " . $e->getMessage() . "\n"; }

try {
  $mysqli->query("SELECT id, name FROM wohnungen WHERE projekt_id=4");
  echo "wohnungen ok\n";
} catch(Exception $e) { echo "wohnungen error: " . $e->getMessage() . "\n"; }
