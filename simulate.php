<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

/**
 * Nur Superadmin darf Simulation starten/ändern.
 * (Wir prüfen die echte Rolle aus der Session, nicht die simulierte.)
 */
$realRole = $_SESSION['rolle'] ?? 'gast';
if ($realRole !== 'superadmin') {
    http_response_code(403);
    echo "Simulation nur für Superadmin erlaubt.";
    exit;
}

/** Ziel-URL nach Aktion (Referrer oder Fallback) */
$redirect = $_SERVER['HTTP_REFERER'] ?? "http://localhost/pendenz.com/index_superadmin.php";

/** Reset? */
if (isset($_GET['reset'])) {
    unset($_SESSION['simulate_role'], $_SESSION['simulate_user_id'], $_SESSION['simulate_name']);
    header("Location: $redirect");
    exit;
}

/**
 * Simulation per Rolle: ?role=gast|benutzer|admin
 */
if (isset($_GET['role'])) {
    $role = strtolower(trim($_GET['role']));
    $allowed = ['gast','benutzer','admin'];
    if (!in_array($role, $allowed, true)) {
        echo "Ungültige Rolle. Erlaubt: gast, benutzer, admin.";
        exit;
    }
    $_SESSION['simulate_role']    = $role;
    $_SESSION['simulate_name']    = strtoupper($role);

    // Reale IDs zuweisen für stabilere DB-Interaktion
    if ($role === 'admin') $_SESSION['simulate_user_id'] = 15; // Admin Demo
    elseif ($role === 'benutzer') $_SESSION['simulate_user_id'] = 16; // Nedo User
    else $_SESSION['simulate_user_id'] = null; // Gast

    header("Location: $redirect");
    exit;
}

/**
 * Simulation per user_id oder email:
 *   ?user_id=42
 *   ?email=foo@bar.tld
 */
$targetUser = null;
if (isset($_GET['user_id'])) {
    $id = (int)$_GET['user_id'];
    $stmt = $mysqli->prepare("SELECT id, name, rolle FROM benutzer WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $targetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} elseif (isset($_GET['email'])) {
    $email = trim($_GET['email']);
    $stmt = $mysqli->prepare("SELECT id, name, rolle FROM benutzer WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $targetUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if ($targetUser) {
    $_SESSION['simulate_role']    = $targetUser['rolle'] ?? 'benutzer';
    $_SESSION['simulate_user_id'] = (int)$targetUser['id'];
    $_SESSION['simulate_name']    = $targetUser['name'] ?? ('User#'.$targetUser['id']);
    header("Location: $redirect");
    exit;
}

echo "Keine Aktion ausgeführt. Verwende z. B. ?role=admin | ?role=benutzer | ?role=gast | ?user_id=42 | ?email=…";
