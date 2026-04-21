<?php
/**
 * POST /api/update-aussteller-review.php
 *
 * Aktualisiert editierbare Felder einer Aussteller-Review-Seite.
 * Nur erlaubt wenn Status = "Entwurf".
 * Kein Admin-Secret erforderlich – die page_id selbst dient als Token.
 *
 * Erwartet JSON-Body:
 *   { "id": "<page_id>", "firma"?, "beschreibung"?, "messeSpecial"?, "webseite"?, "webshop"? }
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

// URL-Felder validieren
foreach (['webseite', 'webshop'] as $field) {
    if (!empty($input[$field])) {
        $url = $input[$field];
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            http_response_code(400);
            echo json_encode(['error' => "Ungültige URL im Feld '{$field}'"]);
            exit;
        }
    }
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
        'error'  => 'Review kann nicht mehr bearbeitet werden.',
        'status' => $review['status'],
    ]);
    exit;
}

// Nur bekannte Felder übernehmen (Firmenname wird intern geprüft, nicht über dieses Formular geändert)
$allowed = ['beschreibung', 'messeSpecial', 'webseite', 'webshop'];
$data = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $input)) {
        $data[$key] = $input[$key];
    }
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine Felder zum Aktualisieren angegeben']);
    exit;
}

$ok = $notion->updateAusstellerReview($pageId, $data);

if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Speichern fehlgeschlagen. Bitte erneut versuchen.']);
    exit;
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
