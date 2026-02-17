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
$outFile     = __DIR__ . '/../public/api/aussteller.json';
$standFile   = __DIR__ . '/../public/api/standplan.json';

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

        // Standplan-Koordinaten (Number)
        $standX = $props['Stand_X']['number'] ?? null;
        $standY = $props['Stand_Y']['number'] ?? null;
        $standW = $props['Stand_W']['number'] ?? null;
        $standH = $props['Stand_H']['number'] ?? null;

        // Logo: Notion-hosted URLs haben Expiry (~1h) – erst einbauen
        // wenn wir Bilder beim Generieren lokal speichern.
        // Slug: aktuell ungenutzt, bei Bedarf wieder aktivieren.

        $entry = [
            'id'           => str_replace('-', '', $page['id']),
            'page_id'      => $page['id'],
            'firma'        => $firma,
            'stand'        => $stand,
            'beschreibung' => trim($beschreibung),
            'kategorie'    => $kategorie,
            'website'      => $website ?: '',
            'instagram'    => $instagram ?: '',
            'stand_x'      => $standX,
            'stand_y'      => $standY,
            'stand_w'      => $standW,
            'stand_h'      => $standH,
        ];

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

// ── 3) standplan.json ableiten (Koordinaten aus Notion) ──
$hallenConfig = [
    'FW'  => ['bild' => '/img/plan/FW.jpg', 'label' => 'Foyer West'],
    'AT'  => ['bild' => '/img/plan/FW.jpg', 'label' => 'Foyer West (Atrium)'],
    'FG'  => ['bild' => '/img/plan/FG.jpg', 'label' => 'Freigel\u00e4nde West'],
    'FGO' => ['bild' => '/img/plan/FG.jpg', 'label' => 'Freigel\u00e4nde Ost'],
    'A3'  => ['bild' => '/img/plan/A3.jpg', 'label' => 'Halle A3'],
    'A4'  => ['bild' => '/img/plan/A4.jpg', 'label' => 'Halle A4'],
    'A5'  => ['bild' => '/img/plan/A5.jpg', 'label' => 'Halle A5'],
    'A6'  => ['bild' => '/img/plan/A6.jpg', 'label' => 'Halle A6'],
];

$staende = [];
$coordCount = 0;
foreach ($all as $a) {
    if ($a['stand'] && $a['stand_x'] !== null && $a['stand_y'] !== null) {
        $entry = ['x' => $a['stand_x'], 'y' => $a['stand_y']];
        if ($a['stand_w'] !== null) $entry['w'] = $a['stand_w'];
        if ($a['stand_h'] !== null) $entry['h'] = $a['stand_h'];
        $staende[$a['stand']] = $entry;
        $coordCount++;
    }
}

$standplanOutput = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'hallen'    => $hallenConfig,
    'staende'   => (object)$staende,
];

$standJson = json_encode($standplanOutput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($standFile, $standJson);

echo "✅ {$standFile}\n";
echo "   {$coordCount} St\u00e4nde mit Koordinaten\n";
