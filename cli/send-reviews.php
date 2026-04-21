<?php
/**
 * cli/send-reviews.php – Massenversand Aussteller-Reviews
 *
 * Lädt alle Aussteller mit Status = "Bereit" aus der Notion Aussteller-DB,
 * erstellt für jeden eine Review-Seite + E-Mail-Draft und setzt den
 * Aussteller-Status auf "Review erfolgt".
 *
 * Aussteller mit bereits aktiver Review werden übersprungen.
 *
 * Usage:
 *   php cli/send-reviews.php                    – alle "Bereit"-Aussteller abarbeiten
 *   php cli/send-reviews.php --dry-run           – nur Vorschau, keine Änderungen
 *   php cli/send-reviews.php --deadline=2026-06-01  – Deadline überschreiben (Standard: +14 Tage)
 *   php cli/send-reviews.php --limit=5           – max. N Aussteller verarbeiten
 */

$args       = $argv ?? [];
$dryRun     = in_array('--dry-run', $args, true);
$deadline   = null;
$limit      = null;

foreach ($args as $arg) {
    if (preg_match('/^--deadline=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) {
        $deadline = $m[1];
    }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$deadline = $deadline ?? date('Y-m-d', strtotime('+14 days'));

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

if (empty(NOTION_AUSSTELLER_DB) || empty(NOTION_AUSSTELLER_REVIEW_DB)) {
    die("❌ NOTION_AUSSTELLER_DB oder NOTION_AUSSTELLER_REVIEW_DB nicht gesetzt.\n");
}

$notion = new NotionClient(NOTION_TOKEN);

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "  Aussteller-Review Massenversand\n";
echo "  Deadline: {$deadline}\n";
echo "  Modus:    " . ($dryRun ? "DRY-RUN (keine Änderungen)" : "LIVE") . "\n";
if ($limit) echo "  Limit:    {$limit} Aussteller\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// ── 1) Aussteller mit Status = "Bereit" laden ────────────────────────────────
echo "📥 Lade Aussteller mit Status = 'Bereit'...\n";

$body = [
    'page_size' => 100,
    'filter' => [
        'property' => 'Status',
        'select'   => ['equals' => 'Bereit'],
    ],
    'sorts' => [
        ['property' => 'Aussteller', 'direction' => 'ascending'],
    ],
];

$aussteller = [];
$cursor = null;

do {
    if ($cursor) $body['start_cursor'] = $cursor;
    $data = $notion->queryDatabase(NOTION_AUSSTELLER_DB, $body);
    if (!$data) {
        echo "❌ Notion-Abfrage fehlgeschlagen.\n";
        exit(1);
    }

    foreach ($data['results'] ?? [] as $page) {
        $props = $page['properties'] ?? [];
        $pageId = $page['id'];

        // Firmenname
        $firma = '';
        foreach ($props['Aussteller']['title'] ?? [] as $t) {
            $firma .= $t['plain_text'] ?? '';
        }
        $firma = trim($firma);
        if (empty($firma)) continue;

        $aussteller[] = [
            'page_id' => $pageId,
            'firma'   => $firma,
        ];

        if ($limit && count($aussteller) >= $limit) break 2;
    }

    $cursor = $data['next_cursor'] ?? null;
} while ($data['has_more'] ?? false);

$total = count($aussteller);
echo "   {$total} Aussteller gefunden.\n\n";

if ($total === 0) {
    echo "✅ Nichts zu tun.\n";
    exit(0);
}

// ── 2) Pro Aussteller Review + Mail erstellen ────────────────────────────────
$stats = ['sent' => 0, 'skipped' => 0, 'error' => 0];

foreach ($aussteller as $i => $aus) {
    $num    = $i + 1;
    $pageId = $aus['page_id'];
    $firma  = $aus['firma'];

    echo "[{$num}/{$total}] {$firma}\n";

    // Duplikat-Check
    $existing = $notion->queryDatabase(NOTION_AUSSTELLER_REVIEW_DB, [
        'filter' => [
            'and' => [
                ['property' => 'Aussteller (AS26)', 'relation' => ['contains' => $pageId]],
                ['property' => 'Status', 'select' => ['does_not_equal' => 'Übertragen']],
            ],
        ],
        'page_size' => 1,
    ]);

    if (!empty($existing['results'])) {
        $existStatus = $existing['results'][0]['properties']['Status']['select']['name'] ?? '?';
        echo "   ⏭ Übersprungen – aktive Review vorhanden (Status: {$existStatus})\n";
        $stats['skipped']++;
        continue;
    }

    // Live-Daten aus Notion laden
    $ausLive = $notion->getAusstellerForReview($pageId);
    if (!$ausLive) {
        echo "   ❌ Aussteller-Daten nicht abrufbar\n";
        $stats['error']++;
        continue;
    }
    $ausLive['page_id'] = $pageId;

    // Aus aussteller.json logo_local ergänzen (optional)
    $jsonFile = __DIR__ . '/../public/api/aussteller.json';
    if (file_exists($jsonFile)) {
        $jsonData = json_decode(file_get_contents($jsonFile), true);
        $cleanId  = str_replace('-', '', $pageId);
        foreach ($jsonData['aussteller'] ?? [] as $entry) {
            if ($entry['id'] === $cleanId) {
                if (empty($ausLive['logo_local']) && !empty($entry['logo_local'])) {
                    $ausLive['logo_local'] = $entry['logo_local'];
                }
                break;
            }
        }
    }

    $email = $ausLive['kontakt_email'] ?? '';

    echo "   Firma:    {$firma}\n";
    echo "   E-Mail:   " . ($email ?: '(keine)') . "\n";
    echo "   Deadline: {$deadline}\n";

    if ($dryRun) {
        echo "   [DRY-RUN] Würde Review + Mail erstellen\n";
        $stats['sent']++;
        continue;
    }

    // Review-Seite erstellen
    $reviewPage = $notion->createAusstellerReviewPage($ausLive, $deadline, $email);
    if (!$reviewPage || empty($reviewPage['id'])) {
        echo "   ❌ Review-Seite konnte nicht erstellt werden\n";
        $stats['error']++;
        continue;
    }

    $reviewPageId   = $reviewPage['id'];
    // Kunden-URL immer auf Live-Domain – REVIEW_PUBLIC_URL ignoriert SITE_URL bewusst.
    $publicBase      = rtrim(REVIEW_PUBLIC_URL ?: (defined('SITE_URL') ? SITE_URL : ''), '/');
    $reviewCustomUrl = $publicBase
        ? $publicBase . '/review.html?id=' . str_replace('-', '', $reviewPageId)
        : ($reviewPage['url'] ?? '');

    echo "   ✓ Review: {$reviewCustomUrl}\n";

    // E-Mail-Draft erstellen
    if ($email) {
        $vorname  = $ausLive['kontakt_vorname'] ?? $firma;
        $nachname = $ausLive['kontakt_nachname'] ?? '';
        $duzen    = $ausLive['kontakt_duzen'] ?? false;

        $emailPage = $notion->createAusstellerEmailDraft(
            $firma, $email, $vorname, $nachname, $reviewCustomUrl, $deadline, $duzen
        );
        echo "   " . ($emailPage ? "✓ E-Mail-Draft erstellt" : "⚠ E-Mail-Draft fehlgeschlagen") . "\n";
    } else {
        echo "   ⚠ Keine E-Mail – Draft übersprungen\n";
    }

    // Aussteller-Status wird NICHT hier gesetzt – erst wenn der Kunde
    // das Formular auf review.html tatsächlich abschickt (submit-aussteller-review.php).

    $stats['sent']++;
    echo "\n";

    usleep(400000); // Rate-Limit: 400ms zwischen Requests
}

// ── 3) Zusammenfassung ───────────────────────────────────────────────────────
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo ($dryRun ? "[DRY-RUN] " : "") . "Fertig!\n";
echo "  Versendet:   {$stats['sent']}\n";
echo "  Übersprungen:{$stats['skipped']}\n";
echo "  Fehler:      {$stats['error']}\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
