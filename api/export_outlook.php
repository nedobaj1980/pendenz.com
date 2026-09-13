<?php
// api/export_outlook.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'event'; // 'event' or 'task'

if ($id <= 0) die("Invalid ID.");

$res = $mysqli->query("SELECT p.*, pr.name AS projekt_name FROM pendenzen p LEFT JOIN projekte pr ON p.projekt_id = pr.id WHERE p.id = $id");
$row = $res ? $res->fetch_assoc() : null;

if (!$row) die("Task not found.");

$summary = "[" . $row['projekt_name'] . "] " . $row['titel'];
$description = $row['kurzbeschreibung'] . "\n\n" . $row['langbeschreibung'];
$start = $row['startdatum'] ?: date('Y-m-d');
$end = $row['enddatum'] ?: $start;

// Clean description
$description = str_replace("\r", "", $description);
$description = str_replace("\n", "\\n", $description);

// Times
$dtStart = date('Ymd\THis', strtotime($start . " 09:00:00"));
$dtEnd   = date('Ymd\THis', strtotime($end . " 17:00:00"));
$dtStamp = date('Ymd\THis');

// Filename
$filename = "aufgabe_" . $id . ".ics";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//pendenz.com//NONSGML v1.0//EN\r\n";

if ($type === 'task') {
    echo "BEGIN:VTODO\r\n";
    echo "UID:task-$id@pendenz.com\r\n";
    echo "DTSTAMP:$dtStamp\r\n";
    echo "DUE:$dtEnd\r\n";
    echo "SUMMARY:" . escape_ics($summary) . "\r\n";
    echo "DESCRIPTION:" . escape_ics($description) . "\r\n";
    echo "STATUS:" . ($row['status'] === 'erledigt' ? 'COMPLETED' : 'NEEDS-ACTION') . "\r\n";
    echo "END:VTODO\r\n";
} else {
    echo "BEGIN:VEVENT\r\n";
    echo "UID:event-$id@pendenz.com\r\n";
    echo "DTSTAMP:$dtStamp\r\n";
    echo "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($start)) . "\r\n";
    echo "DTEND;VALUE=DATE:" . date('Ymd', strtotime($end . " + 1 day")) . "\r\n";
    echo "SUMMARY:" . escape_ics($summary) . "\r\n";
    echo "DESCRIPTION:" . escape_ics($description) . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";

function escape_ics($string) {
    return preg_replace('/([\,;])/','\\\$1', $string);
}
?>
