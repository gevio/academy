<?php
// public/api/app-feedback.php
// POST /api/app-feedback.php  – App-Feedback entgegennehmen, an n8n + Notion schreiben

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/NotionClient.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger JSON-Body']);
    exit;
}

// ── Pflichtfeld: App-Bewertung ────────────────────────────────────────────
$appBewertung = isset($body['app_bewertung']) ? (int)$body['app_bewertung'] : 0;
if ($appBewertung < 1 || $appBewertung > 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'app_bewertung (1–5) erforderlich']);
    exit;
}

// ── Optionale Felder ──────────────────────────────────────────────────────
$navigation          = max(0, min(5, (int)($body['navigation']          ?? 0)));
$ladegeschwindigkeit = max(0, min(5, (int)($body['ladegeschwindigkeit'] ?? 0)));
$nuetzlichkeit       = max(0, min(5, (int)($body['nuetzlichkeit']       ?? 0)));
$nps                 = max(0, min(10, (int)($body['nps']                ?? 0)));
$verbesserung        = mb_substr(trim($body['verbesserung']   ?? ''), 0, 2000);
$featureWunsch       = mb_substr(trim($body['feature_wunsch'] ?? ''), 0, 2000);

// ── Plattform: aus Body (vom JS erkannt) oder User-Agent-Fallback ─────────
$plattform = $body['plattform'] ?? '';
if (!in_array($plattform, ['iOS', 'Android', 'Browser'], true)) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iPod') !== false) {
        $plattform = 'iOS';
    } elseif (stripos($ua, 'Android') !== false) {
        $plattform = 'Android';
    } else {
        $plattform = 'Browser';
    }
}

$appVersion = mb_substr(trim($body['app_version'] ?? APP_VERSION), 0, 20);

// ── Device-ID ─────────────────────────────────────────────────────────────
$deviceId = getOrCreateDeviceId();

// ── Rate-Limit: 1 Feedback pro Device pro Tag ─────────────────────────────
$notion = new NotionClient(NOTION_TOKEN);
if ($notion->hasAppFeedbackToday($deviceId)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Du hast heute bereits App-Feedback gegeben. Danke! 🙏']);
    exit;
}

// ── Webhook-First ─────────────────────────────────────────────────────────
$payload = [
    'event'               => 'AS26',
    'app_bewertung'       => $appBewertung,
    'navigation'          => $navigation,
    'ladegeschwindigkeit' => $ladegeschwindigkeit,
    'nuetzlichkeit'       => $nuetzlichkeit,
    'nps'                 => $nps,
    'verbesserung'        => $verbesserung,
    'feature_wunsch'      => $featureWunsch,
    'plattform'           => $plattform,
    'app_version'         => $appVersion,
    'device_id'           => $deviceId,
];

$webhookOk = postToWebhook(N8N_APP_FEEDBACK_WEBHOOK, $payload);

// ── Fallback: direkte Notion API ──────────────────────────────────────────
if (!$webhookOk) {
    $result = $notion->createAppFeedback(
        $appBewertung, $navigation, $ladegeschwindigkeit, $nuetzlichkeit,
        $nps, $verbesserung, $featureWunsch, $plattform, $appVersion, $deviceId
    );
    if (!$result) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Feedback konnte nicht gespeichert werden.']);
        exit;
    }
}

echo json_encode(['ok' => true]);
