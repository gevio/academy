<?php
/**
 * generate-aussteller-json.php – CLI-Script
 *
 * Lädt alle Aussteller aus der Notion DB "AS26_Aussteller"
 * und schreibt /public/api/aussteller.json.
 *
 * Usage:  php scripts/generate-aussteller-json.php
 */

$t0 = microtime(true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$notion  = new NotionClient(NOTION_TOKEN);
$outFile = __DIR__ . '/../public/api/aussteller.json';

if (!defined('NOTION_AUSSTELLER_DB') || empty(NOTION_AUSSTELLER_DB)) {
    die("❌ NOTION_AUSSTELLER_DB nicht gesetzt. Bitte in .env konfigurieren.\n");
}

// ── 1) Alle Aussteller laden ──
echo "📥 Aussteller laden...\n";

$body = [
    'page_size' => 100,
    'sorts' => [
        ['property' => 'Aussteller', 'direction' => 'ascending'],
    ],
];

$all = [];
$cursor = null;

do {
    if ($cursor) $body['start_cursor'] = $cursor;
    $data = $notion->queryDatabase(NOTION_AUSSTELLER_DB, $body);
    if (!$data) break;

    foreach ($data['results'] ?? [] as $page) {
        $props = $page['properties'] ?? [];

        // Aussteller (title)
        $firma = '';
        foreach ($props['Aussteller']['title'] ?? [] as $t) {
            $firma .= $t['plain_text'] ?? '';
        }
        $firma = trim($firma);
        if (empty($firma)) continue;

        // Stand (rich_text)
        $stand = '';
        foreach ($props['Stand']['rich_text'] ?? [] as $t) {
            $stand .= $t['plain_text'] ?? '';
        }
        $stand = trim($stand);

        // Beschreibung (rich_text)
        $beschreibung = '';
        foreach ($props['Beschreibung']['rich_text'] ?? [] as $t) {
            $beschreibung .= $t['plain_text'] ?? '';
        }

        // Kategorie (select)
        $kategorie = $props['Kategorie']['select']['name'] ?? '';

        // Website (url)
        $website = $props['Website']['url'] ?? '';

        // Instagram (url)
        $instagram = $props['Instagram']['url'] ?? '';

        // Logo (files) – erste Datei-URL
        $logo = '';
        $logoFiles = $props['Logo']['files'] ?? [];
        if (!empty($logoFiles)) {
            $first = $logoFiles[0];
            if (($first['type'] ?? '') === 'file') {
                $logo = $first['file']['url'] ?? '';
            } elseif (($first['type'] ?? '') === 'external') {
                $logo = $first['external']['url'] ?? '';
            }
        }

        // Slug (rich_text)
        $slug = '';
        foreach ($props['Slug']['rich_text'] ?? [] as $t) {
            $slug .= $t['plain_text'] ?? '';
        }

        $entry = [
            'id'           => str_replace('-', '', $page['id']),
            'firma'        => $firma,
            'stand'        => $stand,
            'beschreibung' => trim($beschreibung),
            'kategorie'    => $kategorie,
            'website'      => $website ?: '',
            'instagram'    => $instagram ?: '',
        ];

        // Logo nur wenn vorhanden (Notion-hosted URLs haben Expiry)
        if ($logo) {
            $entry['logo'] = $logo;
        }

        $all[] = $entry;

        $standInfo = $stand ? "({$stand})" : "(kein Stand)";
        echo "   ✓ {$firma} {$standInfo}\n";
    }

    $cursor = $data['next_cursor'] ?? null;
    usleep(350000); // Rate-Limit
} while ($data['has_more'] ?? false);

echo "\n   " . count($all) . " Aussteller geladen.\n\n";

// ── 2) JSON schreiben ──
$output = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'count'     => count($all),
    'aussteller' => $all,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json);

$size = round(strlen($json) / 1024, 1);
$elapsed = round(microtime(true) - $t0, 1);

echo "────────────────────────────────────\n";
echo "✅ {$outFile}\n";
echo "   {$size} KB, {$elapsed}s\n";
