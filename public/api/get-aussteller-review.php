<?php
/**
 * GET /api/get-aussteller-review.php?id=<page_id>
 *
 * Liest öffentliche Felder einer Aussteller-Review-Seite.
 * Kein Admin-Secret erforderlich – die page_id selbst dient als Token.
 *
 * Response:
 *   { id, status, deadline, firma, beschreibung, messeSpecial, webseite, webshop, logoUrl }
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

$pageId = trim($_GET['id'] ?? '');

if (!preg_match('/^[a-f0-9\-]{32,36}$/', $pageId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Review-ID']);
    exit;
}

// Normalisierung: 32 Hex-Zeichen → UUID mit Bindestrichen
if (strlen($pageId) === 32 && strpos($pageId, '-') === false) {
    $pageId = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $pageId);
}

$notion = new NotionClient(NOTION_TOKEN);
$review = $notion->getAusstellerReview($pageId);

if ($review === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Review nicht gefunden']);
    exit;
}

echo json_encode($review, JSON_UNESCAPED_UNICODE);
