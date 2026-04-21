<?php
/**
 * POST /api/submit-aussteller-review.php
 *
 * Reicht eine Aussteller-Review ein: Status → "Eingereicht".
 * Nur erlaubt wenn Status = "Entwurf".
 * Kein Admin-Secret erforderlich – die page_id selbst dient als Token.
 *
 * Erwartet JSON-Body:
 *   { "id": "<page_id>" }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

$input  = json_decode(file_get_contents('php://input'), true);
$pageId = trim($input['id'] ?? '');

if (!preg_match('/^[a-f0-9\-]{32,36}$/', $pageId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Review-ID']);
    exit;
}

// Normalisierung: 32 Hex → UUID mit Bindestrichen
if (strlen($pageId) === 32 && strpos($pageId, '-') === false) {
    $pageId = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $pageId);
}

$notion = new NotionClient(NOTION_TOKEN);

// Status vorab prüfen: nur "Entwurf" erlaubt
$review = $notion->getAusstellerReview($pageId);
if ($review === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Review nicht gefunden']);
    exit;
}

if ($review['status'] !== 'Entwurf') {
    http_response_code(403);
    echo json_encode([
        'error'  => 'Diese Review wurde bereits eingereicht.',
        'status' => $review['status'],
    ]);
    exit;
}

$ok = $notion->submitAusstellerReview($pageId);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Einreichen fehlgeschlagen. Bitte erneut versuchen.']);
    exit;
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
