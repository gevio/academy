<?php
/**
 * cli/aggregate-analytics.php
 *
 * Tägliche Aggregation der Analytics-Rohdaten.
 * Berechnet Tageswerte und schreibt sie in analytics_daily.
 * Löscht Rohdaten älter als 90 Tage.
 *
 * Cron (täglich um 3:00):
 *   0 3 * * * cd /var/www/as26.cool-camp.site && php cli/aggregate-analytics.php >> /var/log/as26-analytics.log 2>&1
 *   0 3 * * * cd /var/www/dev.as26.cool-camp.site && php cli/aggregate-analytics.php >> /var/log/as26-analytics.log 2>&1
 */

$dbDir  = __DIR__ . '/../storage/analytics';
$dbPath = $dbDir . '/analytics.sqlite';

if (!file_exists($dbPath)) {
    echo "[" . date('Y-m-d H:i:s') . "] Keine Analytics-DB gefunden. Überspringe.\n";
    exit(0);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
} catch (PDOException $e) {
    echo "[" . date('Y-m-d H:i:s') . "] DB-Fehler: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Welche Tage aggregieren? ─────────────────────────────────────────
// Option --date=YYYY-MM-DD für einzelnen Tag, sonst: gestern
$targetDate = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $targetDate = substr($arg, 7);
    }
}
if (!$targetDate) {
    $targetDate = date('Y-m-d', strtotime('-1 day'));
}

echo "[" . date('Y-m-d H:i:s') . "] Aggregiere Tag: {$targetDate}\n";

$dayStart = $targetDate . 'T00:00:00';
$dayEnd   = $targetDate . 'T23:59:59';

// ── Sessions ─────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT session_id) FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'session_start'
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$sessions = (int) $stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(DISTINCT session_id) FROM analytics_events
    WHERE created_at BETWEEN :start AND :end
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$uniqueVisitors = (int) $stmt->fetchColumn();

// ── Page Views ───────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT COUNT(*) FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'page_view'
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$pageViews = (int) $stmt->fetchColumn();

// ── Session-Dauer (aus Heartbeats) ───────────────────────────────────
// Dauer = (Anzahl Heartbeats pro Session) × 30 Sekunden
$stmt = $db->prepare("
    SELECT session_id, COUNT(*) as hb_count
    FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'heartbeat'
    GROUP BY session_id
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$durations = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $durations[] = $row['hb_count'] * 30;
}
sort($durations);
$avgDuration = count($durations) > 0 ? array_sum($durations) / count($durations) : 0;
$medianDuration = 0;
if (count($durations) > 0) {
    $mid = (int) floor(count($durations) / 2);
    $medianDuration = count($durations) % 2 === 0
        ? ($durations[$mid - 1] + $durations[$mid]) / 2
        : $durations[$mid];
}

// ── Install-Events ───────────────────────────────────────────────────
$installEvents = ['app_installed', 'standalone_opens' => 'app_opened_standalone',
                   'install_prompt_shown' => 'install_prompt_shown',
                   'install_prompt_accepted' => 'install_prompt_accepted'];

$stmtCount = $db->prepare("
    SELECT COUNT(*) FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = :name
");

$countEvent = function (string $eventName) use ($stmtCount, $dayStart, $dayEnd): int {
    $stmtCount->execute([':start' => $dayStart, ':end' => $dayEnd, ':name' => $eventName]);
    return (int) $stmtCount->fetchColumn();
};

$installs       = $countEvent('app_installed');
$standaloneOpens = $countEvent('app_opened_standalone');
$promptShown    = $countEvent('install_prompt_shown');
$promptAccepted = $countEvent('install_prompt_accepted');

// ── Device-Verteilung ────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT device_type, COUNT(DISTINCT session_id) as cnt
    FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'session_start'
    GROUP BY device_type
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$devices = ['mobile' => 0, 'tablet' => 0, 'desktop' => 0];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $key = strtolower($row['device_type'] ?? 'mobile');
    if (isset($devices[$key])) $devices[$key] = (int) $row['cnt'];
}

// ── OS-Verteilung ────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT os_family, COUNT(DISTINCT session_id) as cnt
    FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'session_start'
    GROUP BY os_family
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$os = ['iOS' => 0, 'Android' => 0, 'Windows' => 0, 'macOS' => 0, 'other' => 0];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $key = $row['os_family'] ?? 'other';
    if (isset($os[$key])) $os[$key] += (int) $row['cnt'];
    else $os['other'] += (int) $row['cnt'];
}

// ── Browser-Verteilung ──────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT browser_family, COUNT(DISTINCT session_id) as cnt
    FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'session_start'
    GROUP BY browser_family
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$browsers = ['Chrome' => 0, 'Safari' => 0, 'Firefox' => 0, 'Samsung' => 0, 'other' => 0];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $key = $row['browser_family'] ?? 'other';
    if (isset($browsers[$key])) $browsers[$key] += (int) $row['cnt'];
    else $browsers['other'] += (int) $row['cnt'];
}

// ── Chat-Metriken ────────────────────────────────────────────────────
$chatSessions  = $countEvent('chat_open');
$chatMessages  = $countEvent('chat_message_sent');
$chatCardClicks = $countEvent('chat_card_click');
$chatFavsAdded  = $countEvent('chat_favorite_added');

// ── Top Pages ────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT page, COUNT(*) as cnt
    FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'page_view'
    GROUP BY page ORDER BY cnt DESC LIMIT 10
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$topPages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Top Features ─────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT feature, COUNT(*) as cnt
    FROM analytics_events
    WHERE created_at BETWEEN :start AND :end AND event_name = 'feature_use' AND feature IS NOT NULL
    GROUP BY feature ORDER BY cnt DESC LIMIT 10
");
$stmt->execute([':start' => $dayStart, ':end' => $dayEnd]);
$topFeatures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── UPSERT in analytics_daily ────────────────────────────────────────
$db->prepare("
    INSERT INTO analytics_daily (
        date, sessions, unique_visitors_estimate, page_views,
        avg_session_duration_seconds, median_session_duration_seconds,
        installs, standalone_opens, install_prompt_shown, install_prompt_accepted,
        device_mobile, device_tablet, device_desktop,
        os_ios, os_android, os_windows, os_macos, os_other,
        browser_chrome, browser_safari, browser_firefox, browser_samsung, browser_other,
        chat_sessions, chat_messages, chat_card_clicks, chat_favorites_added,
        top_pages_json, top_features_json
    ) VALUES (
        :date, :sessions, :unique_visitors, :page_views,
        :avg_dur, :median_dur,
        :installs, :standalone_opens, :prompt_shown, :prompt_accepted,
        :d_mobile, :d_tablet, :d_desktop,
        :os_ios, :os_android, :os_windows, :os_macos, :os_other,
        :b_chrome, :b_safari, :b_firefox, :b_samsung, :b_other,
        :chat_sessions, :chat_messages, :chat_card_clicks, :chat_favs,
        :top_pages, :top_features
    )
    ON CONFLICT(date) DO UPDATE SET
        sessions = excluded.sessions,
        unique_visitors_estimate = excluded.unique_visitors_estimate,
        page_views = excluded.page_views,
        avg_session_duration_seconds = excluded.avg_session_duration_seconds,
        median_session_duration_seconds = excluded.median_session_duration_seconds,
        installs = excluded.installs,
        standalone_opens = excluded.standalone_opens,
        install_prompt_shown = excluded.install_prompt_shown,
        install_prompt_accepted = excluded.install_prompt_accepted,
        device_mobile = excluded.device_mobile,
        device_tablet = excluded.device_tablet,
        device_desktop = excluded.device_desktop,
        os_ios = excluded.os_ios,
        os_android = excluded.os_android,
        os_windows = excluded.os_windows,
        os_macos = excluded.os_macos,
        os_other = excluded.os_other,
        browser_chrome = excluded.browser_chrome,
        browser_safari = excluded.browser_safari,
        browser_firefox = excluded.browser_firefox,
        browser_samsung = excluded.browser_samsung,
        browser_other = excluded.browser_other,
        chat_sessions = excluded.chat_sessions,
        chat_messages = excluded.chat_messages,
        chat_card_clicks = excluded.chat_card_clicks,
        chat_favorites_added = excluded.chat_favorites_added,
        top_pages_json = excluded.top_pages_json,
        top_features_json = excluded.top_features_json
")->execute([
    ':date'             => $targetDate,
    ':sessions'         => $sessions,
    ':unique_visitors'  => $uniqueVisitors,
    ':page_views'       => $pageViews,
    ':avg_dur'          => round($avgDuration, 1),
    ':median_dur'       => round($medianDuration, 1),
    ':installs'         => $installs,
    ':standalone_opens' => $standaloneOpens,
    ':prompt_shown'     => $promptShown,
    ':prompt_accepted'  => $promptAccepted,
    ':d_mobile'         => $devices['mobile'],
    ':d_tablet'         => $devices['tablet'],
    ':d_desktop'        => $devices['desktop'],
    ':os_ios'           => $os['iOS'],
    ':os_android'       => $os['Android'],
    ':os_windows'       => $os['Windows'],
    ':os_macos'         => $os['macOS'],
    ':os_other'         => $os['other'],
    ':b_chrome'         => $browsers['Chrome'],
    ':b_safari'         => $browsers['Safari'],
    ':b_firefox'        => $browsers['Firefox'],
    ':b_samsung'        => $browsers['Samsung'],
    ':b_other'          => $browsers['other'],
    ':chat_sessions'    => $chatSessions,
    ':chat_messages'    => $chatMessages,
    ':chat_card_clicks' => $chatCardClicks,
    ':chat_favs'        => $chatFavsAdded,
    ':top_pages'        => json_encode($topPages),
    ':top_features'     => json_encode($topFeatures),
]);

echo "[" . date('Y-m-d H:i:s') . "] Tag {$targetDate}: {$sessions} Sessions, {$pageViews} PVs, {$installs} Installs, {$chatMessages} Chat-Msgs\n";

// ── Rohdaten älter als 90 Tage löschen ──────────────────────────────
$cutoff = date('Y-m-d', strtotime('-90 days'));
$stmt = $db->prepare("DELETE FROM analytics_events WHERE created_at < :cutoff");
$stmt->execute([':cutoff' => $cutoff . 'T00:00:00']);
$deleted = $stmt->rowCount();
if ($deleted > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] {$deleted} Rohdaten-Zeilen vor {$cutoff} gelöscht\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Aggregation abgeschlossen.\n";
