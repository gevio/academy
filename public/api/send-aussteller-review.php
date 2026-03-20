<?php
/**
 * POST /api/send-aussteller-review.php
 *
 * Erstellt eine Review-Seite in Notion (AS26_Aussteller Reviews DB)
 * + eine E-Mail-Draft-Seite (E-Mails Ausgehend DB, Status = Draft).
 *
 * Erwartet JSON-Body:
 *   { "aussteller_id": "abc123...", "deadline": "2026-06-01", "kontakt_email": "optional@example.com" }
 *
 * Auth: Header  X-Admin-Secret  muss mit ADMIN_SECRET übereinstimmen.
 */

header('Content-Type: application/json; charset=utf-8');

// Nur POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

// ── Auth ─────────────────────────────────────────────
$secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';
if (empty(ADMIN_SECRET) || $secret !== ADMIN_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Input ────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$ausstellerId = $input['aussteller_id'] ?? '';
$deadline     = $input['deadline'] ?? date('Y-m-d', strtotime('+14 days'));
$kontaktEmail = $input['kontakt_email'] ?? '';

if (!preg_match('/^[a-f0-9\-]{32,36}$/', $ausstellerId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid aussteller_id']);
    exit;
}

if (empty(NOTION_AUSSTELLER_REVIEW_DB) || empty(NOTION_EMAIL_DB)) {
    http_response_code(500);
    echo json_encode(['error' => 'Aussteller-Review-System not configured (missing DB IDs)']);
    exit;
}

$notion = new NotionClient(NOTION_TOKEN);

// ── 1) Aussteller-Daten laden ────────────────────────
// Vorab aus aussteller.json (hat alle angereicherten Daten)
$cleanId = str_replace('-', '', $ausstellerId);
$ausData = null;
$jsonFile = __DIR__ . '/aussteller.json';
if (file_exists($jsonFile)) {
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    foreach (($jsonData['aussteller'] ?? []) as $entry) {
        if ($entry['id'] === $cleanId) {
            $ausData = $entry;
            break;
        }
    }
}

// page_id mit Bindestrichen für Relations
$pageId = $ausstellerId;
if (strlen($pageId) === 32 && strpos($pageId, '-') === false) {
    $pageId = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $pageId);
}

// Live aus Notion laden (für vollständige Daten inkl. Messe-Special, Webshop, Email)
$ausLive = $notion->getAusstellerForReview($pageId);
if (!$ausLive && !$ausData) {
    http_response_code(404);
    echo json_encode(['error' => 'Aussteller not found']);
    exit;
}

// Merge: Live-Daten als Basis, JSON-Daten als Ergänzung
$aussteller = $ausLive ?: [];
if ($ausData) {
    // JSON-Felder als Fallback nutzen
    $aussteller['firma']         = $aussteller['firma']         ?: ($ausData['firma'] ?? '');
    $aussteller['beschreibung']  = $aussteller['beschreibung']  ?: ($ausData['beschreibung'] ?? '');
    $aussteller['website']       = $aussteller['website']       ?: ($ausData['website'] ?? '');
    $aussteller['stand']         = $aussteller['stand']         ?: ($ausData['stand'] ?? '');
    $aussteller['kategorien']    = $aussteller['kategorien']    ?: ($ausData['kategorien'] ?? []);
}
$aussteller['page_id'] = $pageId;

if (empty($aussteller['firma'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Aussteller hat keinen Firmennamen',
        'hint'  => 'Bitte erst den Firmennamen in der Aussteller-DB eintragen.',
    ]);
    exit;
}

// ── 2) Kontakt-Email ermitteln ───────────────────────
$email = $kontaktEmail ?: ($aussteller['kontakt_email'] ?? '');

// ── 3) Review-Seite erstellen ────────────────────────
$reviewPage = $notion->createAusstellerReviewPage($aussteller, $deadline, $email);

if (!$reviewPage || empty($reviewPage['id'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Review-Seite konnte nicht erstellt werden']);
    exit;
}

$reviewPageId = $reviewPage['id'];
$reviewUrl    = $reviewPage['url'] ?? "https://notion.so/{$reviewPageId}";

// ── 4) E-Mail-Draft erstellen (nur wenn Email vorhanden) ──
$emailResult = null;
if (!empty($email)) {
    // Ansprechpartner: Vorname aus Kontakt (Master), Fallback Firmenname
    $ansprechpartner = $aussteller['kontakt_vorname'] ?? '';
    if (empty($ansprechpartner)) {
        $ansprechpartner = $aussteller['firma'];
    }
    $duzen = $aussteller['kontakt_duzen'] ?? false;

    $emailPage = $notion->createAusstellerEmailDraft(
        $aussteller['firma'],
        $email,
        $ansprechpartner,
        $aussteller['kontakt_nachname'] ?? '',
        $reviewUrl,
        $deadline,
        $duzen
    );
    $emailResult = [
        'name'  => trim(($aussteller['kontakt_vorname'] ?? '') . ' ' . ($aussteller['kontakt_nachname'] ?? '')),
        'email' => $email,
        'ok'    => !empty($emailPage['id']),
    ];
}

// ── Response ─────────────────────────────────────────
$response = [
    'success'        => true,
    'review_page_id' => $reviewPageId,
    'review_url'     => $reviewUrl,
    'firma'          => $aussteller['firma'],
    'deadline'       => $deadline,
    'has_email'      => !empty($email),
    'email_result'   => $emailResult,
];

if (empty($email)) {
    $response['warning'] = 'Keine Kontakt-Email gefunden – kein E-Mail-Draft erstellt. Bitte Review-Link manuell versenden.';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
