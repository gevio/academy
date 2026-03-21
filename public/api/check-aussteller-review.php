<?php
/**
 * GET /api/check-aussteller-review.php?aussteller_id=xxx
 *
 * Prüft ob für einen Aussteller bereits eine aktive Review existiert.
 * Auth: Header X-Admin-Secret muss mit ADMIN_SECRET übereinstimmen.
 *
 * Response:
 *   { "exists": false }
 *   { "exists": true, "status": "Entwurf", "review_url": "https://..." }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

$secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
if (empty(ADMIN_SECRET) || $secret !== ADMIN_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$ausstellerId = $_GET['aussteller_id'] ?? '';
if (!preg_match('/^[a-f0-9\-]{32,36}$/', $ausstellerId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid aussteller_id']);
    exit;
}

// page_id mit Bindestrichen
$pageId = $ausstellerId;
if (strlen($pageId) === 32 && strpos($pageId, '-') === false) {
    $pageId = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $pageId);
}

$notion = new NotionClient(NOTION_TOKEN);

$result = $notion->queryDatabase(NOTION_AUSSTELLER_REVIEW_DB, [
    'filter' => [
        'and' => [
            [
                'property' => 'Aussteller (AS26)',
                'relation' => ['contains' => $pageId],
            ],
            [
                'property' => 'Status',
                'select'   => ['does_not_equal' => 'Übertragen'],
            ],
        ],
    ],
    'page_size' => 1,
]);

if (!empty($result['results'])) {
    $page = $result['results'][0];
    $status = $page['properties']['Status']['select']['name'] ?? '';
    echo json_encode([
        'exists'     => true,
        'status'     => $status,
        'review_url' => $page['url'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['exists' => false]);
}
