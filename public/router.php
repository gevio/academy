<?php
/**
 * router.php – Front-Controller für /w/{id}[/action] URLs
 *
 * Nginx übergibt:
 *   WORKSHOP_ID     = 32-stellige Hex-ID
 *   WORKSHOP_ACTION = /feedback, /qa, /wall, /details oder leer
 *
 * Legacy-Redirect: /index.php?id=X  → /w/X (301)
 */

// ── Legacy-Redirect ──────────────────────────────────────
// Falls jemand noch die alten URLs aufruft
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'router.php'
    && isset($_GET['id'])
    && preg_match('/^[a-f0-9]{32}$/', $_GET['id'])) {
    header('Location: /w/' . $_GET['id'], true, 301);
    exit;
}

// ── Workshop-ID aus Nginx fastcgi_param ──────────────────
$workshopId = $_SERVER['WORKSHOP_ID'] ?? '';
$action     = trim($_SERVER['WORKSHOP_ACTION'] ?? '', '/');

if (!preg_match('/^[a-f0-9]{32}$/', $workshopId)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head>';
    echo '<body><h1>Ungültiger Workshop-Link</h1><p>Bitte scanne den QR-Code erneut.</p></body></html>';
    exit;
}

// Workshop-ID für die bestehenden Dateien verfügbar machen
$_GET['id'] = $workshopId;

// ── Routing ──────────────────────────────────────────────
switch ($action) {
    case 'feedback':
        require __DIR__ . '/feedback.php';
        break;

    case 'qa':
        require __DIR__ . '/qa.php';
        break;

    case 'wall':
        require __DIR__ . '/wall.php';
        break;

    case 'details':
        // Statisches HTML – Workshop-ID wird client-seitig aus URL gelesen
        readfile(__DIR__ . '/details.html');
        break;

    case 'ical':
        require __DIR__ . '/ics.php';
        break;

    case '':   // Landing-Page
    default:
        require __DIR__ . '/index.php';
        break;
}
