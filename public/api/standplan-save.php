<?php
/**
 * standplan-save.php – Map-Editor → Notion API
 *
 * Empfängt Standkoordinaten vom Map-Editor und schreibt sie
 * als Stand_X, Stand_Y, Stand_W, Stand_H in die Notion DB.
 *
 * POST /api/standplan-save.php
 * Body: { "items": [ { "page_id": "uuid", "stand": "A3-300", "x": 45.2, "y": 32.1, "w": null, "h": null }, ... ] }
 */

set_time_limit(300); // Bis zu 5 Minuten für viele Stände
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$items = $input['items'] ?? [];

if (empty($items)) {
    echo json_encode(['error' => 'No items', 'updated' => 0, 'failed' => 0]);
    exit;
}

$notion = new NotionClient(NOTION_TOKEN);
$results = ['updated' => 0, 'failed' => 0, 'errors' => []];

foreach ($items as $item) {
    $pageId = $item['page_id'] ?? '';
    if (!$pageId || !preg_match('/^[a-f0-9\-]{32,36}$/', $pageId)) {
        $results['failed']++;
        $results['errors'][] = ($item['stand'] ?? '?') . ' (ungültige page_id)';
        continue;
    }

    $properties = [];

    if (isset($item['x']) && $item['x'] !== null) {
        $properties['Stand_X'] = ['number' => round((float)$item['x'], 1)];
    } else {
        $properties['Stand_X'] = ['number' => null];
    }

    if (isset($item['y']) && $item['y'] !== null) {
        $properties['Stand_Y'] = ['number' => round((float)$item['y'], 1)];
    } else {
        $properties['Stand_Y'] = ['number' => null];
    }

    // W/H nur setzen wenn vorhanden (Rechteck-Marker), sonst null
    $properties['Stand_W'] = (isset($item['w']) && $item['w'] !== null)
        ? ['number' => round((float)$item['w'], 1)]
        : ['number' => null];

    $properties['Stand_H'] = (isset($item['h']) && $item['h'] !== null)
        ? ['number' => round((float)$item['h'], 1)]
        : ['number' => null];

    $result = $notion->updatePage($pageId, $properties);
    if ($result) {
        $results['updated']++;
    } else {
        $results['failed']++;
        $results['errors'][] = $item['stand'] ?? $pageId;
    }

    usleep(350000); // Rate-Limit: ~3 req/sec
}

echo json_encode($results);
