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
    // logo_local kommt nur aus JSON (lokaler Pfad zum heruntergeladenen Logo)
    if (empty($aussteller['logo_local']) && !empty($ausData['logo_local'])) {
        $aussteller['logo_local'] = $ausData['logo_local'];
    }
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

// ── 1b) Guard: genau 1 Kontakt (Master) nötig ───────────────
$kontaktCount = $aussteller['kontakt_master_count'] ?? null;
if ($kontaktCount === null) {
    // Fallback falls Live-Daten nicht verfügbar (nur JSON-Merge)
    $kontaktCount = !empty($aussteller['kontakt_email']) ? 1 : 0;
}
if ($kontaktCount !== 1) {
    http_response_code(422);
    $kontaktMsg = $kontaktCount === 0
        ? 'Kein Kontakt (Master) verknüpft – bitte zuerst in Notion ergänzen.'
        : "Mehrere Kontakte ({$kontaktCount}) verknüpft – bitte auf genau einen reduzieren.";
    echo json_encode([
        'error'         => $kontaktMsg,
        'kontakt_count' => $kontaktCount,
        'hint'          => 'Tipp: Relation "Kontakt (Master)" in Notion auf "Limit: 1 Seite" stellen.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 2) Duplikat-Check: existiert bereits eine aktive Review? ──
$existingReview = $notion->queryDatabase(NOTION_AUSSTELLER_REVIEW_DB, [
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

if (!empty($existingReview['results'])) {
    $existing = $existingReview['results'][0];
    $existingUrl = $existing['url'] ?? '';
    $existingStatus = $existing['properties']['Status']['select']['name'] ?? '';
    http_response_code(409);
    echo json_encode([
        'error'  => 'Es existiert bereits eine aktive Review für diesen Aussteller.',
        'status' => $existingStatus,
        'review_url' => $existingUrl,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 3) Kontakt-Email ermitteln ───────────────────────
$email = $kontaktEmail ?: ($aussteller['kontakt_email'] ?? '');

// ── 4) Review-Seite erstellen ────────────────────────
$reviewPage = $notion->createAusstellerReviewPage($aussteller, $deadline, $email);

if (!$reviewPage || empty($reviewPage['id'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Review-Seite konnte nicht erstellt werden']);
    exit;
}

$reviewPageId = $reviewPage['id'];
$reviewUrl    = $reviewPage['url'] ?? "https://notion.so/{$reviewPageId}";

// Custom-URL für Kunden-E-Mail: immer die öffentliche Live-Domain – das Dev-System
// darf Kunden niemals sehen.
$publicBase = rtrim(REVIEW_PUBLIC_URL ?: (defined('SITE_URL') ? SITE_URL : ''), '/');
$reviewCustomUrl = $publicBase
    ? $publicBase . '/review.html?id=' . str_replace('-', '', $reviewPageId)
    : $reviewUrl;

// ── 5) E-Mail-Draft erstellen (nur wenn Email vorhanden) ──
// App-Link + App-QR sind deterministisch (id-basiert), kein Writeback nötig.
$ausstellerCleanId = str_replace('-', '', $pageId);
$appLink = $publicBase
    ? $publicBase . '/aussteller.html#id=' . $ausstellerCleanId
    : ((defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/aussteller.html#id=' . $ausstellerCleanId);

// App-QR: PNG aus Notion-Formel (lokal unter public/qr-aussteller/)
$appQrUrl = $publicBase
    ? $publicBase . '/qr-aussteller/' . $ausstellerCleanId . '.png'
    : '';

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
        $reviewCustomUrl,
        $appLink,
        $appQrUrl,
        $appQrUrl,
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
    'success'            => true,
    'review_page_id'     => $reviewPageId,
    'review_url'         => $reviewUrl,
    'review_custom_url'  => $reviewCustomUrl,
    'app_link'           => $appLink,
    'app_qr_url'         => $appQrUrl,
    'firma'              => $aussteller['firma'],
    'deadline'           => $deadline,
    'has_email'          => !empty($email),
    'email_result'       => $emailResult,
];

if (empty($email)) {
    $response['warning'] = 'Keine Kontakt-Email gefunden – kein E-Mail-Draft erstellt. Bitte Review-Link manuell versenden.';
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
