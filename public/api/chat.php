<?php
/**
 * POST /api/chat.php
 *
 * KI-Messe-Assistent – Proxy zwischen Frontend und n8n AI-Workflow.
 *
 * Erwartet JSON-Body:
 *   {
 *     "message":   "Nutzer-Nachricht",
 *     "sessionId": "uuid-v4",
 *     "history":   [ {"role":"user","content":"..."}, ... ],  // optional, max 8
 *     "profile":   { "tage":[], "fahrzeug":"", "level":"" }   // optional
 *   }
 *
 * Gibt zurück:
 *   {
 *     "ok": true,
 *     "message": "...",
 *     "workshops":      [ {"id":"...", "url":"/programm.html#..."} ],
 *     "aussteller":     [ {"id":"...", "url":"/aussteller.html#..."} ],
 *     "experten":       [ {"id":"...", "url":"/experte.html#..."} ],
 *     "profile_update": { "tage":[], "fahrzeug":"", "level":"" },
 *     "quick_replies":  ["Option A", "Option B"],
 *     "sessionId":      "uuid-v4"
 *   }
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── CORS (gleiche Domain, aber für lokale Entwicklung) ──────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ['http://localhost', 'http://localhost:8080'], true)) {
    header("Access-Control-Allow-Origin: $origin");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Nur POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';

// ── Rate-Limiting: 15 Nachrichten / Minute / Device ─────────────────
$deviceId = getOrCreateDeviceId();
$rateLimitKey = 'chat_rl_' . $deviceId;

if (!isset($_SESSION)) session_start();
$now    = time();
$window = 60; // Sekunden
$limit  = 15;

$log = $_SESSION[$rateLimitKey] ?? [];
$log = array_filter($log, fn($t) => ($now - $t) < $window);
$log[] = $now;
$_SESSION[$rateLimitKey] = array_values($log);

if (count($log) > $limit) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Zu viele Anfragen. Bitte kurz warten.']);
    exit;
}

// ── Input validieren ────────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiges JSON']);
    exit;
}

$message   = trim($input['message']   ?? '');
$sessionId = trim($input['sessionId'] ?? '');
$history   = $input['history']        ?? [];
$profile   = $input['profile']        ?? [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nachricht fehlt']);
    exit;
}

if (mb_strlen($message) > 1000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nachricht zu lang (max. 1000 Zeichen)']);
    exit;
}

// Session-ID generieren falls nicht vorhanden
if ($sessionId === '' || !preg_match('/^[a-f0-9\-]{20,40}$/', $sessionId)) {
    $sessionId = bin2hex(random_bytes(16));
}

// History bereinigen: nur gültige Rollen, max 8 Einträge
$history = array_filter($history, fn($m) =>
    is_array($m)
    && in_array($m['role'] ?? '', ['user', 'assistant'], true)
    && is_string($m['content'] ?? null)
    && mb_strlen($m['content']) <= 2000
);
$history = array_values(array_slice($history, -8));

// Profil-Felder bereinigen
$validTage = ['Freitag', 'Samstag', 'Sonntag'];
$profile = [
    'tage'     => array_values(array_filter(
        (array)($profile['tage'] ?? []),
        fn($t) => in_array($t, $validTage, true)
    )),
    'fahrzeug' => mb_substr(trim($profile['fahrzeug'] ?? ''), 0, 50),
    'level'    => mb_substr(trim($profile['level']    ?? ''), 0, 30),
];

// ── Webhook-URL prüfen ───────────────────────────────────────────────
$webhookUrl = defined('N8N_CHAT_WEBHOOK') ? N8N_CHAT_WEBHOOK : '';
if (empty($webhookUrl)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Chat-Service nicht konfiguriert']);
    exit;
}

// ── An n8n senden ───────────────────────────────────────────────────
$payload = json_encode([
    'message'   => $message,
    'sessionId' => $sessionId,
    'history'   => $history,
    'profile'   => $profile,
    'deviceId'  => $deviceId,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 35,           // LLM braucht bis zu 30s
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// ── Fehlerbehandlung ────────────────────────────────────────────────
if ($curlErr !== '') {
    error_log("AS26 Chat cURL error: $curlErr | webhook: $webhookUrl");
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Verbindungsproblem – bitte erneut versuchen']);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    error_log("AS26 Chat webhook HTTP $httpCode: " . substr($result, 0, 500));
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Assistent vorübergehend nicht erreichbar']);
    exit;
}

$response = json_decode($result, true);

if (!is_array($response) || !isset($response['ok'])) {
    // n8n hat valide Antwort zurück, aber kein 'ok'-Feld → trotzdem durchleiten
    if (is_array($response)) {
        echo json_encode(array_merge(['ok' => true], $response));
    } else {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Ungültige Antwort vom Assistenten']);
    }
    exit;
}

// sessionId aus Response übernehmen oder eigene zurückgeben
if (empty($response['sessionId'])) {
    $response['sessionId'] = $sessionId;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
