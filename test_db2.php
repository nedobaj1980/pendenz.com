<?php
require 'config.php';
try {
  $mysqli->query("SELECT id FROM projekte WHERE deleted_at IS NULL");
  echo "projekte ok\n";
} catch(Exception $e) { echo "projekte error: " . $e->getMessage() . "\n"; }
