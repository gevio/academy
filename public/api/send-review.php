<?php
/**
 * POST /api/send-review.php
 *
 * Erstellt eine Review-Seite in Notion (Workshop Reviews DB)
 * + eine E-Mail-Draft-Seite (E-Mails Ausgehend DB, Status = Draft).
 *
 * Erwartet JSON-Body:
 *   { "workshop_id": "abc123...", "deadline": "2026-03-21" }
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
$workshopId = $input['workshop_id'] ?? '';
$deadline   = $input['deadline'] ?? date('Y-m-d', strtotime('+4 weeks'));

if (!preg_match('/^[a-f0-9\-]{32,36}$/', $workshopId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid workshop_id']);
    exit;
}

if (empty(NOTION_REVIEW_DB) || empty(NOTION_EMAIL_DB)) {
    http_response_code(500);
    echo json_encode(['error' => 'Review-System not configured (missing DB IDs)']);
    exit;
}

$notion = new NotionClient(NOTION_TOKEN);

// ── 1) Workshop-Daten laden ──────────────────────────
// Vorab aus workshops.json (hat alle angereicherten Daten)
$cleanId = str_replace('-', '', $workshopId);
$wsData = null;
$jsonFile = __DIR__ . '/workshops.json';
if (file_exists($jsonFile)) {
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    foreach (($jsonData['workshops'] ?? []) as $jws) {
        if ($jws['id'] === $cleanId) {
            $wsData = $jws;
            break;
        }
    }
}

if (!$wsData) {
    // Fallback: Live aus Notion
    $wsLive = $notion->getWorkshop($workshopId);
    if (!$wsLive) {
        http_response_code(404);
        echo json_encode(['error' => 'Workshop not found']);
        exit;
    }
    $wsData = $wsLive;
    $wsData['content_html'] = '';
    $wsData['has_content'] = false;
}

// page_id mit Bindestrichen für Relations
if (empty($wsData['page_id'])) {
    $wsData['page_id'] = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $cleanId);
}

// ── 2) Referent laden ────────────────────────────────
$referent = ['name' => '', 'vorname' => '', 'nachname' => '', 'email' => '', 'bio' => '', 'funktion' => '', 'website' => '', 'foto' => ''];

// Referent-Person-IDs aus workshops.json oder Live-Daten
$personIds = [];
if (!empty($wsData['referent_persons'])) {
    $personIds = array_column($wsData['referent_persons'], 'id');
} elseif (!empty($wsData['referent_person_ids'])) {
    $personIds = $wsData['referent_person_ids'];
}

if (!empty($personIds)) {
    // Ersten Referenten laden (Haupt-Referent)
    $refId = $personIds[0];
    // page_id mit Bindestrichen
    if (strlen($refId) === 32 && strpos($refId, '-') === false) {
        $refId = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $refId);
    }
    $referent = $notion->getReferentFull($refId);
}

if (empty($referent['email'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Referent hat keine E-Mail-Adresse',
        'referent_name' => $referent['name'] ?? 'unbekannt',
        'hint' => 'Bitte erst E-Mail in der Referenten-DB eintragen.',
    ]);
    exit;
}

// ── 3) Firma / Aussteller laden ─────────────────────
$firma = ['firma' => '', 'beschreibung' => '', 'website' => '', 'logo' => ''];

// Aus workshops.json
if (!empty($wsData['aussteller']) && is_array($wsData['aussteller'])) {
    $a = $wsData['aussteller'][0];
    $firma['firma'] = $a['firma'] ?? '';
    // Für mehr Details: Aussteller aus Notion laden
    if (!empty($a['id'])) {
        $ausstellerId = $a['id'];
        if (strlen($ausstellerId) === 32 && strpos($ausstellerId, '-') === false) {
            $ausstellerId = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $ausstellerId);
        }
        $ausstellerData = $notion->getAusstellerInfo($ausstellerId);
        if (!empty($ausstellerData)) {
            $firma = array_merge($firma, $ausstellerData);
        }
    }
} elseif (!empty($wsData['referent_firma'])) {
    $firma['firma'] = $wsData['referent_firma'];
}

// ── 4) Review-Seite erstellen ────────────────────────
$reviewPage = $notion->createReviewPage($wsData, $referent, $firma, $deadline);

if (!$reviewPage || empty($reviewPage['id'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Review-Seite konnte nicht erstellt werden']);
    exit;
}

$reviewPageId = $reviewPage['id'];
$reviewUrl    = $reviewPage['url'] ?? "https://notion.so/{$reviewPageId}";

// ── 5) E-Mail-Draft erstellen (Status = Draft!) ─────
$betreff = "Review: {$wsData['title']} – Adventure Southside 2026";
$emailPage = $notion->createEmailDraft(
    $betreff,
    $referent['email'],
    $referent['vorname'] ?: $referent['name'],
    $reviewUrl,
    $deadline
);

$emailOk = !empty($emailPage['id']);

// ── Response ─────────────────────────────────────────
echo json_encode([
    'success' => true,
    'review_page_id' => $reviewPageId,
    'review_url' => $reviewUrl,
    'email_created' => $emailOk,
    'email_status' => 'Draft',
    'referent_name' => $referent['name'],
    'referent_email' => $referent['email'],
    'workshop_title' => $wsData['title'],
    'deadline' => $deadline,
], JSON_UNESCAPED_UNICODE);
