<?php
/**
 * GET /api/analytics-data.php?secret=ADMIN_SECRET
 *
 * Liefert aggregierte Analytics-Daten für das Admin-Dashboard.
 * Geschützt über ADMIN_SECRET.
 *
 * Query-Parameter:
 *   secret  (required) – Admin-Secret
 *   from    (optional) – Start-Datum YYYY-MM-DD (default: -30 Tage)
 *   to      (optional) – End-Datum YYYY-MM-DD (default: heute)
 *   live    (optional) – "1" für heutige Echtzeit-Rohdaten
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/bootstrap.php';

// ── Auth ─────────────────────────────────────────────────────────────
$secret = $_GET['secret'] ?? '';
if (empty(ADMIN_SECRET) || !hash_equals(ADMIN_SECRET, $secret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$dbPath = __DIR__ . '/../../storage/analytics/analytics.sqlite';
if (!file_exists($dbPath)) {
    echo json_encode(['ok' => true, 'daily' => [], 'live' => null]);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=3000');
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB-Fehler']);
    exit;
}

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiges Datumsformat']);
    exit;
}

// ── Tageswerte ───────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM analytics_daily WHERE date BETWEEN :from AND :to ORDER BY date ASC");
$stmt->execute([':from' => $from, ':to' => $to]);
$daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

// JSON-Strings dekodieren
foreach ($daily as &$row) {
    if (!empty($row['top_pages_json'])) {
        $row['top_pages'] = json_decode($row['top_pages_json'], true);
    }
    unset($row['top_pages_json']);
    if (!empty($row['top_features_json'])) {
        $row['top_features'] = json_decode($row['top_features_json'], true);
    }
    unset($row['top_features_json']);
}
unset($row);

// ── Live-Daten für heute direkt aus Rohdaten berechnen ───────────────
$today = date('Y-m-d');
if ($today >= $from && $today <= $to) {
    $todayStart = $today . 'T00:00:00';
    $todayEnd   = $today . 'T23:59:59';

    $countEvent = function (string $name) use ($db, $todayStart, $todayEnd): int {
        $s = $db->prepare("SELECT COUNT(*) FROM analytics_events WHERE created_at BETWEEN :s AND :e AND event_name = :n");
        $s->execute([':s' => $todayStart, ':e' => $todayEnd, ':n' => $name]);
        return (int) $s->fetchColumn();
    };

    $countDistinct = function (string $name, string $field = 'session_id') use ($db, $todayStart, $todayEnd): int {
        $s = $db->prepare("SELECT COUNT(DISTINCT $field) FROM analytics_events WHERE created_at BETWEEN :s AND :e AND event_name = :n");
        $s->execute([':s' => $todayStart, ':e' => $todayEnd, ':n' => $name]);
        return (int) $s->fetchColumn();
    };

    // Device/OS/Browser aus session_start Events
    $deviceQuery = function (string $column, array $mapping) use ($db, $todayStart, $todayEnd): array {
        $s = $db->prepare("SELECT $column, COUNT(DISTINCT session_id) as cnt FROM analytics_events WHERE created_at BETWEEN :s AND :e AND event_name = 'session_start' GROUP BY $column");
        $s->execute([':s' => $todayStart, ':e' => $todayEnd]);
        $result = array_fill_keys(array_keys($mapping), 0);
        while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            $key = $row[$column] ?? 'other';
            $mapped = $mapping[$key] ?? $mapping['other'] ?? 'other';
            $result[$mapped] = ($result[$mapped] ?? 0) + (int) $row['cnt'];
        }
        return $result;
    };

    $devices = $deviceQuery('device_type', ['mobile' => 'mobile', 'tablet' => 'tablet', 'desktop' => 'desktop', 'other' => 'desktop']);
    $oses = $deviceQuery('os_family', ['iOS' => 'ios', 'Android' => 'android', 'Windows' => 'windows', 'macOS' => 'macos', 'other' => 'other']);
    $browsers = $deviceQuery('browser_family', ['Chrome' => 'chrome', 'Safari' => 'safari', 'Firefox' => 'firefox', 'Samsung' => 'samsung', 'other' => 'other']);

    // Top pages
    $s = $db->prepare("SELECT page, COUNT(*) as cnt FROM analytics_events WHERE created_at BETWEEN :s AND :e AND event_name = 'page_view' GROUP BY page ORDER BY cnt DESC LIMIT 10");
    $s->execute([':s' => $todayStart, ':e' => $todayEnd]);
    $topPages = $s->fetchAll(PDO::FETCH_ASSOC);

    $sessions = $countDistinct('session_start');
    $s = $db->prepare("SELECT COUNT(DISTINCT session_id) FROM analytics_events WHERE created_at BETWEEN :s AND :e");
    $s->execute([':s' => $todayStart, ':e' => $todayEnd]);
    $uniqueVisitors = (int) $s->fetchColumn();

    $todayRow = [
        'date'                          => $today,
        'sessions'                      => $sessions,
        'unique_visitors_estimate'      => $uniqueVisitors,
        'page_views'                    => $countEvent('page_view'),
        'avg_session_duration_seconds'  => 0,
        'median_session_duration_seconds' => 0,
        'installs'                      => $countEvent('app_installed'),
        'standalone_opens'              => $countEvent('app_opened_standalone'),
        'install_prompt_shown'          => $countEvent('install_prompt_shown'),
        'install_prompt_accepted'       => $countEvent('install_prompt_accepted'),
        'device_mobile'                 => $devices['mobile'] ?? 0,
        'device_tablet'                 => $devices['tablet'] ?? 0,
        'device_desktop'                => $devices['desktop'] ?? 0,
        'os_ios'                        => $oses['ios'] ?? 0,
        'os_android'                    => $oses['android'] ?? 0,
        'os_windows'                    => $oses['windows'] ?? 0,
        'os_macos'                      => $oses['macos'] ?? 0,
        'os_other'                      => $oses['other'] ?? 0,
        'browser_chrome'                => $browsers['chrome'] ?? 0,
        'browser_safari'                => $browsers['safari'] ?? 0,
        'browser_firefox'               => $browsers['firefox'] ?? 0,
        'browser_samsung'               => $browsers['samsung'] ?? 0,
        'browser_other'                 => $browsers['other'] ?? 0,
        'chat_sessions'                 => $countEvent('chat_open'),
        'chat_messages'                 => $countEvent('chat_message_sent'),
        'chat_card_clicks'              => $countEvent('chat_card_click'),
        'chat_favorites_added'          => $countEvent('chat_favorite_added'),
        'app_feedback_submitted'        => $countEvent('app_feedback_submitted'),
        'top_pages'                     => $topPages,
        'top_features'                  => [],
    ];

    // Heutigen Eintrag in daily ersetzen oder anhängen
    $replaced = false;
    foreach ($daily as $i => $row) {
        if ($row['date'] === $today) {
            $daily[$i] = $todayRow;
            $replaced = true;
            break;
        }
    }
    if (!$replaced) {
        $daily[] = $todayRow;
    }
}

echo json_encode([
    'ok'    => true,
    'daily' => $daily,
]);
