<?php
/**
 * GET /api/app-feedback-data.php?secret=ADMIN_SECRET
 *
 * Liefert App-Feedback-Einträge aus der Notion-Datenbank für das Admin-Dashboard.
 * Geschützt über ADMIN_SECRET.
 *
 * Query-Parameter:
 *   secret  (required) – Admin-Secret
 *   cursor  (optional) – Notion-Pagination-Cursor
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

// ── Auth ─────────────────────────────────────────────────────────────
$secret = $_GET['secret'] ?? '';
if (empty(ADMIN_SECRET) || !hash_equals(ADMIN_SECRET, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (empty(NOTION_APP_FEEDBACK_DB)) {
    echo json_encode(['ok' => true, 'items' => [], 'has_more' => false]);
    exit;
}

$notion = new NotionClient(NOTION_TOKEN);
$cursor = $_GET['cursor'] ?? null;

$params = [
    'page_size' => 100,
    'sorts'     => [['property' => 'Zeitstempel', 'direction' => 'descending']],
];
if ($cursor) {
    $params['start_cursor'] = $cursor;
}

$result = $notion->queryDatabase(NOTION_APP_FEEDBACK_DB, $params);
if (!$result) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Notion-Fehler']);
    exit;
}

$items = [];
foreach ($result['results'] ?? [] as $page) {
    $props = $page['properties'] ?? [];
    $items[] = [
        'id'                 => $page['id'],
        'zeitstempel'        => $props['Zeitstempel']['date']['start'] ?? ($page['created_time'] ?? ''),
        'app_bewertung'      => $props['App-Bewertung']['number'] ?? null,
        'navigation'         => $props['Navigation']['number'] ?? null,
        'ladegeschwindigkeit'=> $props['Ladegeschwindigkeit']['number'] ?? null,
        'nuetzlichkeit'      => $props['Nützlichkeit']['number'] ?? null,
        'nps'                => $props['NPS']['number'] ?? null,
        'plattform'          => $props['Plattform']['select']['name'] ?? '',
        'app_version'        => $props['App-Version']['rich_text'][0]['text']['content'] ?? '',
        'verbesserung'       => $props['Verbesserungsvorschlag']['rich_text'][0]['text']['content'] ?? '',
        'feature_wunsch'     => $props['Feature-Wunsch']['rich_text'][0]['text']['content'] ?? '',
    ];
}

echo json_encode([
    'ok'          => true,
    'items'       => $items,
    'has_more'    => $result['has_more'] ?? false,
    'next_cursor' => $result['next_cursor'] ?? null,
]);
