<?php
/**
 * ics.php – ICS-Kalender-Download für einen Workshop
 *
 * Route: /w/{id}/ical
 * Liest aus /api/workshops.json (0 Notion-API-Calls).
 * Gibt eine RFC 5545 konforme .ics Datei zurück.
 */
require_once __DIR__ . '/../config/bootstrap.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    http_response_code(400);
    exit('Ungültiger Workshop-Link');
}

// Workshop aus statischem JSON laden
$jsonFile = __DIR__ . '/api/workshops.json';
if (!file_exists($jsonFile)) {
    http_response_code(503);
    exit('Programmdaten nicht verfügbar.');
}

$data = json_decode(file_get_contents($jsonFile), true);
$workshop = null;
foreach ($data['workshops'] ?? [] as $ws) {
    if ($ws['id'] === $id) {
        $workshop = $ws;
        break;
    }
}

if (!$workshop) {
    http_response_code(404);
    exit('Workshop nicht gefunden.');
}

// ── Datum parsen ──────────────────────────────────────────
$dtStart = null;
$dtEnd = null;

if (!empty($workshop['datum_start'])) {
    try {
        $start = new DateTime($workshop['datum_start']);
        $start->setTimezone(new DateTimeZone('Europe/Berlin'));
        $dtStart = $start->format('Ymd\THis');

        // Dauer aus Uhrzeit ableiten (z.B. "10:00 - 10:45 Uhr")
        if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $workshop['zeit'] ?? '', $m)) {
            $endParts = explode(':', $m[2]);
            $end = clone $start;
            $end->setTime((int)$endParts[0], (int)$endParts[1]);
            $dtEnd = $end->format('Ymd\THis');
        } else {
            // Fallback: 45 Minuten
            $end = clone $start;
            $end->modify('+45 minutes');
            $dtEnd = $end->format('Ymd\THis');
        }
    } catch (Exception $e) {
        // Fallback-Datum
    }
}

// Wenn kein Datum, versuche aus Tag + Uhrzeit zu konstruieren
if (!$dtStart) {
    $dayMap = [
        'Freitag' => '20260710',
        'Samstag' => '20260711',
        'Sonntag' => '20260712',
    ];
    $dayStr = $dayMap[$workshop['tag'] ?? ''] ?? '20260710';

    if (preg_match('/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $workshop['zeit'] ?? '', $m)) {
        $dtStart = $dayStr . 'T' . sprintf('%02d%02d00', $m[1], $m[2]);
        $dtEnd   = $dayStr . 'T' . sprintf('%02d%02d00', $m[3], $m[4]);
    } else {
        $dtStart = $dayStr . 'T100000';
        $dtEnd   = $dayStr . 'T104500';
    }
}

// ── ICS generieren ────────────────────────────────────────
$title = str_replace(["\r", "\n", ",", ";"], ['', '', '\\,', '\\;'], $workshop['title'] ?? 'Workshop');
$location = str_replace(["\r", "\n", ",", ";"], ['', '', '\\,', '\\;'], $workshop['ort'] ?? 'Messe Friedrichshafen');
$description = str_replace(["\r", "\n", ",", ";"], ['', ' ', '\\,', '\\;'],
    mb_substr(strip_tags($workshop['beschreibung'] ?? ''), 0, 300));
$url = 'https://agenda.adventuresouthside.com/w/' . $id;
$uid = $id . '@agenda.adventuresouthside.com';
$stamp = gmdate('Ymd\THis\Z');

$ics = "BEGIN:VCALENDAR\r\n";
$ics .= "VERSION:2.0\r\n";
$ics .= "PRODID:-//Academy Live//Adventure Southside 2026//DE\r\n";
$ics .= "CALSCALE:GREGORIAN\r\n";
$ics .= "METHOD:PUBLISH\r\n";
$ics .= "BEGIN:VEVENT\r\n";
$ics .= "UID:{$uid}\r\n";
$ics .= "DTSTAMP:{$stamp}\r\n";
$ics .= "DTSTART;TZID=Europe/Berlin:{$dtStart}\r\n";
$ics .= "DTEND;TZID=Europe/Berlin:{$dtEnd}\r\n";
$ics .= "SUMMARY:{$title}\r\n";
$ics .= "LOCATION:{$location} – Messe Friedrichshafen\r\n";
$ics .= "DESCRIPTION:{$description}\\n\\nDetails: {$url}\r\n";
$ics .= "URL:{$url}\r\n";
$ics .= "STATUS:CONFIRMED\r\n";
$ics .= "END:VEVENT\r\n";
$ics .= "END:VCALENDAR\r\n";

// ── Output ────────────────────────────────────────────────
$filename = preg_replace('/[^a-z0-9_-]/i', '-', $workshop['title'] ?? 'workshop');
$filename = preg_replace('/-+/', '-', trim($filename, '-'));

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.ics"');
header('Cache-Control: no-cache');
echo $ics;
