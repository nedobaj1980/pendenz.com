<?php
require_once __DIR__ . '/../config.php';
$res = $mysqli->query("SELECT erstellt_von FROM pendenzen WHERE id=87");
$p = $res->fetch_assoc();
echo "Creator ID: " . ($p['erstellt_von'] ?? 'NULL') . "<br>";

if ($p['erstellt_von']) {
    $res2 = $mysqli->query("SELECT firmenname, firmenlogo FROM benutzer WHERE id=" . $p['erstellt_von']);
    $u = $res2->fetch_assoc();
    echo "Firmenname: " . ($u['firmenname'] ?? 'empty') . "<br>";
    echo "Firmenlogo: " . ($u['firmenlogo'] ?? 'empty') . "<br>";
    if ($u['firmenlogo']) {
        $abs = realpath(__DIR__ . '/../' . ltrim($u['firmenlogo'], '/'));
        echo "Abs Path: " . ($abs ?: 'NOT FOUND') . "<br>";
    }
}
