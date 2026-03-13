<?php
/**
 * POST /api/analytics.php
 *
 * First-Party Analytics Event-Collector.
 * Nimmt JSON-Events entgegen und schreibt sie in SQLite.
 * Keine volle IP, keine personenbezogenen Daten.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Erlaubte Event-Typen ────────────────────────────────────────────
$ALLOWED_EVENTS = [
    'session_start',
    'page_view',
    'heartbeat',
    'install_prompt_shown',
    'install_prompt_clicked',
    'install_prompt_accepted',
    'install_prompt_dismissed',
    'app_installed',
    'app_opened_standalone',
    'feature_use',
    'chat_open',
    'chat_message_sent',
    'chat_response_received',
    'chat_card_click',
    'chat_favorite_added',
    'chat_quick_reply_used',
    'chat_new_conversation',
    'app_feedback_submitted',
];

// ── Input lesen ─────────────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiges JSON']);
    exit;
}

// Events können einzeln oder als Batch kommen
$events = isset($input['events']) && is_array($input['events'])
    ? $input['events']
    : [$input];

if (count($events) > 50) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Maximal 50 Events pro Request']);
    exit;
}

// ── DB öffnen / erstellen ───────────────────────────────────────────
$dbDir  = __DIR__ . '/../../storage/analytics';
$dbPath = $dbDir . '/analytics.sqlite';
$isNew  = !file_exists($dbPath);

if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=3000');
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB-Fehler']);
    error_log('Analytics DB error: ' . $e->getMessage());
    exit;
}

if ($isNew) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS analytics_events (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%S','now')),
            event_name      TEXT    NOT NULL,
            session_id      TEXT    NOT NULL,
            page            TEXT,
            feature         TEXT,
            device_type     TEXT,
            os_family       TEXT,
            browser_family  TEXT,
            display_mode    TEXT,
            locale          TEXT,
            screen_bucket   TEXT,
            payload_json    TEXT
        );

        CREATE INDEX IF NOT EXISTS idx_events_date ON analytics_events(created_at);
        CREATE INDEX IF NOT EXISTS idx_events_name ON analytics_events(event_name);
        CREATE INDEX IF NOT EXISTS idx_events_session ON analytics_events(session_id);

        CREATE TABLE IF NOT EXISTS analytics_daily (
            date                          TEXT PRIMARY KEY,
            sessions                      INTEGER DEFAULT 0,
            unique_visitors_estimate      INTEGER DEFAULT 0,
            page_views                    INTEGER DEFAULT 0,
            avg_session_duration_seconds  REAL    DEFAULT 0,
            median_session_duration_seconds REAL  DEFAULT 0,
            installs                      INTEGER DEFAULT 0,
            standalone_opens              INTEGER DEFAULT 0,
            install_prompt_shown          INTEGER DEFAULT 0,
            install_prompt_accepted       INTEGER DEFAULT 0,
            device_mobile                 INTEGER DEFAULT 0,
            device_tablet                 INTEGER DEFAULT 0,
            device_desktop                INTEGER DEFAULT 0,
            os_ios                        INTEGER DEFAULT 0,
            os_android                    INTEGER DEFAULT 0,
            os_windows                    INTEGER DEFAULT 0,
            os_macos                      INTEGER DEFAULT 0,
            os_other                      INTEGER DEFAULT 0,
            browser_chrome                INTEGER DEFAULT 0,
            browser_safari                INTEGER DEFAULT 0,
            browser_firefox               INTEGER DEFAULT 0,
            browser_samsung               INTEGER DEFAULT 0,
            browser_other                 INTEGER DEFAULT 0,
            chat_sessions                 INTEGER DEFAULT 0,
            chat_messages                 INTEGER DEFAULT 0,
            chat_card_clicks              INTEGER DEFAULT 0,
            chat_favorites_added          INTEGER DEFAULT 0,
            top_pages_json                TEXT,
            top_features_json             TEXT
        );
    ");
}

// ── Events validieren und einfügen ──────────────────────────────────
$stmt = $db->prepare("
    INSERT INTO analytics_events
        (event_name, session_id, page, feature, device_type, os_family,
         browser_family, display_mode, locale, screen_bucket, payload_json)
    VALUES
        (:event_name, :session_id, :page, :feature, :device_type, :os_family,
         :browser_family, :display_mode, :locale, :screen_bucket, :payload_json)
");

$inserted = 0;

foreach ($events as $ev) {
    if (!is_array($ev)) continue;

    $eventName = $ev['event_name'] ?? '';
    $sessionId = $ev['session_id'] ?? '';

    // Validierung
    if (!in_array($eventName, $ALLOWED_EVENTS, true)) continue;
    if (strlen($sessionId) < 8 || strlen($sessionId) > 64) continue;

    // Felder sanitisieren (max. Länge begrenzen)
    $clean = function(?string $v, int $max = 64): ?string {
        if ($v === null || $v === '') return null;
        return mb_substr($v, 0, $max);
    };

    $payload = null;
    if (isset($ev['payload']) && is_array($ev['payload'])) {
        $encoded = json_encode($ev['payload']);
        if (strlen($encoded) <= 1024) {
            $payload = $encoded;
        }
    }

    $stmt->execute([
        ':event_name'     => $eventName,
        ':session_id'     => $clean($sessionId),
        ':page'           => $clean($ev['page'] ?? null, 128),
        ':feature'        => $clean($ev['feature'] ?? null),
        ':device_type'    => $clean($ev['device_type'] ?? null, 16),
        ':os_family'      => $clean($ev['os_family'] ?? null, 32),
        ':browser_family' => $clean($ev['browser_family'] ?? null, 32),
        ':display_mode'   => $clean($ev['display_mode'] ?? null, 16),
        ':locale'         => $clean($ev['locale'] ?? null, 10),
        ':screen_bucket'  => $clean($ev['screen_bucket'] ?? null, 16),
        ':payload_json'   => $payload,
    ]);
    $inserted++;
}

echo json_encode(['ok' => true, 'inserted' => $inserted]);
