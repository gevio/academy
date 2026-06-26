<?php
/**
 * generate-kids-json.php – CLI-Script
 *
 * Lädt alle Adventure-KIDS-Attraktionen aus Notion (AS26_Kids DB)
 * und schreibt /public/api/kids.json.
 *
 * Notion-DB: NOTION_KIDS_DB (.env)
 * Properties: Name (title), Beschreibung (rich_text), Bereich (select),
 *             Sortierung (number), Highlight (checkbox), Icon (rich_text)
 *
 * Usage: php scripts/generate-kids-json.php
 */

$t0 = microtime(true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$outFile = __DIR__ . '/../public/api/kids.json';

if (empty(NOTION_KIDS_DB)) {
    echo "⚠  NOTION_KIDS_DB nicht gesetzt – überspringe.\n";
    // Leere Datei schreiben damit das Frontend nicht bricht
    file_put_contents($outFile, json_encode([
        'generated' => date('c'),
        'count'     => 0,
        'attraktionen' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    exit(0);
}

$notion  = new NotionClient(NOTION_TOKEN);
$all     = [];
$cursor  = null;

echo "📥 Kids-Attraktionen laden...\n";

$body = [
    'page_size' => 100,
    'sorts'     => [
        ['property' => 'Sortierung', 'direction' => 'ascending'],
    ],
];

do {
    if ($cursor) $body['start_cursor'] = $cursor;
    $data = $notion->queryDatabase(NOTION_KIDS_DB, $body);
    if (!$data) {
        echo "❌ Notion-Abfrage fehlgeschlagen.\n";
        exit(1);
    }

    foreach ($data['results'] ?? [] as $page) {
        $props = $page['properties'] ?? [];

        // Name (title)
        $name = '';
        foreach ($props['Name']['title'] ?? [] as $t) {
            $name .= $t['plain_text'] ?? '';
        }
        $name = trim($name);
        if (empty($name)) continue;

        // Beschreibung (rich_text)
        $beschreibung = '';
        foreach ($props['Beschreibung']['rich_text'] ?? [] as $t) {
            $beschreibung .= $t['plain_text'] ?? '';
        }

        // Bereich (select)
        $bereich = $props['Bereich']['select']['name'] ?? '';

        // Sortierung (number)
        $sortierung = $props['Sortierung']['number'] ?? 999;

        // Highlight (checkbox)
        $highlight = (bool) ($props['Highlight']['checkbox'] ?? false);

        // Icon (rich_text) – z.B. "🏕️" oder "bungee"
        $icon = '';
        foreach ($props['Icon']['rich_text'] ?? [] as $t) {
            $icon .= $t['plain_text'] ?? '';
        }

        $all[] = [
            'name'        => $name,
            'beschreibung'=> trim($beschreibung),
            'bereich'     => $bereich,
            'sortierung'  => (int)$sortierung,
            'highlight'   => $highlight,
            'icon'        => trim($icon),
        ];

        echo "  ✓ {$name}\n";
    }

    $cursor = $data['next_cursor'] ?? null;
} while ($data['has_more'] ?? false);

// Nach Sortierung ordnen
usort($all, fn($a, $b) => $a['sortierung'] <=> $b['sortierung']);

$output = [
    'generated'    => date('c'),
    'count'        => count($all),
    'attraktionen' => $all,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json);

$dur = round(microtime(true) - $t0, 2);
echo "\n✅ " . count($all) . " Attraktionen → {$outFile} ({$dur}s)\n";
