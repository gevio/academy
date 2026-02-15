<?php
/**
 * generate-workshops-json.php – CLI-Script
 *
 * Lädt alle Workshops aus Notion (nur Fr/Sa/So), rendert Page-Content zu HTML
 * und schreibt /public/api/workshops.json. 0 API-Calls pro Besucher danach.
 *
 * Usage:  php scripts/generate-workshops-json.php
 */

$t0 = microtime(true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';
require_once __DIR__ . '/../src/BlockRenderer.php';

$notion   = new NotionClient(NOTION_TOKEN);
$renderer = new BlockRenderer();
$outFile  = __DIR__ . '/../public/api/workshops.json';

// ── 1) Alle Workshops laden (nur Messetage) ──────────────
echo "📥 Workshops laden...\n";
$workshops = $notion->getAllWorkshops(
    NOTION_WORKSHOP_DB,
    ['Freitag', 'Samstag', 'Sonntag']
);
echo "   " . count($workshops) . " Workshops gefunden.\n\n";

if (empty($workshops)) {
    // Kein Tag-Filter? Versuche ohne Filter
    echo "⚠ Keine Workshops mit Tag-Filter gefunden. Lade alle...\n";
    $workshops = $notion->getAllWorkshops(NOTION_WORKSHOP_DB);
    echo "   " . count($workshops) . " Workshops gefunden.\n\n";
}

if (empty($workshops)) {
    die("❌ Keine Workshops in der DB. Abbruch.\n");
}

// ── 2) Page-Content (Blocks) für jeden Workshop laden ────
echo "📄 Page-Content laden & rendern...\n";
$result = [];
$ok = 0;
$noContent = 0;

foreach ($workshops as $ws) {
    $pageId = $ws['page_id'];
    $cleanId = $ws['id'];

    echo "   {$cleanId} ";

    // Blocks laden (mit Rate-Limit)
    usleep(350000); // 350ms → ~2.8 req/s (unter Notion-Limit von 3/s)
    $blocks = $notion->getPageBlocks($pageId);

    $contentHtml = '';
    $hasContent = false;

    if (!empty($blocks)) {
        $contentHtml = $renderer->render($blocks);
        $hasContent = !empty(trim(strip_tags($contentHtml)));
    }

    $result[] = [
        'id'           => $cleanId,
        'title'        => $ws['title'],
        'typ'          => $ws['typ'],
        'tag'          => $ws['tag'],
        'zeit'         => $ws['zeit'],
        'ort'          => $ws['ort'],
        'beschreibung' => $ws['beschreibung'],
        'datum_start'  => $ws['datum_start'],
        'content_html' => $contentHtml,
        'has_content'  => $hasContent,
    ];

    echo($hasContent ? "✓" : "○") . " (" . strlen($contentHtml) . " bytes)\n";
    $hasContent ? $ok++ : $noContent++;
}

// ── 3) JSON schreiben ────────────────────────────────────
$output = [
    'generated' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'count'     => count($result),
    'workshops' => $result,
];

$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
file_put_contents($outFile, $json);

$size = round(strlen($json) / 1024, 1);
$elapsed = round(microtime(true) - $t0, 1);

echo "\n────────────────────────────────────\n";
echo "✅ {$outFile}\n";
echo "   {$ok} mit Content, {$noContent} ohne Content\n";
echo "   {$size} KB, {$elapsed}s\n";
